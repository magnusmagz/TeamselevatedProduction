<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();


require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/JWT.php';
require_once __DIR__ . '/../lib/guardian_sync.php';
require_once __DIR__ . '/../lib/AuditLogger.php';

// Get database connection
$pdo = Database::getInstance()->getConnection();

// Get the authorization header
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

if (!$authHeader || !preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No authorization token provided']);
    exit();
}

$token = $matches[1];

// Signature-VERIFIED. Until 2026-09-02 this was JWT::decode(), which never checks
// the signature — a hand-built token with any user_id passed as that user.
$decoded = JWT::verify($token);
if (!$decoded || empty($decoded->user_id)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid or expired token']);
    exit();
}
$userId = $decoded->user_id;

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Fetch user's profile
    try {
        $stmt = $pdo->prepare("
            SELECT id, email, first_name, last_name, phone, email_signature, created_at
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

        if (isset($data['email_signature'])) {
            $updateFields[] = "email_signature = :email_signature";
            $params['email_signature'] = $data['email_signature'];
        }

        if (isset($data['phone'])) {
            $updateFields[] = "phone = :phone";
            $params['phone'] = trim($data['phone']);
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

        // The guardian row still carries the OLD email and name, so the match has
        // to be made against the pre-update values.
        $beforeStmt = $pdo->prepare('SELECT id, email, first_name, last_name FROM users WHERE id = :user_id');
        $beforeStmt->execute(['user_id' => $userId]);
        $beforeUser = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        if (!empty($updateFields)) {
            $updateFields[] = "updated_at = CURRENT_TIMESTAMP";
            $sql = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = :user_id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            // Mirror the contact change onto the guardians row, which is what the
            // club actually sees (Crew page, sends, exports). users holds the
            // login; guardians holds the club contact. Until 2026-08-04 only the
            // first was written, so a parent could update their details and the
            // club would keep the old ones indefinitely.
            $contactChanges = array_intersect_key(
                $params,
                array_flip(['email', 'first_name', 'last_name', 'phone'])
            );

            if ($contactChanges && $beforeUser) {
                $sync = te_sync_guardian_contact($pdo, $beforeUser, $contactChanges);

                // Audited either way. A change that matched no guardian row is not
                // an error, but it means the club still holds the old details, and
                // that is exactly the thing someone will need to look up later.
                AuditLogger::log(
                    $pdo,
                    $userId,
                    $sync['updated'] > 0 ? 'profile_guardian_synced' : 'profile_guardian_sync_no_match',
                    'guardians',
                    $sync['guardian_ids'][0] ?? null,
                    [
                        'fields' => array_keys($contactChanges),
                        'old_email' => $beforeUser['email'] ?? null,
                        'new_email' => $contactChanges['email'] ?? null,
                        'guardian_ids' => $sync['guardian_ids'],
                        'guardian_rows_updated' => $sync['updated'],
                        'old_email_shared_with_others' => $sync['shared_email'],
                    ]
                );
            }
        }

        $pdo->commit();

        // Fetch updated user data
        $stmt = $pdo->prepare("
            SELECT id, email, first_name, last_name, phone, email_signature, created_at
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
