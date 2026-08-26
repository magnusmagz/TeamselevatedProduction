<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;

require_once __DIR__ . '/../../lib/chat_notification_scope.php';
require_once __DIR__ . '/../../lib/chat_notification_dispatcher.php';

/**
 * Sending the digests that phase 1 says are owed.
 *
 * The two tests that matter most here are structural, and deliberately so:
 * testDispatcherUsesTheTransactionalPathNotEmailSendService and
 * testSuppressionListIsNeverConsulted. Both failures are SILENT — the wrong send
 * path floods Email Reporting with chat noise and quietly drops alerts for
 * anyone who once unsubscribed from club marketing, while every visible symptom
 * says the feature works.
 */
class ChatNotificationDispatcherTest extends TestCase
{
    private PDO $pdo;
    private array $sent = [];

    private const NOW = '2026-08-25 12:00:00';

    protected function setUp(): void
    {
        $this->sent = [];
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->pdo->exec("
            CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, first_name TEXT, last_name TEXT);
            CREATE TABLE guardians (id INTEGER PRIMARY KEY, email TEXT);
            CREATE TABLE athlete_guardians (id INTEGER PRIMARY KEY, athlete_id INTEGER, guardian_id INTEGER);
            CREATE TABLE teams (id INTEGER PRIMARY KEY, name TEXT, club_id INTEGER, primary_coach_id INTEGER);
            CREATE TABLE team_members (id INTEGER PRIMARY KEY, team_id INTEGER, user_id INTEGER,
                                       athlete_id INTEGER, role TEXT, status TEXT);
            CREATE TABLE user_club_access (id INTEGER PRIMARY KEY, user_id INTEGER, club_profile_id INTEGER,
                                           role TEXT, active INTEGER, revoked_at TEXT);
            CREATE TABLE conversations (id INTEGER PRIMARY KEY, type TEXT, team_id INTEGER, club_id INTEGER);
            CREATE TABLE conversation_participants (id INTEGER PRIMARY KEY, conversation_id INTEGER,
                                                    user_id INTEGER, display_name TEXT,
                                                    last_read_message_id INTEGER, muted INTEGER DEFAULT 0,
                                                    left_at TEXT);
            CREATE TABLE chat_messages (id INTEGER PRIMARY KEY, conversation_id INTEGER, sender_id INTEGER,
                                        sender_name TEXT, message_text TEXT, created_at TEXT, deleted_at TEXT);
            CREATE TABLE chat_notification_state (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
                                        conversation_id INTEGER NOT NULL, last_notified_message_id INTEGER,
                                        last_notified_at TEXT, last_notified_channel TEXT,
                                        created_at TEXT, updated_at TEXT, UNIQUE (user_id, conversation_id));
            CREATE TABLE chat_notification_prefs (user_id INTEGER PRIMARY KEY, email_enabled INTEGER DEFAULT 1,
                                        push_enabled INTEGER DEFAULT 1, created_at TEXT, updated_at TEXT);
            CREATE TABLE push_subscriptions (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
                                        endpoint TEXT NOT NULL, p256dh TEXT NOT NULL, auth TEXT NOT NULL,
                                        user_agent TEXT, created_at TEXT, last_used_at TEXT, UNIQUE (endpoint));
            CREATE TABLE notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, type TEXT,
                                        title TEXT, message TEXT, data TEXT, read_at TEXT, created_at TEXT);
        ");

        // Coach 1 (staff in club 51) and parent 2 (guardian of athlete 100) on team 10.
        $this->pdo->exec("
            INSERT INTO users (id, email, first_name, last_name) VALUES
                (1, 'coach@example.com',  'Cora', 'Coach'),
                (2, 'parent@example.com', 'Pat',  'Parent');
            INSERT INTO guardians (id, email) VALUES (500, 'parent@example.com');
            INSERT INTO athlete_guardians (id, athlete_id, guardian_id) VALUES (900, 100, 500);
            INSERT INTO teams (id, name, club_id, primary_coach_id) VALUES (10, 'U12 Blue', 51, 1);
            INSERT INTO team_members (id, team_id, user_id, athlete_id, role, status)
                VALUES (700, 10, NULL, 100, 'player', 'active');
            INSERT INTO user_club_access (id, user_id, club_profile_id, role, active, revoked_at)
                VALUES (1, 1, 51, 'coach', 1, NULL);
            INSERT INTO conversations (id, type, team_id, club_id) VALUES (55, 'team', 10, 51);
            INSERT INTO chat_messages (id, conversation_id, sender_id, sender_name, message_text, created_at)
                VALUES (1, 55, 1, 'Cora Coach', 'Practice moved', '2026-08-25 11:30:00');
        ");
    }

