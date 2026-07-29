<?php
/**
 * Club Users Gateway API
 *
 * Manage users associated with a club
 */

header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();


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
    $auth = AuthMiddleware::requireAuth();
    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'GET':
            // Get all users for a club
            $clubId = $_GET['clubId'] ?? null;

            if (!$clubId) {
                http_response_code(400);
                echo json_encode(['error' => 'clubId is required']);
                exit();
            }

            // Check if user can access this club
            if (!$auth->canAccessClub($clubId)) {
                http_response_code(403);
                echo json_encode(['error' => 'Access denied']);
                exit();
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
                    uca.granted_at
                FROM user_club_access uca
                JOIN users u ON uca.user_id = u.id
                WHERE uca.club_profile_id = ? AND uca.active = true
                ORDER BY uca.role, u.email
            ");
            $stmt->execute([$clubId]);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'users' => $users
            ]);
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
