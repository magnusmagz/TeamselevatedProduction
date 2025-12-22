<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Use centralized database connection
require_once __DIR__ . '/../config/database.php';

try {
    $db = Database::getInstance();
    $connection = $db->getConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$team_id = $_GET['id'] ?? null;

// Get query parameters for filtering
$search = $_GET['search'] ?? '';
$season_id = $_GET['season_id'] ?? '';
$age_group = $_GET['age_group'] ?? '';
$division = $_GET['division'] ?? '';
$primary_coach_id = $_GET['primary_coach_id'] ?? '';

try {
    switch ($method) {
        case 'GET':
            if ($team_id) {
                // Get specific team
                $stmt = $connection->prepare("
                    SELECT t.*,
                           s.name as season_name,
                           CONCAT(u.first_name, ' ', u.last_name) as coach_name,
                           COUNT(DISTINCT tm.id) as player_count
                    FROM teams t
                    LEFT JOIN seasons s ON t.season_id = s.id
                    LEFT JOIN users u ON t.primary_coach_id = u.id
                    LEFT JOIN team_members tm ON t.id = tm.team_id
                    WHERE t.id = ?
                    GROUP BY t.id, t.name, t.program_id, t.season_id, t.primary_coach_id, t.division,
                             t.skill_level, t.age_group, t.gender, t.max_players, t.team_color,
                             t.logo_url, t.status, t.created_at, t.updated_at, s.name, u.first_name, u.last_name
                ");
                $stmt->execute([$team_id]);
                $team = $stmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode($team);
            } else {
                // Get all teams with filters
                $query = "
                    SELECT t.*,
                           s.name as season_name,
                           CONCAT(u.first_name, ' ', u.last_name) as coach_name,
                           COUNT(DISTINCT tm.id) as player_count
                    FROM teams t
                    LEFT JOIN seasons s ON t.season_id = s.id
                    LEFT JOIN users u ON t.primary_coach_id = u.id
                    LEFT JOIN team_members tm ON t.id = tm.team_id
                    WHERE 1=1
                ";

                $params = [];

                if ($search) {
                    $query .= " AND t.name LIKE ?";
                    $params[] = "%$search%";
                }

                if ($season_id) {
                    // Support both season_id and program_id for backward compatibility
                    $query .= " AND (t.season_id = ? OR t.program_id = ?)";
                    $params[] = $season_id;
                    $params[] = $season_id;
                }

                if ($age_group) {
                    $query .= " AND t.age_group = ?";
                    $params[] = $age_group;
                }

                if ($division) {
                    $query .= " AND t.division = ?";
                    $params[] = $division;
                }

                if ($primary_coach_id) {
                    $query .= " AND t.primary_coach_id = ?";
                    $params[] = $primary_coach_id;
                }

                $query .= " GROUP BY t.id, t.name, t.program_id, t.season_id, t.primary_coach_id, t.division,
                                     t.skill_level, t.age_group, t.gender, t.max_players, t.team_color,
                                     t.logo_url, t.status, t.created_at, t.updated_at, s.name, u.first_name, u.last_name
                            ORDER BY t.created_at DESC";

                $stmt = $connection->prepare($query);
                $stmt->execute($params);
                $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode(['teams' => $teams]);
            }
            break;

        case 'POST':
            $data = json_decode(file_get_contents("php://input"), true);

            // program_id is optional, defaults to null
            $program_id = $data['program_id'] ?? null;

            $stmt = $connection->prepare("
                INSERT INTO teams (name, program_id, season_id, primary_coach_id, age_group, division,
                                 max_players, team_color, logo_url, skill_level, gender, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $data['name'],
                $program_id,
                isset($data['season_id']) && $data['season_id'] ? $data['season_id'] : null,
                isset($data['primary_coach_id']) && $data['primary_coach_id'] ? $data['primary_coach_id'] : null,
                $data['age_group'] ?? null,
                $data['division'] ?? null,
                $data['max_players'] ?? 20,
                $data['team_color'] ?? '#3b82f6',
                $data['logo_url'] ?? null,
                $data['skill_level'] ?? 'Beginner',
                $data['gender'] ?? 'Mixed',
                $data['status'] ?? 'forming'
            ]);

            echo json_encode([
                'success' => true,
                'id' => $connection->lastInsertId(),
                'message' => 'Team created successfully'
            ]);
            break;

        case 'PUT':
            if (!$team_id) {
                throw new Exception('Team ID required for update');
            }

            $data = json_decode(file_get_contents("php://input"), true);

            $stmt = $connection->prepare("
                UPDATE teams
                SET name = ?, age_group = ?, division = ?, season_id = ?, primary_coach_id = ?,
                    max_players = ?, team_color = ?, logo_url = ?, skill_level = ?, gender = ?, status = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $data['name'],
                $data['age_group'] ?? null,
                $data['division'] ?? null,
                isset($data['season_id']) && $data['season_id'] ? $data['season_id'] : null,
                isset($data['primary_coach_id']) && $data['primary_coach_id'] ? $data['primary_coach_id'] : null,
                $data['max_players'] ?? 20,
                $data['team_color'] ?? '#3b82f6',
                $data['logo_url'] ?? null,
                $data['skill_level'] ?? 'Beginner',
                $data['gender'] ?? 'Mixed',
                $data['status'] ?? 'forming',
                $team_id
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Team updated successfully'
            ]);
            break;

        case 'DELETE':
            if (!$team_id) {
                throw new Exception('Team ID required for deletion');
            }

            $stmt = $connection->prepare("DELETE FROM teams WHERE id = ?");
            $stmt->execute([$team_id]);

            echo json_encode([
                'success' => true,
                'message' => 'Team deleted successfully'
            ]);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>