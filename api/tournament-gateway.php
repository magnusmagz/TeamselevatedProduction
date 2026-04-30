<?php
/**
 * Tournament API Gateway
 * Authenticated endpoints for tournament management
 *
 * Actions: list, get, create, update, delete, update-status,
 *          divisions-list, division-create, division-update, division-delete,
 *          sport-presets, registrations-list, registration-create,
 *          registration-update-status, registration-update-payment, registration-withdraw,
 *          groups-list, group-create, group-assign-teams, group-auto-assign,
 *          registration-seed, generate-group-schedule, matches-list, match-update,
 *          match-score, match-score-knockout, standings-get,
 *          generate-knockout-bracket, checkin-roster, checkin-player, checkin-status
 */

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../services/TournamentNotificationService.php';

// Require authentication on all tournament gateway endpoints
$auth = AuthMiddleware::requireAuth();

// Only club_admin and coach roles can access tournament management
$userId = $auth->getUserId();
$isAdmin = $auth->hasRole('club_admin') || $auth->isSuperAdmin();
$isCoach = $auth->hasRole('coach');

if (!$isAdmin && !$isCoach) {
    http_response_code(403);
    echo json_encode(['error' => 'Insufficient permissions. Club admin or coach role required.']);
    exit();
}

$database = Database::getInstance();
$db = $database->getConnection();

