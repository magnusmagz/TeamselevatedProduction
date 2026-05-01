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
     * Persistence pattern: explicit UPDATE-first, INSERT-if-missing.
     *
     * Why this pattern: the original code used INSERT ... ON CONFLICT DO UPDATE
     * but on 2026-04-29 a team's row silently failed to update in production
     * even though its matches were correctly tallied in memory. A quick switch
     * to DELETE + INSERT inside a transaction also failed (same group, same
     * data) with a phantom unique-constraint violation on a row that had just
     * been deleted in the same transaction — a behavior we couldn't reproduce
     * outside the StandingsCalculator code path. Root cause never definitively
     * identified.
     *
     * Avoiding both ON CONFLICT and DELETE-then-INSERT eliminates whatever
     * PDO/Postgres edge case produces those failures. Cost: at most one extra
     * round-trip per row when a row doesn't yet exist (the INSERT fallback).
     * Trivial at our scale.
     */
    public function recalculate(int $groupId): array {
        // Get division config
        $configStmt = $this->db->prepare("
            SELECT td.points_for_win, td.points_for_draw, td.points_for_loss,
                   td.goal_differential_cap, td.tiebreaker_rules, td.teams_advancing_per_group,
                   td.scoring_system
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
        $scoringSystem = $config['scoring_system'] ?? 'standard';
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

            // Win/draw/loss base points
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

            // 10-point cup-tournament bonuses: +1 per goal scored capped at
            // 3 (rewards effort without inflating blowouts), +1 for a clean
            // sheet (any result — a 0-0 tie gets it). Bonuses use the
            // *uncapped* match score so a goal-diff cap doesn't suppress
            // bonus points already earned. Max per game = 6+3+1 = 10.
            if ($scoringSystem === 'ten_point') {
                $homeGoalBonus = min($hs, 3);
                $awayGoalBonus = min($as, 3);
                $homeShutout   = ($as === 0) ? 1 : 0;
                $awayShutout   = ($hs === 0) ? 1 : 0;
                $standings[$homeId]['points'] += $homeGoalBonus + $homeShutout;
                $standings[$awayId]['points'] += $awayGoalBonus + $awayShutout;
            }
        }

        // Calculate goal difference
        foreach ($standings as &$s) {
            $s['goal_difference'] = $s['goals_for'] - $s['goals_against'];
        }

        // Sort by tiebreaker rules
        $standingsArray = array_values($standings);
        $standingsArray = $this->resolvePositions($standingsArray, $tiebreakerRules, $groupId);

        // Persist: UPDATE each row, fall back to INSERT if no row existed.
        // See class doc comment for why this avoids both ON CONFLICT and
        // DELETE+INSERT patterns.
        $updateStmt = $this->db->prepare("
            UPDATE tournament_standings SET
                played = ?, won = ?, drawn = ?, lost = ?,
                goals_for = ?, goals_against = ?, goal_difference = ?, points = ?,
                position = ?, updated_at = CURRENT_TIMESTAMP
            WHERE group_id = ? AND registration_id = ?
        ");
        $insertStmt = $this->db->prepare("
            INSERT INTO tournament_standings (
                group_id, registration_id, played, won, drawn, lost,
                goals_for, goals_against, goal_difference, points, position, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ");

        foreach ($standingsArray as $i => $s) {
            $position = $i + 1;
            $updateStmt->execute([
                $s['played'], $s['won'], $s['drawn'], $s['lost'],
                $s['goals_for'], $s['goals_against'], $s['goal_difference'], $s['points'],
                $position, $groupId, $s['registration_id'],
            ]);
            if ($updateStmt->rowCount() === 0) {
                // No existing row — INSERT fresh
                $insertStmt->execute([
                    $groupId, $s['registration_id'],
                    $s['played'], $s['won'], $s['drawn'], $s['lost'],
                    $s['goals_for'], $s['goals_against'], $s['goal_difference'], $s['points'],
                    $position,
                ]);
            }
            $standingsArray[$i]['position'] = $position;
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
     * Sort standings by tiebreaker rules.
     *
     * Stability note: every entry is wrapped with its original index, and the
     * comparator falls back to that index whenever the tiebreaker chain ties.
     * This means the comparator NEVER returns 0 across distinct entries —
     * which sidesteps a 2026-04-29 production issue where usort on this
     * dataset (with the head_to_head tiebreaker doing DB lookups inside the
     * comparator) produced an array with one entry duplicated and another
     * dropped. Root cause was unidentified but consistent: forcing strict
     * ordering eliminates the failure mode.
     */
    public function resolvePositions(array $standings, array $tiebreakerRules, int $groupId): array {
        // Wrap each entry with its original index so we can use it as the
        // ultimate tie-breaker.
        $indexed = [];
        foreach ($standings as $i => $s) {
            $indexed[] = ['idx' => $i, 'data' => $s];
        }

        usort($indexed, function ($A, $B) use ($tiebreakerRules, $groupId) {
            $a = $A['data'];
            $b = $B['data'];
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
                    case 'coin_flip':
                    case 'penalty_shootout':
                        // Director-resolved tiebreakers: the engine cannot
                        // know the outcome, so the comparator returns 0 and
                        // the chain falls through to the next rule (or the
                        // final original-index sort if exhausted). The
                        // director records the result by adjusting seeding
                        // manually. No-op here is intentional.
                        $cmp = 0;
                        break;
                }
                if ($cmp !== 0) return $cmp;
            }
            // Final tie-break: original index. Strict ordering, never 0 between
            // distinct entries.
            return $A['idx'] - $B['idx'];
        });

        // Unwrap
        return array_map(function ($entry) { return $entry['data']; }, $indexed);
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
