<?php
/**
 * Teams API
 * Get teams filtered by club
 */

header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();


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

// Verify user has access to this club (super admins can access any)
$accessibleClubs = $auth->getAccessibleClubIds();
if ($accessibleClubs !== null && !in_array((int)$clubProfileId, $accessibleClubs)) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit();
}

try {
    // Strict club filtering — only show teams in the requested club
    // This matches the correct behavior: admins see their club's teams only
    $sql = "SELECT t.id, t.name, t.age_group, t.gender, t.division, t.status,
                   t.club_id, t.logo_url, t.max_players,
                   s.name AS season_name
            FROM teams t
            LEFT JOIN seasons s ON s.id = t.season_id
            WHERE t.club_id = ?
            AND t.status IN ('forming', 'active')
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
