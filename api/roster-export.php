<?php
/**
 * Download a team's roster as CSV. GET only.
 *
 *   ?team_id=123                 -> athletes only
 *   ?team_id=123&include=crew    -> athletes + their crew's contact details
 *
 * AUTHORIZATION — staff only, via te_team_roster_staff_standing().
 * That is the same predicate legacy/team-players-gateway.php gates roster EDITS
 * on, not the wider one it gates viewing on. A parent or player on the team can
 * see the roster on screen and cannot download it: the file outlives the session
 * and the permission, and the crew flavour is a contact list for other people's
 * families. Never swap this for the view predicate to make a portal screen work.
 *
 * The download is audited (`roster_exported`) with the flavour and the row count.
 * Nothing is mutated, but a bulk export of minors' details is precisely the event
 * a club needs to be able to reconstruct later — same reasoning as
 * `portal_login_link_sent`.
 */

require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/AuditLogger.php';
require_once __DIR__ . '/../lib/team_roster_scope.php';
require_once __DIR__ . '/../lib/roster_export.php';

/** Errors are JSON even though success is CSV — the caller reads them on !ok. */
function te_roster_export_fail(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    te_roster_export_fail(405, 'Method not allowed');
}

try {
    $pdo = Database::getInstance()->getConnection();
} catch (Exception $e) {
    error_log('roster-export: DB connection failed: ' . $e->getMessage());
    te_roster_export_fail(500, 'Database connection failed');
}

$auth = AuthMiddleware::requireAuth();

$teamId = (int) ($_GET['team_id'] ?? 0);
if ($teamId <= 0) {
    te_roster_export_fail(400, 'team_id is required');
}

// An unrecognised value is refused rather than quietly treated as 'athletes':
// the difference between the two flavours is whether families' contact details
// leave the building, so a typo must not decide it.
$flavour = (string) ($_GET['include'] ?? 'athletes');
if (!in_array($flavour, ['athletes', 'crew'], true)) {
    te_roster_export_fail(400, "include must be 'athletes' or 'crew'");
}

switch (te_team_roster_staff_standing($pdo, $auth, $teamId)) {
    case TE_TEAM_ROSTER_OK:
        break;
    case TE_TEAM_ROSTER_NOT_FOUND:
        te_roster_export_fail(404, 'Team not found');
        // no break — te_roster_export_fail exits
    default:
        te_roster_export_fail(403, 'You do not have permission to download this team\'s roster');
}

$stmt = $pdo->prepare('SELECT name FROM teams WHERE id = ?');
$stmt->execute([$teamId]);
$teamName = (string) ($stmt->fetchColumn() ?: 'team');

$today = date('Y-m-d');
$sheet = te_roster_export_sheet($pdo, $teamId, $flavour, $today);
$filename = te_roster_export_filename($teamName, $flavour, $today);
$notice = te_roster_export_truncation_notice($sheet);

AuditLogger::log(
    $pdo,
    (int) $auth->getUserId(),
    'roster_exported',
    'team',
    $teamId,
    [
        'include'      => $flavour,
        'row_count'    => count($sheet['rows']),
        'truncated'    => $notice !== null,
        'notice'       => $notice,
    ]
);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

// The caps are reported, never silent: the browser is downloading a file, so
// this header is the only channel by which the person who pressed the button
// can learn the roster did not fit. The UI reads it and says so.
if ($notice !== null) {
    // Header values are single-line; the notice is assembled from integers and
    // fixed text, but strip control characters anyway rather than trusting that.
    header('X-Roster-Export-Truncated: ' . preg_replace('/[\r\n]+/', ' ', $notice));
    header('Access-Control-Expose-Headers: X-Roster-Export-Truncated');
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
