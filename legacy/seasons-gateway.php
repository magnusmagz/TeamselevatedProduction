<?php
// Disable any auto-prepended files
ini_set('auto_prepend_file', '');

// Set headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Get the action
$action = $_GET['action'] ?? '';

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

switch ($action) {
    case 'list':
        // Fetch all seasons
        try {
            $stmt = $connection->query("SELECT * FROM seasons ORDER BY start_date DESC");
            $seasons = $stmt->fetchAll();
            echo json_encode(['success' => true, 'seasons' => $seasons]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to fetch seasons: ' . $e->getMessage()]);
        }
        break;

    case 'create':
        // Get input
        $data = json_decode(file_get_contents('php://input'), true);

        // Validation
        if (!$data || !isset($data['name']) || !isset($data['start_date']) || !isset($data['end_date'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Name, start_date, and end_date are required']);
            exit;
        }

        // Create season
        try {
            $stmt = $connection->prepare("
                INSERT INTO seasons (name, start_date, end_date, created_at)
                VALUES (:name, :start_date, :end_date, CURRENT_TIMESTAMP)
            ");

            $stmt->execute([
                ':name' => $data['name'],
                ':start_date' => $data['start_date'],
                ':end_date' => $data['end_date']
            ]);

            $seasonId = $connection->lastInsertId();

            // Fetch the created season
            $stmt = $connection->prepare("SELECT * FROM seasons WHERE id = :id");
            $stmt->execute([':id' => $seasonId]);
            $season = $stmt->fetch();

            echo json_encode(['success' => true, 'season' => $season]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to create season: ' . $e->getMessage()]);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
        break;
}
?>