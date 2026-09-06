<?php
/**
 * Referee feedback from the coaches portal (CKU R68, slice 8.6, migration 095).
 *
 * Everything api/referee-feedback.php decides lives here so a test can execute
 * it against a real (SQLite) fixture instead of grepping the gateway — the
 * reason lib/athlete_evaluations.php and lib/tryout_coach_invite.php are shaped
 * the same way. What stays in the gateway is HTTP: routing, status codes, the
 * audit rows and the CSV headers.
 *
 * ── Who may do what ─────────────────────────────────────────────────────────
 *
 *   WRITE  te_event_staff_standing() — super admin, club_admin of the event's
 *          club, or coach of a team ON the event (not merely in the club). AND
 *          te_referee_feedback_rateability(): the event is a game whose date is
 *          today or earlier. A coach cannot rate a game that has not happened.
 *          The team the row is filed under is a claim in the request body and is
 *          resolved against te_referee_feedback_writable_team_ids(), never
 *          trusted.
 *   EDIT   the author only (a club admin may read everything, but rewriting a
 *          coach's words is not reviewing them).
 *   MINE   any coach or club admin; a parent reaches nothing.
 *   LIST / EXPORT  te_is_club_admin() of the club. A coach is team-scoped and
 *          the whole club's opinions of referees are club-wide staff data.
 *
 * ── Date-only rule ──────────────────────────────────────────────────────────
 * calendar_events.event_date is compared as a STRING against a YYYY-MM-DD
 * "today" supplied by the caller. No strtotime, no DateTime: a one-day UTC
 * shift is the difference between "played" and "not played" for a game this
 * morning. See the date-only rule in CLAUDE.md.
 *
 * ── The table may not exist yet ─────────────────────────────────────────────
 * `main` is shared and deploys are by push, so this file reaches production
 * before migration 095 is applied by hand. te_referee_feedback_table_present()
 * probes information_schema (SQLite has none, so the fallback asks the table
 * directly) and the gateway answers 503 with a sentence until it is there.
 */

require_once __DIR__ . '/AthleteScope.php';
require_once __DIR__ . '/event_standing.php';
require_once __DIR__ . '/club_standing.php';

/**
 * The one category list. Mirrored in
 * frontend/src/constants/refereeFeedbackCategories.ts and pinned together by
 * RefereeFeedbackCategoriesTest. Order is canonical: stored rows carry their
 * categories in this order regardless of the order the coach ticked them.
 */
const TE_REFEREE_FEEDBACK_CATEGORIES = ['control', 'consistency', 'communication', 'safety', 'punctuality'];

const TE_REFEREE_FEEDBACK_MAX_NAME = 120;
const TE_REFEREE_FEEDBACK_MAX_COMMENTS = 4000;

/** Export cap. Reported, never silent — see te_referee_feedback_truncation_notice(). */
const TE_REFEREE_FEEDBACK_EXPORT_MAX_ROWS = 5000;

/**
 * Is the migration-095 table live? Memoised per PDO instance via WeakMap for
 * the reason lib/athlete_evaluations.php gives: the suite builds one connection
 * with the table and one without, and object ids are reused.
 */
function te_referee_feedback_table_present(PDO $pdo): bool
{
    static $memo = null;
    $memo ??= new WeakMap();
    if (isset($memo[$pdo])) {
        return $memo[$pdo];
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_name = 'referee_feedback'"
        );
        $stmt->execute();
        return $memo[$pdo] = ((int) $stmt->fetchColumn() === 1);
    } catch (Throwable $e) {
        try {
            $pdo->query('SELECT 1 FROM referee_feedback LIMIT 1');
            return $memo[$pdo] = true;
        } catch (Throwable $e2) {
            return $memo[$pdo] = false;
        }
    }
}

/** The one sentence a caller gets while the table is missing. */
function te_referee_feedback_unavailable_message(): string
{
    return 'Referee feedback is not switched on for this club yet. The database update for this feature has not been applied — nothing was saved.';
}

