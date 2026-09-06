<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/coach_teams.php';

/**
 * Auth double. `te_is_club_admin()` asks isSuperAdmin() and
 * hasRole('club_admin', $clubId, 'club'). canAccessClub() answers TRUE for
 * everyone on purpose — a regression to membership must show up as a parent
 * being allowed to reassign coaches.
 */
class FakeCoachTeamsAuth
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
 * lib/coach_teams.php — assign a coach to a team FROM THE COACH'S ROW on the
 * Coaches page (Maggie, 2026-09-06: "there's no way to assign a coach to a
 * team from the coach").
 *
 * Pinned:
 *  - Club ADMIN standing of the TEAM's club, never membership. A parent, a
 *    coach, and an admin of another club are all 403.
 *  - Head coach is `teams.primary_coach_id`; assistant / manager are
 *    `team_members` rows (user_id, athlete_id NULL, status active, join_date).
 *  - Replacing a head coach is REPORTED, never silent.
 *  - Re-assigning does not duplicate rows; re-assigning the same role is a no-op.
 *  - Unassign never hard-deletes a team_members row — status inactive + leave_date.
 *  - The target must hold an active coach/club_admin role in that club (422).
 *  - Every change writes an audit row.
 */
class CoachTeamsTest extends TestCase
{
    private const CLUB = 100;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("
            CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, first_name TEXT, last_name TEXT);
            CREATE TABLE user_club_access (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, club_profile_id INTEGER, role TEXT,
                revoked_at TEXT, active BOOLEAN DEFAULT 1
            );
            CREATE TABLE programs (id INTEGER PRIMARY KEY, name TEXT, club_id INTEGER);
            CREATE TABLE teams (
                id INTEGER PRIMARY KEY, name TEXT, program_id INTEGER, primary_coach_id INTEGER,
                club_id INTEGER, status TEXT, deleted_at TEXT, age_group TEXT, updated_at TEXT
            );
            CREATE TABLE team_members (
                id INTEGER PRIMARY KEY AUTOINCREMENT, team_id INTEGER, user_id INTEGER, athlete_id INTEGER,
                role TEXT, status TEXT, join_date TEXT, leave_date TEXT, created_at TEXT
            );
            CREATE TABLE audit_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT, resource_type TEXT,
                resource_id INTEGER, ip_address TEXT, user_agent TEXT, details TEXT, created_at TEXT
            );
            INSERT INTO users VALUES
                (7, 'cal@club.test', 'Cal', 'Coach'),
                (8, 'jane@club.test', 'Jane', 'Doe'),
                (12, 'pam@club.test', 'Pam', 'Parent'),
                (13, 'rex@club.test', 'Rex', 'Revoked'),
                (16, 'abe@club.test', 'Abe', 'Admin'),
                (11, 'otto@club.test', 'Otto', 'Other');
            INSERT INTO user_club_access (user_id, club_profile_id, role, active, revoked_at) VALUES
                (7,  100, 'coach', 1, NULL),
                (8,  100, 'coach', 1, NULL),
                (12, 100, 'parent', 1, NULL),
                (13, 100, 'coach', 0, '2026-08-01 00:00:00'),
                (16, 100, 'club_admin', 1, NULL),
                (11, 200, 'coach', 1, NULL);
            INSERT INTO programs VALUES (1, 'Fall Rec', 100), (2, 'Travel', 100);
            INSERT INTO teams (id, name, program_id, primary_coach_id, club_id, status, deleted_at) VALUES
                (10, 'U10 Sharks', 1, 8,    100, 'active', NULL),
                (11, 'U12 Rays',   2, NULL, 100, 'active', NULL),
                (12, 'Gone FC',    1, NULL, 100, 'active', '2026-01-01 00:00:00'),
                (20, 'Elsewhere',  NULL, NULL, 200, 'active', NULL);
            INSERT INTO team_members (team_id, user_id, athlete_id, role, status, join_date) VALUES
                (11, 7, NULL, 'assistant_coach', 'active', '2026-08-01');
        ");
        $this->pdo->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
    }

    private function admin(): FakeCoachTeamsAuth
    {
        return new FakeCoachTeamsAuth(900, [self::CLUB => ['club_admin']]);
    }

    private function audits(string $action): array
    {
        $s = $this->pdo->prepare('SELECT * FROM audit_log WHERE action = ? ORDER BY id');
        $s->execute([$action]);
        return $s->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function members(int $teamId, int $userId): array
    {
        $s = $this->pdo->prepare('SELECT * FROM team_members WHERE team_id = ? AND user_id = ? ORDER BY id');
        $s->execute([$teamId, $userId]);
        return $s->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function headOf(int $teamId)
    {
        $s = $this->pdo->prepare('SELECT primary_coach_id FROM teams WHERE id = ?');
        $s->execute([$teamId]);
        return $s->fetchColumn();
    }

    // ------------------------------------------------------------ standing

    public function testAParentIsRefusedOnEveryAction(): void
    {
        $parent = new FakeCoachTeamsAuth(12, [self::CLUB => ['parent']]);
        $this->assertSame(403, coachTeams_list($this->pdo, $parent, 7, self::CLUB)['status']);
        $this->assertSame(403, coachTeams_assign($this->pdo, $parent, ['user_id' => 7, 'team_id' => 10, 'role' => 'head_coach'])['status']);
        $this->assertSame(403, coachTeams_unassign($this->pdo, $parent, ['user_id' => 7, 'team_id' => 11])['status']);
        $this->assertSame('8', (string) $this->headOf(10), 'nothing changed');
        $this->assertSame('active', $this->members(11, 7)[0]['status']);
    }

    public function testACoachIsNotAnAdmin(): void
    {
        $coach = new FakeCoachTeamsAuth(7, [self::CLUB => ['coach']]);
        $this->assertSame(403, coachTeams_assign($this->pdo, $coach, ['user_id' => 7, 'team_id' => 10, 'role' => 'head_coach'])['status']);
        $this->assertSame(403, coachTeams_list($this->pdo, $coach, 7, self::CLUB)['status']);
    }

    public function testAnAdminOfAnotherClubIsRefused(): void
    {
        $other = new FakeCoachTeamsAuth(950, [200 => ['club_admin']]);
        $this->assertSame(403, coachTeams_assign($this->pdo, $other, ['user_id' => 7, 'team_id' => 10, 'role' => 'head_coach'])['status']);
        $this->assertSame(403, coachTeams_list($this->pdo, $other, 7, self::CLUB)['status']);
        $this->assertSame(403, coachTeams_unassign($this->pdo, $other, ['user_id' => 7, 'team_id' => 11])['status']);
    }

    public function testSuperAdminPasses(): void
    {
        $super = new FakeCoachTeamsAuth(1, [], true);
        $this->assertSame(200, coachTeams_list($this->pdo, $super, 7, self::CLUB)['status']);
    }

    public function testAMissingOrDeletedTeamIs404(): void
    {
        $this->assertSame(404, coachTeams_assign($this->pdo, $this->admin(), ['user_id' => 7, 'team_id' => 999, 'role' => 'head_coach'])['status']);
        $this->assertSame(404, coachTeams_assign($this->pdo, $this->admin(), ['user_id' => 7, 'team_id' => 12, 'role' => 'head_coach'])['status']);
    }

    // ------------------------------------------------------------ list

    public function testListShowsEveryRoleAndTheClubsOtherActiveTeams(): void
    {
        $r = coachTeams_list($this->pdo, $this->admin(), 7, self::CLUB);
        $this->assertSame(200, $r['status'], json_encode($r));
        $teams = $r['body']['teams'];
        $this->assertSame([[11, 'assistant_coach']], array_map(fn($t) => [$t['id'], $t['role']], $teams));

        $available = $r['body']['available'];
        $ids = array_map(fn($t) => $t['id'], $available);
        $this->assertSame([10, 11], $ids, 'soft-deleted and other-club teams are absent');
        $this->assertSame('Fall Rec', $available[0]['program_name']);
        $this->assertSame(['id' => 8, 'name' => 'Jane Doe'], $available[0]['head_coach']);
        $this->assertNull($available[1]['head_coach']);
    }

    public function testListReportsHeadCoachRoleFromPrimaryCoachId(): void
    {
        $r = coachTeams_list($this->pdo, $this->admin(), 8, self::CLUB);
        $this->assertSame([[10, 'head_coach']], array_map(fn($t) => [$t['id'], $t['role']], $r['body']['teams']));
    }

    // ------------------------------------------------------------ assign

    public function testAssignHeadSetsTheColumnAndReportsWhoWasReplaced(): void
    {
        $r = coachTeams_assign($this->pdo, $this->admin(), ['user_id' => 7, 'team_id' => 10, 'role' => 'head_coach']);
        $this->assertSame(200, $r['status'], json_encode($r));
        $this->assertSame('7', (string) $this->headOf(10));
        $this->assertSame(['id' => 8, 'name' => 'Jane Doe'], $r['body']['previous_head_coach']);

        $audits = $this->audits('coach_assigned_to_team');
        $this->assertCount(1, $audits);
        $this->assertSame(900, (int) $audits[0]['user_id']);
        $this->assertSame(10, (int) $audits[0]['resource_id']);
        $details = json_decode($audits[0]['details'], true);
        $this->assertSame(7, $details['coach_user_id']);
        $this->assertSame('head_coach', $details['role']);
        $this->assertSame(8, $details['previous_head_coach_id']);
    }

    public function testAssignHeadWithNoIncumbentReportsNull(): void
    {
        $r = coachTeams_assign($this->pdo, $this->admin(), ['user_id' => 7, 'team_id' => 11, 'role' => 'head_coach']);
        $this->assertSame(200, $r['status']);
        $this->assertNull($r['body']['previous_head_coach']);
    }

    public function testAssignAssistantInsertsOneStaffRow(): void
    {
        $r = coachTeams_assign($this->pdo, $this->admin(), ['user_id' => 8, 'team_id' => 11, 'role' => 'assistant_coach']);
        $this->assertSame(200, $r['status'], json_encode($r));
        $rows = $this->members(11, 8);
        $this->assertCount(1, $rows);
        $this->assertSame('assistant_coach', $rows[0]['role']);
        $this->assertSame('active', $rows[0]['status']);
        $this->assertNull($rows[0]['athlete_id']);
        $this->assertSame(date('Y-m-d'), $rows[0]['join_date']);
        $this->assertCount(1, $this->audits('coach_assigned_to_team'));
    }

    public function testReassigningTheSameRoleIsIdempotent(): void
    {
        coachTeams_assign($this->pdo, $this->admin(), ['user_id' => 7, 'team_id' => 11, 'role' => 'assistant_coach']);
        $r = coachTeams_assign($this->pdo, $this->admin(), ['user_id' => 7, 'team_id' => 11, 'role' => 'assistant_coach']);
        $this->assertSame(200, $r['status']);
        $this->assertTrue($r['body']['unchanged']);
        $this->assertCount(1, $this->members(11, 7), 'no duplicate row');
        $this->assertCount(0, $this->audits('coach_assigned_to_team'), 'nothing happened, nothing audited');

        // Head coach too.
        $r = coachTeams_assign($this->pdo, $this->admin(), ['user_id' => 8, 'team_id' => 10, 'role' => 'head_coach']);
        $this->assertTrue($r['body']['unchanged']);
        $this->assertNull($r['body']['previous_head_coach']);
    }

    public function testChangingAssistantToManagerUpdatesTheRowInsteadOfDuplicating(): void
    {
        $r = coachTeams_assign($this->pdo, $this->admin(), ['user_id' => 7, 'team_id' => 11, 'role' => 'team_manager']);
        $this->assertSame(200, $r['status']);
        $rows = $this->members(11, 7);
        $this->assertCount(1, $rows);
        $this->assertSame('team_manager', $rows[0]['role']);
        $this->assertSame('active', $rows[0]['status']);
    }

    public function testReassigningAfterUnassignReactivatesTheSameRow(): void
    {
        coachTeams_unassign($this->pdo, $this->admin(), ['user_id' => 7, 'team_id' => 11]);
        $r = coachTeams_assign($this->pdo, $this->admin(), ['user_id' => 7, 'team_id' => 11, 'role' => 'assistant_coach']);
        $this->assertSame(200, $r['status']);
        $rows = $this->members(11, 7);
        $this->assertCount(1, $rows);
        $this->assertSame('active', $rows[0]['status']);
        $this->assertNull($rows[0]['leave_date']);
    }

    public function testPromotingAnAssistantToHeadRetiresTheStaffRow(): void
    {
        // Coach 7 is assistant on team 11; making them head must not leave them
        // listed twice on the same team.
        $r = coachTeams_assign($this->pdo, $this->admin(), ['user_id' => 7, 'team_id' => 11, 'role' => 'head_coach']);
        $this->assertSame(200, $r['status']);
        $this->assertSame('7', (string) $this->headOf(11));
        $this->assertSame('inactive', $this->members(11, 7)[0]['status']);
        $list = coachTeams_list($this->pdo, $this->admin(), 7, self::CLUB);
        $this->assertSame([[11, 'head_coach']], array_map(fn($t) => [$t['id'], $t['role']], $list['body']['teams']));
    }

    public function testAnUnknownRoleIs400(): void
    {
        $r = coachTeams_assign($this->pdo, $this->admin(), ['user_id' => 7, 'team_id' => 11, 'role' => 'player']);
        $this->assertSame(400, $r['status']);
    }

    public function testANonCoachIs422(): void
    {
        foreach ([12, 13, 11, 999] as $uid) {
            $r = coachTeams_assign($this->pdo, $this->admin(), ['user_id' => $uid, 'team_id' => 11, 'role' => 'assistant_coach']);
            $this->assertSame(422, $r['status'], "user $uid: " . json_encode($r));
            $this->assertSame([], $this->members(11, $uid));
        }
        $this->assertNull($this->headOf(11));
    }

    public function testAClubAdminMayBeAssignedAsACoach(): void
    {
        $r = coachTeams_assign($this->pdo, $this->admin(), ['user_id' => 16, 'team_id' => 11, 'role' => 'head_coach']);
        $this->assertSame(200, $r['status']);
        $this->assertSame('16', (string) $this->headOf(11));
    }

    // ------------------------------------------------------------ unassign

    public function testUnassignHeadClearsTheColumn(): void
    {
        $r = coachTeams_unassign($this->pdo, $this->admin(), ['user_id' => 8, 'team_id' => 10]);
        $this->assertSame(200, $r['status'], json_encode($r));
        $this->assertNull($this->headOf(10));
        $this->assertSame(['head_coach'], $r['body']['removed_roles']);
        $audits = $this->audits('coach_unassigned_from_team');
        $this->assertCount(1, $audits);
        $this->assertSame(['head_coach'], json_decode($audits[0]['details'], true)['roles']);
    }

    public function testUnassignAssistantSetsInactiveAndLeaveDateNeverDeletes(): void
    {
        $r = coachTeams_unassign($this->pdo, $this->admin(), ['user_id' => 7, 'team_id' => 11]);
        $this->assertSame(200, $r['status']);
        $rows = $this->members(11, 7);
        $this->assertCount(1, $rows, 'the row survives');
        $this->assertSame('inactive', $rows[0]['status']);
        $this->assertSame(date('Y-m-d'), $rows[0]['leave_date']);
        $this->assertSame(['assistant_coach'], $r['body']['removed_roles']);
        $this->assertSame([], coachTeams_list($this->pdo, $this->admin(), 7, self::CLUB)['body']['teams']);
    }

    public function testUnassignSomeoneNotOnTheTeamIs404AndAuditsNothing(): void
    {
        $r = coachTeams_unassign($this->pdo, $this->admin(), ['user_id' => 8, 'team_id' => 11]);
        $this->assertSame(404, $r['status']);
        $this->assertCount(0, $this->audits('coach_unassigned_from_team'));
    }

    // ------------------------------------------------------------ call sites

    public function testTheGatewayAuthenticatesAndDispatchesToTheLib(): void
    {
        $src = file_get_contents(__DIR__ . '/../../api/coach-teams.php');
        $this->assertStringContainsString('AuthMiddleware::requireAuth()', $src);
        $this->assertStringNotContainsString('JWT::decode(', $src);
        foreach (['coachTeams_list(', 'coachTeams_assign(', 'coachTeams_unassign('] as $fn) {
            $this->assertStringContainsString($fn, $src);
        }
    }

    public function testEveryWriteInvalidatesTheRoleCache(): void
    {
        // A coach's derived 'coach' role comes from primary_coach_id / team_members
        // and lives in the cached half of the role set (lib/role_cache.php).
        $code = file_get_contents(__DIR__ . '/../../lib/coach_teams.php');
        $this->assertGreaterThanOrEqual(2, substr_count($code, 'te_role_cache_invalidate('));
    }
}
