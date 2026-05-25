<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;

/**
 * Unit tests for the email-template team-visibility filter (CA-55).
 *
 * The filter lives inline in api/email-templates.php (a procedural gateway that
 * emits HTTP headers on include, so it can't be required directly in a unit
 * test). These tests exercise the two pieces of logic the gateway relies on,
 * implemented here with the SAME SQL and the SAME predicate the gateway uses:
 *
 *   1. getCoachTeamIdsForTemplates() — the canonical coach-teams query
 *      (teams.primary_coach_id = userId OR active assistant_coach/team_manager).
 *   2. The visibility predicate — a non-admin sees a template when its
 *      team_visibility is empty (all teams), it's their own, or it intersects
 *      the teams they coach.
 *
 * Fixture (one club 100):
 *   Team 10 — primary_coach_id = 50
 *   Team 11 — assistant_coach 51 (active)
 *   Team 12 — coached by nobody in this fixture
 *   Coach 50 -> [10], Coach 51 -> [11], User 70 -> []
 */
class EmailTemplateVisibilityTest extends TestCase
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
                primary_coach_id INTEGER
            );
            CREATE TABLE team_members (
                id INTEGER PRIMARY KEY,
                team_id INTEGER,
                user_id INTEGER,
                role TEXT,
                status TEXT
            );
        ");
    }

    private function seed(): void
    {
        $this->pdo->exec("INSERT INTO teams (id, name, club_id, primary_coach_id) VALUES
            (10, 'Team Ten', 100, 50),
            (11, 'Team Eleven', 100, NULL),
            (12, 'Team Twelve', 100, NULL)");
        // Coach 51 is an active assistant_coach on team 11.
        $this->pdo->exec("INSERT INTO team_members (id, team_id, user_id, role, status) VALUES
            (1, 11, 51, 'assistant_coach', 'active'),
            (2, 11, 99, 'assistant_coach', 'inactive')");
    }

    /**
     * Mirrors api/email-templates.php::getCoachTeamIdsForTemplates().
     */
    private function getCoachTeamIds(int $userId, int $clubProfileId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT DISTINCT t.id
            FROM teams t
            LEFT JOIN team_members tm
                ON tm.team_id = t.id
                AND tm.user_id = ?
                AND tm.role IN ('assistant_coach','team_manager')
                AND tm.status = 'active'
            WHERE (t.primary_coach_id = ? OR tm.id IS NOT NULL)
              AND t.club_id = ?
        ");
        $stmt->execute([$userId, $userId, $clubProfileId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Mirrors the visibility predicate in api/email-templates.php list action.
     * Returns true when the non-admin coach should see the template.
     */
    private function visibleToCoach(array $template, array $coachTeamIds, int $userId): bool
    {
        $coachTeamSet = array_fill_keys(array_map('intval', $coachTeamIds), true);
        $vis = $template['team_visibility'] ?? [];

        if (empty($vis)) {
            return true; // visible to all teams
        }
        if (isset($template['created_by']) && (int)$template['created_by'] === $userId) {
            return true; // own template
        }
        foreach ($vis as $teamId) {
            if (isset($coachTeamSet[(int)$teamId])) {
                return true;
            }
        }
        return false;
    }

    // ---- coach-teams query ----

    public function testPrimaryCoachTeams(): void
    {
        $this->assertSame([10], $this->getCoachTeamIds(50, 100));
    }

    public function testAssistantCoachTeams(): void
    {
        $this->assertSame([11], $this->getCoachTeamIds(51, 100));
    }

    public function testInactiveAssistantCoachGetsNoTeams(): void
    {
        // User 99 is only an INACTIVE assistant_coach -> no teams.
        $this->assertSame([], $this->getCoachTeamIds(99, 100));
    }

    public function testNonCoachGetsNoTeams(): void
    {
        $this->assertSame([], $this->getCoachTeamIds(70, 100));
    }

    // ---- visibility predicate ----

    public function testEmptyVisibilityIsVisibleToAllCoaches(): void
    {
        $tpl = ['team_visibility' => [], 'created_by' => 999];
        $this->assertTrue($this->visibleToCoach($tpl, $this->getCoachTeamIds(50, 100), 50));
        $this->assertTrue($this->visibleToCoach($tpl, $this->getCoachTeamIds(70, 100), 70));
    }

    public function testTemplateRestrictedToOtherTeamIsHidden(): void
    {
        // Template visible only to team 12. Coach 50 coaches team 10 -> hidden.
        $tpl = ['team_visibility' => [12], 'created_by' => 999];
        $this->assertFalse($this->visibleToCoach($tpl, $this->getCoachTeamIds(50, 100), 50));
    }

    public function testTemplateVisibleWhenItIntersectsCoachTeams(): void
    {
        // Template visible to teams 10 and 12. Coach 50 coaches team 10 -> visible.
        $tpl = ['team_visibility' => [10, 12], 'created_by' => 999];
        $this->assertTrue($this->visibleToCoach($tpl, $this->getCoachTeamIds(50, 100), 50));
    }

    public function testAssistantCoachSeesTemplateForTheirTeam(): void
    {
        $tpl = ['team_visibility' => [11], 'created_by' => 999];
        $this->assertTrue($this->visibleToCoach($tpl, $this->getCoachTeamIds(51, 100), 51));
        // But the same template is hidden from coach 50 (team 10).
        $this->assertFalse($this->visibleToCoach($tpl, $this->getCoachTeamIds(50, 100), 50));
    }

    public function testCoachSeesOwnTemplateRegardlessOfVisibility(): void
    {
        // Template restricted to team 12 but created by coach 50 themselves.
        $tpl = ['team_visibility' => [12], 'created_by' => 50];
        $this->assertTrue($this->visibleToCoach($tpl, $this->getCoachTeamIds(50, 100), 50));
    }

    public function testCoachWithNoTeamsOnlySeesAllTeamTemplates(): void
    {
        $teams = $this->getCoachTeamIds(70, 100); // []
        $restricted = ['team_visibility' => [10], 'created_by' => 999];
        $allTeams = ['team_visibility' => [], 'created_by' => 999];
        $this->assertFalse($this->visibleToCoach($restricted, $teams, 70));
        $this->assertTrue($this->visibleToCoach($allTeams, $teams, 70));
    }
}
