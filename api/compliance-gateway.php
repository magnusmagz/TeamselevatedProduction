<?php
/**
 * Compliance requirements and person credentials (GOTR G3, migration 091).
 *
 * ⚠️ index.php performs NO authentication. Whatever this file does is the whole of
 * the access control for every action in it, and there is no upstream layer that
 * will catch an action added above the gate.
 *
 * ACTIONS
 *   GET    ?action=requirements&club_id=N        admin of the club   — the club's inherited set
 *   GET    ?action=requirements&org_unit_id=N    org_admin of the unit — that unit's own rows
 *   POST   ?action=requirement-save              admin of the owner  — create or update
 *   DELETE ?action=requirement-delete&id=N       admin of the owner  — deactivate, or delete if unused
 *   GET    ?action=my-requirements[&club_id=N]   the signed-in person, any role
 *   POST   ?action=record                        admin — enter a completion for a person
 *   POST   ?action=submit                        the person — attest a date / attach their document
 *   POST   ?action=review                        admin — verify or reject with a reason
 *   GET    ?action=club-status&club_id=N         admin — every staff member's rollup
 *
 * THE THREE STANDING PREDICATES, AND WHY THEY ARE NOT ONE
 *
 * - `te_compliance_can_admin_club` (club admin, OR org_admin over the club's tier) gates
 *   everything club-wide: the requirement list, recording somebody else's completion,
 *   reviewing proof, the roll call. A COACH is deliberately not admitted — a coach is
 *   team-scoped and this is club-wide staff data about other people's background checks,
 *   the same distinction as te_is_club_admin vs te_is_club_staff.
 * - `te_user_org_standing(...) === 'org_admin'` gates a requirement owned by a tier. An
 *   `org_viewer` reads rollups (G5) and writes nothing.
 * - `my-requirements` and `submit` are about YOURSELF and need no standing beyond being
 *   signed in. They also take no user_id from the body — the person is the token.
 *
 * ⚠️ `record` and `review` take a club_id and check the requirement is actually in THAT
 * club's inherited set. Without it a club admin could record a completion against any
 * requirement id in the platform, including another council's, which would put a row in
 * that council's compliance report signed by somebody with no standing there.
 *
 * Everything is behind te_feature_enabled('COMPLIANCE'), checked once before dispatch.
 * The switch is unset-means-ON per lib/feature_flags.php, so shipping this dark means
 * setting TE_FEATURE_COMPLIANCE=off BEFORE the backend deploy, not merely not setting it.
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/AuditLogger.php';
require_once __DIR__ . '/../lib/club_standing.php';
require_once __DIR__ . '/../lib/org_scope.php';
require_once __DIR__ . '/../lib/compliance.php';
require_once __DIR__ . '/../lib/feature_flags.php';

/** Emit a JSON error and stop. */
function te_comp_fail(int $status, string $message, array $extra = []): void
{
    http_response_code($status);
    echo json_encode(array_merge(['success' => false, 'error' => $message], $extra));
    exit;
}

/**
 * The one sentence a caller gets when migration 091 has not been applied yet.
 *
 * A 503 with a sentence rather than a 500 with a stack trace: `main` is shared and
 * migrations are applied by hand, so this is an expected state for a few hours or days
 * and the admin on the other end needs to know nothing was saved and why.
 */
function te_comp_unavailable(): void
{
    te_comp_fail(503, 'Compliance is not switched on yet. The database update for this feature has not been applied — nothing was saved.', [
        'available' => false,
    ]);
}

