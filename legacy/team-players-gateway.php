<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Use centralized database connection
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/AthleteScope.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

/**
 * Authenticate the caller from the Authorization header.
 * Returns a validated AuthMiddleware instance or exits 401.
 */
function tpg_requireAuth() {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $authHeader = $headers['Authorization'] ?? $headers['authorization']
        ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

    if (!$authHeader || !preg_match('/Bearer\s+(.*)$/i', $authHeader, $m)) {
        http_response_code(401);
        echo json_encode(['error' => 'No authorization token provided']);
        exit;
    }

    $auth = new AuthMiddleware();
    if (!$auth->validateToken($m[1])) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid or expired token']);
        exit;
    }
    return $auth;
}

/**
 * Verify the authenticated user may manage the given team's roster.
 * Allowed: super_admin, club_admin of the team's club, or a coach of the team
 * (primary_coach_id OR active assistant_coach/team_manager team_members row).
 * Exits 403 if not authorized, 404 if the team does not exist.
 */
function tpg_requireTeamAccess($pdo, $auth, $teamId) {
    $teamId = (int)$teamId;

    $stmt = $pdo->prepare("SELECT club_id FROM teams WHERE id = ?");
    $stmt->execute([$teamId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => 'Team not found']);
        exit;
    }

    $clubId = $row['club_id'] !== null ? (int)$row['club_id'] : null;

    if ($auth->isSuperAdmin()) {
        return $teamId;
    }
    if ($clubId !== null && $auth->hasRole('club_admin', $clubId, 'club')) {
        return $teamId;
    }

    $userId = (int)$auth->getUserId();
    $coachTeamIds = AthleteScope::coachTeamIdsForUser($pdo, $userId);
    if (in_array($teamId, $coachTeamIds, true)) {
        return $teamId;
    }

    http_response_code(403);
    echo json_encode(['error' => 'You do not have permission to edit this team\'s roster']);
    exit;
}

/**
 * Same check as tpg_requireTeamAccess, resolved from a team_members row id.
 * Exits 404 if the team_member row is missing.
 */
function tpg_requireTeamRosterAccess($pdo, $auth, $teamMemberId) {
    $stmt = $pdo->prepare("SELECT team_id FROM team_members WHERE id = ?");
    $stmt->execute([$teamMemberId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => 'Team member not found']);
        exit;
    }

    return tpg_requireTeamAccess($pdo, $auth, (int)$row['team_id']);
}

