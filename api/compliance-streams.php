<?php
/**
 * Compliance reminder streams — authoring and preview (GOTR G7).
 *
 *   GET  ?action=for-requirement&requirement_id=N&club_id=C     (or &org_unit_id=U)
 *          -> which stream applies at this tier, the tier's own row, the tag list
 *   POST ?action=save        {id?, requirement_id, club_profile_id | org_unit_id, steps, active?}
 *   POST ?action=set-active  {id, active}
 *   POST ?action=preview     {subject, body, club_id?}   -> the step rendered for the caller
 *
 * AUTHORIZATION is the TIER's, through te_compliance_stream_can_author():
 * te_compliance_can_admin_club for a club stream (club admin, or org_admin over
 * the club's unit) and org_admin standing for an org-unit stream. On save and
 * set-active of an EXISTING row the tier comes from the stored row, never the
 * body. A coach has no standing here at all — a stream mails every staff member
 * of a club.
 *
 * Validation failures are 422 with the reason and, for an unknown merge tag,
 * the tag names — the form renders them next to the field.
 *
 * Behind te_feature_enabled('COMPLIANCE'), checked once before dispatch, and
 * te_compliance_tables_present() (migration 091) — the same 503 sentence as the
 * main gateway until it is applied.
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/AuditLogger.php';
require_once __DIR__ . '/../lib/org_scope.php';
require_once __DIR__ . '/../lib/compliance.php';
require_once __DIR__ . '/../lib/compliance_reminders.php';
require_once __DIR__ . '/../lib/compliance_streams.php';
require_once __DIR__ . '/../lib/feature_flags.php';

function te_streams_fail(int $status, string $message, array $extra = []): void
{
    http_response_code($status);
    echo json_encode(array_merge(['success' => false, 'error' => $message], $extra));
    exit;
}

try {
    $pdo = Database::getInstance()->getConnection();
} catch (Exception $e) {
    error_log('compliance-streams: DB connection failed: ' . $e->getMessage());
    te_streams_fail(500, 'Database connection failed');
}

$auth = AuthMiddleware::requireAuth();
$userId = (int) $auth->getUserId();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$body = [];
if ($method !== 'GET') {
    $raw = file_get_contents('php://input');
    $body = $raw ? (json_decode($raw, true) ?: []) : [];
}
$action = (string) ($_GET['action'] ?? $body['action'] ?? '');

if (!te_feature_enabled('COMPLIANCE')) {
    http_response_code(503);
    echo json_encode(te_feature_disabled_response('COMPLIANCE'));
    exit;
}

$available = te_compliance_tables_present($pdo);

// ---------------------------------------------------------------------------
if ($action === 'for-requirement') {
    if ($method !== 'GET') {
        te_streams_fail(405, 'Method not allowed');
    }
    $requirementId = (int) ($_GET['requirement_id'] ?? 0);
    $clubId = (int) ($_GET['club_id'] ?? 0);
    $orgUnitId = (int) ($_GET['org_unit_id'] ?? 0);
    if ($requirementId <= 0 || ($clubId > 0) === ($orgUnitId > 0)) {
        te_streams_fail(400, 'requirement_id and exactly one of club_id or org_unit_id are required');
    }
    if (!te_compliance_stream_can_author($pdo, $auth, $clubId, $orgUnitId)) {
        te_streams_fail(403, 'Only an administrator of this tier can manage its reminder streams');
    }
    if (!$available) {
        echo json_encode(['success' => true, 'available' => false, 'applies' => 'default', 'stream' => null,
                          'own' => null, 'inherited_from' => null, 'tags' => TE_COMPLIANCE_STREAM_TAGS,
                          'default_thresholds' => TE_COMPLIANCE_REMINDER_THRESHOLDS]);
        exit;
    }
    if (!te_compliance_stream_tier_applies($pdo, $requirementId, $clubId, $orgUnitId)) {
        te_streams_fail(404, 'That requirement does not apply at this tier');
    }
    $described = $clubId > 0
        ? te_compliance_stream_describe($pdo, $requirementId, $clubId)
        : te_compliance_stream_describe_unit($pdo, $requirementId, $orgUnitId);
    echo json_encode(['success' => true, 'available' => true] + $described);
    exit;
}

// ---------------------------------------------------------------------------
if ($action === 'save') {
    if ($method !== 'POST' && $method !== 'PUT') {
        te_streams_fail(405, 'Method not allowed');
    }
    if (!$available) {
        te_streams_fail(503, 'Compliance is not switched on yet. The database update for this feature has not been applied — nothing was saved.', ['available' => false]);
    }

    $id = (int) ($body['id'] ?? 0);
    if ($id > 0) {
        // Tier from the STORED row, never the body.
        $existing = te_compliance_stream_get($pdo, $id);
        if (!$existing) {
            te_streams_fail(404, 'Stream not found');
        }
        $clubId = (int) ($existing['club_profile_id'] ?? 0);
        $orgUnitId = (int) ($existing['org_unit_id'] ?? 0);
        $requirementId = $existing['requirement_id'];
    } else {
        $clubId = (int) ($body['club_profile_id'] ?? 0);
        $orgUnitId = (int) ($body['org_unit_id'] ?? 0);
        $requirementId = (int) ($body['requirement_id'] ?? 0);
        if ($requirementId <= 0 || ($clubId > 0) === ($orgUnitId > 0)) {
            te_streams_fail(400, 'requirement_id and exactly one of club_profile_id or org_unit_id are required');
        }
    }
    if (!te_compliance_stream_can_author($pdo, $auth, $clubId, $orgUnitId)) {
        te_streams_fail(403, 'Only an administrator of this tier can manage its reminder streams');
    }

    $data = [
        'id'              => $id,
        'requirement_id'  => $requirementId,
        'club_profile_id' => $clubId,
        'org_unit_id'     => $orgUnitId,
        'steps'           => $body['steps'] ?? [],
    ];
    // `active` is written only when the body says so; an omitted key keeps the
    // stored value on an update and defaults to OFF on a create — a stream
    // starts dark and is switched on deliberately.
    if (array_key_exists('active', $body)) {
        $data['active'] = $body['active'];
    } elseif ($id <= 0) {
        $data['active'] = false;
    }
    $result = te_compliance_stream_save($pdo, $data, $userId ?: null);

    if (!$result['ok']) {
        $reason = $result['reason'] ?? 'error';
        $status = match ($reason) {
            'not_found'                => 404,
            'requirement_not_at_tier'  => 404,
            'one_tier', 'requirement_required' => 400,
            'error', 'schema'          => 500,
            default                    => 422,
        };
        te_streams_fail($status, $result['error'] ?? 'Could not save the stream', [
            'reason' => $reason, 'unknown_tags' => $result['unknown_tags'] ?? [],
        ]);
    }

    $saved = te_compliance_stream_get($pdo, $result['id']);
    AuditLogger::log($pdo, $userId ?: null, 'compliance_stream_saved', 'compliance_reminder_streams', $result['id'], [
        'requirement_id'  => $requirementId,
        'club_profile_id' => $clubId ?: null,
        'org_unit_id'     => $orgUnitId ?: null,
        'active'          => $saved['active'] ?? null,
        'steps'           => array_map(static fn (array $s): int => $s['days_before'], $saved['steps'] ?? []),
        'created'         => $result['created'] ?? false,
    ]);

    echo json_encode(['success' => true, 'id' => $result['id'], 'stream' => $saved]);
    exit;
}

// ---------------------------------------------------------------------------
if ($action === 'set-active') {
    if ($method !== 'POST' && $method !== 'PUT') {
        te_streams_fail(405, 'Method not allowed');
    }
    if (!$available) {
        te_streams_fail(503, 'Compliance is not switched on yet. The database update for this feature has not been applied — nothing was saved.', ['available' => false]);
    }
    $id = (int) ($body['id'] ?? 0);
    $existing = $id > 0 ? te_compliance_stream_get($pdo, $id) : null;
    if (!$existing) {
        te_streams_fail(404, 'Stream not found');
    }
    $clubId = (int) ($existing['club_profile_id'] ?? 0);
    $orgUnitId = (int) ($existing['org_unit_id'] ?? 0);
    if (!te_compliance_stream_can_author($pdo, $auth, $clubId, $orgUnitId)) {
        te_streams_fail(403, 'Only an administrator of this tier can manage its reminder streams');
    }
    $active = te_compliance_bool($body['active'] ?? false);
    if ($active && !$existing['steps']) {
        te_streams_fail(422, 'Add at least one step before switching the stream on', ['reason' => 'no_steps']);
    }
    $result = te_compliance_stream_set_active($pdo, $id, $active);
    if (!$result['ok']) {
        te_streams_fail(500, 'Could not update the stream');
    }
    AuditLogger::log($pdo, $userId ?: null, $active ? 'compliance_stream_activated' : 'compliance_stream_deactivated',
        'compliance_reminder_streams', $id, [
            'requirement_id' => $existing['requirement_id'], 'club_profile_id' => $clubId ?: null,
            'org_unit_id' => $orgUnitId ?: null,
        ]);
    echo json_encode(['success' => true, 'id' => $id, 'active' => $active, 'stream' => te_compliance_stream_get($pdo, $id)]);
    exit;
}

// ---------------------------------------------------------------------------
if ($action === 'preview') {
    if ($method !== 'POST') {
        te_streams_fail(405, 'Method not allowed');
    }
    // Renders ONE step for the signed-in admin with sample values. No send,
    // no write. The subject/body are validated the same way a save is, so a
    // preview cannot show a tag the save would then refuse.
    $validated = te_compliance_stream_validate_steps([[
        'days_before' => $body['days_before'] ?? 30,
        'subject'     => $body['subject'] ?? '',
        'body'        => $body['body'] ?? '',
    ]]);
    if (!$validated['ok']) {
        te_streams_fail(422, $validated['error'] ?? 'Invalid step', [
            'reason' => $validated['reason'] ?? 'invalid', 'unknown_tags' => $validated['unknown_tags'] ?? [],
        ]);
    }
    $step = $validated['steps'][0];
    $clubId = (int) ($body['club_id'] ?? 0);
    $clubName = $clubId > 0 && te_compliance_can_admin_club($pdo, $auth, $clubId)
        ? te_compliance_stream_club_name($pdo, $clubId) : '';

    $firstName = '';
    try {
        $stmt = $pdo->prepare('SELECT first_name FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $firstName = trim((string) $stmt->fetchColumn());
    } catch (Throwable $e) {
        error_log('compliance-streams preview: ' . $e->getMessage());
    }

    $days = $step['days_before'];
    $sampleExpiry = te_compliance_reminder_shift(te_compliance_today(), $days);
    $values = [
        'first_name'       => $firstName !== '' ? $firstName : 'Sam',
        'requirement_name' => (string) ($body['requirement_name'] ?? 'Background check'),
        'expires_on'       => te_compliance_reminder_format_date($sampleExpiry),
        'days_left'        => (string) abs($days),
        'club_name'        => $clubName !== '' ? $clubName : 'Your club',
        'renewal_url'      => rtrim((string) Env::get('APP_URL', 'https://teams-elevated.netlify.app'), '/') . '/compliance/mine',
    ];
    echo json_encode([
        'success' => true,
        'subject' => te_compliance_stream_render($step['subject'], $values)['text'],
        'body'    => te_compliance_stream_render($step['body'], $values)['text'],
        'values'  => $values,
    ]);
    exit;
}

te_streams_fail(400, 'Unknown action');
