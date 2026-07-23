<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';

$database = Database::getInstance();
$db = $database->getConnection();

$method = $_SERVER['REQUEST_METHOD'];
$request_uri = $_SERVER['REQUEST_URI'];
$uri_parts = explode('/', parse_url($request_uri, PHP_URL_PATH));

try {
    switch ($method) {
        case 'GET':
            if (isset($uri_parts[4]) && is_numeric($uri_parts[4])) {
                // Get specific venue with fields
                $venue_id = $uri_parts[4];

                $stmt = $db->prepare("
                    SELECT v.*,
                           COUNT(f.id) as field_count
                    FROM venues v
                    LEFT JOIN fields f ON v.id = f.venue_id
                    WHERE v.id = ?
                    GROUP BY v.id
                ");
                $stmt->execute([$venue_id]);
                $venue = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($venue) {
                    // Get fields for this venue
                    $stmt = $db->prepare("
                        SELECT * FROM fields
                        WHERE venue_id = ?
                        ORDER BY name
                    ");
                    $stmt->execute([$venue_id]);
                    $venue['fields'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }

                echo json_encode($venue);
            } else {
                // Get all venues
                $stmt = $db->prepare("
                    SELECT v.*,
                           COUNT(f.id) as field_count
                    FROM venues v
                    LEFT JOIN fields f ON v.id = f.venue_id
                    GROUP BY v.id
                    ORDER BY v.name
                ");
                $stmt->execute();
                $venues = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode($venues);
            }
            break;

        case 'POST':
            // AuthZ: writes require a valid token and an accessible owning club.
            $auth = AuthMiddleware::requireAuth();
            $accessibleClubIds = $auth->getAccessibleClubIds(); // null = super admin
            $data = json_decode(file_get_contents("php://input"), true);

            $clubId = $data['club_profile_id'] ?? $data['club_id'] ?? null;
            if ($clubId === null && is_array($accessibleClubIds) && count($accessibleClubIds) === 1) {
                $clubId = $accessibleClubIds[0]; // single-club admin: unambiguous default
            }
            if ($clubId === null) {
                http_response_code(400);
                echo json_encode(['error' => 'club_profile_id is required']);
                exit();
            }
            if (!$auth->canAccessClub((int) $clubId)) {
                http_response_code(403);
                echo json_encode(['error' => 'Not authorized for this club']);
                exit();
            }

            // Begin transaction
            $db->beginTransaction();

            try {
                // Insert venue
                $stmt = $db->prepare("
                    INSERT INTO venues (
                        club_id, name, address, city, state, zip_code, map_url, website,
                        maintenance_contact_name, maintenance_contact_phone, maintenance_contact_email,
                        emergency_contact_name, emergency_contact_phone, emergency_contact_email,
                        billing_contact_name, billing_contact_phone, billing_contact_email,
                        gm_contact_name, gm_contact_phone, gm_contact_email
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    (int) $clubId,
                    $data['name'],
                    $data['address'],
                    $data['city'] ?? null,
                    $data['state'] ?? null,
                    $data['zip_code'] ?? $data['zip'] ?? null,
                    $data['map_url'] ?? null,
                    $data['website'] ?? null,
                    $data['maintenance_contact_name'] ?? null,
                    $data['maintenance_contact_phone'] ?? null,
                    $data['maintenance_contact_email'] ?? null,
                    $data['emergency_contact_name'] ?? null,
                    $data['emergency_contact_phone'] ?? null,
                    $data['emergency_contact_email'] ?? null,
                    $data['billing_contact_name'] ?? null,
                    $data['billing_contact_phone'] ?? null,
                    $data['billing_contact_email'] ?? null,
                    $data['gm_contact_name'] ?? null,
                    $data['gm_contact_phone'] ?? null,
                    $data['gm_contact_email'] ?? null
                ]);

                $venue_id = $db->lastInsertId();

                // Insert fields if provided
                if (!empty($data['fields'])) {
                    $field_stmt = $db->prepare("
                        INSERT INTO fields (venue_id, name, field_type, surface_type, dimensions, has_lights, status)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");

                    foreach ($data['fields'] as $field) {
                        $field_stmt->execute([
                            $venue_id,
                            $field['name'],
                            $field['field_type'] ?? 'Soccer',
                            $field['surface_type'] ?? $field['surface'] ?? 'Grass',
                            $field['dimensions'] ?? $field['size'] ?? null,
                            $field['has_lights'] ?? $field['lights'] ?? false,
                            $field['status'] ?? 'available'
                        ]);
                    }
                }

                $db->commit();

                echo json_encode([
                    'success' => true,
                    'id' => $venue_id,
                    'message' => 'Venue created successfully'
                ]);
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
            break;

        case 'PUT':
            if (isset($uri_parts[4]) && is_numeric($uri_parts[4])) {
                $venue_id = $uri_parts[4];

                // AuthZ: writes require a valid token and ownership of the venue's club.
                $auth = AuthMiddleware::requireAuth();
                $own = $db->prepare("SELECT club_id FROM venues WHERE id = ?");
                $own->execute([$venue_id]);
                $ownRow = $own->fetch(PDO::FETCH_ASSOC);
                if (!$ownRow) {
                    http_response_code(404);
                    echo json_encode(['error' => 'Venue not found']);
                    exit();
                }
                if (!$auth->canAccessClub((int) ($ownRow['club_id'] ?? 0))) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Not authorized for this facility']);
                    exit();
                }

                $data = json_decode(file_get_contents("php://input"), true);

                $db->beginTransaction();

                try {
                    // Update venue
                    $stmt = $db->prepare("
                        UPDATE venues
                        SET name = ?, address = ?, city = ?, state = ?, zip_code = ?, map_url = ?, website = ?,
                            maintenance_contact_name = ?, maintenance_contact_phone = ?, maintenance_contact_email = ?,
                            emergency_contact_name = ?, emergency_contact_phone = ?, emergency_contact_email = ?,
                            billing_contact_name = ?, billing_contact_phone = ?, billing_contact_email = ?,
                            gm_contact_name = ?, gm_contact_phone = ?, gm_contact_email = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $data['name'],
                        $data['address'],
                        $data['city'] ?? null,
                        $data['state'] ?? null,
                        $data['zip_code'] ?? $data['zip'] ?? null,
                        $data['map_url'] ?? null,
                        $data['website'] ?? null,
                        $data['maintenance_contact_name'] ?? null,
                        $data['maintenance_contact_phone'] ?? null,
                        $data['maintenance_contact_email'] ?? null,
                        $data['emergency_contact_name'] ?? null,
                        $data['emergency_contact_phone'] ?? null,
                        $data['emergency_contact_email'] ?? null,
                        $data['billing_contact_name'] ?? null,
                        $data['billing_contact_phone'] ?? null,
                        $data['billing_contact_email'] ?? null,
                        $data['gm_contact_name'] ?? null,
                        $data['gm_contact_phone'] ?? null,
                        $data['gm_contact_email'] ?? null,
                        $venue_id
                    ]);

                    // Delete existing fields
                    $stmt = $db->prepare("DELETE FROM fields WHERE venue_id = ?");
                    $stmt->execute([$venue_id]);

                    // Insert new fields
                    if (!empty($data['fields'])) {
                        $field_stmt = $db->prepare("
                            INSERT INTO fields (venue_id, name, field_type, surface_type, dimensions, has_lights, status)
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                        ");

                        foreach ($data['fields'] as $field) {
                            $field_stmt->execute([
                                $venue_id,
                                $field['name'],
                                $field['field_type'] ?? 'Soccer',
                                $field['surface_type'] ?? $field['surface'] ?? 'Grass',
                                $field['dimensions'] ?? $field['size'] ?? null,
                                $field['has_lights'] ?? $field['lights'] ?? false,
                                $field['status'] ?? 'available'
                            ]);
                        }
                    }

                    $db->commit();

                    echo json_encode([
                        'success' => true,
                        'message' => 'Venue updated successfully'
                    ]);
                } catch (Exception $e) {
                    $db->rollBack();
                    throw $e;
                }
            }
            break;

        case 'DELETE':
            if (isset($uri_parts[4]) && is_numeric($uri_parts[4])) {
                $venue_id = $uri_parts[4];

                // AuthZ: writes require a valid token and ownership of the venue's club.
                $auth = AuthMiddleware::requireAuth();
                $own = $db->prepare("SELECT club_id FROM venues WHERE id = ?");
                $own->execute([$venue_id]);
                $ownRow = $own->fetch(PDO::FETCH_ASSOC);
                if (!$ownRow) {
                    http_response_code(404);
                    echo json_encode(['error' => 'Venue not found']);
                    exit();
                }
                if (!$auth->canAccessClub((int) ($ownRow['club_id'] ?? 0))) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Not authorized for this facility']);
                    exit();
                }

                $stmt = $db->prepare("DELETE FROM venues WHERE id = ?");
                $stmt->execute([$venue_id]);

                echo json_encode([
                    'success' => true,
                    'message' => 'Venue deleted successfully'
                ]);
            }
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>