function te_referee_feedback_now_sql(PDO $pdo): string
{
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? "datetime('now')" : 'NOW()';
}

// ---------------------------------------------------------------------------
// The event
// ---------------------------------------------------------------------------

/**
 * The event a row is about, with its teams. Null when it does not exist — the
 * gateway 404s; it must not treat that as "no standing".
 *
 * @return array{id:int, club_id:?int, name:string, type:string, event_date:string, start_time:?string, opponent_name:?string, team_ids:int[]}|null
 */
function te_referee_feedback_event(PDO $pdo, int $eventId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, club_id, name, type, event_date, start_time, opponent_name
           FROM calendar_events WHERE id = ?'
    );
    $stmt->execute([$eventId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    $teams = $pdo->prepare('SELECT team_id FROM calendar_event_teams WHERE event_id = ? ORDER BY team_id');
    $teams->execute([$eventId]);

    return [
        'id'            => (int) $row['id'],
        'club_id'       => $row['club_id'] === null ? null : (int) $row['club_id'],
        'name'          => (string) $row['name'],
        'type'          => (string) $row['type'],
        'event_date'    => (string) $row['event_date'],
        'start_time'    => $row['start_time'],
        'opponent_name' => $row['opponent_name'],
        'team_ids'      => array_map('intval', $teams->fetchAll(PDO::FETCH_COLUMN)),
    ];
}

/**
 * May feedback be recorded about this event at all? Null means yes; otherwise
 * the sentence to show. Two refusals: not a game, or not yet played. The date
 * is compared as a string — see the header.
 */
function te_referee_feedback_rateability(array $event, string $today): ?string
{
    if (($event['type'] ?? '') !== 'game') {
        return 'Referee feedback can only be recorded for a game.';
    }
    $date = substr((string) ($event['event_date'] ?? ''), 0, 10);
    if ($date === '' || strcmp($date, $today) > 0) {
        return 'This game has not been played yet. Referee feedback can be recorded on the day of the game or after it.';
    }
    return null;
}

/**
 * The teams on the event this caller may file a row under.
 *
 * Club admin (or super admin): every team on the event. Coach: the event's
 * teams they coach — getCoachTeamIds semantics via AthleteScope::coachTeamIdsForUser,
 * which counts assistant_coach / team_manager, never primary_coach_id alone.
 * Empty for everyone else, including a coach whose team is not on the event.
 *
 * @return int[] ascending
 */
function te_referee_feedback_writable_team_ids(PDO $pdo, AuthMiddleware $auth, array $event): array
{
    $eventTeams = array_map('intval', $event['team_ids'] ?? []);
    sort($eventTeams);
    if (empty($eventTeams)) {
        return [];
    }

    if ($auth->isSuperAdmin()) {
        return $eventTeams;
    }
    if ($event['club_id'] !== null && $auth->hasRole('club_admin', (int) $event['club_id'], 'club')) {
        return $eventTeams;
    }

    $uid = (int) $auth->getUserId();
    if ($uid <= 0) {
        return [];
    }
    $coachTeams = AthleteScope::coachTeamIdsForUser($pdo, $uid);
    return array_values(array_intersect($eventTeams, $coachTeams));
}

/**
 * Pick the team a row is filed under. One writable team needs no claim; two or
 * more need one, and the claim must be in the list. Null means "cannot decide"
 * and the gateway 422s with the list so the client can ask.
 */
function te_referee_feedback_resolve_team(array $writable, $requested): ?int
{
    $writable = array_map('intval', $writable);
    if (empty($writable)) {
        return null;
    }
    if ($requested === null || $requested === '' || (int) $requested <= 0) {
        return count($writable) === 1 ? $writable[0] : null;
    }
    $requested = (int) $requested;
    return in_array($requested, $writable, true) ? $requested : null;
}

/** May this caller reach the "mine" list at all? Coach or club admin somewhere. */
function te_referee_feedback_can_review_own(AuthMiddleware $auth): bool
{
    return $auth->isSuperAdmin() || $auth->hasRole('coach') || $auth->hasRole('club_admin');
}

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

/**
 * Normalise a categories claim to the canonical list order, or null when it
 * holds something the list does not.
 */
function te_referee_feedback_normalize_categories($raw): ?array
{
    if ($raw === null || $raw === '') {
        return [];
    }
    if (!is_array($raw)) {
        return null;
    }
    $wanted = [];
    foreach ($raw as $v) {
        if (!is_string($v)) {
            return null;
        }
        $v = strtolower(trim($v));
        if (!in_array($v, TE_REFEREE_FEEDBACK_CATEGORIES, true)) {
            return null;
        }
        $wanted[$v] = true;
    }
    return array_values(array_filter(TE_REFEREE_FEEDBACK_CATEGORIES, fn($c) => isset($wanted[$c])));
}

/**
 * Validate the feedback fields shared by create and update.
 *
 * @return array{error:?string, values:array}
 */
function te_referee_feedback_validate(array $body): array
{
    $fail = fn(string $m) => ['error' => $m, 'values' => []];

    $name = trim((string) ($body['referee_name'] ?? ''));
    if ($name === '') {
        return $fail('referee_name is required');
    }
    if (mb_strlen($name) > TE_REFEREE_FEEDBACK_MAX_NAME) {
        return $fail('referee_name is too long');
    }

    $ratingRaw = $body['rating'] ?? null;
    if (!is_numeric($ratingRaw) || (string) (int) $ratingRaw !== (string) $ratingRaw && !is_int($ratingRaw)) {
        return $fail('rating must be a whole number from 1 to 5');
    }
    $rating = (int) $ratingRaw;
    if ($rating < 1 || $rating > 5) {
        return $fail('rating must be a whole number from 1 to 5');
    }

    $categories = te_referee_feedback_normalize_categories($body['categories'] ?? []);
    if ($categories === null) {
        return $fail('categories must be a list drawn from: ' . implode(', ', TE_REFEREE_FEEDBACK_CATEGORIES));
    }

    $comments = isset($body['comments']) ? trim((string) $body['comments']) : '';
    if (mb_strlen($comments) > TE_REFEREE_FEEDBACK_MAX_COMMENTS) {
        return $fail('comments are too long');
    }

    $incidentRaw = $body['incident'] ?? false;
    $incident = filter_var($incidentRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($incident === null) {
        return $fail('incident must be true or false');
    }

    return [
        'error'  => null,
        'values' => [
            'referee_name' => $name,
            'rating'       => $rating,
            'categories'   => $categories,
            'comments'     => $comments !== '' ? $comments : null,
            'incident'     => $incident,
        ],
    ];
}

// ---------------------------------------------------------------------------
// Writes
// ---------------------------------------------------------------------------

function te_referee_feedback_bool_param(PDO $pdo, bool $v)
{
    // Postgres coerces 'true'/'false' text params to boolean; SQLite stores
    // integers. Same split lib/athlete_medical.php makes.
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? ($v ? 1 : 0) : ($v ? 'true' : 'false');
}

/** Insert one row. The UNIQUE constraint surfaces as a PDOException (23505). */
function te_referee_feedback_create(PDO $pdo, array $event, int $teamId, int $userId, array $values): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO referee_feedback
            (club_id, calendar_event_id, team_id, submitted_by, referee_name, rating,
             categories, comments, incident, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ' . te_referee_feedback_now_sql($pdo) . ')'
    );
    $stmt->execute([
        (int) $event['club_id'],
        (int) $event['id'],
        $teamId,
        $userId,
        $values['referee_name'],
        $values['rating'],
        json_encode($values['categories']),
        $values['comments'],
        te_referee_feedback_bool_param($pdo, (bool) $values['incident']),
    ]);
    return (int) $pdo->lastInsertId();
}

