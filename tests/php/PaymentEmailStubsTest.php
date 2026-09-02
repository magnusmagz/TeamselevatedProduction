<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/Email.php';
require_once __DIR__ . '/../../lib/feature_flags.php';

/**
 * The three payment endpoints that used to answer success without sending
 * anything (roadmap R3/R11, Phase 2 slice 2.1a, 2026-09-02).
 *
 *   api/payment-receipt.php?action=email     "DEMO: Would send receipt email ..."
 *   api/payment-failures.php?action=notify   "DEMO: Would send failure notification ..."
 *   api/payment-reminders.php?action=send    wrote payment_reminder_log, then logged
 *                                            "DEMO: Would send reminder email"
 *
 * All three returned `success: true` (two of them with `demo_mode: true`, which
 * nothing in the frontend read), so the product asserted a family had been
 * contacted and no mail existed. The reminder one is the worst of the three: it
 * INSERTed into payment_reminder_log FIRST, and `?action=list` reads MAX(sent_at)
 * from that table to decide who still needs chasing — so the fictional contact
 * suppressed the real one.
 *
 * These assertions are parse-based on purpose. The bug class this repo keeps
 * producing is "fixed one, missed three", and what has to hold is a property of
 * every send site in the file, not the behaviour of one function.
 */
class PaymentEmailStubsTest extends TestCase
{
    private const FILES = [
        'api/payment-receipt.php',
        'api/payment-failures.php',
        'api/payment-reminders.php',
    ];

    private function src(string $rel): string
    {
        $path = __DIR__ . '/../../' . $rel;
        $this->assertFileExists($path);
        return file_get_contents($path);
    }

    /** Byte offsets of every match of $re in $src. */
    private function offsets(string $re, string $src): array
    {
        preg_match_all($re, $src, $m, PREG_OFFSET_CAPTURE);
        return array_map(function ($hit) { return $hit[1]; }, $m[0]);
    }

    /** Every `->sendSomething(` call on an Email object. */
    private function sendOffsets(string $src): array
    {
        return $this->offsets('/->send[A-Z][A-Za-z]*\s*\(/', $src);
    }

    /**
     * Not one "DEMO:" line left, and not one `demo_mode` key.
     *
     * `demo_mode` is the tell that survives a half-fix: a handler can be wired to a
     * real send and still be advertising itself as a stub.
     */
    public function testNoDemoStubsRemain(): void
    {
        foreach (self::FILES as $rel) {
            $src = $this->src($rel);
            $this->assertStringNotContainsString('DEMO:', $src, "$rel still carries a DEMO: log line");
            $this->assertStringNotContainsString('demo_mode', $src, "$rel still returns demo_mode");
            $this->assertStringNotContainsString(
                'demo@example.com',
                $src,
                "$rel still falls back to a placeholder address — that is a misdirected email now, not a no-op"
            );
        }
    }

    /** Each file actually sends. A file that sends nothing cannot have been wired up. */
    public function testEachEndpointReallySends(): void
    {
        foreach (self::FILES as $rel) {
            $this->assertNotEmpty(
                $this->sendOffsets($this->src($rel)),
                "$rel does not call any Email send method"
            );
        }
    }

    /**
     * Every send sits behind the kill switch, inside the same `case` block.
     *
     * Checking only that the file mentions te_feature_enabled somewhere would pass
     * a file that gates one action and sends unguarded from another — which is the
     * whole failure mode, since each of these files has several send-shaped actions.
     */
    public function testEverySendSiteIsGatedOnTheKillSwitch(): void
    {
        foreach (self::FILES as $rel) {
            $src = $this->src($rel);

            $caseStarts = $this->offsets("/\n\s*case '[a-z-]+':/", $src);
            $this->assertNotEmpty($caseStarts, "$rel: expected a switch of case blocks");
            $caseStarts[] = strlen($src);

            for ($i = 0; $i < count($caseStarts) - 1; $i++) {
                $block = substr($src, $caseStarts[$i], $caseStarts[$i + 1] - $caseStarts[$i]);
                $sends = $this->sendOffsets($block);
                if (!$sends) {
                    continue;
                }

                $gate = strpos($block, "te_feature_enabled('TRANSACTIONAL_EMAIL')");
                $label = trim(strtok(trim($block), "\n"));
                $this->assertNotFalse(
                    $gate,
                    "$rel $label sends without checking te_feature_enabled('TRANSACTIONAL_EMAIL')"
                );
                $this->assertLessThan(
                    min($sends),
                    $gate,
                    "$rel $label checks the switch AFTER it sends"
                );
            }
        }
    }

