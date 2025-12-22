<?php
/**
 * Leagues Management API
 *
 * Handles league CRUD operations
 *
 * Endpoints:
 * - GET ?action=get&id=X - Get single league by ID
 * - GET ?action=list - List all leagues
 * - PUT ?action=update&id=X - Update league information
 */

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, PUT, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

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
$action = $_GET['action'] ?? 'list';
$leagueId = $_GET['id'] ?? null;

try {
    switch ($method) {
        case 'GET':
            if ($action === 'get' && $leagueId) {
                handleGetLeague($connection, $leagueId);
            } elseif ($action === 'list') {
                handleListLeagues($connection);
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid action or missing league ID']);
            }
            break;

        case 'PUT':
            if ($action === 'update' && $leagueId) {
                handleUpdateLeague($connection, $leagueId);
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Missing league ID for update']);
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
    error_log("Leagues Gateway Error: " . $e->getMessage());
}

/**
 * Get single league by ID
 */
function handleGetLeague($connection, $leagueId) {
    $stmt = $connection->prepare("
        SELECT
            id,
            name,
            description,
            website,
            contact_email,
            contact_phone,
            logo_url,
            address,
            city,
            state,
            zip_code,
            active,
            created_at,
            updated_at
        FROM leagues
        WHERE id = ?
    ");
    $stmt->execute([$leagueId]);
    $league = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$league) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'League not found'
        ]);
        return;
    }

    echo json_encode([
        'success' => true,
        'league' => $league
    ]);
}

/**
 * List all leagues
 */
function handleListLeagues($connection) {
    $stmt = $connection->prepare("
        SELECT
            id,
            name,
            description,
            logo_url,
            active,
            created_at
        FROM leagues
        WHERE active = TRUE
        ORDER BY name ASC
    ");
    $stmt->execute();
    $leagues = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'leagues' => $leagues
    ]);
}

/**
 * Update league information
 */
function handleUpdateLeague($connection, $leagueId) {
    $data = json_decode(file_get_contents("php://input"), true);

    // Verify league exists
    $stmt = $connection->prepare("SELECT id FROM leagues WHERE id = ?");
    $stmt->execute([$leagueId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'League not found'
        ]);
        return;
    }

    // Build dynamic UPDATE query based on provided fields
    $updates = [];
    $params = [];

    $allowedFields = [
        'name', 'description', 'website', 'contact_email', 'contact_phone',
        'logo_url', 'address', 'city', 'state', 'zip_code', 'active'
    ];

    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $updates[] = "$field = ?";
            $params[] = $data[$field];
        }
    }

    if (empty($updates)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'No valid fields to update'
        ]);
        return;
    }

    // Add league ID to params
    $params[] = $leagueId;

    $sql = "UPDATE leagues SET " . implode(', ', $updates) . ", updated_at = CURRENT_TIMESTAMP WHERE id = ?";

    try {
        $stmt = $connection->prepare($sql);
        $stmt->execute($params);

        echo json_encode([
            'success' => true,
            'message' => 'League updated successfully'
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to update league: ' . $e->getMessage()
        ]);
    }
}
?>
