<?php
/**
 * Mid-year athlete evaluations and IDP goals (CKU R76 + R77, slice 8.4).
 *
 * A narrow, purpose-built door in the same spirit as api/athlete-jersey-size.php,
 * and for the same reason: the alternative was widening a staff gateway whose
 * field whitelist also covers date_of_birth and the guardian links, which would
 * make "let a coach write a development note" and "let a coach rewrite a child's
 * record" the same request separated only by what the form happens to send.
 *
 * Every decision this file makes lives in lib/athlete_evaluations.php so it can
 * be executed by a test. What is HERE is only the HTTP shape: routing, status
 * codes, audit, and the degraded answers for a production that has this code but
 * not migration 086 yet.
 *
 * ACTIONS
 *   GET    ?action=list&athlete_id=N     read  — AthleteScope::userCanAccessAthlete
 *   GET    ?action=criteria&athlete_id=N write — te_athlete_evaluation_can_write
 *   POST   ?action=create                write — te_athlete_evaluation_can_write
 *   PUT    ?action=update                write — plus author-or-club-admin
 *   DELETE ?action=delete&id=N           club admin only
 *
 * `criteria` is on the write gate, not the read gate, because it exposes the
 * club's own scoring configuration and only somebody about to fill in the form
 * has any use for it. A parent reading the list gets the criterion names that
 * were actually used, copied onto their child's rows.
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/AthleteScope.php';
require_once __DIR__ . '/../lib/AuditLogger.php';
require_once __DIR__ . '/../lib/athlete_evaluations.php';

/** Emit a JSON error and stop. */
function te_eval_fail(int $status, string $message, array $extra = []): void
{
    http_response_code($status);
    echo json_encode(array_merge(['success' => false, 'error' => $message], $extra));
    exit;
}

/**
 * The one sentence a write gets when migration 086 has not been applied yet.
 *
 * A 503 rather than a 500, and a sentence rather than a stack trace: `main` is
 * shared and migrations are applied by hand, so this is an expected state for a
 * few hours or days and the coach on the other end needs to know their work was
 * not saved and why.
 */
function te_eval_unavailable(): void
{
    te_eval_fail(503, 'Evaluations are not switched on for this club yet. The database update for this feature has not been applied — no evaluation was saved.', [
        'available' => false,
    ]);
}

try {
    $pdo = Database::getInstance()->getConnection();
} catch (Exception $e) {
    error_log('athlete-evaluations: DB connection failed: ' . $e->getMessage());
    te_eval_fail(500, 'Database connection failed');
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
    $action = $method === 'GET' ? 'list' : ($method === 'DELETE' ? 'delete' : 'create');
}

// ---------------------------------------------------------------------------
// Reads
// ---------------------------------------------------------------------------

if ($action === 'list' || $action === 'criteria') {
    $athleteId = (int) ($_GET['athlete_id'] ?? 0);
    if ($athleteId <= 0) {
        te_eval_fail(400, 'athlete_id is required');
    }

    if ($action === 'criteria') {
        // Authorization BEFORE the club's configuration is read.
        if (!te_athlete_evaluation_can_write($pdo, $auth, $athleteId)) {
            te_eval_fail(403, 'Only a coach of this athlete or a club admin can record an evaluation');
        }
        $criteria = te_athlete_evaluation_criteria($pdo, $athleteId);
        echo json_encode([
            'success'   => true,
            'available' => te_athlete_evaluation_tables_present($pdo),
            'criteria'  => $criteria['criteria'],
            'source'    => $criteria['source'],
        ]);
        exit;
    }

    if (!te_athlete_evaluation_can_read($pdo, $auth, $athleteId)) {
        te_eval_fail(403, 'Access denied');
    }

    // `available: false` is NOT "no evaluations yet" — an empty list and a
    // missing feature are opposite answers, and the panel renders them
    // differently on purpose.
    if (!te_athlete_evaluation_tables_present($pdo)) {
        echo json_encode([
            'success'      => true,
            'available'    => false,
            'evaluations'  => [],
            'can_evaluate' => false,
            'can_delete'   => false,
        ]);
        exit;
    }

    echo json_encode([
        'success'      => true,
        'available'    => true,
        'evaluations'  => te_athlete_evaluation_list($pdo, $athleteId),
        'can_evaluate' => te_athlete_evaluation_can_write($pdo, $auth, $athleteId),
        'can_delete'   => te_athlete_evaluation_is_club_admin($pdo, $auth, $athleteId),
        'viewer_id'    => $userId,
    ]);
    exit;
}