    /**
     * When the switch is off the caller is told, and never told the mail went.
     *
     * te_feature_disabled_response() carries success:false / sent:false, so the
     * requirement is that each gated branch returns it rather than inventing its
     * own shape.
     */
    public function testTheDisabledBranchReturnsTheSharedResponse(): void
    {
        foreach (self::FILES as $rel) {
            $src = $this->src($rel);
            $gates = substr_count($src, "te_feature_enabled('TRANSACTIONAL_EMAIL')");
            $responses = substr_count($src, "te_feature_disabled_response('TRANSACTIONAL_EMAIL')");
            $this->assertSame(
                $gates,
                $responses,
                "$rel checks the switch $gates time(s) but returns the disabled response $responses time(s)"
            );
        }
    }

    /**
     * Every send is branded as the club.
     *
     * `(new Email())->forClub($pdo, $clubId)->sendX(...)`. Without forClub the mail
     * goes out as "Teams Elevated" and a family has no idea who is asking them for
     * money — see lib/email_sender.php.
     */
    public function testEverySendIsBrandedAsTheClubFirst(): void
    {
        foreach (self::FILES as $rel) {
            $src = $this->src($rel);

            $constructs = $this->offsets('/new\s+Email\s*\(\s*\)/', $src);
            $branded = $this->offsets('/->forClub\s*\(/', $src);
            $sends = $this->sendOffsets($src);

            $this->assertSame(count($constructs), count($branded),
                "$rel constructs " . count($constructs) . " Email(s) but brands " . count($branded));
            $this->assertSame(count($sends), count($branded),
                "$rel makes " . count($sends) . " send(s) but calls forClub " . count($branded) . " time(s)");

            foreach ($sends as $i => $sendAt) {
                $this->assertLessThan($sendAt, $branded[$i], "$rel: forClub() must precede the send it brands");
                $this->assertLessThan($branded[$i], $constructs[$i], "$rel: forClub() must follow its own new Email()");
            }
        }
    }

    /**
     * The club is resolved from the payment/program row, not from the request body.
     *
     * A club_id taken off the body is a claim by the caller; it would let anyone who
     * can reach the endpoint choose whose name the mail goes out under.
     */
    public function testTheClubIsNotTakenFromTheRequestBody(): void
    {
        foreach (self::FILES as $rel) {
            $src = $this->src($rel);
            $this->assertDoesNotMatchRegularExpression(
                "/\\\$data\\[['\"](club_id|league_id)['\"]\\][^\n]*\n[^\n]*forClub/",
                $src,
                "$rel appears to brand mail with a club id from the request body"
            );
            $this->assertMatchesRegularExpression(
                '/(p|pi|a|cp)\.club_id|club_id as club_id|COALESCE\(p\.club_id/',
                $src,
                "$rel must resolve the club from the payment/program row"
            );
        }
    }

    /**
     * ⚠️ payment_reminder_log is written only AFTER the send returns true.
     *
     * A row in that table is the record that a family was contacted, and
     * ?action=list reads MAX(sent_at) from it to decide who still needs chasing.
     * The old handler INSERTed first and never sent, so it both invented contact
     * and suppressed the real reminder for three days.
     */
    public function testTheReminderLogIsWrittenAfterTheSend(): void
    {
        $src = $this->src('api/payment-reminders.php');

        $sends = $this->offsets('/->sendPaymentReminder\s*\(/', $src);
        $inserts = $this->offsets('/INSERT INTO payment_reminder_log/', $src);

        $this->assertNotEmpty($sends, 'payment-reminders.php must send a reminder');
        $this->assertSame(
            count($sends),
            count($inserts),
            'every reminder log row must correspond to exactly one send'
        );

        foreach ($inserts as $i => $insertAt) {
            $this->assertGreaterThan(
                $sends[$i],
                $insertAt,
                'payment_reminder_log must be INSERTed after the send, not before it'
            );
        }
    }

