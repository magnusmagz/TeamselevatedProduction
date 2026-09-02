<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;

require_once __DIR__ . '/../../lib/chat_notification_scope.php';

/**
 * Who is owed a chat notification.
 *
 * The test that matters most is testTeamChatWithNoParticipantRowDoesNotReplayHistory.
 * Team conversations are created with no participant rows, and the unread read in
 * chat-server/server.js:305 falls back to `|| 0` — so the naive implementation
 * emails a parent every message ever sent in a team chat they never opened, the
 * first time the dispatcher runs. That is the bug this whole phase exists to
 * avoid, and it is the one a unit test can actually catch.
 */
class ChatNotificationScopeTest extends TestCase
{
    private PDO $pdo;

    /** A fixed clock, so "recent" is a property of the data and not of the wall. */
    private const NOW = '2026-08-25 12:00:00';

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // Mirrors tests/fixtures/production-schema.json for the columns used here.
        // A fixture that does not mirror the live shape is worse than no fixture —
        // MergeFieldServiceTest stayed green for months against a table that had
        // been renamed out from under it.
        $this->pdo->exec("
            CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, first_name TEXT, last_name TEXT);
            CREATE TABLE guardians (id INTEGER PRIMARY KEY, email TEXT, first_name TEXT, last_name TEXT);
            CREATE TABLE athletes (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, deleted_at TEXT);
            CREATE TABLE athlete_guardians (id INTEGER PRIMARY KEY, athlete_id INTEGER, guardian_id INTEGER);
            CREATE TABLE user_guardians (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL, guardian_id INTEGER NOT NULL,
                source TEXT, confidence TEXT, linked_by INTEGER, created_at TEXT,
                UNIQUE (user_id, guardian_id)
            );
            CREATE TABLE teams (id INTEGER PRIMARY KEY, name TEXT, club_id INTEGER, primary_coach_id INTEGER);
            CREATE TABLE team_members (
                id INTEGER PRIMARY KEY, team_id INTEGER, user_id INTEGER, athlete_id INTEGER,
                role TEXT, status TEXT
            );
            CREATE TABLE conversations (
                id INTEGER PRIMARY KEY, type TEXT, team_id INTEGER, club_id INTEGER
            );
            CREATE TABLE conversation_participants (
                id INTEGER PRIMARY KEY, conversation_id INTEGER, user_id INTEGER,
                last_read_message_id INTEGER, muted INTEGER DEFAULT 0, left_at TEXT
            );
            CREATE TABLE chat_messages (
                id INTEGER PRIMARY KEY, conversation_id INTEGER, sender_id INTEGER,
                message_text TEXT, created_at TEXT, deleted_at TEXT
            );
            CREATE TABLE chat_notification_state (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL, conversation_id INTEGER NOT NULL,
                last_notified_message_id INTEGER, last_notified_at TEXT,
                last_notified_channel TEXT, created_at TEXT, updated_at TEXT,
                UNIQUE (user_id, conversation_id)
            );
            CREATE TABLE chat_notification_prefs (
                user_id INTEGER PRIMARY KEY,
                email_enabled INTEGER NOT NULL DEFAULT 1,
                push_enabled INTEGER NOT NULL DEFAULT 1,
                created_at TEXT, updated_at TEXT
            );
        ");

        // Team 10 in club 51. Coach 1 runs it. Athlete 100 plays for it, and his
        // guardian is user 2. User 3 is an assistant coach. User 9 is a club admin
        // with no other connection to the team.
        $this->pdo->exec("
            INSERT INTO users (id, email, first_name, last_name) VALUES
                (1, 'coach@example.com',     'Cora',  'Coach'),
                (2, 'parent@example.com',    'Pat',   'Parent'),
                (3, 'assistant@example.com', 'Alex',  'Assistant'),
                (9, 'admin@example.com',     'Adele', 'Admin');

            INSERT INTO guardians (id, email, first_name, last_name) VALUES
                (500, 'parent@example.com', 'Pat', 'Parent');

            INSERT INTO athletes (id, first_name, last_name) VALUES (100, 'Sam', 'Smith');
            INSERT INTO athlete_guardians (id, athlete_id, guardian_id) VALUES (900, 100, 500);

            INSERT INTO teams (id, name, club_id, primary_coach_id) VALUES (10, 'U12 Blue', 51, 1);
            INSERT INTO team_members (id, team_id, user_id, athlete_id, role, status) VALUES
                (700, 10, NULL, 100, 'player', 'active'),
                (701, 10, 3,    NULL, 'assistant_coach', 'active');

            INSERT INTO conversations (id, type, team_id, club_id) VALUES (55, 'team', 10, 51);
        ");
    }