    /** Captures envelopes instead of reaching SendGrid. */
    private function dispatch(array $opts = []): array
    {
        $capture = function (array $envelope) use (&$opts): bool {
            $this->sent[] = $envelope;
            return $opts['__succeed'] ?? true;
        };

        return te_chat_dispatch_notifications($this->pdo, array_merge(
            ['now' => self::NOW, 'mailer' => $opts['mailer'] ?? $capture],
            array_diff_key($opts, ['mailer' => 1, '__succeed' => 1])
        ));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The silent ones
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * EmailSendService writes a communication_log row per send and applies the
     * marketing suppression list. Using it here would both pollute Email
     * Reporting and silently stop alerts for unsubscribed families.
     */
    public function testDispatcherUsesTheTransactionalPathNotEmailSendService(): void
    {
        $src = $this->strippedSource(__DIR__ . '/../../lib/chat_notification_dispatcher.php');

        $this->assertStringNotContainsString(
            'EmailSendService',
            $src,
            'Chat alerts must not go through EmailSendService — it logs a campaign row per send and '
            . 'applies email_suppressions, so an unsubscribed parent silently stops hearing from their coach.'
        );
        $this->assertStringContainsString('new Email()', $src, 'Must use the lib/Email.php transactional path.');
        $this->assertStringContainsString(
            '->forClub(',
            $src,
            'A parent should see their own club as the sender, not the platform. EmailSenderTest enforces '
            . 'this shape across every club-aware send site.'
        );
    }

    /**
     * Scan the CODE, not the file.
     *
     * The dispatcher's header comment explains at length why suppression must
     * not reach this path, so a naive substring search matches the warning and
     * fails on the very documentation that prevents the bug. Same lesson
     * MysqlOnlySqlTest records: scan SQL, not source, or the checker cries wolf
     * and someone deletes it.
     */
    public function testSuppressionListIsNeverConsulted(): void
    {
        $code = $this->strippedSource(__DIR__ . '/../../lib/chat_notification_dispatcher.php');

        foreach (['email_suppressions', 'te_sms_skip_reason', 'suppression.php'] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $code,
                "The marketing suppression list must not gate chat alerts ({$needle}). Opting out of club "
                . 'broadcasts is not opting out of being told your coach messaged you. The controls for '
                . 'this feature are conversation_participants.muted and chat_notification_prefs.'
            );
        }
    }

    /** Source with comments and docblocks removed, so prose cannot trip a scan. */
    private function strippedSource(string $path): string
    {
        $out = '';
        foreach (token_get_all(file_get_contents($path)) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Delivery
    // ─────────────────────────────────────────────────────────────────────────

    public function testAnOwedDigestIsSent(): void
    {
        $result = $this->dispatch();

        $this->assertSame(1, $result['sent']);
        $this->assertCount(1, $this->sent);

        $envelope = $this->sent[0];
        $this->assertSame('parent@example.com', $envelope['to']);
        $this->assertSame('Pat', $envelope['recipient_name']);
        $this->assertSame('U12 Blue', $envelope['conversation_label']);
        $this->assertSame(['Cora Coach'], $envelope['sender_names']);
        $this->assertSame(1, $envelope['message_count']);
        $this->assertSame(51, $envelope['club_id']);
    }

    /** The sender is not notified about their own message, so the coach gets nothing. */
    public function testTheSenderIsNotEmailed(): void
    {
        $this->dispatch();

        $recipients = array_column($this->sent, 'to');
        $this->assertNotContains('coach@example.com', $recipients);
    }

    public function testASentDigestIsNotSentAgainOnTheNextTick(): void
    {
        $this->assertSame(1, $this->dispatch()['sent']);

        $this->sent = [];
        $this->assertSame(0, $this->dispatch()['sent'], 'The marker must stop a re-send.');
        $this->assertSame([], $this->sent);
    }

    /**
     * A digest that failed to send must NOT be marked as delivered, or the
     * message is lost silently and the person is never told.
     */
    public function testAFailedSendIsRetriedRatherThanMarked(): void
    {
        $result = $this->dispatch(['__succeed' => false]);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(0, $result['sent']);

        $this->assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) c FROM chat_notification_state')->fetch()['c'],
            'Nothing was delivered, so nothing may be recorded as delivered.'
        );

        $this->sent = [];
        $this->assertSame(1, $this->dispatch()['sent'], 'The next tick must try again.');
    }