    /**
     * A failed send is reported as a failure.
     *
     * Each file must branch on the send's return value; `success: true` for a send
     * the provider refused is the same lie in a new place.
     */
    public function testAFailedSendIsNotReportedAsSuccess(): void
    {
        foreach (self::FILES as $rel) {
            $src = $this->src($rel);
            $this->assertMatchesRegularExpression(
                '/if\s*\(\s*!\s*\$(sent|parentSent|adminSent)\s*\)|\$failed\s*=|empty\(\$n\[\'sent\'\]\)/',
                $src,
                "$rel never inspects whether the send succeeded"
            );
            $this->assertStringContainsString(
                "'sent' =>",
                $src,
                "$rel must report a `sent` flag reflecting what actually happened"
            );
        }
    }

    /**
     * No money in a subject line.
     *
     * A subject renders on a lock screen and in every notification preview. What a
     * family owes is nobody else's business, and the existing Stripe receipt
     * (sendPaymentReceipt) is the counter-example this rule was written against.
     */
    public function testTheNewSubjectLinesCarryNoMoney(): void
    {
        $src = file_get_contents(__DIR__ . '/../../lib/Email.php');

        foreach (['sendPaymentTransactionReceipt', 'sendPaymentReminder', 'sendPaymentFailureNotice'] as $method) {
            $at = strpos($src, "function $method(");
            $this->assertNotFalse($at, "lib/Email.php must define $method");

            $body = substr($src, $at, 2500);
            preg_match_all('/\$subject\s*=\s*(.+?);/s', $body, $m);
            $this->assertNotEmpty($m[1], "$method must build a subject");

            foreach ($m[1] as $subjectExpr) {
                $this->assertStringNotContainsString('amountFormatted', $subjectExpr,
                    "$method puts the amount in the subject line");
                $this->assertStringNotContainsString('number_format', $subjectExpr,
                    "$method puts a formatted amount in the subject line");
            }
        }
    }

    // ---------------------------------------------------------------------
    // Rendering. The three templates are exercised through reflection, the way
    // EmailButtonContrastTest does it — never through send(), which would try to
    // put mail on the wire.
    // ---------------------------------------------------------------------