try {
    $pdo = Database::getInstance()->getConnection();
} catch (Exception $e) {
    error_log('compliance-gateway: DB connection failed: ' . $e->getMessage());
    te_comp_fail(500, 'Database connection failed');
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

// The kill switch, once, before anything dispatches. A feature that is off must
// not accept a write and answer success for it.
if (!te_feature_enabled('COMPLIANCE')) {
    http_response_code(503);
    echo json_encode(te_feature_disabled_response('COMPLIANCE'));
    exit;
}

$available = te_compliance_tables_present($pdo);

// ---------------------------------------------------------------------------
// Reads
// ---------------------------------------------------------------------------

if ($action === 'requirements') {
    if ($method !== 'GET') {
        te_comp_fail(405, 'Method not allowed');
    }

    $clubId = (int) ($_GET['club_id'] ?? 0);
    $orgUnitId = (int) ($_GET['org_unit_id'] ?? 0);
    if ($clubId <= 0 && $orgUnitId <= 0) {
        te_comp_fail(400, 'club_id or org_unit_id is required');
    }

    if ($orgUnitId > 0) {
        // Authorization BEFORE anything is read.
        if (te_user_org_standing($pdo, $auth, $orgUnitId) !== 'org_admin') {
            te_comp_fail(403, 'Only an administrator of this org unit can manage its requirements');
        }
        if (!$available) {
            echo json_encode(['success' => true, 'available' => false, 'requirements' => []]);
            exit;
        }
        try {
            $stmt = $pdo->prepare(
                'SELECT * FROM compliance_requirements WHERE org_unit_id = ? ORDER BY sort_order, name, id'
            );
            $stmt->execute([$orgUnitId]);
            $rows = te_compliance_decorate_requirements($pdo, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        } catch (Throwable $e) {
            error_log('compliance-gateway requirements(org): ' . $e->getMessage());
            te_comp_fail(500, 'Could not read requirements');
        }
        echo json_encode(['success' => true, 'available' => true, 'requirements' => $rows]);
        exit;
    }

    if (!te_compliance_can_admin_club($pdo, $auth, $clubId)) {
        te_comp_fail(403, 'Only a club administrator can manage requirements');
    }
    // The INHERITED set, not just the club's own rows: an admin has to see what
    // national and their division already demand before adding a fourth copy of it.
    echo json_encode([
        'success'      => true,
        'available'    => $available,
        'requirements' => $available ? te_compliance_requirements_for_club($pdo, $clubId) : [],
    ]);
    exit;
}

if ($action === 'my-requirements') {
    if ($method !== 'GET') {
        te_comp_fail(405, 'Method not allowed');
    }

    // No user_id is accepted here. The person is the token — a body parameter
    // would make this a way to read anybody's compliance record.
    $clubIds = te_compliance_user_club_ids($pdo, $userId);
    $requested = (int) ($_GET['club_id'] ?? 0);
    if ($requested > 0) {
        if (!in_array($requested, $clubIds, true)) {
            te_comp_fail(403, 'You do not hold a role in that club');
        }
        $clubIds = [$requested];
    }

    $clubs = [];
    foreach ($clubIds as $clubId) {
        $status = te_compliance_status($pdo, $userId, $clubId);
        // A club where the person is not staff has no requirements and is not
        // reported at all — an empty list rendered as a green tick is the
        // failure mode, so it must not be reachable from here.
        if (!$status['requirements']) {
            continue;
        }
        $clubs[] = ['club_id' => $clubId] + $status;
    }

    echo json_encode(['success' => true, 'available' => $available, 'clubs' => $clubs]);
    exit;
}

if ($action === 'club-status') {
    if ($method !== 'GET') {
        te_comp_fail(405, 'Method not allowed');
    }
    $clubId = (int) ($_GET['club_id'] ?? 0);
    if ($clubId <= 0) {
        te_comp_fail(400, 'club_id is required');
    }
    if (!te_compliance_can_admin_club($pdo, $auth, $clubId)) {
        te_comp_fail(403, 'Only a club administrator can read the compliance roll call');
    }
    if (!$available) {
        echo json_encode(['success' => true, 'available' => false, 'people' => [], 'summary' => null]);
        exit;
    }

    $filter = strtolower(trim((string) ($_GET['filter'] ?? '')));
    if ($filter !== '' && !in_array($filter, ['compliant', 'expiring', 'expired', 'missing'], true)) {
        // Validated, not defaulted: silently ignoring a typo would report the
        // whole club as if it were the filtered subset.
        te_comp_fail(400, 'filter must be compliant, expiring, expired or missing');
    }

    $people = [];
    $summary = ['total' => 0, 'compliant' => 0, 'expiring_30' => 0, 'expired' => 0, 'missing' => 0];
    foreach (te_compliance_club_staff($pdo, $clubId) as $person) {
        $status = te_compliance_status($pdo, $person['user_id'], $clubId);
        $rollup = $status['rollup'];

        // The summary counts everyone, before the filter — a filtered page that
        // also filtered its own totals could not say "3 of 40".
        $summary['total']++;
        $summary['compliant'] += $rollup['compliant'] ? 1 : 0;
        $summary['expiring_30'] += $rollup['expiring_30'] > 0 ? 1 : 0;
        $summary['expired'] += $rollup['expired'] > 0 ? 1 : 0;
        $summary['missing'] += $rollup['missing'] > 0 ? 1 : 0;

        $keep = match ($filter) {
            'compliant' => $rollup['compliant'],
            'expiring'  => $rollup['expiring_30'] > 0,
            'expired'   => $rollup['expired'] > 0,
            'missing'   => $rollup['missing'] > 0,
            default     => true,
        };
        if ($keep) {
            $people[] = $person + ['rollup' => $rollup, 'requirements' => $status['requirements']];
        }
    }

    echo json_encode([
        'success'   => true,
        'available' => true,
        'filter'    => $filter === '' ? null : $filter,
        'summary'   => $summary,
        'people'    => $people,
    ]);
    exit;
}

// ---------------------------------------------------------------------------
// Writes
// ---------------------------------------------------------------------------

if ($action === 'requirement-save') {
    if ($method !== 'POST' && $method !== 'PUT') {
        te_comp_fail(405, 'Method not allowed');
    }
    if (!$available) {
        te_comp_unavailable();
    }

    $id = (int) ($body['id'] ?? 0);
    $clubId = (int) ($body['club_profile_id'] ?? 0);
    $orgUnitId = (int) ($body['org_unit_id'] ?? 0);

    if ($id > 0) {
        // Owner comes from the STORED row, never the body: taking it from the
        // request would let an admin re-home somebody else's requirement onto
        // their own club and then edit it.
        try {
            $stmt = $pdo->prepare('SELECT * FROM compliance_requirements WHERE id = ?');
            $stmt->execute([$id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('compliance-gateway requirement-save select: ' . $e->getMessage());
            te_comp_fail(500, 'Could not read the requirement');
        }
        if (!$existing) {
            te_comp_fail(404, 'Requirement not found');
        }
        $clubId = (int) ($existing['club_profile_id'] ?? 0);
        $orgUnitId = (int) ($existing['org_unit_id'] ?? 0);
    }

    if (($clubId > 0) === ($orgUnitId > 0)) {
        te_comp_fail(400, 'A requirement belongs to exactly one of a club or an org unit');
    }
    if ($clubId > 0 && !te_compliance_can_admin_club($pdo, $auth, $clubId)) {
        te_comp_fail(403, 'Only a club administrator can manage requirements');
    }
    if ($orgUnitId > 0 && te_user_org_standing($pdo, $auth, $orgUnitId) !== 'org_admin') {
        te_comp_fail(403, 'Only an administrator of this org unit can manage its requirements');
    }

    $name = trim((string) ($body['name'] ?? ''));
    if ($name === '') {
        te_comp_fail(400, 'name is required');
    }
    $kind = strtolower(trim((string) ($body['kind'] ?? 'custom')));
    if (!in_array($kind, TE_COMPLIANCE_KINDS, true)) {
        te_comp_fail(400, 'kind is not one of ' . implode(', ', TE_COMPLIANCE_KINDS));
    }
    $proof = strtolower(trim((string) ($body['proof'] ?? 'attested_date')));
    if (!in_array($proof, TE_COMPLIANCE_PROOFS, true)) {
        te_comp_fail(400, 'proof is not one of ' . implode(', ', TE_COMPLIANCE_PROOFS));
    }

    $roles = [];
    foreach ((array) ($body['roles'] ?? []) as $role) {
        $role = strtolower(trim((string) $role));
        if ($role === '') {
            continue;
        }
        if (!in_array($role, TE_COMPLIANCE_STAFF_ROLES, true)) {
            te_comp_fail(400, "roles contains an unknown staff role: $role");
        }
        if (!in_array($role, $roles, true)) {
            $roles[] = $role;
        }
    }

    $validity = $body['validity_days'] ?? null;
    $validity = ($validity === null || $validity === '' || (int) $validity <= 0) ? null : (int) $validity;

    $fields = [
        'kind'          => $kind,
        'name'          => $name,
        'description'   => isset($body['description']) ? (string) $body['description'] : null,
        'proof'         => $proof,
        'proof_url'     => isset($body['proof_url']) ? (string) $body['proof_url'] : null,
        'validity_days' => $validity,
        'required'      => te_compliance_bool($body['required'] ?? true) ? 1 : 0,
        'active'        => te_compliance_bool($body['active'] ?? true) ? 1 : 0,
        'sort_order'    => (int) ($body['sort_order'] ?? 0),
        'updated_at'    => date('Y-m-d H:i:s'),
    ];

    try {
        $pdo->beginTransaction();
        if ($id > 0) {
            $set = implode(', ', array_map(static fn (string $k): string => "$k = ?", array_keys($fields)));
            $pdo->prepare("UPDATE compliance_requirements SET $set WHERE id = ?")
                ->execute(array_merge(array_values($fields), [$id]));
        } else {
            $fields['org_unit_id'] = $orgUnitId > 0 ? $orgUnitId : null;
            $fields['club_profile_id'] = $clubId > 0 ? $clubId : null;
            $fields['created_by'] = $userId ?: null;
            $cols = implode(', ', array_keys($fields));
            $marks = implode(', ', array_fill(0, count($fields), '?'));
            $pdo->prepare("INSERT INTO compliance_requirements ($cols) VALUES ($marks)")
                ->execute(array_values($fields));
            $id = te_org_last_insert_id($pdo, 'compliance_requirements_id_seq');
        }

        // The role set is replaced wholesale. A diff would leave a removed role
        // attached whenever the client sent a shorter list than it meant to; a
        // replace makes the submitted list the answer, which is what the form means.
        $pdo->prepare('DELETE FROM compliance_requirement_roles WHERE requirement_id = ?')->execute([$id]);
        if ($roles) {
            $insert = $pdo->prepare(
                'INSERT INTO compliance_requirement_roles (requirement_id, staff_role) VALUES (?, ?)'
            );
            foreach ($roles as $role) {
                $insert->execute([$id, $role]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('compliance-gateway requirement-save: ' . $e->getMessage());
        te_comp_fail(500, 'Could not save the requirement');
    }

    AuditLogger::log($pdo, $userId ?: null, 'compliance_requirement_saved', 'compliance_requirements', $id, [
        'club_profile_id' => $clubId ?: null,
        'org_unit_id'     => $orgUnitId ?: null,
        'name'            => $name,
        'kind'            => $kind,
        'proof'           => $proof,
        'validity_days'   => $validity,
        'required'        => (bool) $fields['required'],
        'active'          => (bool) $fields['active'],
        'roles'           => $roles,
    ]);

    echo json_encode(['success' => true, 'id' => $id]);
    exit;
}

if ($action === 'requirement-delete') {
    if ($method !== 'DELETE' && $method !== 'POST') {
        te_comp_fail(405, 'Method not allowed');
    }
    if (!$available) {
        te_comp_unavailable();
    }

    $id = (int) ($_GET['id'] ?? $body['id'] ?? 0);
    if ($id <= 0) {
        te_comp_fail(400, 'id is required');
    }
    try {
        $stmt = $pdo->prepare('SELECT * FROM compliance_requirements WHERE id = ?');
        $stmt->execute([$id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('compliance-gateway requirement-delete select: ' . $e->getMessage());
        te_comp_fail(500, 'Could not read the requirement');
    }
    if (!$existing) {
        te_comp_fail(404, 'Requirement not found');
    }

    $clubId = (int) ($existing['club_profile_id'] ?? 0);
    $orgUnitId = (int) ($existing['org_unit_id'] ?? 0);
    if ($clubId > 0 && !te_compliance_can_admin_club($pdo, $auth, $clubId)) {
        te_comp_fail(403, 'Only a club administrator can manage requirements');
    }
    if ($orgUnitId > 0 && te_user_org_standing($pdo, $auth, $orgUnitId) !== 'org_admin') {
        te_comp_fail(403, 'Only an administrator of this org unit can manage its requirements');
    }

    // Deactivate rather than delete once anybody has a record against it. The
    // credential is evidence that somebody completed something; deleting the
    // requirement would take the name of what they completed with it, and the
    // ON DELETE CASCADE would take the credential too.
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM person_credentials WHERE requirement_id = ?');
        $stmt->execute([$id]);
        $used = (int) $stmt->fetchColumn() > 0;

        if ($used) {
            $pdo->prepare('UPDATE compliance_requirements SET active = ?, updated_at = ? WHERE id = ?')
                ->execute([0, date('Y-m-d H:i:s'), $id]);
        } else {
            $pdo->prepare('DELETE FROM compliance_requirements WHERE id = ?')->execute([$id]);
        }
    } catch (Throwable $e) {
        error_log('compliance-gateway requirement-delete: ' . $e->getMessage());
        te_comp_fail(500, 'Could not remove the requirement');
    }

    AuditLogger::log($pdo, $userId ?: null, 'compliance_requirement_deleted', 'compliance_requirements', $id, [
        'club_profile_id' => $clubId ?: null,
        'org_unit_id'     => $orgUnitId ?: null,
        'name'            => $existing['name'] ?? null,
        'deactivated'     => $used,
    ]);

    echo json_encode(['success' => true, 'deactivated' => $used]);
    exit;
}

if ($action === 'record' || $action === 'review') {
    if ($method !== 'POST' && $method !== 'PUT') {
        te_comp_fail(405, 'Method not allowed');
    }
    if (!$available) {
        te_comp_unavailable();
    }

    $clubId = (int) ($body['club_id'] ?? 0);
    $personId = (int) ($body['user_id'] ?? 0);
    $requirementId = (int) ($body['requirement_id'] ?? 0);
    if ($clubId <= 0 || $personId <= 0 || $requirementId <= 0) {
        te_comp_fail(400, 'club_id, user_id and requirement_id are required');
    }
    if (!te_compliance_can_admin_club($pdo, $auth, $clubId)) {
        te_comp_fail(403, 'Only a club administrator can record or review compliance');
    }

    // The requirement must be one this club actually inherits. Otherwise an
    // admin could write a row against another council's requirement and it
    // would surface in that council's report.
    $inScope = false;
    foreach (te_compliance_requirements_for_club($pdo, $clubId) as $req) {
        if ($req['id'] === $requirementId) {
            $inScope = true;
            break;
        }
    }
    if (!$inScope) {
        te_comp_fail(404, 'That requirement does not apply to this club');
    }

    if ($action === 'record') {
        $completedAt = te_compliance_date_or_null($body['completed_at'] ?? null);
        if ($completedAt === null) {
            te_comp_fail(400, 'completed_at must be a date (YYYY-MM-DD)');
        }
        $result = te_credential_upsert($pdo, [
            'user_id'        => $personId,
            'requirement_id' => $requirementId,
            'status'         => 'verified',
            'completed_at'   => $completedAt,
            // An override is honoured only when it parses; a typo must not
            // silently become "no expiry".
            'expires_at'     => te_compliance_date_or_null($body['expires_at'] ?? null),
            'source'         => 'admin',
            'notes'          => $body['notes'] ?? null,
        ], $userId ?: null);
        $auditAction = 'compliance_credential_recorded';
    } else {
        $decision = strtolower(trim((string) ($body['decision'] ?? '')));
        if (!in_array($decision, ['verify', 'reject'], true)) {
            te_comp_fail(400, 'decision must be verify or reject');
        }
        $reason = trim((string) ($body['rejection_reason'] ?? ''));
        if ($decision === 'reject' && $reason === '') {
            // A rejection with no reason is a dead end for the person on the
            // other side: they are told no and cannot act on it.
            te_comp_fail(400, 'rejection_reason is required when rejecting');
        }
        $result = te_credential_upsert($pdo, [
            'user_id'          => $personId,
            'requirement_id'   => $requirementId,
            'status'           => $decision === 'verify' ? 'verified' : 'rejected',
            'rejection_reason' => $reason,
        ], $userId ?: null);
        $auditAction = $decision === 'verify' ? 'compliance_credential_verified' : 'compliance_credential_rejected';
    }

    if (!$result['ok']) {
        te_comp_fail($result['reason'] === 'requirement_not_found' ? 404 : 400,
            'Could not save the record', ['reason' => $result['reason']]);
    }

    AuditLogger::log($pdo, $userId ?: null, $auditAction, 'person_credentials', $result['id'] ?? null, [
        'subject_user_id' => $personId,
        'requirement_id'  => $requirementId,
        'club_id'         => $clubId,
        'completed_at'    => $body['completed_at'] ?? null,
        'expires_at'      => $result['expires_at'] ?? null,
        'reason'          => $body['rejection_reason'] ?? null,
    ]);

    echo json_encode(['success' => true, 'credential_id' => $result['id'], 'expires_at' => $result['expires_at']]);
    exit;
}

if ($action === 'submit') {
    if ($method !== 'POST' && $method !== 'PUT') {
        te_comp_fail(405, 'Method not allowed');
    }
    if (!$available) {
        te_comp_unavailable();
    }

    $requirementId = (int) ($body['requirement_id'] ?? 0);
    if ($requirementId <= 0) {
        te_comp_fail(400, 'requirement_id is required');
    }

    // The subject is the token, never the body. This action is the only write a
    // non-admin can make and it can only ever be about themselves.
    $applies = false;
    foreach (te_compliance_user_club_ids($pdo, $userId) as $clubId) {
        foreach (te_person_requirements($pdo, $userId, $clubId) as $req) {
            if ($req['id'] === $requirementId) {
                $applies = true;
                break 2;
            }
        }
    }
    if (!$applies) {
        te_comp_fail(404, 'That requirement does not apply to you');
    }

    $documentId = (int) ($body['document_id'] ?? 0);
    if ($documentId > 0) {
        // A document id in a request body is a claim. Attaching one you do not
        // own would put somebody else's file — with its name and its contents —
        // in front of a reviewer as if it were yours.
        try {
            $stmt = $pdo->prepare('SELECT uploaded_by FROM documents WHERE id = ?');
            $stmt->execute([$documentId]);
            $owner = $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('compliance-gateway submit document: ' . $e->getMessage());
            te_comp_fail(500, 'Could not read that document');
        }
        if ($owner === false || (int) $owner !== $userId) {
            te_comp_fail(403, 'That document is not yours');
        }
    }

    $completedAt = te_compliance_date_or_null($body['completed_at'] ?? null);
    if ($completedAt === null && $documentId <= 0) {
        te_comp_fail(400, 'Attach a document or give the date you completed it');
    }

    $result = te_credential_upsert($pdo, [
        'user_id'        => $userId,
        'requirement_id' => $requirementId,
        // 'submitted', never 'verified'. A person cannot verify their own proof;
        // that is the whole reason the review step exists.
        'status'         => 'submitted',
        'completed_at'   => $completedAt,
        'document_id'    => $documentId ?: null,
        'source'         => 'portal',
        'notes'          => $body['notes'] ?? null,
    ], $userId ?: null);

    if (!$result['ok']) {
        te_comp_fail(400, 'Could not save your submission', ['reason' => $result['reason']]);
    }

    AuditLogger::log($pdo, $userId ?: null, 'compliance_credential_submitted', 'person_credentials', $result['id'] ?? null, [
        'requirement_id' => $requirementId,
        'document_id'    => $documentId ?: null,
        'completed_at'   => $completedAt,
        'expires_at'     => $result['expires_at'] ?? null,
    ]);

    echo json_encode(['success' => true, 'credential_id' => $result['id'], 'status' => 'submitted']);
    exit;
}

te_comp_fail(400, 'Unknown action');
