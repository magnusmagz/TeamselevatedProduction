<?php
/**
 * Tournament Public API Gateway
 * Unauthenticated endpoints for public tournament views
 *
 * Actions: tournament-by-slug, public-schedule, public-standings, public-bracket
 */

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// No authentication required — these are public endpoints
require_once __DIR__ . '/../config/database.php';

$database = Database::getInstance();
$db = $database->getConnection();

$action = $_GET['action'] ?? '';

// Only allow publicly visible statuses
$publicStatuses = ['registration_open', 'registration_closed', 'scheduling', 'in_progress', 'weather_delay', 'completed'];

try {
    switch ($action) {

        case 'tournament-by-slug':
            // GET ?action=tournament-by-slug&slug={slug}
            $slug = $_GET['slug'] ?? '';
            if (!$slug) {
                http_response_code(400);
                echo json_encode(['error' => 'slug parameter is required']);
                exit();
            }

            $placeholders = implode(',', array_fill(0, count($publicStatuses), '?'));
            $stmt = $db->prepare("
                SELECT t.id, t.name, t.description, t.sport, t.start_date, t.end_date,
                       t.location_name, t.location_address, t.location_city, t.location_state,
                       t.location_zip, t.location_coordinates, t.status,
                       t.contact_name, t.contact_email, t.contact_phone,
                       t.rules_document_url, t.faq_markdown,
                       t.governing_body, t.sanction_number, t.state_association,
                       t.host_name,
                       t.venue_id,
                       v.name    AS venue_name,
                       v.address AS venue_address,
                       v.city    AS venue_city,
                       v.state   AS venue_state,
                       v.zip_code AS venue_zip,
                       cp.name AS club_name, cp.logo_url AS club_logo_url,
                       cp.primary_color, cp.secondary_color
                FROM tournaments t
                JOIN club_profile cp ON cp.id = t.club_id
                LEFT JOIN venues v ON v.id = t.venue_id
                WHERE t.public_url_slug = ?
                AND t.status IN ($placeholders)
            ");
            $params = array_merge([$slug], $publicStatuses);
            $stmt->execute($params);
            $tournament = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$tournament) {
                http_response_code(404);
                echo json_encode(['error' => 'Tournament not found']);
                exit();
            }

            // Fetch divisions
            $divStmt = $db->prepare("
                SELECT id, name, age_group, gender, format, sport_rule_notes
                FROM tournament_divisions
                WHERE tournament_id = ?
                ORDER BY sort_order, age_group
            ");
            $divStmt->execute([$tournament['id']]);
            $tournament['divisions'] = $divStmt->fetchAll(PDO::FETCH_ASSOC);

            // Fetch active club sponsors so the public page can render a
            // sponsor strip. Sponsors are club-scoped (cross-tournament).
            $sponsorStmt = $db->prepare("
                SELECT id, name, website, logo_data
                FROM sponsors
                WHERE club_id = (SELECT club_id FROM tournaments WHERE id = ?)
                  AND is_active = true
                  AND deleted_at IS NULL
                ORDER BY display_order, name
            ");
            $sponsorStmt->execute([$tournament['id']]);
            $tournament['sponsors'] = $sponsorStmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode($tournament);
            break;

        case 'public-schedule':
            // GET ?action=public-schedule&tournament_id={id}&division_id={id}&date={date}&team_name={name}&field_id={id}
            $tournamentId = $_GET['tournament_id'] ?? null;
            if (!$tournamentId) { http_response_code(400); echo json_encode(['error' => 'tournament_id required']); exit(); }

            // Verify tournament is public
            $placeholders2 = implode(',', array_fill(0, count($publicStatuses), '?'));
            $tCheck = $db->prepare("SELECT id FROM tournaments WHERE id = ? AND status IN ($placeholders2)");
            $tCheck->execute(array_merge([(int)$tournamentId], $publicStatuses));
            if (!$tCheck->fetch()) { http_response_code(404); echo json_encode(['error' => 'Tournament not found']); exit(); }

            $sql = "SELECT m.match_number, m.round, m.home_score, m.away_score,
                           m.home_penalty_score, m.away_penalty_score, m.status, m.scheduled_time,
                           COALESCE(hr.team_name_override, ht.name) AS home_team,
                           COALESCE(ar.team_name_override, at2.name) AS away_team,
                           m.home_placeholder, m.away_placeholder,
                           f.name AS field_name, tg.name AS group_name
                    FROM tournament_matches m
                    LEFT JOIN tournament_registrations hr ON hr.id = m.home_registration_id
                    LEFT JOIN teams ht ON ht.id = hr.team_id
                    LEFT JOIN tournament_registrations ar ON ar.id = m.away_registration_id
                    LEFT JOIN teams at2 ON at2.id = ar.team_id
                    LEFT JOIN fields f ON f.id = m.field_id
                    LEFT JOIN tournament_groups tg ON tg.id = m.group_id
                    JOIN tournament_divisions td ON td.id = m.division_id
                    WHERE td.tournament_id = ?";
            $params = [(int)$tournamentId];

            if (!empty($_GET['division_id'])) { $sql .= " AND m.division_id = ?"; $params[] = (int)$_GET['division_id']; }
            if (!empty($_GET['date'])) { $sql .= " AND DATE(m.scheduled_time) = ?"; $params[] = $_GET['date']; }
            if (!empty($_GET['field_id'])) { $sql .= " AND m.field_id = ?"; $params[] = (int)$_GET['field_id']; }
            if (!empty($_GET['team_name'])) {
                $sql .= " AND (COALESCE(hr.team_name_override, ht.name) ILIKE ? OR COALESCE(ar.team_name_override, at2.name) ILIKE ?)";
                $term = '%' . $_GET['team_name'] . '%';
                $params[] = $term; $params[] = $term;
            }

            $sql .= " ORDER BY m.scheduled_time, m.match_number";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Build filter options
            $divStmt = $db->prepare("SELECT id, name FROM tournament_divisions WHERE tournament_id = ? ORDER BY sort_order");
            $divStmt->execute([(int)$tournamentId]);
            $divisions = $divStmt->fetchAll(PDO::FETCH_ASSOC);

            $dateStmt = $db->prepare("SELECT DISTINCT DATE(scheduled_time) AS d FROM tournament_matches m JOIN tournament_divisions td ON td.id = m.division_id WHERE td.tournament_id = ? AND m.scheduled_time IS NOT NULL ORDER BY d");
            $dateStmt->execute([(int)$tournamentId]);
            $dates = array_column($dateStmt->fetchAll(PDO::FETCH_ASSOC), 'd');

            echo json_encode([
                'matches' => $matches,
                'filters' => [
                    'divisions' => $divisions,
                    'dates' => $dates,
                ],
            ]);
            break;

        case 'public-standings':
            // GET ?action=public-standings&division_id={id}
            $divisionId = $_GET['division_id'] ?? null;
            if (!$divisionId) { http_response_code(400); echo json_encode(['error' => 'division_id required']); exit(); }

            // Verify division is in a public tournament; pull tiebreaker rules
            // and advancing count so the standings UI can render the chain caption.
            $divCheck = $db->prepare("
                SELECT td.name AS division_name, td.tiebreaker_rules, td.teams_advancing_per_group, t.status
                FROM tournament_divisions td
                JOIN tournaments t ON t.id = td.tournament_id
                WHERE td.id = ?
            ");
            $divCheck->execute([(int)$divisionId]);
            $divData = $divCheck->fetch(PDO::FETCH_ASSOC);
            if (!$divData || !in_array($divData['status'], $publicStatuses)) {
                http_response_code(404);
                echo json_encode(['error' => 'Division not found']);
                exit();
            }

            $groupStmt = $db->prepare("SELECT id, name FROM tournament_groups WHERE division_id = ? ORDER BY sort_order");
            $groupStmt->execute([(int)$divisionId]);
            $groups = $groupStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($groups as &$group) {
                $standingsStmt = $db->prepare("
                    SELECT ts.position, ts.played, ts.won, ts.drawn, ts.lost,
                           ts.goals_for, ts.goals_against, ts.goal_difference, ts.points,
                           COALESCE(tr.team_name_override, t.name) AS team_name
                    FROM tournament_standings ts
                    JOIN tournament_registrations tr ON tr.id = ts.registration_id
                    JOIN teams t ON t.id = tr.team_id
                    WHERE ts.group_id = ?
                    ORDER BY ts.position
                ");
                $standingsStmt->execute([(int)$group['id']]);
                $group['standings'] = $standingsStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // Decode tiebreaker_rules JSONB for client consumption
            $rules = $divData['tiebreaker_rules'];
            if (is_string($rules)) $rules = json_decode($rules, true);

            echo json_encode([
                'division_name'             => $divData['division_name'],
                'tiebreaker_rules'          => $rules,
                'teams_advancing_per_group' => (int)$divData['teams_advancing_per_group'],
                'groups'                    => $groups,
            ]);
            break;

        case 'public-live-scoreboard':
            // GET ?action=public-live-scoreboard&slug={slug}
            // Returns matches grouped by field, each field tagged with:
            //   - live:    in-progress now (NOW within scheduled_time..scheduled_end_time AND status='scheduled')
            //   - upcoming: next scheduled match on that field after NOW
            //   - recent:  last completed match on that field
            // Fields with zero matches across the whole tournament are excluded.
            $slug = $_GET['slug'] ?? '';
            if (!$slug) { http_response_code(400); echo json_encode(['error' => 'slug required']); exit(); }

            $placeholders = implode(',', array_fill(0, count($publicStatuses), '?'));
            $tStmt = $db->prepare("
                SELECT t.id, t.name, t.start_date, t.end_date, t.status,
                       cp.name AS club_name, cp.logo_url AS club_logo_url,
                       cp.primary_color
                FROM tournaments t
                JOIN club_profile cp ON cp.id = t.club_id
                WHERE t.public_url_slug = ? AND t.status IN ($placeholders)
            ");
            $tStmt->execute(array_merge([$slug], $publicStatuses));
            $tournament = $tStmt->fetch(PDO::FETCH_ASSOC);
            if (!$tournament) { http_response_code(404); echo json_encode(['error' => 'Tournament not found']); exit(); }

            // All non-placeholder matches with a field assigned
            $stmt = $db->prepare("
                SELECT m.id, m.match_number, m.round, m.status,
                       m.scheduled_time, m.scheduled_end_time,
                       m.home_score, m.away_score,
                       m.home_penalty_score, m.away_penalty_score,
                       m.scored_at,
                       f.id   AS field_id,
                       f.name AS field_name,
                       d.name AS division_name,
                       g.name AS group_name,
                       COALESCE(NULLIF(rh.team_name_override, ''), th.name, m.home_placeholder, 'TBD') AS home_team,
                       COALESCE(NULLIF(ra.team_name_override, ''), ta.name, m.away_placeholder, 'TBD') AS away_team
                FROM tournament_matches m
                JOIN tournament_divisions d ON m.division_id = d.id
                JOIN fields f ON m.field_id = f.id
                LEFT JOIN tournament_groups g ON m.group_id = g.id
                LEFT JOIN tournament_registrations rh ON m.home_registration_id = rh.id
                LEFT JOIN teams th ON rh.team_id = th.id
                LEFT JOIN tournament_registrations ra ON m.away_registration_id = ra.id
                LEFT JOIN teams ta ON ra.team_id = ta.id
                WHERE d.tournament_id = ?
                ORDER BY f.name, m.scheduled_time
            ");
            $stmt->execute([(int)$tournament['id']]);
            $allMatches = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Group by field, derive live/upcoming/recent
            $now = time();
            $byField = [];
            foreach ($allMatches as $m) {
                $fid = $m['field_id'];
                if (!isset($byField[$fid])) {
                    $byField[$fid] = [
                        'field_id'   => (int)$fid,
                        'field_name' => $m['field_name'],
                        'live'       => null,
                        'upcoming'   => null,
                        'recent'     => null,
                    ];
                }

                $startTs = $m['scheduled_time']     ? strtotime($m['scheduled_time'])     : null;
                $endTs   = $m['scheduled_end_time'] ? strtotime($m['scheduled_end_time']) : null;

                $isLive = $m['status'] === 'scheduled'
                       && $startTs !== null && $endTs !== null
                       && $startTs <= $now && $now <= $endTs;
                $isCompleted = $m['status'] === 'completed';
                $isUpcoming = $m['status'] === 'scheduled' && $startTs !== null && $startTs > $now;

                if ($isLive && !$byField[$fid]['live']) {
                    $byField[$fid]['live'] = $m;
                }
                if ($isUpcoming && (!$byField[$fid]['upcoming']
                    || strtotime($m['scheduled_time']) < strtotime($byField[$fid]['upcoming']['scheduled_time']))) {
                    $byField[$fid]['upcoming'] = $m;
                }
                if ($isCompleted && (!$byField[$fid]['recent']
                    || ($m['scored_at'] && (!$byField[$fid]['recent']['scored_at']
                        || strtotime($m['scored_at']) > strtotime($byField[$fid]['recent']['scored_at']))))) {
                    $byField[$fid]['recent'] = $m;
                }
            }

            // Drop fields with no representative match in any of the three slots
            $fields = array_values(array_filter($byField, function ($f) {
                return $f['live'] || $f['upcoming'] || $f['recent'];
            }));

            // Sort fields: live first, then by name
            usort($fields, function ($a, $b) {
                $aLive = $a['live'] ? 0 : 1;
                $bLive = $b['live'] ? 0 : 1;
                if ($aLive !== $bLive) return $aLive - $bLive;
                return strnatcmp($a['field_name'], $b['field_name']);
            });

            echo json_encode([
                'tournament' => [
                    'id'              => (int)$tournament['id'],
                    'name'            => $tournament['name'],
                    'start_date'      => $tournament['start_date'],
                    'end_date'        => $tournament['end_date'],
                    'club_name'       => $tournament['club_name'],
                    'club_logo_url'   => $tournament['club_logo_url'],
                    'primary_color'   => $tournament['primary_color'],
                ],
                'server_time' => date('c', $now),
                'fields'      => $fields,
            ]);
            break;

        case 'public-bracket':
            // GET ?action=public-bracket&division_id={id}
            $divisionId = $_GET['division_id'] ?? null;
            if (!$divisionId) { http_response_code(400); echo json_encode(['error' => 'division_id required']); exit(); }

            $divCheck = $db->prepare("
                SELECT td.name AS division_name, t.status
                FROM tournament_divisions td JOIN tournaments t ON t.id = td.tournament_id WHERE td.id = ?
            ");
            $divCheck->execute([(int)$divisionId]);
            $divData = $divCheck->fetch(PDO::FETCH_ASSOC);
            if (!$divData || !in_array($divData['status'], $publicStatuses)) {
                http_response_code(404);
                echo json_encode(['error' => 'Division not found']);
                exit();
            }

            $matchStmt = $db->prepare("
                SELECT m.match_number, m.round, m.status,
                       m.home_score, m.away_score, m.home_penalty_score, m.away_penalty_score,
                       COALESCE(hr.team_name_override, ht.name) AS home_team,
                       COALESCE(ar.team_name_override, at2.name) AS away_team,
                       m.home_placeholder, m.away_placeholder,
                       m.winner_registration_id,
                       COALESCE(wr.team_name_override, wt.name) AS winner
                FROM tournament_matches m
                LEFT JOIN tournament_registrations hr ON hr.id = m.home_registration_id
                LEFT JOIN teams ht ON ht.id = hr.team_id
                LEFT JOIN tournament_registrations ar ON ar.id = m.away_registration_id
                LEFT JOIN teams at2 ON at2.id = ar.team_id
                LEFT JOIN tournament_registrations wr ON wr.id = m.winner_registration_id
                LEFT JOIN teams wt ON wt.id = wr.team_id
                WHERE m.division_id = ? AND m.round != 'Group Stage'
                ORDER BY m.match_number
            ");
            $matchStmt->execute([(int)$divisionId]);
            $matches = $matchStmt->fetchAll(PDO::FETCH_ASSOC);

            // Group by round
            $rounds = [];
            foreach ($matches as $m) {
                $rounds[$m['round']][] = $m;
            }

            $result = [];
            foreach ($rounds as $roundName => $roundMatches) {
                $result[] = ['name' => $roundName, 'matches' => $roundMatches];
            }

            echo json_encode(['division_name' => $divData['division_name'], 'rounds' => $result]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action: ' . $action]);
    }
} catch (Exception $e) {
    error_log("Tournament public gateway error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
