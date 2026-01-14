<?php
/**
 * Super Admin Gateway API
 *
 * Platform-wide administration for super admins only.
 * Allows viewing and managing all clubs, teams, users, and roles.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';

// Require authentication
$auth = AuthMiddleware::requireAuth();

// Require super admin role
if (!$auth->isSuperAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Super admin access required']);
    exit;
}

$db = Database::getInstance();
$pdo = $db->getConnection();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'stats':
            handleGetStats($pdo);
            break;

        case 'clubs':
            handleGetClubs($pdo);
            break;

        case 'club-details':
            $clubId = $_GET['id'] ?? null;
            if (!$clubId) {
                throw new Exception('Club ID required');
            }
            handleGetClubDetails($pdo, (int)$clubId);
            break;

        case 'users':
            handleGetUsers($pdo);
            break;

        case 'user-details':
            $userId = $_GET['id'] ?? null;
            if (!$userId) {
                throw new Exception('User ID required');
            }
            handleGetUserDetails($pdo, (int)$userId);
            break;

        case 'update-system-role':
            if ($method !== 'PUT') {
                throw new Exception('PUT method required');
            }
            $input = json_decode(file_get_contents('php://input'), true);
            handleUpdateSystemRole($pdo, $input, $auth);
            break;

        case 'update-club-role':
            if ($method !== 'PUT') {
                throw new Exception('PUT method required');
            }
            $input = json_decode(file_get_contents('php://input'), true);
            handleUpdateClubRole($pdo, $input);
            break;

        case 'remove-club-access':
            if ($method !== 'DELETE') {
                throw new Exception('DELETE method required');
            }
            $userId = $_GET['user_id'] ?? null;
            $clubId = $_GET['club_id'] ?? null;
            if (!$userId || !$clubId) {
                throw new Exception('user_id and club_id required');
            }
            handleRemoveClubAccess($pdo, (int)$userId, (int)$clubId);
            break;

        default:
            http_response_code(404);
            echo json_encode(['error' => 'Action not found']);
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * Get platform-wide statistics
 */
function handleGetStats($pdo) {
    $stats = [];

    // Total clubs
    $stmt = $pdo->query("SELECT COUNT(*) FROM club_profile");
    $stats['total_clubs'] = (int)$stmt->fetchColumn();

    // Total users
    $stmt = $pdo->query("SELECT COUNT(*) FROM users");
    $stats['total_users'] = (int)$stmt->fetchColumn();

    // Total teams
    $stmt = $pdo->query("SELECT COUNT(*) FROM teams");
    $stats['total_teams'] = (int)$stmt->fetchColumn();

    // Total athletes
    $stmt = $pdo->query("SELECT COUNT(*) FROM athletes");
    $stats['total_athletes'] = (int)$stmt->fetchColumn();

    // Super admins count
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE system_role = 'super_admin'");
    $stats['super_admins'] = (int)$stmt->fetchColumn();

    // Active club admins
    $stmt = $pdo->query("SELECT COUNT(DISTINCT user_id) FROM user_club_access WHERE role = 'club_admin' AND active = true");
    $stats['club_admins'] = (int)$stmt->fetchColumn();

    // Active coaches
    $stmt = $pdo->query("SELECT COUNT(DISTINCT user_id) FROM user_club_access WHERE role = 'coach' AND active = true");
    $stats['coaches'] = (int)$stmt->fetchColumn();

    echo json_encode(['success' => true, 'stats' => $stats]);
}

/**
 * Get all clubs with counts
 */
function handleGetClubs($pdo) {
    $search = $_GET['search'] ?? '';

    $sql = "
        SELECT
            c.id,
            c.name,
            c.created_at,
            (SELECT COUNT(*) FROM user_club_access WHERE club_profile_id = c.id AND role = 'club_admin' AND active = true) as admin_count,
            (SELECT COUNT(*) FROM user_club_access WHERE club_profile_id = c.id AND role = 'coach' AND active = true) as coach_count,
            (SELECT COUNT(*) FROM teams WHERE club_id = c.id) as team_count,
            (SELECT COUNT(*) FROM athletes WHERE club_id = c.id) as athlete_count
        FROM club_profile c
        WHERE 1=1
    ";

    $params = [];

    if ($search) {
        $sql .= " AND c.name ILIKE ?";
        $params[] = "%$search%";
    }

    $sql .= " ORDER BY c.name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $clubs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'clubs' => $clubs]);
}

/**
 * Get single club details with teams and users
 */
