<?php
/**
 * Clubs Gateway API
 *
 * Fetch clubs (league hierarchy removed - clubs are now top-level)
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

    // Get clubs user has access to
    $clubScope = $auth->getClubScopeWhereClause($connection, 'id');

    $stmt = $connection->prepare("
        SELECT
            id,
            name,
            address_line1,
            city,
            state,
            zip_code,
            phone,
            email
        FROM club_profile
        " . $clubScope['where'] . "
        ORDER BY name
    ");

    $stmt->execute($clubScope['params']);
    $clubs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'clubs' => $clubs
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    error_log("Clubs Gateway Error: " . $e->getMessage());
}
?>
