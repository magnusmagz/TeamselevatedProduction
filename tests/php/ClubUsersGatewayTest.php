<?php

use PHPUnit\Framework\TestCase;

if (!defined('TE_CLUB_USERS_LIB_ONLY')) {
    define('TE_CLUB_USERS_LIB_ONLY', true);
}
require_once __DIR__ . '/../../api/club-users-gateway.php';

/**
 * Auth double — canAccessClub() answers TRUE for everyone, so a regression to
 * that predicate turns the parent test below green. That is the point.
 */
class FakeClubUsersAuth
{
    public function __construct(private int $userId, private array $rolesByClub = [], private bool $superAdmin = false)
    {
    }
    public function getUserId(): int { return $this->userId; }
    public function isSuperAdmin(): bool { return $this->superAdmin; }
    public function canAccessClub($clubId): bool { return true; }
    public function hasRole($role, $clubId = null, $scopeType = null): bool
    {
        if ($this->superAdmin) { return true; }
        return in_array($role, $this->rolesByClub[(int) $clubId] ?? [], true);
    }
}

/**
 * api/club-users-gateway.php GET — the Club Settings -> Users list.
 *
 * Until 2026-09-06 it gated on canAccessClub(), which a `parent` row satisfies,
 * so any parent could list every staff member's name and email for their club.
 * It is club ADMIN data (PUT/DELETE already said so). It also now carries the
 * portal-status fields the access controls key on.
 */
class ClubUsersGatewayTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec("
            CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, first_name TEXT, last_name TEXT,
                password_hash TEXT, last_login_at TEXT);
            CREATE TABLE user_club_access (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER,
                club_profile_id INTEGER, role TEXT, active BOOLEAN, revoked_at TEXT, granted_at TEXT);
            CREATE TABLE magic_link_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT, token TEXT,
                expires_at TEXT, used_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP);
            CREATE TABLE audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT,
                resource_type TEXT, resource_id INTEGER, created_at TEXT);
            CREATE TABLE athletes (id INTEGER PRIMARY KEY, user_id INTEGER);
            INSERT INTO users VALUES
                (1, 'admin@club.test', 'Ada', 'Admin', 'hash', '2026-08-01 00:00:00'),
                (2, 'coach@club.test', 'Cal', 'Coach', NULL, NULL),
                (3, 'parent@club.test', 'Pam', 'Parent', 'hash', NULL),
                (4, 'other@club.test', 'Otto', 'Other', NULL, NULL);
            INSERT INTO user_club_access (user_id, club_profile_id, role, active, revoked_at) VALUES
                (1, 51, 'club_admin', 1, NULL),
                (2, 51, 'coach', 1, NULL),
                (3, 51, 'parent', 1, NULL),
                (4, 52, 'coach', 1, NULL);
            INSERT INTO magic_link_tokens (email, token, expires_at) VALUES
                ('coach@club.test:coach_invite', 't', '2099-01-01 00:00:00');
        ");
        // Postgres functions the portal-status columns use; SQLite lacks them.
        $this->pdo->sqliteCreateFunction('btrim', fn($v) => trim((string) $v), 1);
        $this->pdo->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
    }

    public function testAParentTokenIsRefusedOnGet(): void
    {
        $parent = new FakeClubUsersAuth(3, [51 => ['parent']]);
        $r = clubUsers_list($this->pdo, $parent, 51);
        $this->assertSame(403, $r['status']);
        $this->assertArrayNotHasKey('users', $r['body']);
    }

    public function testACoachIsRefusedToo(): void
    {
        $coach = new FakeClubUsersAuth(2, [51 => ['coach']]);
        $this->assertSame(403, clubUsers_list($this->pdo, $coach, 51)['status']);
    }

    public function testAnAdminOfAnotherClubIsRefused(): void
    {
        $elsewhere = new FakeClubUsersAuth(9, [52 => ['club_admin']]);
        $this->assertSame(403, clubUsers_list($this->pdo, $elsewhere, 51)['status']);
    }

    /** The real portal-status columns, with the one Postgres-only spelling SQLite rejects swapped. */
    private function sqliteStatusColumns(): string
    {
        return str_replace(
            "string_agg(DISTINCT uca2.role, '/')",
            "group_concat(uca2.role, '/')",
            te_portal_status_columns('u.email', 'u', 'coach_invite')
        );
    }

    public function testAClubAdminGetsTheListWithAccessStatus(): void
    {
        $admin = new FakeClubUsersAuth(1, [51 => ['club_admin']]);
        $r = clubUsers_list($this->pdo, $admin, 51, $this->sqliteStatusColumns());
        $this->assertSame(200, $r['status']);
        $rows = [];
        foreach ($r['body']['users'] as $u) {
            $rows[$u['role']] = $u;
        }
        $this->assertSame(['club_admin', 'coach', 'parent'], array_keys($rows), 'this club only, every role');
        $this->assertSame('active', $rows['club_admin']['status']);
        $this->assertSame('invited', $rows['coach']['status'], 'the :coach_invite evidence is read for staff');
        $this->assertSame('account_never_used', $rows['parent']['status']);
        foreach (['first_login_at', 'invited_at', 'shared_account', 'shared_reason'] as $k) {
            $this->assertArrayHasKey($k, $rows['coach']);
        }
        $this->assertSame(2, $rows['coach']['user_id']);
    }

    public function testSuperAdminPasses(): void
    {
        $r = clubUsers_list($this->pdo, new FakeClubUsersAuth(0, [], true), 51, $this->sqliteStatusColumns());
        $this->assertSame(200, $r['status']);
    }

    public function testTheGatewayNoLongerGatesOnMereClubMembership(): void
    {
        $src = file_get_contents(__DIR__ . '/../../api/club-users-gateway.php');
        $this->assertDoesNotMatchRegularExpression('/\$auth\s*->\s*canAccessClub\s*\(/', $src,
            'canAccessClub is club membership; a parent satisfies it');
        $this->assertStringContainsString('te_is_club_admin(', $src);
    }
}
