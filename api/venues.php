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
            $data = json_decode(file_get_contents("php://input"), true);

            // Begin transaction
            $db->beginTransaction();

            try {
                // Insert venue
                $stmt = $db->prepare("
                    INSERT INTO venues (name, address, city, state, zip, map_url, website)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $data['name'],
                    $data['address'],
                    $data['city'] ?? null,
                    $data['state'] ?? null,
                    $data['zip'] ?? null,
                    $data['map_url'] ?? null,
                    $data['website'] ?? null
                ]);

                $venue_id = $db->lastInsertId();

                // Insert fields if provided
                if (!empty($data['fields'])) {
                    $field_stmt = $db->prepare("
                        INSERT INTO fields (venue_id, name, field_type, surface, size, lights, status)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");

                    foreach ($data['fields'] as $field) {
                        $field_stmt->execute([
                            $venue_id,
                            $field['name'],
                            $field['field_type'] ?? 'Soccer',
                            $field['surface'] ?? 'Grass',
                            $field['size'] ?? null,
                            $field['lights'] ?? false,
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
                $data = json_decode(file_get_contents("php://input"), true);

                $db->beginTransaction();

                try {
                    // Update venue
                    $stmt = $db->prepare("
                        UPDATE venues
                        SET name = ?, address = ?, city = ?, state = ?, zip = ?, map_url = ?, website = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $data['name'],
                        $data['address'],
                        $data['city'] ?? null,
                        $data['state'] ?? null,
                        $data['zip'] ?? null,
                        $data['map_url'] ?? null,
                        $data['website'] ?? null,
                        $venue_id
                    ]);

                    // Delete existing fields
                    $stmt = $db->prepare("DELETE FROM fields WHERE venue_id = ?");
                    $stmt->execute([$venue_id]);

                    // Insert new fields
                    if (!empty($data['fields'])) {
                        $field_stmt = $db->prepare("
                            INSERT INTO fields (venue_id, name, field_type, surface, size, lights, status)
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                        ");

                        foreach ($data['fields'] as $field) {
                            $field_stmt->execute([
                                $venue_id,
                                $field['name'],
                                $field['field_type'] ?? 'Soccer',
                                $field['surface'] ?? 'Grass',
                                $field['size'] ?? null,
                                $field['lights'] ?? false,
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