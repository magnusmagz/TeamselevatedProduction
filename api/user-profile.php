<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();


require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/JWT.php';
require_once __DIR__ . '/../lib/guardian_sync.php';
require_once __DIR__ . '/../lib/AuditLogger.php';
require_once __DIR__ . '/../lib/signature_html.php';
require_once __DIR__ . '/../lib/coach_access.php';

// Get database connection
$pdo = Database::getInstance()->getConnection();

// users.email_signature_format (migration 092) may not be applied yet — `main`
// is shared, deploys are by push, and migrations are run by hand, so this file
// reaches production before the SQL does. Naming a missing column on Postgres is
// 42703, which would 500 the whole profile page. Probe once, then build both the
// SELECT list and the UPDATE around what is actually there. Absent, every
// signature reads as 'text', which is exactly today's behaviour.
$signatureFormatLive = te_signature_format_column_present($pdo);
$signatureFormatSelect = $signatureFormatLive
    ? 'email_signature_format'
    : "'text' AS email_signature_format";

// users.password_set_by_admin_at (migration 097), same treatment. Reported so
// the staff dashboard can show its one-line "change it" banner; cleared by the
// password change below. Absent, it reads as NULL and no banner is shown.
$adminSetMarkLive = te_password_set_by_admin_column_present($pdo);
$adminSetMarkSelect = $adminSetMarkLive
    ? 'password_set_by_admin_at'
    : 'NULL AS password_set_by_admin_at';

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
            SELECT id, email, first_name, last_name, phone, email_signature,
                   $signatureFormatSelect, $adminSetMarkSelect, created_at
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

            // Update password. A password the user chose themselves clears the
            // admin-set mark in the same statement, so the banner cannot outlive
            // the temporary password it is about.
            $newHash = password_hash($data['new_password'], PASSWORD_DEFAULT);
            $clearMark = $adminSetMarkLive ? ', password_set_by_admin_at = NULL' : '';
            $stmt = $pdo->prepare("
                UPDATE users
                SET password_hash = :password_hash, updated_at = CURRENT_TIMESTAMP $clearMark
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

        // The signature, in whichever of its two shapes the client sent.
        //
        // `email_signature_html` is the rich editor and is SANITISED HERE, before
        // the value reaches the database — this endpoint is the choke point, so
        // the column only ever holds markup that has been through the allowlist
        // and services/EmailSendService.php can emit it without a second parse.
        // Any future writer of users.email_signature has to call the sanitiser
        // too; SignatureHtmlTest parses this file and fails if this call goes.
        //
        // `email_signature` is the plain textarea and is stored verbatim, which
        // is safe only because te_render_signature_html() escapes it at send
        // time. It was NOT escaped before 2026-09-02.
        //
        // The rich key wins when both are present. A client that sends both is
        // confused, and resolving it toward the sanitised value is the answer
        // that cannot lose data — the raw one is recoverable from it, not the
        // other way round.
        if (isset($data['email_signature_html'])) {
            $updateFields[] = "email_signature = :email_signature";
            $params['email_signature'] = te_sanitize_signature_html((string) $data['email_signature_html']);
            if ($signatureFormatLive) {
                $updateFields[] = "email_signature_format = :email_signature_format";
                $params['email_signature_format'] = 'html';
            }
        } elseif (isset($data['email_signature'])) {
            $updateFields[] = "email_signature = :email_signature";
            $params['email_signature'] = $data['email_signature'];
            // Stamped back to 'text' explicitly. A staff member who moves from
            // the rich editor back to the plain textarea must not leave the row
            // claiming to be HTML, or their next signature ships unescaped.
            if ($signatureFormatLive) {
                $updateFields[] = "email_signature_format = :email_signature_format";
                $params['email_signature_format'] = 'text';
            }
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
            SELECT id, email, first_name, last_name, phone, email_signature,
                   $signatureFormatSelect, $adminSetMarkSelect, created_at
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