/** Rewrite the feedback fields of one row. Author, event and team never change. */
function te_referee_feedback_update(PDO $pdo, int $id, array $values): void
{
    $stmt = $pdo->prepare(
        'UPDATE referee_feedback
            SET referee_name = ?, rating = ?, categories = ?, comments = ?, incident = ?,
                updated_at = ' . te_referee_feedback_now_sql($pdo) . '
          WHERE id = ?'
    );
    $stmt->execute([
        $values['referee_name'],
        $values['rating'],
        json_encode($values['categories']),
        $values['comments'],
        te_referee_feedback_bool_param($pdo, (bool) $values['incident']),
        $id,
    ]);
}

// ---------------------------------------------------------------------------
// Reads
// ---------------------------------------------------------------------------

function te_referee_feedback_hydrate(array $row): array
{
    $cats = $row['categories'] ?? '[]';
    $decoded = is_array($cats) ? $cats : json_decode((string) $cats, true);
    $row['categories'] = is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    $row['rating'] = (int) $row['rating'];
    $row['incident'] = filter_var($row['incident'] ?? false, FILTER_VALIDATE_BOOLEAN);
    foreach (['id', 'club_id', 'calendar_event_id', 'team_id', 'submitted_by'] as $k) {
        if (array_key_exists($k, $row)) {
            $row[$k] = (int) $row[$k];
        }
    }
    return $row;
}

