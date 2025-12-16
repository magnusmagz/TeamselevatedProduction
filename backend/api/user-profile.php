<?php
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

// Get the authorization header
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
    $userId = $decoded->user_id;
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid or expired token']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Fetch user's profile
    try {
        $stmt = $pdo->prepare("
            SELECT id, email, first_name, last_name, created_at
            FROM users
            WHERE id = :user_id
        ");
        $stmt->execute(['user_id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'User not found']);
            exit();
        }

        echo json_encode([
            'success' => true,
            'user' => $user
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
} elseif ($method === 'PUT') {
    // Update user's profile
    $data = json_decode(file_get_contents('php://input'), true);

    if (!$data) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid request data']);
        exit();
    }

    try {
        $pdo->beginTransaction();

        // First, verify current password if password change is requested
        if (!empty($data['new_password'])) {
            if (empty($data['current_password'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Current password is required to set a new password']);
                exit();
            }

            // Get current password hash
            $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = :user_id");
            $stmt->execute(['user_id' => $userId]);
            $currentHash = $stmt->fetchColumn();

            if (!password_verify($data['current_password'], $currentHash)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Current password is incorrect']);
                exit();
            }

            // Update password
            $newHash = password_hash($data['new_password'], PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("
                UPDATE users
                SET password_hash = :password_hash, updated_at = CURRENT_TIMESTAMP
                WHERE id = :user_id
            ");
            $stmt->execute([
                'password_hash' => $newHash,
                'user_id' => $userId
            ]);
        }

        // Update basic profile fields
        $updateFields = [];
        $params = ['user_id' => $userId];

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
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email AND id != :user_id");
            $stmt->execute(['email' => $data['email'], 'user_id' => $userId]);
            if ($stmt->fetch()) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Email is already in use']);
                exit();
            }

            $updateFields[] = "email = :email";
            $params['email'] = trim($data['email']);

            // Note: In production, you'd want to send a verification email and mark email_verified_at as null
            // For now, we'll allow the change without re-verification
        }

        if (!empty($updateFields)) {
            $updateFields[] = "updated_at = CURRENT_TIMESTAMP";
            $sql = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = :user_id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        }

        $pdo->commit();

        // Fetch updated user data
        $stmt = $pdo->prepare("
            SELECT id, email, first_name, last_name, created_at
            FROM users
            WHERE id = :user_id
        ");
        $stmt->execute(['user_id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'message' => 'Profile updated successfully',
            'user' => $user
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
