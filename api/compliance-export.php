<?php
/**
 * Download a club's compliance roll call as CSV. GET only.
 *
 *   ?club_id=100                    -> every staff member × every requirement
 *   ?club_id=100&filter=expiring    -> only people with something expiring in 30 days
 *   ?org_unit_id=2[&filter=…]       -> the same, across every council under the unit,
 *                                      with the council name as the first column (G5)
 *
 * AUTHORIZATION — two branches, two predicates, on purpose.
 *
 * ?org_unit_id: te_user_org_standing() at that unit — org_admin OR org_viewer.
 * The viewer role exists to read rollups, and a downloadable rollup is a
 * rollup. Standing inherits down and never sideways, so a division viewer
 * cannot pull a sibling division's file.
 *
 * ?club_id: staff only, via te_compliance_can_admin_club(): club admin of
 * the club, or org_admin over the tier it hangs from. Deliberately the SAME
 * predicate as the `club-status` screen it exports, and deliberately NOT the
 * wider te_is_club_staff: a coach is team-scoped and this file is every other
 * member of staff's background-check history. Same shape as
 * te_team_roster_staff_standing() versus tpg_requireTeamViewAccess() — and the
 * reason is stronger here, because a downloaded file outlives both the session
 * and the permission, and this one names people's certificates and expiry dates.
 *
 * The download is audited (`compliance_exported`) with the filter and the row
 * count. Nothing is mutated; a bulk export of staff compliance is exactly the
 * event a council needs to be able to reconstruct for an insurer later.
 *
 * ⚠️ THE CAP IS REPORTED, NEVER SILENT. A CSV is a download — nothing is
 * rendered back to the person who asked — so a file that stops at row 1000 is
 * indistinguishable from a club with 1000 rows. The notice reaches the audit
 * row, the X-Compliance-Export-Truncated response header AND the UI.
 *
 * Behind te_feature_enabled('COMPLIANCE'), checked before anything is read.
 */

require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/AuditLogger.php';
require_once __DIR__ . '/../lib/org_scope.php';
require_once __DIR__ . '/../lib/compliance.php';
require_once __DIR__ . '/../lib/compliance_export.php';
require_once __DIR__ . '/../lib/feature_flags.php';

/** Errors are JSON even though success is CSV — the caller reads them on !ok. */
function te_compliance_export_fail(int $status, string $message, array $extra = []): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => false, 'error' => $message], $extra));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    te_compliance_export_fail(405, 'Method not allowed');
}

try {
    $pdo = Database::getInstance()->getConnection();
} catch (Exception $e) {
    error_log('compliance-export: DB connection failed: ' . $e->getMessage());
    te_compliance_export_fail(500, 'Database connection failed');
}

$auth = AuthMiddleware::requireAuth();

if (!te_feature_enabled('COMPLIANCE')) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(te_feature_disabled_response('COMPLIANCE'));
    exit;
}

$clubId = (int) ($_GET['club_id'] ?? 0);
$orgUnitId = (int) ($_GET['org_unit_id'] ?? 0);
if ($clubId <= 0 && $orgUnitId <= 0) {
    te_compliance_export_fail(400, 'club_id or org_unit_id is required');
}

// An unrecognised filter is refused rather than quietly treated as "everyone".
// Silently ignoring a typo would hand somebody the whole club labelled as the
// filtered subset — the same reasoning as `include=` on the roster export.
$filter = strtolower(trim((string) ($_GET['filter'] ?? '')));
if ($filter !== '' && !in_array($filter, TE_COMPLIANCE_EXPORT_FILTERS, true)) {
    te_compliance_export_fail(400, 'filter must be compliant, expiring, expired or missing');
}

// Authorization BEFORE anything is read.
if ($orgUnitId > 0) {
    // Any standing reads; only NO standing is refused. `!== 'org_admin'` here
    // would lock out every org_viewer, and this file is the viewer's product.
    if (te_user_org_standing($pdo, $auth, $orgUnitId) === null) {
        te_compliance_export_fail(403, 'You do not have standing at this organization');
    }
} elseif (!te_compliance_can_admin_club($pdo, $auth, $clubId)) {
    te_compliance_export_fail(403, 'Only a club administrator can download the compliance report');
}

if (!te_compliance_tables_present($pdo)) {
    te_compliance_export_fail(503, 'Compliance is not switched on yet. The database update for this feature has not been applied.', [
        'available' => false,
    ]);
}

$today = te_compliance_today();

if ($orgUnitId > 0) {
    $unit = te_org_unit($pdo, $orgUnitId);
    $sheet = te_compliance_export_org_sheet($pdo, $orgUnitId, $filter, $today);
    $notice = te_compliance_export_truncation_notice($sheet);
    $filename = te_compliance_export_filename((string) ($unit['name'] ?? 'organization'), $filter, $today);

    AuditLogger::log($pdo, (int) $auth->getUserId(), 'compliance_exported', 'org_units', $orgUnitId, [
        'filter'    => $filter === '' ? null : $filter,
        'councils'  => $sheet['councils'],
        'row_count' => count($sheet['rows']),
        'people'    => $sheet['people'],
        'truncated' => $notice !== null,
        'notice'    => $notice,
    ]);
} else {
    $stmt = $pdo->prepare('SELECT name FROM club_profile WHERE id = ?');
    $stmt->execute([$clubId]);
    $clubName = (string) ($stmt->fetchColumn() ?: 'club');

    $sheet = te_compliance_export_sheet($pdo, $clubId, $filter, $today);
    $notice = te_compliance_export_truncation_notice($sheet);
    $filename = te_compliance_export_filename($clubName, $filter, $today);

    AuditLogger::log($pdo, (int) $auth->getUserId(), 'compliance_exported', 'club_profile', $clubId, [
        'filter'    => $filter === '' ? null : $filter,
        'row_count' => count($sheet['rows']),
        'people'    => $sheet['people'],
        'truncated' => $notice !== null,
        'notice'    => $notice,
    ]);
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

if ($notice !== null) {
    // Header values are single-line. The notice is assembled from integers and
    // fixed text, but strip control characters anyway rather than trusting that.
    header('X-Compliance-Export-Truncated: ' . preg_replace('/[\r\n]+/', ' ', $notice));
    header('Access-Control-Expose-Headers: X-Compliance-Export-Truncated');
}

$out = fopen('php://output', 'w');
// Excel reads a CSV as the local codepage unless the file opens with a BOM, so
// without this every accented name (José, Muñoz) arrives mangled.
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, $sheet['headers']);
foreach ($sheet['rows'] as $row) {
    fputcsv($out, $row);
}
fclose($out);
