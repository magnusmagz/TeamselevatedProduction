<?php
/**
 * Clubs API — PUBLIC, unauthenticated.
 *
 * Its one live caller is FundraiserAdminWrapper (App.tsx), which needs the slug.
 * Until 2026-09-09 it also returned the club's email, phone and both address
 * lines to anyone who guessed an id; the projection is now the same public set
 * lib/club_public_page.php serves, minus contact details. Anything a signed-in
 * admin needs comes from legacy/club-profile-gateway.php.
 */

header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();


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
                SELECT id, name, slug, logo_url, primary_color, secondary_color,
                       city, state, website
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
