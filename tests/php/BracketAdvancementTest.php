<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;
use BracketGenerator;
use StandingsCalculator;

/**
 * Unit tests for group-stage -> knockout advancement (CA-103).
 *
 * CA-103: "Advancing from group stage to knockout should render a bracket with
 * the correct teams and NO duplicate placements."
 *
 * This is the automated counterpart of the manual bug-bash case
 * "Tournaments / Bracket generation -> Advance from groups to knockout".
 * It guards the placement logic that historically broke on 2026-04-29
 * (usort corruption in StandingsCalculator::resolvePositions + duplicate
 * placements when slotting group winners into the bracket).
 *
 * Scope: the placement pipeline that decides WHICH team lands in WHICH slot:
 *   1. StandingsCalculator::resolvePositions  -> stable, unique positions/group
 *   2. BracketGenerator::buildBracketStructure -> unique "Nth Group X" labels
 *   3. BracketGenerator::slotGroupWinners      -> each label -> exactly one team
 *
 * generateBracket() itself is intentionally NOT exercised here: it uses
 * Postgres-only SQL (SELECT ... FOR UPDATE, INSERT ... RETURNING) that does not
 * run on SQLite. The placement correctness it depends on lives entirely in the
 * three methods above, all of which use portable SQL. We persist the bracket
 * rows the same way generateBracket would (round != 'Group Stage', status
 * 'scheduled', group_position source types) so slotGroupWinners sees a faithful
 * fixture.
 *
 * Uses in-memory SQLite. No production data or credentials are touched.
 */
