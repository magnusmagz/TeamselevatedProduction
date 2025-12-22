<?php
/**
 * Coach Profile API
 *
 * Endpoints:
 * - GET /api/coach-profile.php?id={coach_id} - Fetch coach profile with teams
 * - PUT /api/coach-profile.php?id={coach_id} - Update coach profile
 * - PUT /api/coach-profile.php?id={coach_id}&action=archive - Archive coach
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/JWT.php';

// Get database connection
$pdo = Database::getInstance()->getConnection();

// Get authorization header
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

if (!$authHeader || !preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No authorization token provided']);
    exit();
}

$token = $matches[1];

try {
    $jwt = new JWT();
    $decoded = $jwt->decode($token);
    $currentUserId = $decoded->user_id;
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid or expired token']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$coachId = $_GET['id'] ?? null;
$action = $_GET['action'] ?? null;

if (!$coachId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Coach ID is required']);
    exit();
}

// Check if user is admin (for certain actions)
function isAdmin($pdo, $userId) {
    $stmt = $pdo->prepare("
        SELECT system_role, role
        FROM users
        WHERE id = :user_id
    ");
    $stmt->execute(['user_id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) return false;

    // Check if super admin
    if ($user['system_role'] === 'super_admin' || $user['role'] === 'admin') {
        return true;
    }

    // Check if league admin or club admin
    $stmt = $pdo->prepare("
        SELECT role
        FROM user_league_access
        WHERE user_id = :user_id
        AND role IN ('league_admin', 'club_admin')
        LIMIT 1
    ");
    $stmt->execute(['user_id' => $userId]);
    $access = $stmt->fetch(PDO::FETCH_ASSOC);

    return $access !== false;
}

if ($method === 'GET') {
    // Fetch coach profile
    try {
        // Get coach basic info
        $stmt = $pdo->prepare("
            SELECT
                u.id,
                u.first_name,
                u.last_name,
                u.email,
                u.phone,
                u.profile_image_url,
                u.coaching_background,
                u.archived,
                u.created_at
            FROM users u
            WHERE u.id = :coach_id
            AND (u.role = 'coach' OR u.id IN (
                SELECT DISTINCT primary_coach_id
                FROM teams
                WHERE primary_coach_id IS NOT NULL
            ))
        ");
        $stmt->execute(['coach_id' => $coachId]);
        $coach = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$coach) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Coach not found']);
            exit();
        }

        // Get coach's teams
        $stmt = $pdo->prepare("
            SELECT
                t.id,
                t.name,
                t.age_group,
                t.gender,
                t.status,
                t.division,
                t.skill_level,
                s.name as season_name,
                c.name as club_name,
                l.name as league_name,
                (SELECT COUNT(*) FROM team_members tm WHERE tm.team_id = t.id) as athlete_count
            FROM teams t
            LEFT JOIN seasons s ON t.season_id = s.id
            LEFT JOIN club_profile c ON t.club_id = c.id
            LEFT JOIN leagues l ON t.league_id = l.id
            WHERE t.primary_coach_id = :coach_id
            ORDER BY t.created_at DESC
        ");
        $stmt->execute(['coach_id' => $coachId]);
        $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate stats
        $stats = [
            'total_teams' => count($teams),
            'active_teams' => count(array_filter($teams, fn($t) => $t['status'] === 'active')),
            'total_athletes' => array_sum(array_column($teams, 'athlete_count'))
        ];

        echo json_encode([
            'success' => true,
            'coach' => $coach,
            'teams' => $teams,
            'stats' => $stats
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }

} elseif ($method === 'PUT') {

    if ($action === 'archive') {
        // Archive coach (admin only)
        if (!isAdmin($pdo, $currentUserId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Only admins can archive coaches']);
            exit();
        }

        try {
            $stmt = $pdo->prepare("
                UPDATE users
                SET archived = TRUE, updated_at = CURRENT_TIMESTAMP
                WHERE id = :coach_id
            ");
            $stmt->execute(['coach_id' => $coachId]);

            echo json_encode([
                'success' => true,
                'message' => 'Coach archived successfully'
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        }
        exit();
    }

    // Update coach profile
    $data = json_decode(file_get_contents('php://input'), true);

    if (!$data) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid request data']);
        exit();
    }

    // Check permissions: coach can edit own profile, admins can edit any coach
    if ($currentUserId != $coachId && !isAdmin($pdo, $currentUserId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'You do not have permission to edit this profile']);
        exit();
    }

    try {
        $pdo->beginTransaction();

        $updateFields = [];
        $params = ['coach_id' => $coachId];

        if (isset($data['first_name'])) {
            $updateFields[] = "first_name = :first_name";
            $params['first_name'] = trim($data['first_name']);
        }

        if (isset($data['last_name'])) {
            $updateFields[] = "last_name = :last_name";
            $params['last_name'] = trim($data['last_name']);
        }

        if (isset($data['email'])) {
            // Check if email is already taken by another user
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email AND id != :coach_id");
            $stmt->execute(['email' => $data['email'], 'coach_id' => $coachId]);
            if ($stmt->fetch()) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Email is already in use']);
                exit();
            }

            $updateFields[] = "email = :email";
            $params['email'] = trim($data['email']);
        }

        if (isset($data['phone'])) {
            $updateFields[] = "phone = :phone";
            $params['phone'] = $data['phone'] ? trim($data['phone']) : null;
        }

        if (isset($data['profile_image_url'])) {
            $updateFields[] = "profile_image_url = :profile_image_url";
            $params['profile_image_url'] = $data['profile_image_url'] ? trim($data['profile_image_url']) : null;
        }

        if (isset($data['coaching_background'])) {
            $updateFields[] = "coaching_background = :coaching_background";
            $params['coaching_background'] = $data['coaching_background'] ? trim($data['coaching_background']) : null;
        }

        if (!empty($updateFields)) {
            $updateFields[] = "updated_at = CURRENT_TIMESTAMP";
            $sql = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = :coach_id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        }

        $pdo->commit();

        // Fetch updated coach data
        $stmt = $pdo->prepare("
            SELECT
                id, first_name, last_name, email, phone,
                profile_image_url, coaching_background, archived, created_at
            FROM users
            WHERE id = :coach_id
        ");
        $stmt->execute(['coach_id' => $coachId]);
        $coach = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'message' => 'Profile updated successfully',
            'coach' => $coach
        ]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
}
?>
