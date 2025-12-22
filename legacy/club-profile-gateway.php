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

try {
    $db = Database::getInstance();
    $connection = $db->getConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            // Get club profile (there should only be one)
            $stmt = $connection->prepare("SELECT * FROM club_profile LIMIT 1");
            $stmt->execute();
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
            $data = json_decode(file_get_contents("php://input"), true);

            // Check if profile exists
            $stmt = $connection->prepare("SELECT id FROM club_profile LIMIT 1");
            $stmt->execute();
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                // Update existing profile
                $stmt = $connection->prepare("
                    UPDATE club_profile
                    SET club_name = ?,
                        address = ?,
                        city = ?,
                        state = ?,
                        zip = ?,
                        website = ?,
                        phone = ?,
                        email = ?,
                        logo_data = ?,
                        logo_filename = ?,
                        primary_color = ?,
                        secondary_color = ?,
                        accent_color = ?,
                        latitude = ?,
                        longitude = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $data['club_name'],
                    $data['address'],
                    $data['city'],
                    $data['state'],
                    $data['zip'],
                    $data['website'] ?? null,
                    $data['phone'] ?? null,
                    $data['email'] ?? null,
                    $data['logo_data'] ?? null,
                    $data['logo_filename'] ?? null,
                    $data['primary_color'] ?? null,
                    $data['secondary_color'] ?? null,
                    $data['accent_color'] ?? null,
                    $data['latitude'] ?? null,
                    $data['longitude'] ?? null,
                    $existing['id']
                ]);
            } else {
                // Create new profile
                $stmt = $connection->prepare("
                    INSERT INTO club_profile
                    (club_name, address, city, state, zip, website, phone, email, logo_data, logo_filename, primary_color, secondary_color, accent_color, latitude, longitude)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $data['club_name'],
                    $data['address'],
                    $data['city'],
                    $data['state'],
                    $data['zip'],
                    $data['website'] ?? null,
                    $data['phone'] ?? null,
                    $data['email'] ?? null,
                    $data['logo_data'] ?? null,
                    $data['logo_filename'] ?? null,
                    $data['primary_color'] ?? null,
                    $data['secondary_color'] ?? null,
                    $data['accent_color'] ?? null,
                    $data['latitude'] ?? null,
                    $data['longitude'] ?? null
                ]);
            }

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