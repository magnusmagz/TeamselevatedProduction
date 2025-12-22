<?php
/**
 * League Documents Management API
 *
 * Manages documents (links) for leagues
 *
 * Endpoints:
 * - GET ?leagueId=X - List all documents for a league
 * - POST ?action=create - Create a new document
 * - PUT ?action=update - Update a document
 * - DELETE ?action=delete - Delete a document
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

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$leagueId = $_GET['leagueId'] ?? null;

try {
    // Require authentication for all endpoints
    $auth = AuthMiddleware::requireAuth();

    switch ($method) {
        case 'GET':
            handleGetDocuments($connection, $auth, $leagueId);
            break;

        case 'POST':
            if ($action === 'create') {
                handleCreateDocument($connection, $auth);
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid action']);
            }
            break;

        case 'PUT':
            if ($action === 'update') {
                handleUpdateDocument($connection, $auth);
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid action']);
            }
            break;

        case 'DELETE':
            if ($action === 'delete') {
                handleDeleteDocument($connection, $auth);
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid action']);
            }
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    error_log("League Documents Gateway Error: " . $e->getMessage());
}

/**
 * Get all documents for a league
 */
function handleGetDocuments($connection, $auth, $leagueId) {
    if (!$leagueId) {
        http_response_code(400);
        echo json_encode(['error' => 'League ID is required']);
        return;
    }

    // Anyone with access to the league can view documents
    // Permission check is implicit - if they can access league settings, they can view documents

    $stmt = $connection->prepare("
        SELECT
            d.id,
            d.name,
            d.url,
            d.created_at,
            d.updated_at,
            u.first_name || ' ' || u.last_name as created_by_name
        FROM league_documents d
        LEFT JOIN users u ON d.created_by = u.id
        WHERE d.league_id = ?
        ORDER BY d.created_at DESC
    ");
    $stmt->execute([$leagueId]);
    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'documents' => $documents
    ]);
}

/**
 * Create a new document
 */
function handleCreateDocument($connection, $auth) {
    $data = json_decode(file_get_contents("php://input"), true);

    // Validate required fields
    if (empty($data['leagueId']) || empty($data['name']) || empty($data['url'])) {
        http_response_code(400);
        echo json_encode(['error' => 'League ID, name, and URL are required']);
        return;
    }

    $leagueId = $data['leagueId'];
    $name = trim($data['name']);
    $url = trim($data['url']);

    // Validate URL format
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid URL format']);
        return;
    }

    // Check if user is a league admin for this league
    if (!$auth->isSuperAdmin() && !$auth->hasRole('league_admin', $leagueId, 'league')) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied. You must be a league admin to add documents.']);
        return;
    }

    // Verify league exists
    $stmt = $connection->prepare("SELECT id FROM leagues WHERE id = ?");
    $stmt->execute([$leagueId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'League not found']);
        return;
    }

    try {
        $stmt = $connection->prepare("
            INSERT INTO league_documents (league_id, name, url, created_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            RETURNING id
        ");
        $stmt->execute([$leagueId, $name, $url, $auth->getUserId()]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'message' => 'Document added successfully',
            'id' => $result['id']
        ]);
    } catch (Exception $e) {
        throw $e;
    }
}

/**
 * Update a document
 */
function handleUpdateDocument($connection, $auth) {
    $data = json_decode(file_get_contents("php://input"), true);

    // Validate required fields
    if (empty($data['id']) || empty($data['leagueId']) || empty($data['name']) || empty($data['url'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Document ID, league ID, name, and URL are required']);
        return;
    }

    $documentId = $data['id'];
    $leagueId = $data['leagueId'];
    $name = trim($data['name']);
    $url = trim($data['url']);

    // Validate URL format
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid URL format']);
        return;
    }

    // Check if user is a league admin for this league
    if (!$auth->isSuperAdmin() && !$auth->hasRole('league_admin', $leagueId, 'league')) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied. You must be a league admin to update documents.']);
        return;
    }

    // Verify document exists and belongs to this league
    $stmt = $connection->prepare("SELECT id FROM league_documents WHERE id = ? AND league_id = ?");
    $stmt->execute([$documentId, $leagueId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'Document not found']);
        return;
    }

    try {
        $stmt = $connection->prepare("
            UPDATE league_documents
            SET name = ?, url = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([$name, $url, $documentId]);

        echo json_encode([
            'success' => true,
            'message' => 'Document updated successfully'
        ]);
    } catch (Exception $e) {
        throw $e;
    }
}

/**
 * Delete a document
 */
function handleDeleteDocument($connection, $auth) {
    $data = json_decode(file_get_contents("php://input"), true);

    // Validate required fields
    if (empty($data['id']) || empty($data['leagueId'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Document ID and league ID are required']);
        return;
    }

    $documentId = $data['id'];
    $leagueId = $data['leagueId'];

    // Check if user is a league admin for this league
    if (!$auth->isSuperAdmin() && !$auth->hasRole('league_admin', $leagueId, 'league')) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied. You must be a league admin to delete documents.']);
        return;
    }

    // Verify document exists and belongs to this league
    $stmt = $connection->prepare("SELECT id FROM league_documents WHERE id = ? AND league_id = ?");
    $stmt->execute([$documentId, $leagueId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'Document not found']);
        return;
    }

    try {
        $stmt = $connection->prepare("DELETE FROM league_documents WHERE id = ?");
        $stmt->execute([$documentId]);

        echo json_encode([
            'success' => true,
            'message' => 'Document deleted successfully'
        ]);
    } catch (Exception $e) {
        throw $e;
    }
}
?>