function handleGetClubDetails($pdo, $clubId) {
    // Get club info
    $stmt = $pdo->prepare("SELECT * FROM club_profile WHERE id = ?");
    $stmt->execute([$clubId]);
    $club = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$club) {
        throw new Exception('Club not found');
    }

    // Get teams
    $stmt = $pdo->prepare("
        SELECT
            t.id,
            t.name,
            t.age_group,
            t.gender,
            (SELECT COUNT(*) FROM team_members WHERE team_id = t.id) as athlete_count,
            (SELECT CONCAT(u.first_name, ' ', u.last_name)
             FROM users u
             WHERE u.id = t.primary_coach_id) as coach_name
        FROM teams t
        WHERE t.club_id = ?
        ORDER BY t.name ASC
    ");
    $stmt->execute([$clubId]);
    $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get users with access to this club
    $stmt = $pdo->prepare("
        SELECT
            u.id,
            u.first_name,
            u.last_name,
            u.email,
            u.system_role,
            uca.role as club_role,
            uca.granted_at
        FROM users u
        JOIN user_club_access uca ON u.id = uca.user_id
        WHERE uca.club_profile_id = ? AND uca.active = true
        ORDER BY
            CASE uca.role
                WHEN 'club_admin' THEN 1
                WHEN 'coach' THEN 2
                WHEN 'parent' THEN 3
                ELSE 4
            END,
            u.first_name ASC
    ");
    $stmt->execute([$clubId]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'club' => $club,
        'teams' => $teams,
        'users' => $users
    ]);
}

/**
 * Get all users
 */
function handleGetUsers($pdo) {
    $search = $_GET['search'] ?? '';

    $sql = "
        SELECT
            u.id,
            u.first_name,
            u.last_name,
            u.email,
            u.system_role,
            u.created_at,
            u.last_login_at,
            (SELECT COUNT(*) FROM user_club_access WHERE user_id = u.id AND active = true) as club_count
        FROM users u
        WHERE 1=1
    ";

    $params = [];

    if ($search) {
        $sql .= " AND (u.first_name ILIKE ? OR u.last_name ILIKE ? OR u.email ILIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $sql .= " ORDER BY u.first_name ASC, u.last_name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'users' => $users]);
}

/**
 * Get single user details with all club roles
 */
function handleGetUserDetails($pdo, $userId) {
    // Get user info
    $stmt = $pdo->prepare("
        SELECT id, first_name, last_name, email, system_role, created_at, last_login_at
        FROM users WHERE id = ?
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new Exception('User not found');
    }

    // Get all club roles
    $stmt = $pdo->prepare("
        SELECT
            uca.id as access_id,
            uca.role,
            uca.granted_at,
            c.id as club_id,
            c.name as club_name
        FROM user_club_access uca
        JOIN club_profile c ON uca.club_profile_id = c.id
        WHERE uca.user_id = ? AND uca.active = true
        ORDER BY c.name ASC
    ");
    $stmt->execute([$userId]);
    $clubRoles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'user' => $user,
        'club_roles' => $clubRoles
    ]);
}

/**
 * Update user's system role (grant/revoke super_admin)
 */
function handleUpdateSystemRole($pdo, $input, $auth) {
    $userId = $input['user_id'] ?? null;
    $systemRole = $input['system_role'] ?? null;

    if (!$userId || !$systemRole) {
        throw new Exception('user_id and system_role required');
    }

    if (!in_array($systemRole, ['user', 'super_admin'])) {
        throw new Exception('Invalid system_role. Must be "user" or "super_admin"');
    }

    // Prevent removing your own super admin access
    if ($userId == $auth->getUserId() && $systemRole === 'user') {
        throw new Exception('Cannot remove your own super admin access');
    }

    $stmt = $pdo->prepare("UPDATE users SET system_role = ? WHERE id = ?");
    $stmt->execute([$systemRole, $userId]);

    echo json_encode(['success' => true, 'message' => 'System role updated']);
}

/**
 * Update user's role within a club
 */
function handleUpdateClubRole($pdo, $input) {
    $userId = $input['user_id'] ?? null;
    $clubId = $input['club_id'] ?? null;
    $role = $input['role'] ?? null;

    if (!$userId || !$clubId || !$role) {
        throw new Exception('user_id, club_id, and role required');
    }

    if (!in_array($role, ['club_admin', 'coach', 'parent', 'player'])) {
        throw new Exception('Invalid role');
    }

    $stmt = $pdo->prepare("
        UPDATE user_club_access
        SET role = ?
        WHERE user_id = ? AND club_profile_id = ? AND active = true
    ");
    $stmt->execute([$role, $userId, $clubId]);

    if ($stmt->rowCount() === 0) {
        throw new Exception('User club access not found');
    }

    echo json_encode(['success' => true, 'message' => 'Club role updated']);
}

/**
 * Remove user's access to a club
 */
function handleRemoveClubAccess($pdo, $userId, $clubId) {
    $stmt = $pdo->prepare("
        UPDATE user_club_access
        SET active = false, revoked_at = CURRENT_TIMESTAMP
        WHERE user_id = ? AND club_profile_id = ? AND active = true
    ");
    $stmt->execute([$userId, $clubId]);

    if ($stmt->rowCount() === 0) {
        throw new Exception('User club access not found');
    }

    echo json_encode(['success' => true, 'message' => 'User removed from club']);
}
?>
