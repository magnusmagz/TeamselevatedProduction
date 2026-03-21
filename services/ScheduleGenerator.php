<?php
/**
 * ScheduleGenerator Service
 *
 * Generates round-robin match schedules for tournament groups.
 * Uses the circle method (rotation algorithm) for pairing.
 */

class ScheduleGenerator {
    private $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Generate round-robin schedule for all groups in a division
     */
    public function generateRoundRobin(int $divisionId, array $options): array {
        $startTime = $options['start_time'] ?? date('Y-m-d 08:00:00');
        $intervalMinutes = (int)($options['game_interval_minutes'] ?? 70);
        $minRestMinutes = (int)($options['min_rest_minutes'] ?? 120);
        $fieldIds = $options['field_ids'] ?? [];

        // Get division info
        $divStmt = $this->db->prepare("SELECT * FROM tournament_divisions WHERE id = ?");
        $divStmt->execute([$divisionId]);
        $division = $divStmt->fetch(PDO::FETCH_ASSOC);

        if (!$division) {
            throw new \Exception('Division not found');
        }

        // Get groups with their teams
        $groupStmt = $this->db->prepare("
            SELECT tg.id, tg.name
            FROM tournament_groups tg
            WHERE tg.division_id = ?
            ORDER BY tg.sort_order
        ");
        $groupStmt->execute([$divisionId]);
        $groups = $groupStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($groups)) {
            throw new \Exception('No groups found. Create groups and assign teams first.');
        }

        // Delete existing unplayed group stage matches
        $this->db->prepare("
            DELETE FROM tournament_matches
            WHERE division_id = ? AND round = 'Group Stage' AND status = 'scheduled'
        ")->execute([$divisionId]);

        // Get current max match_number for the division
        $maxStmt = $this->db->prepare("SELECT COALESCE(MAX(match_number), 0) AS max_num FROM tournament_matches WHERE division_id = ?");
        $maxStmt->execute([$divisionId]);
        $matchNumber = (int)$maxStmt->fetch(PDO::FETCH_ASSOC)['max_num'];

        $allMatches = [];

        foreach ($groups as $group) {
            // Get teams in this group
            $teamStmt = $this->db->prepare("
                SELECT tr.id AS registration_id
                FROM tournament_registrations tr
                WHERE tr.group_id = ? AND tr.status = 'accepted'
                ORDER BY tr.seed NULLS LAST, tr.id
            ");
            $teamStmt->execute([(int)$group['id']]);
            $teams = array_column($teamStmt->fetchAll(PDO::FETCH_ASSOC), 'registration_id');

            if (count($teams) < 2) continue;

            $pairings = $this->getCircleMethodPairings($teams);

            foreach ($pairings as $pair) {
                $matchNumber++;
                $allMatches[] = [
                    'division_id' => $divisionId,
                    'group_id' => (int)$group['id'],
                    'round' => 'Group Stage',
                    'match_number' => $matchNumber,
                    'home_registration_id' => (int)$pair[0],
                    'away_registration_id' => (int)$pair[1],
                ];
            }
        }

        // Assign times and fields
        $scheduled = $this->assignTimesAndFields($allMatches, $fieldIds, $startTime, $intervalMinutes, $minRestMinutes);

        // Insert into database
        $insertStmt = $this->db->prepare("
            INSERT INTO tournament_matches (
                division_id, group_id, round, match_number,
                home_registration_id, away_registration_id,
                field_id, scheduled_time, scheduled_end_time, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'scheduled')
            RETURNING id
        ");

        $createdMatches = [];
        foreach ($scheduled as $match) {
            $endTime = $match['scheduled_time']
                ? date('Y-m-d H:i:s', strtotime($match['scheduled_time']) + ($division['game_duration_minutes'] * 60))
                : null;

            $insertStmt->execute([
                $match['division_id'],
                $match['group_id'],
                $match['round'],
                $match['match_number'],
                $match['home_registration_id'],
                $match['away_registration_id'],
                $match['field_id'] ?? null,
                $match['scheduled_time'] ?? null,
                $endTime,
            ]);
            $result = $insertStmt->fetch(PDO::FETCH_ASSOC);
            $match['id'] = (int)$result['id'];
            $match['scheduled_end_time'] = $endTime;
            $match['status'] = 'scheduled';
            $createdMatches[] = $match;
        }

        // Initialize standings for all teams in groups
        $this->initializeStandings($divisionId);

        return $createdMatches;
    }

    /**
     * Circle method (rotation algorithm) for round-robin pairings
     * For N teams: N-1 rounds if even, N rounds if odd (with bye)
     */
    public function getCircleMethodPairings(array $teamIds): array {
        $teams = $teamIds;
        $n = count($teams);

        // Add a BYE if odd number of teams
        if ($n % 2 !== 0) {
            $teams[] = null; // BYE
            $n++;
        }

        $pairings = [];
        $rounds = $n - 1;

        for ($round = 0; $round < $rounds; $round++) {
            for ($i = 0; $i < $n / 2; $i++) {
                $home = $teams[$i];
                $away = $teams[$n - 1 - $i];

                // Skip BYE matches
                if ($home === null || $away === null) continue;

                $pairings[] = [$home, $away];
            }

            // Rotate: fix first element, rotate the rest
            $last = array_pop($teams);
            array_splice($teams, 1, 0, [$last]);
        }

        return $pairings;
    }

    /**
     * Assign match times and fields respecting rest constraints
     */
    public function assignTimesAndFields(array $matches, array $fieldIds, string $startTime, int $intervalMinutes, int $minRestMinutes): array {
        if (empty($fieldIds) || empty($matches)) {
            // No fields: assign times sequentially without fields
            $time = strtotime($startTime);
            foreach ($matches as &$match) {
                $match['scheduled_time'] = date('Y-m-d H:i:s', $time);
                $match['field_id'] = null;
                $time += $intervalMinutes * 60;
            }
            return $matches;
        }

        // Track when each team last played and when each field is free
        $teamLastGame = [];
        $fieldNextFree = array_fill_keys($fieldIds, strtotime($startTime));

        $scheduled = [];
        foreach ($matches as $match) {
            $homeId = $match['home_registration_id'];
            $awayId = $match['away_registration_id'];

            // Find earliest time respecting rest for both teams
            $earliestTeamTime = max(
                $teamLastGame[$homeId] ?? 0,
                $teamLastGame[$awayId] ?? 0
            );
            if ($earliestTeamTime > 0) {
                $earliestTeamTime += $minRestMinutes * 60;
            }

            // Find first available field at or after earliest team time
            $bestField = null;
            $bestTime = PHP_INT_MAX;

            foreach ($fieldIds as $fid) {
                $fieldTime = max($fieldNextFree[$fid], $earliestTeamTime, strtotime($startTime));
                if ($fieldTime < $bestTime) {
                    $bestTime = $fieldTime;
                    $bestField = $fid;
                }
            }

            $match['field_id'] = $bestField;
            $match['scheduled_time'] = date('Y-m-d H:i:s', $bestTime);

            // Update trackers
            $teamLastGame[$homeId] = $bestTime;
            $teamLastGame[$awayId] = $bestTime;
            $fieldNextFree[$bestField] = $bestTime + ($intervalMinutes * 60);

            $scheduled[] = $match;
        }

        return $scheduled;
    }

    /**
     * Initialize standings rows for all teams in groups
     */
    private function initializeStandings(int $divisionId): void {
        $this->db->prepare("
            INSERT INTO tournament_standings (group_id, registration_id)
            SELECT tr.group_id, tr.id
            FROM tournament_registrations tr
            WHERE tr.division_id = ? AND tr.group_id IS NOT NULL AND tr.status = 'accepted'
            ON CONFLICT (group_id, registration_id) DO NOTHING
        ")->execute([$divisionId]);
    }
}
