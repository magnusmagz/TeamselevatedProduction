<?php

use PHPUnit\Framework\TestCase;

if (!defined('TE_COACH_ACCESS_LIB_ONLY')) {
    define('TE_COACH_ACCESS_LIB_ONLY', true);
}
require_once __DIR__ . '/../../api/coach-access.php';
require_once __DIR__ . '/../../lib/magic_link.php';

/**
 * Auth double. `te_is_club_admin()` asks exactly two questions — isSuperAdmin()
 * and hasRole('club_admin', $clubId, 'club'). `canAccessClub()` answers TRUE for
 * everyone here on purpose: if the endpoint ever regresses to membership, the
 * parent-token tests below go green, which is the failure this class exists to
 * make loud.
 */
class FakeCoachAccessAuth
{
    /** @param array<int, string[]> $rolesByClub */
    public function __construct(
        private int $userId = 900,
        private array $rolesByClub = [],
        private bool $superAdmin = false
    ) {
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function isSuperAdmin(): bool
    {
        return $this->superAdmin;
    }

    public function canAccessClub($clubProfileId): bool
    {
        return true;
    }

    public function hasRole($role, $clubProfileId = null, $scopeType = null): bool
    {
        if ($this->superAdmin) {
            return true;
        }
        $roles = $this->rolesByClub[(int) $clubProfileId] ?? [];
        return in_array($role, $roles, true);
    }
}

/**
 * api/coach-access.php — the club admin's three ways to get a coach signed in
 * when the coach cannot manage it themselves: invite (or re-invite), a 24h
 * sign-in link, and a temporary password.
 *
 * Pinned, in order of what the alternative would cost:
 *
 *  - Club ADMIN standing, of the COACH's club. A parent, a coach, and an admin
 *    of another club are all 403. The target must hold an active coach role in
 *    that club — the id pair is validated, the email is never read from the body.
 *  - The token is never in a response body, and neither is the password.
 *  - A resend invalidates the earlier link; setting a password spends any
 *    outstanding invite so a stale link cannot later reset what the admin set.
 *  - Every action writes an audit row naming the actor, the target and the
 *    club — and the password row carries no password.
 */
class CoachAccessTest extends TestCase
{
    private PDO $pdo;
    private const CLUB = 100;
    private const OTHER_CLUB = 200;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT UNIQUE, first_name TEXT, last_name TEXT,
                password_hash TEXT, role TEXT, auth_provider TEXT, phone TEXT, last_login_at TEXT,
                password_set_by_admin_at TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE user_club_access (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, club_profile_id INTEGER, role TEXT,
                granted_at TEXT DEFAULT CURRENT_TIMESTAMP, granted_by INTEGER, revoked_at TEXT, revoked_by INTEGER,
                active BOOLEAN DEFAULT 1
            );
            CREATE TABLE magic_link_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT, token TEXT, expires_at TEXT, used_at TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP, invitation_id INTEGER, return_to TEXT
            );
            CREATE TABLE audit_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT, resource_type TEXT,
                resource_id INTEGER, ip_address TEXT, user_agent TEXT, details TEXT, created_at TEXT
            );
            INSERT INTO users (id, email, first_name, last_name, password_hash) VALUES
                (7,  'active@club.test',   'Ann', 'Active',   'hash'),
                (8,  'fresh@club.test',    'Fay', 'Fresh',    NULL),
                (9,  'invited@club.test',  'Ivy', 'Invited',  NULL),
                (10, '',                   'Nel', 'NoEmail',  NULL),
                (11, 'other@club.test',    'Otto','Other',    NULL),
                (12, 'parent@club.test',   'Pam', 'Parent',   'hash'),
                (13, 'revoked@club.test',  'Rex', 'Revoked',  NULL);
            INSERT INTO user_club_access (user_id, club_profile_id, role, active, revoked_at) VALUES
                (7,  100, 'coach', 1, NULL),
                (8,  100, 'coach', 1, NULL),
                (9,  100, 'coach', 1, NULL),
                (10, 100, 'coach', 1, NULL),
                (11, 200, 'coach', 1, NULL),
                (12, 100, 'parent', 1, NULL),
                (13, 100, 'coach', 0, '2026-08-01 00:00:00');
            INSERT INTO magic_link_tokens (email, token, expires_at, used_at) VALUES
                ('invited@club.test:coach_invite', 'oldtoken', '2099-01-01 00:00:00', NULL);
        ");
        $this->pdo->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
        putenv('TE_FEATURE_COACH_INVITE_EMAIL');
        unset($_ENV['TE_FEATURE_COACH_INVITE_EMAIL']);
        putenv('APP_URL=https://app.example.test');
        $_ENV['APP_URL'] = 'https://app.example.test';
        te_password_set_by_admin_probe_override(true);
    }

    protected function tearDown(): void
    {
        te_password_set_by_admin_probe_override(null);
    }

    private function admin(): FakeCoachAccessAuth
    {
        return new FakeCoachAccessAuth(900, [self::CLUB => ['club_admin']]);
    }

    private function body(int $userId, int $clubId = self::CLUB, array $extra = []): array
    {
        return ['user_id' => $userId, 'club_id' => $clubId] + $extra;
    }

    private function tokens(string $key): array
    {
        $s = $this->pdo->prepare('SELECT * FROM magic_link_tokens WHERE email = ? ORDER BY id');
        $s->execute([$key]);
        return $s->fetchAll();
    }

    private function audits(string $action): array
    {
        $s = $this->pdo->prepare('SELECT * FROM audit_log WHERE action = ? ORDER BY id');
        $s->execute([$action]);
        return $s->fetchAll();
    }

    /** A sender that records what it was handed and claims delivery. */
    private function sender(array &$calls): callable
    {
        return function (string $to, string $name, string $link) use (&$calls): bool {
            $calls[] = ['to' => $to, 'name' => $name, 'link' => $link];
            return true;
        };
    }

    // ------------------------------------------------------------ standing

    public function testAParentTokenIsRefusedOnEveryAction(): void
    {
        $parent = new FakeCoachAccessAuth(12, [self::CLUB => ['parent']]);
        $calls = [];
        foreach ([
            coachAccess_invite($this->pdo, $parent, $this->body(8), $this->sender($calls)),
            coachAccess_sendLoginLink($this->pdo, $parent, $this->body(7), $this->sender($calls)),
            coachAccess_setTemporaryPassword($this->pdo, $parent, $this->body(8, self::CLUB, ['password' => 'LongEnough12'])),
        ] as $r) {
            $this->assertSame(403, $r['status'], json_encode($r));
        }
        $this->assertSame([], $calls, 'nothing is mailed to anyone on a refusal');
        $this->assertSame([], $this->tokens('fresh@club.test:coach_invite'));
        $this->assertNull($this->pdo->query('SELECT password_hash FROM users WHERE id = 8')->fetchColumn());
    }

    public function testACoachIsNotAnAdmin(): void
    {
        $coach = new FakeCoachAccessAuth(7, [self::CLUB => ['coach']]);
        $calls = [];
        $r = coachAccess_invite($this->pdo, $coach, $this->body(8), $this->sender($calls));
        $this->assertSame(403, $r['status']);
    }

    public function testAnAdminOfAnotherClubIsRefused(): void
    {
        $elsewhere = new FakeCoachAccessAuth(901, [self::OTHER_CLUB => ['club_admin']]);
        $calls = [];
        $r = coachAccess_invite($this->pdo, $elsewhere, $this->body(8), $this->sender($calls));
        $this->assertSame(403, $r['status']);
        $r = coachAccess_setTemporaryPassword($this->pdo, $elsewhere, $this->body(8, self::CLUB, ['password' => 'LongEnough12']));
        $this->assertSame(403, $r['status']);
    }

    public function testTheTargetMustHoldAnActiveCoachRoleInThatClub(): void
    {
        $calls = [];
        // A coach of a different club, addressed through this admin's club id.
        $r = coachAccess_invite($this->pdo, $this->admin(), $this->body(11), $this->sender($calls));
        $this->assertSame(404, $r['status'], json_encode($r));
        $this->assertSame('not_a_coach', $r['body']['reason']);
        // A parent in this club is not a coach.
        $r = coachAccess_sendLoginLink($this->pdo, $this->admin(), $this->body(12), $this->sender($calls));
        $this->assertSame(404, $r['status']);
        // A revoked coach access does not count.
        $r = coachAccess_setTemporaryPassword($this->pdo, $this->admin(), $this->body(13, self::CLUB, ['password' => 'LongEnough12']));
        $this->assertSame(404, $r['status']);
        $this->assertSame([], $calls);
    }

    public function testASuperAdminPasses(): void
    {
        $super = new FakeCoachAccessAuth(1, [], true);
        $calls = [];
        $r = coachAccess_invite($this->pdo, $super, $this->body(8), $this->sender($calls));
        $this->assertSame(200, $r['status'], json_encode($r));
        $this->assertCount(1, $calls);
    }

    // ------------------------------------------------------------ mapping

    public function testEachStatusMapsToTheRightAction(): void
    {
        $this->assertSame('invite', te_coach_access_action_for_status('not_invited'));
        $this->assertSame('resend', te_coach_access_action_for_status('invited'));
        $this->assertSame('resend', te_coach_access_action_for_status('invite_expired'));
        $this->assertSame('login_link', te_coach_access_action_for_status('active'));
        $this->assertSame('login_link', te_coach_access_action_for_status('account_never_used'));
        $this->assertNull(te_coach_access_action_for_status('no_email'));
        $this->assertNull(te_coach_access_action_for_status('anything_else'));
    }

    // ------------------------------------------------------------ invite

    public function testInviteMintsATokenMailsItAndAuditsTheActor(): void
    {
        $calls = [];
        $r = coachAccess_invite($this->pdo, $this->admin(), $this->body(8), $this->sender($calls));

        $this->assertSame(200, $r['status'], json_encode($r));
        $this->assertTrue($r['body']['success']);
        $this->assertSame('sent', $r['body']['outcome']);
        $this->assertSame('fresh@club.test', $r['body']['email']);
        $this->assertSame('7 days', $r['body']['expires_in']);

        $t = $this->tokens('fresh@club.test:coach_invite');
        $this->assertCount(1, $t);
        $this->assertNull($t[0]['used_at']);
        $this->assertCount(1, $calls);
        $this->assertSame('fresh@club.test', $calls[0]['to']);
        $this->assertStringContainsString($t[0]['token'], $calls[0]['link']);

        $a = $this->audits('coach_invite_sent');
        $this->assertCount(1, $a);
        $this->assertEquals(900, $a[0]['user_id'], 'the admin who pressed the button is the actor');
        $this->assertEquals(8, $a[0]['resource_id']);
        $this->assertEquals(self::CLUB, json_decode($a[0]['details'], true)['club_id']);
        $this->assertSame([], $this->audits('coach_invite_resent'));
    }

    public function testResendInvalidatesTheOldTokenAndAuditsAsAResend(): void
    {
        $calls = [];
        $r = coachAccess_invite($this->pdo, $this->admin(), $this->body(9), $this->sender($calls));

        $this->assertSame(200, $r['status'], json_encode($r));
        $this->assertSame('resent', $r['body']['outcome']);

        $t = $this->tokens('invited@club.test:coach_invite');
        $this->assertCount(2, $t);
        $this->assertSame('oldtoken', $t[0]['token']);
        $this->assertNotNull($t[0]['used_at'], 'the earlier link no longer works');
        $this->assertNull($t[1]['used_at']);
        $this->assertStringContainsString($t[1]['token'], $calls[0]['link']);
        $this->assertStringNotContainsString('oldtoken', $calls[0]['link']);

        $this->assertCount(1, $this->audits('coach_invite_resent'));
        $this->assertSame([], $this->audits('coach_invite_sent'), 'one row per action, not two');
    }

    public function testInviteRefusesAnAccountThatCanAlreadySignIn(): void
    {
        $calls = [];
        $r = coachAccess_invite($this->pdo, $this->admin(), $this->body(7), $this->sender($calls));
        $this->assertSame(409, $r['status']);
        $this->assertSame('already_active', $r['body']['reason']);
        $this->assertSame([], $calls);
        $this->assertSame([], $this->tokens('active@club.test:coach_invite'));
    }

    public function testInviteHonoursTheKillSwitchAndNeverReportsSuccess(): void
    {
        putenv('TE_FEATURE_COACH_INVITE_EMAIL=off');
        $_ENV['TE_FEATURE_COACH_INVITE_EMAIL'] = 'off';
        try {
            $calls = [];
            $r = coachAccess_invite($this->pdo, $this->admin(), $this->body(8), $this->sender($calls));
            $this->assertSame(503, $r['status'], json_encode($r));
            $this->assertFalse($r['body']['success']);
            $this->assertSame('COACH_INVITE_EMAIL', $r['body']['feature_disabled']);
            $this->assertSame([], $calls);
        } finally {
            putenv('TE_FEATURE_COACH_INVITE_EMAIL');
            unset($_ENV['TE_FEATURE_COACH_INVITE_EMAIL']);
        }
    }

    // ------------------------------------------------------------ login link

    public function testLoginLinkMintsA24hTokenAndAudits(): void
    {
        $calls = [];
        $before = time();
        $r = coachAccess_sendLoginLink($this->pdo, $this->admin(), $this->body(7), $this->sender($calls));

        $this->assertSame(200, $r['status'], json_encode($r));
        $this->assertSame('24 hours', $r['body']['expires_in']);
        $this->assertSame('active@club.test', $r['body']['email']);

        $t = $this->tokens('active@club.test');
        $this->assertCount(1, $t, 'a magic-link row is keyed on the bare address, like the login page mints');
        $expiry = strtotime($t[0]['expires_at']);
        $this->assertGreaterThanOrEqual($before + TE_MAGIC_LINK_TTL_ADMIN_SENT - 5, $expiry);
        $this->assertLessThanOrEqual(time() + TE_MAGIC_LINK_TTL_ADMIN_SENT + 5, $expiry);

        $this->assertCount(1, $calls);
        $this->assertSame('https://app.example.test/verify-magic-link?token=' . $t[0]['token'], $calls[0]['link']);

        $a = $this->audits('portal_login_link_sent');
        $this->assertCount(1, $a);
        $this->assertEquals(900, $a[0]['user_id']);
        $this->assertEquals(7, $a[0]['resource_id']);
        $d = json_decode($a[0]['details'], true);
        $this->assertEquals(self::CLUB, $d['club_id']);
        $this->assertTrue($d['emailed']);
    }

    public function testLoginLinkRefusesAnAccountWithNoPassword(): void
    {
        $calls = [];
        $r = coachAccess_sendLoginLink($this->pdo, $this->admin(), $this->body(8), $this->sender($calls));
        $this->assertSame(409, $r['status']);
        $this->assertSame('not_active', $r['body']['reason']);
        $this->assertSame([], $calls);
        $this->assertSame([], $this->tokens('fresh@club.test'));
    }

    public function testNoEmailIsA422OnEveryMailingAction(): void
    {
        $calls = [];
        $r = coachAccess_invite($this->pdo, $this->admin(), $this->body(10), $this->sender($calls));
        $this->assertSame(422, $r['status']);
        $this->assertSame('no_email', $r['body']['reason']);
        $r = coachAccess_sendLoginLink($this->pdo, $this->admin(), $this->body(10), $this->sender($calls));
        $this->assertSame(422, $r['status']);
        $this->assertSame('no_email', $r['body']['reason']);
        $this->assertSame([], $calls);
    }

    // ------------------------------------------------------------ password

    public function testSetPasswordWritesBcryptSpendsTheInviteAndAuditsWithoutThePassword(): void
    {
        $r = coachAccess_setTemporaryPassword(
            $this->pdo, $this->admin(), $this->body(9, self::CLUB, ['password' => 'Temporary-9x'])
        );
        $this->assertSame(200, $r['status'], json_encode($r));
        $this->assertTrue($r['body']['success']);

        $u = $this->pdo->query('SELECT password_hash, auth_provider, password_set_by_admin_at FROM users WHERE id = 9')->fetch();
        $this->assertStringStartsWith('$2y$', (string) $u['password_hash'], 'bcrypt via PASSWORD_DEFAULT');
        $this->assertTrue(password_verify('Temporary-9x', (string) $u['password_hash']));
        $this->assertSame('password', $u['auth_provider']);
        $this->assertNotNull($u['password_set_by_admin_at'], 'the banner reads this');

        $t = $this->tokens('invited@club.test:coach_invite');
        $this->assertCount(1, $t);
        $this->assertNotNull($t[0]['used_at'], 'a stale invite must not later reset what the admin just set');

        $a = $this->audits('password_set_by_admin');
        $this->assertCount(1, $a);
        $this->assertEquals(900, $a[0]['user_id']);
        $this->assertEquals(9, $a[0]['resource_id']);
        $this->assertSame('users', $a[0]['resource_type']);
        $d = json_decode($a[0]['details'], true);
        $this->assertEquals(self::CLUB, $d['club_id']);
        $this->assertEquals(9, $d['target_user_id']);
        $this->assertStringNotContainsString('Temporary-9x', $a[0]['details']);
        $this->assertStringNotContainsString($u['password_hash'], $a[0]['details']);
    }

    public function testSetPasswordDegradesWhenTheColumnIsNotAppliedYet(): void
    {
        te_password_set_by_admin_probe_override(false);
        $r = coachAccess_setTemporaryPassword(
            $this->pdo, $this->admin(), $this->body(8, self::CLUB, ['password' => 'Temporary-9x'])
        );
        $this->assertSame(200, $r['status'], json_encode($r));
        $u = $this->pdo->query('SELECT password_hash, password_set_by_admin_at FROM users WHERE id = 8')->fetch();
        $this->assertTrue(password_verify('Temporary-9x', (string) $u['password_hash']));
        $this->assertNull($u['password_set_by_admin_at'], 'not written when the migration has not run');
    }

    public function testSetPasswordRefusesAShortPasswordAndWritesNothing(): void
    {
        $r = coachAccess_setTemporaryPassword(
            $this->pdo, $this->admin(), $this->body(9, self::CLUB, ['password' => 'short9'])
        );
        $this->assertSame(422, $r['status']);
        $this->assertSame('weak_password', $r['body']['reason']);
        $this->assertNull($this->pdo->query('SELECT password_hash FROM users WHERE id = 9')->fetchColumn());
        $this->assertNull($this->tokens('invited@club.test:coach_invite')[0]['used_at']);
        $this->assertSame([], $this->audits('password_set_by_admin'));
    }

    public function testSetPasswordWorksForAnAccountWithNoEmail(): void
    {
        // The password is the one door that does not need an address.
        $r = coachAccess_setTemporaryPassword(
            $this->pdo, $this->admin(), $this->body(10, self::CLUB, ['password' => 'Temporary-9x'])
        );
        $this->assertSame(200, $r['status'], json_encode($r));
    }

    // ------------------------------------------------------------ secrets

    public function testNoResponseBodyEverCarriesATokenOrAPassword(): void
    {
        $calls = [];
        $bodies = [
            coachAccess_invite($this->pdo, $this->admin(), $this->body(8), $this->sender($calls)),
            coachAccess_invite($this->pdo, $this->admin(), $this->body(9), $this->sender($calls)),
            coachAccess_sendLoginLink($this->pdo, $this->admin(), $this->body(7), $this->sender($calls)),
            coachAccess_setTemporaryPassword($this->pdo, $this->admin(), $this->body(9, self::CLUB, ['password' => 'Temporary-9x'])),
        ];
        $tokens = array_column($this->pdo->query('SELECT token FROM magic_link_tokens')->fetchAll(), 'token');
        $this->assertNotEmpty($tokens);
        foreach ($bodies as $r) {
            $json = json_encode($r['body']);
            $this->assertArrayNotHasKey('token', $r['body']);
            $this->assertArrayNotHasKey('link', $r['body']);
            $this->assertArrayNotHasKey('password', $r['body']);
            $this->assertStringNotContainsString('Temporary-9x', $json);
            foreach ($tokens as $tok) {
                $this->assertStringNotContainsString($tok, $json);
            }
        }
    }

    public function testTheHandlerSourceNeverEchoesATokenOrPasswordKey(): void
    {
        $src = file_get_contents(__DIR__ . '/../../api/coach-access.php');
        $src = preg_replace('!/\*.*?\*/!s', '', $src);
        $src = preg_replace('/^[ \t]*\/\/.*$/m', '', $src);
        $this->assertDoesNotMatchRegularExpression("/['\"]token['\"]\s*=>/", $src);
        $this->assertDoesNotMatchRegularExpression("/['\"]link['\"]\s*=>/", $src);
        $this->assertDoesNotMatchRegularExpression("/['\"]password['\"]\s*=>/", $src);
        $this->assertDoesNotMatchRegularExpression("/['\"]password_hash['\"]\s*=>/", $src);
        $this->assertStringContainsString('requireAuth', $src);
        $this->assertStringContainsString('te_is_club_admin(', $src);
        $this->assertDoesNotMatchRegularExpression('/\$auth\s*->\s*canAccessClub\s*\(/', $src);
        $this->assertStringNotContainsString('JWT::decode(', $src);
    }

    // ------------------------------------------------------------ profile

    public function testTheProfilePasswordChangeClearsTheAdminSetMark(): void
    {
        $src = file_get_contents(__DIR__ . '/../../api/user-profile.php');
        $this->assertStringContainsString('password_set_by_admin_at', $src);
        $this->assertStringContainsString('te_password_set_by_admin_column_present(', $src);
        // The clear rides on the same statement as the new hash — one UPDATE, so
        // the mark cannot survive a password change. It is interpolated (the
        // column may not exist yet), so pin both the fragment and its use.
        $this->assertStringContainsString("password_set_by_admin_at = NULL", $src);
        $this->assertMatchesRegularExpression(
            '/password_hash\s*=\s*:password_hash[^;]*\$clearMark/s',
            $src
        );
    }
}
