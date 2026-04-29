<?php
/**
 * StandingsCalculator Service
 *
 * Recalculates group standings from match results.
 * Supports configurable tiebreaker rules and goal differential caps.
 */

class StandingsCalculator {
    private $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Recalculate all standings for a group from completed matches.
     *
     * Implementation note: this method intentionally uses DELETE + INSERT
     * inside a transaction rather than INSERT ... ON CONFLICT DO UPDATE.
     *
     * Why: a 2026-04-29 incident on Spring Classic 2026 produced a state
     * where one team's standings row silently failed to update via the
     * upsert path even though its matches were correctly tallied in memory.
     * Root cause was never definitively identified (suspected interaction
     * between row-level locks held by an in-flight bracket-slot operation
     * and the upsert's conflict resolution path), but DELETE + INSERT
     * eliminates upsert ambiguity entirely. The trade-off is one extra
     * round-trip per recalculation, which is trivial at our scale.
     */
    public function recalculate(int $groupId): array {
        // Get division config
        $configStmt = $this->db->prepare("
            SELECT td.points_for_win, td.points_for_draw, td.points_for_loss,
                   td.goal_differential_cap, td.tiebreaker_rules, td.teams_advancing_per_group
            FROM tournament_groups tg
            JOIN tournament_divisions td ON td.id = tg.division_id
            WHERE tg.id = ?
        ");
        $configStmt->execute([$groupId]);
        $config = $configStmt->fetch(PDO::FETCH_ASSOC);

        if (!$config) return [];

        $ptsWin = (int)$config['points_for_win'];
        $ptsDraw = (int)$config['points_for_draw'];
        $ptsLoss = (int)$config['points_for_loss'];
        $cap = $config['goal_differential_cap'] ? (int)$config['goal_differential_cap'] : null;
        $tiebreakerRules = is_string($config['tiebreaker_rules'])
            ? json_decode($config['tiebreaker_rules'], true)
            : $config['tiebreaker_rules'];

        // Get all teams in the group
        $teamStmt = $this->db->prepare("
            SELECT tr.id AS registration_id, COALESCE(tr.team_name_override, t.name) AS team_name
            FROM tournament_registrations tr
            JOIN teams t ON t.id = tr.team_id
            WHERE tr.group_id = ? AND tr.status = 'accepted'
        ");
        $teamStmt->execute([$groupId]);
        $teams = $teamStmt->fetchAll(PDO::FETCH_ASSOC);

        // Initialize standings
        $standings = [];
        foreach ($teams as $team) {
            $standings[$team['registration_id']] = [
                'registration_id' => (int)$team['registration_id'],
                'team_name' => $team['team_name'],
                'played' => 0, 'won' => 0, 'drawn' => 0, 'lost' => 0,
                'goals_for' => 0, 'goals_against' => 0,
                'goal_difference' => 0, 'points' => 0,
            ];
        }

        // Get completed matches
        $matchStmt = $this->db->prepare("
            SELECT home_registration_id, away_registration_id, home_score, away_score
            FROM tournament_matches
            WHERE group_id = ? AND status = 'completed'
        ");
        $matchStmt->execute([$groupId]);
        $matches = $matchStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($matches as $match) {
            $homeId = (int)$match['home_registration_id'];
            $awayId = (int)$match['away_registration_id'];
            $hs = (int)$match['home_score'];
            $as = (int)$match['away_score'];

            // Apply goal differential cap
            list($cappedHs, $cappedAs) = $this->applyGoalDiffCap($hs, $as, $cap);

            if (!isset($standings[$homeId]) || !isset($standings[$awayId])) continue;

            // Home team stats
            $standings[$homeId]['played']++;
            $standings[$homeId]['goals_for'] += $cappedHs;
            $standings[$homeId]['goals_against'] += $cappedAs;

            // Away team stats
            $standings[$awayId]['played']++;
            $standings[$awayId]['goals_for'] += $cappedAs;
            $standings[$awayId]['goals_against'] += $cappedHs;

            if ($hs > $as) {
                $standings[$homeId]['won']++;
                $standings[$homeId]['points'] += $ptsWin;
                $standings[$awayId]['lost']++;
                $standings[$awayId]['points'] += $ptsLoss;
            } elseif ($hs < $as) {
                $standings[$awayId]['won']++;
                $standings[$awayId]['points'] += $ptsWin;
                $standings[$homeId]['lost']++;
                $standings[$homeId]['points'] += $ptsLoss;
            } else {
                $standings[$homeId]['drawn']++;
                $standings[$homeId]['points'] += $ptsDraw;
                $standings[$awayId]['drawn']++;
                $standings[$awayId]['points'] += $ptsDraw;
            }
        }

        // Calculate goal difference
        foreach ($standings as &$s) {
            $s['goal_difference'] = $s['goals_for'] - $s['goals_against'];
        }

        // Sort by tiebreaker rules
        $standingsArray = array_values($standings);
        $standingsArray = $this->resolvePositions($standingsArray, $tiebreakerRules, $groupId);

        // Persist via DELETE + INSERT in a transaction. See class doc comment
        // for why this is preferred over INSERT ... ON CONFLICT DO UPDATE here.
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) $this->db->beginTransaction();

        try {
            $this->db->prepare("DELETE FROM tournament_standings WHERE group_id = ?")
                     ->execute([$groupId]);

            $insertStmt = $this->db->prepare("
                INSERT INTO tournament_standings (
                    group_id, registration_id, played, won, drawn, lost,
                    goals_for, goals_against, goal_difference, points, position, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ");

            foreach ($standingsArray as $i => $s) {
                $position = $i + 1;
                $insertStmt->execute([
                    $groupId, $s['registration_id'],
                    $s['played'], $s['won'], $s['drawn'], $s['lost'],
                    $s['goals_for'], $s['goals_against'], $s['goal_difference'], $s['points'],
                    $position,
                ]);
                $standingsArray[$i]['position'] = $position;
            }

            if ($ownsTransaction) $this->db->commit();
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }

        return $standingsArray;
    }

    /**
     * Apply goal differential cap to a match result
     */
    public function applyGoalDiffCap(int $homeScore, int $awayScore, ?int $cap): array {
        if ($cap === null) return [$homeScore, $awayScore];

        $diff = abs($homeScore - $awayScore);
        if ($diff <= $cap) return [$homeScore, $awayScore];

        // Cap the winning score
        if ($homeScore > $awayScore) {
            return [$awayScore + $cap, $awayScore];
        } else {
            return [$homeScore, $homeScore + $cap];
        }
    }

    /**
     * Sort standings by tiebreaker rules
     */
    public function resolvePositions(array $standings, array $tiebreakerRules, int $groupId): array {
        usort($standings, function ($a, $b) use ($tiebreakerRules, $groupId) {
            foreach ($tiebreakerRules as $rule) {
                $cmp = 0;
                switch ($rule) {
                    case 'points':
                        $cmp = $b['points'] - $a['points'];
                        break;
                    case 'goal_difference':
                        $cmp = $b['goal_difference'] - $a['goal_difference'];
                        break;
                    case 'goals_for':
                        $cmp = $b['goals_for'] - $a['goals_for'];
                        break;
                    case 'goals_against':
                        $cmp = $a['goals_against'] - $b['goals_against']; // fewer is better
                        break;
                    case 'wins':
                        $cmp = $b['won'] - $a['won'];
                        break;
                    case 'head_to_head':
                        $cmp = $this->compareHeadToHead($a['registration_id'], $b['registration_id'], $groupId);
                        break;
                }
                if ($cmp !== 0) return $cmp;
            }
            return 0;
        });

        return $standings;
    }

    /**
     * Head-to-head comparison between two teams
     */
    private function compareHeadToHead(int $teamA, int $teamB, int $groupId): int {
        $stmt = $this->db->prepare("
            SELECT home_registration_id, away_registration_id, home_score, away_score
            FROM tournament_matches
            WHERE group_id = ? AND status = 'completed'
            AND ((home_registration_id = ? AND away_registration_id = ?)
              OR (home_registration_id = ? AND away_registration_id = ?))
        ");
        $stmt->execute([$groupId, $teamA, $teamB, $teamB, $teamA]);
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $aPoints = 0; $bPoints = 0;
        foreach ($matches as $m) {
            $hId = (int)$m['home_registration_id'];
            $hs = (int)$m['home_score'];
            $as = (int)$m['away_score'];

            if ($hs > $as) {
                if ($hId === $teamA) $aPoints += 3; else $bPoints += 3;
            } elseif ($hs < $as) {
                if ($hId === $teamA) $bPoints += 3; else $aPoints += 3;
            } else {
                $aPoints += 1; $bPoints += 1;
            }
        }

        return $bPoints - $aPoints; // higher points = better position (sorts first)
    }
}
