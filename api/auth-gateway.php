<?php
/**
 * Authentication Gateway API
 *
 * Handles all authentication-related endpoints:
 * - Magic link generation and verification
 * - Session management
 * - User login/logout
 */

header('Content-Type: application/json');

// Allow specific origin for CORS (required when using credentials)
$origin = $_SERVER['HTTP_ORIGIN'] ?? 'http://localhost:5173';
header('Access-Control-Allow-Origin: ' . $origin);

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../lib/JWT.php';
require_once __DIR__ . '/../lib/AuditLogger.php';

/** Bump when the Terms change so a future revision can require re-acceptance. */
const TOS_VERSION = '1.0';
require_once __DIR__ . '/../lib/Email.php';
require_once __DIR__ . '/../lib/parent_invite_token.php';
require_once __DIR__ . '/../lib/club_standing.php';

// Use existing MySQL database for now (will migrate to Neon later)
require_once __DIR__ . '/../config/database.php';
$db = Database::getInstance()->getConnection();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?: [];

try {
    switch ($action) {
        case 'send-magic-link':
            handleSendMagicLink($db, $input);
            break;

        case 'verify-magic-link':
            handleVerifyMagicLink($db, $input);
            break;

        case 'verify-session':
            handleVerifySession();
            break;

        case 'logout':
            handleLogout();
            break;

        case 'login':
            handlePasswordLogin($db, $input);
            break;

        case 'register':
            handleRegister($db, $input);
            break;

        case 'request-password-reset':
            handleRequestPasswordReset($db, $input);
            break;

        case 'reset-password':
            handleResetPassword($db, $input);
            break;

        case 'set-parent-password':
            handleSetParentPassword($db, $input);
            break;

        case 'send-parent-invite':
            handleSendParentInvite($db, $input);
            break;

        case 'parent-portal-status':
            handleParentPortalStatus($db, $input);
            break;

        case 'club-parents':
            handleClubParents($db, $input);
            break;

        case 'switch-context':
            handleSwitchContext($db, $input);
            break;

        default:
            http_response_code(404);
            echo json_encode(['error' => 'Action not found']);
    }

} catch (Exception $e) {
    error_log('Auth gateway error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error',
        'message' => Env::get('APP_ENV') === 'development' ? $e->getMessage() : null
    ]);
}

/**
 * Send magic link to user's email
 */
