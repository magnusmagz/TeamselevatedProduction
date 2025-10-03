<?php
/**
 * Organization Gateway API
 *
 * Handles organization/club creation and user registration:
 * - Create new organizations with club profiles
 * - Register users with multiple roles
 * - Send magic link for automatic login
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/env.php');
require_once __DIR__ . '/../lib/JWT.php';
require_once __DIR__ . '/../lib/Email.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?: [];

try {
    switch ($action) {
        case 'create':
            handleCreateOrganization($db, $input);
            break;

        default:
            http_response_code(404);
            echo json_encode(['error' => 'Action not found']);
    }

} catch (Exception $e) {
    error_log('Organization gateway error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error',
        'message' => getenv('APP_ENV') === 'development' ? $e->getMessage() : null
    ]);
}

/**
 * Create new organization with club profile and user account
 */
function handleCreateOrganization($db, $input) {
    // Validate required fields
    if (empty($input['organizationName'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Organization name is required']);
        return;
    }

    if (empty($input['yourName'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Your name is required']);
        return;
    }

    if (empty($input['email'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Email is required']);
        return;
    }

    if (empty($input['roles']) || !is_array($input['roles'])) {
        http_response_code(400);
        echo json_encode(['error' => 'At least one role is required']);
        return;
    }

    $organizationName = trim($input['organizationName']);
    $yourName = trim($input['yourName']);
    $email = strtolower(trim($input['email']));
    $phone = isset($input['phone']) ? trim($input['phone']) : null;
    $roles = $input['roles'];

    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid email address']);
        return;
    }

    // Check if user already exists
    $stmt = $db->prepare('SELECT id, email FROM users WHERE email = $1 LIMIT 1');
    $stmt->execute([$email]);
    $existingUser = $stmt->fetch();

    if ($existingUser) {
        http_response_code(400);
        echo json_encode(['error' => 'An account with this email already exists. Please use the login page instead.']);
        return;
    }

    // Start transaction
    $db->beginTransaction();

    try {
        // Step 1: Create club_profile
        $stmt = $db->prepare('
            INSERT INTO club_profile (club_name, address, city, state, zip, phone, email, created_at, updated_at)
            VALUES ($1, $2, $3, $4, $5, $6, $7, NOW(), NOW())
            RETURNING id
        ');

        // Use placeholder values for address fields (can be updated later)
        $stmt->execute([
            $organizationName,
            '', // address - empty for now
            '', // city - empty for now
            '', // state - empty for now
            '', // zip - empty for now
            $phone,
            $email
        ]);

        $clubProfileId = $stmt->fetchColumn();
        error_log("Created club_profile with ID: $clubProfileId");

        // Step 2: Parse name into first_name and last_name
        $nameParts = explode(' ', $yourName, 2);
        $firstName = $nameParts[0];
        $lastName = isset($nameParts[1]) ? $nameParts[1] : '';

        // Step 3: Determine primary role for users table
        // Priority: club_manager > coach > parent > volunteer > player
        $rolePriority = [
            'league' => 'club_manager',
            'administrator' => 'club_manager',
            'coach' => 'coach',
            'parent' => 'parent',
            'team' => 'coach' // Team role maps to coach
        ];

        $primaryRole = 'club_manager'; // Default
        foreach (['league', 'administrator', 'coach', 'parent', 'team'] as $roleKey) {
            if (in_array($roleKey, $roles)) {
                $primaryRole = $rolePriority[$roleKey];
                break;
            }
        }

        // Step 4: Create user account
        $stmt = $db->prepare('
            INSERT INTO users (
                email,
                password,
                first_name,
                last_name,
                phone,
                role,
                auth_provider,
                is_active,
                created_at,
                updated_at
            )
            VALUES ($1, NULL, $2, $3, $4, $5, $6, TRUE, NOW(), NOW())
            RETURNING id
        ');

        $stmt->execute([
            $email,
            $firstName,
            $lastName,
            $phone,
            $primaryRole,
            'magic_link'
        ]);

        $userId = $stmt->fetchColumn();
        error_log("Created user with ID: $userId");

        // Step 5: Create user_roles entries for all selected roles
        // Map frontend roles to backend role names
        $roleMapping = [
            'league' => 'league_admin',
            'team' => 'team_manager',
            'coach' => 'coach',
            'administrator' => 'administrator',
            'parent' => 'parent'
        ];

        $stmt = $db->prepare('
            INSERT INTO user_roles (user_id, role, club_profile_id, team_id, created_at, updated_at)
            VALUES ($1, $2, $3, NULL, NOW(), NOW())
        ');

        foreach ($roles as $role) {
            $mappedRole = $roleMapping[$role] ?? $role;
            $stmt->execute([$userId, $mappedRole, $clubProfileId]);
            error_log("Created user_role: $mappedRole for user $userId");
        }

        // Step 6: Generate magic link token
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + (15 * 60)); // 15 minutes

        $stmt = $db->prepare('
            INSERT INTO magic_link_tokens (email, token, expires_at, created_at)
            VALUES ($1, $2, $3, NOW())
        ');
        $stmt->execute([$email, $token, $expiresAt]);

        error_log("Created magic link token for $email");

        // Step 7: Build magic link URL
        $appUrl = getenv('APP_URL') ?: 'http://localhost:3003';
        $magicLink = "$appUrl/verify-magic-link?token=$token";

        // Step 8: Send magic link email
        $emailService = new Email();
        $sent = $emailService->sendMagicLink($email, $yourName, $magicLink);

        if (!$sent) {
            error_log("Failed to send magic link email to $email");
            // Don't fail the registration, just log it
        }

        // Commit transaction
        $db->commit();

        // Step 9: Return success response
        echo json_encode([
            'success' => true,
            'message' => 'Organization created successfully',
            'magicLink' => $magicLink,
            'userId' => (int)$userId,
            'clubProfileId' => (int)$clubProfileId,
            'debug' => getenv('APP_ENV') === 'development' ? [
                'link' => $magicLink,
                'email' => $email,
                'roles' => $roles,
                'primaryRole' => $primaryRole
            ] : null
        ]);

    } catch (Exception $e) {
        // Rollback transaction on error
        $db->rollBack();
        error_log("Error creating organization: " . $e->getMessage());
        throw $e;
    }
}