class BracketAdvancementTest extends TestCase
{
    private PDO $pdo;
    private StandingsCalculator $standings;
    private BracketGenerator $bracket;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->createSchema();
        $this->standings = new StandingsCalculator($this->pdo);
        $this->bracket = new BracketGenerator($this->pdo);
    }

    private function createSchema(): void
    {
        $this->pdo->exec("
            CREATE TABLE teams (
                id INTEGER PRIMARY KEY,
                name TEXT
            );
            CREATE TABLE tournament_divisions (
                id INTEGER PRIMARY KEY,
                format TEXT,
                teams_advancing_per_group INTEGER,
                points_for_win INTEGER DEFAULT 3,
                points_for_draw INTEGER DEFAULT 1,
                points_for_loss INTEGER DEFAULT 0,
                goal_differential_cap INTEGER,
                scoring_system TEXT DEFAULT 'standard',
                tiebreaker_rules TEXT,
                game_duration_minutes INTEGER DEFAULT 60
            );
            CREATE TABLE tournament_groups (
                id INTEGER PRIMARY KEY,
                division_id INTEGER,
                name TEXT,
                sort_order INTEGER DEFAULT 0
            );
            CREATE TABLE tournament_registrations (
                id INTEGER PRIMARY KEY,
                division_id INTEGER,
                group_id INTEGER,
                team_id INTEGER,
                team_name_override TEXT,
                status TEXT DEFAULT 'accepted'
            );
            CREATE TABLE tournament_standings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                group_id INTEGER,
                registration_id INTEGER,
                played INTEGER DEFAULT 0,
                won INTEGER DEFAULT 0,
                drawn INTEGER DEFAULT 0,
                lost INTEGER DEFAULT 0,
                goals_for INTEGER DEFAULT 0,
                goals_against INTEGER DEFAULT 0,
                goal_difference INTEGER DEFAULT 0,
                points INTEGER DEFAULT 0,
                position INTEGER,
                updated_at TEXT
            );
            CREATE TABLE tournament_matches (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                division_id INTEGER,
                group_id INTEGER,
                round TEXT,
                match_number INTEGER,
                home_registration_id INTEGER,
                away_registration_id INTEGER,
                home_placeholder TEXT,
                away_placeholder TEXT,
                home_source_match_id INTEGER,
                away_source_match_id INTEGER,
                home_source_type TEXT,
                away_source_type TEXT,
                field_id INTEGER,
                scheduled_time TEXT,
                scheduled_end_time TEXT,
                home_score INTEGER,
                away_score INTEGER,
                status TEXT
            );
        ");
    }

    /**
     * Seed a group_knockout division with $numGroups groups, $teamsPerGroup
     * teams each, $advancing teams advancing per group. Returns the division id.
     * Group names are "Group A", "Group B", ... in sort_order.
     */
    private function seedDivision(int $numGroups, int $teamsPerGroup, int $advancing): int
    {
        $divisionId = 1;
        $tiebreakers = json_encode(['points', 'goal_difference', 'goals_for', 'head_to_head']);
        $stmt = $this->pdo->prepare("
            INSERT INTO tournament_divisions
                (id, format, teams_advancing_per_group, tiebreaker_rules, scoring_system)
            VALUES (?, 'group_knockout', ?, ?, 'standard')
        ");
        $stmt->execute([$divisionId, $advancing, $tiebreakers]);

        $regId = 1;
        $teamId = 1;
        for ($g = 0; $g < $numGroups; $g++) {
            $groupId = $g + 1;
            $groupName = 'Group ' . chr(65 + $g);
            $this->pdo->prepare("
                INSERT INTO tournament_groups (id, division_id, name, sort_order)
                VALUES (?, ?, ?, ?)
            ")->execute([$groupId, $divisionId, $groupName, $g]);

            for ($t = 0; $t < $teamsPerGroup; $t++) {
                $name = $groupName . ' Team ' . ($t + 1);
                $this->pdo->prepare("INSERT INTO teams (id, name) VALUES (?, ?)")
                    ->execute([$teamId, $name]);
                $this->pdo->prepare("
                    INSERT INTO tournament_registrations
                        (id, division_id, group_id, team_id, status)
                    VALUES (?, ?, ?, ?, 'accepted')
                ")->execute([$regId, $divisionId, $groupId, $teamId]);
                $regId++;
                $teamId++;
            }
        }
        return $divisionId;
    }

    /**
     * Play a full round-robin in a group with deterministic, distinct results so
     * every team finishes on a different point total (no ties to resolve). The
     * team registered first wins the most; ordering cascades down. Then run the
     * real StandingsCalculator::recalculate so positions are written exactly as
     * production would write them.
     */
    private function playGroupAndRank(int $groupId): array
    {
        $regs = $this->pdo->prepare("
            SELECT id FROM tournament_registrations WHERE group_id = ? ORDER BY id
        ");
        $regs->execute([$groupId]);
        $regIds = array_column($regs->fetchAll(), 'id');

        // Round robin: lower-index reg beats higher-index reg. This makes the
        // first-registered team finish 1st, etc. — a known expected ordering.
        $matchNum = 1000 + $groupId * 100;
        for ($i = 0; $i < count($regIds); $i++) {
            for ($j = $i + 1; $j < count($regIds); $j++) {
                $this->pdo->prepare("
                    INSERT INTO tournament_matches
                        (division_id, group_id, round, match_number,
                         home_registration_id, away_registration_id,
                         home_score, away_score, status)
                    VALUES (1, ?, 'Group Stage', ?, ?, ?, 2, 0, 'completed')
                ")->execute([$groupId, $matchNum++, $regIds[$i], $regIds[$j]]);
            }
        }

        return $this->standings->recalculate($groupId);
    }

    /**
     * Persist a knockout bracket structure the way generateBracket would, so
     * slotGroupWinners can be exercised. Returns the created match rows (with
     * real ids) keyed nothing-special, plus a temp_idx -> real id map.
     */
    private function persistBracket(int $divisionId, int $numTeams): array
    {
        $structure = $this->bracket->buildBracketStructure(
            $numTeams, false, $divisionId, 0, 'group_knockout'
        );

        $insert = $this->pdo->prepare("
            INSERT INTO tournament_matches
                (division_id, group_id, round, match_number,
                 home_placeholder, away_placeholder,
                 home_source_type, away_source_type, status)
            VALUES (?, NULL, ?, ?, ?, ?, ?, ?, 'scheduled')
        ");
        foreach ($structure as $m) {
            $insert->execute([
                $divisionId,
                $m['round'],
                $m['match_number'],
                $m['home_placeholder'] ?? null,
                $m['away_placeholder'] ?? null,
                $m['home_source_type'] ?? null,
                $m['away_source_type'] ?? null,
            ]);
        }
        return $structure;
    }

    /** Fetch the first-round knockout matches after slotting. */
    private function firstRoundSlots(int $divisionId): array
    {
        // First round = the round with group_position source types.
        $stmt = $this->pdo->prepare("
            SELECT id, round, match_number,
                   home_registration_id, away_registration_id,
                   home_placeholder, away_placeholder,
                   home_source_type, away_source_type
            FROM tournament_matches
            WHERE division_id = ? AND round != 'Group Stage'
              AND (home_source_type = 'group_position' OR away_source_type = 'group_position')
            ORDER BY match_number
        ");
        $stmt->execute([$divisionId]);
        return $stmt->fetchAll();
    }

    // =====================================================================
    // resolvePositions: strict, unique positions (the 2026-04-29 usort fix)
    // =====================================================================

    public function testResolvePositionsAssignsUniquePositions(): void
    {
        $divisionId = $this->seedDivision(1, 4, 2);
        $ranked = $this->playGroupAndRank(1);

        $this->assertCount(4, $ranked);
        $positions = array_map(fn($s) => $s['position'], $ranked);
        $this->assertSame([1, 2, 3, 4], $positions, 'positions must be 1..N with no gaps or dupes');

        // No registration appears twice in the ranking.
        $regIds = array_map(fn($s) => $s['registration_id'], $ranked);
        $this->assertCount(count($regIds), array_unique($regIds), 'no team duplicated in standings');
    }

    public function testResolvePositionsIsStableWhenFullyTied(): void
    {
        // All teams identical on every metric -> comparator must fall back to
        // original index and STILL return a strict permutation (no drop/dupe).
        $rows = [];
        for ($i = 1; $i <= 6; $i++) {
            $rows[] = [
                'registration_id' => $i,
                'team_name' => "T$i",
                'played' => 0, 'won' => 0, 'drawn' => 0, 'lost' => 0,
                'goals_for' => 0, 'goals_against' => 0,
                'goal_difference' => 0, 'points' => 0,
            ];
        }
        $out = $this->standings->resolvePositions($rows, ['points', 'goal_difference'], 1);
        $this->assertCount(6, $out, 'no entry dropped');
        $outIds = array_column($out, 'registration_id');
        $this->assertSame([1, 2, 3, 4, 5, 6], $outIds, 'fully-tied set preserves stable order, no dupes');
    }

    // =====================================================================
    // buildBracketStructure: unique group-position labels (no dup placements)
    // =====================================================================

    /**
     * @dataProvider bracketConfigs
     */
    public function testFirstRoundLabelsAreUnique(int $numGroups, int $advancing): void
    {
        $numTeams = $numGroups * $advancing;
        $divisionId = $this->seedDivision($numGroups, 4, $advancing);
        $structure = $this->bracket->buildBracketStructure(
            $numTeams, false, $divisionId, 0, 'group_knockout'
        );

        // Collect every real (non-BYE) group_position placeholder in round 1.
        $labels = [];
        foreach ($structure as $m) {
            if (($m['home_source_type'] ?? null) === 'group_position' && $m['home_placeholder']) {
                $labels[] = $m['home_placeholder'];
            }
            if (($m['away_source_type'] ?? null) === 'group_position'
                && $m['away_placeholder'] && $m['away_placeholder'] !== 'BYE') {
                $labels[] = $m['away_placeholder'];
            }
        }

        $this->assertCount($numTeams, $labels, "expected $numTeams advancing slots");
        $this->assertCount(count($labels), array_unique($labels),
            'no duplicate group-position label (no duplicate placement): ' . implode(', ', $labels));
    }

    public static function bracketConfigs(): array
    {
        return [
            '2 groups x 2 advancing (4 teams)'      => [2, 2],
            '3 groups x 2 advancing (6 teams, byes)' => [3, 2],
            '4 groups x 2 advancing (8 teams)'      => [4, 2],
            '4 groups x 1 advancing (4 teams)'      => [4, 1],
            '2 groups x 4 advancing (8 teams)'      => [2, 4],
            '5 groups x 2 advancing (10 teams, byes)' => [5, 2],
        ];
    }

    // =====================================================================
    // End-to-end: groups -> standings -> bracket -> slotted teams
    // =====================================================================

    public function testAdvanceFromGroupsProducesCorrectTeamsAndNoDuplicates(): void
    {
        $numGroups = 2;
        $advancing = 2;
        $teamsPerGroup = 4;
        $numTeams = $numGroups * $advancing;

        $divisionId = $this->seedDivision($numGroups, $teamsPerGroup, $advancing);

        // Rank each group with a known ordering (reg id ASC == position ASC).
        $expectedByLabel = []; // "1st Group A" => registration_id
        for ($g = 1; $g <= $numGroups; $g++) {
            $ranked = $this->playGroupAndRank($g);
            $groupName = 'Group ' . chr(65 + ($g - 1));
            foreach ($ranked as $s) {
                $pos = $s['position'];
                if ($pos <= $advancing) {
                    $ord = $pos === 1 ? '1st' : ($pos === 2 ? '2nd' : ($pos === 3 ? '3rd' : "{$pos}th"));
                    $expectedByLabel["$ord $groupName"] = (int)$s['registration_id'];
                }
            }
        }
        $this->assertCount($numTeams, $expectedByLabel, 'sanity: one advancing team per label');

        // Build + persist the bracket, then slot the group winners in.
        $this->persistBracket($divisionId, $numTeams);
        $slotted = $this->bracket->slotGroupWinners($divisionId);
        $this->assertSame($numTeams, $slotted, 'every advancing slot should be filled');

        // Gather all slotted registration ids across first-round matches.
        $rows = $this->firstRoundSlots($divisionId);
        $slottedRegIds = [];
        foreach ($rows as $r) {
            // Verify the team matches the placeholder's expected team.
            if ($r['home_source_type'] === 'group_position' && $r['home_placeholder']) {
                $this->assertNotNull($r['home_registration_id'],
                    "home slot for '{$r['home_placeholder']}' should be filled");
                $this->assertSame(
                    $expectedByLabel[$r['home_placeholder']],
                    (int)$r['home_registration_id'],
                    "wrong team slotted into '{$r['home_placeholder']}'"
                );
                $slottedRegIds[] = (int)$r['home_registration_id'];
            }
            if ($r['away_source_type'] === 'group_position'
                && $r['away_placeholder'] && $r['away_placeholder'] !== 'BYE') {
                $this->assertNotNull($r['away_registration_id'],
                    "away slot for '{$r['away_placeholder']}' should be filled");
                $this->assertSame(
                    $expectedByLabel[$r['away_placeholder']],
                    (int)$r['away_registration_id'],
                    "wrong team slotted into '{$r['away_placeholder']}'"
                );
                $slottedRegIds[] = (int)$r['away_registration_id'];
            }
        }

        // THE core CA-103 assertion: no team placed in two slots.
        $this->assertCount($numTeams, $slottedRegIds, 'expected one slotted team per advancing position');
        $this->assertCount(count($slottedRegIds), array_unique($slottedRegIds),
            'NO duplicate placements: each advancing team appears in exactly one bracket slot');

        // And every advancing team (and only those) made it in.
        sort($slottedRegIds);
        $expectedRegIds = array_values($expectedByLabel);
        sort($expectedRegIds);
        $this->assertSame($expectedRegIds, $slottedRegIds, 'bracket contains exactly the advancing teams');
    }

    public function testAdvanceWithByesSlotsRealTeamsAndKeepsByeEmpty(): void
    {
        // 3 groups x 2 = 6 teams -> bracket size 8 -> two byes. The bye opponent
        // slots must stay empty (BYE), and the 6 real advancing teams must each
        // appear exactly once with no duplicates.
        $numGroups = 3;
        $advancing = 2;
        $numTeams = $numGroups * $advancing;

        $divisionId = $this->seedDivision($numGroups, 4, $advancing);
        for ($g = 1; $g <= $numGroups; $g++) {
            $this->playGroupAndRank($g);
        }

        $this->persistBracket($divisionId, $numTeams);
        $slotted = $this->bracket->slotGroupWinners($divisionId);
        $this->assertSame($numTeams, $slotted, '6 real advancing teams slotted, byes left empty');

        $rows = $this->firstRoundSlots($divisionId);
        $slottedRegIds = [];
        $byeCount = 0;
        foreach ($rows as $r) {
            if ($r['home_registration_id'] !== null) $slottedRegIds[] = (int)$r['home_registration_id'];
            if ($r['away_registration_id'] !== null) $slottedRegIds[] = (int)$r['away_registration_id'];
            if ($r['away_placeholder'] === 'BYE') $byeCount++;
        }

        $this->assertSame(2, $byeCount, 'two bye matches for a 6-team / 8-slot bracket');
        $this->assertCount($numTeams, $slottedRegIds, 'all 6 advancing teams slotted');
        $this->assertCount(count($slottedRegIds), array_unique($slottedRegIds),
            'NO duplicate placements even with byes');
    }
}
