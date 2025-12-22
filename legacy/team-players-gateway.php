<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Use centralized database connection
require_once __DIR__ . '/../config/database.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

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
                           COALESCE(a.email, u.email) as email
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
            // Add player to team
            $input = json_decode(file_get_contents('php://input'), true);

            $team_id = $input['team_id'] ?? null;
            $athlete_id = $input['player_id'] ?? null;

            if (!$team_id || !$athlete_id) {
                throw new Exception('Team ID and Athlete ID are required');
            }

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
            // Update team player
            $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
            $input = json_decode(file_get_contents('php://input'), true);

            if (!$id) {
                throw new Exception('Team player ID is required');
            }

            $status = $input['status'] ?? null;

            if ($status) {
                $stmt = $pdo->prepare("UPDATE team_members SET status = ? WHERE id = ?");
                $stmt->execute([$status, $id]);
            }

            echo json_encode(['success' => true, 'message' => 'Team player updated successfully']);
            break;

        case 'DELETE':
            // Remove player from team
            $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
            $team_id = isset($_GET['team_id']) ? (int)$_GET['team_id'] : null;
            $athlete_id = isset($_GET['player_id']) ? (int)$_GET['player_id'] : null;

            if ($id) {
                $stmt = $pdo->prepare("DELETE FROM team_members WHERE id = ?");
                $stmt->execute([$id]);
            } elseif ($team_id && $athlete_id) {
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