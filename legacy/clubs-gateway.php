<?php
/**
 * Clubs Gateway API
 *
 * Fetch clubs for a league
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

    $leagueId = $_GET['league_id'] ?? null;

    if (!$leagueId) {
        http_response_code(400);
        echo json_encode(['error' => 'League ID is required']);
        exit();
    }

    // Fetch clubs for the league
    $stmt = $connection->prepare("
        SELECT
            id,
            name,
            league_id,
            address_line1,
            city,
            state,
            zip_code,
            phone,
            email
        FROM club_profile
        WHERE league_id = ?
        ORDER BY name
    ");

    $stmt->execute([$leagueId]);
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
