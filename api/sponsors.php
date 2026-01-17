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

try {
    switch ($method) {
        case 'GET':
            if (isset($_GET['id'])) {
                // Get single sponsor by ID
                $stmt = $db->prepare("
                    SELECT * FROM sponsors
                    WHERE id = ? AND deleted_at IS NULL
                ");
                $stmt->execute([$_GET['id']]);
                $sponsor = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($sponsor) {
                    echo json_encode($sponsor);
                } else {
                    http_response_code(404);
                    echo json_encode(['error' => 'Sponsor not found']);
                }
            } elseif (isset($_GET['club_id'])) {
                // Get all sponsors for a club
                $includeInactive = isset($_GET['include_inactive']) && $_GET['include_inactive'] === 'true';

                $sql = "
                    SELECT * FROM sponsors
                    WHERE club_id = ? AND deleted_at IS NULL
                ";

                if (!$includeInactive) {
                    $sql .= " AND is_active = TRUE";
                }

                $sql .= " ORDER BY display_order ASC, name ASC";

                $stmt = $db->prepare($sql);
                $stmt->execute([$_GET['club_id']]);
                $sponsors = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode($sponsors);
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'club_id parameter is required']);
            }
            break;

        case 'POST':
            $data = json_decode(file_get_contents("php://input"), true);

            if (empty($data['club_id']) || empty($data['name'])) {
                http_response_code(400);
                echo json_encode(['error' => 'club_id and name are required']);
                break;
            }

            // Get next display order
            $stmt = $db->prepare("
                SELECT COALESCE(MAX(display_order), 0) + 1 as next_order
                FROM sponsors
                WHERE club_id = ? AND deleted_at IS NULL
            ");
            $stmt->execute([$data['club_id']]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $displayOrder = $result['next_order'];

            $stmt = $db->prepare("
                INSERT INTO sponsors (
                    club_id, name, website, contact_name, contact_email, contact_phone,
                    logo_data, logo_filename,
                    link_1_label, link_1_url, link_2_label, link_2_url, link_3_label, link_3_url,
                    display_order, is_active, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $data['club_id'],
                $data['name'],
                $data['website'] ?? null,
                $data['contact_name'] ?? null,
                $data['contact_email'] ?? null,
                $data['contact_phone'] ?? null,
                $data['logo_data'] ?? null,
                $data['logo_filename'] ?? null,
                $data['link_1_label'] ?? null,
                $data['link_1_url'] ?? null,
                $data['link_2_label'] ?? null,
                $data['link_2_url'] ?? null,
                $data['link_3_label'] ?? null,
                $data['link_3_url'] ?? null,
                $displayOrder,
                $data['is_active'] ?? true,
                $data['created_by'] ?? null
            ]);

            $sponsorId = $db->lastInsertId();

            echo json_encode([
                'success' => true,
                'id' => $sponsorId,
                'message' => 'Sponsor created successfully'
            ]);
            break;

        case 'PUT':
            $data = json_decode(file_get_contents("php://input"), true);

            // Handle reorder action
            if (isset($_GET['action']) && $_GET['action'] === 'reorder') {
                if (empty($data['sponsors']) || !is_array($data['sponsors'])) {
                    http_response_code(400);
                    echo json_encode(['error' => 'sponsors array is required']);
                    break;
                }

                $db->beginTransaction();
                try {
                    $stmt = $db->prepare("UPDATE sponsors SET display_order = ? WHERE id = ?");
                    foreach ($data['sponsors'] as $index => $sponsorId) {
                        $stmt->execute([$index, $sponsorId]);
                    }
                    $db->commit();

                    echo json_encode([
                        'success' => true,
                        'message' => 'Display order updated successfully'
                    ]);
                } catch (Exception $e) {
                    $db->rollBack();
                    throw $e;
                }
                break;
            }

            // Regular update
            if (empty($data['id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'id is required']);
                break;
            }

            $stmt = $db->prepare("
                UPDATE sponsors SET
                    name = ?,
                    website = ?,
                    contact_name = ?,
                    contact_email = ?,
                    contact_phone = ?,
                    logo_data = ?,
                    logo_filename = ?,
                    link_1_label = ?,
                    link_1_url = ?,
                    link_2_label = ?,
                    link_2_url = ?,
                    link_3_label = ?,
                    link_3_url = ?,
                    is_active = ?,
                    updated_at = CURRENT_TIMESTAMP,
                    updated_by = ?
                WHERE id = ? AND deleted_at IS NULL
            ");

            $stmt->execute([
                $data['name'],
                $data['website'] ?? null,
                $data['contact_name'] ?? null,
                $data['contact_email'] ?? null,
                $data['contact_phone'] ?? null,
                $data['logo_data'] ?? null,
                $data['logo_filename'] ?? null,
                $data['link_1_label'] ?? null,
                $data['link_1_url'] ?? null,
                $data['link_2_label'] ?? null,
                $data['link_2_url'] ?? null,
                $data['link_3_label'] ?? null,
                $data['link_3_url'] ?? null,
                $data['is_active'] ?? true,
                $data['updated_by'] ?? null,
                $data['id']
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Sponsor updated successfully'
            ]);
            break;

        case 'DELETE':
            if (!isset($_GET['id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'id parameter is required']);
                break;
            }

            $data = json_decode(file_get_contents("php://input"), true);

            // Soft delete
            $stmt = $db->prepare("
                UPDATE sponsors
                SET deleted_at = CURRENT_TIMESTAMP, deleted_by = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $data['deleted_by'] ?? null,
                $_GET['id']
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Sponsor deleted successfully'
            ]);
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
