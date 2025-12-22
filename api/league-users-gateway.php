<?php
/**
 * League Users Management API
 *
 * Manages user access and roles for leagues
 *
 * Endpoints:
 * - GET ?leagueId=X - List all users with access to a league
 * - POST ?action=add - Add a user to a league with a role
 * - POST ?action=create - Create a new user and grant them access to a league
 * - PUT ?action=update - Update a user's role in a league
 * - DELETE ?action=remove - Remove a user from a league
 */

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
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

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$leagueId = $_GET['leagueId'] ?? null;

try {
    // Require authentication for all endpoints
    $auth = AuthMiddleware::requireAuth();

    switch ($method) {
        case 'GET':
            handleGetUsers($connection, $auth, $leagueId);
            break;

        case 'POST':
            if ($action === 'add') {
                handleAddUser($connection, $auth);
            } elseif ($action === 'create') {
                handleCreateUser($connection, $auth);
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid action']);
            }
            break;

        case 'PUT':
            if ($action === 'update') {
                handleUpdateUserRole($connection, $auth);
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid action']);
            }
            break;

        case 'DELETE':
            if ($action === 'remove') {
                handleRemoveUser($connection, $auth);
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid action']);
            }
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    error_log("League Users Gateway Error: " . $e->getMessage());
}

/**
 * Get all users with access to a league (both league-level and club-level)
 */
function handleGetUsers($connection, $auth, $leagueId) {
    if (!$leagueId) {
        http_response_code(400);
        echo json_encode(['error' => 'League ID is required']);
        return;
    }

    // Check if user is a league admin for this league
    if (!$auth->isSuperAdmin() && !$auth->hasRole('league_admin', $leagueId, 'league')) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied. You must be a league admin to view users.']);
        return;
    }

    // Get league-level users
    $stmt = $connection->prepare("
        SELECT
            u.id,
            u.email,
            u.first_name,
            u.last_name,
            u.system_role,
            ula.role,
            'league' as access_type,
            ula.league_id as scope_id,
            l.name as scope_name,
            ula.granted_at,
            ula.active
        FROM user_league_access ula
        JOIN users u ON ula.user_id = u.id
        JOIN leagues l ON ula.league_id = l.id
        WHERE ula.league_id = ? AND ula.active = TRUE
    ");
    $stmt->execute([$leagueId]);
    $leagueUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get club-level users for clubs in this league
    $stmt = $connection->prepare("
        SELECT
            u.id,
            u.email,
            u.first_name,
            u.last_name,
            u.system_role,
            uca.role,
            'club' as access_type,
            uca.club_profile_id as scope_id,
            c.name as scope_name,
            uca.granted_at,
            uca.active
        FROM user_club_access uca
        JOIN users u ON uca.user_id = u.id
        JOIN club_profile c ON uca.club_profile_id = c.id
        WHERE c.league_id = ? AND uca.active = TRUE
    ");
    $stmt->execute([$leagueId]);
    $clubUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Combine and format users
    $allUsers = array_merge($leagueUsers, $clubUsers);

    // Group by user to show all their roles
    $usersById = [];
    foreach ($allUsers as $userAccess) {
        $userId = $userAccess['id'];

        if (!isset($usersById[$userId])) {
            $usersById[$userId] = [
                'id' => $userId,
                'email' => $userAccess['email'],
                'first_name' => $userAccess['first_name'],
                'last_name' => $userAccess['last_name'],
                'name' => trim($userAccess['first_name'] . ' ' . $userAccess['last_name']),
                'system_role' => $userAccess['system_role'],
                'roles' => []
            ];
        }

        $usersById[$userId]['roles'][] = [
            'role' => $userAccess['role'],
            'access_type' => $userAccess['access_type'],
            'scope_id' => $userAccess['scope_id'],
            'scope_name' => $userAccess['scope_name'],
            'granted_at' => $userAccess['granted_at'],
            'active' => $userAccess['active']
        ];
    }

    echo json_encode([
        'success' => true,
        'users' => array_values($usersById)
    ]);
}

/**
 * Add a user to a league with a specific role
 */
function handleAddUser($connection, $auth) {
    $data = json_decode(file_get_contents("php://input"), true);

    // Validate required fields
    if (empty($data['leagueId']) || empty($data['userId']) || empty($data['role'])) {
        http_response_code(400);
        echo json_encode(['error' => 'League ID, user ID, and role are required']);
        return;
    }

    $leagueId = $data['leagueId'];
    $userId = $data['userId'];
    $role = $data['role'];
    $accessType = $data['accessType'] ?? 'league'; // 'league' or 'club'
    $clubId = $data['clubId'] ?? null;

    // Validate role
    $validRoles = ['league_admin', 'club_admin', 'coach'];
    if (!in_array($role, $validRoles)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid role. Must be one of: ' . implode(', ', $validRoles)]);
        return;
    }

    // Check if user is a league admin for this league
    if (!$auth->isSuperAdmin() && !$auth->hasRole('league_admin', $leagueId, 'league')) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied. You must be a league admin to manage users.']);
        return;
    }

    // Verify user exists
    $stmt = $connection->prepare("SELECT id, email FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode(['error' => 'User not found']);
        return;
    }

    // Verify league exists
    $stmt = $connection->prepare("SELECT id, name FROM leagues WHERE id = ?");
    $stmt->execute([$leagueId]);
    $league = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$league) {
        http_response_code(404);
        echo json_encode(['error' => 'League not found']);
        return;
    }

    try {
        $connection->beginTransaction();

        if ($accessType === 'league') {
            // Add league-level access
            $stmt = $connection->prepare("
                INSERT INTO user_league_access (user_id, league_id, role, granted_at, granted_by, active)
                VALUES (?, ?, ?, CURRENT_TIMESTAMP, ?, TRUE)
                ON CONFLICT (user_id, league_id, role)
                DO UPDATE SET active = TRUE, granted_at = CURRENT_TIMESTAMP, granted_by = ?
            ");
            $stmt->execute([$userId, $leagueId, $role, $auth->getUserId(), $auth->getUserId()]);
        } else {
            // Add club-level access
            if (!$clubId) {
                http_response_code(400);
                echo json_encode(['error' => 'Club ID is required for club-level access']);
                $connection->rollBack();
                return;
            }

            // Verify club belongs to league
            $stmt = $connection->prepare("SELECT id FROM club_profile WHERE id = ? AND league_id = ?");
            $stmt->execute([$clubId, $leagueId]);
            if (!$stmt->fetch()) {
                http_response_code(404);
                echo json_encode(['error' => 'Club not found in this league']);
                $connection->rollBack();
                return;
            }

            $stmt = $connection->prepare("
                INSERT INTO user_club_access (user_id, club_profile_id, role, granted_at, granted_by, active)
                VALUES (?, ?, ?, CURRENT_TIMESTAMP, ?, TRUE)
                ON CONFLICT (user_id, club_profile_id, role)
                DO UPDATE SET active = TRUE, granted_at = CURRENT_TIMESTAMP, granted_by = ?
            ");
            $stmt->execute([$userId, $clubId, $role, $auth->getUserId(), $auth->getUserId()]);
        }

        $connection->commit();

        echo json_encode([
            'success' => true,
            'message' => 'User access granted successfully'
        ]);
    } catch (Exception $e) {
        $connection->rollBack();
        throw $e;
    }
}

/**
 * Create a new user and grant them access to a league
 */
function handleCreateUser($connection, $auth) {
    $data = json_decode(file_get_contents("php://input"), true);

    // Validate required fields
    if (empty($data['leagueId']) || empty($data['email']) || empty($data['firstName']) || empty($data['lastName']) || empty($data['role'])) {
        http_response_code(400);
        echo json_encode(['error' => 'League ID, email, first name, last name, and role are required']);
        return;
    }

    $leagueId = $data['leagueId'];
    $email = trim(strtolower($data['email']));
    $firstName = trim($data['firstName']);
    $lastName = trim($data['lastName']);
    $role = $data['role'];
    $accessType = $data['accessType'] ?? 'league';
    $clubId = $data['clubId'] ?? null;

    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid email format']);
        return;
    }

    // Validate role
    $validRoles = ['league_admin', 'club_admin', 'coach'];
    if (!in_array($role, $validRoles)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid role. Must be one of: ' . implode(', ', $validRoles)]);
        return;
    }

    // Check if user is a league admin for this league
    if (!$auth->isSuperAdmin() && !$auth->hasRole('league_admin', $leagueId, 'league')) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied. You must be a league admin to create users.']);
        return;
    }

    // Verify league exists
    $stmt = $connection->prepare("SELECT id, name FROM leagues WHERE id = ?");
    $stmt->execute([$leagueId]);
    $league = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$league) {
        http_response_code(404);
        echo json_encode(['error' => 'League not found']);
        return;
    }

    // Check if user with this email already exists
    $stmt = $connection->prepare("SELECT id, email FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existingUser) {
        http_response_code(409);
        echo json_encode(['error' => 'A user with this email already exists']);
        return;
    }

    try {
        $connection->beginTransaction();

        // Create the new user
        $stmt = $connection->prepare("
            INSERT INTO users (email, first_name, last_name, system_role, created_at)
            VALUES (?, ?, ?, 'user', CURRENT_TIMESTAMP)
            RETURNING id
        ");
        $stmt->execute([$email, $firstName, $lastName]);
        $newUser = $stmt->fetch(PDO::FETCH_ASSOC);
        $userId = $newUser['id'];

        // Grant access to the league or club
        if ($accessType === 'league') {
            // Add league-level access
            $stmt = $connection->prepare("
                INSERT INTO user_league_access (user_id, league_id, role, granted_at, granted_by, active)
                VALUES (?, ?, ?, CURRENT_TIMESTAMP, ?, TRUE)
            ");
            $stmt->execute([$userId, $leagueId, $role, $auth->getUserId()]);
        } else {
            // Add club-level access
            if (!$clubId) {
                http_response_code(400);
                echo json_encode(['error' => 'Club ID is required for club-level access']);
                $connection->rollBack();
                return;
            }

            // Verify club belongs to league
            $stmt = $connection->prepare("SELECT id FROM club_profile WHERE id = ? AND league_id = ?");
            $stmt->execute([$clubId, $leagueId]);
            if (!$stmt->fetch()) {
                http_response_code(404);
                echo json_encode(['error' => 'Club not found in this league']);
                $connection->rollBack();
                return;
            }

            $stmt = $connection->prepare("
                INSERT INTO user_club_access (user_id, club_profile_id, role, granted_at, granted_by, active)
                VALUES (?, ?, ?, CURRENT_TIMESTAMP, ?, TRUE)
            ");
            $stmt->execute([$userId, $clubId, $role, $auth->getUserId()]);
        }

        $connection->commit();

        echo json_encode([
            'success' => true,
            'message' => 'User created and access granted successfully',
            'user_id' => $userId
        ]);
    } catch (Exception $e) {
        $connection->rollBack();
        throw $e;
    }
}

/**
 * Update a user's role in a league
 */
function handleUpdateUserRole($connection, $auth) {
    $data = json_decode(file_get_contents("php://input"), true);

    // Validate required fields
    if (empty($data['leagueId']) || empty($data['userId']) || empty($data['role'])) {
        http_response_code(400);
        echo json_encode(['error' => 'League ID, user ID, and role are required']);
        return;
    }

    $leagueId = $data['leagueId'];
    $userId = $data['userId'];
    $oldRole = $data['oldRole'];
    $newRole = $data['role'];
    $accessType = $data['accessType'] ?? 'league';
    $scopeId = $data['scopeId'] ?? $leagueId;

    // Validate role
    $validRoles = ['league_admin', 'club_admin', 'coach'];
    if (!in_array($newRole, $validRoles)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid role. Must be one of: ' . implode(', ', $validRoles)]);
        return;
    }

    // Check if user is a league admin for this league
    if (!$auth->isSuperAdmin() && !$auth->hasRole('league_admin', $leagueId, 'league')) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied. You must be a league admin to manage users.']);
        return;
    }

    try {
        $connection->beginTransaction();

        if ($accessType === 'league') {
            // First deactivate the old role
            $stmt = $connection->prepare("
                UPDATE user_league_access
                SET active = FALSE
                WHERE user_id = ? AND league_id = ? AND role = ?
            ");
            $stmt->execute([$userId, $leagueId, $oldRole]);

            // Then add/activate the new role
            $stmt = $connection->prepare("
                INSERT INTO user_league_access (user_id, league_id, role, granted_at, granted_by, active)
                VALUES (?, ?, ?, CURRENT_TIMESTAMP, ?, TRUE)
                ON CONFLICT (user_id, league_id, role)
                DO UPDATE SET active = TRUE, granted_at = CURRENT_TIMESTAMP, granted_by = ?
            ");
            $stmt->execute([$userId, $leagueId, $newRole, $auth->getUserId(), $auth->getUserId()]);
        } else {
            // Club-level access update
            $stmt = $connection->prepare("
                UPDATE user_club_access
                SET active = FALSE
                WHERE user_id = ? AND club_profile_id = ? AND role = ?
            ");
            $stmt->execute([$userId, $scopeId, $oldRole]);

            $stmt = $connection->prepare("
                INSERT INTO user_club_access (user_id, club_profile_id, role, granted_at, granted_by, active)
                VALUES (?, ?, ?, CURRENT_TIMESTAMP, ?, TRUE)
                ON CONFLICT (user_id, club_profile_id, role)
                DO UPDATE SET active = TRUE, granted_at = CURRENT_TIMESTAMP, granted_by = ?
            ");
            $stmt->execute([$userId, $scopeId, $newRole, $auth->getUserId(), $auth->getUserId()]);
        }

        $connection->commit();

        echo json_encode([
            'success' => true,
            'message' => 'User role updated successfully'
        ]);
    } catch (Exception $e) {
        $connection->rollBack();
        throw $e;
    }
}

/**
 * Remove a user's access from a league
 */
function handleRemoveUser($connection, $auth) {
    $data = json_decode(file_get_contents("php://input"), true);

    // Validate required fields
    if (empty($data['leagueId']) || empty($data['userId']) || empty($data['role'])) {
        http_response_code(400);
        echo json_encode(['error' => 'League ID, user ID, and role are required']);
        return;
    }

    $leagueId = $data['leagueId'];
    $userId = $data['userId'];
    $role = $data['role'];
    $accessType = $data['accessType'] ?? 'league';
    $scopeId = $data['scopeId'] ?? $leagueId;

    // Check if user is a league admin for this league
    if (!$auth->isSuperAdmin() && !$auth->hasRole('league_admin', $leagueId, 'league')) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied. You must be a league admin to manage users.']);
        return;
    }

    // Prevent removing yourself if you're the last admin
    if ($userId == $auth->getUserId()) {
        if ($accessType === 'league') {
            $stmt = $connection->prepare("
                SELECT COUNT(*) as admin_count
                FROM user_league_access
                WHERE league_id = ? AND role = 'league_admin' AND active = TRUE
            ");
            $stmt->execute([$leagueId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result['admin_count'] <= 1) {
                http_response_code(409);
                echo json_encode(['error' => 'Cannot remove the last league admin. Assign another admin first.']);
                return;
            }
        }
    }

    try {
        if ($accessType === 'league') {
            $stmt = $connection->prepare("
                UPDATE user_league_access
                SET active = FALSE
                WHERE user_id = ? AND league_id = ? AND role = ?
            ");
            $stmt->execute([$userId, $leagueId, $role]);
        } else {
            $stmt = $connection->prepare("
                UPDATE user_club_access
                SET active = FALSE
                WHERE user_id = ? AND club_profile_id = ? AND role = ?
            ");
            $stmt->execute([$userId, $scopeId, $role]);
        }

        echo json_encode([
            'success' => true,
            'message' => 'User access removed successfully'
        ]);
    } catch (Exception $e) {
        throw $e;
    }
}
?>
