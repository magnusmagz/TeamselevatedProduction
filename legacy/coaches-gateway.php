<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS");
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

$action = $_GET['action'] ?? 'available';

try {
    switch ($action) {
        case 'available':
            // Get available coaches
            $stmt = $connection->prepare("
                SELECT u.id, u.first_name, u.last_name, u.email,
                       COUNT(DISTINCT t.id) as team_count
                FROM users u
                LEFT JOIN teams t ON t.primary_coach_id = u.id
                WHERE u.role = 'coach'
                GROUP BY u.id, u.first_name, u.last_name, u.email
                ORDER BY u.last_name, u.first_name
            ");
            $stmt->execute();
            $coaches = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($coaches);
            break;

        case 'create':
            $data = json_decode(file_get_contents("php://input"), true);

            // Check if email already exists
            $stmt = $connection->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$data['email']]);
            if ($stmt->fetch()) {
                http_response_code(400);
                echo json_encode(['error' => 'Email already exists']);
                exit();
            }

            // Create coach account
            $stmt = $connection->prepare("
                INSERT INTO users (first_name, last_name, email, password_hash, role, created_at)
                VALUES (?, ?, ?, ?, 'coach', NOW())
            ");

            $hashedPassword = password_hash($data['password'] ?? 'password123', PASSWORD_DEFAULT);

            $stmt->execute([
                $data['first_name'],
                $data['last_name'],
                $data['email'],
                $hashedPassword
            ]);

            echo json_encode([
                'success' => true,
                'id' => $connection->lastInsertId(),
                'message' => 'Coach created successfully'
            ]);
            break;

        case 'update':
            $data = json_decode(file_get_contents("php://input"), true);
            $coachId = $_GET['id'] ?? null;

            if (!$coachId) {
                http_response_code(400);
                echo json_encode(['error' => 'Coach ID is required']);
                exit();
            }

            // Verify coach exists
            $stmt = $connection->prepare("SELECT id FROM users WHERE id = ? AND role = 'coach'");
            $stmt->execute([$coachId]);
            if (!$stmt->fetch()) {
                http_response_code(404);
                echo json_encode(['error' => 'Coach not found']);
                exit();
            }

            // Update coach information
            $stmt = $connection->prepare("
                UPDATE users
                SET first_name = ?,
                    last_name = ?,
                    email = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $data['first_name'],
                $data['last_name'],
                $data['email'],
                $coachId
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Coach updated successfully'
            ]);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>