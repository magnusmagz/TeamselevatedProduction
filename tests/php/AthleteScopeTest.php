<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;
use AuthMiddleware;
use AthleteScope;

/**
 * Unit tests for AthleteScope access-control logic.
 *
 * Uses an in-memory SQLite database seeded with a fixture so the logic runs
 * without any production data or credentials. The queries in AthleteScope use
 * portable SQL (JOIN / IN / boolean comparisons) that works identically on
 * SQLite and PostgreSQL.
 *
 * Fixture (one club, two teams, two families, two coaches):
 *   Club 100
 *     Team 10 (primary_coach_id = 50)   -> athlete 1
 *     Team 11 (coach 51 via team_members assistant_coach) -> athlete 2
 *   Athlete 1: guardian "alice@family-a.com" (guardian 200)
 *   Athlete 2: guardian "bob@family-b.com"   (guardian 201)
 *   Club admin: user 60 (club_admin scope_id 100)
 *   Unrelated user: 70 (no roles, not guardian, not coach)
 *   Athlete 3: in a DIFFERENT club (101) on team 12 — used for club-scope checks
 */
class AthleteScopeTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->createSchema();
        $this->seed();
    }

    private function createSchema(): void
    {
        $this->pdo->exec("
            CREATE TABLE teams (
                id INTEGER PRIMARY KEY,
                name TEXT,
                club_id INTEGER,
                primary_coach_id INTEGER,
                deleted_at TEXT
            );
            CREATE TABLE team_members (
                id INTEGER PRIMARY KEY,
                team_id INTEGER,
                user_id INTEGER,
                athlete_id INTEGER,
                role TEXT,
                status TEXT
            );
            CREATE TABLE athletes (
                id INTEGER PRIMARY KEY,
                first_name TEXT,
                last_name TEXT
            );
            CREATE TABLE guardians (
                id INTEGER PRIMARY KEY,
                email TEXT
            );
            CREATE TABLE athlete_guardians (
                id INTEGER PRIMARY KEY,
                athlete_id INTEGER,
                guardian_id INTEGER
            );
            CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                email TEXT
            );
            CREATE TABLE user_guardians (
                id INTEGER PRIMARY KEY,
                user_id INTEGER,
                guardian_id INTEGER,
                source TEXT,
                confidence TEXT
            );
        ");
    }

    private function seed(): void
    {
        // Athletes
        $this->pdo->exec("INSERT INTO athletes (id, first_name, last_name) VALUES
            (1, 'Anna', 'Aaron'),
            (2, 'Ben', 'Brown'),
            (3, 'Cara', 'Cole')");

        // Teams: 10 & 11 in club 100, 12 in club 101
        $this->pdo->exec("INSERT INTO teams (id, name, club_id, primary_coach_id, deleted_at) VALUES
            (10, 'Team A', 100, 50, NULL),
            (11, 'Team B', 100, NULL, NULL),
            (12, 'Team C', 101, NULL, NULL)");

        // Roster: athlete players
        $this->pdo->exec("INSERT INTO team_members (id, team_id, user_id, athlete_id, role, status) VALUES
            (1, 10, NULL, 1, 'player', 'active'),
            (2, 11, NULL, 2, 'player', 'active'),
            (3, 12, NULL, 3, 'player', 'active')");

        // Coach 51 is an assistant_coach on team 11 (active)
        $this->pdo->exec("INSERT INTO team_members (id, team_id, user_id, athlete_id, role, status) VALUES
            (4, 11, 51, NULL, 'assistant_coach', 'active')");

        // Guardians
        $this->pdo->exec("INSERT INTO guardians (id, email) VALUES
            (200, 'alice@family-a.com'),
            (201, 'bob@family-b.com')");
        $this->pdo->exec("INSERT INTO athlete_guardians (id, athlete_id, guardian_id) VALUES
            (1, 1, 200),
            (2, 2, 201)");

        // Guardian standing is resolved from the ACCOUNT (lib/guardian_identity.php), so
        // the fixture needs the users rows the resolver reads. 80 and 81 match their
        // guardian row by email; no user_guardians link is seeded, so these cases
        // exercise the email fallback exactly as production does today.
        $this->pdo->exec("INSERT INTO users (id, email) VALUES
            (50, 'coach50@club.test'),
            (51, 'coach51@club.test'),
            (60, 'admin@club.test'),
            (70, 'nobody@example.com'),
            (80, 'alice@family-a.com'),
            (81, 'bob@family-b.com')");
    }

    // ---- Helpers to build AuthMiddleware contexts ----

    private function guardian(string $email, int $userId): AuthMiddleware
    {
        return AuthMiddleware::fromContext([
            'user_id' => $userId,
            'email' => $email,
            'roles' => [],
        ]);
    }

    private function coach(int $userId): AuthMiddleware
    {
        return AuthMiddleware::fromContext([
            'user_id' => $userId,
            'email' => "coach{$userId}@club.test",
            'roles' => [],
        ]);
    }

    private function clubAdmin(int $clubId, int $userId = 60): AuthMiddleware
    {
        return AuthMiddleware::fromContext([
            'user_id' => $userId,
            'email' => 'admin@club.test',
            'roles' => [
                ['role' => 'club_admin', 'scope_type' => 'club', 'scope_id' => $clubId],
            ],
        ]);
    }

    private function unrelated(): AuthMiddleware
    {
        return AuthMiddleware::fromContext([
            'user_id' => 70,
            'email' => 'nobody@example.com',
            'roles' => [],
        ]);
    }

    private function superAdmin(): AuthMiddleware
    {
        return AuthMiddleware::fromContext([
            'user_id' => 1,
            'email' => 'super@platform.test',
            'system_role' => 'super_admin',
            'roles' => [],
        ]);
    }

    // ---- Guardian cases ----

    public function testGuardianCanAccessOwnAthlete(): void
    {
        $auth = $this->guardian('alice@family-a.com', 80);
        $this->assertTrue(AthleteScope::userCanAccessAthlete($this->pdo, $auth, 1));
    }

    public function testGuardianCannotAccessAnotherFamilysAthlete(): void
    {
        $auth = $this->guardian('alice@family-a.com', 80);
        // Athlete 2 belongs to the Brown family (bob@family-b.com).
        $this->assertFalse(AthleteScope::userCanAccessAthlete($this->pdo, $auth, 2));
    }

    // ---- Coach cases ----

    public function testHeadCoachCanAccessAthleteOnTheirTeam(): void
    {
        // Coach 50 is primary_coach_id of team 10 (athlete 1).
        $auth = $this->coach(50);
        $this->assertTrue(AthleteScope::userCanAccessAthlete($this->pdo, $auth, 1));
    }

    public function testAssistantCoachCanAccessAthleteOnTheirTeam(): void
    {
        // Coach 51 is assistant_coach on team 11 (athlete 2).
        $auth = $this->coach(51);
        $this->assertTrue(AthleteScope::userCanAccessAthlete($this->pdo, $auth, 2));
    }

    public function testCoachCannotAccessAthleteOnADifferentTeam(): void
    {
        // Coach 50 only coaches team 10; athlete 2 is on team 11.
        $auth = $this->coach(50);
        $this->assertFalse(AthleteScope::userCanAccessAthlete($this->pdo, $auth, 2));
    }

    // ---- Club admin cases ----

    public function testClubAdminCanAccessAthleteInTheirClub(): void
    {
        $auth = $this->clubAdmin(100);
        $this->assertTrue(AthleteScope::userCanAccessAthlete($this->pdo, $auth, 1));
        $this->assertTrue(AthleteScope::userCanAccessAthlete($this->pdo, $auth, 2));
    }

    public function testClubAdminCannotAccessAthleteInAnotherClub(): void
    {
        // Admin of club 100; athlete 3 is in club 101.
        $auth = $this->clubAdmin(100);
        $this->assertFalse(AthleteScope::userCanAccessAthlete($this->pdo, $auth, 3));
    }

    // ---- Other ----

    public function testUnrelatedUserIsDenied(): void
    {
        $auth = $this->unrelated();
        $this->assertFalse(AthleteScope::userCanAccessAthlete($this->pdo, $auth, 1));
        $this->assertFalse(AthleteScope::userCanAccessAthlete($this->pdo, $auth, 2));
        $this->assertFalse(AthleteScope::userCanAccessAthlete($this->pdo, $auth, 3));
    }

    public function testSuperAdminCanAccessAnyAthlete(): void
    {
        $auth = $this->superAdmin();
        $this->assertTrue(AthleteScope::userCanAccessAthlete($this->pdo, $auth, 3));
    }

    // ---- coachTeamIdsForUser ----

    public function testCoachTeamIdsForUserReturnsPrimaryAndAssistantTeams(): void
    {
        $this->assertSame([10], AthleteScope::coachTeamIdsForUser($this->pdo, 50));
        $this->assertSame([11], AthleteScope::coachTeamIdsForUser($this->pdo, 51));
        $this->assertSame([], AthleteScope::coachTeamIdsForUser($this->pdo, 70));
    }

    // ---- accessibleAthleteFilter (LIST scoping) ----
    //
    // These are FUNCTIONAL: the fragment is executed against the fixture and the
    // rows it returns are asserted. They used to assert `$filter['athlete_ids']`
    // instead, which was only ever a proxy — and the proxy is exactly what was
    // removed. accessibleAthleteFilter() no longer materialises a list (one bind
    // per athlete; Postgres caps binds at 65,535), so the only honest question
    // left is "does the same query come back with the same athletes", which is
    // what the old assertions were standing in for.

    /**
     * Run the scope fragment the way a real list endpoint does and return the
     * athlete ids it admits.
     */
    private function scopedAthleteIds(AuthMiddleware $auth): array
    {
        $filter = AthleteScope::accessibleAthleteFilter($this->pdo, $auth, 'a.id');
        $stmt = $this->pdo->prepare(
            "SELECT a.id FROM athletes a WHERE 1=1 {$filter['sql']} ORDER BY a.id"
        );
        $stmt->execute($filter['params']);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testAccessibleFilterForCoachOnlyReturnsOwnTeamAthletes(): void
    {
        // Coach 50 is primary_coach_id of team 10 (athlete 1) and nothing else.
        $this->assertSame([1], $this->scopedAthleteIds($this->coach(50)));
    }

    public function testAccessibleFilterForAssistantCoachReturnsTheirTeamAthletes(): void
    {
        // Coach 51 reaches team 11 through an active assistant_coach row.
        $this->assertSame([2], $this->scopedAthleteIds($this->coach(51)));
    }

    public function testAccessibleFilterForClubAdminReturnsClubAthletes(): void
    {
        // Club 100 holds teams 10 and 11; athlete 3 is in club 101.
        $this->assertSame([1, 2], $this->scopedAthleteIds($this->clubAdmin(100)));
    }

    public function testAccessibleFilterForGuardianReturnsOwnAthlete(): void
    {
        $this->assertSame([2], $this->scopedAthleteIds($this->guardian('bob@family-b.com', 81)));
    }

    public function testAccessibleFilterForGuardianByRecordedLinkRatherThanEmail(): void
    {
        // The user_guardians half of te_guardian_link_sql(): an account whose
        // address has drifted from its guardian row still reaches its own child.
        // If the rewrite had re-inlined the email comparison this would fail and
        // the email-only cases above would not.
        $this->pdo->exec("INSERT INTO users (id, email) VALUES (82, 'drifted@elsewhere.test')");
        $this->pdo->exec("INSERT INTO user_guardians (id, user_id, guardian_id, source, confidence)
                          VALUES (1, 82, 200, 'admin', 'high')");
        $this->assertSame([1], $this->scopedAthleteIds($this->guardian('drifted@elsewhere.test', 82)));
    }

    public function testAccessibleFilterForCoachParentUnionsBothHats(): void
    {
        // The dual-role case. Coach 50 coaches team 10 (athlete 1); make the same
        // account the guardian of athlete 2, on a team they do NOT coach. Both
        // must come back — capabilities accumulate, they do not replace.
        $this->pdo->exec("UPDATE users SET email = 'bob@family-b.com' WHERE id = 50");
        $this->assertSame([1, 2], $this->scopedAthleteIds($this->coach(50)));
    }

    public function testAccessibleFilterForUnrelatedUserBlocksEverything(): void
    {
        $this->assertSame([], $this->scopedAthleteIds($this->unrelated()));
    }

    public function testAccessibleFilterForSuperAdminIsUnrestricted(): void
    {
        $auth = $this->superAdmin();
        $filter = AthleteScope::accessibleAthleteFilter($this->pdo, $auth);
        $this->assertSame('', $filter['sql']);
        $this->assertSame([], $filter['params']);
        // Unrestricted means every athlete, including the other club's.
        $this->assertSame([1, 2, 3], $this->scopedAthleteIds($auth));
    }

    public function testAccessibleFilterBindsAFixedNumberOfParametersRegardlessOfReach(): void
    {
        // The reason the rewrite exists. Adding 50 more athletes to the admin's
        // club must not add 50 bind parameters.
        $auth = $this->clubAdmin(100);
        $before = count(AthleteScope::accessibleAthleteFilter($this->pdo, $auth)['params']);

        for ($i = 100; $i < 150; $i++) {
            $this->pdo->exec("INSERT INTO athletes (id, first_name, last_name) VALUES ({$i}, 'A{$i}', 'X')");
            $tm = $i + 1000;
            $this->pdo->exec("INSERT INTO team_members (id, team_id, user_id, athlete_id, role, status)
                              VALUES ({$tm}, 10, NULL, {$i}, 'player', 'active')");
        }

        $after = AthleteScope::accessibleAthleteFilter($this->pdo, $auth);
        $this->assertSame($before, count($after['params']));
        $this->assertCount(52, $this->scopedAthleteIds($auth));
    }

    public function testAthleteIdsKeyIsAlwaysNullNow(): void
    {
        // Kept for compatibility, deliberately empty of meaning. A caller that
        // starts reading it again is re-introducing the materialised list.
        foreach ([$this->coach(50), $this->clubAdmin(100), $this->unrelated(), $this->superAdmin()] as $auth) {
            $this->assertNull(AthleteScope::accessibleAthleteFilter($this->pdo, $auth)['athlete_ids']);
        }
    }
}