    /** Insert a message N minutes before the fixed clock. */
    private function message(int $id, int $conversationId, int $senderId, int $minutesAgo): void
    {
        $at = (new \DateTimeImmutable(self::NOW))
            ->sub(new \DateInterval('PT' . $minutesAgo . 'M'))
            ->format('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare(
            'INSERT INTO chat_messages (id, conversation_id, sender_id, message_text, created_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$id, $conversationId, $senderId, "message {$id}", $at]);
    }

    private function pending(array $opts = []): array
    {
        return te_chat_pending_notifications($this->pdo, array_merge(['now' => self::NOW], $opts));
    }

    private function forUser(array $pending, int $userId): ?array
    {
        foreach ($pending as $row) {
            if ($row['user_id'] === $userId) {
                return $row;
            }
        }
        return null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The one that matters
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A parent who has never opened a team chat has NO participant row, so the
     * read watermark is unknowable. That must degrade to "the recent window",
     * never to "everything ever sent".
     */
    public function testTeamChatWithNoParticipantRowDoesNotReplayHistory(): void
    {
        // Two days of history, plus one recent message.
        $this->message(1, 55, 1, 60 * 48);
        $this->message(2, 55, 1, 60 * 24);
        $this->message(3, 55, 1, 60 * 6);
        $this->message(4, 55, 1, 30);

        $this->assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) c FROM conversation_participants')->fetch()['c'],
            'Precondition: team conversations have no participant rows. If this fails the fixture is wrong.'
        );

        $parent = $this->forUser($this->pending(), 2);

        $this->assertNotNull($parent, 'The parent should be notified about the recent message.');
        $this->assertSame(
            [4],
            $parent['message_ids'],
            'Only the message inside the lookback window may be owed. Anything else means a family '
            . 'gets emailed the entire history of a chat they never opened.'
        );
    }

