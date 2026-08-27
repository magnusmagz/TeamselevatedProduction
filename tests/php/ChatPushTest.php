<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;

require_once __DIR__ . '/../../lib/chat_push.php';
require_once __DIR__ . '/../../lib/chat_notification_scope.php';
require_once __DIR__ . '/../../lib/chat_notification_dispatcher.php';

/**
 * Web push delivery, and the push-first / email-fallback rule.
 *
 * Two behaviours here are the ones that go wrong quietly:
 *
 *  - **Pruning.** A push service answers 404/410 for a dead endpoint and there
 *    is no advance warning. Without deleting on that response the table fills
 *    with endpoints that can never be delivered to, and every send burns a
 *    request on each one.
 *  - **Not doing both.** Someone with the app installed getting a buzz AND an
 *    email for every message is the fastest way to make them turn all of it off.
 */
class ChatPushTest extends TestCase
{
    private PDO $pdo;
    private const NOW = '2026-08-26 12:00:00';

    protected function setUp(): void
    {
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
                                        last_notified_at TEXT, last_notified_channel TEXT, clicked_at TEXT, clicked_channel TEXT,
                                        created_at TEXT, updated_at TEXT, UNIQUE (user_id, conversation_id));
            CREATE TABLE chat_notification_prefs (user_id INTEGER PRIMARY KEY, email_enabled INTEGER DEFAULT 1,
                                        push_enabled INTEGER DEFAULT 1, created_at TEXT, updated_at TEXT);
            CREATE TABLE notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, type TEXT,
                                        title TEXT, message TEXT, data TEXT, read_at TEXT, created_at TEXT);
            CREATE TABLE push_subscriptions (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, endpoint TEXT NOT NULL,
                p256dh TEXT NOT NULL, auth TEXT NOT NULL, user_agent TEXT,
                created_at TEXT, last_used_at TEXT, UNIQUE (endpoint)
            );
        ");

        $this->pdo->exec("
            INSERT INTO users (id, email, first_name, last_name) VALUES
                (1, 'coach@example.com',  'Cora', 'Coach'),
                (2, 'parent@example.com', 'Pat',  'Parent');
            INSERT INTO guardians (id, email) VALUES (500, 'parent@example.com');
            INSERT INTO athlete_guardians (id, athlete_id, guardian_id) VALUES (900, 100, 500);
            INSERT INTO teams (id, name, club_id, primary_coach_id) VALUES (10, 'U12 Blue', 51, 1);
            INSERT INTO team_members (id, team_id, user_id, athlete_id, role, status)
                VALUES (700, 10, NULL, 100, 'player', 'active');
            INSERT INTO conversations (id, type, team_id, club_id) VALUES (55, 'team', 10, 51);
            INSERT INTO chat_messages (id, conversation_id, sender_id, sender_name, message_text, created_at)
                VALUES (1, 55, 1, 'Cora Coach', 'Practice moved', '2026-08-26 11:30:00');
        ");

        // te_push_is_configured() reads env; give it keys so the code path runs.
        putenv('VAPID_PUBLIC_KEY=test-public');
        putenv('VAPID_PRIVATE_KEY=test-private');
        $_ENV['VAPID_PUBLIC_KEY'] = 'test-public';
        $_ENV['VAPID_PRIVATE_KEY'] = 'test-private';
    }

    private function subscribe(int $userId, string $endpoint): void
    {
        te_push_save_subscription($this->pdo, $userId, $endpoint, 'p256dh-key', 'auth-key', 'TestAgent/1.0');
    }

    private function countSubs(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) c FROM push_subscriptions')->fetch()['c'];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Subscriptions are per device
    // ─────────────────────────────────────────────────────────────────────────

    public function testAUserCanHaveSeveralDevices(): void
    {
        $this->subscribe(2, 'https://push.example/phone');
        $this->subscribe(2, 'https://push.example/laptop');

        $this->assertCount(
            2,
            te_push_subscriptions_for_user($this->pdo, 2),
            'Enabling notifications on a laptop must not unsubscribe the phone.'
        );
    }

    /**
     * The browser returns the SAME endpoint when the same device re-subscribes.
     * Without an upsert this either violates the unique constraint or grows a row
     * per page load — and then every notification arrives several times.
     */
    public function testResubscribingTheSameDeviceUpdatesRatherThanDuplicates(): void
    {
        $this->subscribe(2, 'https://push.example/phone');
        $this->subscribe(2, 'https://push.example/phone');

        $this->assertSame(1, $this->countSubs());
    }

    /** A shared family tablet can change hands; the newer sign-in owns it. */
    public function testAnEndpointMovesToTheAccountThatMostRecentlyClaimedIt(): void
    {
        $this->subscribe(2, 'https://push.example/tablet');
        $this->subscribe(1, 'https://push.example/tablet');

        $this->assertCount(0, te_push_subscriptions_for_user($this->pdo, 2));
        $this->assertCount(1, te_push_subscriptions_for_user($this->pdo, 1));
    }

    /** An endpoint string alone must not delete another account's device. */
    public function testUnsubscribeIsScopedToTheOwner(): void
    {
        $this->subscribe(2, 'https://push.example/phone');

        te_push_delete_subscription($this->pdo, 1, 'https://push.example/phone');
        $this->assertSame(1, $this->countSubs(), 'A different user must not be able to remove it.');

        te_push_delete_subscription($this->pdo, 2, 'https://push.example/phone');
        $this->assertSame(0, $this->countSubs(), 'The owner can.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pruning
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 404/410 means the endpoint is gone for good — cleared site data, a
     * reinstall, a new phone. Keeping it means every future send wastes a
     * request on a device that can never be reached.
     */
    public function testADeadEndpointIsDeletedNotRetriedForever(): void
    {
        $this->subscribe(2, 'https://push.example/dead');

        $result = te_push_send_to_user($this->pdo, 2, ['title' => 'x'], [
            'sender' => fn() => ['success' => false, 'expired' => true, 'reason' => 'Gone'],
        ]);

        $this->assertSame(1, $result['pruned']);
        $this->assertSame(0, $this->countSubs(), 'A gone endpoint must be removed.');
    }

    /** A transient failure is NOT a dead device and must not cost the subscription. */
    public function testATransientFailureDoesNotDeleteTheSubscription(): void
    {
        $this->subscribe(2, 'https://push.example/phone');

        $result = te_push_send_to_user($this->pdo, 2, ['title' => 'x'], [
            'sender' => fn() => ['success' => false, 'expired' => false, 'reason' => 'Service Unavailable'],
        ]);

        $this->assertSame(1, $result['failed']);
        $this->assertSame(0, $result['pruned']);
        $this->assertSame(1, $this->countSubs(), 'A 503 is not a reason to forget the device.');
    }

    public function testOneDeadDeviceDoesNotStopTheOthers(): void
    {
        $this->subscribe(2, 'https://push.example/dead');
        $this->subscribe(2, 'https://push.example/live');

        $result = te_push_send_to_user($this->pdo, 2, ['title' => 'x'], [
            'sender' => fn($row) => str_contains($row['endpoint'], 'dead')
                ? ['success' => false, 'expired' => true, 'reason' => 'Gone']
                : ['success' => true, 'expired' => false, 'reason' => ''],
        ]);

        $this->assertSame(1, $result['delivered']);
        $this->assertSame(1, $result['pruned']);
    }

    public function testAThrowingDeviceIsCountedNotEscalated(): void
    {
        $this->subscribe(2, 'https://push.example/phone');

        $result = te_push_send_to_user($this->pdo, 2, ['title' => 'x'], [
            'sender' => function () { throw new \RuntimeException('network died'); },
        ]);

        $this->assertSame(1, $result['failed'], 'A throw must not escape into the worker loop.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Push first, email as fallback — never both
    // ─────────────────────────────────────────────────────────────────────────

    public function testPushSuppressesTheEmail(): void
    {
        $this->subscribe(2, 'https://push.example/phone');
        $emails = [];

        $result = te_chat_dispatch_notifications($this->pdo, [
            'now' => self::NOW,
            'mailer' => function (array $e) use (&$emails) { $emails[] = $e; return true; },
            'pusher' => fn() => ['delivered' => 1, 'pruned' => 0, 'failed' => 0],
        ]);

        $this->assertSame(1, $result['pushed']);
        $this->assertSame(0, $result['sent']);
        $this->assertSame([], $emails, 'A buzz AND an email for every message is how people turn it all off.');
    }

    /**
     * Push and email do NOT share a delay, and this is the test that says so.
     *
     * A push five minutes late reads as broken; an email arriving mid-exchange
     * is noise. So a message two minutes old is owed to push and NOT yet to
     * email. Shipped 2026-08-26 resolving both from one call, which left push
     * waiting the full email delay — not what was agreed.
     */
    public function testPushFiresLongBeforeEmailWould(): void
    {
        // Two minutes old: past the 1-minute push window, inside the 5-minute email one.
        $this->pdo->exec("UPDATE chat_messages SET created_at = '2026-08-26 11:58:00' WHERE id = 1");

        $emails = [];
        $pushes = [];

        $result = te_chat_dispatch_notifications($this->pdo, [
            'now' => self::NOW,
            'mailer' => function (array $e) use (&$emails) { $emails[] = $e; return true; },
            'pusher' => function ($userId, array $payload) use (&$pushes) {
                $pushes[] = $payload;
                return ['delivered' => 1, 'pruned' => 0, 'failed' => 0];
            },
        ]);

        $this->assertSame(1, $result['pushed'], 'Push must not wait for the email window.');
        $this->assertSame(0, $result['sent'], 'Email must still be holding at two minutes.');
        $this->assertCount(1, $pushes);
        $this->assertSame([], $emails);
    }

    /**
     * A brand-new message pushes immediately — chat has to feel immediate, and
     * anything that reads as a delay reads as broken (Maggie, 2026-08-26).
     *
     * Bursts are deliberately NOT collapsed: every message alerts, matching
     * iMessage and WhatsApp. Also confirms the email pass is not dragged along
     * with it — the fallback keeps its own 5-minute window.
     */
    public function testABrandNewMessagePushesImmediately(): void
    {
        // 30 seconds old.
        $this->pdo->exec("UPDATE chat_messages SET created_at = '2026-08-26 11:59:30' WHERE id = 1");

        $result = te_chat_dispatch_notifications($this->pdo, [
            'now' => self::NOW,
            'mailer' => fn() => true,
            'pusher' => fn() => ['delivered' => 1, 'pruned' => 0, 'failed' => 0],
        ]);

        $this->assertSame(1, $result['pushed'], 'Push waits for nothing.');
        $this->assertSame(0, $result['sent'], 'Email still holds its own window.');
    }

    /**
     * The push quiet period is zero, so the DISPATCH TICK is what actually
     * bounds latency. If someone raises the quiet period again to "collapse
     * bursts", this fails and says why that was decided against.
     */
    public function testThePushQuietPeriodIsZero(): void
    {
        $this->assertSame(
            0,
            TE_CHAT_NOTIFY_PUSH_QUIET_MINUTES,
            'Push must not wait. Burst collapsing was considered and rejected — every message alerts.'
        );
        $this->assertGreaterThan(
            TE_CHAT_NOTIFY_PUSH_QUIET_MINUTES,
            TE_CHAT_NOTIFY_QUIET_MINUTES,
            'Email must still wait longer than push, or it stops being the fallback.'
        );
    }

    /**
     * A failed push must NOT let email fire early. The fallback belongs on the
     * email schedule, not the push one.
     */
    public function testAFailedPushDoesNotPullTheEmailForward(): void
    {
        $this->pdo->exec("UPDATE chat_messages SET created_at = '2026-08-26 11:58:00' WHERE id = 1");

        $result = te_chat_dispatch_notifications($this->pdo, [
            'now' => self::NOW,
            'mailer' => fn() => true,
            'pusher' => fn() => ['delivered' => 0, 'pruned' => 1, 'failed' => 0],
        ]);

        $this->assertSame(0, $result['pushed']);
        $this->assertSame(0, $result['sent'], 'Email still waits its full window; it is not a push retry.');
    }

    /** No device, or every device dead — this is exactly what email is for. */
    public function testEmailIsSentWhenNothingWasPushed(): void
    {
        $emails = [];

        $result = te_chat_dispatch_notifications($this->pdo, [
            'now' => self::NOW,
            'mailer' => function (array $e) use (&$emails) { $emails[] = $e; return true; },
            'pusher' => fn() => ['delivered' => 0, 'pruned' => 1, 'failed' => 0],
        ]);

        $this->assertSame(0, $result['pushed']);
        $this->assertSame(1, $result['sent']);
        $this->assertCount(1, $emails);
    }

    /** Whichever channel got there first closes the item — one shared watermark. */
    public function testAPushedItemIsNotResentByEitherChannel(): void
    {
        $result = te_chat_dispatch_notifications($this->pdo, [
            'now' => self::NOW,
            'mailer' => fn() => true,
            'pusher' => fn() => ['delivered' => 1, 'pruned' => 0, 'failed' => 0],
        ]);
        $this->assertSame(1, $result['pushed']);

        $again = te_chat_dispatch_notifications($this->pdo, [
            'now' => self::NOW,
            'mailer' => fn() => true,
            'pusher' => fn() => ['delivered' => 1, 'pruned' => 0, 'failed' => 0],
        ]);
        $this->assertSame(0, $again['pushed']);
        $this->assertSame(0, $again['sent']);

        $channel = $this->pdo->query(
            'SELECT last_notified_channel FROM chat_notification_state WHERE user_id = 2'
        )->fetchColumn();
        $this->assertSame('push', $channel, 'The marker records which channel carried it.');
    }

    public function testPushIsSkippedWhenTheUserTurnedItOff(): void
    {
        $this->pdo->exec(
            'INSERT INTO chat_notification_prefs (user_id, email_enabled, push_enabled) VALUES (2, 1, 0)'
        );
        $pushCalls = 0;

        $result = te_chat_dispatch_notifications($this->pdo, [
            'now' => self::NOW,
            'mailer' => fn() => true,
            'pusher' => function () use (&$pushCalls) { $pushCalls++; return ['delivered' => 1]; },
        ]);

        $this->assertSame(0, $pushCalls, 'Push disabled means do not even try.');
        $this->assertSame(1, $result['sent'], 'Email still applies.');
    }

    /**
     * With push in the mix, a person with no email address can still be
     * perfectly reachable — so a missing address must not disqualify them
     * before the push attempt.
     */
    public function testAUserWithNoEmailIsStillReachableByPush(): void
    {
        $this->pdo->exec("UPDATE users SET email = 'Parent@Example.com' WHERE id = 2");
        $this->pdo->exec("UPDATE guardians SET email = 'Parent@Example.com' WHERE id = 500");
        // Keep them in the audience, then remove the deliverable address.
        $this->assertContains(2, te_chat_conversation_audience($this->pdo, 55));
        $this->pdo->exec("UPDATE users SET email = '' WHERE id = 2");
        $this->pdo->exec("UPDATE guardians SET email = '' WHERE id = 500");

        $result = te_chat_dispatch_notifications($this->pdo, [
            'now' => self::NOW,
            'mailer' => fn() => true,
            'pusher' => fn() => ['delivered' => 1, 'pruned' => 0, 'failed' => 0],
        ]);

        $this->assertSame(1, $result['pushed'], 'No address is not the same as unreachable.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // What the notification says
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A push renders on a LOCK SCREEN, where content is more exposed than in an
     * inbox, not less. Same rule as the email digest, for a stronger reason.
     */
    public function testThePushBodyCarriesNoMessageText(): void
    {
        $payloads = [];

        te_chat_dispatch_notifications($this->pdo, [
            'now' => self::NOW,
            'mailer' => fn() => true,
            'pusher' => function ($userId, array $payload) use (&$payloads) {
                $payloads[] = $payload;
                return ['delivered' => 1, 'pruned' => 0, 'failed' => 0];
            },
        ]);

        $this->assertCount(1, $payloads);
        $body = $payloads[0]['body'];

        $this->assertStringNotContainsString('Practice moved', $body, 'The message text must not reach a lock screen.');
        $this->assertStringContainsString('Cora Coach', $body, 'Who it is from is the useful part.');
        $this->assertSame('U12 Blue', $payloads[0]['title']);
        $this->assertSame('chat-55', $payloads[0]['tag'], 'Tagged per conversation so repeats collapse.');
    }

    public function testNothingIsSentWhenPushIsNotConfigured(): void
    {
        putenv('VAPID_PUBLIC_KEY');
        putenv('VAPID_PRIVATE_KEY');
        unset($_ENV['VAPID_PUBLIC_KEY'], $_ENV['VAPID_PRIVATE_KEY']);

        $this->subscribe(2, 'https://push.example/phone');

        $result = te_push_send_to_user($this->pdo, 2, ['title' => 'x'], [
            'sender' => function () { throw new \RuntimeException('must not be reached'); },
        ]);

        $this->assertSame(['delivered' => 0, 'pruned' => 0, 'failed' => 0], $result);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The endpoint that stores all this
    // ─────────────────────────────────────────────────────────────────────────

    // ─────────────────────────────────────────────────────────────────────────
    // Did the notification bring anyone back?
    // ─────────────────────────────────────────────────────────────────────────

    public function testAClickIsRecordedAgainstTheNotification(): void
    {
        te_chat_mark_notified($this->pdo, 2, 55, 1, 'email', self::NOW);

        $this->assertTrue(te_chat_record_click($this->pdo, 2, 55, 'email', self::NOW));

        $row = $this->pdo->query(
            'SELECT clicked_at, clicked_channel FROM chat_notification_state WHERE user_id = 2'
        )->fetch();
        $this->assertSame(self::NOW, $row['clicked_at']);
        $this->assertSame('email', $row['clicked_channel']);
    }

    /**
     * Opening the same conversation three times is not three notifications
     * working. Counting it that way would quietly inflate every rate built on
     * this number.
     */
    public function testOnlyTheFirstClickCounts(): void
    {
        te_chat_mark_notified($this->pdo, 2, 55, 1, 'email', self::NOW);

        $this->assertTrue(te_chat_record_click($this->pdo, 2, 55, 'email', self::NOW));
        $this->assertFalse(te_chat_record_click($this->pdo, 2, 55, 'email', '2026-08-26 13:00:00'));

        $this->assertSame(
            self::NOW,
            $this->pdo->query('SELECT clicked_at FROM chat_notification_state WHERE user_id = 2')->fetchColumn(),
            'The first click stands; a later visit must not overwrite it.'
        );
    }

    /** Opening a conversation you were never notified about is ordinary use. */
    public function testAClickWithNoNotificationRecordsNothing(): void
    {
        $this->assertFalse(te_chat_record_click($this->pdo, 2, 55, 'email', self::NOW));
    }

    /**
     * The channel that EARNED the return is the question, so it is recorded
     * separately rather than assumed to match what was last sent — someone may
     * click yesterday's email after today's push.
     */
    public function testTheClickChannelIsRecordedIndependently(): void
    {
        te_chat_mark_notified($this->pdo, 2, 55, 1, 'push', self::NOW);
        te_chat_record_click($this->pdo, 2, 55, 'email', self::NOW);

        $row = $this->pdo->query(
            'SELECT last_notified_channel, clicked_channel FROM chat_notification_state WHERE user_id = 2'
        )->fetch();
        $this->assertSame('push', $row['last_notified_channel']);
        $this->assertSame('email', $row['clicked_channel']);
    }

    public function testAnUnknownClickChannelIsIgnoredNotStored(): void
    {
        te_chat_mark_notified($this->pdo, 2, 55, 1, 'email', self::NOW);

        $this->assertFalse(te_chat_record_click($this->pdo, 2, 55, 'carrier-pigeon', self::NOW));
        $this->assertNull(
            $this->pdo->query('SELECT clicked_at FROM chat_notification_state WHERE user_id = 2')->fetchColumn()
        );
    }

    /** The link has to carry what makes the click measurable. */
    public function testTheLinkCarriesTheTrackingParameterAndUtms(): void
    {
        $conversation = ['id' => 55, 'type' => 'team', 'team_id' => 10, 'club_id' => 51];

        $email = te_chat_notification_link($this->pdo, 2, $conversation, 'email');
        $this->assertStringContainsString('chat=55', $email);
        $this->assertStringContainsString('tec=email', $email);
        $this->assertStringContainsString('utm_medium=email', $email);
        $this->assertStringContainsString('utm_campaign=chat-notification', $email);

        $push = te_chat_notification_link($this->pdo, 2, $conversation, 'push');
        $this->assertStringContainsString('tec=push', $push);
        $this->assertStringContainsString('utm_medium=push', $push);
    }

    /**
     * The user id must come from the verified token, never the request body —
     * otherwise anyone could register a device against another account and
     * receive that person's notifications.
     */
    public function testTheApiNeverTakesTheUserIdFromTheRequestBody(): void
    {
        $src = file_get_contents(__DIR__ . '/../../api/push-subscriptions.php');

        $this->assertStringContainsString(
            '$auth->getUserId()',
            $src,
            'The account must come from the verified token.'
        );
        foreach (["\$body['user_id']", '$body["user_id"]', "\$_GET['user_id']"] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $src,
                'Taking user_id from the request would let anyone subscribe a device to another account.'
            );
        }
    }
}
