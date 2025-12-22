<?php
/**
 * User Search API
 *
 * Search for users by name or email
 */

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';

try {
    $db = Database::getInstance();
    $connection = $db->getConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

try {
    // Require authentication
    $auth = AuthMiddleware::requireAuth();

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit();
    }

    $query = $_GET['q'] ?? '';

    if (strlen($query) < 2) {
        http_response_code(400);
        echo json_encode(['error' => 'Search query must be at least 2 characters']);
        exit();
    }

    // Search users by name or email
    $stmt = $connection->prepare("
        SELECT
            id,
            email,
            first_name,
            last_name,
            CONCAT(first_name, ' ', last_name) as name
        FROM users
        WHERE
            first_name ILIKE ? OR
            last_name ILIKE ? OR
            email ILIKE ? OR
            CONCAT(first_name, ' ', last_name) ILIKE ?
        ORDER BY first_name, last_name
        LIMIT 20
    ");

    $searchPattern = "%$query%";
    $stmt->execute([$searchPattern, $searchPattern, $searchPattern, $searchPattern]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'users' => $users
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    error_log("User Search Error: " . $e->getMessage());
}
?>
