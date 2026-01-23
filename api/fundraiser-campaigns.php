<?php
/**
 * Fundraiser Campaigns API
 * CRUD operations for goal-based fundraiser campaigns
 */

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
$action = $_GET['action'] ?? 'list';

/**
 * Generate a URL-friendly slug from title
 */
function generateSlug($title, $db, $clubId, $excludeId = null) {
    $slug = strtolower(trim($title));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    $slug = substr($slug, 0, 100);

    // Check for uniqueness
    $baseSlug = $slug;
    $counter = 1;

    while (true) {
        $sql = "SELECT id FROM fundraiser_campaigns WHERE club_id = ? AND slug = ? AND deleted_at IS NULL";
        $params = [$clubId, $slug];

        if ($excludeId) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        if (!$stmt->fetch()) {
            break;
        }

        $slug = $baseSlug . '-' . $counter;
        $counter++;
    }

    return $slug;
}

try {
    switch ($action) {
        // ========================================
        // LIST: Get all campaigns for a club
        // ========================================
        case 'list':
            if ($method !== 'GET') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit();
            }

            $clubId = $_GET['club_id'] ?? null;
            if (!$clubId) {
                http_response_code(400);
                echo json_encode(['error' => 'club_id parameter is required']);
                exit();
            }

            $status = $_GET['status'] ?? null;
            $includeEnded = isset($_GET['include_ended']) && $_GET['include_ended'] === 'true';

            $sql = "
                SELECT fc.*, u.name as created_by_name
                FROM fundraiser_campaigns fc
                LEFT JOIN users u ON fc.created_by = u.id
                WHERE fc.club_id = ? AND fc.deleted_at IS NULL
            ";
            $params = [$clubId];

            if ($status) {
                $sql .= " AND fc.status = ?";
                $params[] = $status;
            } elseif (!$includeEnded) {
                $sql .= " AND fc.status IN ('draft', 'active')";
            }

            $sql .= " ORDER BY
                CASE fc.status
                    WHEN 'active' THEN 1
                    WHEN 'draft' THEN 2
                    WHEN 'ended' THEN 3
                    WHEN 'cancelled' THEN 4
                END,
                fc.end_date ASC";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Add computed fields
            foreach ($campaigns as &$campaign) {
                $campaign['progress_percent'] = $campaign['goal_amount'] > 0
                    ? round(($campaign['amount_raised'] / $campaign['goal_amount']) * 100, 1)
                    : 0;
                $campaign['days_remaining'] = max(0, (strtotime($campaign['end_date']) - time()) / 86400);
                $campaign['is_active'] = $campaign['status'] === 'active' && strtotime($campaign['end_date']) >= time();
            }

            echo json_encode($campaigns);
            break;

        // ========================================
        // GET: Get single campaign by ID or slug
        // ========================================
        case 'get':
            if ($method !== 'GET') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit();
            }

            $id = $_GET['id'] ?? null;
            $slug = $_GET['slug'] ?? null;
            $clubSlug = $_GET['club_slug'] ?? null;

            if (!$id && !($slug && $clubSlug)) {
                http_response_code(400);
                echo json_encode(['error' => 'id or (slug and club_slug) parameters required']);
                exit();
            }

            if ($id) {
                $stmt = $db->prepare("
                    SELECT fc.*, cp.slug as club_slug, cp.club_name, u.name as created_by_name
                    FROM fundraiser_campaigns fc
                    JOIN club_profile cp ON fc.club_id = cp.id
                    LEFT JOIN users u ON fc.created_by = u.id
                    WHERE fc.id = ? AND fc.deleted_at IS NULL
                ");
                $stmt->execute([$id]);
            } else {
                $stmt = $db->prepare("
                    SELECT fc.*, cp.slug as club_slug, cp.club_name, u.name as created_by_name
                    FROM fundraiser_campaigns fc
                    JOIN club_profile cp ON fc.club_id = cp.id
                    LEFT JOIN users u ON fc.created_by = u.id
                    WHERE fc.slug = ? AND cp.slug = ? AND fc.deleted_at IS NULL
                ");
                $stmt->execute([$slug, $clubSlug]);
            }

            $campaign = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$campaign) {
                http_response_code(404);
                echo json_encode(['error' => 'Campaign not found']);
                exit();
            }

            // Add computed fields
            $campaign['progress_percent'] = $campaign['goal_amount'] > 0
                ? round(($campaign['amount_raised'] / $campaign['goal_amount']) * 100, 1)
                : 0;
            $campaign['days_remaining'] = max(0, ceil((strtotime($campaign['end_date']) - time()) / 86400));
            $campaign['is_active'] = $campaign['status'] === 'active' && strtotime($campaign['end_date']) >= time();

            // Get recent updates
            $stmt = $db->prepare("
                SELECT cu.*, u.name as author_name
                FROM campaign_updates cu
                LEFT JOIN users u ON cu.created_by = u.id
                WHERE cu.campaign_id = ? AND cu.deleted_at IS NULL
                ORDER BY cu.created_at DESC
                LIMIT 10
            ");
            $stmt->execute([$campaign['id']]);
            $campaign['updates'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode($campaign);
            break;

        // ========================================
        // CREATE: Create new campaign
        // ========================================
        case 'create':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit();
            }

            $data = json_decode(file_get_contents("php://input"), true);

            // Validate required fields
            $required = ['club_id', 'title', 'goal_amount', 'start_date', 'end_date'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    http_response_code(400);
                    echo json_encode(['error' => "$field is required"]);
                    exit();
                }
            }

            // Generate slug if not provided
            $slug = !empty($data['slug'])
                ? generateSlug($data['slug'], $db, $data['club_id'])
                : generateSlug($data['title'], $db, $data['club_id']);

            // Validate dates
            if (strtotime($data['end_date']) < strtotime($data['start_date'])) {
                http_response_code(400);
                echo json_encode(['error' => 'End date must be after start date']);
                exit();
            }

            $stmt = $db->prepare("
                INSERT INTO fundraiser_campaigns (
                    club_id, title, slug, description, image_url, image_data, image_filename,
                    goal_amount, start_date, end_date,
                    show_donor_names, show_donor_amounts, show_progress, allow_comments, allow_exceed_goal,
                    status, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $data['club_id'],
                $data['title'],
                $slug,
                $data['description'] ?? null,
                $data['image_url'] ?? null,
                $data['image_data'] ?? null,
                $data['image_filename'] ?? null,
                $data['goal_amount'],
                $data['start_date'],
                $data['end_date'],
                $data['show_donor_names'] ?? true,
                $data['show_donor_amounts'] ?? true,
                $data['show_progress'] ?? true,
                $data['allow_comments'] ?? true,
                $data['allow_exceed_goal'] ?? true,
                $data['status'] ?? 'draft',
                $data['created_by'] ?? null
            ]);

            $campaignId = $db->lastInsertId();

            echo json_encode([
                'success' => true,
                'id' => $campaignId,
                'slug' => $slug,
                'message' => 'Campaign created successfully'
            ]);
            break;

        // ========================================
        // UPDATE: Update campaign details
        // ========================================
        case 'update':
            if ($method !== 'PUT' && $method !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit();
            }

            $data = json_decode(file_get_contents("php://input"), true);

            if (empty($data['id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'id is required']);
                exit();
            }

            // Get current campaign
            $stmt = $db->prepare("SELECT * FROM fundraiser_campaigns WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$data['id']]);
            $campaign = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$campaign) {
                http_response_code(404);
                echo json_encode(['error' => 'Campaign not found']);
                exit();
            }

            // Generate new slug if title changed
            $slug = $campaign['slug'];
            if (!empty($data['title']) && $data['title'] !== $campaign['title']) {
                $slug = generateSlug($data['title'], $db, $campaign['club_id'], $data['id']);
            }

            // Build dynamic update query based on provided fields
            $updateFields = [];
            $updateValues = [];

            if (isset($data['title'])) {
                $updateFields[] = 'title = ?';
                $updateValues[] = $data['title'];
            }
            $updateFields[] = 'slug = ?';
            $updateValues[] = $slug;

            if (isset($data['description'])) {
                $updateFields[] = 'description = ?';
                $updateValues[] = $data['description'];
            }
            if (array_key_exists('image_data', $data)) {
                $updateFields[] = 'image_data = ?';
                $updateValues[] = $data['image_data'];
            }
            if (array_key_exists('image_filename', $data)) {
                $updateFields[] = 'image_filename = ?';
                $updateValues[] = $data['image_filename'];
            }
            if (isset($data['goal_amount'])) {
                $updateFields[] = 'goal_amount = ?';
                $updateValues[] = $data['goal_amount'];
            }
            if (isset($data['start_date'])) {
                $updateFields[] = 'start_date = ?';
                $updateValues[] = $data['start_date'];
            }
            if (isset($data['end_date'])) {
                $updateFields[] = 'end_date = ?';
                $updateValues[] = $data['end_date'];
            }
            if (isset($data['show_donor_names'])) {
                $updateFields[] = 'show_donor_names = ?';
                $updateValues[] = $data['show_donor_names'] ? 'true' : 'false';
            }
            if (isset($data['show_donor_amounts'])) {
                $updateFields[] = 'show_donor_amounts = ?';
                $updateValues[] = $data['show_donor_amounts'] ? 'true' : 'false';
            }
            if (isset($data['show_progress'])) {
                $updateFields[] = 'show_progress = ?';
                $updateValues[] = $data['show_progress'] ? 'true' : 'false';
            }
            if (isset($data['allow_comments'])) {
                $updateFields[] = 'allow_comments = ?';
                $updateValues[] = $data['allow_comments'] ? 'true' : 'false';
            }
            if (isset($data['allow_exceed_goal'])) {
                $updateFields[] = 'allow_exceed_goal = ?';
                $updateValues[] = $data['allow_exceed_goal'] ? 'true' : 'false';
            }
            if (isset($data['status'])) {
                $updateFields[] = 'status = ?';
                $updateValues[] = $data['status'];
            }

            $updateValues[] = $data['id'];

            $stmt = $db->prepare("
                UPDATE fundraiser_campaigns SET
                    " . implode(', ', $updateFields) . "
                WHERE id = ? AND deleted_at IS NULL
            ");

            $stmt->execute($updateValues);

            echo json_encode([
                'success' => true,
                'slug' => $slug,
                'message' => 'Campaign updated successfully'
            ]);
            break;

        // ========================================
        // END: Manually end a campaign
        // ========================================
        case 'end':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit();
            }

            $data = json_decode(file_get_contents("php://input"), true);

            if (empty($data['id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'id is required']);
                exit();
            }

            $stmt = $db->prepare("
                UPDATE fundraiser_campaigns
                SET status = 'ended', end_date = CURRENT_DATE
                WHERE id = ? AND deleted_at IS NULL AND status = 'active'
            ");
            $stmt->execute([$data['id']]);

            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Campaign not found or not active']);
                exit();
            }

            echo json_encode([
                'success' => true,
                'message' => 'Campaign ended successfully'
            ]);
            break;

        // ========================================
        // STATS: Get campaign stats for dashboard
        // ========================================
        case 'stats':
            if ($method !== 'GET') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit();
            }

            $clubId = $_GET['club_id'] ?? null;
            if (!$clubId) {
                http_response_code(400);
                echo json_encode(['error' => 'club_id parameter is required']);
                exit();
            }

            // Overall stats
            $stmt = $db->prepare("
                SELECT
                    COUNT(*) as total_campaigns,
                    COUNT(CASE WHEN status = 'active' THEN 1 END) as active_campaigns,
                    COUNT(CASE WHEN status = 'ended' THEN 1 END) as ended_campaigns,
                    COALESCE(SUM(amount_raised), 0) as total_raised,
                    COALESCE(SUM(donor_count), 0) as total_donors
                FROM fundraiser_campaigns
                WHERE club_id = ? AND deleted_at IS NULL
            ");
            $stmt->execute([$clubId]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);

            // Recent donations across all campaigns
            $stmt = $db->prepare("
                SELECT cd.*, fc.title as campaign_title, fc.slug as campaign_slug
                FROM campaign_donations cd
                JOIN fundraiser_campaigns fc ON cd.campaign_id = fc.id
                WHERE fc.club_id = ? AND cd.status = 'succeeded' AND cd.deleted_at IS NULL
                ORDER BY cd.created_at DESC
                LIMIT 10
            ");
            $stmt->execute([$clubId]);
            $stats['recent_donations'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode($stats);
            break;

        // ========================================
        // DELETE: Soft delete a campaign
        // ========================================
        case 'delete':
            if ($method !== 'DELETE' && $method !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit();
            }

            $id = $_GET['id'] ?? null;
            $data = json_decode(file_get_contents("php://input"), true);
            $id = $id ?? ($data['id'] ?? null);

            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'id is required']);
                exit();
            }

            $stmt = $db->prepare("
                UPDATE fundraiser_campaigns
                SET deleted_at = CURRENT_TIMESTAMP, deleted_by = ?
                WHERE id = ? AND deleted_at IS NULL
            ");
            $stmt->execute([
                $data['deleted_by'] ?? null,
                $id
            ]);

            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Campaign not found']);
                exit();
            }

            echo json_encode([
                'success' => true,
                'message' => 'Campaign deleted successfully'
            ]);
            break;

        // ========================================
        // POST-UPDATE: Post an update to campaign
        // ========================================
        case 'post-update':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit();
            }

            $data = json_decode(file_get_contents("php://input"), true);

            if (empty($data['campaign_id']) || empty($data['title']) || empty($data['content'])) {
                http_response_code(400);
                echo json_encode(['error' => 'campaign_id, title, and content are required']);
                exit();
            }

            $stmt = $db->prepare("
                INSERT INTO campaign_updates (campaign_id, title, content, created_by)
                VALUES (?, ?, ?, ?)
            ");

            $stmt->execute([
                $data['campaign_id'],
                $data['title'],
                $data['content'],
                $data['created_by'] ?? null
            ]);

            $updateId = $db->lastInsertId();

            echo json_encode([
                'success' => true,
                'id' => $updateId,
                'message' => 'Update posted successfully'
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode([
                'error' => 'Invalid action',
                'available_actions' => ['list', 'get', 'create', 'update', 'end', 'stats', 'delete', 'post-update']
            ]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
