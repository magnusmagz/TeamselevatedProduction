<?php
/**
 * Referee feedback from the coaches portal (CKU R68, slice 8.6).
 *
 * A coach records what they thought of the referee(s) of a game they coached;
 * a club admin reviews every row for the club and downloads it as CSV. Every
 * decision lives in lib/referee_feedback.php so it can be executed by
 * RefereeFeedbackTest; this file is the HTTP shape only.
 *
 * ACTIONS
 *   GET  ?action=event&event_id=N   staff on the event — the caller's own rows
 *                                   on it, whether it can be rated, which teams
 *   GET  ?action=mine               coach / club admin — everything I submitted
 *   GET  ?action=list&club_id=N     club admin — the club's rows + per-referee
 *                                   summary. Filters: from, to, team_id,
 *                                   incident=1, referee_name
 *   GET  ?action=export&club_id=N   club admin — the same rows as CSV
 *   POST ?action=create             staff on the event AND the game is played
 *   PUT  ?action=update             the author only
 *
 * A parent holds none of these standings and gets 403 everywhere; there is no
 * read path for families by design (decision for Maggie: see the report).
 *
 * Migration 095 may not be applied yet when this ships — `main` is shared and
 * deploys are by push — so writes answer 503 with a sentence and reads answer
 * `available: false` until it is.
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/AuditLogger.php';
require_once __DIR__ . '/../lib/referee_feedback.php';

function te_ref_fail(int $status, string $message, array $extra = []): void
{
    http_response_code($status);
    echo json_encode(array_merge(['success' => false, 'error' => $message], $extra));
    exit;
}

try {
    $pdo = Database::getInstance()->getConnection();
} catch (Exception $e) {
    error_log('referee-feedback: DB connection failed: ' . $e->getMessage());
    te_ref_fail(500, 'Database connection failed');
}

$auth = AuthMiddleware::requireAuth();
$userId = (int) $auth->getUserId();

$method = $_SERVER['REQUEST_METHOD'];
$body = [];
if ($method !== 'GET') {
    $raw = file_get_contents('php://input');
    $body = $raw ? (json_decode($raw, true) ?: []) : [];
}

$action = (string) ($_GET['action'] ?? $body['action'] ?? '');
if ($action === '') {
    $action = $method === 'GET' ? 'mine' : 'create';
}

// "Today" is a date-only string in the server's zone, compared against
// calendar_events.event_date as a string. Never a DateTime.
$today = date('Y-m-d');

/** Names for a list of team ids, in id order. */
function te_ref_team_names(PDO $pdo, array $ids): array
{
    if (empty($ids)) {
        return [];
    }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, name FROM teams WHERE id IN ($marks) ORDER BY id");
    $stmt->execute(array_values($ids));
    return array_map(
        fn($r) => ['id' => (int) $r['id'], 'name' => (string) $r['name']],
        $stmt->fetchAll(PDO::FETCH_ASSOC)
    );
}

/** 404 / 403 / event for a write or the per-event read. */
function te_ref_event_for_staff(PDO $pdo, AuthMiddleware $auth, int $eventId): array
{
    if ($eventId <= 0) {
        te_ref_fail(400, 'event_id is required');
    }
    $standing = te_event_staff_standing($pdo, $auth, $eventId);
    if ($standing === null) {
        te_ref_fail(404, 'Event not found');
    }
    if ($standing === false) {
        te_ref_fail(403, 'Only a coach of a team on this game, or a club admin, can record referee feedback');
    }
    $event = te_referee_feedback_event($pdo, $eventId);
    if ($event === null) {
        te_ref_fail(404, 'Event not found');
    }
    return $event;
}

// ---------------------------------------------------------------------------
// Reads
// ---------------------------------------------------------------------------

