<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With');

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
            if (isset($_GET['id'])) {
                // Get specific program
                $stmt = $pdo->prepare("
                    SELECT p.*,
                           COUNT(DISTINCT t.id) as team_count
                    FROM programs p
                    LEFT JOIN teams t ON p.id = t.program_id
                    WHERE p.id = ?
                    GROUP BY p.id
                ");
                $stmt->execute([$_GET['id']]);
                $program = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($program) {
                    // Get teams for this program
                    $teamStmt = $pdo->prepare("
                        SELECT t.*, u.first_name as coach_first_name, u.last_name as coach_last_name
                        FROM teams t
                        LEFT JOIN users u ON t.primary_coach_id = u.id
                        WHERE t.program_id = ?
                    ");
                    $teamStmt->execute([$_GET['id']]);
                    $program['teams'] = $teamStmt->fetchAll(PDO::FETCH_ASSOC);

                    echo json_encode($program);
                } else {
                    http_response_code(404);
                    echo json_encode(['error' => 'Program not found']);
                }
            } else {
                // Get all programs, optionally filtered
                $whereClause = "WHERE 1=1";
                $params = [];

                if (isset($_GET['season_year'])) {
                    $whereClause .= " AND p.season_year = ?";
                    $params[] = $_GET['season_year'];
                }

                if (isset($_GET['season_type'])) {
                    $whereClause .= " AND p.season_type = ?";
                    $params[] = $_GET['season_type'];
                }

                if (isset($_GET['type'])) {
                    $whereClause .= " AND p.type = ?";
                    $params[] = $_GET['type'];
                }

                if (isset($_GET['status'])) {
                    $whereClause .= " AND p.status = ?";
                    $params[] = $_GET['status'];
                }

                $stmt = $pdo->prepare("
                    SELECT p.*,
                           COUNT(DISTINCT t.id) as team_count,
                           COUNT(DISTINCT tp.user_id) as player_count
                    FROM programs p
                    LEFT JOIN teams t ON p.id = t.program_id
                    LEFT JOIN team_members tp ON t.id = tp.team_id
                    $whereClause
                    GROUP BY p.id
                    ORDER BY p.season_year DESC,
                             FIELD(p.season_type, 'Spring', 'Summer', 'Fall', 'Winter', 'Year-Round'),
                             p.name
                ");
                $stmt->execute($params);
                $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode(['success' => true, 'programs' => $programs]);
            }
            break;

        case 'POST':
            $data = json_decode(file_get_contents('php://input'), true);

            $stmt = $pdo->prepare("
                INSERT INTO programs (
                    club_id, name, type, description,
                    season_year, season_type, is_recurring,
                    start_date, end_date,
                    registration_opens, registration_closes,
                    min_age, max_age, capacity, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $data['club_id'] ?? 1,
                $data['name'],
                $data['type'] ?? 'league',
                $data['description'] ?? null,
                $data['season_year'] ?? date('Y'),
                $data['season_type'] ?? 'Year-Round',
                $data['is_recurring'] ?? false,
                $data['start_date'] ?? null,
                $data['end_date'] ?? null,
                $data['registration_opens'] ?? null,
                $data['registration_closes'] ?? null,
                $data['min_age'] ?? null,
                $data['max_age'] ?? null,
                $data['capacity'] ?? null,
                $data['status'] ?? 'draft'
            ]);

            echo json_encode([
                'success' => true,
                'id' => $pdo->lastInsertId(),
                'message' => 'Program created successfully'
            ]);
            break;

        case 'PUT':
            if (!isset($_GET['id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Program ID required']);
                exit;
            }

            $data = json_decode(file_get_contents('php://input'), true);

            $stmt = $pdo->prepare("
                UPDATE programs SET
                    name = ?,
                    type = ?,
                    description = ?,
                    season_year = ?,
                    season_type = ?,
                    is_recurring = ?,
                    start_date = ?,
                    end_date = ?,
                    registration_opens = ?,
                    registration_closes = ?,
                    min_age = ?,
                    max_age = ?,
                    capacity = ?,
                    status = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");

            $stmt->execute([
                $data['name'],
                $data['type'] ?? 'league',
                $data['description'] ?? null,
                $data['season_year'] ?? date('Y'),
                $data['season_type'] ?? 'Year-Round',
                $data['is_recurring'] ?? false,
                $data['start_date'] ?? null,
                $data['end_date'] ?? null,
                $data['registration_opens'] ?? null,
                $data['registration_closes'] ?? null,
                $data['min_age'] ?? null,
                $data['max_age'] ?? null,
                $data['capacity'] ?? null,
                $data['status'] ?? 'draft',
                $_GET['id']
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Program updated successfully'
            ]);
            break;

        case 'DELETE':
            if (!isset($_GET['id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Program ID required']);
                exit;
            }

            // Check if program has teams
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM teams WHERE program_id = ?");
            $checkStmt->execute([$_GET['id']]);
            $teamCount = $checkStmt->fetchColumn();

            if ($teamCount > 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Cannot delete program with existing teams']);
                exit;
            }

            $stmt = $pdo->prepare("DELETE FROM programs WHERE id = ?");
            $stmt->execute([$_GET['id']]);

            echo json_encode([
                'success' => true,
                'message' => 'Program deleted successfully'
            ]);
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch(Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>