const TE_REFEREE_FEEDBACK_SELECT = '
    SELECT rf.id, rf.club_id, rf.calendar_event_id, rf.team_id, rf.submitted_by,
           rf.referee_name, rf.rating, rf.categories, rf.comments, rf.incident,
           rf.created_at, rf.updated_at,
           ce.name AS event_name, ce.event_date, ce.start_time, ce.opponent_name,
           t.name AS team_name,
           u.first_name AS submitted_by_first_name, u.last_name AS submitted_by_last_name
      FROM referee_feedback rf
      JOIN calendar_events ce ON ce.id = rf.calendar_event_id
      LEFT JOIN teams t ON t.id = rf.team_id
      LEFT JOIN users u ON u.id = rf.submitted_by';

function te_referee_feedback_rows(PDO $pdo, string $where, array $params, string $order): array
{
    $stmt = $pdo->prepare(TE_REFEREE_FEEDBACK_SELECT . ' WHERE ' . $where . ' ORDER BY ' . $order);
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row = te_referee_feedback_hydrate($row);
        $row['submitted_by_name'] = trim(($row['submitted_by_first_name'] ?? '') . ' ' . ($row['submitted_by_last_name'] ?? ''));
        unset($row['submitted_by_first_name'], $row['submitted_by_last_name']);
        $out[] = $row;
    }
    return $out;
}

function te_referee_feedback_find(PDO $pdo, int $id): ?array
{
    $rows = te_referee_feedback_rows($pdo, 'rf.id = ?', [$id], 'rf.id');
    return $rows[0] ?? null;
}

/** The caller's own rows on one event — what the modal shows for editing. */
function te_referee_feedback_for_event(PDO $pdo, int $eventId, int $userId): array
{
    return te_referee_feedback_rows(
        $pdo,
        'rf.calendar_event_id = ? AND rf.submitted_by = ?',
        [$eventId, $userId],
        'rf.referee_name, rf.id'
    );
}

/** Everything the caller has submitted, newest game first. */
function te_referee_feedback_mine(PDO $pdo, int $userId): array
{
    return te_referee_feedback_rows($pdo, 'rf.submitted_by = ?', [$userId], 'ce.event_date DESC, rf.id DESC');
}

/**
 * The club admin's list. Filters: from / to (event_date, inclusive, YYYY-MM-DD
 * strings), team_id, incident (true = incidents only), referee_name (case-
 * insensitive substring).
 */
