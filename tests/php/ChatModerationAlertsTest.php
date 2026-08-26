<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;

require_once __DIR__ . '/../../lib/chat_moderation_alerts.php';

/**
 * Telling club admins that chat has been flagged.
 *
 * Auto-flagging has fired on every message since moderation shipped 2026-07-30
 * and nothing has ever told an admin — ChatModeration.tsx is pull-only, so a
 * flag sits unseen until someone happens to open the page. That is why this
 * moved ahead of web push in the plan.
 *
 * The severity split (docs/chat-moderation-plan.md:328) is the design, not a
 * setting: individual alerts for high severity, everything else in a weekly
 * digest. Mailing per flag is how admins learn to filter the sender, and then
 * the one that mattered goes unread too.
 */
class ChatModerationAlertsTest extends TestCase
{
    private PDO $pdo;
    private array $sent = [];

    private const NOW = '2026-08-26 12:00:00';

    protected function setUp(): void
    {
        $this->sent = [];
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->pdo->exec("
            CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, first_name TEXT, last_name TEXT);
            CREATE TABLE user_club_access (id INTEGER PRIMARY KEY, user_id INTEGER, club_profile_id INTEGER,
                                           role TEXT, active INTEGER, revoked_at TEXT);
            CREATE TABLE chat_message_reports (
                id INTEGER PRIMARY KEY, message_id INTEGER, conversation_id INTEGER, club_id INTEGER,
                source TEXT, reported_by INTEGER, rule TEXT, severity TEXT, note TEXT,
                status TEXT, reviewed_by INTEGER, reviewed_at TEXT, created_at TEXT
            );
            CREATE TABLE chat_moderation_alert_state (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, report_id INTEGER,
                club_id INTEGER, kind TEXT NOT NULL, sent_at TEXT NOT NULL
            );
            CREATE UNIQUE INDEX idx_mod_alert_unique ON chat_moderation_alert_state (user_id, report_id)
                WHERE kind = 'high_severity';
        ");

        $this->pdo->exec("
            INSERT INTO users (id, email, first_name, last_name) VALUES
                (1, 'admin1@example.com', 'Adele', 'Admin'),
                (2, 'admin2@example.com', 'Ben',   'Boss'),
                (3, 'coach@example.com',  'Cora',  'Coach');
            INSERT INTO user_club_access (id, user_id, club_profile_id, role, active, revoked_at) VALUES
                (1, 1, 51, 'club_admin', 1, NULL),
                (2, 2, 51, 'club_admin', 1, NULL),
                (3, 3, 51, 'coach',      1, NULL);
        ");
    }

    private function report(int $id, string $severity, string $status = 'open', int $hoursAgo = 2, string $rule = 'hate_speech'): void
    {
        $at = (new \DateTimeImmutable(self::NOW))
            ->sub(new \DateInterval('PT' . $hoursAgo . 'H'))
            ->format('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare(
            "INSERT INTO chat_message_reports (id, message_id, conversation_id, club_id, source, rule, severity, status, created_at)
             VALUES (?, 900, 55, 51, 'auto', ?, ?, ?, ?)"
        );
        $stmt->execute([$id, $rule, $severity, $status, $at]);
    }

    private function dispatch(array $opts = []): array
    {
        $capture = function (array $envelope) use (&$opts): bool {
            $this->sent[] = $envelope;
            return $opts['__succeed'] ?? true;
        };

        return te_chat_dispatch_moderation_alerts($this->pdo, array_merge(
            ['now' => self::NOW, 'mailer' => $opts['mailer'] ?? $capture],
            array_diff_key($opts, ['mailer' => 1, '__succeed' => 1])
        ));
    }

    private function ofKind(string $kind): array
    {
        return array_values(array_filter($this->sent, fn($e) => $e['kind'] === $kind));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Severity is the design
    // ─────────────────────────────────────────────────────────────────────────

    public function testHighSeverityAlertsEveryClubAdmin(): void
    {
        $this->report(1, 'high');

        $result = $this->dispatch();

        $this->assertSame(2, $result['alerts_sent'], 'Both club admins must be told.');
        $recipients = array_column($this->ofKind('high_severity'), 'to');
        sort($recipients);
        $this->assertSame(['admin1@example.com', 'admin2@example.com'], $recipients);
    }

    /**
     * Most auto-flags are external_app or profanity. Those matter in aggregate
     * and not at 2am; an individual alert for each is how the important ones get
     * filtered too.
     */
    public function testMediumAndLowSeverityDoNotAlertIndividually(): void
    {
        $this->report(1, 'medium', 'open', 2, 'external_app');
        $this->report(2, 'low');

        $result = $this->dispatch();

        $this->assertSame(0, $result['alerts_sent'], 'Only high severity interrupts someone.');
        $this->assertGreaterThan(0, $result['digests_sent'], 'They still appear in the digest.');
    }

    public function testAnAlreadyReviewedReportDoesNotAlert(): void
    {
        $this->report(1, 'high', 'actioned');
        $this->report(2, 'high', 'dismissed');

        $this->assertSame(0, $this->dispatch()['alerts_sent'], 'Only open reports need attention.');
    }

    public function testAnAdminIsNotAlertedTwiceForTheSameReport(): void
    {
        $this->report(1, 'high');

        $this->assertSame(2, $this->dispatch()['alerts_sent']);

        $this->sent = [];
        $this->assertSame(0, $this->dispatch()['alerts_sent'], 'The marker must stop a re-send on the next tick.');
    }

    /**
     * On first deploy the marker table is empty. Without a bound, every
     * historical high-severity flag would be mailed out at once — the same
     * first-run flood the chat lookback window prevents.
     */
    public function testOldReportsAreNotMailedOutOnFirstRun(): void
    {
        $this->report(1, 'high', 'open', 24 * 30); // a month old, still open
        $this->report(2, 'high', 'open', 2);

        $result = $this->dispatch();

        $this->assertSame(2, $result['alerts_sent'], 'Two admins x one recent report.');

        $rows = $this->pdo->query(
            "SELECT DISTINCT report_id FROM chat_moderation_alert_state WHERE kind = 'high_severity'"
        )->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame([2], array_map('intval', $rows), 'Only the recent report may be alerted.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Who
    // ─────────────────────────────────────────────────────────────────────────

    /** Moderation is a club-admin surface; alerting a coach mails them a 403. */
    public function testCoachesAreNotAlerted(): void
    {
        $this->report(1, 'high');
        $this->dispatch();

        $this->assertNotContains('coach@example.com', array_column($this->sent, 'to'));
    }

    /** active = TRUE and revoked_at set can disagree; the revocation is newer. */
    public function testARevokedAdminIsNotAlerted(): void
    {
        $this->pdo->exec("UPDATE user_club_access SET revoked_at = '2026-07-08 00:00:00' WHERE user_id = 2");
        $this->report(1, 'high');

        $this->dispatch();

        $this->assertSame(['admin1@example.com'], array_column($this->ofKind('high_severity'), 'to'));
    }

    public function testAnAdminWithNoEmailIsNotAlerted(): void
    {
        $this->pdo->exec("UPDATE users SET email = '' WHERE id = 2");
        $this->report(1, 'high');

        $this->assertSame(1, $this->dispatch()['alerts_sent']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Digest
    // ─────────────────────────────────────────────────────────────────────────

    public function testTheDigestCountsWhatIsStillOpen(): void
    {
        $this->report(1, 'high');
        $this->report(2, 'medium');
        $this->report(3, 'low', 'actioned');

        $this->dispatch();

        $digest = $this->ofKind('digest')[0];
        $this->assertSame(2, $digest['detail']['open_total'], 'The actioned one is done.');
        $this->assertSame(1, $digest['detail']['open_high']);
    }

    /**
     * A weekly "0 reports" email is exactly the kind of mail people filter, and
     * the filter then catches the week something did happen.
     */
    public function testNoDigestIsSentWhenNothingIsOpen(): void
    {
        $this->report(1, 'high', 'actioned');

        $this->assertSame(0, $this->dispatch()['digests_sent']);
    }

    public function testTheDigestRespectsItsCadence(): void
    {
        $this->report(1, 'medium');

        $this->assertSame(2, $this->dispatch()['digests_sent'], 'Both admins.');

        $this->sent = [];
        $this->assertSame(0, $this->dispatch()['digests_sent'], 'Not again the same day.');

        $this->sent = [];
        $later = (new \DateTimeImmutable(self::NOW))->add(new \DateInterval('P8D'))->format('Y-m-d H:i:s');
        $this->assertSame(2, $this->dispatch(['now' => $later])['digests_sent'], 'A week later, yes.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Not leaking the thing that was flagged
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A stronger version of the rule for family digests: this is content flagged
     * for hate speech or an attempt to move a child off-platform. Copying it into
     * several inboxes spreads it, survives the removal that may follow, and puts
     * it outside the access log that makes admin review accountable.
     */
    public function testTheAlertNeverCarriesTheFlaggedMessage(): void
    {
        $src = file_get_contents(__DIR__ . '/../../lib/chat_moderation_alerts.php');

        $this->assertStringNotContainsString(
            'message_text',
            $src,
            'The alert must not read the flagged message. Its job is to get an admin to the review '
            . 'screen, where reading it is gated and recorded.'
        );

        $this->report(1, 'high');
        $this->dispatch();

        foreach ($this->sent as $envelope) {
            $this->assertArrayNotHasKey('message_text', $envelope);
            $this->assertArrayNotHasKey('body', $envelope);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Isolation
    // ─────────────────────────────────────────────────────────────────────────

    public function testOneThrowingAlertDoesNotAbortTheBatch(): void
    {
        $this->report(1, 'high');

        $result = te_chat_dispatch_moderation_alerts($this->pdo, [
            'now' => self::NOW,
            'mailer' => function (array $envelope): bool {
                if ($envelope['to'] === 'admin1@example.com' && $envelope['kind'] === 'high_severity') {
                    throw new \RuntimeException('SendGrid exploded');
                }
                $this->sent[] = $envelope;
                return true;
            },
        ]);

        $this->assertSame(1, $result['failed']);
        $this->assertSame(1, $result['alerts_sent'], 'The other admin must still be told.');
    }

    public function testAFailedAlertIsRetriedRatherThanMarked(): void
    {
        $this->report(1, 'high');

        $this->assertSame(0, $this->dispatch(['__succeed' => false])['alerts_sent']);
        $this->assertSame(
            0,
            (int) $this->pdo->query("SELECT COUNT(*) c FROM chat_moderation_alert_state WHERE kind = 'high_severity'")->fetch()['c'],
            'Nothing delivered, so nothing recorded as delivered.'
        );

        $this->sent = [];
        $this->assertSame(2, $this->dispatch()['alerts_sent'], 'The next tick tries again.');
    }

    /**
     * The moderation sweep has its OWN catch in the worker. Sharing one with the
     * family digests would mean a failure there silently skips the child-safety
     * alerts.
     */
    public function testTheWorkerCatchesTheModerationSweepSeparately(): void
    {
        $worker = file_get_contents(__DIR__ . '/../../workers/queue-worker.php');

        $chatCall = strpos($worker, 'te_chat_dispatch_notifications(');
        $modCall = strpos($worker, 'te_chat_dispatch_moderation_alerts(');

        $this->assertNotFalse($chatCall, 'The worker must run the chat dispatcher.');
        $this->assertNotFalse($modCall, 'The worker must run the moderation dispatcher.');
        $this->assertGreaterThan($chatCall, $modCall, 'This test assumes the moderation sweep comes second.');

        $between = substr($worker, $chatCall, $modCall - $chatCall);

        $this->assertStringContainsString(
            'catch (Throwable',
            $between,
            'The chat sweep must close its own catch before the moderation sweep starts, or one failure '
            . 'silently skips the other.'
        );
        $this->assertStringContainsString(
            'try {',
            $between,
            'The moderation sweep must open its own try block.'
        );
    }

    public function testAnUnknownAlertKindIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        te_chat_record_alert($this->pdo, 1, 1, 51, 'smoke-signal', self::NOW);
    }
}