    /**
     * An assistant coach with no address on file, reached through team_members
     * rather than the guardian chain.
     *
     * Blanking the PARENT's email would not test this: parent standing is derived
     * by comparing users.email to guardians.email, so an empty address removes
     * them from the audience altogether and the dispatcher never sees them. The
     * recipient has to enter the audience by a route that does not depend on the
     * address, or the test passes while proving nothing.
     */
    public function testAUserWithNoEmailAddressIsSkippedNotFailed(): void
    {
        $this->pdo->exec("
            INSERT INTO users (id, email, first_name, last_name) VALUES (3, '', 'Alex', 'Assistant');
            INSERT INTO team_members (id, team_id, user_id, athlete_id, role, status)
                VALUES (703, 10, 3, NULL, 'assistant_coach', 'active');
        ");

        $this->assertContains(3, te_chat_conversation_audience($this->pdo, 55), 'Precondition: they are in the audience.');

        $result = $this->dispatch();

        $this->assertSame(1, $result['sent'], 'The parent is still notified.');
        $this->assertSame(0, $result['failed'], 'No address is an ordinary outcome, not an error to log every minute.');

        // Since the notification centre landed (phase 5) this is no longer a
        // silent skip: they are told IN THE APP, which also closes the item so
        // the dispatcher does not re-derive it as owed on every tick forever.
        $this->assertSame(1, $result['in_app'], 'The addressless assistant coach is told in-app.');
        $this->assertSame(0, $result['skipped']);

        // BOTH get a centre row — the emailed parent and the addressless coach.
        // The centre is the record of what the product told someone, not a
        // consolation prize for the people no channel could reach.
        $recorded = $this->pdo->query('SELECT user_id, type FROM notifications ORDER BY user_id')->fetchAll();
        $this->assertSame([2, 3], array_map(fn($r) => (int) $r['user_id'], $recorded));
        $this->assertSame(['chat_message', 'chat_message'], array_column($recorded, 'type'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Isolation — this tick shares a process with every other queue
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * One bad recipient must not cost the others their notification. The worker
     * also drives email, SMS, imports and calendar sync, so a throw that escapes
     * this function stops all four.
     */
    public function testOneThrowingSendDoesNotAbortTheBatch(): void
    {
        // Second family on the same team so there are two recipients.
        $this->pdo->exec("
            INSERT INTO users (id, email, first_name, last_name) VALUES (4, 'other@example.com', 'Ola', 'Other');
            INSERT INTO guardians (id, email) VALUES (501, 'other@example.com');
            INSERT INTO athlete_guardians (id, athlete_id, guardian_id) VALUES (901, 101, 501);
            INSERT INTO team_members (id, team_id, user_id, athlete_id, role, status)
                VALUES (702, 10, NULL, 101, 'player', 'active');
        ");

        $result = te_chat_dispatch_notifications($this->pdo, [
            'now' => self::NOW,
            'mailer' => function (array $envelope): bool {
                if ($envelope['to'] === 'parent@example.com') {
                    throw new \RuntimeException('SendGrid exploded');
                }
                $this->sent[] = $envelope;
                return true;
            },
        ]);

        $this->assertSame(1, $result['failed']);
        $this->assertSame(1, $result['sent'], 'The other family must still be told.');
        $this->assertSame(['other@example.com'], array_column($this->sent, 'to'));
        $this->assertNotEmpty($result['errors']);
    }

    /** The worker's tick must be wrapped, or one bad sweep takes down every queue. */
    public function testTheWorkerTickCannotTakeDownTheQueues(): void
    {
        $worker = file_get_contents(__DIR__ . '/../../workers/queue-worker.php');

        $call = strpos($worker, 'te_chat_dispatch_notifications(');
        $this->assertNotFalse($call, 'The worker must run the dispatcher.');

        $before = substr($worker, 0, $call);
        $tryPos = strrpos($before, 'try {');
        $this->assertNotFalse($tryPos, 'The dispatch call must sit inside a try block.');

        $after = substr($worker, $call);
        $catchPos = strpos($after, 'catch (Throwable');
        $this->assertNotFalse(
            $catchPos,
            'The chat tick must catch Throwable. It shares a process with email, SMS, imports and calendar '
            . 'sync — an uncaught throw here stops all four.'
        );
    }

    /** A dead handle is the documented overnight failure; the tick must check first. */
    public function testTheWorkerTickVerifiesTheDatabaseConnection(): void
    {
        $worker = file_get_contents(__DIR__ . '/../../workers/queue-worker.php');

        $call = strpos($worker, 'te_chat_dispatch_notifications(');
        $this->assertNotFalse($call);

        $this->assertNotFalse(
            strrpos(substr($worker, 0, $call), '$ensureDb();'),
            'The tick runs on a timer against a possibly-idle connection — it must ensure the handle first.'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Where the link goes
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Staff reach chat through the ChatWidget, which only mounts on the staff
     * app; families have a real route. One URL cannot serve both.
     */
    public function testStaffAndFamiliesGetDifferentLinks(): void
    {
        $conversation = ['id' => 55, 'type' => 'team', 'team_id' => 10, 'club_id' => 51];

        $this->assertStringEndsWith(
            '/dashboard',
            te_chat_notification_link($this->pdo, 1, $conversation),
            'A coach has no /parent/chat route.'
        );
        $this->assertStringEndsWith(
            '/parent/chat',
            te_chat_notification_link($this->pdo, 2, $conversation),
            'A family without a staff role must land in the parent portal.'
        );
    }

    /** Someone holding both roles is staff, matching lib/JWT.php's precedence. */
    public function testAStaffRoleWinsForSomeoneWhoIsAlsoAParent(): void
    {
        $this->pdo->exec(
            "INSERT INTO user_club_access (id, user_id, club_profile_id, role, active, revoked_at)
             VALUES (2, 2, 51, 'coach', 1, NULL)"
        );

        $this->assertStringEndsWith(
            '/dashboard',
            te_chat_notification_link($this->pdo, 2, ['id' => 55, 'type' => 'team', 'team_id' => 10, 'club_id' => 51]),
            'Matches JWT role precedence and ParentRedirect, which leaves staff on the dashboard.'
        );
    }

    /** A revoked role is not a role — the same gap lib/JWT.php had to close. */
    public function testARevokedStaffRoleDoesNotCountAsStaff(): void
    {
        $this->pdo->exec(
            "INSERT INTO user_club_access (id, user_id, club_profile_id, role, active, revoked_at)
             VALUES (3, 2, 51, 'coach', 1, '2026-07-08 00:00:00')"
        );

        $this->assertStringEndsWith(
            '/parent/chat',
            te_chat_notification_link($this->pdo, 2, ['id' => 55, 'type' => 'team', 'team_id' => 10, 'club_id' => 51]),
            'active = TRUE and revoked_at set can disagree; the revocation is the newer fact.'
        );
    }

    public function testADirectMessageIsLabelledWithTheOtherPerson(): void
    {
        $this->pdo->exec("
            INSERT INTO conversations (id, type, team_id, club_id) VALUES (77, 'direct', NULL, 51);
            INSERT INTO conversation_participants (id, conversation_id, user_id, display_name)
                VALUES (30, 77, 1, 'Cora Coach'), (31, 77, 2, 'Pat Parent');
        ");

        $label = te_chat_conversation_label(
            $this->pdo,
            ['id' => 77, 'type' => 'direct', 'team_id' => null, 'club_id' => 51],
            2
        );

        $this->assertSame('Cora Coach', $label, 'In an inbox the useful part of the subject is who it is from.');
    }
}
