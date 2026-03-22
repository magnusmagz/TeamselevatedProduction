<?php
/**
 * Email Templates API Gateway
 *
 * Actions: list, get, create, update, delete, duplicate, merge-fields
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

$auth = AuthMiddleware::requireAuth();
$userId = $auth->getUserId();
$isAdmin = $auth->hasRole('club_admin') || $auth->isSuperAdmin();

$database = Database::getInstance();
$db = $database->getConnection();

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($action) {

        // ============================================
        // LIST TEMPLATES
        // ============================================
        case 'list':
            if ($method !== 'GET') { methodNotAllowed(); }

            $clubProfileId = (int)($_GET['club_profile_id'] ?? 0);
            if (!$clubProfileId) { badRequest('club_profile_id is required'); }

            requireClubAccess($auth, $clubProfileId);

            $sql = "SELECT id, club_profile_id, name, subject, category, scope,
                           team_visibility, is_active, cloned_from,
                           created_by, updated_by, created_at, updated_at
                    FROM email_templates
                    WHERE club_profile_id = ?
                    ORDER BY updated_at DESC";
            $stmt = $db->prepare($sql);
            $stmt->execute([$clubProfileId]);
            $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Parse JSON fields
            foreach ($templates as &$t) {
                $t['team_visibility'] = json_decode($t['team_visibility'], true) ?: [];
                $t['is_active'] = (bool)$t['is_active'];
            }

            echo json_encode(['success' => true, 'templates' => $templates]);
            break;

        // ============================================
        // GET SINGLE TEMPLATE
        // ============================================
        case 'get':
            if ($method !== 'GET') { methodNotAllowed(); }

            $id = (int)($_GET['id'] ?? 0);
            if (!$id) { badRequest('id is required'); }

            $stmt = $db->prepare("SELECT * FROM email_templates WHERE id = ?");
            $stmt->execute([$id]);
            $template = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$template) { notFound('Template not found'); }

            requireClubAccess($auth, $template['club_profile_id']);

            $template['team_visibility'] = json_decode($template['team_visibility'], true) ?: [];
            $template['design_json'] = json_decode($template['design_json'], true);
            $template['is_active'] = (bool)$template['is_active'];

            echo json_encode(['success' => true, 'template' => $template]);
            break;

        // ============================================
        // CREATE TEMPLATE
        // ============================================
        case 'create':
            if ($method !== 'POST') { methodNotAllowed(); }

            if (!$isAdmin) { forbidden('Only club admins can create templates'); }

            $data = json_decode(file_get_contents('php://input'), true);
            $clubProfileId = (int)($data['club_profile_id'] ?? 0);

            if (!$clubProfileId) { badRequest('club_profile_id is required'); }
            if (empty($data['name'])) { badRequest('name is required'); }

            requireClubAccess($auth, $clubProfileId);

            $sql = "INSERT INTO email_templates
                        (club_profile_id, name, subject, design_json, html_output,
                         category, is_active, scope, team_visibility,
                         created_by, updated_by)
                    VALUES (?, ?, ?, ?::jsonb, ?, ?, ?, ?, ?::jsonb, ?, ?)
                    RETURNING id";
            $stmt = $db->prepare($sql);
            $stmt->execute([
                $clubProfileId,
                $data['name'],
                $data['subject'] ?? '',
                json_encode($data['design_json'] ?? null),
                $data['html_output'] ?? '',
                $data['category'] ?? 'general',
                isset($data['is_active']) ? ($data['is_active'] ? 'true' : 'false') : 'true',
                $data['scope'] ?? 'club',
                json_encode($data['team_visibility'] ?? []),
                $userId,
                $userId,
            ]);
            $newId = $stmt->fetchColumn();

            http_response_code(201);
            echo json_encode(['success' => true, 'id' => $newId, 'message' => 'Template created']);
            break;

        // ============================================
        // UPDATE TEMPLATE
        // ============================================
        case 'update':
            if ($method !== 'PUT') { methodNotAllowed(); }

            if (!$isAdmin) { forbidden('Only club admins can update templates'); }

            $id = (int)($_GET['id'] ?? 0);
            if (!$id) { badRequest('id is required'); }

            // Verify template exists and user has access
            $checkStmt = $db->prepare("SELECT club_profile_id FROM email_templates WHERE id = ?");
            $checkStmt->execute([$id]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
            if (!$existing) { notFound('Template not found'); }

            requireClubAccess($auth, $existing['club_profile_id']);

            $data = json_decode(file_get_contents('php://input'), true);

            $updates = [];
            $params = [];

            if (isset($data['name'])) {
                $updates[] = "name = ?";
                $params[] = $data['name'];
            }
            if (isset($data['subject'])) {
                $updates[] = "subject = ?";
                $params[] = $data['subject'];
            }
            if (isset($data['design_json'])) {
                $updates[] = "design_json = ?::jsonb";
                $params[] = json_encode($data['design_json']);
            }
            if (isset($data['html_output'])) {
                $updates[] = "html_output = ?";
                $params[] = $data['html_output'];
            }
            if (isset($data['category'])) {
                $updates[] = "category = ?";
                $params[] = $data['category'];
            }
            if (isset($data['is_active'])) {
                $updates[] = "is_active = ?";
                $params[] = $data['is_active'] ? 'true' : 'false';
            }
            if (isset($data['scope'])) {
                $updates[] = "scope = ?";
                $params[] = $data['scope'];
            }
            if (isset($data['team_visibility'])) {
                $updates[] = "team_visibility = ?::jsonb";
                $params[] = json_encode($data['team_visibility']);
            }

            $updates[] = "updated_by = ?";
            $params[] = $userId;
            $params[] = $id;

            $sql = "UPDATE email_templates SET " . implode(', ', $updates) . " WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            echo json_encode(['success' => true, 'message' => 'Template updated']);
            break;

        // ============================================
        // DELETE TEMPLATE
        // ============================================
        case 'delete':
            if ($method !== 'DELETE') { methodNotAllowed(); }

            if (!$isAdmin) { forbidden('Only club admins can delete templates'); }

            $id = (int)($_GET['id'] ?? 0);
            if (!$id) { badRequest('id is required'); }

            $checkStmt = $db->prepare("SELECT club_profile_id FROM email_templates WHERE id = ?");
            $checkStmt->execute([$id]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
            if (!$existing) { notFound('Template not found'); }

            requireClubAccess($auth, $existing['club_profile_id']);

            $stmt = $db->prepare("DELETE FROM email_templates WHERE id = ?");
            $stmt->execute([$id]);

            echo json_encode(['success' => true, 'message' => 'Template deleted']);
            break;

        // ============================================
        // DUPLICATE TEMPLATE
        // ============================================
        case 'duplicate':
            if ($method !== 'POST') { methodNotAllowed(); }

            if (!$isAdmin) { forbidden('Only club admins can duplicate templates'); }

            $data = json_decode(file_get_contents('php://input'), true);
            $sourceId = (int)($data['id'] ?? 0);
            if (!$sourceId) { badRequest('id is required'); }

            $stmt = $db->prepare("SELECT * FROM email_templates WHERE id = ?");
            $stmt->execute([$sourceId]);
            $source = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$source) { notFound('Source template not found'); }

            requireClubAccess($auth, $source['club_profile_id']);

            $sql = "INSERT INTO email_templates
                        (club_profile_id, name, subject, design_json, html_output,
                         category, is_active, scope, team_visibility,
                         cloned_from, created_by, updated_by)
                    VALUES (?, ?, ?, ?::jsonb, ?, ?, true, ?, ?::jsonb, ?, ?, ?)
                    RETURNING id";
            $stmt = $db->prepare($sql);
            $stmt->execute([
                $source['club_profile_id'],
                $source['name'] . ' (Copy)',
                $source['subject'],
                $source['design_json'], // already JSON string from DB
                $source['html_output'],
                $source['category'],
                $source['scope'],
                $source['team_visibility'], // already JSON string from DB
                $sourceId,
                $userId,
                $userId,
            ]);
            $newId = $stmt->fetchColumn();

            http_response_code(201);
            echo json_encode(['success' => true, 'id' => $newId, 'message' => 'Template duplicated']);
            break;

        // ============================================
        // MERGE FIELDS
        // ============================================
        case 'merge-fields':
            if ($method !== 'GET') { methodNotAllowed(); }

            // Return available merge tags for the template editor
            $mergeFields = [
                ['key' => 'athlete_first_name', 'name' => 'Athlete First Name', 'value' => '{{athlete_first_name}}', 'sample' => 'John'],
                ['key' => 'athlete_last_name', 'name' => 'Athlete Last Name', 'value' => '{{athlete_last_name}}', 'sample' => 'Doe'],
                ['key' => 'parent_first_name', 'name' => 'Parent First Name', 'value' => '{{parent_first_name}}', 'sample' => 'Jane'],
                ['key' => 'parent_last_name', 'name' => 'Parent Last Name', 'value' => '{{parent_last_name}}', 'sample' => 'Doe'],
                ['key' => 'team_name', 'name' => 'Team Name', 'value' => '{{team_name}}', 'sample' => 'Eagles U14'],
                ['key' => 'club_name', 'name' => 'Club Name', 'value' => '{{club_name}}', 'sample' => 'Metro FC'],
                ['key' => 'event_name', 'name' => 'Event Name', 'value' => '{{event_name}}', 'sample' => 'Team Dinner'],
                ['key' => 'event_date', 'name' => 'Event Date', 'value' => '{{event_date}}', 'sample' => 'March 25, 2026'],
                ['key' => 'event_time', 'name' => 'Event Time', 'value' => '{{event_time}}', 'sample' => '6:00 PM'],
                ['key' => 'event_location', 'name' => 'Event Location', 'value' => '{{event_location}}', 'sample' => '123 Main St'],
                ['key' => 'unsubscribe_url', 'name' => 'Unsubscribe URL', 'value' => '{{unsubscribe_url}}', 'sample' => '#'],
            ];

            echo json_encode(['success' => true, 'merge_fields' => $mergeFields]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action: ' . $action]);
            break;
    }
} catch (PDOException $e) {
    error_log("Email Templates Gateway DB Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error occurred']);
} catch (Exception $e) {
    error_log("Email Templates Gateway Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'An error occurred']);
}

// ============================================
// Helper Functions
// ============================================

function methodNotAllowed() {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

function badRequest($message) {
    http_response_code(400);
    echo json_encode(['error' => $message]);
    exit();
}

function notFound($message) {
    http_response_code(404);
    echo json_encode(['error' => $message]);
    exit();
}

function forbidden($message = 'You do not have permission to perform this action') {
    http_response_code(403);
    echo json_encode(['error' => $message]);
    exit();
}

function requireClubAccess($auth, $clubId) {
    if ($auth->isSuperAdmin()) return;
    if (!$auth->canAccessClub($clubId)) {
        forbidden();
    }
}