function te_referee_feedback_list(PDO $pdo, int $clubId, array $filters): array
{
    $where = ['rf.club_id = ?'];
    $params = [$clubId];

    $from = substr(trim((string) ($filters['from'] ?? '')), 0, 10);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $where[] = 'ce.event_date >= ?';
        $params[] = $from;
    }
    $to = substr(trim((string) ($filters['to'] ?? '')), 0, 10);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $where[] = 'ce.event_date <= ?';
        $params[] = $to;
    }
    if (!empty($filters['team_id']) && (int) $filters['team_id'] > 0) {
        $where[] = 'rf.team_id = ?';
        $params[] = (int) $filters['team_id'];
    }
    if (filter_var($filters['incident'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
        $where[] = 'rf.incident = ' . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '1' : 'TRUE');
    }
    $name = trim((string) ($filters['referee_name'] ?? ''));
    if ($name !== '') {
        $where[] = 'LOWER(rf.referee_name) LIKE ?';
        $params[] = '%' . mb_strtolower(str_replace(['%', '_'], ['\\%', '\\_'], $name)) . '%';
    }

    return te_referee_feedback_rows($pdo, implode(' AND ', $where), $params, 'ce.event_date DESC, rf.id DESC');
}

/**
 * Per-referee-name roll-up of a list: count, average rating, incident count.
 * Grouped on the trimmed name as entered — there is no referee registry, so
 * this claims nothing about two spellings being one person.
 */
function te_referee_feedback_summary(array $rows): array
{
    $by = [];
    foreach ($rows as $r) {
        $key = trim((string) $r['referee_name']);
        if (!isset($by[$key])) {
            $by[$key] = ['referee_name' => $key, 'count' => 0, 'rating_total' => 0, 'incident_count' => 0];
        }
        $by[$key]['count']++;
        $by[$key]['rating_total'] += (int) $r['rating'];
        if (!empty($r['incident'])) {
            $by[$key]['incident_count']++;
        }
    }
    $out = [];
    foreach ($by as $s) {
        $out[] = [
            'referee_name'   => $s['referee_name'],
            'count'          => $s['count'],
            'average_rating' => round($s['rating_total'] / $s['count'], 2),
            'incident_count' => $s['incident_count'],
        ];
    }
    usort($out, fn($a, $b) => [$b['incident_count'], $b['count'], $a['referee_name']] <=> [$a['incident_count'], $a['count'], $b['referee_name']]);
    return $out;
}

// ---------------------------------------------------------------------------
// Export
// ---------------------------------------------------------------------------

/**
 * Rows for the CSV. Dates are emitted as the stored YYYY-MM-DD and never
 * parsed. The cap is recorded on the sheet so the notice, the header and the
 * audit row can all say the same thing.
 */
function te_referee_feedback_export_sheet(array $rows, int $maxRows = TE_REFEREE_FEEDBACK_EXPORT_MAX_ROWS): array
{
    $headers = ['Game date', 'Game', 'Opponent', 'Team', 'Referee', 'Rating', 'Categories', 'Incident', 'Comments', 'Submitted by', 'Submitted at'];
    $out = [];
    $total = count($rows);
    foreach (array_slice($rows, 0, $maxRows) as $r) {
        $out[] = [
            substr((string) $r['event_date'], 0, 10),
            (string) ($r['event_name'] ?? ''),
            (string) ($r['opponent_name'] ?? ''),
            (string) ($r['team_name'] ?? ''),
            (string) $r['referee_name'],
            (string) $r['rating'],
            implode(', ', $r['categories'] ?? []),
            !empty($r['incident']) ? 'Yes' : 'No',
            (string) ($r['comments'] ?? ''),
            (string) ($r['submitted_by_name'] ?? ''),
            (string) ($r['created_at'] ?? ''),
        ];
    }
    return [
        'headers'      => $headers,
        'rows'         => $out,
        'total_rows'   => $total,
        'omitted_rows' => max(0, $total - count($out)),
        'max_rows'     => $maxRows,
    ];
}

function te_referee_feedback_truncation_notice(array $sheet): ?string
{
    if (($sheet['omitted_rows'] ?? 0) <= 0) {
        return null;
    }
    return sprintf(
        '%d of %d feedback rows were left out (the file is capped at %d rows).',
        $sheet['omitted_rows'],
        $sheet['total_rows'],
        $sheet['max_rows'] ?? TE_REFEREE_FEEDBACK_EXPORT_MAX_ROWS
    );
}

function te_referee_feedback_export_filename(string $today): string
{
    return 'referee-feedback-' . $today . '.csv';
}
