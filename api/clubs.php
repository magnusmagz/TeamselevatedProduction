<?php
/**
 * Clubs API
 * Get club information including slug
 */

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

$database = Database::getInstance();
$db = $database->getConnection();

$action = $_GET['action'] ?? 'get';

try {
    switch ($action) {
        case 'get':
            $id = $_GET['id'] ?? null;

            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'id parameter is required']);
                exit();
            }

            $stmt = $db->prepare("
                SELECT id, name, slug, description, logo_url, primary_color, secondary_color,
                       address_line1, address_line2, city, state, zip_code, phone, email, website
                FROM club_profile
                WHERE id = ?
            ");
            $stmt->execute([$id]);
            $club = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$club) {
                http_response_code(404);
                echo json_encode(['error' => 'Club not found']);
                exit();
            }

            echo json_encode($club);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
