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
     * Generate round-robin schedule for all groups in a division.
     *
     * Field selection cascade:
     *   1. options['field_ids']  — explicit caller override
     *   2. tournament.venue_id   — all active fields at the tournament's venue
     *   3. neither               — throw "no fields configured"
     *
     * Time anchoring:
     *   1. options['start_time'] — explicit caller override
     *   2. tournament.start_date + tournament.daily_start_time
     *
     * Daily window: matches scheduled past tournament.daily_end_time roll over
     * to the next day at daily_start_time. Throws if scheduling would extend
     * past tournament.end_date.
     */
    public function generateRoundRobin(int $divisionId, array $options): array {
        $intervalMinutes = (int)($options['game_interval_minutes'] ?? 70);
        $minRestMinutes  = (int)($options['min_rest_minutes'] ?? 120);
        $fieldIds        = $options['field_ids'] ?? [];

        // Get division info + parent tournament's venue/window/dates
        $divStmt = $this->db->prepare("
            SELECT td.*,
                   t.id               AS tournament_id,
                   t.venue_id         AS tournament_venue_id,
                   t.start_date       AS tournament_start_date,
                   t.end_date         AS tournament_end_date,
                   t.daily_start_time AS tournament_daily_start,
                   t.daily_end_time   AS tournament_daily_end
            FROM tournament_divisions td
            JOIN tournaments t ON t.id = td.tournament_id
            WHERE td.id = ?
        ");
        $divStmt->execute([$divisionId]);
        $division = $divStmt->fetch(PDO::FETCH_ASSOC);

        if (!$division) {
            throw new \Exception('Division not found');
        }

        // Resolve field set: caller override → tournament's venue → error.
        if (empty($fieldIds) && !empty($division['tournament_venue_id'])) {
            $venueFieldStmt = $this->db->prepare("
                SELECT id FROM fields
                WHERE venue_id = ? AND active = true
                ORDER BY name
            ");
            $venueFieldStmt->execute([(int)$division['tournament_venue_id']]);
            $fieldIds = array_column($venueFieldStmt->fetchAll(PDO::FETCH_ASSOC), 'id');
        }
        if (empty($fieldIds)) {
            throw new \Exception(
                'No fields configured for this tournament. ' .
                'Set the tournament venue (in Tournament Setup) or pass explicit field_ids.'
            );
        }

        // Resolve schedule anchor: caller override → tournament start_date + daily_start_time.
        $startTime = $options['start_time'] ?? null;
        if (!$startTime) {
            $startTime = trim($division['tournament_start_date'] . ' ' . $division['tournament_daily_start']);
        }

        // Daily window — used by assignTimesAndFields to roll past midnight.
        $dailyStart = $division['tournament_daily_start'] ?? '08:00:00';
        $dailyEnd   = $division['tournament_daily_end']   ?? '20:00:00';
        $tournamentEndDate = $division['tournament_end_date'] ?? null;

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

        // Cross-division collision data: other divisions in the same
        // tournament may already have matches scheduled on these same
        // fields. Without this, two divisions can both land on the same
        // field at the same time. We exclude the division we're
        // (re)generating since its own matches were just deleted above.
        $occupiedByField = $this->loadOccupiedSlots(
            (int)$division['tournament_id'],
            $divisionId,
            $fieldIds
        );

        // Assign times and fields (respects daily window + tournament end date)
        $gameDurationMinutes = (int)$division['game_duration_minutes'];
        $scheduled = $this->assignTimesAndFields(
            $allMatches, $fieldIds, $startTime,
            $intervalMinutes, $minRestMinutes,
            $dailyStart, $dailyEnd, $tournamentEndDate, $gameDurationMinutes,
            $occupiedByField
        );

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
     * Assign match times and fields respecting:
     *   - rest constraints between matches for the same team
     *   - field non-overlap
     *   - daily window (rolls to next day at $dailyStart if past $dailyEnd)
     *   - tournament end date (errors if matches would overflow)
     *
     * Backwards-compatible signature: caller may omit window args; defaults
     * yield the legacy "schedule continuously" behavior. Note: callers are
     * encouraged to pass the new args — generateRoundRobin always does so now.
     */
    public function assignTimesAndFields(
        array $matches,
        array $fieldIds,
        string $startTime,
        int $intervalMinutes,
        int $minRestMinutes,
        ?string $dailyStart = null,
        ?string $dailyEnd = null,
        ?string $tournamentEndDate = null,
        int $gameDurationMinutes = 0,
        array $occupiedByField = []
    ): array {
        if (empty($matches)) return [];

        if (empty($fieldIds)) {
            throw new \Exception(
                'assignTimesAndFields called with no field_ids — caller must resolve fields before scheduling.'
            );
        }

        $anchorTs = strtotime($startTime);
        $teamLastGame = [];
        $fieldNextFree = array_fill_keys($fieldIds, $anchorTs);

        // Hard cap so we don't infinite-loop in pathological cases.
        $endCap = $tournamentEndDate ? strtotime($tournamentEndDate . ' 23:59:59') : null;
        $intervalSec = $intervalMinutes * 60;

        $scheduled = [];
        foreach ($matches as $match) {
            $homeId = $match['home_registration_id'];
            $awayId = $match['away_registration_id'];

            // Earliest start respecting per-team rest
            $earliestTeamTime = max(
                $teamLastGame[$homeId] ?? 0,
                $teamLastGame[$awayId] ?? 0
            );
            if ($earliestTeamTime > 0) {
                $earliestTeamTime += $minRestMinutes * 60;
            }

            // Pick the field that frees up earliest after $earliestTeamTime,
            // then roll the chosen time into the daily window AND skip past
            // any cross-division occupied slots on that field.
            $bestField = null;
            $bestTime  = PHP_INT_MAX;

            foreach ($fieldIds as $fid) {
                $candidate = max($fieldNextFree[$fid], $earliestTeamTime, $anchorTs);
                $candidate = $this->resolveCandidateTime(
                    $candidate, $intervalSec,
                    $occupiedByField[$fid] ?? [],
                    $dailyStart, $dailyEnd, $gameDurationMinutes,
                    $endCap
                );
                if ($candidate !== null && $candidate < $bestTime) {
                    $bestTime  = $candidate;
                    $bestField = $fid;
                }
            }

            // Tournament end-date guard
            if ($bestField === null || ($endCap !== null && $bestTime > $endCap)) {
                throw new \Exception(
                    'Schedule would extend past tournament end date. ' .
                    'Reduce match count, extend the tournament, or widen the daily window.'
                );
            }

            $match['field_id']        = $bestField;
            $match['scheduled_time']  = date('Y-m-d H:i:s', $bestTime);

            $teamLastGame[$homeId]    = $bestTime;
            $teamLastGame[$awayId]    = $bestTime;
            $fieldNextFree[$bestField] = $bestTime + $intervalSec;

            $scheduled[] = $match;
        }

        return $scheduled;
    }

    /**
     * Find the earliest time at or after $candidate where a match of
     * length $intervalSec can fit on a field, respecting both:
     *   - cross-division occupied intervals (other divisions' bookings
     *     on this same field)
     *   - the daily window (matches that wouldn't end before dailyEnd
     *     roll to the next day)
     * Returns null if no valid slot can be found before $endCap.
     *
     * Iterates because either constraint can push the candidate into a
     * region where the other applies — e.g., skipping an occupied slot
     * may land past dailyEnd, the daily-window roll may put us back
     * inside another occupied slot. Loop bails after a generous bound
     * to keep pathological inputs from hanging.
     */
    private function resolveCandidateTime(
        int $candidate,
        int $intervalSec,
        array $occupied,
        ?string $dailyStart,
        ?string $dailyEnd,
        int $gameDurationMinutes,
        ?int $endCap
    ): ?int {
        for ($i = 0; $i < 64; $i++) {
            // First, push out of the daily window if needed.
            $rolled = $this->rollIntoDailyWindow(
                $candidate, $dailyStart, $dailyEnd, $gameDurationMinutes
            );
            // Then, push past any occupied interval that overlaps.
            $skipped = $this->skipOccupied($rolled, $intervalSec, $occupied);
            if ($skipped === $rolled) {
                return $rolled;
            }
            $candidate = $skipped;
            if ($endCap !== null && $candidate > $endCap) return null;
        }
        return null;
    }

    /**
     * If the proposed [start, start+intervalSec) overlaps any occupied
     * interval, return the end of the colliding interval (so the caller
     * tries again from there). Otherwise return $start unchanged.
     *
     * Treats `start_ts >= occupied.end` and `start_ts + intervalSec
     * <= occupied.start` as non-overlapping — i.e., touching is OK.
     */
    private function skipOccupied(int $startTs, int $intervalSec, array $occupied): int {
        $endTs = $startTs + $intervalSec;
        foreach ($occupied as [$oStart, $oEnd]) {
            if ($endTs > $oStart && $startTs < $oEnd) {
                return $oEnd;
            }
        }
        return $startTs;
    }

    /**
     * Load existing match bookings on the given fields from OTHER
     * divisions of the given tournament. Returns a map keyed by
     * field_id with sorted [start_ts, end_ts] tuples.
     *
     * Excludes cancelled matches (their slots are free) and matches
     * with no scheduled_time. Falls back to scheduled_time + the match
     * duration when scheduled_end_time is missing (legacy rows).
     */
    private function loadOccupiedSlots(int $tournamentId, int $excludeDivisionId, array $fieldIds): array {
        if (empty($fieldIds)) return [];
        $placeholders = implode(',', array_fill(0, count($fieldIds), '?'));
        $sql = "
            SELECT m.field_id,
                   m.scheduled_time,
                   COALESCE(m.scheduled_end_time,
                            m.scheduled_time + INTERVAL '1 minute' * d.game_duration_minutes) AS end_ts
            FROM tournament_matches m
            JOIN tournament_divisions d ON d.id = m.division_id
            WHERE d.tournament_id = ?
              AND m.division_id != ?
              AND m.status != 'cancelled'
              AND m.field_id IN ($placeholders)
              AND m.scheduled_time IS NOT NULL
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_merge([$tournamentId, $excludeDivisionId], $fieldIds));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $byField = [];
        foreach ($rows as $r) {
            $fid = (int)$r['field_id'];
            $byField[$fid][] = [
                strtotime($r['scheduled_time']),
                strtotime($r['end_ts']),
            ];
        }
        // Sort each field's intervals so skipOccupied's linear scan is
        // deterministic, even if it doesn't strictly need sorted input.
        foreach ($byField as $fid => $intervals) {
            usort($byField[$fid], function ($a, $b) { return $a[0] - $b[0]; });
        }
        return $byField;
    }

    /**
     * If the given timestamp falls outside [dailyStart, dailyEnd] OR if a
     * match starting at this time wouldn't finish before dailyEnd, advance
     * to the next day's dailyStart. Returns unchanged when window args are
     * null (legacy continuous-scheduling mode).
     */
    private function rollIntoDailyWindow(
        int $ts,
        ?string $dailyStart,
        ?string $dailyEnd,
        int $gameDurationMinutes
    ): int {
        if (!$dailyStart || !$dailyEnd) return $ts;

        $dateStr      = date('Y-m-d', $ts);
        $startOfWindow = strtotime("$dateStr $dailyStart");
        $endOfWindow   = strtotime("$dateStr $dailyEnd");
        $matchEnd      = $ts + ($gameDurationMinutes * 60);

        // Before today's window opens
        if ($ts < $startOfWindow) {
            return $startOfWindow;
        }
        // Match would end after today's window closes — roll to tomorrow
        if ($matchEnd > $endOfWindow) {
            $tomorrow = date('Y-m-d', $ts + 86400);
            return strtotime("$tomorrow $dailyStart");
        }
        return $ts;
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