// ---------------------------------------------------------------------------
// Writes
// ---------------------------------------------------------------------------

/**
 * Validate the fields shared by create and update.
 *
 * @return array{evaluated_at:string, season_label:string, team_id:?int, notes:?string, idp_goals:array, scores:array}
 */
function te_eval_validated_payload(PDO $pdo, array $body, int $athleteId): array
{
    $evaluatedAt = trim((string) ($body['evaluated_at'] ?? ''));
    if ($evaluatedAt === '') {
        te_eval_fail(400, 'evaluated_at is required');
    }
    // Stored and compared as the submitted date-only string; never parsed into a
    // DateTime here. See the date-only rule in CLAUDE.md.
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $evaluatedAt)) {
        te_eval_fail(422, 'evaluated_at must be YYYY-MM-DD');
    }

    $season = trim((string) ($body['season_label'] ?? ''));
    if ($season === '') {
        te_eval_fail(400, 'season_label is required');
    }
    if (mb_strlen($season) > 60) {
        te_eval_fail(422, 'season_label is too long');
    }

    $teamId = isset($body['team_id']) && $body['team_id'] !== '' && $body['team_id'] !== null
        ? (int) $body['team_id'] : null;
    if ($teamId !== null) {
        // The team is a claim in the request body, so it is checked rather than
        // trusted — otherwise an evaluation could be filed against a team the
        // athlete has never played for, in a club nobody involved belongs to.
        $stmt = $pdo->prepare(
            'SELECT 1 FROM team_members WHERE athlete_id = ? AND team_id = ? LIMIT 1'
        );
        $stmt->execute([$athleteId, $teamId]);
        if ($stmt->fetch() === false) {
            te_eval_fail(422, 'That athlete is not on the selected team');
        }
    }

    $goals = te_athlete_evaluation_normalize_goals($body['idp_goals'] ?? null);
    if ($goals['error'] !== null) {
        te_eval_fail(422, $goals['error']);
    }

    $notes = isset($body['notes']) ? trim((string) $body['notes']) : '';

    return [
        'evaluated_at' => $evaluatedAt,
        'season_label' => $season,
        'team_id'      => $teamId,
        'notes'        => $notes !== '' ? $notes : null,
        'idp_goals'    => $goals['goals'],
        'scores'       => $body['scores'] ?? [],
    ];
}

if ($action === 'create') {
    if ($method !== 'POST') {
        te_eval_fail(405, 'Method not allowed');
    }

    $athleteId = (int) ($body['athlete_id'] ?? 0);
    if ($athleteId <= 0) {
        te_eval_fail(400, 'athlete_id is required');
    }
    if (!te_athlete_evaluation_can_write($pdo, $auth, $athleteId)) {
        te_eval_fail(403, 'Only a coach of this athlete or a club admin can record an evaluation');
    }
    if (!te_athlete_evaluation_tables_present($pdo)) {
        te_eval_unavailable();
    }

    $payload = te_eval_validated_payload($pdo, $body, $athleteId);

    try {
        $id = te_athlete_evaluation_create($pdo, $athleteId, $userId, $payload);
    } catch (Throwable $e) {
        error_log('athlete-evaluations: create failed: ' . $e->getMessage());
        te_eval_fail(500, 'Could not save the evaluation');
    }

    AuditLogger::log($pdo, $userId ?: null, 'athlete_evaluation_created', 'athletes', $athleteId, [
        'evaluation_id' => $id,
        'season_label'  => $payload['season_label'],
        'evaluated_at'  => $payload['evaluated_at'],
        'team_id'       => $payload['team_id'],
        'goal_count'    => count($payload['idp_goals']),
    ]);

    echo json_encode(['success' => true, 'available' => true, 'id' => $id]);
    exit;
}

