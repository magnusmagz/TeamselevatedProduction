<?php
/**
 * Coach Notes API
 *
 * Endpoints (via ?action=):
 * - GET    ?action=list&athlete_id={id}  — List notes for an athlete
 * - POST   ?action=create                — Create a new note
 * - PUT    ?action=update                — Update a note (author only)
 * - DELETE ?action=delete&id={id}        — Delete a note (author only)
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();


require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/JWT.php';

// Get database connection
$pdo = Database::getInstance()->getConnection();

// Authenticate
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

if (!$authHeader || !preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No authorization token provided']);
    exit();
}

$token = $matches[1];

try {
    $jwt = new JWT();
    $decoded = $jwt->decode($token);
    $userId = $decoded->user_id;
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid or expired token']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'list':
            handleList($pdo, $userId);
            break;
        case 'create':
            handleCreate($pdo, $userId);
            break;
        case 'update':
            handleUpdate($pdo, $userId);
            break;
        case 'delete':
            handleDelete($pdo, $userId);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
} catch (Exception $e) {
    error_log('Coach Notes API Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}

/**
 * List notes for an athlete
 */
function handleList($pdo, $userId) {
    $athleteId = intval($_GET['athlete_id'] ?? 0);
    if (!$athleteId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'athlete_id is required']);
        return;
    }

    $stmt = $pdo->prepare("
        SELECT cn.*,
               u.first_name AS coach_first_name,
               u.last_name AS coach_last_name,
               t.team_name
        FROM coach_notes cn
        JOIN users u ON cn.coach_user_id = u.id
        LEFT JOIN teams t ON cn.team_id = t.id
        WHERE cn.athlete_id = ?
        ORDER BY cn.created_at DESC
    ");
    $stmt->execute([$athleteId]);
    $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $notes]);
}

/**
 * Create a new note
 */
function handleCreate($pdo, $userId) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'POST method required']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $athleteId = intval($input['athlete_id'] ?? 0);
    $teamId = !empty($input['team_id']) ? intval($input['team_id']) : null;
    $category = $input['category'] ?? 'general';
    $content = trim($input['content'] ?? '');
    $visibility = $input['visibility'] ?? 'coaches_only';

    if (!$athleteId || !$content) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'athlete_id and content are required']);
        return;
    }

    $validCategories = ['performance', 'attitude', 'improvement', 'standout', 'general'];
    if (!in_array($category, $validCategories)) {
        $category = 'general';
    }

    $validVisibility = ['coaches_only', 'share_with_parents'];
    if (!in_array($visibility, $validVisibility)) {
        $visibility = 'coaches_only';
    }

    $stmt = $pdo->prepare("
        INSERT INTO coach_notes (athlete_id, coach_user_id, team_id, category, content, visibility)
        VALUES (?, ?, ?, ?, ?, ?)
        RETURNING id, created_at
    ");
    $stmt->execute([$athleteId, $userId, $teamId, $category, $content, $visibility]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    // Fetch the full note with joins
    $stmt = $pdo->prepare("
        SELECT cn.*,
               u.first_name AS coach_first_name,
               u.last_name AS coach_last_name,
               t.team_name
        FROM coach_notes cn
        JOIN users u ON cn.coach_user_id = u.id
        LEFT JOIN teams t ON cn.team_id = t.id
        WHERE cn.id = ?
    ");
    $stmt->execute([$result['id']]);
    $note = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $note]);
}

/**
 * Update a note (author only)
 */
function handleUpdate($pdo, $userId) {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'PUT method required']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $noteId = intval($input['id'] ?? 0);

    if (!$noteId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Note id is required']);
        return;
    }

    // Verify ownership
    $stmt = $pdo->prepare("SELECT * FROM coach_notes WHERE id = ?");
    $stmt->execute([$noteId]);
    $note = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$note) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Note not found']);
        return;
    }

    if ((int)$note['coach_user_id'] !== (int)$userId) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'You can only edit your own notes']);
        return;
    }

    // Build update fields
    $fields = [];
    $params = [];

    if (isset($input['content']) && trim($input['content']) !== '') {
        $fields[] = 'content = ?';
        $params[] = trim($input['content']);
    }
    if (isset($input['category'])) {
        $validCategories = ['performance', 'attitude', 'improvement', 'standout', 'general'];
        if (in_array($input['category'], $validCategories)) {
            $fields[] = 'category = ?';
            $params[] = $input['category'];
        }
    }
    if (isset($input['visibility'])) {
        $validVisibility = ['coaches_only', 'share_with_parents'];
        if (in_array($input['visibility'], $validVisibility)) {
            $fields[] = 'visibility = ?';
            $params[] = $input['visibility'];
        }
    }

    if (empty($fields)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'No valid fields to update']);
        return;
    }

    $fields[] = 'updated_at = CURRENT_TIMESTAMP';
    $params[] = $noteId;

    $sql = "UPDATE coach_notes SET " . implode(', ', $fields) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // Return updated note
    $stmt = $pdo->prepare("
        SELECT cn.*,
               u.first_name AS coach_first_name,
               u.last_name AS coach_last_name,
               t.team_name
        FROM coach_notes cn
        JOIN users u ON cn.coach_user_id = u.id
        LEFT JOIN teams t ON cn.team_id = t.id
        WHERE cn.id = ?
    ");
    $stmt->execute([$noteId]);
    $updatedNote = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $updatedNote]);
}

/**
 * Delete a note (author only)
 */
function handleDelete($pdo, $userId) {
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'DELETE method required']);
        return;
    }

    $noteId = intval($_GET['id'] ?? 0);
    if (!$noteId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Note id is required']);
        return;
    }

    // Verify ownership
    $stmt = $pdo->prepare("SELECT coach_user_id FROM coach_notes WHERE id = ?");
    $stmt->execute([$noteId]);
    $note = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$note) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Note not found']);
        return;
    }

    if ((int)$note['coach_user_id'] !== (int)$userId) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'You can only delete your own notes']);
        return;
    }

    $stmt = $pdo->prepare("DELETE FROM coach_notes WHERE id = ?");
    $stmt->execute([$noteId]);

    echo json_encode(['success' => true, 'message' => 'Note deleted']);
}
