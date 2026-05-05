<?php

require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/RedisQueue.php';
require_once __DIR__ . '/../services/CalendarSyncService.php';

try {
    $db = Database::getInstance();
    $connection = $db->getConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;

try {
    $auth = AuthMiddleware::requireAuth();

    $syncService = new CalendarSyncService($connection);

    switch ($action) {
        case 'list':
            handleList($auth, $connection);
            break;
        case 'get':
            handleGet($auth, $connection);
            break;
        case 'create':
            handleCreate($auth, $connection);
            break;
        case 'upload':
            handleUpload($auth, $connection, $syncService);
            break;
        case 'update':
            handleUpdate($auth, $connection);
            break;
        case 'delete':
            handleDelete($auth, $connection);
            break;
        case 'sync':
            handleSync($auth, $connection);
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
    }
} catch (Exception $e) {
    error_log("[CalendarSubscriptions] Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

// ──────────────────────────────────────────────
// Handlers
// ──────────────────────────────────────────────

function handleList($auth, PDO $db): void
{
    $clubId = $_GET['club_id'] ?? null;
    $teamId = $_GET['team_id'] ?? null;

    if (!$clubId) {
        http_response_code(400);
        echo json_encode(['error' => 'club_id is required']);
        return;
    }

    if (!$auth->canAccessClub($clubId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        return;
    }

    $sql = "SELECT cs.*, t.name as team_name
            FROM calendar_subscriptions cs
            LEFT JOIN teams t ON cs.team_id = t.id
            WHERE cs.club_id = ?";
    $params = [$clubId];

    if ($teamId) {
        $sql .= " AND cs.team_id = ?";
        $params[] = $teamId;
    } elseif (!$auth->hasRole('club_admin', $clubId, 'club')) {
        $sql .= " AND (cs.team_id IN (
            SELECT tm.team_id FROM team_members tm WHERE tm.user_id = ?
        ) OR cs.team_id IS NULL)";
        $params[] = $auth->getUserId();
    }

    $sql .= " ORDER BY cs.created_at DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    echo json_encode(['subscriptions' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function handleGet($auth, PDO $db): void
{
    $id = $_GET['id'] ?? null;
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'id is required']);
        return;
    }

    $stmt = $db->prepare("
        SELECT cs.*, t.name as team_name, u.first_name || ' ' || u.last_name as created_by_name
        FROM calendar_subscriptions cs
        LEFT JOIN teams t ON cs.team_id = t.id
        LEFT JOIN users u ON cs.created_by = u.id
        WHERE cs.id = ?
    ");
    $stmt->execute([$id]);
    $sub = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sub) {
        http_response_code(404);
        echo json_encode(['error' => 'Subscription not found']);
        return;
    }

    if (!$auth->canAccessClub($sub['club_id'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        return;
    }

    if (!canManageSubscription($auth, $db, $sub)) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied to this subscription']);
        return;
    }

    $eventStmt = $db->prepare("
        SELECT id, name, event_date, start_time, end_time, status, external_uid
        FROM calendar_events
        WHERE subscription_id = ?
        ORDER BY event_date DESC
        LIMIT 50
    ");
    $eventStmt->execute([$id]);

    echo json_encode([
        'subscription' => $sub,
        'events' => $eventStmt->fetchAll(PDO::FETCH_ASSOC),
    ]);
}

function handleCreate($auth, PDO $db): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }

    $data = json_decode(file_get_contents('php://input'), true);

    $clubId = $data['club_id'] ?? null;
    $name = trim($data['name'] ?? '');
    $feedUrl = trim($data['feed_url'] ?? '');
    $teamId = $data['team_id'] ?? null;
    $syncInterval = (int)($data['sync_interval_minutes'] ?? 60);

    if (!$clubId || !$name || !$feedUrl) {
        http_response_code(400);
        echo json_encode(['error' => 'club_id, name, and feed_url are required']);
        return;
    }

    if (!$auth->canAccessClub($clubId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        return;
    }

    if ($teamId && !canAccessTeam($auth, $db, $teamId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied to this team']);
        return;
    }

    $feedUrl = preg_replace('/^webcal:\/\//', 'https://', $feedUrl);

    $syncInterval = max(15, min(1440, $syncInterval));

    $stmt = $db->prepare("
        INSERT INTO calendar_subscriptions (club_id, team_id, name, feed_url, source_type, sync_interval_minutes, created_by)
        VALUES (?, ?, ?, ?, 'feed', ?, ?)
        RETURNING id
    ");
    $stmt->execute([$clubId, $teamId, $name, $feedUrl, $syncInterval, $auth->getUserId()]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'message' => 'Subscription created',
        'subscription_id' => $row['id'],
    ]);
}

function handleUpload($auth, PDO $db, CalendarSyncService $syncService): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }

    $clubId = $_POST['club_id'] ?? null;
    $name = trim($_POST['name'] ?? '');
    $teamId = $_POST['team_id'] ?? null;

    if (!$clubId || !$name) {
        http_response_code(400);
        echo json_encode(['error' => 'club_id and name are required']);
        return;
    }

    if (!isset($_FILES['ics_file']) || $_FILES['ics_file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => 'No .ics file uploaded or upload error']);
        return;
    }

    if (!$auth->canAccessClub($clubId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        return;
    }

    if ($teamId && !canAccessTeam($auth, $db, $teamId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied to this team']);
        return;
    }

    $icsContent = file_get_contents($_FILES['ics_file']['tmp_name']);

    if (stripos($icsContent, 'BEGIN:VCALENDAR') === false) {
        http_response_code(400);
        echo json_encode(['error' => 'File does not appear to be a valid .ics file']);
        return;
    }

    $stmt = $db->prepare("
        INSERT INTO calendar_subscriptions (club_id, team_id, name, source_type, is_active, created_by)
        VALUES (?, ?, ?, 'file_upload', false, ?)
        RETURNING id
    ");
    $stmt->execute([$clubId, $teamId, $name, $auth->getUserId()]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $subscriptionId = $row['id'];

    $result = $syncService->importFromContent($icsContent, $subscriptionId, (int)$clubId, $teamId ? (int)$teamId : null);

    echo json_encode([
        'message' => 'File imported successfully',
        'subscription_id' => $subscriptionId,
        'result' => $result,
    ]);
}

function handleUpdate($auth, PDO $db): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    $id = $_GET['id'] ?? $data['id'] ?? null;

    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'id is required']);
        return;
    }

    $sub = getSubscription($db, $id);
    if (!$sub) {
        http_response_code(404);
        echo json_encode(['error' => 'Subscription not found']);
        return;
    }

    if (!$auth->canAccessClub($sub['club_id'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        return;
    }

    if (!canManageSubscription($auth, $db, $sub)) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied to this subscription']);
        return;
    }

    $updates = [];
    $params = [];

    if (isset($data['name'])) {
        $updates[] = "name = ?";
        $params[] = trim($data['name']);
    }
    if (isset($data['feed_url']) && $sub['source_type'] === 'feed') {
        $url = preg_replace('/^webcal:\/\//', 'https://', trim($data['feed_url']));
        $updates[] = "feed_url = ?";
        $params[] = $url;
    }
    if (isset($data['sync_interval_minutes'])) {
        $updates[] = "sync_interval_minutes = ?";
        $params[] = max(15, min(1440, (int)$data['sync_interval_minutes']));
    }
    if (isset($data['is_active'])) {
        $updates[] = "is_active = ?";
        $params[] = (bool)$data['is_active'];
    }

    if (empty($updates)) {
        http_response_code(400);
        echo json_encode(['error' => 'No fields to update']);
        return;
    }

    $updates[] = "updated_at = NOW()";
    $params[] = $id;

    $stmt = $db->prepare("UPDATE calendar_subscriptions SET " . implode(', ', $updates) . " WHERE id = ?");
    $stmt->execute($params);

    echo json_encode(['message' => 'Subscription updated']);
}

function handleDelete($auth, PDO $db): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }

    $id = $_GET['id'] ?? null;
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'id is required']);
        return;
    }

    $sub = getSubscription($db, $id);
    if (!$sub) {
        http_response_code(404);
        echo json_encode(['error' => 'Subscription not found']);
        return;
    }

    if (!$auth->canAccessClub($sub['club_id'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        return;
    }

    if (!canManageSubscription($auth, $db, $sub)) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied to this subscription']);
        return;
    }

    $removeEvents = filter_var($_GET['remove_events'] ?? 'false', FILTER_VALIDATE_BOOLEAN);

    $db->beginTransaction();
    try {
        if ($removeEvents) {
            $stmt = $db->prepare("DELETE FROM calendar_event_teams WHERE event_id IN (SELECT id FROM calendar_events WHERE subscription_id = ?)");
            $stmt->execute([$id]);

            $stmt = $db->prepare("DELETE FROM calendar_events WHERE subscription_id = ?");
            $stmt->execute([$id]);
        }

        $stmt = $db->prepare("DELETE FROM calendar_subscriptions WHERE id = ?");
        $stmt->execute([$id]);

        $db->commit();

        echo json_encode(['message' => 'Subscription deleted', 'events_removed' => $removeEvents]);
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

function handleSync($auth, PDO $db): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    $id = $_GET['id'] ?? $data['id'] ?? null;

    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'id is required']);
        return;
    }

    $sub = getSubscription($db, $id);
    if (!$sub) {
        http_response_code(404);
        echo json_encode(['error' => 'Subscription not found']);
        return;
    }

    if (!$auth->canAccessClub($sub['club_id'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        return;
    }

    if (!canManageSubscription($auth, $db, $sub)) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied to this subscription']);
        return;
    }

    if ($sub['source_type'] !== 'feed') {
        http_response_code(400);
        echo json_encode(['error' => 'Cannot sync a file upload subscription']);
        return;
    }

    $queue = RedisQueue::getInstance();
    $queue->push('calendar_sync_queue', [
        'id'              => uniqid('calsync_'),
        'subscription_id' => (int)$id,
    ]);

    echo json_encode(['message' => 'Sync job queued']);
}

// ──────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────

function getSubscription(PDO $db, int $id): ?array
{
    $stmt = $db->prepare("SELECT * FROM calendar_subscriptions WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function canManageSubscription($auth, PDO $db, array $sub): bool
{
    if ($auth->hasRole('club_admin', $sub['club_id'], 'club')) {
        return true;
    }

    if ($sub['team_id'] === null) {
        return false;
    }

    return canAccessTeam($auth, $db, $sub['team_id']);
}

function canAccessTeam($auth, PDO $db, int $teamId): bool
{
    if ($auth->hasRole('club_admin', null, null)) {
        return true;
    }

    $stmt = $db->prepare("SELECT 1 FROM team_members WHERE team_id = ? AND user_id = ?");
    $stmt->execute([$teamId, $auth->getUserId()]);
    return (bool)$stmt->fetch();
}
