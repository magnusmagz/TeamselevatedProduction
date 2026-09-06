<?php
/**
 * Division and national compliance rollups (GOTR G5). GET only, read only.
 *
 *   ?view=units                                   the caller's org units (what the nav offers)
 *   ?view=summary&org_unit_id=N[&requirement_id=R] per-council counts + total, highest risk first
 *   ?view=trend&org_unit_id=N                     per-council expiries by month, next 6 months
 *   ?view=club&org_unit_id=N&club_id=C            one council's per-person roll call
 *
 * AUTHORIZATION — te_user_org_standing() at the org unit asked about, and
 * NOTHING ELSE. `org_admin` and `org_viewer` both read; the only refusal is no
 * standing at all. A super admin passes everywhere (te_user_org_standing says
 * so). Standing inherits DOWN the tree and never up or sideways, so a division
 * admin asking about a sibling division — or about national — is a 403, and
 * the drill-down checks the club against the SAME unit's descendant set so an
 * in-scope org_unit_id cannot be paired with an out-of-scope club_id.
 *
 * ⚠️ index.php performs NO authentication. This file is the whole of the access
 * control for everything it answers.
 *
 * WHY THE VIEWS ARE HERE AND NOT ON compliance-gateway.php
 * That file's actions gate on te_compliance_can_admin_club, which refuses an
 * org_viewer by design. Putting a viewer-readable action beside eight
 * admin-only ones is how one of them ends up on the wrong predicate. This file
 * has one predicate, and ComplianceRollupTest asserts it is the only one and
 * that nothing here writes.
 *
 * Behind te_feature_enabled('COMPLIANCE'), checked before anything is read.
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/org_scope.php';
require_once __DIR__ . '/../lib/compliance.php';
require_once __DIR__ . '/../lib/compliance_origin.php';
require_once __DIR__ . '/../lib/compliance_rollup.php';
require_once __DIR__ . '/../lib/feature_flags.php';

function te_rollup_fail(int $status, string $message, array $extra = []): void
{
    http_response_code($status);
    echo json_encode(array_merge(['success' => false, 'error' => $message], $extra));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    te_rollup_fail(405, 'Method not allowed');
}

try {
    $pdo = Database::getInstance()->getConnection();
} catch (Exception $e) {
    error_log('compliance-rollup: DB connection failed: ' . $e->getMessage());
    te_rollup_fail(500, 'Database connection failed');
}

$auth = AuthMiddleware::requireAuth();

if (!te_feature_enabled('COMPLIANCE')) {
    http_response_code(503);
    echo json_encode(te_feature_disabled_response('COMPLIANCE'));
    exit;
}

$view = strtolower(trim((string) ($_GET['view'] ?? 'summary')));
$available = te_org_tables_present($pdo) && te_compliance_tables_present($pdo);

// ---------------------------------------------------------------------------
// units — no org_unit_id: the caller's own grants, for the nav and the picker.
// A super admin gets the whole tree as org_admin, which is what
// te_user_org_standing would answer for every one of them anyway.
// ---------------------------------------------------------------------------
if ($view === 'units') {
    $units = [];
    if ($auth->isSuperAdmin()) {
        foreach (te_org_unit_tree($pdo) as $unit) {
            $units[] = [
                'org_unit_id' => $unit['id'], 'role' => 'org_admin', 'name' => $unit['name'],
                'type' => $unit['type'], 'path' => $unit['path'], 'depth' => $unit['depth'],
            ];
        }
    } else {
        foreach (te_org_units_for_user($pdo, (int) $auth->getUserId()) as $grant) {
            $units[] = [
                'org_unit_id' => $grant['org_unit_id'], 'role' => $grant['role'], 'name' => $grant['name'],
                'type' => $grant['type'], 'path' => $grant['path'], 'depth' => $grant['depth'],
            ];
        }
    }
    echo json_encode(['success' => true, 'available' => $available, 'units' => $units]);
    exit;
}

// ---------------------------------------------------------------------------
// Everything else needs an org unit and standing at it. The check happens
// BEFORE any view dispatches, so a view added below cannot skip it.
// ---------------------------------------------------------------------------
$orgUnitId = (int) ($_GET['org_unit_id'] ?? 0);
if ($orgUnitId <= 0) {
    te_rollup_fail(400, 'org_unit_id is required');
}

$standing = te_user_org_standing($pdo, $auth, $orgUnitId);
if ($standing === null) {
    // A missing unit and a unit outside the caller's tree answer the same
    // thing, on purpose: which org unit ids exist is not the caller's to learn.
    te_rollup_fail(403, 'You do not have standing at this organization');
}

$unit = te_org_unit($pdo, $orgUnitId);
$today = te_compliance_today();

if ($view === 'summary') {
    $requirementId = (int) ($_GET['requirement_id'] ?? 0);
    $rollup = $available
        ? te_compliance_rollup($pdo, $orgUnitId, $today, $requirementId > 0 ? $requirementId : null)
        : ['councils' => [], 'total' => ['staff_total' => 0, 'compliant' => 0, 'expiring_30' => 0, 'expired' => 0, 'missing' => 0]];

    // The requirements that apply anywhere in this tree, for the filter
    // select: the unit's own and its ancestors' (inherited by every council),
    // plus any council's own rows. Read, never written.
    $requirements = [];
    if ($available) {
        try {
            $scope = te_org_descendant_club_ids_sql([$orgUnitId]);
            $stmt = $pdo->prepare(
                'SELECT r.id, r.name, r.kind, r.required, r.org_unit_id, r.club_profile_id'
                . ' FROM compliance_requirements r'
                . ' WHERE r.active = ' . te_compliance_true_literal($pdo)
                . ' AND (r.club_profile_id IN (' . $scope['sql'] . ')'
                . '   OR r.org_unit_id IN (SELECT a.id FROM org_units a JOIN org_units o'
                . "        ON o.path LIKE a.path || '%' WHERE o.path LIKE ? || '%'))"
                . ' ORDER BY r.sort_order, r.name, r.id'
            );
            $stmt->execute(array_merge($scope['params'], [(string) ($unit['path'] ?? '')]));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $requirements[] = [
                    'id'              => (int) $row['id'],
                    'name'            => (string) $row['name'],
                    'kind'            => (string) $row['kind'],
                    'required'        => te_compliance_bool($row['required']),
                    'org_unit_id'     => $row['org_unit_id'] === null ? null : (int) $row['org_unit_id'],
                    'club_profile_id' => $row['club_profile_id'] === null ? null : (int) $row['club_profile_id'],
                ];
            }
        } catch (Throwable $e) {
            error_log('compliance-rollup requirements: ' . $e->getMessage());
        }
    }

    echo json_encode([
        'success'        => true,
        'available'      => $available,
        'standing'       => $standing,
        'unit'           => $unit,
        'units'          => $available ? te_compliance_rollup_units($pdo, $orgUnitId) : [],
        'as_of'          => $today,
        'requirement_id' => $requirementId > 0 ? $requirementId : null,
        'requirements'   => $requirements,
        'total'          => $rollup['total'],
        'councils'       => $rollup['councils'],
    ]);
    exit;
}

if ($view === 'trend') {
    $trend = $available
        ? te_compliance_rollup_trend($pdo, $orgUnitId, $today)
        : ['months' => te_compliance_rollup_months($today), 'councils' => []];
    echo json_encode([
        'success'   => true,
        'available' => $available,
        'standing'  => $standing,
        'unit'      => $unit,
        'as_of'     => $today,
        'months'    => $trend['months'],
        'councils'  => $trend['councils'],
    ]);
    exit;
}

if ($view === 'club') {
    $clubId = (int) ($_GET['club_id'] ?? 0);
    if ($clubId <= 0) {
        te_rollup_fail(400, 'club_id is required');
    }
    // Missing is a 404; existing-but-elsewhere is a 403. The org unit was
    // already established as the caller's, so the 404 confirms nothing about
    // clubs they cannot see beyond "not under yours".
    $scopeAnswer = te_compliance_rollup_club_scope($pdo, $orgUnitId, $clubId);
    if ($scopeAnswer === 'missing') {
        te_rollup_fail(404, 'Club not found');
    }
    if ($scopeAnswer !== 'in') {
        te_rollup_fail(403, 'That club is not under this organization');
    }

    $stmt = $pdo->prepare('SELECT id, name FROM club_profile WHERE id = ?');
    $stmt->execute([$clubId]);
    $club = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['id' => $clubId, 'name' => 'Club ' . $clubId];

    $people = [];
    $summary = ['total' => 0, 'compliant' => 0, 'expiring_30' => 0, 'expired' => 0, 'missing' => 0];
    if ($available) {
        // The same two functions the council's own screen renders from. The
        // status is reused, never re-derived, so this drill-down cannot say
        // something different from what the council admin sees.
        foreach (te_compliance_club_staff($pdo, $clubId) as $person) {
            $status = te_compliance_status($pdo, (int) $person['user_id'], $clubId, $today);
            $rollup = $status['rollup'];
            $summary['total']++;
            $summary['compliant'] += $rollup['compliant'] ? 1 : 0;
            $summary['expiring_30'] += $rollup['expiring_30'] > 0 ? 1 : 0;
            $summary['expired'] += $rollup['expired'] > 0 ? 1 : 0;
            $summary['missing'] += $rollup['missing'] > 0 ? 1 : 0;

            $decorated = te_compliance_decorate_origins(
                $pdo,
                array_map(static fn (array $r): array => $r['requirement'], $status['requirements']),
                $clubId
            );
            foreach ($status['requirements'] as $index => $row) {
                $status['requirements'][$index]['requirement'] = $decorated[$index] ?? $row['requirement'];
            }
            $people[] = $person + [
                'staff_roles'  => te_compliance_staff_roles($pdo, (int) $person['user_id'], $clubId),
                'rollup'       => $rollup,
                'requirements' => $status['requirements'],
            ];
        }
    }

    echo json_encode([
        'success'   => true,
        'available' => $available,
        'standing'  => $standing,
        'unit'      => $unit,
        'club'      => ['id' => (int) $club['id'], 'name' => (string) $club['name']],
        'as_of'     => $today,
        'summary'   => $summary,
        'people'    => $people,
    ]);
    exit;
}

te_rollup_fail(400, 'view must be units, summary, trend or club');
