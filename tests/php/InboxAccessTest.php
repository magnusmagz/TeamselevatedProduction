<?php

use PHPUnit\Framework\TestCase;

if (!defined('TE_INBOX_LIB_ONLY')) {
    define('TE_INBOX_LIB_ONLY', true);
}
require_once __DIR__ . '/../../api/inbox.php';

class FakeInboxAuth
{
    public function __construct(private array $clubs, private array $adminClubs) {}
    public function canAccessClub($c): bool { return in_array((int) $c, $this->clubs, true); }
    public function hasRole($role, $c, $lvl): bool
    {
        return $role === 'club_admin' && in_array((int) $c, $this->adminClubs, true);
    }
}

/**
 * Who can open the SMS inbox (M3 of docs/sms-inbox-scope.md).
 *
 * Three gates in order — club access, club admin, and the per-club flag — and the
 * flag is enforced HERE rather than only by hiding a nav item. Hiding a link is
 * not an access control, and a club that has not switched the inbox on has not
 * agreed to anyone reading their families' replies in this surface.
 */
class InboxAccessTest extends TestCase
{
    private PDO $pdo;

    private const KANSAS = 51;   // inbox enabled
    private const OTHER  = 32;   // has a number, inbox NOT enabled

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("
            CREATE TABLE sms_phone_numbers (id INTEGER PRIMARY KEY, club_profile_id INTEGER NOT NULL,
                user_id INTEGER, phone_number TEXT, is_active INTEGER NOT NULL DEFAULT 1,
                inbox_enabled INTEGER NOT NULL DEFAULT 0);
        ");
        $this->pdo->exec("INSERT INTO sms_phone_numbers (id, club_profile_id, user_id, phone_number, is_active, inbox_enabled) VALUES
            (1, 51, NULL, '+17854654221', 1, 1),
            (2, 32, NULL, '+13605164604', 1, 0),
            (3, 60, NULL, '+15005550001', 0, 1)");   // enabled but the number is retired
    }

    private function check($auth, $club): ?array
    {
        return inboxAuthError($auth, $this->pdo, $club);
    }

    private function admin(array $clubs = [self::KANSAS, self::OTHER]): FakeInboxAuth
    {
        return new FakeInboxAuth($clubs, $clubs);
    }

    private function coach(array $clubs = [self::KANSAS]): FakeInboxAuth
    {
        return new FakeInboxAuth($clubs, []);
    }

    public function testAnAdminOfAnEnabledClubGetsIn(): void
    {
        $this->assertNull($this->check($this->admin(), self::KANSAS));
    }

    // ── M3.5 ─────────────────────────────────────────────────────────────────
    public function testACoachIsRefused(): void
    {
        $err = $this->check($this->coach(), self::KANSAS);

        $this->assertNotNull($err);
        $this->assertSame(403, $err['code']);
        $this->assertStringContainsString('club admins', $err['error']);
    }

    // ── M3.6 ─────────────────────────────────────────────────────────────────
    public function testAnAdminOfAnotherClubIsRefused(): void
    {
        $outsider = new FakeInboxAuth([self::OTHER], [self::OTHER]);
        $err = $this->check($outsider, self::KANSAS);

        $this->assertNotNull($err);
        $this->assertSame(403, $err['code']);
        $this->assertStringContainsString('Access denied', $err['error']);
    }

    // ── M3.7 ─────────────────────────────────────────────────────────────────
    /**
     * The flag is what makes the auto-reply copy honest: "this number is not
     * monitored" stays true precisely while this returns an error.
     */
    public function testAClubWithTheFlagOffIsRefusedEvenForItsOwnAdmin(): void
    {
        $err = $this->check($this->admin(), self::OTHER);

        $this->assertNotNull($err);
        $this->assertSame(404, $err['code']);
        $this->assertStringContainsString('not enabled', $err['error']);
    }

    public function testAClubWithNoNumberAtAllIsRefused(): void
    {
        $err = $this->check(new FakeInboxAuth([99], [99]), 99);

        $this->assertNotNull($err);
        $this->assertSame(404, $err['code']);
    }

    /**
     * The flag lives on the ACTIVE club number. A club whose number was retired
     * has no live sender, so there is nothing to run an inbox against.
     */
    public function testAnEnabledButRetiredNumberDoesNotOpenTheInbox(): void
    {
        $err = $this->check(new FakeInboxAuth([60], [60]), 60);

        $this->assertNotNull($err);
        $this->assertSame(404, $err['code']);
    }

    public function testAMissingClubIdIsARequestError(): void
    {
        $err = $this->check($this->admin(), null);

        $this->assertSame(400, $err['code']);
    }

    // ── The machine/human distinction ────────────────────────────────────────
    /**
     * "Needs reply" hinges on this: an auto-reply is outbound with user_id NULL,
     * so it is excluded from "the newest message a human or family sent" and a
     * thread the robot answered stays in the queue. Without the FILTER, recording
     * the auto-reply (which M3 added) would have emptied the inbox instead of
     * filling it.
     */
    public function testNeedsReplyIgnoresAutomatedMessages(): void
    {
        $sql = inboxLatestHumanMessageSql();

        $this->assertStringContainsString("direction = 'inbound'", $sql);
        $this->assertStringContainsString('user_id IS NOT NULL', $sql);
    }
}
