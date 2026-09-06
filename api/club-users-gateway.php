<?php
/**
 * Club Users Gateway API
 *
 * Manage users associated with a club
 */

// Test hook: defining this loads the collaborators and returns before any side
// effect. Never defined in production; must stay above everything with an effect.
if (defined('TE_CLUB_USERS_LIB_ONLY')) {
    require_once __DIR__ . '/../lib/club_standing.php';
    require_once __DIR__ . '/../lib/portal_status.php';
    return;
}

header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();


require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/role_cache.php';
require_once __DIR__ . '/../lib/club_standing.php';
require_once __DIR__ . '/../lib/portal_status.php';

/**
 * GET — every active role row in the club, with platform-access status.
 *
 * ⚠️ Gated on te_is_club_admin(), NOT canAccessClub(). Until 2026-09-06 this
 * used canAccessClub(), which is club MEMBERSHIP — a `parent` row satisfies it
 * — so any parent could list every staff member's name and email for their
 * club. PUT/DELETE already required admin standing; the read now matches.
 *
 * Status comes from lib/portal_status.php with the `:coach_invite` suffix for
 * every staff role (the invite is shared; only the email copy is role-aware),
 * so the Users tab's status->action map is the one the Coaches page built.
 *
 * @param string|null $statusColumnsSql TEST SEAM ONLY. The real columns carry
 *        `string_agg(DISTINCT …)`, which SQLite cannot parse; the test passes the
 *        same columns with that one spelling swapped. Production passes nothing.
 * @return array{status:int, body:array}
 */
function clubUsers_list(PDO $connection, $auth, $clubId, ?string $statusColumnsSql = null): array
{
    $clubId = (int) $clubId;
    if ($clubId <= 0) {
        return ['status' => 400, 'body' => ['error' => 'clubId is required']];
    }
    if (!te_is_club_admin($auth, $clubId)) {
        return ['status' => 403, 'body' => ['error' => 'Only club admins can list club users']];
    }

    $stmt = $connection->prepare("
        SELECT
            uca.id,
            uca.user_id,
            u.email,
            u.first_name,
            u.last_name,
            uca.role,
            uca.active,
            uca.granted_at,
            " . ($statusColumnsSql ?? te_portal_status_columns('u.email', 'u', 'coach_invite')) . "
        FROM user_club_access uca
        JOIN users u ON uca.user_id = u.id
        WHERE uca.club_profile_id = ? AND uca.active = true
        ORDER BY uca.role, u.email
    ");
    $stmt->execute([$clubId]);

    $users = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $s = te_portal_status($r, (string) $r['email'], 'coach');
        $users[] = [
            'id'             => (int) $r['id'],
            'user_id'        => (int) $r['user_id'],
            'email'          => $r['email'],
            'first_name'     => $r['first_name'],
            'last_name'      => $r['last_name'],
            'role'           => $r['role'],
            'active'         => $r['active'],
            'granted_at'     => $r['granted_at'],
            'status'         => $s['status'],
            'first_login_at' => $s['first_login_at'],
            'invited_at'     => $s['invited_at'],
            'shared_account' => $s['shared_account'],
            'shared_reason'  => $s['shared_reason'],
        ];
    }

    return ['status' => 200, 'body' => ['success' => true, 'users' => $users]];
}

try {
    $db = Database::getInstance();
    $connection = $db->getConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

try {
    $auth = AuthMiddleware::requireAuth();
    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'GET':
            $result = clubUsers_list($connection, $auth, $_GET['clubId'] ?? null);
            http_response_code($result['status']);
            echo json_encode($result['body']);
            break;

        case 'PUT':
            // Update user role
            $data = json_decode(file_get_contents("php://input"), true);
            $clubId = $data['clubId'] ?? null;
            $userId = $data['userId'] ?? null;
            $newRole = $data['role'] ?? null;

            if (!$clubId || !$userId || !$newRole) {
                http_response_code(400);
                echo json_encode(['error' => 'clubId, userId, and role are required']);
                exit();
            }

            // Check if user has admin access to this club
            if (!$auth->can('manage_club', $clubId, 'club')) {
                http_response_code(403);
                echo json_encode(['error' => 'You do not have permission to manage users']);
                exit();
            }

            // Validate role
            $validRoles = ['club_admin', 'coach'];
            if (!in_array($newRole, $validRoles)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid role']);
                exit();
            }

            $stmt = $connection->prepare("
                UPDATE user_club_access
                SET role = ?
                WHERE club_profile_id = ? AND user_id = ? AND active = true
            ");
            $stmt->execute([$newRole, $clubId, $userId]);
            te_role_cache_invalidate($userId);

            echo json_encode([
                'success' => true,
                'message' => 'User role updated successfully'
            ]);
            break;

        case 'DELETE':
            // Remove user from club
            $clubId = $_GET['clubId'] ?? null;
            $userId = $_GET['userId'] ?? null;

            if (!$clubId || !$userId) {
                http_response_code(400);
                echo json_encode(['error' => 'clubId and userId are required']);
                exit();
            }

            // Check if user has admin access to this club
            if (!$auth->can('manage_club', $clubId, 'club')) {
                http_response_code(403);
                echo json_encode(['error' => 'You do not have permission to manage users']);
                exit();
            }

            // Don't allow removing yourself
            if ($userId == $auth->getUserId()) {
                http_response_code(400);
                echo json_encode(['error' => 'You cannot remove yourself from the club']);
                exit();
            }

            $stmt = $connection->prepare("
                UPDATE user_club_access
                SET active = false, revoked_at = CURRENT_TIMESTAMP
                WHERE club_profile_id = ? AND user_id = ? AND active = true
            ");
            $stmt->execute([$clubId, $userId]);
            // A REVOCATION — see the note in super-admin-gateway.php.
            te_role_cache_invalidate($userId);

            echo json_encode([
                'success' => true,
                'message' => 'User removed from club'
            ]);
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    error_log("Club Users Gateway Error: " . $e->getMessage());
}
?>
