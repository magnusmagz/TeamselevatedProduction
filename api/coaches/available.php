<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../lib/AuthMiddleware.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Coaches are tenant data: require auth and scope to the caller's club(s).
// A "coach of a club" = has a user_club_access coach role in the club, or is
// the primary_coach of a (non-deleted) team in the club. Mirrors the scoping in
// legacy/coaches-gateway.php and how JWT.php derives club-scoped coach roles.
$auth = AuthMiddleware::requireAuth();
$accessibleClubs = $auth->getAccessibleClubIds(); // null => super admin (all clubs)

// Authenticated but scoped to no club (and not a super admin): nothing visible.
if ($accessibleClubs !== null && empty($accessibleClubs)) {
    echo json_encode([]);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();

    $params = [];
    if ($accessibleClubs === null) {
        $teamClubFilter = '';
        $ucaClubFilter = '';
    } else {
        $ph = implode(',', array_fill(0, count($accessibleClubs), '?'));
        $teamClubFilter = "AND t.club_id IN ($ph)";
        $ucaClubFilter  = "AND uca.club_profile_id IN ($ph)";
        // Placeholder order below: teams LEFT JOIN filter, then uca EXISTS filter.
        $params = array_merge($accessibleClubs, $accessibleClubs);
    }

    $sql = "SELECT u.id, u.first_name, u.last_name, u.email,
                   COUNT(DISTINCT t.id) AS team_count
            FROM users u
            LEFT JOIN teams t
                   ON t.primary_coach_id = u.id
                  AND t.deleted_at IS NULL
                  $teamClubFilter
            WHERE (
                EXISTS (
                    SELECT 1 FROM user_club_access uca
                    WHERE uca.user_id = u.id
                      AND uca.active = true
                      AND uca.role = 'coach'
                      $ucaClubFilter
                )
                OR t.id IS NOT NULL
            )
            GROUP BY u.id, u.first_name, u.last_name, u.email
            ORDER BY u.last_name, u.first_name";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch coaches']);
}
