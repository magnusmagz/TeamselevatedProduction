<?php
/**
 * Product usage metrics — super admin only.
 *
 *   GET ?action=summary&as_of=YYYY-MM-DD   DAU / WAU / MAU per club × role, with
 *                                          holders (the denominator) and a per-club
 *                                          any-role line; as_of defaults to today (UTC)
 *   GET ?action=trend&weeks=12&as_of=...   weekly active users per club × role for
 *                                          the last N 7-day windows
 *
 * Read side of lib/usage_activity.php. This is the platform's own view of the
 * platform: it is not a club-facing report, and it deliberately does not live
 * on analytics-gateway.php (that page is the club's email marketing analytics).
 * Individual users are never returned here — counts only.
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
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'GET only']);
    exit;
}

$auth = AuthMiddleware::requireAuth();
if (!$auth->isSuperAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Super admin only']);
    exit;
}

$action = $_GET['action'] ?? 'summary';
$asOf = te_usage_resolve_as_of($_GET['as_of'] ?? null);

try {
    $pdo = Database::getInstance()->getConnection();
    if (!te_usage_table_present($pdo)) {
        http_response_code(503);
        echo json_encode(['available' => false, 'error' => 'Usage tracking is not set up yet (migration 103 is not applied).']);
        exit;
    }

    $clubNames = [];
    foreach ($pdo->query("SELECT id, name FROM club_profile")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $clubNames[(int)$c['id']] = $c['name'];
    }
    $clubNames[TE_USAGE_PLATFORM_CLUB_ID] = 'Platform';

    if ($action === 'summary') {
        $summary = te_usage_summary($pdo, $asOf);
        foreach ($summary['rows'] as &$r) {
            $r['club_name'] = $clubNames[$r['club_id']] ?? ('Club ' . $r['club_id']);
        }
        unset($r);
        foreach ($summary['clubs'] as &$c) {
            $c['club_name'] = $clubNames[$c['club_id']] ?? ('Club ' . $c['club_id']);
        }
        unset($c);
        echo json_encode(['available' => true] + $summary);
        exit;
    }

    if ($action === 'trend') {
        $weeks = (int)($_GET['weeks'] ?? 12);
        $trend = te_usage_weekly_trend($pdo, $asOf, $weeks);
        $series = [];
        foreach ($trend['series'] as $key => $values) {
            [$clubId, $role] = explode('|', $key, 2);
            $series[] = [
                'club_id' => (int)$clubId,
                'club_name' => $clubNames[(int)$clubId] ?? ('Club ' . $clubId),
                'role' => $role,
                'values' => array_values($values),
            ];
        }
        echo json_encode(['available' => true, 'as_of' => $asOf, 'weeks' => $trend['weeks'], 'series' => $series]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
} catch (Throwable $e) {
    error_log('usage-metrics: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Could not compute usage metrics']);
}

function te_usage_resolve_as_of(?string $raw): string
{
    $today = gmdate('Y-m-d');
    if ($raw && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) && $raw <= $today) {
        return $raw;
    }
    return $today;
}
