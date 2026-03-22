<?php
/**
 * Club Document Center API
 *
 * Manages documents organized by predefined slots (background check, CPR, etc.)
 * and custom documents for a club.
 */

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

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

$defaultSlots = [
    'background_check' => 'Background Check',
    'abuse_prevention' => 'Abuse Prevention Training',
    'concussion' => 'Concussion Protocol',
    'code_of_conduct' => 'Code of Conduct',
    'coaches_certificate' => 'Coaches Certificate',
    'attestation' => 'Attestation',
    'first_aid' => 'First Aid',
    'cpr' => 'CPR',
    'trainings' => 'Trainings',
];

try {
    $auth = AuthMiddleware::requireAuth();
    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'GET':
            // Single document by id
            if (isset($_GET['id'])) {
                $docId = $_GET['id'];
                $stmt = $connection->prepare("
                    SELECT cd.*, CONCAT(u.first_name, ' ', u.last_name) as creator_name
                    FROM club_documents cd
                    LEFT JOIN users u ON cd.created_by = u.id
                    WHERE cd.id = ?
                ");
                $stmt->execute([$docId]);
                $doc = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$doc) {
                    http_response_code(404);
                    echo json_encode(['error' => 'Document not found']);
                    exit();
                }

                if (!$auth->canAccessClub($doc['club_profile_id'])) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Access denied']);
                    exit();
                }

                echo json_encode(['success' => true, 'document' => $doc]);
                break;
            }

            // List all documents for a club, grouped by slot
            $clubId = $_GET['club_profile_id'] ?? $_GET['clubId'] ?? null;

            if (!$clubId) {
                http_response_code(400);
                echo json_encode(['error' => 'club_profile_id is required']);
                exit();
            }

            if (!$auth->canAccessClub($clubId)) {
                http_response_code(403);
                echo json_encode(['error' => 'Access denied']);
                exit();
            }

            $stmt = $connection->prepare("
                SELECT cd.*, CONCAT(u.first_name, ' ', u.last_name) as creator_name
                FROM club_documents cd
                LEFT JOIN users u ON cd.created_by = u.id
                WHERE cd.club_profile_id = ?
                ORDER BY cd.created_at DESC
            ");
            $stmt->execute([$clubId]);
            $allDocs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Group documents into slots and custom
            $slots = [];
            $customDocuments = [];

            foreach ($defaultSlots as $key => $label) {
                $slots[] = [
                    'key' => $key,
                    'label' => $label,
                    'documents' => [],
                ];
            }

            foreach ($allDocs as $doc) {
                $slotKey = $doc['slot'] ?? null;
                $placed = false;

                if ($slotKey) {
                    foreach ($slots as &$slot) {
                        if ($slot['key'] === $slotKey) {
                            $slot['documents'][] = $doc;
                            $placed = true;
                            break;
                        }
                    }
                    unset($slot);
                }

                if (!$placed) {
                    $customDocuments[] = $doc;
                }
            }

            echo json_encode([
                'success' => true,
                'slots' => $slots,
                'custom_documents' => $customDocuments,
            ]);
            break;

        case 'POST':
            $data = json_decode(file_get_contents("php://input"), true);
            $clubId = $data['club_profile_id'] ?? $data['clubId'] ?? null;
            $slot = $data['slot'] ?? null;
            $name = $data['name'] ?? null;
            $url = $data['url'] ?? null;
            $linkUrl = $data['link_url'] ?? null;
            $fileType = $data['file_type'] ?? null;
            $notes = $data['notes'] ?? null;

            if (!$clubId || !$name) {
                http_response_code(400);
                echo json_encode(['error' => 'club_profile_id and name are required']);
                exit();
            }

            if (!$url && !$linkUrl) {
                http_response_code(400);
                echo json_encode(['error' => 'Either url (uploaded file) or link_url (external link) is required']);
                exit();
            }

            if (!$auth->can('manage_club', $clubId, 'club')) {
                http_response_code(403);
                echo json_encode(['error' => 'You do not have permission to add documents']);
                exit();
            }

            $stmt = $connection->prepare("
                INSERT INTO club_documents (club_profile_id, name, url, slot, link_url, file_type, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$clubId, $name, $url, $slot, $linkUrl, $fileType, $notes, $auth->getUserId()]);

            echo json_encode([
                'success' => true,
                'id' => $connection->lastInsertId(),
                'message' => 'Document added successfully',
            ]);
            break;

        case 'PUT':
            $data = json_decode(file_get_contents("php://input"), true);
            $docId = $data['id'] ?? null;

            if (!$docId) {
                http_response_code(400);
                echo json_encode(['error' => 'Document id is required']);
                exit();
            }

            // Get existing document to check club access
            $stmt = $connection->prepare("SELECT club_profile_id FROM club_documents WHERE id = ?");
            $stmt->execute([$docId]);
            $doc = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$doc) {
                http_response_code(404);
                echo json_encode(['error' => 'Document not found']);
                exit();
            }

            if (!$auth->can('manage_club', $doc['club_profile_id'], 'club')) {
                http_response_code(403);
                echo json_encode(['error' => 'You do not have permission to edit documents']);
                exit();
            }

            // Build dynamic update
            $fields = [];
            $params = [];

            if (isset($data['name'])) {
                $fields[] = 'name = ?';
                $params[] = $data['name'];
            }
            if (array_key_exists('url', $data)) {
                $fields[] = 'url = ?';
                $params[] = $data['url'];
            }
            if (array_key_exists('link_url', $data)) {
                $fields[] = 'link_url = ?';
                $params[] = $data['link_url'];
            }
            if (array_key_exists('notes', $data)) {
                $fields[] = 'notes = ?';
                $params[] = $data['notes'];
            }
            if (array_key_exists('slot', $data)) {
                $fields[] = 'slot = ?';
                $params[] = $data['slot'];
            }

            if (empty($fields)) {
                http_response_code(400);
                echo json_encode(['error' => 'No fields to update']);
                exit();
            }

            $fields[] = 'updated_at = CURRENT_TIMESTAMP';
            $params[] = $docId;

            $sql = "UPDATE club_documents SET " . implode(', ', $fields) . " WHERE id = ?";
            $stmt = $connection->prepare($sql);
            $stmt->execute($params);

            echo json_encode([
                'success' => true,
                'message' => 'Document updated successfully',
            ]);
            break;

        case 'DELETE':
            $docId = $_GET['id'] ?? null;

            if (!$docId) {
                http_response_code(400);
                echo json_encode(['error' => 'Document id is required']);
                exit();
            }

            $stmt = $connection->prepare("SELECT club_profile_id FROM club_documents WHERE id = ?");
            $stmt->execute([$docId]);
            $doc = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$doc) {
                http_response_code(404);
                echo json_encode(['error' => 'Document not found']);
                exit();
            }

            if (!$auth->can('manage_club', $doc['club_profile_id'], 'club')) {
                http_response_code(403);
                echo json_encode(['error' => 'You do not have permission to delete documents']);
                exit();
            }

            $stmt = $connection->prepare("DELETE FROM club_documents WHERE id = ?");
            $stmt->execute([$docId]);

            echo json_encode([
                'success' => true,
                'message' => 'Document deleted successfully',
            ]);
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    error_log("Club Document Center Error: " . $e->getMessage());
}
?>
