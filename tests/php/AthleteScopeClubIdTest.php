<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;
use AuthMiddleware;
use AthleteScope;

/**
 * CA-18 regression: a club admin's People -> Athletes list (and the per-athlete
 * access check) must include athletes who belong to the club but are not yet on
 * any team. Club membership is derived from BOTH team_members -> teams.club_id
 * AND the direct athletes.club_id column.
 *
 * The original AthleteScope only looked at team membership, so an athlete with
 * athletes.club_id set but no team_members row was invisible to the club admin —
 * search/filter could never match them because they never entered the list.
 *
 * This fixture deliberately gives the athletes table a club_id column (which the
 * other scope tests omit) to prove the direct-club path works, while the guarded
 * lookups in AthleteScope keep those older column-less fixtures passing.
 *
 * Runs entirely against in-memory SQLite — never touches production Neon.
 *
 * Fixture:
 *   Club 100 admin = user 60
 *   Team 10 (club 100) -> athlete 1 (on a team)
 *   Athlete 2: club_id = 100, NO team membership (the previously-hidden case)
 *   Athlete 3: club_id = 101 (different club) — must stay out of scope
 */
class AthleteScopeClubIdTest extends TestCase
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
            CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT);
            CREATE TABLE user_guardians (id INTEGER PRIMARY KEY, user_id INTEGER, guardian_id INTEGER, source TEXT, confidence TEXT);
            CREATE TABLE athlete_guardians (
                id INTEGER PRIMARY KEY, athlete_id INTEGER, guardian_id INTEGER
            );
        ");

        $this->pdo->exec("INSERT INTO athletes (id, first_name, last_name, club_id) VALUES
            (1, 'OnTeam', 'Athlete', 100),
            (2, 'NoTeam', 'Athlete', 100),
            (3, 'Other',  'Club',    101)");

        $this->pdo->exec("INSERT INTO teams (id, name, club_id, primary_coach_id, deleted_at) VALUES
            (10, 'Team A', 100, 50, NULL)");

        $this->pdo->exec("INSERT INTO team_members (id, team_id, user_id, athlete_id, role, status) VALUES
            (1, 10, NULL, 1, 'player', 'active')");
    }

    private function clubAdmin(int $clubId): AuthMiddleware
    {
        return AuthMiddleware::fromContext([
            'user_id' => 60,
            'email' => 'admin@club.test',
            'roles' => [['role' => 'club_admin', 'scope_type' => 'club', 'scope_id' => $clubId]],
        ]);
    }

    public function testClubAdminSeesTeamlessClubAthleteInList(): void
    {
        $filter = AthleteScope::accessibleAthleteFilter($this->pdo, $this->clubAdmin(100));
        sort($filter['athlete_ids']);
        // Both the on-team athlete (1) and the team-less club athlete (2) appear.
        $this->assertSame([1, 2], $filter['athlete_ids']);
    }

    public function testClubAdminListExcludesOtherClubAthlete(): void
    {
        $filter = AthleteScope::accessibleAthleteFilter($this->pdo, $this->clubAdmin(100));
        $this->assertNotContains(3, $filter['athlete_ids']);
    }

    public function testClubAdminCanAccessTeamlessClubAthleteDetail(): void
    {
        // CA-19/CA-20 detail + edit access for the previously-hidden athlete.
        $this->assertTrue(
            AthleteScope::userCanAccessAthlete($this->pdo, $this->clubAdmin(100), 2)
        );
    }

    public function testClubAdminCannotAccessOtherClubAthleteDetail(): void
    {
        $this->assertFalse(
            AthleteScope::userCanAccessAthlete($this->pdo, $this->clubAdmin(100), 3)
        );
    }

    public function testAthleteClubIdsUnionsTeamAndDirectClub(): void
    {
        // Athlete 1 is on a team in club 100 AND has club_id 100 -> single entry.
        $this->assertSame([100], AthleteScope::athleteClubIds($this->pdo, 1));
        // Athlete 2 has only the direct club_id path.
        $this->assertSame([100], AthleteScope::athleteClubIds($this->pdo, 2));
    }
}
