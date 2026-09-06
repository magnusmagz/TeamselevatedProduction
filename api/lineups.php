<?php
/**
 * Lineup builder (CKU R67, slice 8.5). Spec: docs/lineup-builder-spec-2026-09.md.
 *
 * Every decision lives in lib/lineups.php as handlers returning {status, body,
 * audit}, executed by LineupTest; this file is the HTTP shape only.
 *
 * ACTIONS
 *   GET  ?action=get&team_id=N[&event_id=M]   staff: the game's lineup (or the
 *                                             template, flagged) + roster,
 *                                             attendance, presets. A guardian
 *                                             of a player: 403 until the game's
 *                                             lineup is published, then the
 *                                             reduced shape.
 *   GET  ?action=games&team_id=N              staff: the team's games with
 *                                             lineup / published flags
 *   POST ?action=save        {team_id, event_id?, formation, slots, name?, field_size?}
 *                                             full slot replace; event_id absent
 *                                             = the template
 *   POST ?action=copy-from   {team_id, event_id, source: template|last|<event id>}
 *   POST ?action=publish     {team_id, event_id}
 *   POST ?action=unpublish   {team_id, event_id}
 *
 * Migration 096 may not be applied yet when this ships — `main` is shared and
 * deploys are by push — so every action answers 503 with a sentence until it is.
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/AuditLogger.php';
require_once __DIR__ . '/../lib/lineups.php';

try {
    $pdo = Database::getInstance()->getConnection();
} catch (Exception $e) {
    error_log('lineups: DB connection failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$auth = AuthMiddleware::requireAuth();
$userId = (int) $auth->getUserId();

$method = $_SERVER['REQUEST_METHOD'];
$body = [];
if ($method !== 'GET') {
    $raw = file_get_contents('php://input');
    $body = $raw ? (json_decode($raw, true) ?: []) : [];
}
$action = (string) ($_GET['action'] ?? $body['action'] ?? ($method === 'GET' ? 'get' : 'save'));

/** Emit a handler result and its audit row, then exit. */
function te_lineups_emit(PDO $pdo, int $userId, array $result): void
{
    if (!empty($result['audit'])) {
        $a = $result['audit'];
        AuditLogger::log($pdo, $userId ?: null, $a['action'], $a['resource_type'] ?? 'lineup', $a['resource_id'] ?? null, $a['details'] ?? []);
    }
    http_response_code($result['status']);
    echo json_encode($result['body']);
    exit;
}

$teamId = (int) ($_GET['team_id'] ?? $body['team_id'] ?? 0);
$eventRaw = $_GET['event_id'] ?? $body['event_id'] ?? null;
$eventId = ($eventRaw === null || $eventRaw === '' ) ? null : (int) $eventRaw;
if ($teamId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'team_id is required']);
    exit;
}

switch ($action) {
    case 'get':
        te_lineups_emit($pdo, $userId, te_lineup_get($pdo, $auth, $teamId, $eventId));
        // no break — emit exits
    case 'games':
        te_lineups_emit($pdo, $userId, te_lineup_games($pdo, $auth, $teamId));
        // no break
    case 'save':
        if ($method !== 'POST' && $method !== 'PUT') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            exit;
        }
        te_lineups_emit($pdo, $userId, te_lineup_save($pdo, $auth, $teamId, $eventId, $body, $userId));
        // no break
    case 'copy-from':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            exit;
        }
        te_lineups_emit($pdo, $userId, te_lineup_copy_from($pdo, $auth, $teamId, $eventId, $body['source'] ?? 'template', $userId));
        // no break
    case 'publish':
    case 'unpublish':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            exit;
        }
        te_lineups_emit($pdo, $userId, te_lineup_publish($pdo, $auth, $teamId, $eventId, $action === 'publish', $userId));
        // no break
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
        exit;
}