function handleSendMagicLink($db, $input) {
    if (empty($input['email'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Email is required']);
        return;
    }

    $email = strtolower(trim($input['email']));

    // Check if user exists
    $stmt = $db->prepare('SELECT id, first_name, last_name, email FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        // For security, don't reveal if user exists
        // But we'll send a different message internally
        error_log("Magic link requested for non-existent user: $email");

        // Return success anyway to prevent email enumeration
        echo json_encode([
            'success' => true,
            'message' => 'If an account exists with this email, a magic link has been sent.'
        ]);
        return;
    }

    // Generate secure token
    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + (15 * 60)); // 15 minutes

    // Store token in database
    $stmt = $db->prepare('
        INSERT INTO magic_link_tokens (email, token, expires_at, created_at)
        VALUES (?, ?, ?, CURRENT_TIMESTAMP)
    ');
    $stmt->execute([$email, $token, $expiresAt]);

    // Build magic link URL
    $appUrl = Env::get('APP_URL', 'http://localhost:3003');
    $magicLink = "$appUrl/verify-magic-link?token=$token";

    // Send email
    $emailService = new Email();
    $userName = trim($user['first_name'] . ' ' . $user['last_name']);
    $sent = $emailService->sendMagicLink($email, $userName, $magicLink);

    if (!$sent) {
        error_log("Failed to send magic link email to $email");
    }

    echo json_encode([
        'success' => true,
        'message' => 'Magic link sent to your email',
        'debug' => Env::get('APP_ENV') === 'development' ? ['link' => $magicLink] : null
    ]);
}

/**
 * Verify magic link token and create session
 */
function handleVerifyMagicLink($db, $input) {
    if (empty($input['token'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Token is required']);
        return;
    }

    $token = $input['token'];

    // Look up token
    $stmt = $db->prepare('
        SELECT id, email, expires_at, used_at
        FROM magic_link_tokens
        WHERE token = ?
        LIMIT 1
    ');
    $stmt->execute([$token]);
    $tokenData = $stmt->fetch();

    if (!$tokenData) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid or expired magic link']);
        return;
    }

    // Check if already used
    if ($tokenData['used_at'] !== null) {
        http_response_code(400);
        echo json_encode(['error' => 'This magic link has already been used']);
        return;
    }

    // Check if expired
    if (strtotime($tokenData['expires_at']) < time()) {
        http_response_code(400);
        echo json_encode(['error' => 'This magic link has expired']);
        return;
    }

    // Mark token as used
    $stmt = $db->prepare('UPDATE magic_link_tokens SET used_at = CURRENT_TIMESTAMP WHERE id = ?');
    $stmt->execute([$tokenData['id']]);

    // Get user details
    $stmt = $db->prepare('SELECT id, email, first_name, last_name FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$tokenData['email']]);
    $user = $stmt->fetch();

    if (!$user) {
        http_response_code(400);
        echo json_encode(['error' => 'User not found']);
        return;
    }

    // Update last login
    $stmt = $db->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?');
    $stmt->execute([$user['id']]);

    // Generate enhanced JWT with organizational context
    $userName = trim($user['first_name'] . ' ' . $user['last_name']);
    $jwt = JWT::generateEnhanced($db, $user['id'], $user['email'], $userName);

    // Decode to get user context for response
    $payload = JWT::decode($jwt);

    // Return JWT in response body (no cookie needed for cross-domain)
    echo json_encode([
        'success' => true,
        'message' => 'Authentication successful',
        'token' => $jwt,
        'user' => [
            'id' => (int)$user['id'],
            'email' => $user['email'],
            'name' => $userName,
            'system_role' => $payload->system_role ?? 'user',
            'organization' => [
                'orgId' => $payload->org_id ?? null,
                'orgType' => $payload->org_type ?? null,
                'orgName' => $payload->org_name ?? null
            ],
            'roles' => $payload->roles ?? [],
            'activeRole' => $payload->active_context ?? null
        ]
    ]);
}

/**
 * Verify current session (check if user is authenticated)
 */
function handleVerifySession() {
    global $db;

    // Check for JWT in Authorization header first, then fall back to cookie
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $jwt = null;

    if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        $jwt = $matches[1];
    } else {
        // Fallback to cookie for backwards compatibility
        $jwt = $_COOKIE['team-auth'] ?? null;
    }

    if (!$jwt) {
        echo json_encode([
            'authenticated' => false,
            'user' => null
        ]);
        return;
    }

    // Verify JWT
    $payload = JWT::verify($jwt);

    if (!$payload) {
        // Invalid or expired token
        echo json_encode([
            'authenticated' => false,
            'user' => null
        ]);
        return;
    }

    // Regenerate JWT with fresh database data (including updated league/club names)
    $userId = $payload->user_id;
    $stmt = $db->prepare('SELECT id, email, first_name, last_name FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        echo json_encode([
            'authenticated' => false,
            'user' => null
        ]);
        return;
    }

    // Get active context from current token (to maintain user's context selection)
    $activeContextScopeId = $payload->active_context->scope_id ?? null;
    $activeContextType = $payload->active_context->scope_type ?? null;

    // Generate fresh JWT with updated data from database
    $userName = trim($user['first_name'] . ' ' . $user['last_name']);
    $freshJwt = JWT::generateEnhanced($db, $user['id'], $user['email'], $userName, $activeContextScopeId, $activeContextType);

    // Decode the fresh token to get updated payload
    $freshPayload = JWT::decode($freshJwt);

    // Token is valid - return full organizational context with fresh data
    echo json_encode([
        'authenticated' => true,
        'token' => $freshJwt,  // Return the fresh token
        'user' => [
            'id' => (int)$user['id'],
            'email' => $user['email'],
            'name' => $userName,
            'system_role' => $freshPayload->system_role ?? 'user',
            'organization' => [
                'orgId' => $freshPayload->org_id ?? null,
                'orgType' => $freshPayload->org_type ?? null,
                'orgName' => $freshPayload->org_name ?? null
            ],
            'roles' => $freshPayload->roles ?? [],
            'activeRole' => $freshPayload->active_context ?? null
        ]
    ]);
}

/**
 * Logout user (clear session cookie)
 */
function handleLogout() {
    // Clear the cookie by setting it to expire in the past
    setcookie(
        'team-auth',
        '',
        [
            'expires' => time() - 3600,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax'
        ]
    );

    echo json_encode([
        'success' => true,
        'message' => 'Logged out successfully'
    ]);
}

/**
 * Handle email/password login
 */
function handlePasswordLogin($db, $input) {
    if (empty($input['email']) || empty($input['password'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Email and password are required']);
        return;
    }

    $email = strtolower(trim($input['email']));
    $password = $input['password'];

    // Get user with password hash
    $stmt = $db->prepare('
        SELECT id, email, password_hash, first_name, last_name, auth_provider
        FROM users
        WHERE email = ?
        LIMIT 1
    ');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        // Per COPPA-COMPLIANCE.md: login_failure is audited. No user id exists,
        // so the attempted address goes in details — that is what makes a
        // credential-stuffing run visible after the fact.
        AuditLogger::log($db, null, 'login_failure', 'users', null, ['email' => $email, 'reason' => 'unknown_email']);
        http_response_code(401);
        echo json_encode(['error' => 'Invalid email or password']);
        return;
    }

    // Check if user has a password set
    if (empty($user['password_hash'])) {
        http_response_code(401);
        echo json_encode([
            'error' => 'No password set for this account',
            'message' => 'Please use magic link to login, or set a password via password reset'
        ]);
        return;
    }

    // Verify password
    if (!password_verify($password, $user['password_hash'])) {
        AuditLogger::log($db, (int) $user['id'], 'login_failure', 'users', (int) $user['id'], ['reason' => 'bad_password']);
        http_response_code(401);
        echo json_encode(['error' => 'Invalid email or password']);
        return;
    }

    // Update last login and auth provider
    $stmt = $db->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP, auth_provider = ? WHERE id = ?');
    $stmt->execute(['password', $user['id']]);

    AuditLogger::log($db, (int) $user['id'], 'login_success', 'users', (int) $user['id'], ['method' => 'password']);

    // Generate enhanced JWT with organizational context
    $userName = trim($user['first_name'] . ' ' . $user['last_name']);
    $jwt = JWT::generateEnhanced($db, $user['id'], $user['email'], $userName);

    // Decode to get user context for response
    $payload = JWT::decode($jwt);

    echo json_encode([
        'success' => true,
        'message' => 'Login successful',
        'token' => $jwt,
        'user' => [
            'id' => (int)$user['id'],
            'email' => $user['email'],
            'name' => $userName,
            'system_role' => $payload->system_role ?? 'user',
            'organization' => [
                'orgId' => $payload->org_id ?? null,
                'orgType' => $payload->org_type ?? null,
                'orgName' => $payload->org_name ?? null
            ],
            'roles' => $payload->roles ?? [],
            'activeRole' => $payload->active_context ?? null
        ]
    ]);
}

/**
 * Handle user registration with email/password
 */
function handleRegister($db, $input) {
    $errors = [];

    // Validate required fields
    if (empty($input['email']) || !filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Valid email is required';
    }

    if (empty($input['password'])) {
        $errors['password'] = 'Password is required';
    } elseif (strlen($input['password']) < 8) {
        $errors['password'] = 'Password must be at least 8 characters';
    } elseif (!preg_match('/[A-Z]/', $input['password'])) {
        $errors['password'] = 'Password must contain at least one uppercase letter';
    } elseif (!preg_match('/[a-z]/', $input['password'])) {
        $errors['password'] = 'Password must contain at least one lowercase letter';
    } elseif (!preg_match('/[0-9]/', $input['password'])) {
        $errors['password'] = 'Password must contain at least one number';
    }

    // The ToS checkbox is enforced in the browser; enforce it here too. SignUp.tsx
    // is the only caller of this action, so requiring it breaks nothing.
    if (empty($input['tos_accepted'])) {
        $errors['tos_accepted'] = 'You must accept the Terms of Service';
    }

    if (empty($input['first_name'])) {
        $errors['first_name'] = 'First name is required';
    }

    if (empty($input['last_name'])) {
        $errors['last_name'] = 'Last name is required';
    }

    if (!empty($errors)) {
        http_response_code(400);
        echo json_encode(['error' => 'Validation failed', 'errors' => $errors]);
        return;
    }

    $email = strtolower(trim($input['email']));

    // Check if email already exists
    $stmt = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        http_response_code(400);
        echo json_encode(['error' => 'An account with this email already exists']);
        return;
    }

    // Hash password
    $passwordHash = password_hash($input['password'], PASSWORD_DEFAULT);

    // Create user
    $stmt = $db->prepare('
        INSERT INTO users (email, password_hash, first_name, last_name, role, auth_provider,
                           tos_accepted_at, tos_version, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        RETURNING id
    ');
    $stmt->execute([
        $email,
        $passwordHash,
        trim($input['first_name']),
        trim($input['last_name']),
        $input['role'] ?? 'parent',
        'password',
        // Stamped at the same instant as the account, from the acceptance the
        // form has been sending and the backend has been throwing away.
        TOS_VERSION
    ]);
    $result = $stmt->fetch();
    $userId = $result['id'];

    // COPPA-COMPLIANCE.md lists user_registered as an audited action. Records the
    // ToS version accepted so the acceptance is reconstructible from the audit
    // trail as well as the users row.
    AuditLogger::log($db, (int) $userId, 'user_registered', 'users', (int) $userId, [
        'role' => $input['role'] ?? 'parent',
        'tos_version' => TOS_VERSION,
    ]);

    // Generate enhanced JWT for auto-login after registration
    $userName = trim($input['first_name'] . ' ' . $input['last_name']);
    $jwt = JWT::generateEnhanced($db, $userId, $email, $userName);

    // Decode to get user context for response
    $payload = JWT::decode($jwt);

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Account created successfully',
        'token' => $jwt,
        'user' => [
            'id' => (int)$userId,
            'email' => $email,
            'name' => $userName,
            'system_role' => $payload->system_role ?? 'user',
            'organization' => [
                'orgId' => $payload->org_id ?? null,
                'orgType' => $payload->org_type ?? null,
                'orgName' => $payload->org_name ?? null
            ],
            'roles' => $payload->roles ?? [],
            'activeRole' => $payload->active_context ?? null
        ]
    ]);
}

/**
 * Handle password reset request
 */
function handleRequestPasswordReset($db, $input) {
    if (empty($input['email'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Email is required']);
        return;
    }

    $email = strtolower(trim($input['email']));

    // Check if user exists
    $stmt = $db->prepare('SELECT id, first_name, last_name FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // Always return success to prevent email enumeration
    if (!$user) {
        error_log("Password reset requested for non-existent user: $email");
        echo json_encode([
            'success' => true,
            'message' => 'If an account exists with this email, a password reset link has been sent.'
        ]);
        return;
    }

    // Generate secure token
    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + (60 * 60)); // 1 hour

    // Store reset token (reuse magic_link_tokens table with a type indicator)
    $stmt = $db->prepare('
        INSERT INTO magic_link_tokens (email, token, expires_at, created_at)
        VALUES (?, ?, ?, CURRENT_TIMESTAMP)
    ');
    $stmt->execute([$email . ':password_reset', $token, $expiresAt]);

    // Build reset link
    $appUrl = Env::get('APP_URL', 'http://localhost:3003');
    $resetLink = "$appUrl/reset-password?token=$token";

    // Send email
    $emailService = new Email();
    $userName = trim($user['first_name'] . ' ' . $user['last_name']);
    $sent = $emailService->sendPasswordReset($email, $userName, $resetLink);

    if (!$sent) {
        error_log("Failed to send password reset email to $email");
    }

    echo json_encode([
        'success' => true,
        'message' => 'If an account exists with this email, a password reset link has been sent.',
        'debug' => Env::get('APP_ENV') === 'development' ? ['link' => $resetLink] : null
    ]);
}

/**
 * Handle password reset completion
 */
function handleResetPassword($db, $input) {
    if (empty($input['token'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Token is required']);
        return;
    }

    if (empty($input['password'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Password is required']);
        return;
    }

    // Validate password strength
    $password = $input['password'];
    if (strlen($password) < 8) {
        http_response_code(400);
        echo json_encode(['error' => 'Password must be at least 8 characters']);
        return;
    }
    if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        http_response_code(400);
        echo json_encode(['error' => 'Password must contain uppercase, lowercase, and numbers']);
        return;
    }

    $token = $input['token'];

    // Look up token (password reset tokens have :password_reset suffix in email field)
    $stmt = $db->prepare('
        SELECT id, email, expires_at, used_at
        FROM magic_link_tokens
        WHERE token = ? AND email LIKE ?
        LIMIT 1
    ');
    $stmt->execute([$token, '%:password_reset']);
    $tokenData = $stmt->fetch();

    if (!$tokenData) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid or expired reset link']);
        return;
    }

    // Check if already used
    if ($tokenData['used_at'] !== null) {
        http_response_code(400);
        echo json_encode(['error' => 'This reset link has already been used']);
        return;
    }

    // Check if expired
    if (strtotime($tokenData['expires_at']) < time()) {
        http_response_code(400);
        echo json_encode(['error' => 'This reset link has expired']);
        return;
    }

    // Extract actual email (remove :password_reset suffix)
    $email = str_replace(':password_reset', '', $tokenData['email']);

    // Mark token as used
    $stmt = $db->prepare('UPDATE magic_link_tokens SET used_at = CURRENT_TIMESTAMP WHERE id = ?');
    $stmt->execute([$tokenData['id']]);

    // Update user's password
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare('UPDATE users SET password_hash = ?, auth_provider = ?, updated_at = CURRENT_TIMESTAMP WHERE email = ?');
    $stmt->execute([$passwordHash, 'password', $email]);

    echo json_encode([
        'success' => true,
        'message' => 'Password has been reset successfully. You can now log in with your new password.'
    ]);
}

/**
 * Handle parent-invite "set your password" completion.
 *
 * Mirrors handleResetPassword but operates on the ':parent_invite' token suffix,
 * sets the parent's password, and auto-logs them in by returning a JWT (same
 * response shape as login/register).
 */
function handleSetParentPassword($db, $input) {
    if (empty($input['token'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Token is required']);
        return;
    }

    if (empty($input['password'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Password is required']);
        return;
    }

    // Validate password strength (same rules as reset-password).
    $password = $input['password'];
    if (strlen($password) < 8) {
        http_response_code(400);
        echo json_encode(['error' => 'Password must be at least 8 characters']);
        return;
    }
    if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        http_response_code(400);
        echo json_encode(['error' => 'Password must contain uppercase, lowercase, and numbers']);
        return;
    }

    $token = $input['token'];

    // Look up the parent-invite token WITHOUT folding used/expiry into the WHERE
    // clause. Those predicates used to live here, which made a missing row, a
    // spent row and an expired row indistinguishable — all three answered
    // "Invalid or expired link". A parent who had already completed setup was
    // told his link had expired four days before it actually would.
    // See lib/parent_invite_token.php.
    $stmt = $db->prepare("
        SELECT id, email, expires_at, used_at
        FROM magic_link_tokens
        WHERE token = ? AND email LIKE '%:parent_invite'
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $tokenData = $stmt->fetch();

    $classification = te_classify_parent_invite_token($tokenData ?: null);
    if ($classification !== TE_INVITE_TOKEN_VALID) {
        $err = te_parent_invite_token_error($classification);
        http_response_code($err['status']);
        echo json_encode(['error' => $err['error'], 'reason' => $err['reason']]);
        return;
    }

    // Derive the real email by stripping the trailing ':parent_invite' suffix.
    $email = preg_replace('/:parent_invite$/', '', $tokenData['email']);

    // RESOLVE THE ACCOUNT BEFORE SPENDING THE TOKEN.
    //
    // This lookup used to run last, after the password write and after the token
    // was marked used — and the password UPDATE keyed on email without checking
    // how many rows it touched. So for any invite whose address had no matching
    // users row, the parent's FIRST attempt silently wrote nothing, burned their
    // link, and returned "Invalid or expired link"; every retry then failed for
    // real. Nothing in the logs distinguished that from an ordinary expiry.
    $stmt = $db->prepare('SELECT id, email, first_name, last_name FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        // The token is left UNSPENT on purpose: nothing was accomplished, so the
        // parent's link must still work once the missing account is repaired.
        error_log("set-parent-password: valid token for '$email' but no users row; token left unspent");
        http_response_code(500);
        echo json_encode([
            'error'  => 'Your account is not set up correctly. Please contact your club.',
            'reason' => 'account_missing',
        ]);
        return;
    }

    // Write the password and spend the token together, keyed on the resolved id
    // rather than the email string.
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    try {
        $db->beginTransaction();

        $stmt = $db->prepare("UPDATE users SET password_hash = ?, auth_provider = 'password', updated_at = NOW() WHERE id = ?");
        $stmt->execute([$passwordHash, $user['id']]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('password update affected ' . $stmt->rowCount() . ' rows');
        }

        $stmt = $db->prepare('UPDATE magic_link_tokens SET used_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->execute([$tokenData['id']]);

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('set-parent-password: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'error'  => 'We could not finish setting up your account. Please try again.',
            'reason' => 'write_failed',
        ]);
        return;
    }

    $userName = trim($user['first_name'] . ' ' . $user['last_name']);
    $jwt = JWT::generateEnhanced($db, $user['id'], $user['email'], $userName);
    $payload = JWT::decode($jwt);

    echo json_encode([
        'success' => true,
        'message' => 'Your parent account is ready.',
        'token' => $jwt,
        'user' => [
            'id' => (int)$user['id'],
            'email' => $user['email'],
            'name' => $userName,
            'system_role' => $payload->system_role ?? 'user',
            'organization' => [
                'orgId' => $payload->org_id ?? null,
                'orgType' => $payload->org_type ?? null,
                'orgName' => $payload->org_name ?? null
            ],
            'roles' => $payload->roles ?? [],
            'activeRole' => $payload->active_context ?? null
        ]
    ]);
}

/**
 * Handle the manual "Invite to parent portal" button.
 *
 * Admin/coach-only. Ensures the guardian has a parent login + emails them a
 * "set your password" link. Returns the resolved status so the UI can toast.
 */
function handleSendParentInvite($db, $input) {
    require_once __DIR__ . '/../lib/AuthMiddleware.php';
    require_once __DIR__ . '/../lib/ParentInvite.php';

    $auth = AuthMiddleware::requireAuth();

    if (empty($input['guardian_id']) || empty($input['club_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'guardian_id and club_id are required']);
        return;
    }

    $clubId = (int)$input['club_id'];
    $guardianId = (int)$input['guardian_id'];

    // Staff only (club admin or coach). canAccessClub() would also admit a
    // parent — see lib/club_standing.php.
    if (!te_is_club_staff($auth, $clubId)) {
        http_response_code(403);
        echo json_encode(['error' => 'You do not have access to this club']);
        return;
    }

    $inv = parentInvite_ensureUserAndToken($db, $guardianId, $clubId);

    if ($inv['status'] === 'error') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'status' => 'error',
            'error' => $inv['message'] ?? 'Could not create invite'
        ]);
        return;
    }

    if ($inv['status'] === 'invited') {
        // Optional athlete name for context.
        $athleteName = null;
        if (!empty($input['athlete_id'])) {
            try {
                $aStmt = $db->prepare('SELECT first_name, last_name FROM athletes WHERE id = ?');
                $aStmt->execute([(int)$input['athlete_id']]);
                $a = $aStmt->fetch();
                if ($a) {
                    $athleteName = trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? '')) ?: null;
                }
            } catch (Throwable $ae) {
                // non-fatal
            }
        }

        $appUrl = rtrim(Env::get('APP_URL', 'https://teams-elevated.netlify.app'), '/');
        $link = $appUrl . '/set-parent-password?token=' . $inv['token'];
        // Send as the club, so the parent recognises the sender. See lib/email_sender.php.
        (new Email())->forClub($db, $clubId)->sendParentInvite($inv['email'], $inv['name'], $link, $athleteName);
    }

    echo json_encode([
        'success' => true,
        'status' => $inv['status'],
        'email' => $inv['email']
    ]);
}

/**
 * Read-only portal status for each guardian of an athlete, for the Guardian
 * Manager / athlete-profile invite UI. Mirrors ParentInvite's definition:
 *   active      = a users row for the email has a password_hash set
 *   invited     = an unused, unexpired ':parent_invite' token exists
 *   not_invited = neither
 *   no_email    = guardian has no email (can't be invited)
 */
function handleParentPortalStatus($db, $input) {
    require_once __DIR__ . '/../lib/AuthMiddleware.php';
    $auth = AuthMiddleware::requireAuth();

    $athleteId = (int)($input['athlete_id'] ?? 0);
    $clubId = (int)($input['club_id'] ?? 0);
    if (!$athleteId || !$clubId) {
        http_response_code(400);
        echo json_encode(['error' => 'athlete_id and club_id are required']);
        return;
    }
    // Staff only (club admin or coach). canAccessClub() would also admit a
    // parent — see lib/club_standing.php.
    if (!te_is_club_staff($auth, $clubId)) {
        http_response_code(403);
        echo json_encode(['error' => 'You do not have access to this club']);
        return;
    }

    $stmt = $db->prepare("
        SELECT g.id AS guardian_id, g.email,
               CASE
                   WHEN u.password_hash IS NOT NULL AND u.password_hash <> '' THEN 'active'
                   WHEN EXISTS (
                       SELECT 1 FROM magic_link_tokens t
                       WHERE t.email = lower(btrim(g.email)) || ':parent_invite'
                         AND t.used_at IS NULL AND t.expires_at > NOW()
                   ) THEN 'invited'
                   ELSE 'not_invited'
               END AS status,
               (
                   SELECT max(t2.created_at) FROM magic_link_tokens t2
                   WHERE t2.email = lower(btrim(g.email)) || ':parent_invite'
               ) AS invited_at
        FROM athlete_guardians ag
        JOIN guardians g ON g.id = ag.guardian_id
        LEFT JOIN users u ON lower(u.email) = lower(btrim(g.email))
        WHERE ag.athlete_id = ?
    ");
    $stmt->execute([$athleteId]);

    $statuses = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $email = trim((string)($r['email'] ?? ''));
        $statuses[] = [
            'guardian_id' => (int)$r['guardian_id'],
            'status'      => $email === '' ? 'no_email' : $r['status'],
            'invited_at'  => $r['invited_at'],
        ];
    }

    echo json_encode(['success' => true, 'statuses' => $statuses]);
}

/**
 * Club-wide parents roster with portal status — powers the Parents page
 * (see the athlete-scoped handleParentPortalStatus for the status definitions).
 * One row per guardian linked to any athlete in the club, with their athletes.
 */
function handleClubParents($db, $input) {
    require_once __DIR__ . '/../lib/AuthMiddleware.php';
    $auth = AuthMiddleware::requireAuth();

    $clubId = (int)($input['club_id'] ?? 0);
    if (!$clubId) {
        http_response_code(400);
        echo json_encode(['error' => 'club_id is required']);
        return;
    }
    // CLUB ADMIN ONLY. canAccessClub() is club MEMBERSHIP — a `parent` row
    // satisfies it — so this returned every guardian in the club (name, email,
    // mobile, portal status, children's names) to any parent who posted their own
    // club_id. Verified against production. See lib/club_standing.php.
    if (!te_is_club_admin($auth, $clubId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Only club admins can view the crew roster']);
        return;
    }

    require_once __DIR__ . '/../lib/portal_status.php';

    // Status comes from te_portal_status(), not from a CASE here — the Coaches page
    // asks the same question and the two must not drift. The old CASE read a
    // password as a login and let an expired invite decay into 'not_invited'.
    $stmt = $db->prepare("
        SELECT g.id AS guardian_id, g.first_name, g.last_name, g.email, g.mobile_phone,
               string_agg(DISTINCT a.first_name || ' ' || a.last_name, ', ') AS athletes,
               min(a.id) AS any_athlete_id,
               " . te_portal_status_columns('g.email', 'u') . "
        FROM guardians g
        JOIN athlete_guardians ag ON ag.guardian_id = g.id
        JOIN athletes a ON a.id = ag.athlete_id
        LEFT JOIN users u ON lower(u.email) = lower(btrim(g.email))
        WHERE a.club_id = ?
        GROUP BY g.id, u.id, u.password_hash, u.last_login_at
        ORDER BY g.last_name, g.first_name
    ");
    $stmt->execute([$clubId]);

    $parents = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $email = trim((string)($r['email'] ?? ''));
        $s = te_portal_status($r, $email, 'crew');
        $parents[] = [
            'guardian_id'    => (int)$r['guardian_id'],
            'first_name'     => $r['first_name'],
            'last_name'      => $r['last_name'],
            'email'          => $email,
            'mobile_phone'   => $r['mobile_phone'],
            'athletes'       => $r['athletes'],
            'athlete_id'     => (int)$r['any_athlete_id'],
            'status'         => $s['status'],
            'first_login_at' => $s['first_login_at'],
            'invited_at'     => $s['invited_at'],
            'shared_account' => $s['shared_account'],
            'shared_reason'  => $s['shared_reason'],
        ];
    }

    echo json_encode(['success' => true, 'parents' => $parents]);
}

/**
 * Handle context switching (change active role/organization)
 */
function handleSwitchContext($db, $input) {
    // Require authentication
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $jwt = null;

    if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        $jwt = $matches[1];
    }

    if (!$jwt) {
        http_response_code(401);
        echo json_encode(['error' => 'Authentication required']);
        return;
    }

    // Verify current JWT
    $payload = JWT::verify($jwt);

    if (!$payload) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid or expired token']);
        return;
    }

    // Validate input
    if (empty($input['scope_id']) || empty($input['scope_type'])) {
        http_response_code(400);
        echo json_encode(['error' => 'scope_id and scope_type are required']);
        return;
    }

    $scopeId = (int)$input['scope_id'];
    $scopeType = $input['scope_type'];

    if ($scopeType !== 'club') {
        http_response_code(400);
        echo json_encode(['error' => 'scope_type must be "club"']);
        return;
    }

    // Get user details
    $userId = $payload->user_id;
    $stmt = $db->prepare('SELECT id, email, first_name, last_name FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        http_response_code(404);
        echo json_encode(['error' => 'User not found']);
        return;
    }

    // Verify user has access to the requested club
    $stmt = $db->prepare('
        SELECT COUNT(*) as count
        FROM user_club_access
        WHERE user_id = ? AND club_profile_id = ? AND active = TRUE
    ');
    $stmt->execute([$userId, $scopeId]);
    $result = $stmt->fetch();
    $hasAccess = $result['count'] > 0;

    if (!$hasAccess) {
        http_response_code(403);
        echo json_encode(['error' => 'You do not have access to this organization']);
        return;
    }

    // Generate new JWT with the requested active context
    $userName = trim($user['first_name'] . ' ' . $user['last_name']);
    $newJwt = JWT::generateEnhanced($db, $user['id'], $user['email'], $userName, $scopeId, $scopeType);

    // Decode to get updated user context
    $newPayload = JWT::decode($newJwt);

    echo json_encode([
        'success' => true,
        'message' => 'Context switched successfully',
        'token' => $newJwt,
        'user' => [
            'id' => (int)$user['id'],
            'email' => $user['email'],
            'name' => $userName,
            'system_role' => $newPayload->system_role ?? 'user',
            'organization' => [
                'orgId' => $newPayload->org_id ?? null,
                'orgType' => $newPayload->org_type ?? null,
                'orgName' => $newPayload->org_name ?? null
            ],
            'roles' => $newPayload->roles ?? [],
            'activeRole' => $newPayload->active_context ?? null
        ]
    ]);
}
