<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Direct database connection
try {
    $connection = new PDO(
        "mysql:unix_socket=/Applications/MAMP/tmp/mysql/mysql.sock;dbname=teams_elevated;charset=utf8mb4",
        "root",
        "root",
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
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
                           f.name as home_field_name,
                           COUNT(DISTINCT tp.user_id) as player_count
                    FROM teams t
                    LEFT JOIN seasons s ON t.season_id = s.id
                    LEFT JOIN users u ON t.primary_coach_id = u.id
                    LEFT JOIN fields f ON t.home_field_id = f.id
                    LEFT JOIN team_players tp ON t.id = tp.team_id
                    WHERE t.id = ?
                    GROUP BY t.id
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
                           f.name as home_field_name,
                           COUNT(DISTINCT tp.user_id) as player_count
                    FROM teams t
                    LEFT JOIN seasons s ON t.season_id = s.id
                    LEFT JOIN users u ON t.primary_coach_id = u.id
                    LEFT JOIN fields f ON t.home_field_id = f.id
                    LEFT JOIN team_players tp ON t.id = tp.team_id
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

                $query .= " GROUP BY t.id ORDER BY t.created_at DESC";

                $stmt = $connection->prepare($query);
                $stmt->execute($params);
                $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode(['teams' => $teams]);
            }
            break;

        case 'POST':
            $data = json_decode(file_get_contents("php://input"), true);

            $stmt = $connection->prepare("
                INSERT INTO teams (name, age_group, division, season_id, primary_coach_id, home_field_id, max_players,
                                 logo_data, logo_filename, primary_color, secondary_color, accent_color)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $data['name'],
                $data['age_group'],
                $data['division'],
                isset($data['season_id']) && $data['season_id'] ? $data['season_id'] : null,
                isset($data['primary_coach_id']) && $data['primary_coach_id'] ? $data['primary_coach_id'] : null,
                isset($data['home_field_id']) && $data['home_field_id'] ? $data['home_field_id'] : null,
                $data['max_players'] ?? 20,
                $data['logo_data'] ?? null,
                $data['logo_filename'] ?? null,
                $data['primary_color'] ?? null,
                $data['secondary_color'] ?? null,
                $data['accent_color'] ?? null
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
                SET name = ?, age_group = ?, division = ?, season_id = ?,
                    primary_coach_id = ?, home_field_id = ?, max_players = ?,
                    logo_data = ?, logo_filename = ?, primary_color = ?, secondary_color = ?, accent_color = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $data['name'],
                $data['age_group'],
                $data['division'],
                isset($data['season_id']) && $data['season_id'] ? $data['season_id'] : null,
                isset($data['primary_coach_id']) && $data['primary_coach_id'] ? $data['primary_coach_id'] : null,
                isset($data['home_field_id']) && $data['home_field_id'] ? $data['home_field_id'] : null,
                $data['max_players'] ?? 20,
                $data['logo_data'] ?? null,
                $data['logo_filename'] ?? null,
                $data['primary_color'] ?? null,
                $data['secondary_color'] ?? null,
                $data['accent_color'] ?? null,
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