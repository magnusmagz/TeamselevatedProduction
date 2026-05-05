<?php
/**
 * Team Coaches API
 *
 * GET ?team_id=N → returns coaches (primary + assistant_coach + team_manager)
 * for a team, with bio fields for the parent portal "Coaches" card.
 */

require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';

$auth = AuthMiddleware::requireAuth();
$userId = $auth->getUserId();

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$teamId = $_GET['team_id'] ?? null;
if (!$teamId) {
    http_response_code(400);
    echo json_encode(['error' => 'team_id is required']);
    exit;
}

$db = Database::getInstance()->getConnection();

// Verify the requester has access to this team's club
$stmt = $db->prepare("SELECT club_id FROM teams WHERE id = ? AND deleted_at IS NULL");
$stmt->execute([$teamId]);
$team = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$team) {
    http_response_code(404);
    echo json_encode(['error' => 'Team not found']);
    exit;
}

if (!$auth->canAccessClub($team['club_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

$sql = "
    SELECT u.id,
           u.first_name,
           u.last_name,
           u.email,
           u.profile_image_url,
           u.coaching_background,
           src.role
    FROM (
        SELECT t.primary_coach_id AS user_id, 'head_coach' AS role
        FROM teams t WHERE t.id = ? AND t.primary_coach_id IS NOT NULL
        UNION
        SELECT tm.user_id, tm.role
        FROM team_members tm
        WHERE tm.team_id = ?
          AND tm.role IN ('assistant_coach', 'team_manager')
          AND tm.status = 'active'
          AND tm.user_id IS NOT NULL
    ) src
    JOIN users u ON u.id = src.user_id
    ORDER BY CASE src.role WHEN 'head_coach' THEN 0 WHEN 'assistant_coach' THEN 1 ELSE 2 END,
             u.last_name, u.first_name
";

$stmt = $db->prepare($sql);
$stmt->execute([$teamId, $teamId]);
$coaches = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($coaches as &$c) {
    $c['id'] = (int)$c['id'];
}
unset($c);

echo json_encode(['success' => true, 'coaches' => $coaches]);
