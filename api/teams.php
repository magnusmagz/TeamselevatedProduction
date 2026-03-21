<?php
/**
 * Teams API
 * Get teams filtered by club
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

$auth = AuthMiddleware::requireAuth();

$database = Database::getInstance();
$db = $database->getConnection();

$clubProfileId = $_GET['club_profile_id'] ?? null;

if (!$clubProfileId) {
    http_response_code(400);
    echo json_encode(['error' => 'club_profile_id is required']);
    exit();
}

// Verify user has access to this club
$accessibleClubs = $auth->getAccessibleClubIds();
if ($accessibleClubs !== null && !in_array((int)$clubProfileId, $accessibleClubs)) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit();
}

try {
    $sql = "SELECT t.id, t.name, t.age_group, t.division, t.status,
                   t.logo_url, t.max_players,
                   s.name AS season_name
            FROM teams t
            LEFT JOIN seasons s ON s.id = t.season_id
            WHERE t.club_id = ?
            AND (t.deleted_at IS NULL OR t.deleted_at IS NULL)
            AND t.status != 'deleted'
            ORDER BY t.name";

    $stmt = $db->prepare($sql);
    $stmt->execute([(int)$clubProfileId]);
    $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'teams' => $teams]);
} catch (Exception $e) {
    error_log("Teams API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
