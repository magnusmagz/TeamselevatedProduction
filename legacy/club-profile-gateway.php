<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Use centralized database connection
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';

try {
    $db = Database::getInstance();
    $connection = $db->getConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

// Require authentication
$auth = AuthMiddleware::requireAuth();

// Get the user's active club from context
$activeContext = $auth->getActiveContext();
$clubId = null;

if ($activeContext && $activeContext->scope_type === 'club') {
    $clubId = $activeContext->scope_id;
}

if (!$clubId) {
    http_response_code(400);
    echo json_encode(['error' => 'No active club context']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            // Get club profile for user's active club
            $stmt = $connection->prepare("
                SELECT
                    id,
                    name as club_name,
                    address_line1 as address,
                    city,
                    state,
                    zip_code as zip,
                    website,
                    phone,
                    email,
                    logo_url as logo_data,
                    primary_color,
                    secondary_color,
                    description,
                    social_facebook,
                    social_instagram,
                    social_twitter,
                    social_tiktok,
                    social_youtube,
                    social_linkedin
                FROM club_profile
                WHERE id = ?
            ");
            $stmt->execute([$clubId]);
            $club = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($club) {
                echo json_encode($club);
            } else {
                // Return empty profile structure if none exists
                echo json_encode([
                    'id' => null,
                    'club_name' => '',
                    'address' => '',
                    'city' => '',
                    'state' => '',
                    'zip' => '',
                    'website' => '',
                    'phone' => '',
                    'email' => '',
                    'logo_data' => '',
                    'logo_filename' => '',
                    'primary_color' => '',
                    'secondary_color' => '',
                    'accent_color' => ''
                ]);
            }
            break;

        case 'PUT':
            // Check if user can manage this club
            if (!$auth->can('manage_club', $clubId, 'club')) {
                http_response_code(403);
                echo json_encode(['error' => 'You do not have permission to edit this club']);
                exit();
            }

            $data = json_decode(file_get_contents("php://input"), true);

            // Update existing profile
            $stmt = $connection->prepare("
                UPDATE club_profile
                SET name = ?,
                    address_line1 = ?,
                    city = ?,
                    state = ?,
                    zip_code = ?,
                    website = ?,
                    phone = ?,
                    email = ?,
                    logo_url = ?,
                    primary_color = ?,
                    secondary_color = ?,
                    social_facebook = ?,
                    social_instagram = ?,
                    social_twitter = ?,
                    social_tiktok = ?,
                    social_youtube = ?,
                    social_linkedin = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([
                $data['club_name'],
                $data['address'] ?? null,
                $data['city'] ?? null,
                $data['state'] ?? null,
                $data['zip'] ?? null,
                $data['website'] ?? null,
                $data['phone'] ?? null,
                $data['email'] ?? null,
                $data['logo_data'] ?? null,
                $data['primary_color'] ?? null,
                $data['secondary_color'] ?? null,
                $data['social_facebook'] ?? null,
                $data['social_instagram'] ?? null,
                $data['social_twitter'] ?? null,
                $data['social_tiktok'] ?? null,
                $data['social_youtube'] ?? null,
                $data['social_linkedin'] ?? null,
                $clubId
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Club profile updated successfully'
            ]);
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
