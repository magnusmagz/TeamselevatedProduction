<?php
/**
 * "I am here today." One POST per signed-in user per local day, from
 * useUsagePing in AppContent (staff app, parent portal and referee page are
 * one call). Writes the user's daily-active rows — one per (club, role) they
 * hold — through lib/usage_activity.php, the same rule that sizes the
 * denominator in api/usage-metrics.php.
 *
 * Idempotent: a second ping on the same day only moves last_seen_at. The
 * client dedupes in localStorage; the server does not rate-limit because a
 * duplicate costs one upsert and reveals nothing.
 *
 * Body: { local_date?: 'YYYY-MM-DD', surface?: 'staff'|'parent'|'referee' }.
 * The local date is trusted only within a day of the server's UTC date.
 *
 * Answers 202 with { recorded: false, reason: 'table_missing' } until
 * migration 103 is applied — never a 500 on every page load.
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();

require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/usage_activity.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$auth = AuthMiddleware::requireAuth();
$userId = (int)$auth->getUserId();

$body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
$surface = in_array($body['surface'] ?? '', TE_USAGE_SURFACES, true) ? $body['surface'] : 'staff';
if ($surface === 'backfill') {
    $surface = 'staff';
}
$date = te_usage_resolve_activity_date($body['local_date'] ?? null);

try {
    $pdo = Database::getInstance()->getConnection();
    if (!te_usage_table_present($pdo)) {
        http_response_code(202);
        echo json_encode(['recorded' => false, 'reason' => 'table_missing']);
        exit;
    }
    $buckets = te_usage_record($pdo, $userId, $date, $surface);
    echo json_encode([
        'recorded' => count($buckets) > 0,
        'activity_date' => $date,
        'buckets' => count($buckets),
    ]);
} catch (Throwable $e) {
    // A metrics write must never break the page that made it.
    error_log('usage-ping: ' . $e->getMessage());
    http_response_code(202);
    echo json_encode(['recorded' => false, 'reason' => 'error']);
}
