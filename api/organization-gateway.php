<?php
/**
 * Organization Gateway API
 * Handles organization creation and management
 */

header('Content-Type: application/json');

// Allow all origins for CORS
header('Access-Control-Allow-Origin: *');

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/JWT.php';
require_once __DIR__ . '/../lib/Email.php';

$db = Database::getInstance();
$conn = $db->getConnection();

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

/**
 * Create Organization and User
 */
function handleCreateOrganization($conn, $input) {
    // Validate required fields
    if (empty($input['organizationName']) || empty($input['yourName']) || empty($input['email']) || empty($input['roles'])) {
        http_response_code(400);
        return ['error' => 'Organization name, your name, email, and roles are required'];
    }

    $organizationName = trim($input['organizationName']);
    $userName = trim($input['yourName']);
    $email = strtolower(trim($input['email']));
    $phone = !empty($input['phone']) ? trim($input['phone']) : null;
    $roles = $input['roles']; // Array of role strings

    try {
        error_log('Starting organization creation for: ' . $email);

        // TEST: Skip transaction and RLS bypass entirely - use autocommit
        error_log('TESTING: Skipping transaction and RLS bypass');

        // 1. Check if user already exists
        error_log('Checking if user exists: ' . $email);
        try {
            $stmt = $conn->prepare('SELECT id, first_name, last_name, email FROM users WHERE email = :email');
            $stmt->execute(['email' => $email]);
            $existingUser = $stmt->fetch();
            error_log('User check complete. Exists: ' . ($existingUser ? 'yes' : 'no'));
        } catch (PDOException $e) {
            error_log('SELECT user FAILED: ' . $e->getMessage());
            throw $e;
        }

        if ($existingUser) {
            // User already exists, send magic link to existing user
            $userId = $existingUser['id'];

            // Generate magic link token
            $token = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+15 minutes'));

            // Store token (magic_link_tokens uses email, not user_id)
            $stmt = $conn->prepare('
                INSERT INTO magic_link_tokens (email, token, expires_at, created_at)
                VALUES (:email, :token, :expires_at, CURRENT_TIMESTAMP)
            ');
            $stmt->execute([
                'email' => $email,
                'token' => $token,
                'expires_at' => $expiresAt
            ]);

            // Send magic link email
            $magicLink = getenv('APP_URL') . '/verify-magic-link?token=' . $token;
            $emailSender = new Email();
            $fullName = trim($existingUser['first_name'] . ' ' . $existingUser['last_name']);
            $emailSender->sendMagicLink($email, $fullName, $magicLink);

            // TEST: No COMMIT needed in autocommit mode
            error_log('TESTING: Existing user path completed in autocommit mode');

            return [
                'success' => true,
                'message' => 'User already exists. Magic link sent to your email.',
                'existingUser' => true,
                'magicLink' => $magicLink  // Always return for auto-login
            ];
        }

        // 2. Create new user
        error_log('Creating new user: ' . $userName);
        // Split name into first and last
        $nameParts = explode(' ', $userName, 2);
        $firstName = $nameParts[0];
        $lastName = $nameParts[1] ?? '';
        error_log('Name split - First: ' . $firstName . ', Last: ' . $lastName);

        try {
            error_log('Preparing INSERT statement...');
            $stmt = $conn->prepare('
                INSERT INTO users (first_name, last_name, email, auth_provider, created_at)
                VALUES (:first_name, :last_name, :email, :auth_provider, CURRENT_TIMESTAMP)
                RETURNING id
            ');

            if ($stmt === false) {
                $errorInfo = $conn->errorInfo();
                error_log('Prepare FAILED - Error: ' . print_r($errorInfo, true));
                throw new PDOException('Prepare failed: ' . $errorInfo[2]);
            }

            error_log('Prepared INSERT statement successfully (stmt is valid), executing...');

            $result = $stmt->execute([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'auth_provider' => 'magic_link'
            ]);

            if ($result === false) {
                $errorInfo = $stmt->errorInfo();
                error_log('Execute FAILED - Error: ' . print_r($errorInfo, true));
                throw new PDOException('Execute failed: ' . $errorInfo[2]);
            }

            error_log('INSERT executed successfully');
        } catch (PDOException $e) {
            error_log('INSERT prepare or execute FAILED: ' . $e->getMessage());
            throw $e;
        }

        $result = $stmt->fetch();
        $userId = $result['id'];
        error_log('User created with ID: ' . $userId);

        // 3. Create organization (club profile)
        $stmt = $conn->prepare('
            INSERT INTO club_profile (name, email, phone, created_at)
            VALUES (:name, :email, :phone, CURRENT_TIMESTAMP)
            RETURNING id
        ');
        $stmt->execute([
            'name' => $organizationName,
            'email' => $email,
            'phone' => $phone
        ]);
        $result = $stmt->fetch();
        $clubId = $result['id'];

        // 4. Assign roles to user
        // Map frontend role names to database role names
        $roleMapping = [
            'league' => 'league_admin',
            'team' => 'team_manager',
            'coach' => 'coach',
            'administrator' => 'administrator',
            'parent' => 'parent'
        ];

        foreach ($roles as $role) {
            $dbRole = $roleMapping[$role] ?? $role;

            // Insert into user_roles table
            $stmt = $conn->prepare('
                INSERT INTO user_roles (user_id, role, club_profile_id, created_at)
                VALUES (:user_id, :role, :club_profile_id, CURRENT_TIMESTAMP)
            ');
            $stmt->execute([
                'user_id' => $userId,
                'role' => $dbRole,
                'club_profile_id' => $clubId
            ]);
        }

        // 5. Generate magic link for authentication
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+15 minutes'));

        $stmt = $conn->prepare('
            INSERT INTO magic_link_tokens (email, token, expires_at, created_at)
            VALUES (:email, :token, :expires_at, CURRENT_TIMESTAMP)
        ');
        $stmt->execute([
            'email' => $email,
            'token' => $token,
            'expires_at' => $expiresAt
        ]);

        // 6. Send magic link email
        $magicLink = getenv('APP_URL') . '/verify-magic-link?token=' . $token;
        $emailSender = new Email();
        $emailSender->sendMagicLink($email, $userName, $magicLink);

        // TEST: No COMMIT needed in autocommit mode
        error_log('TESTING: All operations completed in autocommit mode');

        return [
            'success' => true,
            'message' => 'Organization created successfully',
            'user' => [
                'id' => $userId,
                'name' => $userName,
                'email' => $email
            ],
            'organization' => [
                'id' => $clubId,
                'name' => $organizationName
            ],
            'roles' => $roles,
            'magicLink' => $magicLink  // Always return for auto-login
        ];

    } catch (Exception $e) {
        // Log the FULL error
        $fullError = $e->getMessage() . ' | File: ' . $e->getFile() . ' | Line: ' . $e->getLine();
        error_log('Organization creation error (FULL): ' . $fullError);

        // TEST: No ROLLBACK needed in autocommit mode

        http_response_code(500);
        return [
            'error' => 'Failed to create organization',
            'details' => $e->getMessage() // Show error temporarily for debugging
        ];
    }
}

// Route handler
try {
    if ($method === 'POST' && $action === 'create') {
        $input = json_decode(file_get_contents('php://input'), true);
        $response = handleCreateOrganization($conn, $input);
        echo json_encode($response);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Endpoint not found']);
    }
} catch (Exception $e) {
    error_log('API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error',
        'details' => getenv('APP_ENV') === 'development' ? $e->getMessage() : null
    ]);
}
