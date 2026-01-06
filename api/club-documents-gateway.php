<?php
/**
 * Club Documents Gateway API
 *
 * Manage documents/links associated with a club
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

try {
    $auth = AuthMiddleware::requireAuth();
    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'GET':
            // Get all documents for a club
            $clubId = $_GET['clubId'] ?? null;

            if (!$clubId) {
                http_response_code(400);
                echo json_encode(['error' => 'clubId is required']);
                exit();
            }

            // Check if user can access this club
            if (!$auth->canAccessClub($connection, $clubId)) {
                http_response_code(403);
                echo json_encode(['error' => 'Access denied']);
                exit();
            }

            $stmt = $connection->prepare("
                SELECT
                    cd.id,
                    cd.name,
                    cd.url,
                    cd.created_by,
                    CONCAT(u.first_name, ' ', u.last_name) as creator_name,
                    cd.created_at
                FROM club_documents cd
                LEFT JOIN users u ON cd.created_by = u.id
                WHERE cd.club_profile_id = ?
                ORDER BY cd.created_at DESC
            ");
            $stmt->execute([$clubId]);
            $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'documents' => $documents
            ]);
            break;

        case 'POST':
            // Create a new document
            $data = json_decode(file_get_contents("php://input"), true);
            $clubId = $data['clubId'] ?? null;
            $name = $data['name'] ?? null;
            $url = $data['url'] ?? null;

            if (!$clubId || !$name || !$url) {
                http_response_code(400);
                echo json_encode(['error' => 'clubId, name, and url are required']);
                exit();
            }

            // Check if user has admin access to this club
            if (!$auth->can('manage_club', $clubId, 'club')) {
                http_response_code(403);
                echo json_encode(['error' => 'You do not have permission to add documents']);
                exit();
            }

            $stmt = $connection->prepare("
                INSERT INTO club_documents (club_profile_id, name, url, created_by)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$clubId, $name, $url, $auth->getUserId()]);

            echo json_encode([
                'success' => true,
                'id' => $connection->lastInsertId(),
                'message' => 'Document added successfully'
            ]);
            break;

        case 'PUT':
            // Update a document
            $data = json_decode(file_get_contents("php://input"), true);
            $docId = $data['id'] ?? null;
            $name = $data['name'] ?? null;
            $url = $data['url'] ?? null;

            if (!$docId || !$name || !$url) {
                http_response_code(400);
                echo json_encode(['error' => 'id, name, and url are required']);
                exit();
            }

            // Get the document to check club access
            $stmt = $connection->prepare("SELECT club_profile_id FROM club_documents WHERE id = ?");
            $stmt->execute([$docId]);
            $doc = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$doc) {
                http_response_code(404);
                echo json_encode(['error' => 'Document not found']);
                exit();
            }

            // Check if user has admin access to this club
            if (!$auth->can('manage_club', $doc['club_profile_id'], 'club')) {
                http_response_code(403);
                echo json_encode(['error' => 'You do not have permission to edit documents']);
                exit();
            }

            $stmt = $connection->prepare("
                UPDATE club_documents
                SET name = ?, url = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$name, $url, $docId]);

            echo json_encode([
                'success' => true,
                'message' => 'Document updated successfully'
            ]);
            break;

        case 'DELETE':
            // Delete a document
            $docId = $_GET['id'] ?? null;

            if (!$docId) {
                http_response_code(400);
                echo json_encode(['error' => 'Document id is required']);
                exit();
            }

            // Get the document to check club access
            $stmt = $connection->prepare("SELECT club_profile_id FROM club_documents WHERE id = ?");
            $stmt->execute([$docId]);
            $doc = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$doc) {
                http_response_code(404);
                echo json_encode(['error' => 'Document not found']);
                exit();
            }

            // Check if user has admin access to this club
            if (!$auth->can('manage_club', $doc['club_profile_id'], 'club')) {
                http_response_code(403);
                echo json_encode(['error' => 'You do not have permission to delete documents']);
                exit();
            }

            $stmt = $connection->prepare("DELETE FROM club_documents WHERE id = ?");
            $stmt->execute([$docId]);

            echo json_encode([
                'success' => true,
                'message' => 'Document deleted successfully'
            ]);
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    error_log("Club Documents Gateway Error: " . $e->getMessage());
}
?>