    private function clubPdo(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $pdo->exec("
            CREATE TABLE club_profile (
                id INTEGER PRIMARY KEY, name TEXT, primary_color TEXT, email TEXT,
                website TEXT, social_facebook TEXT, social_instagram TEXT,
                logo_png BLOB, logo_w INTEGER, logo_h INTEGER
            );
        ");
        $pdo->sqliteCreateFunction('md5', 'md5', 1);
        $pdo->exec("INSERT INTO club_profile (id, name, primary_color)
                    VALUES (51, 'Central Kansas United', '#12443e')");

        // EmailBranding caches per club id across calls.
        if (class_exists('EmailBranding') && property_exists('EmailBranding', 'cache')) {
            $ref = new \ReflectionProperty('EmailBranding', 'cache');
            $ref->setAccessible(true);
            $ref->setValue(null, []);
        }

        return $pdo;
    }

    private function render(\Email $email, string $method, array $args): string
    {
        $m = new \ReflectionMethod(\Email::class, $method);
        $m->setAccessible(true);
        return $m->invokeArgs($email, $args);
    }

    /** @return array<string, array{0:string,1:array}> */
    public static function paymentTemplates(): array
    {
        $rows = [
            'Athlete' => 'Rachel Diaz',
            'Program' => 'Fall 2026 Rec',
            'Item'    => 'Registration Fee',
            'Amount'  => '$125.00',
            'Due date'=> 'October 1, 2026',
        ];

        return [
            'receipt'  => ['getPaymentReceiptTemplate',  ['Jane', $rows, 'Central Kansas United']],
            'reminder' => ['getPaymentReminderTemplate', ['Jane', 'This payment is past its due date.', $rows, true, 'Central Kansas United', 'https://example.invalid/parent/payments']],
            'failure'  => ['getPaymentFailureTemplate',  ['Jane', $rows, false, 'Central Kansas United', 'https://example.invalid/parent/payments']],
            'failure (admin copy)' => ['getPaymentFailureTemplate', ['Central Kansas United', $rows, true, 'Central Kansas United', 'https://example.invalid/parent/payments']],
        ];
    }

    /** The club's name is what a family sees in the From line, not "Teams Elevated". */
    public function testForClubPutsTheClubNameInTheFrom(): void
    {
        $email = (new \Email())->forClub($this->clubPdo(), 51);

        $prop = new \ReflectionProperty(\Email::class, 'fromName');
        $prop->setAccessible(true);
        $this->assertSame('Central Kansas United', $prop->getValue($email));

        $addr = new \ReflectionProperty(\Email::class, 'fromEmail');
        $addr->setAccessible(true);
        $this->assertSame(
            te_email_from_address(),
            $addr->getValue($email),
            'the address must stay the one SendGrid-authenticated sender'
        );
    }

    /**
     * @dataProvider paymentTemplates
     */
    public function testTheTemplateRendersWithNothingLeftUnresolved(string $method, array $args): void
    {
        $html = $this->render(new \Email(), $method, $args);

        $this->assertStringNotContainsString('{{', $html, "$method: unresolved merge tag left in the body");
        $this->assertStringNotContainsString('}}', $html, "$method: unresolved merge tag left in the body");
        // A heredoc that fails to interpolate leaves the variable name behind.
        $this->assertDoesNotMatchRegularExpression('/\$[a-zA-Z_][a-zA-Z0-9_]*/', $html,
            "$method: a PHP variable name reached the rendered body");

        $this->assertStringContainsString('Central Kansas United', $html, "$method: the club must be named in the body");
        $this->assertStringContainsString('Rachel Diaz', $html, "$method: the athlete must be named");
        $this->assertStringContainsString('Registration Fee', $html, "$method: the detail rows must render");
    }

    /**
     * @dataProvider paymentTemplates
     */
    public function testAnyButtonLabelIsExplicitlyWhite(string $method, array $args): void
    {
        $html = $this->render(new \Email(), $method, $args);

        if (!str_contains($html, 'class="button"')) {
            // The receipt has no call to action; there is nothing to press.
            $this->assertSame('getPaymentReceiptTemplate', $method,
                "$method has a CTA-less body but is not the receipt");
            return;
        }

        // Same rule EmailButtonContrastTest pins for the four older templates: white
        // inline on the anchor AND on a nested span, because mail clients override
        // anchor colour with their own link styling.
        $this->assertMatchesRegularExpression(
            '/<a\b[^>]*class="button"[^>]*style="[^"]*color:\s*#ffffff\s*!important/i',
            $html,
            "$method: button anchor must set white inline"
        );
        $this->assertMatchesRegularExpression(
            '/<a\b[^>]*class="button"[^>]*>\s*<span[^>]*color:\s*#ffffff\s*!important/i',
            $html,
            "$method: button label needs a white <span>"
        );
    }

    /**
     * Names, item names and the processor's failure_reason all come from data, and
     * the failure reason in particular is a string the card processor wrote.
     */
    public function testDataValuesAreEscaped(): void
    {
        $rows = ['Athlete' => 'Rachel <script>alert(1)</script>', 'Reason' => 'Card "declined" & retried'];
        $html = $this->render(new \Email(), 'getPaymentFailureTemplate',
            ['Jane & Co', $rows, false, 'Central Kansas United', 'https://example.invalid/p']);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('&amp;', $html);
    }

    /** action=get returned guardian email, amounts and method for any transaction id, no token. */
    public function testTheReceiptReadIsGatedLikeTheReceiptSend(): void
    {
        $src = file_get_contents(__DIR__ . '/../../api/payment-receipt.php');
        $get = strpos($src, "case 'get':");
        $email = strpos($src, "case 'email':");
        $body = substr($src, $get, $email - $get);
        $gate = strpos($body, 'AthleteScope::userCanAccessAthlete(');
        $out = strpos($body, "'receipt' =>") ?: strpos($body, 'echo json_encode([');
        $this->assertNotFalse($gate, 'get must check the athlete read predicate');
        $this->assertLessThan($out, $gate, 'the gate must precede the receipt output');
    }
}