    /**
     * Proves WHICH guard is doing the work above.
     *
     * The read watermark cannot be load-bearing here — there is no row to read it
     * from. Widen the lookback and the same data replays the full history, which
     * is the failure itself. If someone ever "optimises away" the window because
     * the watermark looks sufficient, this fails and says why.
     */
    public function testTheLookbackWindowIsWhatPreventsTheReplay(): void
    {
        $this->message(1, 55, 1, 60 * 48);
        $this->message(2, 55, 1, 60 * 24);
        $this->message(3, 55, 1, 60 * 6);
        $this->message(4, 55, 1, 30);

        $narrow = $this->forUser($this->pending(), 2);
        $this->assertSame([4], $narrow['message_ids']);

        $wide = $this->forUser($this->pending(['lookback_minutes' => 99999]), 2);
        $this->assertSame(
            [1, 2, 3, 4],
            $wide['message_ids'],
            'With the window removed the whole history comes back — so the window, not the '
            . 'watermark, is what protects a parent who has never opened the chat.'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Digest behaviour
    // ─────────────────────────────────────────────────────────────────────────

    public function testABurstCollapsesIntoOneDigestNotOnePerMessage(): void
    {
        foreach ([1, 2, 3, 4, 5, 6] as $i) {
            $this->message($i, 55, 1, 20);
        }

        $pending = $this->pending();
        $parentEntries = array_filter($pending, fn($r) => $r['user_id'] === 2);

        $this->assertCount(1, $parentEntries, 'Six messages must produce ONE digest entry, not six.');

        $parent = $this->forUser($pending, 2);
        $this->assertSame(6, $parent['message_count']);
        $this->assertSame(6, $parent['latest_message_id']);
    }

    public function testAMessageInsideTheQuietPeriodIsNotYetOwed(): void
    {
        $this->message(1, 55, 1, 1); // one minute old, exchange may still be live

        $this->assertNull(
            $this->forUser($this->pending(), 2),
            'A message younger than the quiet period must wait — the point is not to email mid-exchange.'
        );

        $this->assertNotNull(
            $this->forUser($this->pending(['quiet_minutes' => 0]), 2),
            'With no quiet period the same message is owed, so the delay is what held it.'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Who
    // ─────────────────────────────────────────────────────────────────────────

    public function testNobodyIsNotifiedAboutTheirOwnMessage(): void
    {
        $this->message(1, 55, 1, 30); // sent by the coach

        $this->assertNull($this->forUser($this->pending(), 1), 'The sender must never be notified.');
        $this->assertNotNull($this->forUser($this->pending(), 2), 'Everyone else still is.');
    }

    public function testTeamAudienceIsCoachesAndGuardiansOfThatTeam(): void
    {
        $audience = te_chat_conversation_audience($this->pdo, 55);
        sort($audience);

        $this->assertSame(
            [1, 2, 3],
            $audience,
            'Primary coach, guardian of an athlete on the team, and the active assistant coach.'
        );
    }

    /**
     * A club admin can READ every team conversation in their club. That is
     * oversight, not a subscription — notifying them would mail an admin of 16
     * teams every message in the club.
     */
    public function testClubAdminIsNotNotifiedForOversightAlone(): void
    {
        $this->message(1, 55, 1, 30);

        $this->assertNull(
            $this->forUser($this->pending(), 9),
            'Access is not a subscription. An admin gets team chat only as a coach or a guardian.'
        );
    }

    /**
     * Postgres `=` is case-sensitive and the two email columns are independently
     * editable. One capital letter severed Emily Govier's whole family
     * (CLAUDE.md, migration 071). The same join is here, so the same rule is.
     */
    public function testGuardianMatchIsCaseInsensitive(): void
    {
        $this->pdo->exec("UPDATE guardians SET email = 'Parent@Example.com' WHERE id = 500");

        $this->assertContains(
            2,
            te_chat_conversation_audience($this->pdo, 55),
            'Guardian email comparison must LOWER() both sides, or one capital letter empties a family.'
        );
    }

    /**
     * The case this phase exists for: a parent whose LOGIN address is not the
     * address on their guardian record. Allix Boyce signed in on @gmail while her
     * guardian row said @yahoo, and nothing could derive her standing — her chat
     * list was empty and she was owed no notification about it either.
     *
     * `user_guardians` (migration 072) records the relationship as a row, so the
     * two addresses no longer have to agree. This mirrors the same union in
     * chat-server/lib/guardian_identity.js; if the two ever disagree we either
     * mail someone a link to a 403, or silently stop telling a family that their
     * coach messaged them.
     */
    public function testALinkedGuardianIsNotifiedEvenWhenTheEmailsDiffer(): void
    {
        $this->pdo->exec("
            INSERT INTO users (id, email, first_name, last_name) VALUES
                (4, 'new-address@example.com', 'Robin', 'Moved');

            INSERT INTO guardians (id, email, first_name, last_name) VALUES
                (501, 'old-address@example.com', 'Robin', 'Moved');

            INSERT INTO athletes (id, first_name, last_name) VALUES (101, 'Rae', 'Moved');
            INSERT INTO athlete_guardians (id, athlete_id, guardian_id) VALUES (901, 101, 501);
            INSERT INTO team_members (id, team_id, user_id, athlete_id, role, status)
                VALUES (702, 10, NULL, 101, 'player', 'active');

            INSERT INTO user_guardians (user_id, guardian_id, source) VALUES (4, 501, 'admin');
        ");

        $this->assertContains(
            4,
            te_chat_conversation_audience($this->pdo, 55),
            'A recorded user_guardians link must confer chat standing on its own. '
            . 'Requiring the two email columns to agree is the bug migration 072 removed.'
        );
    }

    /**
     * The email branch is guarded on the USER's address being non-blank, and that
     * guard is load-bearing rather than defensive. `guardians.email` is NOT NULL
     * and 24 production rows hold `''`; in SQL `'' = ''` is true, so an account
     * with a blank address would otherwise be treated as a guardian of every one
     * of those unrelated families at once — and be mailed their team chat.
     */
    public function testABlankEmailDoesNotMatchEveryBlankGuardian(): void
    {
        $this->pdo->exec("
            INSERT INTO users (id, email, first_name, last_name) VALUES
                (5, '', 'Blank', 'Account');

            INSERT INTO guardians (id, email, first_name, last_name) VALUES
                (502, '', 'Someone', 'Else');

            INSERT INTO athletes (id, first_name, last_name) VALUES (102, 'Not', 'Related');
            INSERT INTO athlete_guardians (id, athlete_id, guardian_id) VALUES (902, 102, 502);
            INSERT INTO team_members (id, team_id, user_id, athlete_id, role, status)
                VALUES (703, 10, NULL, 102, 'player', 'active');
        ");

        $this->assertNotContains(
            5,
            te_chat_conversation_audience($this->pdo, 55),
            'A blank login address must not collapse into every blank guardian row.'
        );
    }

    public function testDirectMessageAudienceComesFromParticipantsAndExcludesWhoLeft(): void
    {
        $this->pdo->exec("
            INSERT INTO conversations (id, type, team_id, club_id) VALUES (77, 'direct', NULL, 51);
            INSERT INTO conversation_participants (id, conversation_id, user_id, left_at) VALUES
                (10, 77, 1, NULL),
                (11, 77, 2, NULL),
                (12, 77, 3, '2026-08-01 00:00:00');
        ");

        $audience = te_chat_conversation_audience($this->pdo, 77);
        sort($audience);

        $this->assertSame([1, 2], $audience, 'left_at means gone — six read-side uses already treat it that way.');
    }

    public function testATeamConversationWithNoTeamResolvesNobody(): void
    {
        $this->pdo->exec("INSERT INTO conversations (id, type, team_id, club_id) VALUES (88, 'team', NULL, 51)");

        $this->assertSame(
            [],
            te_chat_conversation_audience($this->pdo, 88),
            'Returning nobody is honest; falling back to the club would invent an audience.'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Suppression
    // ─────────────────────────────────────────────────────────────────────────

    public function testMutingAConversationStopsNotifications(): void
    {
        $this->message(1, 55, 1, 30);
        $this->pdo->exec(
            "INSERT INTO conversation_participants (id, conversation_id, user_id, muted) VALUES (20, 55, 2, 1)"
        );

        $this->assertNull($this->forUser($this->pending(), 2), 'A muted conversation must not notify.');
        $this->assertNotNull($this->forUser($this->pending(), 3), 'Muting is per user, not per conversation.');
    }

    public function testAlreadyReadMessagesAreNotNotified(): void
    {
        $this->message(1, 55, 1, 30);
        $this->message(2, 55, 1, 25);
        $this->pdo->exec(
            "INSERT INTO conversation_participants (id, conversation_id, user_id, last_read_message_id)
             VALUES (21, 55, 2, 1)"
        );

        $parent = $this->forUser($this->pending(), 2);
        $this->assertSame([2], $parent['message_ids'], 'Message 1 was already read.');
    }

    public function testTurningBothChannelsOffStopsNotifications(): void
    {
        $this->message(1, 55, 1, 30);
        $this->pdo->exec(
            "INSERT INTO chat_notification_prefs (user_id, email_enabled, push_enabled) VALUES (2, 0, 0)"
        );

        $this->assertNull($this->forUser($this->pending(), 2));
    }

    public function testAbsentPreferencesMeanOptedIn(): void
    {
        $this->message(1, 55, 1, 30);

        $this->assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) c FROM chat_notification_prefs')->fetch()['c'],
            'Precondition: nobody has a preferences row.'
        );

        $parent = $this->forUser($this->pending(), 2);
        $this->assertNotNull($parent, 'On by default — a row must not be required to be notified.');
        $this->assertTrue($parent['email_enabled']);
        $this->assertTrue($parent['push_enabled']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Not telling anyone twice
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The dispatcher runs on a timer. Without a durable marker it re-sends the
     * same digest on every tick, forever.
     */
    public function testMarkingNotifiedStopsTheNextTickResending(): void
    {
        $this->message(1, 55, 1, 30);

        $parent = $this->forUser($this->pending(), 2);
        $this->assertNotNull($parent);

        te_chat_mark_notified($this->pdo, 2, 55, $parent['latest_message_id'], 'email', self::NOW);

        $this->assertNull($this->forUser($this->pending(), 2), 'Already told — must not be told again.');
    }

    /**
     * Team conversations have no participant row, so the marker table starts
     * empty for almost everyone. A bare UPDATE would affect zero rows and the
     * same digest would go out every tick — exactly how markRead failed on team
     * chats before it became an upsert.
     */
    public function testTheNotifiedMarkerIsAnUpsertNotAnUpdate(): void
    {
        $this->message(1, 55, 1, 30);

        te_chat_mark_notified($this->pdo, 2, 55, 1, 'email', self::NOW);
        $count = (int) $this->pdo->query('SELECT COUNT(*) c FROM chat_notification_state')->fetch()['c'];
        $this->assertSame(1, $count, 'The first mark must INSERT — there is no row to update.');

        te_chat_mark_notified($this->pdo, 2, 55, 1, 'email', self::NOW);
        $count = (int) $this->pdo->query('SELECT COUNT(*) c FROM chat_notification_state')->fetch()['c'];
        $this->assertSame(1, $count, 'The second must UPDATE in place, not insert a duplicate.');
    }

    /** A newer message after the marker is still owed — the mark is a watermark, not an off switch. */
    public function testNewMessagesAfterTheMarkerAreStillOwed(): void
    {
        $this->message(1, 55, 1, 40);
        te_chat_mark_notified($this->pdo, 2, 55, 1, 'email', self::NOW);

        $this->message(2, 55, 1, 30);

        $parent = $this->forUser($this->pending(), 2);
        $this->assertNotNull($parent);
        $this->assertSame([2], $parent['message_ids']);
    }

    public function testAnUnknownChannelIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        te_chat_mark_notified($this->pdo, 2, 55, 1, 'carrier-pigeon', self::NOW);
    }

    public function testAModeratedMessageDoesNotNotify(): void
    {
        $this->message(1, 55, 1, 30);
        $this->pdo->exec("UPDATE chat_messages SET deleted_at = '2026-08-25 11:45:00' WHERE id = 1");

        $this->assertNull(
            $this->forUser($this->pending(), 2),
            'A message removed by moderation must not then be emailed out.'
        );
    }
}
