<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;

require_once __DIR__ . '/../../lib/notification_centre.php';

/**
 * The in-app notification centre.
 *
 * The `notifications` table has existed in Neon all along with nothing reading
 * or writing it. This is what finally uses it — and what stops it being a way to
 * read someone else's.
 */
class NotificationCentreTest extends TestCase
{
    private PDO $pdo;
    private const NOW = '2026-08-26 12:00:00';

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec("
            CREATE TABLE notifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, type TEXT,
                title TEXT, message TEXT, data TEXT, read_at TEXT, created_at TEXT
            );
        ");
    }

    private function create(int $userId, string $title = 'U12 Blue', array $data = [], ?string $at = null): void
    {
        te_notify_create($this->pdo, $userId, 'chat_message', $title, 'You have 1 new message.', $data, $at ?? self::NOW);
    }

    public function testANotificationIsStoredAndReadBack(): void
    {
        $this->create(2, 'U12 Blue', ['url' => '/parent/chat', 'conversation_id' => 55]);

        $list = te_notify_list($this->pdo, 2);

        $this->assertCount(1, $list);
        $this->assertSame('U12 Blue', $list[0]['title']);
        $this->assertSame('chat_message', $list[0]['type']);
        $this->assertFalse($list[0]['read']);
        $this->assertSame('/parent/chat', $list[0]['data']['url'], 'The payload must survive the round trip.');
    }

    /** A free-text type is unqueryable later; the vocabulary is deliberately narrow. */
    public function testAnUnknownTypeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        te_notify_create($this->pdo, 2, 'invented_type', 'x', 'y');
    }

    public function testTheListIsScopedToOnePerson(): void
    {
        $this->create(2);
        $this->create(3);

        $this->assertCount(1, te_notify_list($this->pdo, 2));
        $this->assertSame(1, te_notify_unread_count($this->pdo, 2));
    }

    public function testNewestFirst(): void
    {
        $this->create(2, 'older', [], '2026-08-20 09:00:00');
        $this->create(2, 'newer', [], '2026-08-26 09:00:00');

        $titles = array_column(te_notify_list($this->pdo, 2), 'title');
        $this->assertSame(['newer', 'older'], $titles);
    }

    public function testMarkingAllReadClearsTheCount(): void
    {
        $this->create(2);
        $this->create(2);

        $this->assertSame(2, te_notify_mark_read($this->pdo, 2, null, self::NOW));
        $this->assertSame(0, te_notify_unread_count($this->pdo, 2));
    }

    /**
     * Scoped by user_id AND id, so a stranger's id silently affects nothing
     * rather than clearing their unread.
     */
    public function testYouCannotMarkSomeoneElsesNotificationRead(): void
    {
        $this->create(3);
        $theirId = (int) $this->pdo->query('SELECT id FROM notifications')->fetchColumn();

        $this->assertSame(0, te_notify_mark_read($this->pdo, 2, [$theirId], self::NOW));
        $this->assertSame(1, te_notify_unread_count($this->pdo, 3), 'Their unread must be untouched.');
    }

    public function testMarkingReadIsIdempotent(): void
    {
        $this->create(2);

        $this->assertSame(1, te_notify_mark_read($this->pdo, 2, null, self::NOW));
        $this->assertSame(0, te_notify_mark_read($this->pdo, 2, null, self::NOW), 'Nothing left to mark.');
    }

    /**
     * An EMPTY list is a different statement from an ABSENT one: it means the
     * caller had nothing to mark. Treating it as "all" would clear a person's
     * whole centre on a request that asked for nothing.
     */
    public function testAnEmptyIdListMarksNothing(): void
    {
        $this->create(2);

        $this->assertSame(0, te_notify_mark_read($this->pdo, 2, [], self::NOW));
        $this->assertSame(1, te_notify_unread_count($this->pdo, 2), 'An empty request is not "everything".');
    }

    public function testUnreadOnlyFiltersTheList(): void
    {
        $this->create(2, 'read-one');
        $id = (int) $this->pdo->query('SELECT id FROM notifications')->fetchColumn();
        te_notify_mark_read($this->pdo, 2, [$id], self::NOW);
        $this->create(2, 'unread-one');

        $titles = array_column(te_notify_list($this->pdo, 2, ['unread_only' => true]), 'title');
        $this->assertSame(['unread-one'], $titles);
    }

    /** A malformed payload must degrade to an empty object, not break the list. */
    public function testABrokenPayloadDoesNotBreakTheList(): void
    {
        $this->pdo->exec(
            "INSERT INTO notifications (user_id, type, title, message, data, created_at)
             VALUES (2, 'chat_message', 'x', 'y', 'not json at all', '2026-08-26 12:00:00')"
        );

        $list = te_notify_list($this->pdo, 2);
        $this->assertSame([], $list[0]['data']);
    }

    /**
     * There is deliberately no way to ask for everyone's notifications — its
     * absence is what stops the endpoint becoming a data leak.
     */
    public function testThereIsNoUnscopedListing(): void
    {
        $src = file_get_contents(__DIR__ . '/../../lib/notification_centre.php');

        preg_match_all('/FROM notifications/', $src, $reads);
        preg_match_all('/user_id = \?/', $src, $scopes);

        $this->assertGreaterThan(0, count($reads[0]));
        $this->assertGreaterThanOrEqual(
            count($reads[0]),
            count($scopes[0]),
            'Every read of notifications must be scoped to one user id.'
        );
    }

    /** The API must take the account from the token, never the request. */
    public function testTheApiNeverTakesTheUserIdFromTheRequest(): void
    {
        $src = file_get_contents(__DIR__ . '/../../api/notifications.php');

        $this->assertStringContainsString('$auth->getUserId()', $src);
        foreach (["\$body['user_id']", "\$_GET['user_id']"] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src);
        }
    }
}