if ($action === 'event') {
    $event = te_ref_event_for_staff($pdo, $auth, (int) ($_GET['event_id'] ?? 0));
    $reason = te_referee_feedback_rateability($event, $today);
    $writable = te_referee_feedback_writable_team_ids($pdo, $auth, $event);
    $available = te_referee_feedback_table_present($pdo);

    echo json_encode([
        'success'    => true,
        'available'  => $available,
        'event'      => [
            'id'            => $event['id'],
            'name'          => $event['name'],
            'event_date'    => substr($event['event_date'], 0, 10),
            'opponent_name' => $event['opponent_name'],
        ],
        'can_submit' => $available && $reason === null && !empty($writable),
        'reason'     => $available ? $reason : te_referee_feedback_unavailable_message(),
        'teams'      => te_ref_team_names($pdo, $writable),
        'categories' => TE_REFEREE_FEEDBACK_CATEGORIES,
        'feedback'   => $available ? te_referee_feedback_for_event($pdo, $event['id'], $userId) : [],
    ]);
    exit;
}

if ($action === 'mine') {
    if (!te_referee_feedback_can_review_own($auth)) {
        te_ref_fail(403, 'Access denied');
    }
    if (!te_referee_feedback_table_present($pdo)) {
        echo json_encode(['success' => true, 'available' => false, 'feedback' => []]);
        exit;
    }
    echo json_encode([
        'success'   => true,
        'available' => true,
        'feedback'  => te_referee_feedback_mine($pdo, $userId),
    ]);
    exit;
}

