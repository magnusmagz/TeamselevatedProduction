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

            $channel = $_GET['channel'] ?? null;

            $sql = "SELECT id, club_profile_id, name, subject, body_text, channel,
                           category, scope, team_visibility, is_active, cloned_from,
                           created_by, updated_by, created_at, updated_at
                    FROM email_templates
                    WHERE (club_profile_id = ? OR scope = 'platform')
                      AND is_active = true";
            $params = [$clubProfileId];

            if ($channel) {
                $sql .= " AND channel = ?";
                $params[] = $channel;
            }

            $sql .= " ORDER BY scope DESC, updated_at DESC";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
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

            // Platform templates are accessible to all; club templates require access
            if ($template['scope'] !== 'platform') {
                requireClubAccess($auth, $template['club_profile_id']);
            }

            $template['team_visibility'] = json_decode($template['team_visibility'], true) ?: [];
            $template['design_json'] = json_decode($template['design_json'] ?? '{}', true);
            $template['is_active'] = (bool)$template['is_active'];

            echo json_encode(['success' => true, 'template' => $template]);
            break;

        // ============================================
        // CLONE PLATFORM TEMPLATE FOR CLUB (clone-on-edit)
        // ============================================
        case 'clone-for-club':
            if ($method !== 'POST') { methodNotAllowed(); }

            $data = json_decode(file_get_contents('php://input'), true);
            $templateId = (int)($data['template_id'] ?? 0);
            $clubProfileId = (int)($data['club_profile_id'] ?? 0);

            if (!$templateId || !$clubProfileId) { badRequest('template_id and club_profile_id are required'); }
            requireClubAccess($auth, $clubProfileId);

            // Check if already cloned
            $stmt = $db->prepare("SELECT id FROM email_templates WHERE cloned_from = ? AND club_profile_id = ? AND is_active = true LIMIT 1");
            $stmt->execute([$templateId, $clubProfileId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                // Return existing clone
                echo json_encode(['success' => true, 'clone_id' => (int)$existing['id'], 'already_cloned' => true]);
                break;
            }

            // Fetch original
            $stmt = $db->prepare("SELECT * FROM email_templates WHERE id = ? AND scope = 'platform'");
            $stmt->execute([$templateId]);
            $original = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$original) { notFound('Platform template not found'); }

            // Clone it
            $stmt = $db->prepare("
                INSERT INTO email_templates (club_profile_id, name, subject, design_json, html_output, category, is_active, scope, team_visibility, channel, body_text, cloned_from, created_by, updated_by)
                VALUES (?, ?, ?, ?, ?, ?, true, 'club', '[]'::jsonb, ?, ?, ?, ?, ?)
                RETURNING id
            ");
            $stmt->execute([
                $clubProfileId,
                $original['name'],
                $original['subject'],
                $original['design_json'],
                $original['html_output'],
                $original['category'],
                $original['channel'] ?? 'email',
                $original['body_text'],
                $templateId,
                $userId,
                $userId
            ]);
            $cloneId = (int)$stmt->fetchColumn();

            echo json_encode(['success' => true, 'clone_id' => $cloneId, 'already_cloned' => false]);
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
                         channel, body_text,
                         created_by, updated_by)
                    VALUES (?, ?, ?, ?::jsonb, ?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?)
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
                $data['channel'] ?? 'email',
                $data['body_text'] ?? null,
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
            if (isset($data['channel'])) {
                $updates[] = "channel = ?";
                $params[] = $data['channel'];
            }
            if (isset($data['body_text'])) {
                $updates[] = "body_text = ?";
                $params[] = $data['body_text'];
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

            // Soft delete: flip is_active so the row is preserved (the list query filters is_active = true)
            $stmt = $db->prepare("UPDATE email_templates SET is_active = false, updated_by = ? WHERE id = ?");
            $stmt->execute([$userId, $id]);

            echo json_encode(['success' => true, 'message' => 'Template deleted']);
            break;

        // ============================================
        // DUPLICATE TEMPLATE
        // ============================================
        case 'duplicate':
            if ($method !== 'POST') { methodNotAllowed(); }

            // Anyone with access to the template's club may duplicate it (gated by
            // requireClubAccess on the source's club below). The copy is always created
            // as a private 'personal' template owned by the duplicator, so a coach can
            // duplicate a club template into their own personal copy (COACH-22).

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
                         channel, body_text,
                         cloned_from, created_by, updated_by)
                    VALUES (?, ?, ?, ?::jsonb, ?, ?, true, ?, ?::jsonb, ?, ?, ?, ?, ?)
                    RETURNING id";
            $stmt = $db->prepare($sql);
            $stmt->execute([
                $source['club_profile_id'],
                $source['name'] . ' (Copy)',
                $source['subject'],
                $source['design_json'], // already JSON string from DB
                $source['html_output'],
                $source['category'],
                'personal',     // duplicate is always a private personal copy (COACH-22)
                '[]',           // personal copy has no team visibility
                $source['channel'] ?? 'email',
                $source['body_text'],
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
