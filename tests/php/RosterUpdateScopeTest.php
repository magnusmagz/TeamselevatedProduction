<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;
use AuthMiddleware;
use AthleteScope;

/**
 * Unit tests for COACH-07: roster edit persistence + coach scope enforcement.
 *
 * The update lives in the procedural gateway legacy/team-players-gateway.php
 * (it emits HTTP headers / exit() on include, so it can't be required in a unit
 * test). These tests exercise the two pieces of logic the gateway relies on,
 * implemented here with the SAME field mapping, the SAME UPDATE shape, and the
 * SAME scope predicate the gateway uses:
 *
 *   1. Field mapping + UPDATE — applyRosterUpdate() mirrors the gateway PUT:
 *      status / jersey_number / primary_position / positions are mapped to
 *      team_members columns; an empty field set is a no-op that must be
 *      rejected (the original bug returned success without updating).
 *   2. Scope — canManageTeamRoster() mirrors tpg_requireTeamRosterAccess():
 *      super_admin, club_admin of the team's club, or a coach of the team may
 *      edit; anyone else is denied. Coach->teams uses the real
 *      AthleteScope::coachTeamIdsForUser().
 *
 * Fixture (one club 100, two teams):
 *   Team 10 — primary_coach_id = 50  -> roster member id 1 (athlete 1)
 *   Team 11 — assistant_coach 51     -> roster member id 2 (athlete 2)
 *   Club admin: user 60 (club_admin scope_id 100)
 *   Unrelated: user 70
 */
