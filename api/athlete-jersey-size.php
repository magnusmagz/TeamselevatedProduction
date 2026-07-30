<?php
/**
 * Read and write ONE field: athletes.jersey_size.
 *
 * WHY A DEDICATED ENDPOINT RATHER THAN THE ATHLETE GATEWAY
 * `legacy/athletes-gateway.php` (PUT) already authorizes guardians — it gates on
 * AthleteScope::userCanAccessAthlete, whose 4th branch is "guardian of the
 * athlete". So the parent portal *could* have posted a jersey size there with no
 * backend work at all. It is deliberately not doing that: that handler's field
 * whitelist also covers first_name, date_of_birth, the home address, the guardian
 * links and the emergency contacts, and it is reached with the same token the
 * portal already holds. Pointing a parent-facing screen at it would make "let a
 * parent fix a t-shirt size" and "let a parent rewrite their child's date of
 * birth" the same request, separated only by what the UI happens to send.
 *
 * This file is the narrow door instead: one column, one CHECK-constrained
 * vocabulary, audit-logged. What the portal can write is bounded by the endpoint,
 * not by the form.
 *
 * (The wider exposure on the legacy PUT predates this file and is untouched here.)
 *
 * WHY THE SAME FIELD IS EDITABLE FROM TWO PLACES AT ONCE
 * Staff edit jersey size on the athlete form / roster slide-out; crew edit it
 * here. There is nothing to reconcile between them because there is nothing to
 * reconcile *with*: jersey size is one column on `athletes`, so both surfaces are
 * writing the same cell and each read sees the other's last write. Sync is a
 * property of the schema (migration 054 deliberately put size on the athlete and
 * not on team_members), not something this endpoint maintains. Do not add a
 * portal-side copy of the value — that is what would create a sync problem.
 *
 * AUTHORIZATION
 * Delegated wholesale to AthleteScope::userCanAccessAthlete: super admin, club
 * admin of the athlete's club, a coach of one of their teams, or a guardian of
 * the athlete. That is already the codebase's single answer to "may this person
 * see this child", and a t-shirt size is strictly less sensitive than what that
 * predicate already gates. Never re-implement the guardian check inline here.
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/AthleteScope.php';
require_once __DIR__ . '/../lib/AuditLogger.php';
require_once __DIR__ . '/../lib/jersey_size.php';

/** Emit a JSON error and stop. */
function te_jersey_fail(int $status, string $message, array $extra = []): void
{
    http_response_code($status);
    echo json_encode(array_merge(['success' => false, 'error' => $message], $extra));
    exit;
}

try {
    $pdo = Database::getInstance()->getConnection();
} catch (Exception $e) {
    error_log('athlete-jersey-size: DB connection failed: ' . $e->getMessage());
    te_jersey_fail(500, 'Database connection failed');
}

$auth = AuthMiddleware::requireAuth();

$method = $_SERVER['REQUEST_METHOD'];
$body = [];
if ($method !== 'GET') {
    $raw = file_get_contents('php://input');
    $body = $raw ? (json_decode($raw, true) ?: []) : [];
}

$athleteId = (int) ($_GET['athlete_id'] ?? $body['athlete_id'] ?? 0);
if ($athleteId <= 0) {
    te_jersey_fail(400, 'athlete_id is required');
}

// Authorization before any athlete data is read or written.
if (!AthleteScope::userCanAccessAthlete($pdo, $auth, $athleteId)) {
    te_jersey_fail(403, 'Access denied');
}

// Soft-deleted athletes are invisible everywhere else; keep them unwritable too.
$stmt = $pdo->prepare(
    'SELECT jersey_size FROM athletes WHERE id = ? AND active_status = true AND deleted_at IS NULL'
);
$stmt->execute([$athleteId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if ($row === false) {
    te_jersey_fail(404, 'Athlete not found');
}
$current = $row['jersey_size'] !== null && $row['jersey_size'] !== ''
    ? (string) $row['jersey_size']
    : null;

/** The response shape both GET and the write path return. */
function te_jersey_payload(int $athleteId, ?string $size): array
{
    return [
        'success'      => true,
        'athlete_id'   => $athleteId,
        'jersey_size'  => $size,
        'jersey_label' => $size !== null ? (TE_JERSEY_SIZE_LABELS[$size] ?? $size) : null,
    ];
}

if ($method === 'GET') {
    echo json_encode(te_jersey_payload($athleteId, $current));
    exit;
}

if ($method !== 'PUT' && $method !== 'POST') {
    te_jersey_fail(405, 'Method not allowed');
}

if (!array_key_exists('jersey_size', $body)) {
    te_jersey_fail(400, 'jersey_size is required');
}

$submitted = $body['jersey_size'];

// The size IS this request, so an unreadable value is refused rather than
// collapsed to NULL — answering 200 to "set this to Large" while storing nothing
// is the silent-failure shape this codebase keeps rediscovering. See
// te_classify_jersey_size_submission() for why that differs from the athlete form.
$decision = te_classify_jersey_size_submission($submitted);
if ($decision['action'] === TE_JERSEY_SUBMISSION_INVALID) {
    te_jersey_fail(422, 'Unrecognized jersey size', [
        'submitted'       => is_scalar($submitted) ? (string) $submitted : null,
        'accepted_sizes'  => TE_JERSEY_SIZES,
        'accepted_labels' => te_jersey_size_options(),
    ]);
}
$normalized = $decision['code'];

if ($normalized === $current) {
    // Nothing changed — don't write, and don't log a non-event to the audit trail.
    echo json_encode(te_jersey_payload($athleteId, $current) + ['unchanged' => true]);
    exit;
}

try {
    $stmt = $pdo->prepare(
        'UPDATE athletes SET jersey_size = ?, updated_at = NOW()
         WHERE id = ? AND active_status = true AND deleted_at IS NULL'
    );
    $stmt->execute([$normalized, $athleteId]);
} catch (PDOException $e) {
    // 23514 = CHECK violation. Unreachable while the value has been through
    // te_normalize_jersey_size(), which can only return a code from the same list
    // the constraint holds — but if the two ever drift, say so instead of
    // reporting a save that did not happen.
    error_log('athlete-jersey-size: update failed: ' . $e->getMessage());
    te_jersey_fail(500, 'Could not save jersey size');
}

AuditLogger::log(
    $pdo,
    (int) $auth->getUserId() ?: null,
    'athlete_jersey_size_updated',
    'athletes',
    $athleteId,
    ['from' => $current, 'to' => $normalized]
);

echo json_encode(te_jersey_payload($athleteId, $normalized));