try {
    switch ($method) {
        case 'GET':
            // Get team players
            $team_id = isset($_GET['team_id']) ? (int)$_GET['team_id'] : null;

            if ($team_id) {
                $stmt = $pdo->prepare("
                    SELECT tp.*,
                           COALESCE(a.first_name, u.first_name) as first_name,
                           COALESCE(a.last_name, u.last_name) as last_name,
                           COALESCE(a.email, u.email) as email,
                           a.date_of_birth,
                           a.gender,
                           a.school_name,
                           a.grade_level,
                           a.photo_url
                    FROM team_members tp
                    LEFT JOIN athletes a ON tp.athlete_id = a.id
                    LEFT JOIN users u ON tp.user_id = u.id
                    WHERE tp.team_id = ?
                    ORDER BY COALESCE(a.last_name, u.last_name), COALESCE(a.first_name, u.first_name)
                ");
                $stmt->execute([$team_id]);
                $team_members = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode(['success' => true, 'team_members' => $team_members]);
            } else {
                // Get all team players
                $stmt = $pdo->prepare("
                    SELECT tp.*,
                           COALESCE(a.first_name, u.first_name) as first_name,
                           COALESCE(a.last_name, u.last_name) as last_name,
                           COALESCE(a.email, u.email) as email,
                           COALESCE(a.id, u.id) as member_id,
                           t.name as team_name
                    FROM team_members tp
                    LEFT JOIN athletes a ON tp.athlete_id = a.id
                    LEFT JOIN users u ON tp.user_id = u.id
                    JOIN teams t ON tp.team_id = t.id
                    ORDER BY t.name, COALESCE(a.last_name, u.last_name), COALESCE(a.first_name, u.first_name)
                ");
                $stmt->execute();
                $team_members = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode(['success' => true, 'team_players' => $team_members]);
            }
            break;

        case 'POST':
            // Add player to team — requires auth + team-roster scope.
            $auth = tpg_requireAuth();

            $input = json_decode(file_get_contents('php://input'), true);

            $team_id = $input['team_id'] ?? null;
            $athlete_id = $input['player_id'] ?? null;

            if (!$team_id || !$athlete_id) {
                throw new Exception('Team ID and Athlete ID are required');
            }

            // Verify the caller coaches/administers the target team.
            tpg_requireTeamAccess($pdo, $auth, $team_id);

            // Check if player is already on the team
            $stmt = $pdo->prepare("SELECT id FROM team_members WHERE team_id = ? AND athlete_id = ?");
            $stmt->execute([$team_id, $athlete_id]);

            if ($stmt->fetch()) {
                throw new Exception('Player is already on this team');
            }

            $stmt = $pdo->prepare("
                INSERT INTO team_members (team_id, athlete_id, join_date)
                VALUES (?, ?, CURRENT_DATE)
            ");
            $stmt->execute([$team_id, $athlete_id]);

            echo json_encode(['success' => true, 'message' => 'Player added to team successfully']);
            break;

        case 'PUT':
            // Update team player — requires auth + team-roster scope.
            $auth = tpg_requireAuth();

            $input = json_decode(file_get_contents('php://input'), true);
            if (!is_array($input)) {
                $input = [];
            }
            $id = isset($_GET['id']) ? (int)$_GET['id'] : ($input['team_member_id'] ?? null);
            $id = $id !== null ? (int)$id : null;

            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'Team player ID is required']);
                break;
            }

            // Verify the caller coaches/administers the team this row belongs to.
            tpg_requireTeamRosterAccess($pdo, $auth, $id);

            $fields = [];
            $values = [];

            if (array_key_exists('status', $input)) {
                $allowedStatuses = ['active', 'injured', 'suspended', 'inactive'];
                if (!in_array($input['status'], $allowedStatuses, true)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid status. Allowed: ' . implode(', ', $allowedStatuses)]);
                    break;
                }
                $fields[] = 'status = ?';
                $values[] = $input['status'];
            }
            if (array_key_exists('jersey_number', $input)) {
                $fields[] = 'jersey_number = ?';
                // Normalize empty string to NULL; cast numeric values to int.
                $jersey = $input['jersey_number'];
                $values[] = ($jersey === '' || $jersey === null) ? null : (int)$jersey;
            }
            if (array_key_exists('primary_position', $input)) {
                $fields[] = 'primary_position = ?';
                $values[] = ($input['primary_position'] === '') ? null : $input['primary_position'];
            }
            if (array_key_exists('positions', $input)) {
                $fields[] = 'positions = ?';
                $values[] = json_encode($input['positions']);
            }

            // No recognized fields => this is a no-op. Surface it instead of
            // returning a misleading success (the original bug: empty $fields
            // silently reported success without running any UPDATE).
            if (empty($fields)) {
                http_response_code(400);
                echo json_encode(['error' => 'No updatable fields provided']);
                break;
            }

            $values[] = $id;
            $stmt = $pdo->prepare("UPDATE team_members SET " . implode(', ', $fields) . " WHERE id = ?");
            $stmt->execute($values);

            echo json_encode([
                'success' => true,
                'message' => 'Team player updated successfully',
                'rows_affected' => $stmt->rowCount(),
            ]);
            break;

        case 'DELETE':
            // Remove player from team — requires auth + team-roster scope.
            $auth = tpg_requireAuth();

            $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
            $team_id = isset($_GET['team_id']) ? (int)$_GET['team_id'] : null;
            $athlete_id = isset($_GET['player_id']) ? (int)$_GET['player_id'] : null;

            if ($id) {
                tpg_requireTeamRosterAccess($pdo, $auth, $id);
                $stmt = $pdo->prepare("DELETE FROM team_members WHERE id = ?");
                $stmt->execute([$id]);
            } elseif ($team_id && $athlete_id) {
                tpg_requireTeamAccess($pdo, $auth, $team_id);
                $stmt = $pdo->prepare("DELETE FROM team_members WHERE team_id = ? AND athlete_id = ?");
                $stmt->execute([$team_id, $athlete_id]);
            } else {
                throw new Exception('Either team player ID or both team ID and athlete ID are required');
            }

            echo json_encode(['success' => true, 'message' => 'Player removed from team successfully']);
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
?>