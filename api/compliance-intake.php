<?php
/**
 * Credential intake — the LMS feed and its admin surface (GOTR G7, migration 098).
 *
 *   POST ?action=lms          Authorization: Bearer <intake key>
 *        {email, requirement_key, completed_on, external_id?, org_unit_id?}
 *        200 {matched: true, credential_id}      the credential was written (source='lms')
 *        202 {matched: false, unmatched: true}   nobody / no requirement — queued for an admin
 *        400 invalid body · 401 bad or revoked key · 403 key of another unit
 *        429 over 600/min for this key · 503 feature off or migration 098 not applied
 *
 *   The admin half, JWT + org_admin standing at ?org_unit_id=U:
 *   GET  ?action=keys&org_unit_id=U
 *   POST ?action=key-create   {org_unit_id, name}      -> the plaintext key, ONCE
 *   POST ?action=key-revoke   {org_unit_id, id}
 *   GET  ?action=unmatched&org_unit_id=U
 *   POST ?action=match        {org_unit_id, id, user_id, requirement_id?}
 *   GET  ?action=people&org_unit_id=U&q=…               staff under the unit, for the match picker
 *
 * ⚠️ THE FEED ACTION DOES NOT REQUIRE A USER TOKEN. It is authenticated by the
 * intake key, and that key is the whole of its authorization — which is why
 * it is dispatched BEFORE AuthMiddleware::requireAuth() and why it can do only
 * one thing: write a credential for a person the key's unit already has.
 * Everything else on this file is behind a JWT and org_admin standing.
 *
 * Two switches: COMPLIANCE (the feature) and COMPLIANCE_INTAKE (this feed).
 * A feed that is off answers feature_disabled, never success.
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/AuditLogger.php';
require_once __DIR__ . '/../lib/org_scope.php';
require_once __DIR__ . '/../lib/compliance.php';
require_once __DIR__ . '/../lib/compliance_intake.php';
require_once __DIR__ . '/../lib/feature_flags.php';

function te_intake_fail(int $status, string $message, array $extra = []): void
{
    http_response_code($status);
    echo json_encode(array_merge(['success' => false, 'error' => $message], $extra));
    exit;
}

function te_intake_unavailable(): void
{
    http_response_code(503);
    echo json_encode([
        'success' => false, 'available' => false,
        'error' => 'Credential intake is not switched on yet. The database update for this feature (migration 098) has not been applied — nothing was saved.',
    ]);
    exit;
}

try {
    $pdo = Database::getInstance()->getConnection();
} catch (Exception $e) {
    error_log('compliance-intake: DB connection failed: ' . $e->getMessage());
    te_intake_fail(500, 'Database connection failed');
}

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

// ---------------------------------------------------------------------------
// The feed. Key-authenticated; see the header.
// ---------------------------------------------------------------------------
if ($action === 'lms') {
    if ($method !== 'POST') {
        te_intake_fail(405, 'Method not allowed');
    }
    if (!te_feature_enabled('COMPLIANCE_INTAKE')) {
        http_response_code(503);
        echo json_encode(te_feature_disabled_response('COMPLIANCE_INTAKE'));
        exit;
    }
    if (!te_compliance_intake_tables_present($pdo) || !te_compliance_tables_present($pdo)) {
        te_intake_unavailable();
    }

    $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
    $header = $headers['Authorization'] ?? $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? null);
    $authKey = te_compliance_intake_authenticate($pdo, te_compliance_intake_bearer_from_header($header));
    if (!$authKey['ok']) {
        // Missing, unknown and revoked all answer the same sentence: which keys
        // exist is not the caller's to learn.
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'A valid intake key is required']);
        exit;
    }
    $key = $authKey['key'];

    // A request that names a unit other than the key's is refused, not
    // silently re-scoped — a feed configured for the wrong council is a
    // misconfiguration the integrator needs to see.
    $claimedUnit = (int) ($body['org_unit_id'] ?? $_GET['org_unit_id'] ?? 0);
    if ($claimedUnit > 0 && $claimedUnit !== $key['org_unit_id']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'This key does not belong to that organization']);
        exit;
    }

    $redis = null;
    try {
        require_once __DIR__ . '/../lib/RedisQueue.php';
        $redis = Env::get('REDIS_URL') ? RedisQueue::getInstance()->getClient() : null;
    } catch (Throwable $e) {
        $redis = null; // the DB count takes over
    }
    if (te_compliance_intake_rate_limited($pdo, $key['id'], $redis)) {
        http_response_code(429);
        header('Retry-After: 60');
        echo json_encode(['success' => false, 'error' => 'Too many requests for this key; limit is '
            . TE_COMPLIANCE_INTAKE_RATE_PER_MINUTE . ' per minute']);
        exit;
    }

    $result = te_compliance_intake_receive($pdo, $key, $body);
    if (!$result['ok']) {
        if (($result['reason'] ?? '') === 'invalid') {
            te_intake_fail(400, $result['error'] ?? 'Invalid request');
        }
        te_intake_fail(500, $result['error'] ?? 'Could not record the completion');
    }
    te_compliance_intake_touch_key($pdo, $key['id']);

    // Every accepted arrival is audited — and the rate limit's DB fallback
    // counts exactly these rows, so the audit IS the counter when Redis is out.
    AuditLogger::log($pdo, null, 'compliance_intake_received', 'compliance_intake_keys', $key['id'], [
        'org_unit_id'     => $key['org_unit_id'],
        'matched'         => $result['matched'],
        'reason'          => $result['reason'] ?? null,
        'user_id'         => $result['user_id'] ?? null,
        'requirement_id'  => $result['requirement_id'] ?? null,
        'credential_id'   => $result['credential_id'] ?? null,
        'unmatched_id'    => $result['unmatched_id'] ?? null,
        'requirement_key' => (string) ($body['requirement_key'] ?? ''),
    ]);

    if ($result['matched']) {
        echo json_encode([
            'success' => true, 'matched' => true,
            'credential_id' => $result['credential_id'], 'expires_at' => $result['expires_at'] ?? null,
        ]);
        exit;
    }
    http_response_code(202);
    echo json_encode([
        'success' => true, 'matched' => false, 'unmatched' => true,
        'reason' => $result['reason'], 'unmatched_id' => $result['unmatched_id'] ?? null,
    ]);
    exit;
}

// ---------------------------------------------------------------------------
// The admin surface. JWT, then org_admin standing at the unit, before anything
// dispatches — an action added below cannot skip it.
// ---------------------------------------------------------------------------
$auth = AuthMiddleware::requireAuth();
$userId = (int) $auth->getUserId();

$orgUnitId = (int) ($_GET['org_unit_id'] ?? $body['org_unit_id'] ?? 0);
if ($orgUnitId <= 0) {
    te_intake_fail(400, 'org_unit_id is required');
}
if (te_user_org_standing($pdo, $auth, $orgUnitId) !== 'org_admin') {
    te_intake_fail(403, 'Only an administrator of this organization can manage its intake feeds');
}
$available = te_compliance_intake_tables_present($pdo);

if ($action === 'keys') {
    if ($method !== 'GET') {
        te_intake_fail(405, 'Method not allowed');
    }
    echo json_encode(['success' => true, 'available' => $available,
                      'keys' => $available ? te_compliance_intake_keys($pdo, $orgUnitId) : []]);
    exit;
}

if ($action === 'key-create') {
    if ($method !== 'POST') {
        te_intake_fail(405, 'Method not allowed');
    }
    if (!$available) {
        te_intake_unavailable();
    }
    $created = te_compliance_intake_key_create($pdo, $orgUnitId, (string) ($body['name'] ?? ''), $userId ?: null);
    if (!$created['ok']) {
        te_intake_fail(($created['reason'] ?? '') === 'name_required' ? 400 : 500, $created['error'] ?? 'Could not create the key');
    }
    AuditLogger::log($pdo, $userId ?: null, 'compliance_intake_key_created', 'compliance_intake_keys', $created['id'], [
        'org_unit_id' => $orgUnitId, 'name' => (string) ($body['name'] ?? ''), 'prefix' => $created['prefix'],
    ]);
    // The plaintext, exactly once. It is not stored and cannot be shown again.
    echo json_encode(['success' => true, 'id' => $created['id'], 'key' => $created['plain'], 'prefix' => $created['prefix']]);
    exit;
}

if ($action === 'key-revoke') {
    if ($method !== 'POST') {
        te_intake_fail(405, 'Method not allowed');
    }
    if (!$available) {
        te_intake_unavailable();
    }
    $id = (int) ($body['id'] ?? 0);
    $revoked = te_compliance_intake_key_revoke($pdo, $id, $orgUnitId, $userId ?: null);
    if (!$revoked['ok']) {
        te_intake_fail(($revoked['reason'] ?? '') === 'not_found' ? 404 : 500, 'Could not revoke the key');
    }
    if (!$revoked['already']) {
        AuditLogger::log($pdo, $userId ?: null, 'compliance_intake_key_revoked', 'compliance_intake_keys', $id, [
            'org_unit_id' => $orgUnitId,
        ]);
    }
    echo json_encode(['success' => true, 'id' => $id]);
    exit;
}

if ($action === 'unmatched') {
    if ($method !== 'GET') {
        te_intake_fail(405, 'Method not allowed');
    }
    echo json_encode(['success' => true, 'available' => $available,
                      'arrivals' => $available ? te_compliance_intake_unmatched($pdo, $orgUnitId) : []]);
    exit;
}

if ($action === 'people') {
    // Staff under the unit whose name or email contains q — the match picker.
    if ($method !== 'GET') {
        te_intake_fail(405, 'Method not allowed');
    }
    $q = strtolower(trim((string) ($_GET['q'] ?? '')));
    $people = [];
    if (mb_strlen($q) >= 2) {
        foreach (te_compliance_intake_clubs_under_unit($pdo, $orgUnitId) as $clubId) {
            foreach (te_compliance_club_staff($pdo, $clubId) as $person) {
                $hay = strtolower(trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? '') . ' ' . ($person['email'] ?? '')));
                if (strpos($hay, $q) !== false) {
                    $people[$person['user_id']] = $person + ['club_id' => $clubId];
                }
            }
            if (count($people) >= 25) {
                break;
            }
        }
    }
    echo json_encode(['success' => true, 'people' => array_slice(array_values($people), 0, 25)]);
    exit;
}

if ($action === 'match') {
    if ($method !== 'POST') {
        te_intake_fail(405, 'Method not allowed');
    }
    if (!$available) {
        te_intake_unavailable();
    }
    $id = (int) ($body['id'] ?? 0);
    $personId = (int) ($body['user_id'] ?? 0);
    $requirementId = isset($body['requirement_id']) && (int) $body['requirement_id'] > 0 ? (int) $body['requirement_id'] : null;
    if ($id <= 0 || $personId <= 0) {
        te_intake_fail(400, 'id and user_id are required');
    }
    $matched = te_compliance_intake_match($pdo, $orgUnitId, $id, $personId, $userId ?: null, $requirementId);
    if (!$matched['ok']) {
        $status = match ($matched['reason'] ?? '') {
            'not_found'              => 404,
            'already_matched'        => 409,
            'person_not_under_unit', 'no_requirement', 'bad_date' => 422,
            default                  => 500,
        };
        te_intake_fail($status, $matched['error'] ?? 'Could not match the arrival', ['reason' => $matched['reason'] ?? null]);
    }
    AuditLogger::log($pdo, $userId ?: null, 'compliance_intake_matched', 'compliance_intake_unmatched', $id, [
        'org_unit_id'    => $orgUnitId,
        'user_id'        => $personId,
        'requirement_id' => $matched['requirement_id'],
        'credential_id'  => $matched['credential_id'],
    ]);
    echo json_encode(['success' => true, 'credential_id' => $matched['credential_id']]);
    exit;
}

te_intake_fail(400, 'Unknown action');
