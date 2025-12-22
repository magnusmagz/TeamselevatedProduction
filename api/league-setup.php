<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/JWT.php';

try {
    $db = Database::getInstance();
    $connection = $db->getConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
$requiredFields = ['leagueName', 'userName', 'userEmail'];
foreach ($requiredFields as $field) {
    if (empty($input[$field])) {
        http_response_code(400);
        echo json_encode(['error' => "Missing required field: $field"]);
        exit();
    }
}

$leagueName = $input['leagueName'];
$userName = $input['userName'];
$userEmail = $input['userEmail'];
$userPhone = $input['userPhone'] ?? null;
$address = $input['address'] ?? null;
$city = $input['city'] ?? null;
$state = $input['state'] ?? null;
$zipCode = $input['zipCode'] ?? null;

try {
    $connection->beginTransaction();

    // 1. Check if user already exists
    try {
        $stmt = $connection->prepare("SELECT id FROM users WHERE email = ?");
        if (!$stmt) {
            $errorInfo = $connection->errorInfo();
            throw new Exception("Prepare failed: " . json_encode($errorInfo));
        }
        $result = $stmt->execute([$userEmail]);
        if (!$result) {
            $errorInfo = $stmt->errorInfo();
            throw new Exception("Execute failed: " . json_encode($errorInfo));
        }
        $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existingUser) {
            $connection->rollBack();
            http_response_code(400);
            echo json_encode(['error' => 'An account with this email already exists. Please log in instead.']);
            exit();
        }
    } catch (Exception $e) {
        throw new Exception("Step 1 (check user): " . $e->getMessage());
    }

    // 2. Create the user
    try {
        $stmt = $connection->prepare("
            INSERT INTO users (email, first_name, last_name, system_role)
            VALUES (?, ?, ?, 'user')
            RETURNING id
        ");

        // Split name into first and last
        $nameParts = explode(' ', $userName, 2);
        $firstName = $nameParts[0];
        $lastName = isset($nameParts[1]) ? $nameParts[1] : '';

        $stmt->execute([$userEmail, $firstName, $lastName]);
        $userId = $stmt->fetchColumn();

        if (!$userId) {
            throw new Exception("Failed to get user ID after insert");
        }
    } catch (Exception $e) {
        throw new Exception("Step 2 (create user): " . $e->getMessage());
    }

    // 3. Create the league
    try {
        $stmt = $connection->prepare("
            INSERT INTO leagues (name, description, address, city, state, zip_code, contact_phone, active)
            VALUES (?, ?, ?, ?, ?, ?, ?, true)
            RETURNING id
        ");

        $leagueDescription = "Welcome to $leagueName";
        $stmt->execute([$leagueName, $leagueDescription, $address, $city, $state, $zipCode, $userPhone]);
        $leagueId = $stmt->fetchColumn();

        if (!$leagueId) {
            throw new Exception("Failed to get league ID after insert");
        }
    } catch (Exception $e) {
        throw new Exception("Step 3 (create league): " . $e->getMessage());
    }

    // 4. Assign user as league admin
    try {
        $stmt = $connection->prepare("
            INSERT INTO user_league_access (user_id, league_id, role)
            VALUES (?, ?, 'league_admin')
        ");
        $stmt->execute([$userId, $leagueId]);
    } catch (Exception $e) {
        throw new Exception("Step 4 (assign league admin): " . $e->getMessage());
    }

    // 5. Create a default club for the league (optional but helpful)
    try {
        $stmt = $connection->prepare("
            INSERT INTO club_profile (name, league_id, address_line1, city, state, zip_code, phone)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            RETURNING id
        ");
        $defaultClubName = "$leagueName - Main Club";
        $stmt->execute([$defaultClubName, $leagueId, $address, $city, $state, $zipCode, $userPhone]);
        $clubId = $stmt->fetchColumn();

        if (!$clubId) {
            throw new Exception("Failed to get club ID after insert");
        }
    } catch (Exception $e) {
        throw new Exception("Step 5 (create default club): " . $e->getMessage());
    }

    // 6. Also give the user club admin access to the default club
    try {
        $stmt = $connection->prepare("
            INSERT INTO user_club_access (user_id, club_profile_id, role)
            VALUES (?, ?, 'club_admin')
        ");
        $stmt->execute([$userId, $clubId]);
    } catch (Exception $e) {
        throw new Exception("Step 6 (assign club admin): " . $e->getMessage());
    }

    // 7. Generate JWT token for immediate login
    try {
        $token = JWT::generateEnhanced($connection, $userId, $userEmail, $userName, $leagueId, 'league');
    } catch (Exception $e) {
        throw new Exception("Step 7 (generate JWT): " . $e->getMessage());
    }

    $connection->commit();

    // Return success with token and league info
    echo json_encode([
        'success' => true,
        'message' => 'League created successfully',
        'token' => $token,
        'league' => [
            'id' => $leagueId,
            'name' => $leagueName
        ],
        'user' => [
            'id' => $userId,
            'email' => $userEmail,
            'name' => $userName
        ]
    ]);

} catch (Exception $e) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => 'Failed to create league: ' . $e->getMessage()]);
}
?>
