<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/support_tickets.php';
require_once __DIR__ . '/../../lib/Slack.php';

/**
 * SQLite has no `NOW() - INTERVAL '1 hour'`, and the gateway's SQL is Postgres.
 * Rewriting it at prepare() keeps the test exercising the REAL query instead of a
 * paraphrase — and matters more than usual here, because te_support_is_rate_limited
 * fails OPEN: without this the query errors, the limiter returns false, and the
 * test passes while proving nothing. Same approach as IlikeTranslatingPdo in
 * RecipientSearchRosterTest.
 */
class IntervalTranslatingPdo extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = str_ireplace("NOW() - INTERVAL '1 hour'", "datetime('now','-1 hour')", $query);
        return parent::prepare($query, $options);
    }
}

/**
 * Lite support ticketing.
 *
 * The parts worth pinning are the ones that decide whether to ACCEPT something —
 * attachment validation and the rate limit — plus the Slack payload, because a
 * wrong label there is how an unverified report gets treated as identity.
 */
class SupportTicketTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new IntervalTranslatingPdo('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("
            CREATE TABLE support_tickets (
                id INTEGER PRIMARY KEY, user_id INTEGER, club_id INTEGER,
                reporter_name TEXT, reporter_email TEXT, description TEXT,
                page_url TEXT, device_info TEXT, ip_address TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
        ");
    }

    private function pngBytes(): string
    {
        // Smallest valid 1x1 PNG.
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );
    }

    // ─── Attachment validation ────────────────────────────────────────────────

    public function testAcceptsAPlainBase64Png(): void
    {
        $r = te_support_decode_attachment(base64_encode($this->pngBytes()), 'shot.png');

        $this->assertArrayNotHasKey('error', $r);
        $this->assertSame('image/png', $r['mime']);
        $this->assertSame(strlen($this->pngBytes()), $r['size']);
    }

    public function testAcceptsADataUri(): void
    {
        $uri = 'data:image/png;base64,' . base64_encode($this->pngBytes());

        $r = te_support_decode_attachment($uri, 'shot.png');

        $this->assertArrayNotHasKey('error', $r);
        $this->assertSame('image/png', $r['mime']);
    }

    /**
     * The type is sniffed from the BYTES. A data URI claiming to be a PNG, and a
     * .png filename, are both attacker-controlled and prove nothing.
     */
    public function testALiedAboutTypeIsRejected(): void
    {
        $uri = 'data:image/png;base64,' . base64_encode('%PDF-1.4 not an image at all');

        $r = te_support_decode_attachment($uri, 'totally-a.png');

        $this->assertSame('unsupported_type', $r['reason']);
    }

    public function testOversizeIsRejected(): void
    {
        // Valid PNG header followed by filler, past the 2 MB ceiling.
        $big = $this->pngBytes() . str_repeat("\0", TE_SUPPORT_MAX_ATTACHMENT_BYTES + 10);

        $r = te_support_decode_attachment(base64_encode($big));

        $this->assertSame('too_large', $r['reason']);
    }

    public function testGarbageIsRejectedNotCrashed(): void
    {
        $r = te_support_decode_attachment('!!!! not base64 !!!!');

        $this->assertSame('undecodable', $r['reason']);
    }

    public function testEmptyIsRejected(): void
    {
        $this->assertSame('undecodable', te_support_decode_attachment('')['reason']);
    }

    /** A path in the filename must not escape into the stored name. */
    public function testFilenameIsBasenamedAndBounded(): void
    {
        $r = te_support_decode_attachment(
            base64_encode($this->pngBytes()),
            '../../../etc/passwd'
        );

        $this->assertSame('passwd', $r['filename']);
    }

    // ─── Rate limiting ────────────────────────────────────────────────────────

    private function insert(?int $userId, ?string $ip): void
    {
        $s = $this->pdo->prepare(
            'INSERT INTO support_tickets (user_id, ip_address, description, created_at)
             VALUES (?, ?, ?, CURRENT_TIMESTAMP)'
        );
        $s->execute([$userId, $ip, 'x']);
    }

    public function testUnderTheLimitIsAllowed(): void
    {
        for ($i = 0; $i < TE_SUPPORT_RATE_LIMIT - 1; $i++) {
            $this->insert(7, '1.2.3.4');
        }

        $this->assertFalse(te_support_is_rate_limited($this->pdo, 7, '1.2.3.4'));
    }

    public function testAtTheLimitIsBlocked(): void
    {
        for ($i = 0; $i < TE_SUPPORT_RATE_LIMIT; $i++) {
            $this->insert(7, '1.2.3.4');
        }

        $this->assertTrue(te_support_is_rate_limited($this->pdo, 7, '1.2.3.4'));
    }

    /**
     * `create` is reachable unauthenticated, so a user-keyed limit alone would be
     * no limit at all for exactly the callers that need one.
     */
    public function testAnonymousReportersAreLimitedByIp(): void
    {
        for ($i = 0; $i < TE_SUPPORT_RATE_LIMIT; $i++) {
            $this->insert(null, '9.9.9.9');
        }

        $this->assertTrue(te_support_is_rate_limited($this->pdo, null, '9.9.9.9'));
        $this->assertFalse(te_support_is_rate_limited($this->pdo, null, '8.8.8.8'));
    }

    public function testOneUsersFloodDoesNotBlockAnother(): void
    {
        for ($i = 0; $i < TE_SUPPORT_RATE_LIMIT; $i++) {
            $this->insert(7, '1.2.3.4');
        }

        $this->assertFalse(te_support_is_rate_limited($this->pdo, 8, '1.2.3.4'));
    }

    /** Losing a real report is worse than accepting a duplicate. */
    public function testAFailedCheckFailsOpen(): void
    {
        $broken = new IntervalTranslatingPdo('sqlite::memory:');   // no support_tickets table
        $broken->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->assertFalse(te_support_is_rate_limited($broken, 7, '1.2.3.4'));
    }

    // ─── Slack payload ────────────────────────────────────────────────────────

    /**
     * Anyone can file an anonymous ticket, so the name in one proves nothing. If
     * that label goes missing, an unverified report starts reading as identity.
     */
    public function testAnonymousTicketsAreLabelledUnverified(): void
    {
        $p = te_slack_support_ticket_payload([
            'id' => 12, 'user_id' => null,
            'reporter_name' => 'Totally The Club Admin',
            'description' => 'help',
        ]);

        $this->assertStringContainsString('identity unverified', json_encode($p));
    }

    public function testSignedInTicketsAreNotLabelledUnverified(): void
    {
        $p = te_slack_support_ticket_payload([
            'id' => 13, 'user_id' => 42,
            'reporter_name' => 'Jess Ziegler', 'reporter_email' => 'jess@example.com',
            'description' => 'calendar is empty',
        ]);

        $this->assertStringNotContainsString('identity unverified', json_encode($p));
        $this->assertStringContainsString('jess@example.com', json_encode($p));
    }

    public function testScreenshotLinkAppearsOnlyWhenThereIsOne(): void
    {
        $with = te_slack_support_ticket_payload(['id' => 1, 'description' => 'x'], 'https://x/y?token=abc');
        $without = te_slack_support_ticket_payload(['id' => 1, 'description' => 'x'], null);

        $this->assertStringContainsString('View screenshot', json_encode($with));
        $this->assertStringNotContainsString('View screenshot', json_encode($without));
    }

    /** Slack needs fallback text or the notification is blank. */
    public function testPayloadCarriesFallbackText(): void
    {
        $p = te_slack_support_ticket_payload(['id' => 9, 'description' => 'x']);

        $this->assertNotEmpty($p['text']);
        $this->assertStringContainsString('#9', $p['text']);
    }

    // ─── Device summary ───────────────────────────────────────────────────────

    public function testDeviceSummaryReadsLikeSomethingAHumanWants(): void
    {
        $s = te_support_device_summary([
            'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_6_0 like Mac OS X) AppleWebKit/605.1.15 CriOS/151.0',
            'viewport' => '390x844',
            'timezone' => 'America/Chicago',
        ]);

        $this->assertStringContainsString('Chrome (iOS)', $s);
        $this->assertStringContainsString('iPhone', $s);
        $this->assertStringContainsString('390x844', $s);
    }

    /**
     * Edge contains "Chrome" and Chrome contains "Safari", so a naive match
     * reports every Edge user as Chrome and every Chrome user as Safari.
     */
    public function testBrowserDetectionIsNotFooledBySubstrings(): void
    {
        $edge = te_support_device_summary(['user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120 Safari/537.36 Edg/120']);
        $chrome = te_support_device_summary(['user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120 Safari/537.36']);

        $this->assertStringContainsString('Edge on Windows', $edge);
        $this->assertStringContainsString('Chrome on Windows', $chrome);
    }

    public function testOfflineIsFlagged(): void
    {
        $this->assertStringContainsString('offline', te_support_device_summary(['online' => false]));
    }

    public function testEmptyDeviceInfoDoesNotProduceJunk(): void
    {
        $this->assertSame('—', te_support_device_summary([]));
    }

    public function testScreenshotLinksExpire(): void
    {
        $this->assertSame(90, TE_SUPPORT_LINK_TTL_DAYS);
    }

    // ─── Screenshot link host ─────────────────────────────────────────────────

    /**
     * The first end-to-end test posted a Slack link built from APP_URL — which is
     * the FRONTEND (Netlify) — so it 404'd. The attachment endpoint is PHP on
     * Heroku. This must resolve to the API's own host.
     */
    public function testApiBaseUrlPrefersExplicitConfig(): void
    {
        putenv('API_BASE_URL=https://api.example.com/');
        $_ENV['API_BASE_URL'] = 'https://api.example.com/';
        try {
            $this->assertSame('https://api.example.com', te_support_api_base_url());
        } finally {
            putenv('API_BASE_URL');
            unset($_ENV['API_BASE_URL']);
        }
    }

    public function testApiBaseUrlFallsBackToTheRequestHost(): void
    {
        putenv('API_BASE_URL');
        unset($_ENV['API_BASE_URL']);
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $_SERVER['HTTP_HOST'] = 'teamselevated-backend.herokuapp.com';

        $this->assertSame(
            'https://teamselevated-backend.herokuapp.com',
            te_support_api_base_url()
        );
    }

    /** Heroku can send a comma-joined proto list; only the first is ours. */
    public function testApiBaseUrlHandlesAForwardedProtoList(): void
    {
        putenv('API_BASE_URL');
        unset($_ENV['API_BASE_URL']);
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https,http';
        $_SERVER['HTTP_HOST'] = 'example.org';

        $this->assertSame('https://example.org', te_support_api_base_url());
    }
}
