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
require_once __DIR__ . '/../lib/Email.php';

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

    // Token is valid - return full organizational context
    echo json_encode([
        'authenticated' => true,
        'user' => [
            'id' => isset($payload->user_id) ? (int)$payload->user_id : null,
            'email' => $payload->email ?? null,
            'name' => $payload->name ?? null,
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
        http_response_code(401);
        echo json_encode(['error' => 'Invalid email or password']);
        return;
    }

    // Update last login and auth provider
    $stmt = $db->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP, auth_provider = ? WHERE id = ?');
    $stmt->execute(['password', $user['id']]);

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
        INSERT INTO users (email, password_hash, first_name, last_name, role, auth_provider, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        RETURNING id
    ');
    $stmt->execute([
        $email,
        $passwordHash,
        trim($input['first_name']),
        trim($input['last_name']),
        $input['role'] ?? 'parent',
        'password'
    ]);
    $result = $stmt->fetch();
    $userId = $result['id'];

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
    $scopeType = $input['scope_type']; // 'league' or 'club'

    if (!in_array($scopeType, ['league', 'club'])) {
        http_response_code(400);
        echo json_encode(['error' => 'scope_type must be "league" or "club"']);
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

    // Verify user has access to the requested scope
    $hasAccess = false;

    if ($scopeType === 'league') {
        // Check if user has any league role for this league
        $stmt = $db->prepare('
            SELECT COUNT(*) as count
            FROM user_league_access
            WHERE user_id = ? AND league_id = ? AND active = TRUE
        ');
        $stmt->execute([$userId, $scopeId]);
        $result = $stmt->fetch();
        $hasAccess = $result['count'] > 0;
    } else {
        // Check if user has any club role for this club
        $stmt = $db->prepare('
            SELECT COUNT(*) as count
            FROM user_club_access
            WHERE user_id = ? AND club_profile_id = ? AND active = TRUE
        ');
        $stmt->execute([$userId, $scopeId]);
        $result = $stmt->fetch();
        $hasAccess = $result['count'] > 0;
    }

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
