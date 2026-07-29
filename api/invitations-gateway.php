<?php
/**
 * Invitations Gateway API
 * Handles invitation management for clubs
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();


require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/JWT.php';
require_once __DIR__ . '/../lib/Email.php';

$db = Database::getInstance();
$conn = $db->getConnection();

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

/**
 * Get authenticated user from JWT
 */
function getAuthenticatedUser($headers) {
    if (!isset($headers['Authorization'])) {
        return null;
    }

    $authHeader = $headers['Authorization'];
    $token = str_replace('Bearer ', '', $authHeader);

    try {
        $payload = JWT::decode($token);
        // JWT::decode returns stdClass object, not array
        return $payload->user_id ?? null;
    } catch (Exception $e) {
        error_log('JWT decode error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Generate random invitation code
 */
function generateInvitationCode($length = 8) {
    $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // Exclude similar looking chars
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $characters[random_int(0, strlen($characters) - 1)];
    }
    return $code;
}

/**
 * Send email invitations
 * POST ?action=send
 */
function handleSendInvitations($conn, $input, $userId) {
    if (!isset($input['emails']) || !is_array($input['emails']) || count($input['emails']) === 0) {
        http_response_code(400);
        return ['error' => 'Email addresses are required'];
    }

    $emails = $input['emails'];
    $role = $input['role'] ?? 'coach';
    $clubId = $input['clubId'] ?? null;
    $personalMessage = $input['personalMessage'] ?? '';

    // Validate: must have clubId
    if (!$clubId) {
        http_response_code(400);
        return ['error' => 'clubId is required'];
    }

    // Get inviter info
    $stmt = $conn->prepare('SELECT first_name, last_name, email FROM users WHERE id = :user_id');
    $stmt->execute(['user_id' => $userId]);
    $inviter = $stmt->fetch();

    if (!$inviter) {
        http_response_code(404);
        return ['error' => 'User not found'];
    }

    $inviterName = trim($inviter['first_name'] . ' ' . $inviter['last_name']);

    // Get club name
    $stmt = $conn->prepare('SELECT name FROM club_profile WHERE id = :id');
    $stmt->execute(['id' => $clubId]);
    $org = $stmt->fetch();
    $orgName = $org['name'] ?? 'Unknown Club';

    $sentInvitations = [];
    $errors = [];

    foreach ($emails as $email) {
        $email = strtolower(trim($email));
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid email: $email";
            continue;
        }

        try {
            // Check if invitation already exists (pending)
            $stmt = $conn->prepare('
                SELECT id FROM invitations
                WHERE email = :email
                  AND club_profile_id = :club_id
                  AND status = \'pending\'
            ');
            $stmt->execute([
                'email' => $email,
                'club_id' => $clubId
            ]);
            $existing = $stmt->fetch();

            if ($existing) {
                $errors[] = "Invitation already exists for $email";
                continue;
            }

            // Create invitation
            $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));

            $stmt = $conn->prepare('
                INSERT INTO invitations (
                    club_profile_id, email, role, status,
                    invited_by, personal_message, expires_at, created_at
                ) VALUES (
                    :club_id, :email, :role, \'pending\',
                    :invited_by, :message, :expires_at, CURRENT_TIMESTAMP
                )
                RETURNING id
            ');

            $stmt->execute([
                'club_id' => $clubId,
                'email' => $email,
                'role' => $role,
                'invited_by' => $userId,
                'message' => $personalMessage ?: null,
                'expires_at' => $expiresAt
            ]);

            $result = $stmt->fetch();
            $invitationId = $result['id'];

            // Generate invitation link
            $appUrl = getenv('APP_URL') ?: 'https://teams-elevated.netlify.app';
            $invitationLink = "$appUrl/accept-invitation?id=$invitationId";

            // Send email
            $emailSender = new Email();
            $emailSender->sendTeamInvitation(
                $email,
                $orgName,
                $inviterName,
                $invitationLink,
                $personalMessage
            );

            $sentInvitations[] = [
                'email' => $email,
                'invitationId' => $invitationId,
                'link' => $invitationLink
            ];

        } catch (Exception $e) {
            error_log('Error creating invitation for ' . $email . ': ' . $e->getMessage());
            $errors[] = "Failed to send invitation to $email";
        }
    }

    return [
        'success' => count($sentInvitations) > 0,
        'sent' => $sentInvitations,
        'errors' => $errors,
        'message' => count($sentInvitations) . ' invitation(s) sent'
    ];
}

/**
 * Create shareable invitation link
 * POST ?action=create-link
 */
function handleCreateLink($conn, $input, $userId) {
    $clubId = $input['clubId'] ?? null;
    $role = $input['role'] ?? 'coach';
    $maxUses = $input['maxUses'] ?? null;

    // Validate: must have clubId
    if (!$clubId) {
        http_response_code(400);
        return ['error' => 'clubId is required'];
    }

    // Generate unique code
    $code = generateInvitationCode();
    $attempts = 0;
    while ($attempts < 10) {
        $stmt = $conn->prepare('SELECT id FROM invitation_links WHERE code = :code');
        $stmt->execute(['code' => $code]);
        if (!$stmt->fetch()) {
            break;
        }
        $code = generateInvitationCode();
        $attempts++;
    }

    if ($attempts >= 10) {
        http_response_code(500);
        return ['error' => 'Failed to generate unique code'];
    }

    // Create invitation link
    $expiresAt = date('Y-m-d H:i:s', strtotime('+90 days'));

    $stmt = $conn->prepare('
        INSERT INTO invitation_links (
            club_profile_id, code, role, created_by,
            max_uses, expires_at, created_at
        ) VALUES (
            :club_id, :code, :role, :created_by,
            :max_uses, :expires_at, CURRENT_TIMESTAMP
        )
        RETURNING id
    ');

    $stmt->execute([
        'club_id' => $clubId,
        'code' => $code,
        'role' => $role,
        'created_by' => $userId,
        'max_uses' => $maxUses,
        'expires_at' => $expiresAt
    ]);

    $result = $stmt->fetch();
    $linkId = $result['id'];

    $appUrl = getenv('APP_URL') ?: 'https://teams-elevated.netlify.app';
    $url = "$appUrl/accept-invitation?code=$code";

    return [
        'success' => true,
        'id' => $linkId,
        'code' => $code,
        'url' => $url,
        'expiresAt' => $expiresAt
    ];
}

/**
 * List invitations
 * GET ?action=list&clubId=X&status=pending
 */
function handleListInvitations($conn, $userId) {
    $clubId = $_GET['clubId'] ?? null;
    $status = $_GET['status'] ?? null;

    // Build query
    $where = ['invited_by = :user_id'];
    $params = ['user_id' => $userId];

    if ($clubId) {
        $where[] = 'club_profile_id = :club_id';
        $params['club_id'] = $clubId;
    }
    if ($status) {
        $where[] = 'status = :status';
        $params['status'] = $status;
    }

    $whereClause = implode(' AND ', $where);

    $stmt = $conn->prepare("
        SELECT
            i.id, i.email, i.role, i.status, i.personal_message,
            i.created_at, i.accepted_at, i.expires_at,
            i.club_profile_id,
            u.first_name || ' ' || u.last_name as inviter_name
        FROM invitations i
        LEFT JOIN users u ON i.invited_by = u.id
        WHERE $whereClause
        ORDER BY i.created_at DESC
    ");

    $stmt->execute($params);
    $invitations = $stmt->fetchAll();

    // Get invitation links
    $where = ['created_by = :user_id', 'is_active = TRUE'];
    $params = ['user_id' => $userId];

    if ($clubId) {
        $where[] = 'club_profile_id = :club_id';
        $params['club_id'] = $clubId;
    }

    $whereClause = implode(' AND ', $where);

    $stmt = $conn->prepare("
        SELECT
            il.id, il.code, il.role, il.max_uses, il.uses_count,
            il.created_at, il.expires_at,
            u.first_name || ' ' || u.last_name as creator_name
        FROM invitation_links il
        LEFT JOIN users u ON il.created_by = u.id
        WHERE $whereClause
        ORDER BY il.created_at DESC
    ");

    $stmt->execute($params);
    $links = $stmt->fetchAll();

    // Add URLs to links
    $appUrl = getenv('APP_URL') ?: 'https://teams-elevated.netlify.app';
    foreach ($links as &$link) {
        $link['url'] = "$appUrl/accept-invitation?code=" . $link['code'];
    }

    return [
        'success' => true,
        'invitations' => $invitations,
        'invitationLinks' => $links
    ];
}

/**
 * Get invitation info (public endpoint for acceptance page)
 * GET ?action=info&id=X or &code=X
 */
function handleGetInvitationInfo($conn) {
    $id = $_GET['id'] ?? null;
    $code = $_GET['code'] ?? null;

    if ($id) {
        // Email invitation
        $stmt = $conn->prepare('
            SELECT
                i.id, i.email, i.role, i.personal_message, i.status, i.expires_at,
                i.club_profile_id,
                c.name as club_name,
                u.first_name || \' \' || u.last_name as inviter_name
            FROM invitations i
            LEFT JOIN club_profile c ON i.club_profile_id = c.id
            LEFT JOIN users u ON i.invited_by = u.id
            WHERE i.id = :id
        ');
        $stmt->execute(['id' => $id]);
        $invitation = $stmt->fetch();

        if (!$invitation) {
            http_response_code(404);
            return ['error' => 'Invitation not found'];
        }

        if ($invitation['status'] !== 'pending') {
            http_response_code(400);
            return ['error' => 'Invitation is no longer valid'];
        }

        if (strtotime($invitation['expires_at']) < time()) {
            http_response_code(400);
            return ['error' => 'Invitation has expired'];
        }

        return [
            'success' => true,
            'type' => 'email',
            'invitationId' => $invitation['id'],
            'email' => $invitation['email'],
            'role' => $invitation['role'],
            'organizationName' => $invitation['club_name'],
            'organizationType' => 'club',
            'inviterName' => $invitation['inviter_name'],
            'personalMessage' => $invitation['personal_message']
        ];
    } elseif ($code) {
        // Shareable link
        $stmt = $conn->prepare('
            SELECT
                il.id, il.code, il.role, il.max_uses, il.uses_count,
                il.expires_at, il.is_active,
                il.club_profile_id,
                c.name as club_name,
                u.first_name || \' \' || u.last_name as creator_name
            FROM invitation_links il
            LEFT JOIN club_profile c ON il.club_profile_id = c.id
            LEFT JOIN users u ON il.created_by = u.id
            WHERE il.code = :code
        ');
        $stmt->execute(['code' => $code]);
        $link = $stmt->fetch();

        if (!$link) {
            http_response_code(404);
            return ['error' => 'Invitation link not found'];
        }

        if (!$link['is_active']) {
            http_response_code(400);
            return ['error' => 'Invitation link is no longer active'];
        }

        if (strtotime($link['expires_at']) < time()) {
            http_response_code(400);
            return ['error' => 'Invitation link has expired'];
        }

        if ($link['max_uses'] && $link['uses_count'] >= $link['max_uses']) {
            http_response_code(400);
            return ['error' => 'Invitation link has reached maximum uses'];
        }

        return [
            'success' => true,
            'type' => 'link',
            'invitationId' => $link['id'],
            'code' => $link['code'],
            'role' => $link['role'],
            'organizationName' => $link['club_name'],
            'organizationType' => 'club',
            'creatorName' => $link['creator_name'],
            'usesRemaining' => $link['max_uses'] ? ($link['max_uses'] - $link['uses_count']) : null
        ];
    } else {
        http_response_code(400);
        return ['error' => 'Either id or code is required'];
    }
}

/**
 * Accept invitation (public endpoint)
 * POST ?action=accept
 */
function handleAcceptInvitation($conn, $input) {
    $id = $input['id'] ?? null;
    $code = $input['code'] ?? null;
    $name = $input['name'] ?? '';
    $email = $input['email'] ?? '';

    if ($id) {
        // Accept email invitation
        $stmt = $conn->prepare('
            SELECT i.*, c.name as club_name
            FROM invitations i
            LEFT JOIN club_profile c ON i.club_profile_id = c.id
            WHERE i.id = :id AND i.status = \'pending\'
        ');
        $stmt->execute(['id' => $id]);
        $invitation = $stmt->fetch();

        if (!$invitation) {
            http_response_code(404);
            return ['error' => 'Invitation not found or already used'];
        }

        if (strtotime($invitation['expires_at']) < time()) {
            http_response_code(400);
            return ['error' => 'Invitation has expired'];
        }

        $invitationEmail = $invitation['email'];
        $role = $invitation['role'];
        $clubId = $invitation['club_profile_id'];

    } elseif ($code) {
        // Accept via shareable link
        $stmt = $conn->prepare('
            SELECT il.*, c.name as club_name
            FROM invitation_links il
            LEFT JOIN club_profile c ON il.club_profile_id = c.id
            WHERE il.code = :code AND il.is_active = TRUE
        ');
        $stmt->execute(['code' => $code]);
        $link = $stmt->fetch();

        if (!$link) {
            http_response_code(404);
            return ['error' => 'Invitation link not found'];
        }

        if (strtotime($link['expires_at']) < time()) {
            http_response_code(400);
            return ['error' => 'Invitation link has expired'];
        }

        if ($link['max_uses'] && $link['uses_count'] >= $link['max_uses']) {
            http_response_code(400);
            return ['error' => 'Invitation link has reached maximum uses'];
        }

        if (!$email) {
            http_response_code(400);
            return ['error' => 'Email is required for link-based invitations'];
        }

        $invitationEmail = strtolower(trim($email));
        $role = $link['role'];
        $clubId = $link['club_profile_id'];

    } else {
        http_response_code(400);
        return ['error' => 'Either id or code is required'];
    }

    // For new users we need a name BEFORE we open the transaction so the
    // caller gets a clean 400 (not a half-applied write).
    $existingUser = null;
    $needsNewUser = false;
    $firstName = '';
    $lastName = '';

    $stmt = $conn->prepare('SELECT id FROM users WHERE email = :email');
    $stmt->execute(['email' => $invitationEmail]);
    $existingUser = $stmt->fetch();

    if (!$existingUser) {
        if (!$name) {
            http_response_code(400);
            return ['error' => 'Name is required for new users'];
        }
        $needsNewUser = true;
        $nameParts = explode(' ', trim($name), 2);
        $firstName = $nameParts[0];
        $lastName = $nameParts[1] ?? '';
    }

    // Run user-create + club-access grant + invitation status update as one
    // unit so a failure can't leave a user with no access (or vice versa).
    $userId = null;
    $manageTxn = !$conn->inTransaction();
    if ($manageTxn) {
        $conn->beginTransaction();
    }

    try {
        if ($existingUser) {
            $userId = $existingUser['id'];
        } else {
            // Create new user. No password — they authenticate via the
            // invitation/magic-link flow (auth_provider = 'invitation').
            $stmt = $conn->prepare('
                INSERT INTO users (first_name, last_name, email, auth_provider, created_at)
                VALUES (:first_name, :last_name, :email, \'invitation\', CURRENT_TIMESTAMP)
                RETURNING id
            ');
            $stmt->execute([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $invitationEmail
            ]);
            $result = $stmt->fetch();
            $userId = $result['id'];
        }

        // Grant club access (idempotent on the unique (user, club, role) key)
        $stmt = $conn->prepare('
            INSERT INTO user_club_access (user_id, club_profile_id, role, granted_at)
            VALUES (:user_id, :club_profile_id, :role, CURRENT_TIMESTAMP)
            ON CONFLICT (user_id, club_profile_id, role) DO NOTHING
        ');
        $stmt->execute([
            'user_id' => $userId,
            'club_profile_id' => $clubId,
            'role' => $role
        ]);

        // Mark invitation as accepted (if email invitation).
        // NOTE: only set columns that exist on the table — `accepted_by` was
        // never added by any migration, so writing it threw a SQL error and
        // failed the whole accept (this was the "Accept button does nothing"
        // bug). The accepting user's id is recoverable via the email link.
        if ($id) {
            $stmt = $conn->prepare('
                UPDATE invitations
                SET status = \'accepted\', accepted_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ');
            $stmt->execute(['id' => $id]);
        }

        // Increment link usage (if link invitation)
        if ($code) {
            $stmt = $conn->prepare('
                UPDATE invitation_links
                SET uses_count = uses_count + 1
                WHERE code = :code
            ');
            $stmt->execute(['code' => $code]);
        }

        if ($manageTxn) {
            $conn->commit();
        }
    } catch (Exception $e) {
        if ($manageTxn && $conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }

    // Generate login token for the user
    $userName = $name ?: ($existingUser ? '' : $name);
    $additionalClaims = [];
    if ($clubId) {
        $additionalClaims['club_id'] = $clubId;
    }
    $additionalClaims['role'] = $role;

    $token = JWT::generate($userId, $invitationEmail, $userName, $additionalClaims);

    return [
        'success' => true,
        'message' => 'Invitation accepted successfully',
        'token' => $token,
        'userId' => $userId,
        'role' => $role
    ];
}

/**
 * Minimum seconds between resends of the same invitation.
 * Prevents the "invited over and over" spam issue (CA-118).
 */
const RESEND_COOLDOWN_SECONDS = 120;

/**
 * Compute remaining cooldown (in seconds) for an invitation given its
 * last-sent timestamp. Returns 0 when a resend is allowed.
 *
 * Pulled out as a pure function so it can be unit tested without a DB.
 */
function resendCooldownRemaining($lastSentAt, $nowTs = null) {
    if (empty($lastSentAt)) {
        return 0; // never sent / unknown -> allow
    }
    $nowTs = $nowTs ?? time();
    $lastTs = is_numeric($lastSentAt) ? (int)$lastSentAt : strtotime($lastSentAt);
    if ($lastTs === false) {
        return 0;
    }
    $elapsed = $nowTs - $lastTs;
    $remaining = RESEND_COOLDOWN_SECONDS - $elapsed;
    return $remaining > 0 ? $remaining : 0;
}

/**
 * Resend invitation email
 * POST ?action=resend
 */
function handleResendInvitation($conn, $input, $userId) {
    $invitationId = $input['invitationId'] ?? null;

    if (!$invitationId) {
        http_response_code(400);
        return ['error' => 'Invitation ID is required'];
    }

    // Get invitation
    $stmt = $conn->prepare('
        SELECT i.*, c.name as club_name,
               u.first_name || \' \' || u.last_name as inviter_name
        FROM invitations i
        LEFT JOIN club_profile c ON i.club_profile_id = c.id
        LEFT JOIN users u ON i.invited_by = u.id
        WHERE i.id = :id AND i.invited_by = :user_id
    ');
    $stmt->execute(['id' => $invitationId, 'user_id' => $userId]);
    $invitation = $stmt->fetch();

    if (!$invitation) {
        http_response_code(404);
        return ['error' => 'Invitation not found'];
    }

    if ($invitation['status'] !== 'pending') {
        http_response_code(400);
        return ['error' => 'Can only resend pending invitations'];
    }

    // Cooldown guard — reject if the invitation was (re)sent very recently.
    // We use created_at as the "last sent" marker because the table has no
    // dedicated resend column (no migration allowed here) and created_at is
    // bumped to CURRENT_TIMESTAMP on every successful resend below.
    $remaining = resendCooldownRemaining($invitation['created_at'] ?? null);
    if ($remaining > 0) {
        http_response_code(429);
        return [
            'error' => 'This invitation was sent recently. Please wait before resending.',
            'retryAfterSeconds' => $remaining
        ];
    }

    // Generate new invitation link
    $appUrl = getenv('APP_URL') ?: 'https://teams-elevated.netlify.app';
    $invitationLink = "$appUrl/accept-invitation?id=" . $invitation['id'];

    $orgName = $invitation['club_name'];

    // Send email
    $emailSender = new Email();
    $emailSender->sendTeamInvitation(
        $invitation['email'],
        $orgName,
        $invitation['inviter_name'],
        $invitationLink,
        $invitation['personal_message']
    );

    // Record the send time so the cooldown applies to the next resend.
    $stmt = $conn->prepare('
        UPDATE invitations
        SET created_at = CURRENT_TIMESTAMP
        WHERE id = :id AND invited_by = :user_id
    ');
    $stmt->execute(['id' => $invitationId, 'user_id' => $userId]);

    return [
        'success' => true,
        'message' => 'Invitation resent successfully'
    ];
}

/**
 * Cancel invitation
 * POST ?action=cancel
 */
function handleCancelInvitation($conn, $input, $userId) {
    $invitationId = $input['invitationId'] ?? null;

    if (!$invitationId) {
        http_response_code(400);
        return ['error' => 'Invitation ID is required'];
    }

    $stmt = $conn->prepare('
        UPDATE invitations
        SET status = \'canceled\'
        WHERE id = :id AND invited_by = :user_id AND status = \'pending\'
        RETURNING id
    ');
    $stmt->execute(['id' => $invitationId, 'user_id' => $userId]);
    $result = $stmt->fetch();

    if (!$result) {
        http_response_code(404);
        return ['error' => 'Invitation not found or cannot be canceled'];
    }

    return [
        'success' => true,
        'message' => 'Invitation canceled successfully'
    ];
}

// Route handler
try {
    $headers = getallheaders();
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    // Public endpoints (no auth required)
    if ($method === 'GET' && $action === 'info') {
        $response = handleGetInvitationInfo($conn);
        echo json_encode($response);
        exit;
    }

    if ($method === 'POST' && $action === 'accept') {
        $response = handleAcceptInvitation($conn, $input);
        echo json_encode($response);
        exit;
    }

    // Protected endpoints (auth required)
    $userId = getAuthenticatedUser($headers);
    if (!$userId) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    if ($method === 'POST' && $action === 'send') {
        $response = handleSendInvitations($conn, $input, $userId);
    } elseif ($method === 'POST' && $action === 'create-link') {
        $response = handleCreateLink($conn, $input, $userId);
    } elseif ($method === 'GET' && $action === 'list') {
        $response = handleListInvitations($conn, $userId);
    } elseif ($method === 'POST' && $action === 'resend') {
        $response = handleResendInvitation($conn, $input, $userId);
    } elseif ($method === 'POST' && $action === 'cancel') {
        $response = handleCancelInvitation($conn, $input, $userId);
    } else {
        http_response_code(404);
        $response = ['error' => 'Endpoint not found'];
    }

    echo json_encode($response);

} catch (Exception $e) {
    error_log('Invitations API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error',
        'details' => getenv('APP_ENV') === 'development' ? $e->getMessage() : null
    ]);
}
