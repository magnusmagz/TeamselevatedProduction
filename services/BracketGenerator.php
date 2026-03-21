<?php
/**
 * BracketGenerator Service
 *
 * Generates knockout brackets and handles match progression.
 */

class BracketGenerator {
    private $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Generate knockout bracket for a division
     */
    public function generateBracket(int $divisionId, array $options): array {
        $includeThirdPlace = $options['include_third_place'] ?? false;
        $fieldIds = $options['field_ids'] ?? [];
        $startTime = $options['start_time'] ?? null;
        $intervalMinutes = (int)($options['game_interval_minutes'] ?? 90);

        // Get division info
        $divStmt = $this->db->prepare("SELECT * FROM tournament_divisions WHERE id = ?");
        $divStmt->execute([$divisionId]);
        $division = $divStmt->fetch(PDO::FETCH_ASSOC);
        if (!$division) throw new \Exception('Division not found');

        // Determine advancing teams based on format
        $format = $division['format'];
        $numTeams = 0;

        if ($format === 'single_elimination') {
            // Direct bracket from seeded registrations
            $teamStmt = $this->db->prepare("
                SELECT COUNT(*) AS cnt FROM tournament_registrations
                WHERE division_id = ? AND status = 'accepted'
            ");
            $teamStmt->execute([$divisionId]);
            $numTeams = (int)$teamStmt->fetch(PDO::FETCH_ASSOC)['cnt'];
        } else {
            // Group knockout: calculate from groups
            $groupStmt = $this->db->prepare("SELECT COUNT(*) AS cnt FROM tournament_groups WHERE division_id = ?");
            $groupStmt->execute([$divisionId]);
            $numGroups = (int)$groupStmt->fetch(PDO::FETCH_ASSOC)['cnt'];
            $advancing = (int)$division['teams_advancing_per_group'];
            $numTeams = $numGroups * $advancing;
        }

        if ($numTeams < 2) throw new \Exception('Need at least 2 teams for knockout bracket');

        // Delete existing scheduled knockout matches
        $this->db->prepare("
            DELETE FROM tournament_matches
            WHERE division_id = ? AND round != 'Group Stage' AND status = 'scheduled'
        ")->execute([$divisionId]);

        // Get next match number
        $maxStmt = $this->db->prepare("SELECT COALESCE(MAX(match_number), 0) AS max_num FROM tournament_matches WHERE division_id = ?");
        $maxStmt->execute([$divisionId]);
        $matchNumber = (int)$maxStmt->fetch(PDO::FETCH_ASSOC)['max_num'];

        // Build bracket structure
        $matches = $this->buildBracketStructure($numTeams, $includeThirdPlace, $divisionId, $matchNumber, $format);

        // Assign times and fields
        $time = $startTime ? strtotime($startTime) : null;
        $fieldIdx = 0;

        foreach ($matches as &$match) {
            if ($time) {
                $match['scheduled_time'] = date('Y-m-d H:i:s', $time);
                $match['scheduled_end_time'] = date('Y-m-d H:i:s', $time + ($division['game_duration_minutes'] * 60));
                if (!empty($fieldIds)) {
                    $match['field_id'] = $fieldIds[$fieldIdx % count($fieldIds)];
                    $fieldIdx++;
                }
                $time += $intervalMinutes * 60;
            }
        }

        // Insert matches
        $insertStmt = $this->db->prepare("
            INSERT INTO tournament_matches (
                division_id, group_id, round, match_number,
                home_registration_id, away_registration_id,
                home_placeholder, away_placeholder,
                home_source_match_id, away_source_match_id,
                home_source_type, away_source_type,
                field_id, scheduled_time, scheduled_end_time, status
            ) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'scheduled')
            RETURNING id
        ");

        $createdMatches = [];
        $matchIdMap = []; // temp_id -> real_id

        foreach ($matches as $match) {
            $insertStmt->execute([
                $divisionId,
                $match['round'],
                $match['match_number'],
                $match['home_registration_id'] ?? null,
                $match['away_registration_id'] ?? null,
                $match['home_placeholder'] ?? null,
                $match['away_placeholder'] ?? null,
                isset($match['home_source_match_idx']) ? ($matchIdMap[$match['home_source_match_idx']] ?? null) : null,
                isset($match['away_source_match_idx']) ? ($matchIdMap[$match['away_source_match_idx']] ?? null) : null,
                $match['home_source_type'] ?? null,
                $match['away_source_type'] ?? null,
                $match['field_id'] ?? null,
                $match['scheduled_time'] ?? null,
                $match['scheduled_end_time'] ?? null,
            ]);
            $result = $insertStmt->fetch(PDO::FETCH_ASSOC);
            $realId = (int)$result['id'];
            $matchIdMap[$match['temp_idx']] = $realId;
            $match['id'] = $realId;
            $createdMatches[] = $match;
        }

        // Update source match IDs now that we have real IDs
        foreach ($createdMatches as $match) {
            $homeSourceId = isset($match['home_source_match_idx']) ? ($matchIdMap[$match['home_source_match_idx']] ?? null) : null;
            $awaySourceId = isset($match['away_source_match_idx']) ? ($matchIdMap[$match['away_source_match_idx']] ?? null) : null;
            if ($homeSourceId || $awaySourceId) {
                $this->db->prepare("
                    UPDATE tournament_matches SET home_source_match_id = ?, away_source_match_id = ? WHERE id = ?
                ")->execute([$homeSourceId, $awaySourceId, $match['id']]);
            }
        }

        return $createdMatches;
    }

    /**
     * Build bracket match structure
     */
    public function buildBracketStructure(int $numTeams, bool $includeThirdPlace, int $divisionId, int $startMatchNum, string $format): array {
        // Find next power of 2
        $bracketSize = 1;
        while ($bracketSize < $numTeams) $bracketSize *= 2;

        $numRounds = (int)log($bracketSize, 2);
        $roundNames = $this->getRoundNames($numRounds);

        $matches = [];
        $tempIdx = 0;
        $matchNumber = $startMatchNum;

        // First round matches
        $firstRoundMatches = $bracketSize / 2;
        $firstRoundIdxs = [];

        for ($i = 0; $i < $firstRoundMatches; $i++) {
            $matchNumber++;
            $homeSeed = $i + 1;
            $awaySeed = $bracketSize - $i;

            // Generate placeholders based on format
            if ($format === 'single_elimination') {
                $homePlaceholder = "Seed $homeSeed";
                $awayPlaceholder = $awaySeed <= $numTeams ? "Seed $awaySeed" : null;
            } else {
                // Group knockout: map positions
                $homePlaceholder = $this->getGroupPositionLabel($homeSeed, $divisionId);
                $awayPlaceholder = $awaySeed <= $numTeams ? $this->getGroupPositionLabel($awaySeed, $divisionId) : null;
            }

            // Bye: if awaySeed > numTeams, this is a bye
            $isBye = $awaySeed > $numTeams;

            $matches[] = [
                'temp_idx' => $tempIdx,
                'round' => $roundNames[0],
                'match_number' => $matchNumber,
                'home_registration_id' => null,
                'away_registration_id' => null,
                'home_placeholder' => $homePlaceholder,
                'away_placeholder' => $isBye ? 'BYE' : $awayPlaceholder,
                'home_source_type' => 'group_position',
                'away_source_type' => $isBye ? null : 'group_position',
                'field_id' => null,
                'scheduled_time' => null,
                'scheduled_end_time' => null,
            ];
            $firstRoundIdxs[] = $tempIdx;
            $tempIdx++;
        }

        // Subsequent rounds
        $prevRoundIdxs = $firstRoundIdxs;
        for ($round = 1; $round < $numRounds; $round++) {
            $roundIdxs = [];
            $numMatches = count($prevRoundIdxs) / 2;

            for ($i = 0; $i < $numMatches; $i++) {
                $matchNumber++;
                $sourceA = $prevRoundIdxs[$i * 2];
                $sourceB = $prevRoundIdxs[$i * 2 + 1];

                $matches[] = [
                    'temp_idx' => $tempIdx,
                    'round' => $roundNames[$round],
                    'match_number' => $matchNumber,
                    'home_registration_id' => null,
                    'away_registration_id' => null,
                    'home_placeholder' => "Winner Match " . $matches[$sourceA]['match_number'],
                    'away_placeholder' => "Winner Match " . $matches[$sourceB]['match_number'],
                    'home_source_match_idx' => $sourceA,
                    'away_source_match_idx' => $sourceB,
                    'home_source_type' => 'winner',
                    'away_source_type' => 'winner',
                    'field_id' => null,
                    'scheduled_time' => null,
                    'scheduled_end_time' => null,
                ];
                $roundIdxs[] = $tempIdx;
                $tempIdx++;
            }
            $prevRoundIdxs = $roundIdxs;
        }

        // 3rd place match
        if ($includeThirdPlace && $numRounds >= 2) {
            $matchNumber++;
            $sfIdxs = array_slice($matches, -3, 2); // Last 2 before final are semifinals
            $sfIdx1 = count($matches) - 3;
            $sfIdx2 = count($matches) - 2;

            $matches[] = [
                'temp_idx' => $tempIdx,
                'round' => '3rd Place',
                'match_number' => $matchNumber,
                'home_registration_id' => null,
                'away_registration_id' => null,
                'home_placeholder' => "Loser Match " . $matches[$sfIdx1]['match_number'],
                'away_placeholder' => "Loser Match " . $matches[$sfIdx2]['match_number'],
                'home_source_match_idx' => $sfIdx1,
                'away_source_match_idx' => $sfIdx2,
                'home_source_type' => 'loser',
                'away_source_type' => 'loser',
                'field_id' => null,
                'scheduled_time' => null,
                'scheduled_end_time' => null,
            ];
        }

        return $matches;
    }

    /**
     * Get group position label (e.g., "1st Group A", "2nd Group B")
     */
    private function getGroupPositionLabel(int $overallSeed, int $divisionId): string {
        $groupStmt = $this->db->prepare("SELECT id, name FROM tournament_groups WHERE division_id = ? ORDER BY sort_order");
        $groupStmt->execute([$divisionId]);
        $groups = $groupStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($groups)) return "Seed $overallSeed";

        $divStmt = $this->db->prepare("SELECT teams_advancing_per_group FROM tournament_divisions WHERE id = ?");
        $divStmt->execute([$divisionId]);
        $advancing = (int)$divStmt->fetch(PDO::FETCH_ASSOC)['teams_advancing_per_group'];

        $groupIdx = ($overallSeed - 1) % count($groups);
        $position = intdiv($overallSeed - 1, count($groups)) + 1;

        $ordinal = $position === 1 ? '1st' : ($position === 2 ? '2nd' : ($position === 3 ? '3rd' : "{$position}th"));
        return "$ordinal {$groups[$groupIdx]['name']}";
    }

    /**
     * Slot group winners into knockout bracket
     */
    public function slotGroupWinners(int $divisionId): int {
        // Get standings with positions for each group
        $stmt = $this->db->prepare("
            SELECT ts.registration_id, ts.position, tg.name AS group_name, tg.sort_order AS group_order
            FROM tournament_standings ts
            JOIN tournament_groups tg ON tg.id = ts.group_id
            WHERE tg.division_id = ?
            ORDER BY tg.sort_order, ts.position
        ");
        $stmt->execute([$divisionId]);
        $standings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get knockout matches with group_position placeholders
        $matchStmt = $this->db->prepare("
            SELECT id, home_placeholder, away_placeholder, home_source_type, away_source_type
            FROM tournament_matches
            WHERE division_id = ? AND round != 'Group Stage' AND status = 'scheduled'
            AND (home_source_type = 'group_position' OR away_source_type = 'group_position')
        ");
        $matchStmt->execute([$divisionId]);
        $matches = $matchStmt->fetchAll(PDO::FETCH_ASSOC);

        $slotted = 0;
        foreach ($matches as $match) {
            $updates = [];
            $params = [];

            if ($match['home_source_type'] === 'group_position' && $match['home_placeholder']) {
                $regId = $this->findTeamByPlaceholder($match['home_placeholder'], $standings);
                if ($regId) { $updates[] = "home_registration_id = ?"; $params[] = $regId; $slotted++; }
            }
            if ($match['away_source_type'] === 'group_position' && $match['away_placeholder'] && $match['away_placeholder'] !== 'BYE') {
                $regId = $this->findTeamByPlaceholder($match['away_placeholder'], $standings);
                if ($regId) { $updates[] = "away_registration_id = ?"; $params[] = $regId; $slotted++; }
            }

            if (!empty($updates)) {
                $params[] = (int)$match['id'];
                $this->db->prepare("UPDATE tournament_matches SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params);
            }
        }

        return $slotted;
    }

    private function findTeamByPlaceholder(string $placeholder, array $standings): ?int {
        // Parse "1st Group A" -> position=1, group_name="Group A"
        if (preg_match('/^(\d+)(?:st|nd|rd|th)\s+(.+)$/', $placeholder, $m)) {
            $position = (int)$m[1];
            $groupName = $m[2];

            foreach ($standings as $s) {
                if ((int)$s['position'] === $position && $s['group_name'] === $groupName) {
                    return (int)$s['registration_id'];
                }
            }
        }
        return null;
    }

    /**
     * Advance winner of a knockout match to the next round
     */
    public function advanceWinner(int $matchId): ?array {
        $match = $this->db->prepare("SELECT * FROM tournament_matches WHERE id = ?")->execute([$matchId]);
        $match = $this->db->prepare("SELECT * FROM tournament_matches WHERE id = ?");
        $match->execute([$matchId]);
        $match = $match->fetch(PDO::FETCH_ASSOC);
        if (!$match || !$match['winner_registration_id']) return null;

        $winnerId = (int)$match['winner_registration_id'];
        $loserId = $winnerId === (int)$match['home_registration_id']
            ? (int)$match['away_registration_id']
            : (int)$match['home_registration_id'];

        $advanced = [];

        // Find downstream matches that source from this match
        $nextStmt = $this->db->prepare("
            SELECT id, home_source_match_id, away_source_match_id, home_source_type, away_source_type
            FROM tournament_matches
            WHERE (home_source_match_id = ? OR away_source_match_id = ?)
            AND status = 'scheduled'
        ");
        $nextStmt->execute([$matchId, $matchId]);
        $nextMatches = $nextStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($nextMatches as $next) {
            $updates = [];
            $params = [];

            if ((int)($next['home_source_match_id'] ?? 0) === $matchId) {
                $teamId = $next['home_source_type'] === 'winner' ? $winnerId : $loserId;
                $updates[] = "home_registration_id = ?";
                $params[] = $teamId;
            }
            if ((int)($next['away_source_match_id'] ?? 0) === $matchId) {
                $teamId = $next['away_source_type'] === 'winner' ? $winnerId : $loserId;
                $updates[] = "away_registration_id = ?";
                $params[] = $teamId;
            }

            if (!empty($updates)) {
                $params[] = (int)$next['id'];
                $this->db->prepare("UPDATE tournament_matches SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params);
                $advanced[] = (int)$next['id'];
            }
        }

        return $advanced;
    }

    private function getRoundNames(int $numRounds): array {
        $names = [];
        for ($i = 0; $i < $numRounds; $i++) {
            $remaining = $numRounds - $i;
            if ($remaining === 1) $names[] = 'Final';
            elseif ($remaining === 2) $names[] = 'Semifinal';
            elseif ($remaining === 3) $names[] = 'Quarterfinal';
            else $names[] = 'Round of ' . pow(2, $remaining);
        }
        return $names;
    }
}