if ($action === 'update') {
    if ($method !== 'PUT' && $method !== 'POST') {
        te_eval_fail(405, 'Method not allowed');
    }

    $id = (int) ($body['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) {
        te_eval_fail(400, 'id is required');
    }
    if (!te_athlete_evaluation_tables_present($pdo)) {
        te_eval_unavailable();
    }

    $existing = te_athlete_evaluation_find($pdo, $id);
    if ($existing === null) {
        te_eval_fail(404, 'Evaluation not found');
    }
    $athleteId = (int) $existing['athlete_id'];

    if (!te_athlete_evaluation_can_write($pdo, $auth, $athleteId)) {
        te_eval_fail(403, 'Only a coach of this athlete or a club admin can record an evaluation');
    }
    // A second coach of the same team passes the gate above, which is right for
    // recording their OWN evaluation and wrong for rewriting somebody else's
    // words. Editing is the author's, or a club admin's.
    if ((int) $existing['evaluator_id'] !== $userId
        && !te_athlete_evaluation_is_club_admin($pdo, $auth, $athleteId)) {
        te_eval_fail(403, 'Only the coach who wrote this evaluation, or a club admin, can change it');
    }

    $payload = te_eval_validated_payload($pdo, $body, $athleteId);

    try {
        te_athlete_evaluation_update($pdo, $id, $payload);
    } catch (Throwable $e) {
        error_log('athlete-evaluations: update failed: ' . $e->getMessage());
        te_eval_fail(500, 'Could not save the evaluation');
    }

    AuditLogger::log($pdo, $userId ?: null, 'athlete_evaluation_updated', 'athletes', $athleteId, [
        'evaluation_id' => $id,
        'season_label'  => $payload['season_label'],
        'goal_count'    => count($payload['idp_goals']),
    ]);

    echo json_encode(['success' => true, 'available' => true, 'id' => $id]);
    exit;
}

if ($action === 'delete') {
    if ($method !== 'DELETE' && $method !== 'POST') {
        te_eval_fail(405, 'Method not allowed');
    }

    $id = (int) ($_GET['id'] ?? $body['id'] ?? 0);
    if ($id <= 0) {
        te_eval_fail(400, 'id is required');
    }
    if (!te_athlete_evaluation_tables_present($pdo)) {
        te_eval_unavailable();
    }

    $existing = te_athlete_evaluation_find($pdo, $id);
    if ($existing === null) {
        te_eval_fail(404, 'Evaluation not found');
    }
    $athleteId = (int) $existing['athlete_id'];

    // Deletion is club admin only. A coach who disagrees with a past evaluation
    // records a new one; removing a child's development history is an
    // administrative act, and it leaves an audit row saying who did it.
    if (!te_athlete_evaluation_is_club_admin($pdo, $auth, $athleteId)) {
        te_eval_fail(403, 'Only a club admin can delete an evaluation');
    }

    try {
        te_athlete_evaluation_delete($pdo, $id);
    } catch (Throwable $e) {
        error_log('athlete-evaluations: delete failed: ' . $e->getMessage());
        te_eval_fail(500, 'Could not delete the evaluation');
    }

    AuditLogger::log($pdo, $userId ?: null, 'athlete_evaluation_deleted', 'athletes', $athleteId, [
        'evaluation_id'  => $id,
        'written_by'     => (int) $existing['evaluator_id'],
    ]);

    echo json_encode(['success' => true, 'available' => true, 'id' => $id]);
    exit;
}

te_eval_fail(400, 'Unknown action');