// Tournament notification service — every public method is feature-flagged + try/catch
// internally, so calls into it are safe to fire from any action handler. Failures are
// logged via error_log and never propagate up to break the originating tournament action.
$tournamentNotifications = new TournamentNotificationService($db);

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($action) {

        // ============================================
        // TOURNAMENT CRUD
        // ============================================

        case 'list':
            // GET ?action=list&club_id={id}&status={status}&season_id={id}
            if ($method !== 'GET') { methodNotAllowed(); }

            $clubId = $_GET['club_id'] ?? null;
            if (!$clubId) {
                http_response_code(400);
                echo json_encode(['error' => 'club_id is required']);
                exit();
            }

            // Verify user has access to this club
            $accessibleClubs = $auth->getAccessibleClubIds();
            if ($accessibleClubs !== null && !in_array((int)$clubId, $accessibleClubs)) {
                http_response_code(403);
                echo json_encode(['error' => 'Access denied to this club']);
                exit();
            }

            $sql = "SELECT t.*,
                        v.name  AS venue_name,
                        v.city  AS venue_city,
                        v.state AS venue_state,
                        (SELECT COUNT(*) FROM tournament_divisions WHERE tournament_id = t.id) AS division_count,
                        (SELECT COUNT(*) FROM tournament_registrations WHERE tournament_id = t.id) AS registration_count
                    FROM tournaments t
                    LEFT JOIN venues v ON t.venue_id = v.id
                    WHERE t.club_id = ?";
            $params = [(int)$clubId];

            if (!empty($_GET['status'])) {
                $sql .= " AND t.status = ?";
                $params[] = $_GET['status'];
            }
            if (!empty($_GET['season_id'])) {
                $sql .= " AND t.season_id = ?";
                $params[] = (int)$_GET['season_id'];
            }

            // Coaches only see tournaments where they have registered teams
            if (!$isAdmin) {
                $sql .= " AND (t.id IN (
                    SELECT tr.tournament_id FROM tournament_registrations tr
                    JOIN team_members tm ON tm.team_id = tr.team_id
                    WHERE tm.user_id = ?
                ) OR t.status IN ('registration_open','in_progress','completed'))";
                $params[] = $userId;
            }

            $sql .= " ORDER BY t.start_date DESC";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $tournaments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['tournaments' => $tournaments]);
            break;

        case 'get':
            // GET ?action=get&id={id}
            if ($method !== 'GET') { methodNotAllowed(); }

            $tournamentId = $_GET['id'] ?? null;
            if (!$tournamentId) {
                http_response_code(400);
                echo json_encode(['error' => 'id is required']);
                exit();
            }

            $stmt = $db->prepare("
                SELECT t.*,
                       v.name    AS venue_name,
                       v.address AS venue_address,
                       v.city    AS venue_city,
                       v.state   AS venue_state,
                       v.zip_code AS venue_zip,
                       (SELECT COUNT(*) FROM fields WHERE venue_id = t.venue_id AND active = true) AS venue_field_count
                FROM tournaments t
                LEFT JOIN venues v ON t.venue_id = v.id
                WHERE t.id = ?
            ");
            $stmt->execute([(int)$tournamentId]);
            $tournament = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$tournament) {
                http_response_code(404);
                echo json_encode(['error' => 'Tournament not found']);
                exit();
            }

            // Verify club access
            $accessibleClubs = $auth->getAccessibleClubIds();
            if ($accessibleClubs !== null && !in_array((int)$tournament['club_id'], $accessibleClubs)) {
                http_response_code(403);
                echo json_encode(['error' => 'Access denied']);
                exit();
            }

            // Fetch divisions
            $divStmt = $db->prepare("
                SELECT td.*,
                    (SELECT COUNT(*) FROM tournament_registrations WHERE division_id = td.id) AS registration_count,
                    (SELECT COUNT(*) FROM tournament_groups WHERE division_id = td.id) AS group_count
                FROM tournament_divisions td
                WHERE td.tournament_id = ?
                ORDER BY td.sort_order, td.age_group
            ");
            $divStmt->execute([(int)$tournamentId]);
            $tournament['divisions'] = $divStmt->fetchAll(PDO::FETCH_ASSOC);

            // Decode JSONB fields
            foreach ($tournament['divisions'] as &$div) {
                if (is_string($div['tiebreaker_rules'])) {
                    $div['tiebreaker_rules'] = json_decode($div['tiebreaker_rules'], true);
                }
                if (is_string($div['sport_rule_notes'])) {
                    $div['sport_rule_notes'] = json_decode($div['sport_rule_notes'], true);
                }
                if (is_string($div['overtime_rules'])) {
                    $div['overtime_rules'] = json_decode($div['overtime_rules'], true);
                }
            }

            echo json_encode($tournament);
            break;

        case 'create':
            // POST ?action=create
            if ($method !== 'POST') { methodNotAllowed(); }
            requireAdmin($isAdmin);

            $data = json_decode(file_get_contents('php://input'), true);

            // Validate required fields
            $required = ['club_id', 'name', 'start_date', 'end_date'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    http_response_code(400);
                    echo json_encode(['error' => "$field is required"]);
                    exit();
                }
            }

            // Validate dates
            if ($data['start_date'] > $data['end_date']) {
                http_response_code(400);
                echo json_encode(['error' => 'start_date must be on or before end_date']);
                exit();
            }

            if (!empty($data['registration_open_date']) && !empty($data['registration_close_date'])) {
                if ($data['registration_open_date'] >= $data['registration_close_date']) {
                    http_response_code(400);
                    echo json_encode(['error' => 'registration_open_date must be before registration_close_date']);
                    exit();
                }
            }

            // Verify club access
            $accessibleClubs = $auth->getAccessibleClubIds();
            if ($accessibleClubs !== null && !in_array((int)$data['club_id'], $accessibleClubs)) {
                http_response_code(403);
                echo json_encode(['error' => 'Access denied to this club']);
                exit();
            }

            // Check slug uniqueness
            if (!empty($data['public_url_slug'])) {
                $slugCheck = $db->prepare("SELECT id FROM tournaments WHERE club_id = ? AND public_url_slug = ?");
                $slugCheck->execute([(int)$data['club_id'], $data['public_url_slug']]);
                if ($slugCheck->fetch()) {
                    http_response_code(400);
                    echo json_encode(['error' => 'A tournament with this URL slug already exists for this club']);
                    exit();
                }
            }

            $stmt = $db->prepare("
                INSERT INTO tournaments (
                    club_id, name, description, sport, start_date, end_date,
                    venue_id, daily_start_time, daily_end_time,
                    location_name, location_address, location_city, location_state, location_zip,
                    registration_open_date, registration_close_date,
                    entry_fee_cents, max_teams_per_division,
                    rules_document_url, contact_name, contact_email, contact_phone,
                    public_url_slug, season_id, faq_markdown, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                RETURNING id
            ");
            $stmt->execute([
                (int)$data['club_id'],
                $data['name'],
                $data['description'] ?? null,
                $data['sport'] ?? 'soccer',
                $data['start_date'],
                $data['end_date'],
                !empty($data['venue_id']) ? (int)$data['venue_id'] : null,
                nullIfEmpty($data['daily_start_time'] ?? null) ?? '08:00:00',
                nullIfEmpty($data['daily_end_time']   ?? null) ?? '20:00:00',
                $data['location_name'] ?? null,
                $data['location_address'] ?? null,
                $data['location_city'] ?? null,
                $data['location_state'] ?? null,
                $data['location_zip'] ?? null,
                nullIfEmpty($data['registration_open_date'] ?? null),
                nullIfEmpty($data['registration_close_date'] ?? null),
                (int)($data['entry_fee_cents'] ?? 0),
                nullIfEmpty($data['max_teams_per_division'] ?? null),
                $data['rules_document_url'] ?? null,
                $data['contact_name'] ?? null,
                $data['contact_email'] ?? null,
                $data['contact_phone'] ?? null,
                $data['public_url_slug'] ?? null,
                nullIfEmpty($data['season_id'] ?? null),
                $data['faq_markdown'] ?? null,
                $userId,
            ]);

            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            http_response_code(201);
            echo json_encode(['id' => (int)$result['id'], 'message' => 'Tournament created successfully']);
            break;

        case 'update':
            // PUT ?action=update&id={id}
            if ($method !== 'PUT') { methodNotAllowed(); }
            requireAdmin($isAdmin);

            $tournamentId = $_GET['id'] ?? null;
            if (!$tournamentId) {
                http_response_code(400);
                echo json_encode(['error' => 'id is required']);
                exit();
            }

            // Verify tournament exists and user has access
            $check = $db->prepare("SELECT club_id FROM tournaments WHERE id = ?");
            $check->execute([(int)$tournamentId]);
            $existing = $check->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                http_response_code(404);
                echo json_encode(['error' => 'Tournament not found']);
                exit();
            }

            $accessibleClubs = $auth->getAccessibleClubIds();
            if ($accessibleClubs !== null && !in_array((int)$existing['club_id'], $accessibleClubs)) {
                http_response_code(403);
                echo json_encode(['error' => 'Access denied']);
                exit();
            }

            $data = json_decode(file_get_contents('php://input'), true);

            // Validate dates if provided
            $startDate = $data['start_date'] ?? null;
            $endDate = $data['end_date'] ?? null;
            if ($startDate && $endDate && $startDate > $endDate) {
                http_response_code(400);
                echo json_encode(['error' => 'start_date must be on or before end_date']);
                exit();
            }

            // Check slug uniqueness if changing
            if (!empty($data['public_url_slug'])) {
                $slugCheck = $db->prepare("SELECT id FROM tournaments WHERE club_id = ? AND public_url_slug = ? AND id != ?");
                $slugCheck->execute([(int)$existing['club_id'], $data['public_url_slug'], (int)$tournamentId]);
                if ($slugCheck->fetch()) {
                    http_response_code(400);
                    echo json_encode(['error' => 'A tournament with this URL slug already exists for this club']);
                    exit();
                }
            }

            // Build dynamic update
            $fields = [
                'name', 'description', 'sport', 'start_date', 'end_date',
                'venue_id', 'daily_start_time', 'daily_end_time',
                'location_name', 'location_address', 'location_city', 'location_state', 'location_zip',
                'registration_open_date', 'registration_close_date',
                'entry_fee_cents', 'max_teams_per_division',
                'rules_document_url', 'contact_name', 'contact_email', 'contact_phone',
                'public_url_slug', 'season_id', 'faq_markdown',
            ];

            // Fields stored as DATE / TIMESTAMP / TIME / nullable INT — empty
            // strings from the frontend get coerced to NULL so Postgres doesn't
            // reject "" with SQLSTATE 22007.
            $nullableFields = [
                'start_date', 'end_date',
                'registration_open_date', 'registration_close_date',
                'daily_start_time', 'daily_end_time',
                'venue_id', 'season_id', 'max_teams_per_division', 'entry_fee_cents',
            ];

            $setClauses = [];
            $params = [];
            foreach ($fields as $field) {
                if (array_key_exists($field, $data)) {
                    $setClauses[] = "$field = ?";
                    $params[] = in_array($field, $nullableFields, true)
                        ? nullIfEmpty($data[$field])
                        : $data[$field];
                }
            }

            if (empty($setClauses)) {
                http_response_code(400);
                echo json_encode(['error' => 'No fields to update']);
                exit();
            }

            $setClauses[] = "updated_at = CURRENT_TIMESTAMP";
            $params[] = (int)$tournamentId;

            $sql = "UPDATE tournaments SET " . implode(', ', $setClauses) . " WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            echo json_encode(['success' => true, 'message' => 'Tournament updated']);
            break;

        case 'delete':
            // DELETE ?action=delete&id={id}
            if ($method !== 'DELETE') { methodNotAllowed(); }
            requireAdmin($isAdmin);

            $tournamentId = $_GET['id'] ?? null;
            if (!$tournamentId) {
                http_response_code(400);
                echo json_encode(['error' => 'id is required']);
                exit();
            }

            // Verify access
            $check = $db->prepare("SELECT club_id FROM tournaments WHERE id = ?");
            $check->execute([(int)$tournamentId]);
            $existing = $check->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                http_response_code(404);
                echo json_encode(['error' => 'Tournament not found']);
                exit();
            }

            $accessibleClubs = $auth->getAccessibleClubIds();
            if ($accessibleClubs !== null && !in_array((int)$existing['club_id'], $accessibleClubs)) {
                http_response_code(403);
                echo json_encode(['error' => 'Access denied']);
                exit();
            }

            // Soft delete: set status to cancelled
            $stmt = $db->prepare("UPDATE tournaments SET status = 'cancelled', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([(int)$tournamentId]);

            echo json_encode(['success' => true, 'message' => 'Tournament cancelled']);
            break;

        case 'update-status':
            // PUT ?action=update-status&id={id}
            if ($method !== 'PUT') { methodNotAllowed(); }
            requireAdmin($isAdmin);

            $tournamentId = $_GET['id'] ?? null;
            if (!$tournamentId) {
                http_response_code(400);
                echo json_encode(['error' => 'id is required']);
                exit();
            }

            $data = json_decode(file_get_contents('php://input'), true);
            $newStatus = $data['status'] ?? null;

            if (!$newStatus) {
                http_response_code(400);
                echo json_encode(['error' => 'status is required']);
                exit();
            }

            // Get current status
            $check = $db->prepare("SELECT status, club_id FROM tournaments WHERE id = ?");
            $check->execute([(int)$tournamentId]);
            $existing = $check->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                http_response_code(404);
                echo json_encode(['error' => 'Tournament not found']);
                exit();
            }

            $accessibleClubs = $auth->getAccessibleClubIds();
            if ($accessibleClubs !== null && !in_array((int)$existing['club_id'], $accessibleClubs)) {
                http_response_code(403);
                echo json_encode(['error' => 'Access denied']);
                exit();
            }

            // Validate status transition
            $validTransitions = [
                'draft' => ['registration_open', 'cancelled'],
                'registration_open' => ['registration_closed', 'cancelled'],
                'registration_closed' => ['scheduling', 'registration_open', 'cancelled'],
                'scheduling' => ['in_progress', 'cancelled'],
                'in_progress' => ['weather_delay', 'completed', 'cancelled'],
                'weather_delay' => ['in_progress', 'cancelled'],
                'completed' => [],
                'cancelled' => [],
            ];

            $currentStatus = $existing['status'];
            $allowed = $validTransitions[$currentStatus] ?? [];

            if (!in_array($newStatus, $allowed)) {
                http_response_code(400);
                echo json_encode([
                    'error' => "Invalid status transition from '$currentStatus' to '$newStatus'",
                    'allowed' => $allowed,
                ]);
                exit();
            }

            $stmt = $db->prepare("UPDATE tournaments SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$newStatus, (int)$tournamentId]);

            // Tournament status transitions trigger notifications. Only the actual
            // transition fires (entering 'in_progress' = schedule published, entering
            // 'weather_delay' = delay broadcast). Other transitions are silent.
            if ($newStatus === 'in_progress' && $currentStatus !== 'in_progress') {
                $tournamentNotifications->notifySchedulePublished((int)$tournamentId, $userId);
            } elseif ($newStatus === 'weather_delay' && $currentStatus !== 'weather_delay') {
                $tournamentNotifications->notifyWeatherDelay((int)$tournamentId, $userId);
            }

            echo json_encode(['success' => true, 'message' => "Status updated to $newStatus"]);
            break;

        // ============================================
        // DIVISION CRUD
        // ============================================

        case 'divisions-list':
            // GET ?action=divisions-list&tournament_id={id}
            if ($method !== 'GET') { methodNotAllowed(); }

            $tournamentId = $_GET['tournament_id'] ?? null;
            if (!$tournamentId) {
                http_response_code(400);
                echo json_encode(['error' => 'tournament_id is required']);
                exit();
            }

            // Verify tournament access
            verifyTournamentAccess($db, $auth, (int)$tournamentId);

            $stmt = $db->prepare("
                SELECT td.*,
                    (SELECT COUNT(*) FROM tournament_registrations WHERE division_id = td.id) AS registration_count,
                    (SELECT COUNT(*) FROM tournament_groups WHERE division_id = td.id) AS group_count
                FROM tournament_divisions td
                WHERE td.tournament_id = ?
                ORDER BY td.sort_order, td.age_group
            ");
            $stmt->execute([(int)$tournamentId]);
            $divisions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Decode JSONB fields
            foreach ($divisions as &$div) {
                if (is_string($div['tiebreaker_rules'])) $div['tiebreaker_rules'] = json_decode($div['tiebreaker_rules'], true);
                if (is_string($div['sport_rule_notes'])) $div['sport_rule_notes'] = json_decode($div['sport_rule_notes'], true);
                if (is_string($div['overtime_rules'])) $div['overtime_rules'] = json_decode($div['overtime_rules'], true);
            }

            echo json_encode(['divisions' => $divisions]);
            break;

        case 'division-create':
            // POST ?action=division-create&tournament_id={id}
            if ($method !== 'POST') { methodNotAllowed(); }
            requireAdmin($isAdmin);

            $tournamentId = $_GET['tournament_id'] ?? null;
            if (!$tournamentId) {
                http_response_code(400);
                echo json_encode(['error' => 'tournament_id is required']);
                exit();
            }

            verifyTournamentAccess($db, $auth, (int)$tournamentId);

            $data = json_decode(file_get_contents('php://input'), true);

            // Validate required fields
            if (empty($data['name']) || empty($data['age_group'])) {
                http_response_code(400);
                echo json_encode(['error' => 'name and age_group are required']);
                exit();
            }

            // Get tournament sport for preset lookup
            $tStmt = $db->prepare("SELECT sport FROM tournaments WHERE id = ?");
            $tStmt->execute([(int)$tournamentId]);
            $tournament = $tStmt->fetch(PDO::FETCH_ASSOC);
            $sport = $tournament['sport'] ?? 'soccer';

            // Auto-fill from sport presets if values not explicitly provided
            $preset = null;
            $presetStmt = $db->prepare("SELECT * FROM sport_presets WHERE sport = ? AND age_group = ?");
            $presetStmt->execute([$sport, $data['age_group']]);
            $preset = $presetStmt->fetch(PDO::FETCH_ASSOC);

            $gameDuration = $data['game_duration_minutes'] ?? ($preset ? (int)$preset['game_duration_minutes'] : 50);
            $halfDuration = $data['half_duration_minutes'] ?? ($preset ? (int)$preset['half_duration_minutes'] : 25);
            $maxRoster = $data['max_roster_size'] ?? ($preset ? (int)$preset['max_roster_size'] : 18);
            $minRoster = $data['min_roster_size'] ?? ($preset ? (int)$preset['min_roster_size'] : 7);
            $maxOnField = $data['max_players_on_field'] ?? ($preset ? (int)$preset['max_players_on_field'] : null);
            $ruleNotes = $data['sport_rule_notes'] ?? ($preset ? (is_string($preset['rule_notes']) ? json_decode($preset['rule_notes'], true) : $preset['rule_notes']) : null);

            $tiebreakerRules = $data['tiebreaker_rules'] ?? ['points','head_to_head','goal_difference','goals_for','goals_against','wins','coin_flip'];

            // Get next sort_order
            $orderStmt = $db->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_order FROM tournament_divisions WHERE tournament_id = ?");
            $orderStmt->execute([(int)$tournamentId]);
            $nextOrder = (int)$orderStmt->fetch(PDO::FETCH_ASSOC)['next_order'];

            $stmt = $db->prepare("
                INSERT INTO tournament_divisions (
                    tournament_id, name, age_group, gender, format,
                    game_duration_minutes, half_duration_minutes,
                    max_roster_size, min_roster_size, max_teams,
                    teams_per_group, teams_advancing_per_group,
                    goal_differential_cap, tiebreaker_rules,
                    points_for_win, points_for_draw, points_for_loss,
                    max_players_on_field, sport_rule_notes, overtime_rules, sort_order
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?, ?::jsonb, ?::jsonb, ?)
                RETURNING id
            ");
            $stmt->execute([
                (int)$tournamentId,
                $data['name'],
                $data['age_group'],
                $data['gender'] ?? 'coed',
                $data['format'] ?? 'group_knockout',
                $gameDuration,
                $halfDuration,
                $maxRoster,
                $minRoster,
                $data['max_teams'] ?? null,
                (int)($data['teams_per_group'] ?? 4),
                (int)($data['teams_advancing_per_group'] ?? 2),
                $data['goal_differential_cap'] ?? null,
                json_encode($tiebreakerRules),
                (int)($data['points_for_win'] ?? 3),
                (int)($data['points_for_draw'] ?? 1),
                (int)($data['points_for_loss'] ?? 0),
                $maxOnField,
                $ruleNotes ? json_encode($ruleNotes) : null,
                isset($data['overtime_rules']) ? json_encode($data['overtime_rules']) : null,
                $nextOrder,
            ]);

            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            http_response_code(201);
            echo json_encode(['id' => (int)$result['id'], 'message' => 'Division created']);
            break;

        case 'division-update':
            // PUT ?action=division-update&id={divisionId}
            if ($method !== 'PUT') { methodNotAllowed(); }
            requireAdmin($isAdmin);

            $divisionId = $_GET['id'] ?? null;
            if (!$divisionId) {
                http_response_code(400);
                echo json_encode(['error' => 'id is required']);
                exit();
            }

            // Get division's tournament and verify access
            $check = $db->prepare("SELECT td.tournament_id, t.club_id FROM tournament_divisions td JOIN tournaments t ON t.id = td.tournament_id WHERE td.id = ?");
            $check->execute([(int)$divisionId]);
            $existing = $check->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                http_response_code(404);
                echo json_encode(['error' => 'Division not found']);
                exit();
            }

            $accessibleClubs = $auth->getAccessibleClubIds();
            if ($accessibleClubs !== null && !in_array((int)$existing['club_id'], $accessibleClubs)) {
                http_response_code(403);
                echo json_encode(['error' => 'Access denied']);
                exit();
            }

            $data = json_decode(file_get_contents('php://input'), true);

            $fields = [
                'name', 'age_group', 'gender', 'format',
                'game_duration_minutes', 'half_duration_minutes',
                'max_roster_size', 'min_roster_size', 'max_teams',
                'teams_per_group', 'teams_advancing_per_group',
                'goal_differential_cap', 'points_for_win', 'points_for_draw', 'points_for_loss',
                'max_players_on_field', 'sort_order',
            ];

            $setClauses = [];
            $params = [];
            foreach ($fields as $field) {
                if (array_key_exists($field, $data)) {
                    $setClauses[] = "$field = ?";
                    $params[] = $data[$field];
                }
            }

            // Handle JSONB fields separately
            if (array_key_exists('tiebreaker_rules', $data)) {
                $setClauses[] = "tiebreaker_rules = ?::jsonb";
                $params[] = json_encode($data['tiebreaker_rules']);
            }
            if (array_key_exists('sport_rule_notes', $data)) {
                $setClauses[] = "sport_rule_notes = ?::jsonb";
                $params[] = $data['sport_rule_notes'] ? json_encode($data['sport_rule_notes']) : null;
            }
            if (array_key_exists('overtime_rules', $data)) {
                $setClauses[] = "overtime_rules = ?::jsonb";
                $params[] = $data['overtime_rules'] ? json_encode($data['overtime_rules']) : null;
            }

            if (empty($setClauses)) {
                http_response_code(400);
                echo json_encode(['error' => 'No fields to update']);
                exit();
            }

            $setClauses[] = "updated_at = CURRENT_TIMESTAMP";
            $params[] = (int)$divisionId;

            $sql = "UPDATE tournament_divisions SET " . implode(', ', $setClauses) . " WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            echo json_encode(['success' => true, 'message' => 'Division updated']);
            break;

        case 'division-delete':
            // DELETE ?action=division-delete&id={divisionId}
            if ($method !== 'DELETE') { methodNotAllowed(); }
            requireAdmin($isAdmin);

            $divisionId = $_GET['id'] ?? null;
            if (!$divisionId) {
                http_response_code(400);
                echo json_encode(['error' => 'id is required']);
                exit();
            }

            // Verify access
            $check = $db->prepare("SELECT td.tournament_id, t.club_id FROM tournament_divisions td JOIN tournaments t ON t.id = td.tournament_id WHERE td.id = ?");
            $check->execute([(int)$divisionId]);
            $existing = $check->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                http_response_code(404);
                echo json_encode(['error' => 'Division not found']);
                exit();
            }

            $accessibleClubs = $auth->getAccessibleClubIds();
            if ($accessibleClubs !== null && !in_array((int)$existing['club_id'], $accessibleClubs)) {
                http_response_code(403);
                echo json_encode(['error' => 'Access denied']);
                exit();
            }

            // Check for active registrations
            $regCheck = $db->prepare("SELECT COUNT(*) AS cnt FROM tournament_registrations WHERE division_id = ? AND status IN ('pending', 'accepted')");
            $regCheck->execute([(int)$divisionId]);
            $regCount = (int)$regCheck->fetch(PDO::FETCH_ASSOC)['cnt'];

            if ($regCount > 0) {
                http_response_code(409);
                echo json_encode(['error' => "Cannot delete division with $regCount active registration(s). Reject or withdraw them first."]);
                exit();
            }

            $stmt = $db->prepare("DELETE FROM tournament_divisions WHERE id = ?");
            $stmt->execute([(int)$divisionId]);

            echo json_encode(['success' => true, 'message' => 'Division deleted']);
            break;

        case 'sport-presets':
            // GET ?action=sport-presets&sport={sport}
            if ($method !== 'GET') { methodNotAllowed(); }
            $sport = $_GET['sport'] ?? 'soccer';
            $stmt = $db->prepare("SELECT * FROM sport_presets WHERE sport = ? ORDER BY age_group");
            $stmt->execute([$sport]);
            $presets = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['presets' => $presets]);
            break;

        // ============================================
        // REGISTRATIONS
        // ============================================

        case 'registrations-list':
            // GET ?action=registrations-list&tournament_id={id}&division_id={id}&status={status}
            if ($method !== 'GET') { methodNotAllowed(); }

            $tournamentId = $_GET['tournament_id'] ?? null;
            if (!$tournamentId) {
                http_response_code(400);
                echo json_encode(['error' => 'tournament_id is required']);
                exit();
            }

            verifyTournamentAccess($db, $auth, (int)$tournamentId);

            $sql = "SELECT tr.*,
                        t.name AS team_name,
                        COALESCE(tr.team_name_override, t.name) AS display_name,
                        td.name AS division_name,
                        tg.name AS group_name,
                        u.first_name || ' ' || u.last_name AS registered_by_name
                    FROM tournament_registrations tr
                    JOIN teams t ON t.id = tr.team_id
                    JOIN tournament_divisions td ON td.id = tr.division_id
                    LEFT JOIN tournament_groups tg ON tg.id = tr.group_id
                    JOIN users u ON u.id = tr.registered_by
                    WHERE tr.tournament_id = ?";
            $params = [(int)$tournamentId];

            if (!empty($_GET['division_id'])) {
                $sql .= " AND tr.division_id = ?";
                $params[] = (int)$_GET['division_id'];
            }
            if (!empty($_GET['status'])) {
                $sql .= " AND tr.status = ?";
                $params[] = $_GET['status'];
            }

            $sql .= " ORDER BY tr.created_at DESC";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get counts by status
            $countStmt = $db->prepare("
                SELECT status, COUNT(*) AS cnt
                FROM tournament_registrations
                WHERE tournament_id = ?
                GROUP BY status
            ");
            $countStmt->execute([(int)$tournamentId]);
            $counts = [];
            foreach ($countStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $counts[$row['status']] = (int)$row['cnt'];
            }

            echo json_encode(['registrations' => $registrations, 'counts' => $counts]);
            break;

        case 'registration-create':
            // POST ?action=registration-create
            if ($method !== 'POST') { methodNotAllowed(); }

            $data = json_decode(file_get_contents('php://input'), true);

            if (empty($data['tournament_id']) || empty($data['division_id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'tournament_id and division_id are required']);
                exit();
            }

            $tournamentData = verifyTournamentAccess($db, $auth, (int)$data['tournament_id']);

            // If coach, verify they have access to this team
            if (!$isAdmin) {
                if (empty($data['team_id'])) {
                    http_response_code(400);
                    echo json_encode(['error' => 'team_id is required']);
                    exit();
                }
                $teamCheck = $db->prepare("
                    SELECT 1 FROM team_members WHERE team_id = ? AND user_id = ?
                    UNION
                    SELECT 1 FROM teams WHERE id = ? AND primary_coach_id = ?
                ");
                $teamCheck->execute([(int)$data['team_id'], $userId, (int)$data['team_id'], $userId]);
                if (!$teamCheck->fetch()) {
                    http_response_code(403);
                    echo json_encode(['error' => 'You do not have access to this team']);
                    exit();
                }
            }

            // Handle guest teams: create stub team record
            $teamId = $data['team_id'] ?? null;
            if (!empty($data['team_name_override']) && !$teamId) {
                $stubStmt = $db->prepare("INSERT INTO teams (name, club_id, status) VALUES (?, ?, 'active') RETURNING id");
                $stubStmt->execute([
                    $data['team_name_override'],
                    (int)$tournamentData['club_id'],
                ]);
                $teamId = (int)$stubStmt->fetch(PDO::FETCH_ASSOC)['id'];
            }

            if (!$teamId) {
                http_response_code(400);
                echo json_encode(['error' => 'team_id or team_name_override is required']);
                exit();
            }

            // Check duplicate
            $dupCheck = $db->prepare("SELECT id FROM tournament_registrations WHERE tournament_id = ? AND team_id = ?");
            $dupCheck->execute([(int)$data['tournament_id'], (int)$teamId]);
            if ($dupCheck->fetch()) {
                http_response_code(409);
                echo json_encode(['error' => 'This team is already registered for this tournament']);
                exit();
            }

            // Check division max_teams
            $divCheck = $db->prepare("SELECT max_teams FROM tournament_divisions WHERE id = ?");
            $divCheck->execute([(int)$data['division_id']]);
            $divData = $divCheck->fetch(PDO::FETCH_ASSOC);

            $status = 'pending';
            if ($divData && $divData['max_teams']) {
                $acceptedCount = $db->prepare("SELECT COUNT(*) AS cnt FROM tournament_registrations WHERE division_id = ? AND status = 'accepted'");
                $acceptedCount->execute([(int)$data['division_id']]);
                $cnt = (int)$acceptedCount->fetch(PDO::FETCH_ASSOC)['cnt'];
                if ($cnt >= (int)$divData['max_teams']) {
                    $status = 'waitlisted';
                }
            }

            // Get tournament entry fee
            $feeStmt = $db->prepare("SELECT entry_fee_cents FROM tournaments WHERE id = ?");
            $feeStmt->execute([(int)$data['tournament_id']]);
            $fee = (int)$feeStmt->fetch(PDO::FETCH_ASSOC)['entry_fee_cents'];

            $stmt = $db->prepare("
                INSERT INTO tournament_registrations (
                    tournament_id, division_id, team_id,
                    team_name_override, club_name_override,
                    registered_by, status, payment_amount_cents, notes
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                RETURNING id
            ");
            $stmt->execute([
                (int)$data['tournament_id'],
                (int)$data['division_id'],
                (int)$teamId,
                $data['team_name_override'] ?? null,
                $data['club_name_override'] ?? null,
                $userId,
                $status,
                $fee,
                $data['notes'] ?? null,
            ]);

            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            http_response_code(201);
            echo json_encode([
                'id' => (int)$result['id'],
                'status' => $status,
                'message' => $status === 'waitlisted'
                    ? 'Team registered (waitlisted — division is full)'
                    : 'Registration submitted successfully',
            ]);
            break;

        case 'registration-update-status':
            // PUT ?action=registration-update-status&id={regId}
            if ($method !== 'PUT') { methodNotAllowed(); }
            requireAdmin($isAdmin);

            $regId = $_GET['id'] ?? null;
            if (!$regId) {
                http_response_code(400);
                echo json_encode(['error' => 'id is required']);
                exit();
            }

            $data = json_decode(file_get_contents('php://input'), true);
            $newStatus = $data['status'] ?? null;

            $validStatuses = ['pending', 'accepted', 'rejected', 'waitlisted'];
            if (!$newStatus || !in_array($newStatus, $validStatuses)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid status. Allowed: ' . implode(', ', $validStatuses)]);
                exit();
            }

            // Verify registration exists and get division for max_teams check
            $regCheck = $db->prepare("
                SELECT tr.division_id, tr.tournament_id, t.club_id
                FROM tournament_registrations tr
                JOIN tournaments t ON t.id = tr.tournament_id
                WHERE tr.id = ?
            ");
            $regCheck->execute([(int)$regId]);
            $reg = $regCheck->fetch(PDO::FETCH_ASSOC);
            if (!$reg) {
                http_response_code(404);
                echo json_encode(['error' => 'Registration not found']);
                exit();
            }

            // If accepting, check max_teams
            if ($newStatus === 'accepted') {
                $divCheck = $db->prepare("SELECT max_teams FROM tournament_divisions WHERE id = ?");
                $divCheck->execute([(int)$reg['division_id']]);
                $divData = $divCheck->fetch(PDO::FETCH_ASSOC);

                if ($divData && $divData['max_teams']) {
                    $cnt = $db->prepare("SELECT COUNT(*) AS cnt FROM tournament_registrations WHERE division_id = ? AND status = 'accepted'");
                    $cnt->execute([(int)$reg['division_id']]);
                    if ((int)$cnt->fetch(PDO::FETCH_ASSOC)['cnt'] >= (int)$divData['max_teams']) {
                        http_response_code(400);
                        echo json_encode(['error' => 'Division is at maximum capacity']);
                        exit();
                    }
                }
            }

            // Capture current status BEFORE the update so we only notify on actual transitions.
            $priorStatusStmt = $db->prepare("SELECT status FROM tournament_registrations WHERE id = ?");
            $priorStatusStmt->execute([(int)$regId]);
            $priorRegStatus = $priorStatusStmt->fetchColumn();

            $stmt = $db->prepare("UPDATE tournament_registrations SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$newStatus, (int)$regId]);

            // Fire trigger only on actual status change.
            if ($newStatus !== $priorRegStatus) {
                if ($newStatus === 'accepted') {
                    $tournamentNotifications->notifyRegistrationAccepted((int)$regId, $userId);
                } elseif ($newStatus === 'rejected') {
                    $tournamentNotifications->notifyRegistrationDeclined((int)$regId, $userId);
                } elseif ($newStatus === 'waitlisted') {
                    $tournamentNotifications->notifyRegistrationWaitlisted((int)$regId, $userId);
                }
            }

            echo json_encode(['success' => true, 'message' => "Registration status updated to $newStatus"]);
            break;

        case 'registration-update-payment':
            // PUT ?action=registration-update-payment&id={regId}
            if ($method !== 'PUT') { methodNotAllowed(); }
            requireAdmin($isAdmin);

            $regId = $_GET['id'] ?? null;
            if (!$regId) {
                http_response_code(400);
                echo json_encode(['error' => 'id is required']);
                exit();
            }

            $data = json_decode(file_get_contents('php://input'), true);

            $validPaymentStatuses = ['unpaid', 'paid', 'refunded', 'waived'];
            if (empty($data['payment_status']) || !in_array($data['payment_status'], $validPaymentStatuses)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid payment_status']);
                exit();
            }

            // Capture prior payment_status so we only notify on transition to 'paid'.
            $priorPayStmt = $db->prepare("SELECT payment_status FROM tournament_registrations WHERE id = ?");
            $priorPayStmt->execute([(int)$regId]);
            $priorPaymentStatus = $priorPayStmt->fetchColumn();

            $stmt = $db->prepare("
                UPDATE tournament_registrations
                SET payment_status = ?,
                    payment_amount_cents = COALESCE(?, payment_amount_cents),
                    payment_reference = COALESCE(?, payment_reference),
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([
                $data['payment_status'],
                $data['payment_amount_cents'] ?? null,
                $data['payment_reference'] ?? null,
                (int)$regId,
            ]);

            if ($data['payment_status'] === 'paid' && $priorPaymentStatus !== 'paid') {
                $tournamentNotifications->notifyPaymentReceived((int)$regId, $userId);
            }

            echo json_encode(['success' => true, 'message' => 'Payment status updated']);
            break;

        case 'registration-withdraw':
            // PUT ?action=registration-withdraw&id={regId}
            if ($method !== 'PUT') { methodNotAllowed(); }

            $regId = $_GET['id'] ?? null;
            if (!$regId) {
                http_response_code(400);
                echo json_encode(['error' => 'id is required']);
                exit();
            }

            // Coach can only withdraw their own registrations
            $regCheck = $db->prepare("SELECT registered_by, tournament_id FROM tournament_registrations WHERE id = ?");
            $regCheck->execute([(int)$regId]);
            $reg = $regCheck->fetch(PDO::FETCH_ASSOC);
            if (!$reg) {
                http_response_code(404);
                echo json_encode(['error' => 'Registration not found']);
                exit();
            }

            if (!$isAdmin && (int)$reg['registered_by'] !== $userId) {
                http_response_code(403);
                echo json_encode(['error' => 'You can only withdraw your own registrations']);
                exit();
            }

            $stmt = $db->prepare("UPDATE tournament_registrations SET status = 'withdrawn', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([(int)$regId]);

            echo json_encode(['success' => true, 'message' => 'Registration withdrawn']);
            break;

        case 'registration-seed':
            // PUT ?action=registration-seed&id={regId}
            if ($method !== 'PUT') { methodNotAllowed(); }
            requireAdmin($isAdmin);

            $regId = $_GET['id'] ?? null;
            if (!$regId) {
                http_response_code(400);
                echo json_encode(['error' => 'id is required']);
                exit();
            }

            $data = json_decode(file_get_contents('php://input'), true);
            $seed = $data['seed'] ?? null;

            if ($seed !== null && (!is_numeric($seed) || (int)$seed < 1)) {
                http_response_code(400);
                echo json_encode(['error' => 'seed must be a positive integer or null']);
                exit();
            }

            $stmt = $db->prepare("UPDATE tournament_registrations SET seed = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$seed, (int)$regId]);

            echo json_encode(['success' => true, 'message' => 'Seed updated']);
            break;

        // ============================================
        // GROUPS / POOLS
        // ============================================

        case 'groups-list':
            // GET ?action=groups-list&division_id={id}
            if ($method !== 'GET') { methodNotAllowed(); }

            $divisionId = $_GET['division_id'] ?? null;
            if (!$divisionId) {
                http_response_code(400);
                echo json_encode(['error' => 'division_id is required']);
                exit();
            }

            // Verify access via division -> tournament
            $divCheck = $db->prepare("SELECT td.tournament_id FROM tournament_divisions td WHERE td.id = ?");
            $divCheck->execute([(int)$divisionId]);
            $divRow = $divCheck->fetch(PDO::FETCH_ASSOC);
            if (!$divRow) { http_response_code(404); echo json_encode(['error' => 'Division not found']); exit(); }
            verifyTournamentAccess($db, $auth, (int)$divRow['tournament_id']);

            // Fetch groups with assigned teams
            $groupStmt = $db->prepare("
                SELECT tg.id, tg.name, tg.sort_order
                FROM tournament_groups tg
                WHERE tg.division_id = ?
                ORDER BY tg.sort_order
            ");
            $groupStmt->execute([(int)$divisionId]);
            $groups = $groupStmt->fetchAll(PDO::FETCH_ASSOC);

            // Fetch teams for each group
            foreach ($groups as &$group) {
                $teamStmt = $db->prepare("
                    SELECT tr.id AS registration_id,
                           COALESCE(tr.team_name_override, t.name) AS display_name,
                           tr.seed
                    FROM tournament_registrations tr
                    JOIN teams t ON t.id = tr.team_id
                    WHERE tr.group_id = ? AND tr.status = 'accepted'
                    ORDER BY tr.seed NULLS LAST, tr.id
                ");
                $teamStmt->execute([(int)$group['id']]);
                $group['teams'] = $teamStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // Fetch unassigned accepted teams
            $unassignedStmt = $db->prepare("
                SELECT tr.id AS registration_id,
                       COALESCE(tr.team_name_override, t.name) AS display_name,
                       tr.seed
                FROM tournament_registrations tr
                JOIN teams t ON t.id = tr.team_id
                WHERE tr.division_id = ? AND tr.status = 'accepted' AND tr.group_id IS NULL
                ORDER BY tr.seed NULLS LAST, tr.id
            ");
            $unassignedStmt->execute([(int)$divisionId]);
            $unassigned = $unassignedStmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['groups' => $groups, 'unassigned_teams' => $unassigned]);
            break;

        case 'group-create':
            // POST ?action=group-create&division_id={id}
            if ($method !== 'POST') { methodNotAllowed(); }
            requireAdmin($isAdmin);

            $divisionId = $_GET['division_id'] ?? null;
            if (!$divisionId) {
                http_response_code(400);
                echo json_encode(['error' => 'division_id is required']);
                exit();
            }

            $divCheck = $db->prepare("SELECT td.tournament_id FROM tournament_divisions td WHERE td.id = ?");
            $divCheck->execute([(int)$divisionId]);
            $divRow = $divCheck->fetch(PDO::FETCH_ASSOC);
            if (!$divRow) { http_response_code(404); echo json_encode(['error' => 'Division not found']); exit(); }
            verifyTournamentAccess($db, $auth, (int)$divRow['tournament_id']);

            $data = json_decode(file_get_contents('php://input'), true);

            // Get next sort_order and auto-name if not provided
            $orderStmt = $db->prepare("SELECT COUNT(*) AS cnt FROM tournament_groups WHERE division_id = ?");
            $orderStmt->execute([(int)$divisionId]);
            $cnt = (int)$orderStmt->fetch(PDO::FETCH_ASSOC)['cnt'];

            $name = $data['name'] ?? ('Group ' . chr(65 + $cnt)); // Group A, B, C...
            $sortOrder = $data['sort_order'] ?? $cnt;

            $stmt = $db->prepare("INSERT INTO tournament_groups (division_id, name, sort_order) VALUES (?, ?, ?) RETURNING id");
            $stmt->execute([(int)$divisionId, $name, $sortOrder]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            http_response_code(201);
            echo json_encode(['id' => (int)$result['id'], 'name' => $name]);
            break;

        case 'group-assign-teams':
            // PUT ?action=group-assign-teams&group_id={id}
            if ($method !== 'PUT') { methodNotAllowed(); }
            requireAdmin($isAdmin);

            $groupId = $_GET['group_id'] ?? null;
            if (!$groupId) {
                http_response_code(400);
                echo json_encode(['error' => 'group_id is required']);
                exit();
            }

            // Verify group exists and get division
            $groupCheck = $db->prepare("
                SELECT tg.division_id, td.tournament_id
                FROM tournament_groups tg
                JOIN tournament_divisions td ON td.id = tg.division_id
                WHERE tg.id = ?
            ");
            $groupCheck->execute([(int)$groupId]);
            $groupRow = $groupCheck->fetch(PDO::FETCH_ASSOC);
            if (!$groupRow) { http_response_code(404); echo json_encode(['error' => 'Group not found']); exit(); }
            verifyTournamentAccess($db, $auth, (int)$groupRow['tournament_id']);

            $data = json_decode(file_get_contents('php://input'), true);
            $registrationIds = $data['registration_ids'] ?? [];

            if (!is_array($registrationIds)) {
                http_response_code(400);
                echo json_encode(['error' => 'registration_ids must be an array']);
                exit();
            }

            // Verify all registrations are accepted and in the same division
            if (!empty($registrationIds)) {
                $placeholders = implode(',', array_fill(0, count($registrationIds), '?'));
                $verifyStmt = $db->prepare("
                    SELECT id, status, division_id FROM tournament_registrations
                    WHERE id IN ($placeholders)
                ");
                $verifyStmt->execute(array_map('intval', $registrationIds));
                $regs = $verifyStmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($regs as $reg) {
                    if ($reg['status'] !== 'accepted') {
                        http_response_code(400);
                        echo json_encode(['error' => "Registration #{$reg['id']} is not accepted (status: {$reg['status']})"]);
                        exit();
                    }
                    if ((int)$reg['division_id'] !== (int)$groupRow['division_id']) {
                        http_response_code(400);
                        echo json_encode(['error' => "Registration #{$reg['id']} is in a different division"]);
                        exit();
                    }
                }
            }

            // Clear existing assignments for this group
            $db->prepare("UPDATE tournament_registrations SET group_id = NULL WHERE group_id = ?")->execute([(int)$groupId]);

            // Assign new teams
            if (!empty($registrationIds)) {
                $placeholders = implode(',', array_fill(0, count($registrationIds), '?'));
                $params = array_merge([(int)$groupId], array_map('intval', $registrationIds));
                $db->prepare("UPDATE tournament_registrations SET group_id = ? WHERE id IN ($placeholders)")->execute($params);
            }

            echo json_encode(['success' => true, 'message' => count($registrationIds) . ' teams assigned to group']);
            break;

        case 'group-auto-assign':
            // POST ?action=group-auto-assign&division_id={id}
            if ($method !== 'POST') { methodNotAllowed(); }
            requireAdmin($isAdmin);

            $divisionId = $_GET['division_id'] ?? null;
            if (!$divisionId) {
                http_response_code(400);
                echo json_encode(['error' => 'division_id is required']);
                exit();
            }

            $divCheck = $db->prepare("SELECT td.tournament_id, td.teams_per_group FROM tournament_divisions td WHERE td.id = ?");
            $divCheck->execute([(int)$divisionId]);
            $divRow = $divCheck->fetch(PDO::FETCH_ASSOC);
            if (!$divRow) { http_response_code(404); echo json_encode(['error' => 'Division not found']); exit(); }
            verifyTournamentAccess($db, $auth, (int)$divRow['tournament_id']);

            $data = json_decode(file_get_contents('php://input'), true);
            $strategy = $data['strategy'] ?? 'snake_seed';

            // Get accepted teams ordered by seed
            $teamsStmt = $db->prepare("
                SELECT tr.id AS registration_id, tr.seed,
                       COALESCE(tr.team_name_override, t.name) AS display_name
                FROM tournament_registrations tr
                JOIN teams t ON t.id = tr.team_id
                WHERE tr.division_id = ? AND tr.status = 'accepted'
                ORDER BY tr.seed NULLS LAST, tr.id
            ");
            $teamsStmt->execute([(int)$divisionId]);
            $teams = $teamsStmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($teams)) {
                http_response_code(400);
                echo json_encode(['error' => 'No accepted teams to assign']);
                exit();
            }

            $teamsPerGroup = (int)($divRow['teams_per_group'] ?: 4);
            $numGroups = max(1, (int)ceil(count($teams) / $teamsPerGroup));

            // Create groups if they don't exist
            $existingGroups = $db->prepare("SELECT id, name FROM tournament_groups WHERE division_id = ? ORDER BY sort_order");
            $existingGroups->execute([(int)$divisionId]);
            $groups = $existingGroups->fetchAll(PDO::FETCH_ASSOC);

            while (count($groups) < $numGroups) {
                $letter = chr(65 + count($groups));
                $stmt = $db->prepare("INSERT INTO tournament_groups (division_id, name, sort_order) VALUES (?, ?, ?) RETURNING id, name");
                $stmt->execute([(int)$divisionId, "Group $letter", count($groups)]);
                $groups[] = $stmt->fetch(PDO::FETCH_ASSOC);
            }

            // Clear all group assignments in this division
            $db->prepare("UPDATE tournament_registrations SET group_id = NULL WHERE division_id = ? AND status = 'accepted'")->execute([(int)$divisionId]);

            // Snake seed: 1,4,5,8 go to A; 2,3,6,7 go to B (for 2 groups)
            $groupAssignments = array_fill(0, count($groups), []);

            foreach ($teams as $i => $team) {
                $round = intdiv($i, $numGroups);
                $pos = $i % $numGroups;
                // Snake: reverse direction on odd rounds
                $groupIdx = ($round % 2 === 0) ? $pos : ($numGroups - 1 - $pos);
                $groupAssignments[$groupIdx][] = $team['registration_id'];
            }

            // Apply assignments
            $resultGroups = [];
            foreach ($groups as $idx => $group) {
                $regIds = $groupAssignments[$idx] ?? [];
                if (!empty($regIds)) {
                    $placeholders = implode(',', array_fill(0, count($regIds), '?'));
                    $params = array_merge([(int)$group['id']], array_map('intval', $regIds));
                    $db->prepare("UPDATE tournament_registrations SET group_id = ? WHERE id IN ($placeholders)")->execute($params);
                }
                $resultGroups[] = [
                    'id' => (int)$group['id'],
                    'name' => $group['name'],
                    'registration_ids' => array_map('intval', $regIds),
                ];
            }

            echo json_encode(['success' => true, 'groups' => $resultGroups]);
            break;

        // ============================================
        // SCHEDULE / MATCHES
        // ============================================

        case 'generate-group-schedule':
            // POST ?action=generate-group-schedule&division_id={id}
            if ($method !== 'POST') { methodNotAllowed(); }
            requireAdmin($isAdmin);

            $divisionId = $_GET['division_id'] ?? null;
            if (!$divisionId) { http_response_code(400); echo json_encode(['error' => 'division_id is required']); exit(); }

            $divCheck = $db->prepare("SELECT tournament_id FROM tournament_divisions WHERE id = ?");
            $divCheck->execute([(int)$divisionId]);
            $divRow = $divCheck->fetch(PDO::FETCH_ASSOC);
            if (!$divRow) { http_response_code(404); echo json_encode(['error' => 'Division not found']); exit(); }
            verifyTournamentAccess($db, $auth, (int)$divRow['tournament_id']);

            $data = json_decode(file_get_contents('php://input'), true);

            require_once __DIR__ . '/../services/ScheduleGenerator.php';
            $generator = new ScheduleGenerator($db);

            try {
                $matches = $generator->generateRoundRobin((int)$divisionId, $data);
                echo json_encode(['success' => true, 'matches_created' => count($matches), 'matches' => $matches]);
            } catch (\Exception $e) {
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'matches-list':
            // GET ?action=matches-list&division_id={id}&group_id={id}&date={date}&round={round}
            if ($method !== 'GET') { methodNotAllowed(); }

            $divisionId = $_GET['division_id'] ?? null;
            if (!$divisionId) { http_response_code(400); echo json_encode(['error' => 'division_id is required']); exit(); }

            $sql = "SELECT m.*,
                        COALESCE(hr.team_name_override, ht.name) AS home_team_name,
                        COALESCE(ar.team_name_override, at.name) AS away_team_name,
                        f.name AS field_name,
                        tg.name AS group_name
                    FROM tournament_matches m
                    LEFT JOIN tournament_registrations hr ON hr.id = m.home_registration_id
                    LEFT JOIN teams ht ON ht.id = hr.team_id
                    LEFT JOIN tournament_registrations ar ON ar.id = m.away_registration_id
                    LEFT JOIN teams at ON at.id = ar.team_id
                    LEFT JOIN fields f ON f.id = m.field_id
                    LEFT JOIN tournament_groups tg ON tg.id = m.group_id
                    WHERE m.division_id = ?";
            $params = [(int)$divisionId];

            if (!empty($_GET['group_id'])) { $sql .= " AND m.group_id = ?"; $params[] = (int)$_GET['group_id']; }
            if (!empty($_GET['status'])) { $sql .= " AND m.status = ?"; $params[] = $_GET['status']; }
            if (!empty($_GET['round'])) { $sql .= " AND m.round = ?"; $params[] = $_GET['round']; }
            if (!empty($_GET['date'])) { $sql .= " AND DATE(m.scheduled_time) = ?"; $params[] = $_GET['date']; }

            $sql .= " ORDER BY m.match_number";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            echo json_encode(['matches' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'match-update':
            // PUT ?action=match-update&id={matchId}
            if ($method !== 'PUT') { methodNotAllowed(); }
            requireAdmin($isAdmin);

            $matchId = $_GET['id'] ?? null;
            if (!$matchId) { http_response_code(400); echo json_encode(['error' => 'id is required']); exit(); }

            $data = json_decode(file_get_contents('php://input'), true);

            $setClauses = [];
            $params = [];
            // Whitelist: scheduling fields + Match Center referee-report fields.
            // Optional text fields preserve empty strings as valid clears
            // ("the conditions textarea is now empty"); date/FK fields get
            // nullIfEmpty so blank inputs become NULL not ''.
            foreach ([
                'field_id', 'scheduled_time', 'scheduled_end_time',
                'notes', 'field_conditions', 'incident_report', 'match_card_photo_url',
            ] as $f) {
                if (array_key_exists($f, $data)) {
                    $setClauses[] = "$f = ?";
                    $params[] = in_array($f, ['field_id', 'scheduled_time', 'scheduled_end_time'], true)
                        ? nullIfEmpty($data[$f])
                        : $data[$f];
                }
            }
            if (empty($setClauses)) { http_response_code(400); echo json_encode(['error' => 'No fields to update']); exit(); }

            // Capture prior field/time so we only notify on actual schedule changes.
            // (Editing notes alone shouldn't broadcast a "rescheduled" alert.)
            $priorMatchStmt = $db->prepare("SELECT field_id, scheduled_time FROM tournament_matches WHERE id = ?");
            $priorMatchStmt->execute([(int)$matchId]);
            $priorMatch = $priorMatchStmt->fetch(PDO::FETCH_ASSOC) ?: ['field_id' => null, 'scheduled_time' => null];

            $setClauses[] = "updated_at = CURRENT_TIMESTAMP";
            $params[] = (int)$matchId;
            $db->prepare("UPDATE tournament_matches SET " . implode(', ', $setClauses) . " WHERE id = ?")->execute($params);

            $fieldChanged = array_key_exists('field_id', $data)
                && (string)($data['field_id'] ?? '') !== (string)($priorMatch['field_id'] ?? '');
            $timeChanged = array_key_exists('scheduled_time', $data)
                && (string)($data['scheduled_time'] ?? '') !== (string)($priorMatch['scheduled_time'] ?? '');

            if ($fieldChanged || $timeChanged) {
                $tournamentNotifications->notifyMatchRescheduled((int)$matchId, $userId);
            }

            echo json_encode(['success' => true]);
            break;

        // ============================================
        // SCORING / STANDINGS
        // ============================================

        case 'match-score':
            // PUT ?action=match-score&id={matchId}
            if ($method !== 'PUT') { methodNotAllowed(); }

            $matchId = $_GET['id'] ?? null;
            if (!$matchId) { http_response_code(400); echo json_encode(['error' => 'id is required']); exit(); }

            $data = json_decode(file_get_contents('php://input'), true);

            if (!isset($data['home_score']) || !isset($data['away_score'])) {
                http_response_code(400);
                echo json_encode(['error' => 'home_score and away_score are required']);
                exit();
            }

            $homeScore = (int)$data['home_score'];
            $awayScore = (int)$data['away_score'];

            if ($homeScore < 0 || $awayScore < 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Scores cannot be negative']);
                exit();
            }

            // Get match and verify access
            $matchStmt = $db->prepare("SELECT m.*, td.tournament_id FROM tournament_matches m JOIN tournament_divisions td ON td.id = m.division_id WHERE m.id = ?");
            $matchStmt->execute([(int)$matchId]);
            $match = $matchStmt->fetch(PDO::FETCH_ASSOC);
            if (!$match) { http_response_code(404); echo json_encode(['error' => 'Match not found']); exit(); }

            // Coach access check: must be coach of participating team
            if (!$isAdmin) {
                $teamCheck = $db->prepare("
                    SELECT 1 FROM tournament_registrations tr
                    JOIN team_members tm ON tm.team_id = tr.team_id AND tm.user_id = ?
                    WHERE tr.id IN (?, ?)
                    UNION
                    SELECT 1 FROM tournament_registrations tr
                    JOIN teams t ON t.id = tr.team_id AND t.primary_coach_id = ?
                    WHERE tr.id IN (?, ?)
                ");
                $teamCheck->execute([
                    $userId, $match['home_registration_id'], $match['away_registration_id'],
                    $userId, $match['home_registration_id'], $match['away_registration_id'],
                ]);
                if (!$teamCheck->fetch()) {
                    http_response_code(403);
                    echo json_encode(['error' => 'You can only score matches involving your team']);
                    exit();
                }
            }

            // Determine winner
            $winnerId = null;
            if ($homeScore > $awayScore) $winnerId = $match['home_registration_id'];
            elseif ($awayScore > $homeScore) $winnerId = $match['away_registration_id'];

            // Update match
            $db->prepare("
                UPDATE tournament_matches
                SET home_score = ?, away_score = ?, status = 'completed',
                    winner_registration_id = ?, scored_by = ?, scored_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ")->execute([$homeScore, $awayScore, $winnerId, $userId, (int)$matchId]);

            // Recalculate standings if group stage
            $standings = null;
            if ($match['group_id']) {
                require_once __DIR__ . '/../services/StandingsCalculator.php';
                $calc = new StandingsCalculator($db);
                $standings = $calc->recalculate((int)$match['group_id']);
            }

            // Score-posted notification (email + SMS) — fires once per match completion.
            // Re-scoring the same match will fire again, which is expected: a score
            // correction is news to the parents/players too.
            $tournamentNotifications->notifyScorePosted((int)$matchId, $userId);

            echo json_encode([
                'success' => true,
                'match' => [
                    'id' => (int)$matchId,
                    'home_score' => $homeScore,
                    'away_score' => $awayScore,
                    'status' => 'completed',
                    'winner_registration_id' => $winnerId ? (int)$winnerId : null,
                ],
                'standings' => $standings,
            ]);
            break;

        case 'standings-get':
            // GET ?action=standings-get&group_id={id}
            if ($method !== 'GET') { methodNotAllowed(); }

            $groupId = $_GET['group_id'] ?? null;
            if (!$groupId) { http_response_code(400); echo json_encode(['error' => 'group_id is required']); exit(); }

            $stmt = $db->prepare("
                SELECT ts.*, COALESCE(tr.team_name_override, t.name) AS team_name
                FROM tournament_standings ts
                JOIN tournament_registrations tr ON tr.id = ts.registration_id
                JOIN teams t ON t.id = tr.team_id
                WHERE ts.group_id = ?
                ORDER BY ts.position
            ");
            $stmt->execute([(int)$groupId]);
            echo json_encode(['standings' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        // ============================================
        // KNOCKOUT BRACKET
        // ============================================

        case 'generate-knockout-bracket':
            // POST ?action=generate-knockout-bracket&division_id={id}
            if ($method !== 'POST') { methodNotAllowed(); }
            requireAdmin($isAdmin);

            $divisionId = $_GET['division_id'] ?? null;
            if (!$divisionId) { http_response_code(400); echo json_encode(['error' => 'division_id is required']); exit(); }

            $divCheck = $db->prepare("SELECT tournament_id FROM tournament_divisions WHERE id = ?");
            $divCheck->execute([(int)$divisionId]);
            $divRow = $divCheck->fetch(PDO::FETCH_ASSOC);
            if (!$divRow) { http_response_code(404); echo json_encode(['error' => 'Division not found']); exit(); }
            verifyTournamentAccess($db, $auth, (int)$divRow['tournament_id']);

            $data = json_decode(file_get_contents('php://input'), true);

            require_once __DIR__ . '/../services/BracketGenerator.php';
            $generator = new BracketGenerator($db);

            try {
                $matches = $generator->generateBracket((int)$divisionId, $data);
                echo json_encode(['success' => true, 'matches_created' => count($matches), 'matches' => $matches]);
            } catch (\Exception $e) {
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'match-score-knockout':
            // PUT ?action=match-score-knockout&id={matchId}
            if ($method !== 'PUT') { methodNotAllowed(); }

            $matchId = $_GET['id'] ?? null;
            if (!$matchId) { http_response_code(400); echo json_encode(['error' => 'id is required']); exit(); }

            $data = json_decode(file_get_contents('php://input'), true);

            if (!isset($data['home_score']) || !isset($data['away_score'])) {
                http_response_code(400);
                echo json_encode(['error' => 'home_score and away_score are required']);
                exit();
            }

            $homeScore = (int)$data['home_score'];
            $awayScore = (int)$data['away_score'];
            $homePK = isset($data['home_penalty_score']) ? (int)$data['home_penalty_score'] : null;
            $awayPK = isset($data['away_penalty_score']) ? (int)$data['away_penalty_score'] : null;

            if ($homeScore < 0 || $awayScore < 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Scores cannot be negative']);
                exit();
            }

            // Draw without penalties is invalid in knockout
            if ($homeScore === $awayScore && ($homePK === null || $awayPK === null)) {
                http_response_code(400);
                echo json_encode(['error' => 'Knockout matches cannot end in a draw. Provide penalty scores.']);
                exit();
            }
            if ($homePK !== null && $awayPK !== null && $homePK === $awayPK) {
                http_response_code(400);
                echo json_encode(['error' => 'Penalty scores cannot be equal']);
                exit();
            }

            // Get match
            $matchStmt = $db->prepare("SELECT * FROM tournament_matches WHERE id = ?");
            $matchStmt->execute([(int)$matchId]);
            $match = $matchStmt->fetch(PDO::FETCH_ASSOC);
            if (!$match) { http_response_code(404); echo json_encode(['error' => 'Match not found']); exit(); }

            // Determine winner
            if ($homeScore > $awayScore) {
                $winnerId = $match['home_registration_id'];
            } elseif ($awayScore > $homeScore) {
                $winnerId = $match['away_registration_id'];
            } else {
                // Penalties
                $winnerId = $homePK > $awayPK ? $match['home_registration_id'] : $match['away_registration_id'];
            }

            $db->prepare("
                UPDATE tournament_matches
                SET home_score = ?, away_score = ?,
                    home_penalty_score = ?, away_penalty_score = ?,
                    status = 'completed', winner_registration_id = ?,
                    scored_by = ?, scored_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ")->execute([$homeScore, $awayScore, $homePK, $awayPK, $winnerId, $userId, (int)$matchId]);

            // Advance winner to next round
            require_once __DIR__ . '/../services/BracketGenerator.php';
            $bracketGen = new BracketGenerator($db);
            $advancedTo = $bracketGen->advanceWinner((int)$matchId);

            // Score-posted notification (email + SMS), same as group-stage match-score.
            $tournamentNotifications->notifyScorePosted((int)$matchId, $userId);

            echo json_encode([
                'success' => true,
                'match' => [
                    'id' => (int)$matchId,
                    'home_score' => $homeScore,
                    'away_score' => $awayScore,
                    'home_penalty_score' => $homePK,
                    'away_penalty_score' => $awayPK,
                    'status' => 'completed',
                    'winner_registration_id' => (int)$winnerId,
                ],
                'advanced_to_match_ids' => $advancedTo,
            ]);
            break;

        // ============================================
        // MATCH EVENTS — yellow/red cards, goals, etc.
        // ============================================

        case 'match-events-list':
            // GET ?action=match-events-list&match_id={id}
            if ($method !== 'GET') { methodNotAllowed(); }

            $matchId = $_GET['match_id'] ?? null;
            if (!$matchId) { http_response_code(400); echo json_encode(['error' => 'match_id is required']); exit(); }

            $stmt = $db->prepare("
                SELECT e.id, e.match_id, e.registration_id, e.event_type, e.minute,
                       e.athlete_id, e.details, e.created_at,
                       COALESCE(NULLIF(r.team_name_override, ''), t.name) AS team_name,
                       COALESCE(a.first_name || ' ' || a.last_name, '') AS athlete_name,
                       COALESCE(NULLIF(e.details->>'player_name', ''), '') AS free_text_player
                FROM tournament_match_events e
                LEFT JOIN tournament_registrations r ON e.registration_id = r.id
                LEFT JOIN teams t ON r.team_id = t.id
                LEFT JOIN athletes a ON e.athlete_id = a.id
                WHERE e.match_id = ?
                ORDER BY e.minute NULLS LAST, e.id
            ");
            $stmt->execute([(int)$matchId]);
            $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Decode JSONB details for client
            foreach ($events as &$evt) {
                if (is_string($evt['details'])) {
                    $evt['details'] = json_decode($evt['details'], true);
                }
            }
            echo json_encode(['events' => $events]);
            break;

        case 'match-event-add':
            // POST ?action=match-event-add&match_id={id}
            if ($method !== 'POST') { methodNotAllowed(); }

            $matchId = $_GET['match_id'] ?? null;
            if (!$matchId) { http_response_code(400); echo json_encode(['error' => 'match_id is required']); exit(); }

            $data = json_decode(file_get_contents('php://input'), true);
            $validTypes = [
                'goal','own_goal','penalty_goal','missed_penalty',
                'yellow_card','red_card','second_yellow',
                'substitution_in','substitution_out','injury',
            ];
            $eventType = $data['event_type'] ?? null;
            $registrationId = $data['registration_id'] ?? null;
            if (!$eventType || !in_array($eventType, $validTypes, true)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid event_type. Allowed: ' . implode(', ', $validTypes)]);
                exit();
            }
            if (!$registrationId) {
                http_response_code(400);
                echo json_encode(['error' => 'registration_id is required (which team committed the event)']);
                exit();
            }

            // Free-text player name lives in details.player_name when athlete_id
            // can't be selected from a roster (e.g., guest team or rapid entry).
            $details = [];
            if (!empty($data['player_name'])) {
                $details['player_name'] = (string)$data['player_name'];
            }
            if (!empty($data['notes'])) {
                $details['notes'] = (string)$data['notes'];
            }

            $stmt = $db->prepare("
                INSERT INTO tournament_match_events
                    (match_id, registration_id, event_type, minute, athlete_id, details, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?::jsonb, ?, CURRENT_TIMESTAMP)
                RETURNING id
            ");
            $stmt->execute([
                (int)$matchId,
                (int)$registrationId,
                $eventType,
                nullIfEmpty($data['minute'] ?? null),
                nullIfEmpty($data['athlete_id'] ?? null),
                empty($details) ? null : json_encode($details),
                $userId,
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            http_response_code(201);
            echo json_encode(['id' => (int)$row['id']]);
            break;

        case 'match-event-delete':
            // DELETE ?action=match-event-delete&id={eventId}
            if ($method !== 'DELETE') { methodNotAllowed(); }

            $eventId = $_GET['id'] ?? null;
            if (!$eventId) { http_response_code(400); echo json_encode(['error' => 'id is required']); exit(); }

            $stmt = $db->prepare("DELETE FROM tournament_match_events WHERE id = ?");
            $stmt->execute([(int)$eventId]);
            echo json_encode(['success' => true, 'deleted' => $stmt->rowCount()]);
            break;

        case 'tournament-disciplinary-list':
            // GET ?action=tournament-disciplinary-list&tournament_id={id}
            // Returns all yellow/red/second-yellow events across the tournament
            // plus a per-player accumulation summary so directors can see who
            // is approaching or has crossed the suspension threshold.
            if ($method !== 'GET') { methodNotAllowed(); }

            $tournamentId = $_GET['tournament_id'] ?? null;
            if (!$tournamentId) { http_response_code(400); echo json_encode(['error' => 'tournament_id is required']); exit(); }

            verifyTournamentAccess($db, $auth, (int)$tournamentId);

            $stmt = $db->prepare("
                SELECT e.id, e.match_id, e.registration_id, e.event_type, e.minute, e.athlete_id,
                       e.details, e.created_at,
                       m.match_number, m.round,
                       d.id   AS division_id,
                       d.name AS division_name,
                       d.age_group,
                       COALESCE(NULLIF(r.team_name_override, ''), t.name) AS team_name,
                       COALESCE(a.first_name || ' ' || a.last_name, '')   AS athlete_name,
                       COALESCE(NULLIF(rh.team_name_override, ''), th.name, m.home_placeholder, '') AS home_team,
                       COALESCE(NULLIF(ra.team_name_override, ''), ta.name, m.away_placeholder, '') AS away_team
                FROM tournament_match_events e
                JOIN tournament_matches m ON e.match_id = m.id
                JOIN tournament_divisions d ON m.division_id = d.id
                LEFT JOIN tournament_registrations r  ON e.registration_id = r.id
                LEFT JOIN teams t   ON r.team_id = t.id
                LEFT JOIN athletes a ON e.athlete_id = a.id
                LEFT JOIN tournament_registrations rh ON m.home_registration_id = rh.id
                LEFT JOIN teams th ON rh.team_id = th.id
                LEFT JOIN tournament_registrations ra ON m.away_registration_id = ra.id
                LEFT JOIN teams ta ON ra.team_id = ta.id
                WHERE d.tournament_id = ?
                  AND e.event_type IN ('yellow_card', 'red_card', 'second_yellow')
                ORDER BY e.created_at DESC, e.id DESC
            ");
            $stmt->execute([(int)$tournamentId]);
            $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Decode JSONB details + extract player_name fallback
            foreach ($events as &$evt) {
                if (is_string($evt['details'])) {
                    $evt['details'] = json_decode($evt['details'], true);
                }
                $evt['player_label'] = $evt['athlete_name'] !== ''
                    ? $evt['athlete_name']
                    : ($evt['details']['player_name'] ?? 'Unknown player');
            }
            unset($evt);

            // Per-player accumulation summary keyed by (team_name, player_label)
            // Suspension thresholds (per spec): 2 yellow_card → 1 match suspension,
            // any red_card → 1+ match suspension. We compute the boolean flag here
            // so the UI can simply render an "auto-suspended" badge without
            // re-running the math.
            $accumulation = [];
            foreach ($events as $evt) {
                $key = ($evt['team_name'] ?? '?') . '||' . $evt['player_label'];
                if (!isset($accumulation[$key])) {
                    $accumulation[$key] = [
                        'team_name'        => $evt['team_name'] ?? '?',
                        'division_name'    => $evt['division_name'],
                        'player_label'     => $evt['player_label'],
                        'yellow_count'     => 0,
                        'red_count'        => 0,
                        'second_yellow_count' => 0,
                    ];
                }
                if ($evt['event_type'] === 'yellow_card')   $accumulation[$key]['yellow_count']++;
                if ($evt['event_type'] === 'red_card')      $accumulation[$key]['red_count']++;
                if ($evt['event_type'] === 'second_yellow') $accumulation[$key]['second_yellow_count']++;
            }
            // Flatten + flag suspension status
            $accumulationList = array_values(array_map(function ($entry) {
                $entry['suspended'] = ($entry['yellow_count'] >= 2)
                                   || ($entry['red_count'] >= 1)
                                   || ($entry['second_yellow_count'] >= 1);
                return $entry;
            }, $accumulation));

            // Sort suspended-first, then by yellow_count desc
            usort($accumulationList, function ($a, $b) {
                if ($a['suspended'] !== $b['suspended']) {
                    return $b['suspended'] <=> $a['suspended'];
                }
                return ($b['yellow_count'] + $b['red_count'] + $b['second_yellow_count'])
                     - ($a['yellow_count'] + $a['red_count'] + $a['second_yellow_count']);
            });

            echo json_encode([
                'events'       => $events,
                'accumulation' => $accumulationList,
                'totals' => [
                    'cards'            => count($events),
                    'yellows'          => count(array_filter($events, fn($e) => $e['event_type'] === 'yellow_card')),
                    'reds'             => count(array_filter($events, fn($e) => $e['event_type'] === 'red_card')),
                    'second_yellows'   => count(array_filter($events, fn($e) => $e['event_type'] === 'second_yellow')),
                    'suspended_players'=> count(array_filter($accumulationList, fn($p) => $p['suspended'])),
                ],
            ]);
            break;

        case 'slot-group-winners':
            // POST ?action=slot-group-winners&division_id={id}
            if ($method !== 'POST') { methodNotAllowed(); }
            requireAdmin($isAdmin);

            $divisionId = $_GET['division_id'] ?? null;
            if (!$divisionId) { http_response_code(400); echo json_encode(['error' => 'division_id is required']); exit(); }

            require_once __DIR__ . '/../services/BracketGenerator.php';
            $bracketGen = new BracketGenerator($db);
            $slotted = $bracketGen->slotGroupWinners((int)$divisionId);

            echo json_encode(['success' => true, 'teams_slotted' => $slotted]);
            break;

        // ============================================
        // CHECK-IN
        // ============================================

        case 'checkin-roster':
            // GET ?action=checkin-roster&match_id={id}&registration_id={regId}
            if ($method !== 'GET') { methodNotAllowed(); }

            $matchId = $_GET['match_id'] ?? null;
            $regId = $_GET['registration_id'] ?? null;
            if (!$matchId || !$regId) {
                http_response_code(400);
                echo json_encode(['error' => 'match_id and registration_id are required']);
                exit();
            }

            // Get team info
            $regStmt = $db->prepare("
                SELECT tr.team_id, COALESCE(tr.team_name_override, t.name) AS team_name,
                       td.min_roster_size
                FROM tournament_registrations tr
                JOIN teams t ON t.id = tr.team_id
                JOIN tournament_divisions td ON td.id = tr.division_id
                WHERE tr.id = ?
            ");
            $regStmt->execute([(int)$regId]);
            $regData = $regStmt->fetch(PDO::FETCH_ASSOC);
            if (!$regData) { http_response_code(404); echo json_encode(['error' => 'Registration not found']); exit(); }

            // Get roster from team_members + athletes
            $rosterStmt = $db->prepare("
                SELECT a.id AS athlete_id, a.first_name, a.last_name, a.date_of_birth, a.photo_url,
                       tm.jersey_number, tm.primary_position AS position,
                       EXTRACT(YEAR FROM AGE(a.date_of_birth))::int AS age
                FROM team_members tm
                JOIN athletes a ON a.id = tm.athlete_id
                WHERE tm.team_id = ? AND tm.status = 'active' AND tm.role = 'player'
                ORDER BY tm.jersey_number::int NULLS LAST, a.last_name
            ");
            $rosterStmt->execute([(int)$regData['team_id']]);
            $roster = $rosterStmt->fetchAll(PDO::FETCH_ASSOC);

            // Get existing check-ins for this match+registration
            $checkinStmt = $db->prepare("
                SELECT athlete_id, checked_in FROM tournament_match_checkins
                WHERE match_id = ? AND registration_id = ?
            ");
            $checkinStmt->execute([(int)$matchId, (int)$regId]);
            $checkins = [];
            foreach ($checkinStmt->fetchAll(PDO::FETCH_ASSOC) as $ci) {
                $checkins[(int)$ci['athlete_id']] = $ci['checked_in'] === true || $ci['checked_in'] === 't';
            }

            // Merge check-in status into roster
            $checkedInCount = 0;
            foreach ($roster as &$player) {
                $player['checked_in'] = $checkins[(int)$player['athlete_id']] ?? false;
                if ($player['checked_in']) $checkedInCount++;
            }

            echo json_encode([
                'team_name' => $regData['team_name'],
                'roster' => $roster,
                'checked_in_count' => $checkedInCount,
                'roster_count' => count($roster),
                'min_roster_size' => (int)$regData['min_roster_size'],
            ]);
            break;

        case 'checkin-player':
            // PUT ?action=checkin-player&match_id={id}&athlete_id={id}
            if ($method !== 'PUT') { methodNotAllowed(); }

            $matchId = $_GET['match_id'] ?? null;
            $athleteId = $_GET['athlete_id'] ?? null;
            if (!$matchId || !$athleteId) {
                http_response_code(400);
                echo json_encode(['error' => 'match_id and athlete_id are required']);
                exit();
            }

            $data = json_decode(file_get_contents('php://input'), true);
            $regId = $data['registration_id'] ?? null;
            $checkedIn = $data['checked_in'] ?? true;

            if (!$regId) {
                http_response_code(400);
                echo json_encode(['error' => 'registration_id is required in body']);
                exit();
            }

            $db->prepare("
                INSERT INTO tournament_match_checkins (match_id, registration_id, athlete_id, checked_in, checked_in_by, checked_in_at)
                VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                ON CONFLICT (match_id, registration_id, athlete_id) DO UPDATE SET
                    checked_in = EXCLUDED.checked_in,
                    checked_in_by = EXCLUDED.checked_in_by,
                    checked_in_at = CURRENT_TIMESTAMP
            ")->execute([(int)$matchId, (int)$regId, (int)$athleteId, $checkedIn ? 'true' : 'false', $userId]);

            echo json_encode(['success' => true, 'checked_in' => $checkedIn]);
            break;

        case 'checkin-status':
            // GET ?action=checkin-status&match_id={id}
            if ($method !== 'GET') { methodNotAllowed(); }

            $matchId = $_GET['match_id'] ?? null;
            if (!$matchId) { http_response_code(400); echo json_encode(['error' => 'match_id required']); exit(); }

            $stmt = $db->prepare("
                SELECT m.home_registration_id, m.away_registration_id,
                       COALESCE(hr.team_name_override, ht.name) AS home_team,
                       COALESCE(ar.team_name_override, at2.name) AS away_team
                FROM tournament_matches m
                LEFT JOIN tournament_registrations hr ON hr.id = m.home_registration_id
                LEFT JOIN teams ht ON ht.id = hr.team_id
                LEFT JOIN tournament_registrations ar ON ar.id = m.away_registration_id
                LEFT JOIN teams at2 ON at2.id = ar.team_id
                WHERE m.id = ?
            ");
            $stmt->execute([(int)$matchId]);
            $match = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$match) { http_response_code(404); echo json_encode(['error' => 'Match not found']); exit(); }

            // Count check-ins per side
            $countStmt = $db->prepare("
                SELECT registration_id, COUNT(*) FILTER (WHERE checked_in = true) AS checked_in_count,
                       COUNT(*) AS total
                FROM tournament_match_checkins WHERE match_id = ? GROUP BY registration_id
            ");
            $countStmt->execute([(int)$matchId]);
            $counts = [];
            foreach ($countStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $counts[(int)$row['registration_id']] = [
                    'checked_in' => (int)$row['checked_in_count'],
                    'total' => (int)$row['total'],
                ];
            }

            echo json_encode([
                'home' => [
                    'registration_id' => $match['home_registration_id'] ? (int)$match['home_registration_id'] : null,
                    'team_name' => $match['home_team'],
                    'checkin' => $counts[(int)$match['home_registration_id']] ?? ['checked_in' => 0, 'total' => 0],
                ],
                'away' => [
                    'registration_id' => $match['away_registration_id'] ? (int)$match['away_registration_id'] : null,
                    'team_name' => $match['away_team'],
                    'checkin' => $counts[(int)$match['away_registration_id']] ?? ['checked_in' => 0, 'total' => 0],
                ],
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action: ' . $action]);
    }
} catch (Exception $e) {
    error_log("Tournament gateway error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}

// ============================================
// HELPER FUNCTIONS
// ============================================

function requireAdmin($isAdmin) {
    if (!$isAdmin) {
        http_response_code(403);
        echo json_encode(['error' => 'Club admin access required']);
        exit();
    }
}

/**
 * Treat empty strings the same as null for optional date/time/timestamp fields.
 * The frontend sends empty strings when a date/time input is left blank, but
 * Postgres rejects '' as a TIMESTAMP/DATE/TIME value (SQLSTATE 22007).
 */
function nullIfEmpty($v) {
    if ($v === null) return null;
    if (is_string($v) && trim($v) === '') return null;
    return $v;
}

function methodNotAllowed() {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

function verifyTournamentAccess($db, $auth, int $tournamentId) {
    $stmt = $db->prepare("SELECT club_id FROM tournaments WHERE id = ?");
    $stmt->execute([$tournamentId]);
    $tournament = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tournament) {
        http_response_code(404);
        echo json_encode(['error' => 'Tournament not found']);
        exit();
    }

    $accessibleClubs = $auth->getAccessibleClubIds();
    if ($accessibleClubs !== null && !in_array((int)$tournament['club_id'], $accessibleClubs)) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        exit();
    }

    return $tournament;
}