class RosterUpdateScopeTest extends TestCase
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
                status TEXT,
                jersey_number INTEGER,
                primary_position TEXT,
                positions TEXT
            );
        ");
    }

    private function seed(): void
    {
        $this->pdo->exec("INSERT INTO teams (id, name, club_id, primary_coach_id, deleted_at) VALUES
            (10, 'Team A', 100, 50, NULL),
            (11, 'Team B', 100, NULL, NULL)");

        // Roster players
        $this->pdo->exec("INSERT INTO team_members (id, team_id, user_id, athlete_id, role, status, jersey_number, primary_position, positions) VALUES
            (1, 10, NULL, 1, 'player', 'active', NULL, NULL, NULL),
            (2, 11, NULL, 2, 'player', 'active', NULL, NULL, NULL)");

        // Coach 51 is an active assistant_coach on team 11
        $this->pdo->exec("INSERT INTO team_members (id, team_id, user_id, athlete_id, role, status) VALUES
            (3, 11, 51, NULL, 'assistant_coach', 'active')");
    }

    // ---- Auth helpers (mirror AthleteScopeTest) ----

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

    // ---- Logic under test: scope (mirrors tpg_requireTeamRosterAccess) ----

    private function canManageTeamRoster(AuthMiddleware $auth, int $teamMemberId): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT tm.team_id, t.club_id
            FROM team_members tm
            JOIN teams t ON t.id = tm.team_id
            WHERE tm.id = ?
        ");
        $stmt->execute([$teamMemberId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }

        $teamId = (int) $row['team_id'];
        $clubId = $row['club_id'] !== null ? (int) $row['club_id'] : null;

        if ($auth->isSuperAdmin()) {
            return true;
        }
        if ($clubId !== null && $auth->hasRole('club_admin', $clubId, 'club')) {
            return true;
        }
        $userId = (int) $auth->getUserId();
        $coachTeamIds = AthleteScope::coachTeamIdsForUser($this->pdo, $userId);
        return in_array($teamId, $coachTeamIds, true);
    }

    // ---- Logic under test: field mapping + UPDATE (mirrors the gateway PUT) ----

    /**
     * @return int rows affected; -1 signals "no updatable fields" (no-op -> 400)
     */
    private function applyRosterUpdate(int $teamMemberId, array $input): int
    {
        $fields = [];
        $values = [];

        if (array_key_exists('status', $input)) {
            $fields[] = 'status = ?';
            $values[] = $input['status'];
        }
        if (array_key_exists('jersey_number', $input)) {
            $fields[] = 'jersey_number = ?';
            $jersey = $input['jersey_number'];
            $values[] = ($jersey === '' || $jersey === null) ? null : (int) $jersey;
        }
        if (array_key_exists('primary_position', $input)) {
            $fields[] = 'primary_position = ?';
            $values[] = ($input['primary_position'] === '') ? null : $input['primary_position'];
        }
        if (array_key_exists('positions', $input)) {
            $fields[] = 'positions = ?';
            $values[] = json_encode($input['positions']);
        }

        if (empty($fields)) {
            return -1; // no-op -> gateway returns 400
        }

        $values[] = $teamMemberId;
        $stmt = $this->pdo->prepare(
            "UPDATE team_members SET " . implode(', ', $fields) . " WHERE id = ?"
        );
        $stmt->execute($values);
        return $stmt->rowCount();
    }

    private function fetchMember(int $id): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM team_members WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // ---- Persistence tests ----

    public function testJerseyAndPositionPersist(): void
    {
        $affected = $this->applyRosterUpdate(1, [
            'primary_position' => 'Striker',
            'positions' => ['Striker'],
            'jersey_number' => 9,
        ]);
        $this->assertSame(1, $affected);

        $row = $this->fetchMember(1);
        $this->assertSame('Striker', $row['primary_position']);
        $this->assertSame(9, (int) $row['jersey_number']);
        $this->assertSame('["Striker"]', $row['positions']);
    }

    public function testStatusPersistsForEachAllowedValue(): void
    {
        foreach (['injured', 'suspended', 'inactive', 'active'] as $status) {
            $affected = $this->applyRosterUpdate(1, ['status' => $status]);
            $this->assertSame(1, $affected, "status=$status should update one row");
            $this->assertSame($status, $this->fetchMember(1)['status']);
        }
    }

    public function testEmptyStringJerseyIsStoredAsNull(): void
    {
        $this->applyRosterUpdate(1, ['jersey_number' => 7]);
        $this->assertSame(7, (int) $this->fetchMember(1)['jersey_number']);

        $this->applyRosterUpdate(1, ['jersey_number' => '']);
        $this->assertNull($this->fetchMember(1)['jersey_number']);
    }

    public function testNoUpdatableFieldsIsRejectedAsNoOp(): void
    {
        // The original bug: an empty field set returned success without updating.
        $result = $this->applyRosterUpdate(1, ['team_member_id' => 1]);
        $this->assertSame(-1, $result, 'no recognized fields must be a no-op (400)');
    }

    // ---- Scope tests ----

    public function testHeadCoachCanManageOwnTeamMember(): void
    {
        // Coach 50 is primary_coach of team 10 (member id 1).
        $this->assertTrue($this->canManageTeamRoster($this->coach(50), 1));
    }

    public function testAssistantCoachCanManageOwnTeamMember(): void
    {
        // Coach 51 is assistant_coach on team 11 (member id 2).
        $this->assertTrue($this->canManageTeamRoster($this->coach(51), 2));
    }

    public function testCoachCannotManageOtherTeamMember(): void
    {
        // Coach 50 coaches team 10 only; member id 2 is on team 11.
        $this->assertFalse($this->canManageTeamRoster($this->coach(50), 2));
        // Coach 51 coaches team 11 only; member id 1 is on team 10.
        $this->assertFalse($this->canManageTeamRoster($this->coach(51), 1));
    }

    public function testClubAdminCanManageAnyMemberInClub(): void
    {
        $admin = $this->clubAdmin(100);
        $this->assertTrue($this->canManageTeamRoster($admin, 1));
        $this->assertTrue($this->canManageTeamRoster($admin, 2));
    }

    public function testUnrelatedUserIsDenied(): void
    {
        $this->assertFalse($this->canManageTeamRoster($this->unrelated(), 1));
        $this->assertFalse($this->canManageTeamRoster($this->unrelated(), 2));
    }

    public function testSuperAdminCanManageAnyMember(): void
    {
        $this->assertTrue($this->canManageTeamRoster($this->superAdmin(), 1));
        $this->assertTrue($this->canManageTeamRoster($this->superAdmin(), 2));
    }

    public function testMissingMemberIsDenied(): void
    {
        $this->assertFalse($this->canManageTeamRoster($this->superAdmin(), 999));
    }
}