if ($action === 'list' || $action === 'export') {
    $clubId = (int) ($_GET['club_id'] ?? 0);
    if ($clubId <= 0) {
        te_ref_fail(400, 'club_id is required');
    }
    // Club-wide staff data: admin only. A coach reads their own rows via `mine`.
    if (!te_is_club_admin($auth, $clubId)) {
        te_ref_fail(403, 'Only a club admin can review referee feedback for the club');
    }

    $filters = [
        'from'         => $_GET['from'] ?? null,
        'to'           => $_GET['to'] ?? null,
        'team_id'      => $_GET['team_id'] ?? null,
        'incident'     => $_GET['incident'] ?? null,
        'referee_name' => $_GET['referee_name'] ?? null,
    ];

    if ($action === 'list') {
        if (!te_referee_feedback_table_present($pdo)) {
            echo json_encode(['success' => true, 'available' => false, 'feedback' => [], 'summary' => []]);
            exit;
        }
        $rows = te_referee_feedback_list($pdo, $clubId, $filters);
        echo json_encode([
            'success'    => true,
            'available'  => true,
            'feedback'   => $rows,
            'summary'    => te_referee_feedback_summary($rows),
            'categories' => TE_REFEREE_FEEDBACK_CATEGORIES,
        ]);
        exit;
    }

    // export
    if (!te_referee_feedback_table_present($pdo)) {
        te_ref_fail(503, te_referee_feedback_unavailable_message(), ['available' => false]);
    }
    $rows = te_referee_feedback_list($pdo, $clubId, $filters);
    $sheet = te_referee_feedback_export_sheet($rows);
    $notice = te_referee_feedback_truncation_notice($sheet);

    AuditLogger::log($pdo, $userId ?: null, 'referee_feedback_exported', 'club', $clubId, [
        'row_count' => count($sheet['rows']),
        'truncated' => $notice !== null,
        'notice'    => $notice,
        'filters'   => array_filter($filters, fn($v) => $v !== null && $v !== ''),
    ]);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . te_referee_feedback_export_filename($today) . '"');
    header('Cache-Control: no-store');
    // The caps are reported, never silent — a download has no screen to say so.
    if ($notice !== null) {
        header('X-Referee-Feedback-Export-Truncated: ' . preg_replace('/[\r\n]+/', ' ', $notice));
        header('Access-Control-Expose-Headers: X-Referee-Feedback-Export-Truncated');
    }

    $out = fopen('php://output', 'w');
    // Excel reads a CSV as the local codepage without a BOM (José, Muñoz).
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $sheet['headers']);
    foreach ($sheet['rows'] as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

// ---------------------------------------------------------------------------
// Writes
// ---------------------------------------------------------------------------

if ($action === 'create') {
    if ($method !== 'POST') {
        te_ref_fail(405, 'Method not allowed');
    }

    $event = te_ref_event_for_staff($pdo, $auth, (int) ($body['event_id'] ?? 0));
    $reason = te_referee_feedback_rateability($event, $today);
    if ($reason !== null) {
        te_ref_fail(422, $reason);
    }
    $writable = te_referee_feedback_writable_team_ids($pdo, $auth, $event);
    $teamId = te_referee_feedback_resolve_team($writable, $body['team_id'] ?? null);
    if ($teamId === null) {
        te_ref_fail(422, count($writable) > 1
            ? 'Say which of your teams this feedback is for'
            : 'That team is not yours on this game', ['teams' => te_ref_team_names($pdo, $writable)]);
    }
    if (!te_referee_feedback_table_present($pdo)) {
        te_ref_fail(503, te_referee_feedback_unavailable_message(), ['available' => false]);
    }

    $validated = te_referee_feedback_validate($body);
    if ($validated['error'] !== null) {
        te_ref_fail(422, $validated['error']);
    }
    $values = $validated['values'];

    // One row per (game, coach, referee): a second submission is an edit.
    foreach (te_referee_feedback_for_event($pdo, $event['id'], $userId) as $existing) {
        if (mb_strtolower($existing['referee_name']) === mb_strtolower($values['referee_name'])) {
            te_ref_fail(409, 'You have already recorded feedback about this referee for this game — edit that instead', [
                'id' => $existing['id'],
            ]);
        }
    }

    try {
        $id = te_referee_feedback_create($pdo, $event, $teamId, $userId, $values);
    } catch (Throwable $e) {
        error_log('referee-feedback: create failed: ' . $e->getMessage());
        te_ref_fail(500, 'Could not save the feedback');
    }

    AuditLogger::log($pdo, $userId ?: null, 'referee_feedback_submitted', 'calendar_event', $event['id'], [
        'feedback_id'  => $id,
        'team_id'      => $teamId,
        'referee_name' => $values['referee_name'],
        'rating'       => $values['rating'],
        'incident'     => $values['incident'],
    ]);

    echo json_encode(['success' => true, 'available' => true, 'id' => $id, 'feedback' => te_referee_feedback_find($pdo, $id)]);
    exit;
}

if ($action === 'update') {
    if ($method !== 'PUT' && $method !== 'POST') {
        te_ref_fail(405, 'Method not allowed');
    }
    $id = (int) ($body['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) {
        te_ref_fail(400, 'id is required');
    }
    if (!te_referee_feedback_table_present($pdo)) {
        te_ref_fail(503, te_referee_feedback_unavailable_message(), ['available' => false]);
    }

    $existing = te_referee_feedback_find($pdo, $id);
    if ($existing === null) {
        te_ref_fail(404, 'Feedback not found');
    }
    // Standing on the event is re-checked — a coach moved off the team since
    // is no longer staff on it — and then the row must be the caller's own.
    $event = te_ref_event_for_staff($pdo, $auth, (int) $existing['calendar_event_id']);
    if ((int) $existing['submitted_by'] !== $userId) {
        te_ref_fail(403, 'Only the coach who recorded this feedback can change it');
    }

    $validated = te_referee_feedback_validate($body);
    if ($validated['error'] !== null) {
        te_ref_fail(422, $validated['error']);
    }
    $values = $validated['values'];

    try {
        te_referee_feedback_update($pdo, $id, $values);
    } catch (Throwable $e) {
        error_log('referee-feedback: update failed: ' . $e->getMessage());
        te_ref_fail(500, 'Could not save the feedback');
    }

    AuditLogger::log($pdo, $userId ?: null, 'referee_feedback_updated', 'calendar_event', $event['id'], [
        'feedback_id'  => $id,
        'referee_name' => $values['referee_name'],
        'rating'       => $values['rating'],
        'incident'     => $values['incident'],
        'was_incident' => (bool) $existing['incident'],
    ]);

    echo json_encode(['success' => true, 'available' => true, 'id' => $id, 'feedback' => te_referee_feedback_find($pdo, $id)]);
    exit;
}

te_ref_fail(400, 'Unknown action');
