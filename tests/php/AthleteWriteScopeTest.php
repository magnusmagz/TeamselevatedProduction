<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;
use AuthMiddleware;
use AthleteScope;

/**
 * Writing an athlete is not the same permission as reading one.
 *
 * `userCanAccessAthlete` passes guardians, which is correct — a parent should see
 * their own child. It was also, until 2026-07-30, the gate on every *write* in
 * `legacy/athletes-gateway.php` and `legacy/guardian-gateway.php`, which meant a
 * parent-portal token could:
 *
 *   - PUT their child's `date_of_birth` (age-group eligibility), name and address
 *   - DELETE (soft) their child's athlete record off every roster
 *   - DELETE the OTHER parent's `athlete_guardians` link
 *
 * and, worse, the guardian gateway's POST and PUT had no scope check at all, so
 * ANY authenticated user could attach a guardian row carrying their own email to
 * ANY athlete id — becoming a guardian of a stranger's child, which then unlocked
 * that child's record and health data through the read predicate.
 *
 * None of it was reachable by clicking: every caller is a staff screen. That is
 * precisely why it survived. The tests below assert the predicates are distinct,
 * and the source-level test at the bottom asserts the write handlers actually use
 * the stricter one — because the bug was never in the predicate, it was in which
 * predicate got called.
 *
 * Fixture (mirrors AthleteScopeTest):
 *   Club 100: team 10 (coach 50) -> athlete 1; athlete 4 has club_id 100, NO team
 *   Club 101: team 12            -> athlete 3
 *   Athlete 1 guardian: alice@family-a.com
 */
class AthleteWriteScopeTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->pdo->exec("
            CREATE TABLE teams (
                id INTEGER PRIMARY KEY, name TEXT, club_id INTEGER,
                primary_coach_id INTEGER, deleted_at TEXT
            );
            CREATE TABLE team_members (
                id INTEGER PRIMARY KEY, team_id INTEGER, user_id INTEGER,
                athlete_id INTEGER, role TEXT, status TEXT
            );
            CREATE TABLE athletes (
                id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, club_id INTEGER
            );
            CREATE TABLE guardians (id INTEGER PRIMARY KEY, email TEXT);
            CREATE TABLE athlete_guardians (
                id INTEGER PRIMARY KEY, athlete_id INTEGER, guardian_id INTEGER
            );
        ");

        $this->pdo->exec("INSERT INTO athletes (id, first_name, last_name, club_id) VALUES
            (1, 'Anna', 'Aaron', 100),
            (3, 'Cara', 'Cross', 101),
            (4, 'Dana', 'Doyle', 100)");
        $this->pdo->exec("INSERT INTO teams (id, name, club_id, primary_coach_id) VALUES
            (10, 'Team A', 100, 50),
            (12, 'Team C', 101, 52)");
        $this->pdo->exec("INSERT INTO team_members (id, team_id, athlete_id, role, status) VALUES
            (1, 10, 1, 'player', 'active'),
            (3, 12, 3, 'player', 'active')");
        $this->pdo->exec("INSERT INTO guardians (id, email) VALUES (200, 'alice@family-a.com')");
        $this->pdo->exec("INSERT INTO athlete_guardians (id, athlete_id, guardian_id) VALUES (1, 1, 200)");
    }

    private function guardian(string $email): AuthMiddleware
    {
        return AuthMiddleware::fromContext(['user_id' => 70, 'email' => $email, 'roles' => []]);
    }

    private function coach(int $userId): AuthMiddleware
    {
        return AuthMiddleware::fromContext([
            'user_id' => $userId, 'email' => "coach{$userId}@club.test", 'roles' => [],
        ]);
    }

    private function clubAdmin(int $clubId): AuthMiddleware
    {
        return AuthMiddleware::fromContext([
            'user_id' => 60, 'email' => 'admin@club.test',
            'roles' => [['role' => 'club_admin', 'scope_type' => 'club', 'scope_id' => $clubId]],
        ]);
    }

    private function superAdmin(): AuthMiddleware
    {
        return AuthMiddleware::fromContext([
            'user_id' => 1, 'email' => 'super@platform.test',
            'system_role' => 'super_admin', 'roles' => [],
        ]);
    }

    /** THE REGRESSION. A guardian reads their child and may not rewrite them. */
    public function testGuardianCanReadButNotManageOwnAthlete(): void
    {
        $auth = $this->guardian('alice@family-a.com');

        $this->assertTrue(
            AthleteScope::userCanAccessAthlete($this->pdo, $auth, 1),
            'a parent must still be able to view their own child'
        );
        $this->assertFalse(
            AthleteScope::staffCanManageAthlete($this->pdo, $auth, 1),
            'a parent must NOT hold write standing over the athlete record'
        );
    }

    /** Staff standing is unchanged — this fix must not lock admins out. */
    public function testStaffKeepBothReadAndManage(): void
    {
        foreach ([
            'coach of the athlete\'s team' => $this->coach(50),
            'club admin of the athlete\'s club' => $this->clubAdmin(100),
            'super admin' => $this->superAdmin(),
        ] as $who => $auth) {
            $this->assertTrue(AthleteScope::userCanAccessAthlete($this->pdo, $auth, 1), $who);
            $this->assertTrue(AthleteScope::staffCanManageAthlete($this->pdo, $auth, 1), $who);
        }
    }

    /**
     * An athlete with a club but no team yet (CA-18) must stay manageable by
     * their club admin — athleteClubIds() reads athletes.club_id as well as team
     * membership. Getting this wrong would silently break editing every athlete
     * who has been created but not yet rostered.
     */
    public function testClubAdminCanManageAthleteWithNoTeamYet(): void
    {
        $this->assertTrue(
            AthleteScope::staffCanManageAthlete($this->pdo, $this->clubAdmin(100), 4)
        );
    }

    public function testStaffStandingDoesNotCrossClubs(): void
    {
        // Club 100's admin has no standing over club 101's athlete...
        $this->assertFalse(
            AthleteScope::staffCanManageAthlete($this->pdo, $this->clubAdmin(100), 3)
        );
        // ...nor does a coach over an athlete on a team they don't coach.
        $this->assertFalse(
            AthleteScope::staffCanManageAthlete($this->pdo, $this->coach(50), 3)
        );
    }

    public function testUnrelatedUserHasNeither(): void
    {
        $auth = $this->guardian('nobody@example.com');

        $this->assertFalse(AthleteScope::userCanAccessAthlete($this->pdo, $auth, 1));
        $this->assertFalse(AthleteScope::staffCanManageAthlete($this->pdo, $auth, 1));
    }

    /**
     * The predicates being right is not the fix — calling the right one is.
     *
     * This reads the two gateways and asserts their write handlers gate on
     * staffCanManageAthlete, and that the read predicate survives only where a
     * read happens. A future edit that swaps one back fails here rather than
     * quietly re-opening a parent's write access.
     */
    public function testWriteHandlersUseTheStricterPredicate(): void
    {
        $athletes = file_get_contents(__DIR__ . '/../../legacy/athletes-gateway.php');
        $guardians = file_get_contents(__DIR__ . '/../../legacy/guardian-gateway.php');

        // athletes-gateway: exactly one read gate (the GET), and PUT + DELETE
        // both on the strict predicate.
        $this->assertSame(
            1,
            substr_count($athletes, 'AthleteScope::userCanAccessAthlete'),
            'athletes-gateway should gate only its GET on the read predicate'
        );
        $this->assertSame(
            2,
            substr_count($athletes, 'AthleteScope::staffCanManageAthlete'),
            'athletes-gateway PUT and DELETE must both require staff standing'
        );

        // guardian-gateway writes to athlete_guardians on every method it
        // accepts, so the read predicate has no business in it at all.
        $this->assertSame(
            0,
            substr_count($guardians, 'AthleteScope::userCanAccessAthlete'),
            'guardian-gateway has no read-only handler; nothing there should use the read predicate'
        );
        $this->assertSame(
            3,
            substr_count($guardians, 'AthleteScope::staffCanManageAthlete'),
            'guardian-gateway POST, PUT and DELETE must each require staff standing'
        );
    }
}
