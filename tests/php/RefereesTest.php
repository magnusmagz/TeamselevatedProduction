<?php

use PHPUnit\Framework\TestCase;

if (!defined('TE_REFEREES_LIB_ONLY')) {
    define('TE_REFEREES_LIB_ONLY', true);
}
require_once __DIR__ . '/../../api/referees.php';

/**
 * Real AuthMiddleware contexts (te_event_staff_standing is typed on the
 * class). Roles are [clubId => [role, ...]].
 *
 * @param array<int, string[]> $rolesByClub
 */
function referees_test_auth(int $userId, array $rolesByClub = []): AuthMiddleware
{
    $roles = [];
    foreach ($rolesByClub as $clubId => $names) {
        foreach ($names as $name) {
            $roles[] = ['role' => $name, 'scope_type' => 'club', 'scope_id' => (int) $clubId];
        }
    }
    return AuthMiddleware::fromContext(['user_id' => $userId, 'email' => "user{$userId}@test", 'roles' => $roles]);
}

/**
 * Referees (Maggie, 2026-09-08; migration 099) — executed against SQLite
 * through the real api/referees.php handlers.
 *
 * Fixture: club 100 (admin 60, coach 50 of team 10, parent 80) and an
 * unrelated club 200 (admin 90, coach 91 of team 20). Referee user 300 is in
 * BOTH clubs' directories (rows 1 and 3); referee row 2 (club 100, no account)
 * and row 4 (club 100, archived). Games: 500 upcoming club 100 (team 10),
 * 501 played club 100, 502 practice club 100, 503 upcoming club 200 (team 20),
 * 504 upcoming club 100 with a National minimum, 505 upcoming club 100 with a
 * center already assigned. "Today" is 2026-09-08.
 */
