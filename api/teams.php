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

// Verify user has access to this club (super admins can access any)
$accessibleClubs = $auth->getAccessibleClubIds();
if ($accessibleClubs !== null && !in_array((int)$clubProfileId, $accessibleClubs)) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit();
}

try {
    // Use club scope for filtering — super admins see all teams when club_profile_id
    // is provided, same as legacy teams-gateway behavior
    $clubScope = $auth->getClubScopeWhereClause('t.club_id');

    $sql = "SELECT t.id, t.name, t.age_group, t.division, t.status,
                   t.club_id, t.logo_url, t.max_players,
                   s.name AS season_name, cp.name AS club_name
            FROM teams t
            LEFT JOIN seasons s ON s.id = t.season_id
            LEFT JOIN club_profile cp ON cp.id = t.club_id
            WHERE t.status IN ('forming', 'active')";
    $params = [];

    // Super admins with a club_profile_id filter by that club
    // but also see all teams if the club has none (fallback)
    if ($auth->isSuperAdmin()) {
        // Show teams from the requested club, plus all others for super admin
        $sql .= " AND t.club_id = ?";
        $params[] = (int)$clubProfileId;

        // If no teams in this club, show all teams
        $checkStmt = $db->prepare("SELECT COUNT(*) AS cnt FROM teams WHERE club_id = ? AND status IN ('forming', 'active')");
        $checkStmt->execute([(int)$clubProfileId]);
        if ((int)$checkStmt->fetch(PDO::FETCH_ASSOC)['cnt'] === 0) {
            // Remove the club filter for super admins with empty clubs
            $sql = "SELECT t.id, t.name, t.age_group, t.division, t.status,
                           t.club_id, t.logo_url, t.max_players,
                           s.name AS season_name, cp.name AS club_name
                    FROM teams t
                    LEFT JOIN seasons s ON s.id = t.season_id
                    LEFT JOIN club_profile cp ON cp.id = t.club_id
                    WHERE t.status IN ('forming', 'active')";
            $params = [];
        }
    } else {
        $sql .= " AND t.club_id = ?";
        $params[] = (int)$clubProfileId;
    }

    $sql .= " ORDER BY t.name";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'teams' => $teams]);
} catch (Exception $e) {
    error_log("Teams API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