class RefereesTest extends TestCase
{
    private const TODAY = '2026-09-08';
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = self::basePdo();
        $this->migrate($this->pdo);
        $this->seed($this->pdo);
    }

    private static function basePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        // AuditLogger writes NOW(); give SQLite one so audit rows land and can be asserted.
        $pdo->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'));
        $pdo->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT UNIQUE, first_name TEXT, last_name TEXT,
                password_hash TEXT, role TEXT, auth_provider TEXT, phone TEXT, last_login_at TEXT,
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
            CREATE TABLE club_profile (id INTEGER PRIMARY KEY, name TEXT, primary_color TEXT);
            CREATE TABLE teams (id INTEGER PRIMARY KEY, name TEXT, club_id INTEGER, primary_coach_id INTEGER,
                primary_color TEXT, deleted_at TEXT);
            CREATE TABLE team_members (id INTEGER PRIMARY KEY, team_id INTEGER, user_id INTEGER,
                athlete_id INTEGER, role TEXT, status TEXT);
            CREATE TABLE venues (id INTEGER PRIMARY KEY, name TEXT, address TEXT, city TEXT);
            CREATE TABLE fields (id INTEGER PRIMARY KEY, venue_id INTEGER, name TEXT, active INTEGER DEFAULT 1, field_size TEXT);
            CREATE TABLE calendar_events (id INTEGER PRIMARY KEY, club_id INTEGER, name TEXT, type TEXT,
                event_date TEXT, start_time TEXT, end_time TEXT, venue_id INTEGER, field_id INTEGER, location TEXT,
                opponent_name TEXT, status TEXT, min_referee_grade TEXT,
                allow_referee_self_assign INTEGER NOT NULL DEFAULT 1);
            CREATE TABLE calendar_event_teams (id INTEGER PRIMARY KEY, event_id INTEGER, team_id INTEGER);
        ");
        return $pdo;
    }

    /** The 099 tables, in SQLite. Columns mirror the migration. */
    private function migrate(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE referees (
                id INTEGER PRIMARY KEY AUTOINCREMENT, club_id INTEGER NOT NULL, user_id INTEGER,
                first_name TEXT NOT NULL, last_name TEXT NOT NULL, email TEXT, phone TEXT, grade TEXT,
                certification_level TEXT, notes TEXT, active INTEGER NOT NULL DEFAULT 1, archived_at TEXT,
                created_by INTEGER, created_at TEXT, updated_at TEXT
            );
            CREATE UNIQUE INDEX referees_club_lower_email_uniq ON referees (club_id, lower(email)) WHERE email IS NOT NULL;
            CREATE TABLE game_referees (
                id INTEGER PRIMARY KEY AUTOINCREMENT, calendar_event_id INTEGER NOT NULL, referee_id INTEGER NOT NULL,
                role TEXT NOT NULL DEFAULT 'referee' CHECK (role IN ('referee','center','assistant','fourth')),
                assigned_by INTEGER, assigned_at TEXT, self_assigned INTEGER NOT NULL DEFAULT 0,
                grade_override INTEGER NOT NULL DEFAULT 0,
                conflict_override INTEGER NOT NULL DEFAULT 0,
                UNIQUE (calendar_event_id, referee_id)
            );
        ");
    }

    private function seed(PDO $pdo): void
    {
        $pdo->exec("INSERT INTO club_profile (id, name, primary_color) VALUES (100, 'Home FC', '#112233'), (200, 'Away United', '#445566')");
        $pdo->exec("INSERT INTO users (id, email, first_name, last_name, password_hash) VALUES
            (50, 'coach50@club.test', 'Cora', 'Coach', 'hash'),
            (60, 'admin@club.test', 'Ada', 'Admin', 'hash'),
            (80, 'parent@family.test', 'Pat', 'Parent', 'hash'),
            (90, 'admin@other.test', 'Otto', 'Other', 'hash'),
            (91, 'coach91@other.test', 'Cy', 'Elsewhere', 'hash'),
            (300, 'ref@whistle.test', 'Ray', 'Whistle', 'hash'),
            (301, 'existing@account.test', 'Eve', 'Existing', NULL)");
        $pdo->exec("INSERT INTO user_club_access (user_id, club_profile_id, role) VALUES
            (50, 100, 'coach'), (60, 100, 'club_admin'), (80, 100, 'parent'), (90, 200, 'club_admin'), (91, 200, 'coach'),
            (300, 100, 'referee'), (300, 200, 'referee')");
        $pdo->exec("INSERT INTO teams (id, name, club_id, primary_coach_id, primary_color) VALUES
            (10, 'U12 Blue', 100, 50, '#0000ff'), (11, 'U14 Red', 100, NULL, '#ff0000'), (20, 'Other U12', 200, 91, NULL)");
        $pdo->exec("INSERT INTO venues (id, name, address, city) VALUES (7, 'North Park', '1 Park Rd', 'Wichita')");
        $pdo->exec("INSERT INTO fields (id, venue_id, name, active, field_size) VALUES (70, 7, 'Field 2', 1, '9v9')");
        $pdo->exec("INSERT INTO calendar_events (id, club_id, name, type, event_date, start_time, venue_id, opponent_name, status, min_referee_grade) VALUES
            (500, 100, 'League match', 'game', '2026-09-20', '10:00', 7, 'Rivals FC', 'scheduled', NULL),
            (501, 100, 'Played match', 'game', '2026-09-01', '10:00', 7, 'Old FC', 'scheduled', NULL),
            (502, 100, 'Practice', 'practice', '2026-09-20', '18:00', NULL, NULL, 'scheduled', NULL),
            (503, 200, 'Other league', 'game', '2026-09-15', '09:00', NULL, 'Elsewhere', 'scheduled', NULL),
            (504, 100, 'Cup final', 'game', '2026-09-25', '14:00', 7, 'Cup FC', 'scheduled', 'National'),
            (505, 100, 'Covered match', 'game', '2026-09-22', '12:00', 7, 'Done FC', 'scheduled', NULL)");
        // 500 is on a named field (migration 100); the rest have a venue only, or nothing.
        $pdo->exec("UPDATE calendar_events SET field_id = 70 WHERE id = 500");
        $pdo->exec("INSERT INTO calendar_event_teams (event_id, team_id) VALUES (500, 10), (501, 10), (502, 10), (503, 20), (504, 11), (505, 10)");
        $pdo->exec("INSERT INTO referees (id, club_id, user_id, first_name, last_name, email, phone, grade, created_at, updated_at) VALUES
            (1, 100, 300, 'Ray', 'Whistle', 'ref@whistle.test', '+13165550100', 'Regional', 'x', 'x'),
            (2, 100, NULL, 'Nora', 'Flag', 'nora@flag.test', NULL, 'Grade 5', 'x', 'x'),
            (3, 200, 300, 'Ray', 'Whistle', 'ref@whistle.test', NULL, 'National', 'x', 'x'),
            (4, 100, NULL, 'Arch', 'Ived', 'arch@ived.test', NULL, NULL, 'x', 'x')");
        $pdo->exec("UPDATE referees SET archived_at = '2026-08-01', active = 0 WHERE id = 4");
        $pdo->exec("INSERT INTO game_referees (calendar_event_id, referee_id, role, assigned_by, assigned_at) VALUES (505, 2, 'center', 60, 'x')");
    }

    // ---------------------------------------------------------------- actors

    private function admin(): AuthMiddleware   { return referees_test_auth(60, [100 => ['club_admin']]); }
    private function coach(): AuthMiddleware   { return referees_test_auth(50, [100 => ['coach']]); }
    private function parent(): AuthMiddleware  { return referees_test_auth(80, [100 => ['parent']]); }
    private function otherAdmin(): AuthMiddleware { return referees_test_auth(90, [200 => ['club_admin']]); }
    private function otherCoach(): AuthMiddleware { return referees_test_auth(91, [200 => ['coach']]); }
    private function referee(): AuthMiddleware { return referees_test_auth(300, [100 => ['referee'], 200 => ['referee']]); }

    private function audits(string $action): array
    {
        $s = $this->pdo->prepare('SELECT * FROM audit_log WHERE action = ? ORDER BY id');
        $s->execute([$action]);
        return $s->fetchAll();
    }

    // ---------------------------------------------------------------- directory

    public function testAdminCreatesUpdatesArchivesAndRestores(): void
    {
        $r = referees_create($this->pdo, $this->admin(), [
            'club_id' => 100, 'first_name' => ' Sam ', 'last_name' => 'Line', 'email' => 'Sam.Line@Example.com',
            'phone' => '(316) 555-0199', 'grade' => 'Grassroots', 'certification_level' => 'Safe Sport', 'notes' => 'Weekends only',
        ]);
        $this->assertSame(201, $r['status'], json_encode($r['body']));
        $ref = $r['body']['referee'];
        $this->assertSame('Sam', $ref['first_name']);
        $this->assertSame('sam.line@example.com', $ref['email'], 'email is lowercased');
        $this->assertSame('+13165550199', $ref['phone'], 'phone is stored E.164');
        $this->assertSame('Grassroots', $ref['grade']);
        $this->assertNull($ref['user_id'], 'no account on that address');
        $this->assertCount(1, $this->audits('referee_created'));

        $u = referees_update($this->pdo, $this->admin(), ['id' => $ref['id'], 'grade' => 'Regional', 'phone' => '']);
        $this->assertSame(200, $u['status'], json_encode($u['body']));
        $this->assertSame('Regional', $u['body']['referee']['grade'], 'grade round-trips');
        $this->assertNull($u['body']['referee']['phone'], 'blank phone clears');
        $this->assertSame('Safe Sport', $u['body']['referee']['certification_level'], 'a partial save does not blank an unsent field');
        $this->assertCount(1, $this->audits('referee_updated'));

        $a = referees_set_archived($this->pdo, $this->admin(), ['id' => $ref['id']], true);
        $this->assertSame(200, $a['status']);
        $this->assertNotNull($a['body']['referee']['archived_at']);
        $this->assertFalse($a['body']['referee']['active']);
        $this->assertCount(1, $this->audits('referee_archived'));

        $list = referees_list($this->pdo, $this->admin(), ['club_id' => 100]);
        $this->assertNotContains($ref['id'], array_column($list['body']['referees'], 'id'), 'archived rows are hidden by default');
        $listAll = referees_list($this->pdo, $this->admin(), ['club_id' => 100, 'include_archived' => '1']);
        $this->assertContains($ref['id'], array_column($listAll['body']['referees'], 'id'));

        $s = referees_set_archived($this->pdo, $this->admin(), ['id' => $ref['id']], false);
        $this->assertNull($s['body']['referee']['archived_at']);
        $this->assertTrue($s['body']['referee']['active']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM referees WHERE id = ' . (int) $ref['id'])->fetchColumn(), 'never hard-deleted');
    }

    public function testListCarriesGradeAndIsSortedByName(): void
    {
        $list = referees_list($this->pdo, $this->admin(), ['club_id' => 100]);
        $this->assertSame(200, $list['status']);
        $rows = $list['body']['referees'];
        $this->assertSame(['Flag', 'Whistle'], array_column($rows, 'last_name'));
        $this->assertSame(['Grade 5', 'Regional'], array_column($rows, 'grade'), 'grade is a column on the list');
        $this->assertContains('Grassroots', $list['body']['grades'], 'the select options come from the server');
    }

    public function testUnreadablePhoneIs422AndNothingIsWritten(): void
    {
        $r = referees_create($this->pdo, $this->admin(), [
            'club_id' => 100, 'first_name' => 'Bad', 'last_name' => 'Phone', 'phone' => 'call me',
        ]);
        $this->assertSame(422, $r['status']);
        $this->assertStringContainsString('phone', $r['body']['error']);
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM referees WHERE last_name = 'Phone'")->fetchColumn());

        $u = referees_update($this->pdo, $this->admin(), ['id' => 2, 'phone' => 'nope']);
        $this->assertSame(422, $u['status']);
    }

    public function testDuplicateEmailInTheClubIs409NamingTheExistingReferee(): void
    {
        $r = referees_create($this->pdo, $this->admin(), [
            'club_id' => 100, 'first_name' => 'Another', 'last_name' => 'Nora', 'email' => 'NORA@flag.test',
        ]);
        $this->assertSame(409, $r['status']);
        $this->assertSame(2, $r['body']['existing_id']);
        $this->assertStringContainsString('Nora Flag', $r['body']['error']);

        // The same address in ANOTHER club is not a duplicate — the directory is per club.
        $ok = referees_create($this->pdo, $this->otherAdmin(), [
            'club_id' => 200, 'first_name' => 'Nora', 'last_name' => 'Flag', 'email' => 'nora@flag.test',
        ]);
        $this->assertSame(201, $ok['status'], json_encode($ok['body']));

        $u = referees_update($this->pdo, $this->admin(), ['id' => 1, 'email' => 'nora@flag.test']);
        $this->assertSame(409, $u['status'], 'an update cannot take another row\'s address either');
    }

    /** One person, many clubs: an address that already has an account links to it. */
    public function testCreatingByAnEmailThatAlreadyHasAnAccountLinksRatherThanDuplicates(): void
    {
        $r = referees_create($this->pdo, $this->otherAdmin(), [
            'club_id' => 200, 'first_name' => 'Eve', 'last_name' => 'Existing', 'email' => 'Existing@Account.test',
        ]);
        $this->assertSame(201, $r['status'], json_encode($r['body']));
        $this->assertSame(301, $r['body']['referee']['user_id'], 'linked to the existing account');
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM users WHERE lower(email) = 'existing@account.test'")->fetchColumn(), 'no second users row');
    }

    public function testInviteOnAnExistingAccountAddsTheRefereeRoleInThisClubAndLinksTheRow(): void
    {
        // Row 2 has no account and no user_id; invite creates one and mints a token.
        $sent = [];
        $sender = function (string $to, string $name, string $link) use (&$sent): bool {
            $sent[] = [$to, $name, $link];
            return true;
        };
        $r = referees_invite($this->pdo, $this->admin(), ['id' => 2], $sender);
        $this->assertSame(200, $r['status'], json_encode($r['body']));
        $this->assertSame('invited', $r['body']['invite']['status']);
        $this->assertTrue($r['body']['invite']['sent']);
        $this->assertNotNull($r['body']['referee']['user_id'], 'directory row linked to the new account');
        $userId = $r['body']['referee']['user_id'];
        $this->assertSame('referee', $this->pdo->query("SELECT role FROM user_club_access WHERE user_id = {$userId} AND club_profile_id = 100")->fetchColumn());
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('accept-coach-invite?token=', $sent[0][2]);
        $this->assertArrayNotHasKey('token', $r['body']['invite'], 'the token is never in a response');

        // Eve (301) already exists with NO password: inviting into club 200 attaches — one account, two clubs.
        $c = referees_create($this->pdo, $this->otherAdmin(), [
            'club_id' => 200, 'first_name' => 'Eve', 'last_name' => 'Existing', 'email' => 'existing@account.test', 'invite' => true,
        ], $sender);
        $this->assertSame(201, $c['status'], json_encode($c['body']));
        $this->assertSame(301, $c['body']['referee']['user_id']);
        $this->assertSame('invited', $c['body']['invite']['status']);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM user_club_access WHERE user_id = 301 AND club_profile_id = 200 AND role = 'referee'")->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM users WHERE id = 301")->fetchColumn());
    }

    public function testInviteOnAnAccountWithAPasswordAttachesWithoutMailing(): void
    {
        // Ray (300) can already sign in. Adding him to a third club must not mail a set-password link.
        $this->pdo->exec("INSERT INTO club_profile (id, name) VALUES (300, 'Third Club')");
        $this->pdo->exec("INSERT INTO user_club_access (user_id, club_profile_id, role) VALUES (60, 300, 'club_admin')");
        $sent = 0;
        $sender = function () use (&$sent): bool { $sent++; return true; };
        $c = referees_create($this->pdo, referees_test_auth(60, [300 => ['club_admin']]), [
            'club_id' => 300, 'first_name' => 'Ray', 'last_name' => 'Whistle', 'email' => 'ref@whistle.test', 'invite' => true,
        ], $sender);
        $this->assertSame(201, $c['status'], json_encode($c['body']));
        $this->assertSame('already_active', $c['body']['invite']['status']);
        $this->assertSame(0, $sent);
        $this->assertSame(300, $c['body']['referee']['user_id']);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM user_club_access WHERE user_id = 300 AND club_profile_id = 300 AND role = 'referee'")->fetchColumn());
    }

    public function testInviteWithoutAnEmailIs422(): void
    {
        $this->pdo->exec("INSERT INTO referees (id, club_id, first_name, last_name, created_at, updated_at) VALUES (9, 100, 'No', 'Mail', 'x', 'x')");
        $r = referees_invite($this->pdo, $this->admin(), ['id' => 9]);
        $this->assertSame(422, $r['status']);
    }

    // ---------------------------------------------------------------- standing

    public function testParentIs403Everywhere(): void
    {
        $p = $this->parent();
        $this->assertSame(403, referees_list($this->pdo, $p, ['club_id' => 100])['status']);
        $this->assertSame(403, referees_search($this->pdo, $p, ['club_id' => 100, 'q' => 'ray'])['status']);
        $this->assertSame(403, referees_create($this->pdo, $p, ['club_id' => 100, 'first_name' => 'X', 'last_name' => 'Y'])['status']);
        $this->assertSame(403, referees_update($this->pdo, $p, ['id' => 1, 'grade' => 'National'])['status']);
        $this->assertSame(403, referees_set_archived($this->pdo, $p, ['id' => 1], true)['status']);
        $this->assertSame(403, referees_invite($this->pdo, $p, ['id' => 2])['status']);
        $this->assertSame(403, referees_for_event($this->pdo, $p, ['event_id' => 500])['status']);
        $this->assertSame(403, referees_assign($this->pdo, $p, ['event_id' => 500, 'referee_id' => 1])['status']);
        $this->assertSame(403, referees_unassign($this->pdo, $p, ['event_id' => 505, 'referee_id' => 2])['status']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM game_referees')->fetchColumn(), 'nothing changed');
    }

    public function testCoachCanListAndSearchButNotCreate(): void
    {
        $c = $this->coach();
        $this->assertSame(200, referees_list($this->pdo, $c, ['club_id' => 100])['status']);
        $s = referees_search($this->pdo, $c, ['club_id' => 100, 'q' => 'whis']);
        $this->assertSame(200, $s['status']);
        $this->assertSame([1], array_column($s['body']['referees'], 'id'));
        $this->assertSame('Regional', $s['body']['referees'][0]['grade']);
        $this->assertSame(403, referees_create($this->pdo, $c, ['club_id' => 100, 'first_name' => 'X', 'last_name' => 'Y'])['status']);
        $this->assertSame(403, referees_update($this->pdo, $c, ['id' => 1, 'grade' => 'National'])['status']);
        $this->assertSame(403, referees_invite($this->pdo, $c, ['id' => 2])['status']);
    }

    public function testSearchExcludesArchivedAndOtherClubs(): void
    {
        $s = referees_search($this->pdo, $this->admin(), ['club_id' => 100, 'q' => '']);
        $this->assertSame([2, 1], array_column($s['body']['referees'], 'id'), 'Flag, Whistle — no archived row 4, no club-200 row 3');
    }

    public function testAdminOfAnotherClubIs403OnThisClubsReferees(): void
    {
        $o = $this->otherAdmin();
        $this->assertSame(403, referees_list($this->pdo, $o, ['club_id' => 100])['status']);
        $this->assertSame(403, referees_update($this->pdo, $o, ['id' => 2, 'grade' => 'National'])['status']);
        $this->assertSame(403, referees_set_archived($this->pdo, $o, ['id' => 2], true)['status']);
        $this->assertSame(403, referees_invite($this->pdo, $o, ['id' => 2])['status']);
        // and cannot assign club 100's referee to their own game, nor see club 100's game
        $this->assertSame(403, referees_for_event($this->pdo, $o, ['event_id' => 500])['status']);
        $a = referees_assign($this->pdo, $o, ['event_id' => 503, 'referee_id' => 2]);
        $this->assertSame(422, $a['status'], 'club 100\'s referee is not in club 200\'s directory');
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM game_referees WHERE calendar_event_id = 503')->fetchColumn());
    }

    public function testRefereeRoleIsNotStaffOnTheDirectory(): void
    {
        $r = $this->referee();
        $this->assertSame(403, referees_list($this->pdo, $r, ['club_id' => 100])['status']);
        $this->assertSame(403, referees_assign($this->pdo, $r, ['event_id' => 500, 'referee_id' => 1])['status']);
    }

    // ---------------------------------------------------------------- assignments

    public function testAdminAssignsAndUnassignsWithAudit(): void
    {
        $a = referees_assign($this->pdo, $this->admin(), ['event_id' => 500, 'referee_id' => 1, 'role' => 'center']);
        $this->assertSame(200, $a['status'], json_encode($a['body']));
        $this->assertSame([['id' => 1, 'role' => 'center']], array_map(fn($x) => ['id' => $x['id'], 'role' => $x['role']], $a['body']['referees']));
        $this->assertFalse($a['body']['referees'][0]['self_assigned']);
        $this->assertFalse($a['body']['referees'][0]['grade_override']);
        $this->assertSame('Regional', $a['body']['referees'][0]['grade'], 'grade rides with the assignment so the picker can show it');
        $audits = $this->audits('referee_assigned_to_game');
        $this->assertCount(1, $audits);
        $this->assertFalse(json_decode($audits[0]['details'], true)['grade_override']);

        // a second assign of the same referee updates the role, never a second row
        referees_assign($this->pdo, $this->admin(), ['event_id' => 500, 'referee_id' => 1, 'role' => 'assistant']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM game_referees WHERE calendar_event_id = 500')->fetchColumn());

        $u = referees_unassign($this->pdo, $this->admin(), ['event_id' => 500, 'referee_id' => 1]);
        $this->assertSame(200, $u['status']);
        $this->assertTrue($u['body']['removed']);
        $this->assertSame([], $u['body']['referees']);
        $this->assertCount(1, $this->audits('referee_unassigned_from_game'));
    }

    public function testCoachOfATeamOnTheGameCanAssignAndACoachOfAnotherTeamCannot(): void
    {
        $a = referees_assign($this->pdo, $this->coach(), ['event_id' => 500, 'referee_id' => 1, 'role' => 'center']);
        $this->assertSame(200, $a['status'], json_encode($a['body']));

        // 504 is team 11's game; coach 50 coaches team 10 only.
        $b = referees_assign($this->pdo, $this->coach(), ['event_id' => 504, 'referee_id' => 1, 'role' => 'center']);
        $this->assertSame(403, $b['status']);
        // and a coach from another club is refused on this club's game
        $c = referees_assign($this->pdo, $this->otherCoach(), ['event_id' => 500, 'referee_id' => 1]);
        $this->assertSame(403, $c['status']);
    }

    public function testAssignRefusesNonGamesArchivedAndBadRoles(): void
    {
        $this->assertSame(422, referees_assign($this->pdo, $this->admin(), ['event_id' => 502, 'referee_id' => 1])['status'], 'a practice is not a game');
        $this->assertSame(422, referees_assign($this->pdo, $this->admin(), ['event_id' => 500, 'referee_id' => 4])['status'], 'archived');
        $this->assertSame(422, referees_assign($this->pdo, $this->admin(), ['event_id' => 500, 'referee_id' => 1, 'role' => 'linesman'])['status']);
        $this->assertSame(404, referees_assign($this->pdo, $this->admin(), ['event_id' => 999, 'referee_id' => 1])['status']);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM game_referees WHERE calendar_event_id IN (500, 502)')->fetchColumn());
    }

    /** Staff may place someone below the game's minimum; the row and the audit row say so. */
    public function testStaffAssigningBelowTheMinimumGradeRecordsAnOverride(): void
    {
        // 504 needs National; referee 1 is Regional in club 100.
        $a = referees_assign($this->pdo, $this->admin(), ['event_id' => 504, 'referee_id' => 1, 'role' => 'center']);
        $this->assertSame(200, $a['status'], json_encode($a['body']));
        $this->assertTrue($a['body']['referees'][0]['grade_override']);
        $details = json_decode($this->audits('referee_assigned_to_game')[0]['details'], true);
        $this->assertTrue($details['grade_override']);
        $this->assertSame('National', $details['min_referee_grade']);
        $this->assertSame('Regional', $details['referee_grade']);

        // Nora is Grade 5 (≈ Regional): also an override. Ray in club 200 is National — but he is not in club 100's directory.
        $b = referees_assign($this->pdo, $this->admin(), ['event_id' => 504, 'referee_id' => 2, 'role' => 'assistant']);
        $this->assertTrue($b['body']['referees'][1]['grade_override']);
    }

    /** legacy/events-gateway.php's one-step create: the list is validated whole, then written. */
    public function testCreateWithRefereesAppliesTheListAndRejectsABadEntryWhole(): void
    {
        $event = te_game_for_assignment($this->pdo, 500);
        $n = te_game_referees_apply($this->pdo, $event, [['referee_id' => 1, 'role' => 'center'], ['referee_id' => 2, 'role' => 'assistant']], 60, false);
        $this->assertSame(2, $n);
        $rows = te_game_referees_for_event($this->pdo, 500);
        $this->assertSame([[1, 'center'], [2, 'assistant']], array_map(fn($r) => [$r['id'], $r['role']], $rows));

        // replace (the edit path): drop 2, keep 1 as fourth
        te_game_referees_apply($this->pdo, $event, [['referee_id' => 1, 'role' => 'fourth']], 60, true);
        $rows = te_game_referees_for_event($this->pdo, 500);
        $this->assertSame([[1, 'fourth']], array_map(fn($r) => [$r['id'], $r['role']], $rows));

        // a foreign referee in the list fails the whole call and writes nothing
        try {
            te_game_referees_apply($this->pdo, $event, [['referee_id' => 2, 'role' => 'center'], ['referee_id' => 3, 'role' => 'assistant']], 60, true);
            $this->fail('expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('directory', $e->getMessage());
        }
        $this->assertSame([[1, 'fourth']], array_map(fn($r) => [$r['id'], $r['role']], te_game_referees_for_event($this->pdo, 500)), 'nothing written on a refused list');

        // a practice refuses the list too
        $this->expectException(InvalidArgumentException::class);
        te_game_referees_apply($this->pdo, te_game_for_assignment($this->pdo, 502), [['referee_id' => 1]], 60, false);
    }

    public function testForEventShowsTheAssignedRefereesToEventStaff(): void
    {
        $r = referees_for_event($this->pdo, $this->coach(), ['event_id' => 505]);
        $this->assertSame(200, $r['status']);
        $this->assertSame([2], array_column($r['body']['referees'], 'id'));
        $this->assertSame('Grade 5', $r['body']['referees'][0]['grade']);
        $this->assertSame(TE_GAME_REFEREE_ROLES, $r['body']['roles']);
    }

    // ---------------------------------------------------------------- the referee's view

    /** Linked in two clubs → both clubs' games in one list, upcoming first across clubs. */
    public function testMyGamesSpansEveryClubTheRefereeIsLinkedIn(): void
    {
        referees_assign($this->pdo, $this->admin(), ['event_id' => 500, 'referee_id' => 1, 'role' => 'center']);
        referees_assign($this->pdo, $this->admin(), ['event_id' => 501, 'referee_id' => 1, 'role' => 'assistant']);
        referees_assign($this->pdo, $this->otherAdmin(), ['event_id' => 503, 'referee_id' => 3, 'role' => 'center']);

        $r = referees_my_games($this->pdo, $this->referee(), self::TODAY);
        $this->assertSame(200, $r['status'], json_encode($r['body']));
        $this->assertSame([200, 100], array_column($r['body']['clubs'], 'club_id'), 'the clubs strip is in name order');
        $this->assertSame(['National', 'Regional'], array_column($r['body']['clubs'], 'grade'), 'grade per club, read-only here');
        $this->assertSame([503, 500], array_column($r['body']['upcoming'], 'id'), 'soonest first, across clubs');
        $this->assertSame(['Away United', 'Home FC'], array_column($r['body']['upcoming'], 'club_name'));
        $this->assertSame([501], array_column($r['body']['past'], 'id'));
        $game = $r['body']['upcoming'][1];
        $this->assertSame('2026-09-20', $game['event_date'], 'date-only string, never parsed');
        $this->assertSame('North Park', $game['venue_name']);
        $this->assertSame('Field 2', $game['field_name'], 'the referee sees which pitch (migration 100)');
        $this->assertNull($r['body']['upcoming'][0]['field_name'], '503 has no venue and no field');
        $this->assertSame('Rivals FC', $game['opponent_name']);
        $this->assertSame('center', $game['role']);
        $this->assertSame(['U12 Blue'], array_column($game['teams'], 'name'));
        $this->assertFalse($game['self_assigned']);
    }

    public function testAUserWithNoRefereeRowSeesAnEmptyAnswerNotA403(): void
    {
        $r = referees_my_games($this->pdo, $this->parent(), self::TODAY);
        $this->assertSame(200, $r['status']);
        $this->assertSame([], $r['body']['clubs']);
        $this->assertSame([], $r['body']['upcoming']);
    }

    public function testMyGamesDoesNotDependOnTheTokensRoles(): void
    {
        // The account is linked but the token carries no referee role at all (a coach who also referees).
        referees_assign($this->pdo, $this->admin(), ['event_id' => 500, 'referee_id' => 1, 'role' => 'center']);
        $r = referees_my_games($this->pdo, referees_test_auth(300, []), self::TODAY);
        $this->assertSame([500], array_column($r['body']['upcoming'], 'id'));
    }

    // ---------------------------------------------------------------- coverage

    /** A row with the generic 'referee' role (the column default) covers the game. */
    public function testTheGenericRefereeRoleCoversAGameLikeCenterDoes(): void
    {
        referees_assign($this->pdo, $this->admin(), ['event_id' => 500, 'referee_id' => 1, 'role' => 'referee']);
        $row = $this->pdo->query("SELECT e.id, e.type, e.event_date" . te_game_referee_status_columns($this->pdo, 'e') . " FROM calendar_events e WHERE e.id = 500")->fetch(PDO::FETCH_ASSOC);
        $ev = te_game_referee_status_apply($row, self::TODAY);
        $this->assertSame('covered', $ev['referee_status']);
    }

    // ---------------------------------------------------------------- set my grade

    public function testARefereeSetsTheirOwnGradeAcrossEveryClubRow(): void
    {
        $r = referees_set_my_grade($this->pdo, $this->referee(), ['grade' => 'National Assistant Referee']);
        $this->assertSame(200, $r['status'], json_encode($r['body']));
        $this->assertSame(2, $r['body']['clubs_updated']);
        $grades = $this->pdo->query('SELECT DISTINCT grade FROM referees WHERE user_id = 300 AND archived_at IS NULL')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['National Assistant Referee'], $grades);
        $n = (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'referee_self_set_grade'")->fetchColumn();
        $this->assertSame(2, $n, 'one audit row per club row');
    }

    public function testSetMyGradeRefusesAnonymousAndNonReferees(): void
    {
        $this->assertSame(401, referees_set_my_grade($this->pdo, referees_test_auth(0, []), ['grade' => 'Regional'])['status']);
        $this->assertSame(404, referees_set_my_grade($this->pdo, $this->parent(), ['grade' => 'Regional'])['status']);
        $this->assertSame(422, referees_set_my_grade($this->pdo, $this->referee(), ['grade' => str_repeat('x', 61)])['status']);
    }

    // ---------------------------------------------------------------- open games / claim / release

    public function testOpenGamesExcludesCoveredGamesOtherClubsPastGamesAndGradeMismatches(): void
    {
        $r = referees_open_games($this->pdo, $this->referee(), self::TODAY);
        $this->assertSame(200, $r['status'], json_encode($r['body']));
        // 503 (club 200, National ref there), 500 (club 100). NOT 505 (center assigned), 501 (played),
        // 502 (practice), 504 (needs National; Ray is Regional in club 100).
        $this->assertSame([503, 500], array_column($r['body']['games'], 'id'));
        $this->assertSame('Away United', $r['body']['games'][0]['club_name']);
        $this->assertContains('center', $r['body']['games'][1]['open_roles']);
        $this->assertSame('Field 2', $r['body']['games'][1]['field_name'], 'open games name the pitch too');
        $this->assertNull($r['body']['games'][0]['field_name']);

        // Promote Ray to National in club 100 → 504 opens up (the grade is per club).
        referees_update($this->pdo, $this->admin(), ['id' => 1, 'grade' => 'National']);
        $r = referees_open_games($this->pdo, $this->referee(), self::TODAY);
        $this->assertSame([503, 500, 504], array_column($r['body']['games'], 'id'));

        // A game with only an assistant is still open for center, and lists who is on it.
        referees_assign($this->pdo, $this->admin(), ['event_id' => 500, 'referee_id' => 2, 'role' => 'assistant']);
        $r = referees_open_games($this->pdo, $this->referee(), self::TODAY);
        $this->assertContains(500, array_column($r['body']['games'], 'id'));
        $g = array_values(array_filter($r['body']['games'], fn($x) => $x['id'] === 500))[0];
        $this->assertSame(['Nora Flag'], array_column($g['referees'], 'name'));
        $this->assertNotContains('assistant', $g['open_roles']);

        // A referee from a club the game is not in never sees it: parent 80 has no rows at all.
        $this->assertSame([], referees_open_games($this->pdo, $this->parent(), self::TODAY)['body']['games']);
    }

    public function testLegacyNumericGradesMapOntoTheScale(): void
    {
        $this->assertTrue(te_referee_grade_meets('Grade 5', 'Regional'));
        $this->assertFalse(te_referee_grade_meets('Grade 5', 'National'));
        $this->assertTrue(te_referee_grade_meets('Grade 3', 'National'));
        $this->assertTrue(te_referee_grade_meets('grade 1', 'Professional'));
        $this->assertTrue(te_referee_grade_meets('7', 'Grassroots'), 'a bare digit reads as the legacy scale');
        $this->assertFalse(te_referee_grade_meets('Grade 9', 'Regional'));
        $this->assertFalse(te_referee_grade_meets('Other', 'Grassroots'), 'Other never qualifies for a minimum');
        $this->assertFalse(te_referee_grade_meets(null, 'Grassroots'), 'blank never qualifies for a minimum');
        $this->assertTrue(te_referee_grade_meets(null, null), 'no minimum: anyone');
        $this->assertTrue(te_referee_grade_meets('Other', ''), 'no minimum: anyone');
    }

    // ---------------------------------------------------------------- assistant-only grades

    public function testAssistantRefereeGradesRankAtTheirLevelButNeverQualifyForCenter(): void
    {
        $this->assertTrue(te_referee_grade_meets('Regional Assistant Referee', 'Regional'));
        $this->assertTrue(te_referee_grade_meets('National Assistant Referee', 'National'));
        $this->assertFalse(te_referee_grade_meets('Regional Assistant Referee', 'National'));
        $this->assertTrue(te_referee_grade_qualifies('National Assistant Referee', 'National', 'assistant'));
        $this->assertTrue(te_referee_grade_qualifies('National Assistant Referee', 'National', 'fourth'));
        $this->assertFalse(te_referee_grade_qualifies('National Assistant Referee', null, 'center'), 'never center, even with no minimum');
        $this->assertTrue(te_referee_grade_qualifies('National', null, 'center'));
        $this->assertContains('Regional Assistant Referee', TE_REFEREE_GRADES, 'offered in the select');
    }

    public function testAnAssistantGradedRefereeIsNotOfferedCenterAndCannotClaimIt(): void
    {
        referees_update($this->pdo, $this->admin(), ['id' => 1, 'grade' => 'National Assistant Referee']);
        $ref = $this->referee();
        $open = referees_open_games($this->pdo, $ref, self::TODAY)['body']['games'];
        $g500 = array_values(array_filter($open, fn($g) => $g['id'] === 500))[0];
        $this->assertNotContains('center', $g500['open_roles']);
        $this->assertContains('assistant', $g500['open_roles']);
        // 504 needs National: an NAR meets that for assistant, and still no center.
        $g504 = array_values(array_filter($open, fn($g) => $g['id'] === 504))[0];
        $this->assertSame(['assistant', 'fourth'], $g504['open_roles'], 'the generic referee role is center, so an AR grade is not offered it either');

        $c = referees_claim($this->pdo, $ref, ['event_id' => 500, 'role' => 'center'], self::TODAY);
        $this->assertSame(422, $c['status']);
        $this->assertStringContainsString('assistant referee grade', $c['body']['error']);
        $this->assertSame(200, referees_claim($this->pdo, $ref, ['event_id' => 500, 'role' => 'assistant'], self::TODAY)['status']);

        // Staff placing an AR grade as center: allowed, flagged, warned.
        $a = referees_assign($this->pdo, $this->admin(), ['event_id' => 504, 'referee_id' => 1, 'role' => 'center']);
        $this->assertSame(200, $a['status']);
        $this->assertTrue($a['body']['referees'][0]['grade_override']);
        $this->assertNotEmpty($a['body']['warnings']);
    }

    /** When center is the only open position, an AR-graded referee does not see the game at all. */
    public function testAGameOpenOnlyForCenterIsHiddenFromAnAssistantGradedReferee(): void
    {
        referees_update($this->pdo, $this->admin(), ['id' => 1, 'grade' => 'Regional Assistant Referee']);
        foreach (['referee', 'assistant', 'fourth'] as $i => $role) {
            $this->pdo->exec("INSERT INTO referees (id, club_id, first_name, last_name, created_at, updated_at) VALUES (" . (20 + $i) . ", 100, 'R', '$i', 'x', 'x')");
            referees_assign($this->pdo, $this->admin(), ['event_id' => 500, 'referee_id' => 20 + $i, 'role' => $role]);
        }
        $ids = array_column(referees_open_games($this->pdo, $this->referee(), self::TODAY)['body']['games'], 'id');
        $this->assertNotContains(500, $ids);
    }

    // ---------------------------------------------------------------- self-assign toggle

    public function testAGameClosedToSelfAssignmentIsHiddenAndRefused(): void
    {
        $this->pdo->exec('UPDATE calendar_events SET allow_referee_self_assign = 0 WHERE id = 500');
        $ids = array_column(referees_open_games($this->pdo, $this->referee(), self::TODAY)['body']['games'], 'id');
        $this->assertNotContains(500, $ids);
        $this->assertContains(503, $ids, 'other games unaffected');

        $c = referees_claim($this->pdo, $this->referee(), ['event_id' => 500, 'role' => 'center'], self::TODAY);
        $this->assertSame(422, $c['status']);
        $this->assertStringContainsString('not open for referees to claim', $c['body']['error']);

        // Staff assignment is unaffected by the toggle.
        $this->assertSame(200, referees_assign($this->pdo, $this->admin(), ['event_id' => 500, 'referee_id' => 1, 'role' => 'center'])['status']);
        $this->assertTrue(te_game_for_assignment($this->pdo, 503)['allow_referee_self_assign'], 'default is open');
        $this->assertNull(te_game_self_assign_flag(null));
        $this->assertFalse(te_game_self_assign_flag('false'));
        $this->assertTrue(te_game_self_assign_flag('1'));
    }

    // ---------------------------------------------------------------- time conflicts

    public function testOverlapDetectionAssumesTwoHoursWhenEndTimeIsMissing(): void
    {
        $a = ['event_date' => '2026-09-20', 'start_time' => '10:00', 'end_time' => null];
        $this->assertTrue(te_games_overlap($a, ['event_date' => '2026-09-20', 'start_time' => '11:30', 'end_time' => '13:00']));
        $this->assertFalse(te_games_overlap($a, ['event_date' => '2026-09-20', 'start_time' => '12:00', 'end_time' => '13:00']), 'ends exactly at 12:00');
        $this->assertTrue(te_games_overlap(['event_date' => '2026-09-20', 'start_time' => '09:00', 'end_time' => '10:30'], $a));
        $this->assertFalse(te_games_overlap($a, ['event_date' => '2026-09-21', 'start_time' => '10:00', 'end_time' => null]), 'different day');
        $this->assertFalse(te_games_overlap($a, ['event_date' => '2026-09-20', 'start_time' => null, 'end_time' => null]), 'no start: cannot be shown to clash');
        $this->assertTrue(te_games_overlap(['event_date' => '2026-09-20', 'start_time' => '10:00:00', 'end_time' => '12:00:00'], ['event_date' => '2026-09-20', 'start_time' => '11:59', 'end_time' => null]));
    }

    public function testClaimRefusesAnOverlappingGameNamingItAndOpenGamesMarksItInstead(): void
    {
        // Ray claims 500 (Sep 20 10:00, no end → until 12:00). A second club-100 game the same morning:
        $this->pdo->exec("INSERT INTO calendar_events (id, club_id, name, type, event_date, start_time, end_time, opponent_name, status) VALUES
            (510, 100, 'Morning clash', 'game', '2026-09-20', '11:00', '12:30', 'Clash FC', 'scheduled'),
            (511, 100, 'Afternoon fine', 'game', '2026-09-20', '12:00', '13:30', 'Later FC', 'scheduled')");
        referees_claim($this->pdo, $this->referee(), ['event_id' => 500, 'role' => 'center'], self::TODAY);

        $c = referees_claim($this->pdo, $this->referee(), ['event_id' => 510, 'role' => 'center'], self::TODAY);
        $this->assertSame(409, $c['status']);
        $this->assertStringContainsString('League match', $c['body']['error'], 'the conflicting game is named');
        $this->assertStringContainsString('from 10:00', $c['body']['error']);
        $this->assertSame(200, referees_claim($this->pdo, $this->referee(), ['event_id' => 511, 'role' => 'center'], self::TODAY)['status'], 'back-to-back is fine');

        $open = referees_open_games($this->pdo, $this->referee(), self::TODAY)['body']['games'];
        $g510 = array_values(array_filter($open, fn($g) => $g['id'] === 510))[0] ?? null;
        $this->assertNotNull($g510, 'shown, not hidden');
        $this->assertTrue($g510['conflict']);
        $this->assertStringContainsString('League match', $g510['conflict_reason']);
        $g503 = array_values(array_filter($open, fn($g) => $g['id'] === 503))[0];
        $this->assertFalse($g503['conflict']);
    }

    /** Conflicts are per PERSON: a game in club 200 counts against a club-100 claim. */
    public function testConflictsSpanClubs(): void
    {
        $this->pdo->exec("INSERT INTO calendar_events (id, club_id, name, type, event_date, start_time, end_time, status) VALUES
            (520, 200, 'Away same slot', 'game', '2026-09-20', '10:30', '12:00', 'scheduled')");
        referees_assign($this->pdo, $this->otherAdmin(), ['event_id' => 520, 'referee_id' => 3, 'role' => 'center']);
        $c = referees_claim($this->pdo, $this->referee(), ['event_id' => 500, 'role' => 'center'], self::TODAY);
        $this->assertSame(409, $c['status']);
        $this->assertStringContainsString('Away same slot (Away United)', $c['body']['error']);
    }

    public function testStaffAssigningOverAConflictIsAllowedWarnedAndFlagged(): void
    {
        $this->pdo->exec("INSERT INTO calendar_events (id, club_id, name, type, event_date, start_time, end_time, status) VALUES
            (510, 100, 'Morning clash', 'game', '2026-09-20', '11:00', '12:30', 'scheduled')");
        referees_assign($this->pdo, $this->admin(), ['event_id' => 500, 'referee_id' => 1, 'role' => 'center']);
        $a = referees_assign($this->pdo, $this->admin(), ['event_id' => 510, 'referee_id' => 1, 'role' => 'center']);
        $this->assertSame(200, $a['status']);
        $this->assertTrue($a['body']['referees'][0]['conflict_override']);
        $this->assertFalse($a['body']['referees'][0]['grade_override']);
        $this->assertStringContainsString('They are already on League match', $a['body']['warnings'][0]);
        $details = json_decode($this->audits('referee_assigned_to_game')[1]['details'], true);
        $this->assertTrue($details['conflict_override']);
        $this->assertSame(500, $details['conflicts_with_event_id']);

        // The one-step create path flags it too.
        $this->pdo->exec("INSERT INTO calendar_events (id, club_id, name, type, event_date, start_time, end_time, status) VALUES
            (512, 100, 'Third clash', 'game', '2026-09-20', '10:15', '11:00', 'scheduled')");
        te_game_referees_apply($this->pdo, te_game_for_assignment($this->pdo, 512), [['referee_id' => 1, 'role' => 'assistant']], 60, false);
        $this->assertTrue(te_game_referees_for_event($this->pdo, 512)[0]['conflict_override']);
    }

    public function testClaimHappyPathThenReleaseOwnRow(): void
    {
        $c = referees_claim($this->pdo, $this->referee(), ['event_id' => 500, 'role' => 'center'], self::TODAY);
        $this->assertSame(200, $c['status'], json_encode($c['body']));
        $this->assertSame([1], array_column($c['body']['referees'], 'id'));
        $this->assertTrue($c['body']['referees'][0]['self_assigned']);
        $this->assertCount(1, $this->audits('referee_self_assigned'));

        $mine = referees_my_games($this->pdo, $this->referee(), self::TODAY);
        $this->assertTrue($mine['body']['upcoming'][0]['self_assigned'], 'My games says it was claimed');
        $this->assertNotContains(500, array_column(referees_open_games($this->pdo, $this->referee(), self::TODAY)['body']['games'], 'id'), 'no longer open');

        $r = referees_release($this->pdo, $this->referee(), ['event_id' => 500], self::TODAY);
        $this->assertSame(200, $r['status'], json_encode($r['body']));
        $this->assertSame([], $r['body']['referees']);
        $this->assertCount(1, $this->audits('referee_released'));
    }

    public function testClaimRefusals(): void
    {
        $ref = $this->referee();
        $this->assertSame(422, referees_claim($this->pdo, $ref, ['event_id' => 501], self::TODAY)['status'], 'past game');
        $this->assertSame(422, referees_claim($this->pdo, $ref, ['event_id' => 502], self::TODAY)['status'], 'not a game');
        $this->assertSame(409, referees_claim($this->pdo, $ref, ['event_id' => 505, 'role' => 'center'], self::TODAY)['status'], 'center already filled');
        $this->assertSame(200, referees_claim($this->pdo, $ref, ['event_id' => 505, 'role' => 'assistant'], self::TODAY)['status'], 'but assistant is open');
        $this->assertSame(409, referees_claim($this->pdo, $ref, ['event_id' => 505, 'role' => 'fourth'], self::TODAY)['status'], 'already on it');

        $below = referees_claim($this->pdo, $ref, ['event_id' => 504, 'role' => 'center'], self::TODAY);
        $this->assertSame(422, $below['status'], 'below the minimum grade');
        $this->assertStringContainsString('National', $below['body']['error']);
        $this->assertStringContainsString('Regional', $below['body']['error']);

        // A club they do not referee for: parent 80 has no rows anywhere; Ray tries a club-300 game.
        $this->pdo->exec("INSERT INTO club_profile (id, name) VALUES (300, 'Third')");
        $this->pdo->exec("INSERT INTO calendar_events (id, club_id, name, type, event_date, status) VALUES (600, 300, 'Far game', 'game', '2026-09-30', 'scheduled')");
        $this->assertSame(403, referees_claim($this->pdo, $ref, ['event_id' => 600, 'role' => 'center'], self::TODAY)['status']);
        $this->assertSame(403, referees_claim($this->pdo, $this->parent(), ['event_id' => 500, 'role' => 'center'], self::TODAY)['status']);
        $this->assertSame(404, referees_claim($this->pdo, $ref, ['event_id' => 999], self::TODAY)['status']);
    }

    public function testReleaseRefusesAStaffPlacedRowAndAPastGame(): void
    {
        referees_assign($this->pdo, $this->admin(), ['event_id' => 500, 'referee_id' => 1, 'role' => 'center']);
        $r = referees_release($this->pdo, $this->referee(), ['event_id' => 500], self::TODAY);
        $this->assertSame(403, $r['status'], 'the club placed them; they ask the club');
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM game_referees WHERE calendar_event_id = 500')->fetchColumn());

        $this->assertSame(404, referees_release($this->pdo, $this->referee(), ['event_id' => 503], self::TODAY)['status'], 'not on it');

        // Claimed, then the game passes: no release after the fact.
        referees_claim($this->pdo, $this->referee(), ['event_id' => 503, 'role' => 'center'], self::TODAY);
        $this->assertSame(422, referees_release($this->pdo, $this->referee(), ['event_id' => 503], '2026-12-01')['status']);

        // Staff can still unassign a self-assigned row like any other.
        $u = referees_unassign($this->pdo, $this->otherAdmin(), ['event_id' => 503, 'referee_id' => 3]);
        $this->assertTrue($u['body']['removed']);
    }

    // ---------------------------------------------------------------- "needs ref" on the events list

    public function testRefereeStatusIsComputedFromTheSubselectColumns(): void
    {
        $cols = te_game_referee_status_columns($this->pdo, 'e');
        $this->assertStringContainsString('game_referees', $cols);
        $rows = $this->pdo->query("SELECT e.* {$cols} FROM calendar_events e ORDER BY e.id")->fetchAll();
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = te_game_referee_status_apply($row, self::TODAY);
        }
        $this->assertSame('needs_ref', $byId[500]['referee_status'], 'upcoming game, nobody assigned');
        $this->assertSame(0, $byId[500]['referee_count']);
        $this->assertSame('covered', $byId[505]['referee_status'], 'a center is assigned');
        $this->assertSame(1, $byId[505]['referee_count']);
        $this->assertArrayNotHasKey('referee_status', $byId[501], 'a played game is not flagged');
        $this->assertArrayNotHasKey('referee_status', $byId[502], 'a practice is not a game');
        $this->assertArrayNotHasKey('center_referee_count', $byId[500], 'the helper column is folded away');

        // an assistant alone does not cover the game
        referees_assign($this->pdo, $this->admin(), ['event_id' => 500, 'referee_id' => 1, 'role' => 'assistant']);
        $row = $this->pdo->query("SELECT e.* {$cols} FROM calendar_events e WHERE e.id = 500")->fetch();
        $this->assertSame('needs_ref', te_game_referee_status_apply($row, self::TODAY)['referee_status']);
        $this->assertSame(1, te_game_referee_status_apply($row, self::TODAY)['referee_count']);
    }

    public function testRefereeStatusColumnsAreAbsentUntilTheMigrationIsApplied(): void
    {
        $bare = self::basePdo();
        $this->assertSame('', te_game_referee_status_columns($bare, 'e'));
        $row = ['id' => 1, 'type' => 'game', 'event_date' => '2026-09-20'];
        $this->assertSame($row, te_game_referee_status_apply($row, self::TODAY), 'nothing to fold, nothing added');
    }

    // ---------------------------------------------------------------- before the migration

    public function testEverythingAnswers503OrEmptyUntilTheTableExists(): void
    {
        $bare = self::basePdo();
        $this->assertSame(503, referees_create($bare, $this->admin(), ['club_id' => 100, 'first_name' => 'X', 'last_name' => 'Y'])['status']);
        $list = referees_list($bare, $this->admin(), ['club_id' => 100]);
        $this->assertSame(200, $list['status']);
        $this->assertFalse($list['body']['available']);
        $this->assertSame([], $list['body']['referees']);
        $mine = referees_my_games($bare, $this->referee(), self::TODAY);
        $this->assertFalse($mine['body']['available']);
        $this->assertSame(0, te_referee_link_user_by_email($bare, 300, 'ref@whistle.test'), 'the accept-invite link is a no-op, never a failure');
    }

    public function testAcceptingARefereeInvitationLinksEveryUnlinkedRowOnTheAddress(): void
    {
        $this->pdo->exec("INSERT INTO users (id, email, first_name, last_name) VALUES (302, 'nora@flag.test', 'Nora', 'Flag')");
        $this->assertSame(1, te_referee_link_user_by_email($this->pdo, 302, 'NORA@flag.test'));
        $this->assertSame(302, (int) $this->pdo->query('SELECT user_id FROM referees WHERE id = 2')->fetchColumn());
        $this->assertSame(300, (int) $this->pdo->query('SELECT user_id FROM referees WHERE id = 1')->fetchColumn(), 'an already-linked row is untouched');
    }

    public function testTheTokenAndThePasswordNeverAppearInAResponse(): void
    {
        $src = file_get_contents(__DIR__ . '/../../api/referees.php');
        $this->assertStringNotContainsString("'token' =>", $src);
        $this->assertStringNotContainsString('password_hash', $src);
    }
}
