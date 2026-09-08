<?php
/**
 * Referees — the club directory, the `referee` role's link to it, and game
 * assignments (Maggie, 2026-09-08; migration 099).
 *
 * Every decision api/referees.php and legacy/events-gateway.php make about a
 * referee lives here so RefereesTest can execute it against SQLite. The
 * gateway is HTTP only.
 *
 * ── Shape ─────────────────────────────────────────────────────────────────
 *   referees        one row per CLUB per person: the club's grade, certification
 *                   and notes about them. The PERSON is the users row —
 *                   referees work with different clubs and users.email is
 *                   UNIQUE, so one account holds `referee` in several clubs and
 *                   `referees.user_id` ties each club's row to it.
 *   game_referees   who is refereeing which game, with the position on the day.
 *
 * ── Standing ──────────────────────────────────────────────────────────────
 *   list / search   te_is_club_staff (admin or coach): a coach picks a referee
 *                   on the feedback modal and when creating a game.
 *   create / update / archive / restore / invite
 *                   te_is_club_admin only.
 *   assign / unassign / for-event
 *                   te_event_staff_standing(): whoever can edit the game —
 *                   club admin of its club, or a coach of a team ON it.
 *   my-games        anyone with a referees row carrying their user id, in ANY
 *                   club, regardless of the token's active context. Built off
 *                   the user id, never active_context.
 *
 * ── Grades ────────────────────────────────────────────────────────────────
 * One ordered scale, TE_REFEREE_GRADE_RANK, mirrored in
 * frontend/src/constants/refereeGrades.ts (RefereeGradesConsistencyTest pins
 * them together): Grassroots (1) < Regional (2) < National (3) < Professional
 * (4). The legacy numeric grades are mapped onto it for comparison — 9, 8, 7 ≈
 * Grassroots; 6, 5 ≈ Regional; 4, 3 ≈ National; 2, 1 ≈ Professional. A blank
 * or "Other" grade has NO rank and never qualifies for a game that sets a
 * minimum (calendar_events.min_referee_grade). open-games filters on it and
 * claim re-checks it server-side; staff may place someone below the minimum
 * and the row records grade_override = true.
 *
 * ── Self-assignment ───────────────────────────────────────────────────────
 * A referee may claim an upcoming game in a club they referee for when the
 * role they want is not yet filled (self_assigned = true), and release only a
 * row they claimed themselves while the game is still upcoming. A row placed
 * by staff is the club's decision; they ask the club.
 *
 * ── What a referee is NOT ─────────────────────────────────────────────────
 * Not club staff. te_is_club_staff / AthleteScope / roster, document and event
 * standing / compliance staff roles / te_is_financial_admin all refuse the
 * role; RefereeScopeTest scans those files for the word. A referee reaches
 * their own games and their own contact card, and nothing else.
 *
 * ── The tables may not exist yet ──────────────────────────────────────────
 * `main` is shared and deploys are by push, so this code reaches production
 * before 099 is applied by hand. te_referees_table_present() probes and the
 * gateway answers 503 with a sentence until it is there.
 */

require_once __DIR__ . '/suppression.php';
require_once __DIR__ . '/club_standing.php';
require_once __DIR__ . '/event_standing.php';
require_once __DIR__ . '/AuditLogger.php';

/** Positions a referee can hold on a game. `referee` is the default / unspecified. */
const TE_GAME_REFEREE_ROLES = ['referee', 'center', 'assistant', 'fourth'];

/**
 * US Soccer grades offered as a select. Mirrored in
 * frontend/src/constants/refereeGrades.ts. Free text is also accepted ("Other").
 */
const TE_REFEREE_GRADES = [
    'Grassroots', 'Regional', 'National', 'Professional',
    'Grade 9', 'Grade 8', 'Grade 7', 'Grade 6', 'Grade 5', 'Grade 4', 'Grade 3', 'Grade 2', 'Grade 1',
];

/**
 * Grade → rank. Higher is more qualified. Anything not here (free text, blank)
 * has no rank. Keys are compared case-insensitively after trimming.
 */
const TE_REFEREE_GRADE_RANK = [
    'grassroots' => 1, 'regional' => 2, 'national' => 3, 'professional' => 4,
    'grade 9' => 1, 'grade 8' => 1, 'grade 7' => 1,
    'grade 6' => 2, 'grade 5' => 2,
    'grade 4' => 3, 'grade 3' => 3,
    'grade 2' => 4, 'grade 1' => 4,
];

/** The four values a game may set as its minimum. */
const TE_GAME_MIN_GRADES = ['Grassroots', 'Regional', 'National', 'Professional'];

const TE_REFEREE_MAX_NAME = 80;
const TE_REFEREE_MAX_TEXT = 200;
const TE_REFEREE_MAX_NOTES = 4000;
const TE_REFEREE_SEARCH_LIMIT = 10;

// ---------------------------------------------------------------------------
// Probes and small helpers
// ---------------------------------------------------------------------------

/** Is the migration-099 `referees` table live? Memoised per PDO (WeakMap). */
function te_referees_table_present(PDO $pdo): bool
{
    static $memo = null;
    $memo ??= new WeakMap();
    if (isset($memo[$pdo])) {
        return $memo[$pdo];
    }
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_name = 'referees'");
        $stmt->execute();
        return $memo[$pdo] = ((int) $stmt->fetchColumn() === 1);
    } catch (Throwable $e) {
        try {
            $pdo->query('SELECT 1 FROM referees LIMIT 1');
            return $memo[$pdo] = true;
        } catch (Throwable $e2) {
            return $memo[$pdo] = false;
        }
    }
}

/** The one sentence a caller gets while the table is missing. */
function te_referees_unavailable_message(): string
{
    return 'Referees are not switched on for this club yet. The database update for this feature has not been applied — nothing was saved.';
}

function te_referees_now_sql(PDO $pdo): string
{
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? "datetime('now')" : 'NOW()';
}

function te_referees_bool_param(PDO $pdo, bool $v)
{
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? ($v ? 1 : 0) : ($v ? 'true' : 'false');
}

function te_referee_is_true($v): bool
{
    return filter_var($v, FILTER_VALIDATE_BOOLEAN);
}

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

/**
 * Validate the directory fields. `$partial` (update) only checks the keys that
 * were sent; create requires first/last.
 *
 * Phone goes through te_normalize_sms_phone: blank clears (NULL), unreadable
 * is an error — a single-field save that stores NULL for "unreadable" reports
 * success while saving nothing (the jersey-size lesson). Email is lowercased.
 *
 * @return array{error:?string, values:array}
 */
function te_referee_validate(array $body, bool $partial = false): array
{
    $fail = fn(string $m) => ['error' => $m, 'values' => []];
    $values = [];

    foreach (['first_name', 'last_name'] as $k) {
        if (array_key_exists($k, $body) || !$partial) {
            $v = trim((string) ($body[$k] ?? ''));
            if ($v === '') {
                return $fail(str_replace('_', ' ', $k) . ' is required');
            }
            if (mb_strlen($v) > TE_REFEREE_MAX_NAME) {
                return $fail(str_replace('_', ' ', $k) . ' is too long');
            }
            $values[$k] = $v;
        }
    }

    if (array_key_exists('email', $body)) {
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $fail("'{$email}' is not a valid email address");
        }
        $values['email'] = $email !== '' ? $email : null;
    } elseif (!$partial) {
        $values['email'] = null;
    }

    if (array_key_exists('phone', $body)) {
        $raw = trim((string) ($body['phone'] ?? ''));
        if ($raw === '') {
            $values['phone'] = null;
        } else {
            $normalized = te_normalize_sms_phone($raw);
            if ($normalized === null) {
                return $fail("'{$raw}' is not a phone number we can read — use 10 digits, or +country code");
            }
            $values['phone'] = $normalized;
        }
    } elseif (!$partial) {
        $values['phone'] = null;
    }

    foreach (['grade', 'certification_level'] as $k) {
        if (array_key_exists($k, $body)) {
            $v = trim((string) ($body[$k] ?? ''));
            if (mb_strlen($v) > TE_REFEREE_MAX_TEXT) {
                return $fail(str_replace('_', ' ', $k) . ' is too long');
            }
            $values[$k] = $v !== '' ? $v : null;
        } elseif (!$partial) {
            $values[$k] = null;
        }
    }

    if (array_key_exists('notes', $body)) {
        $v = trim((string) ($body['notes'] ?? ''));
        if (mb_strlen($v) > TE_REFEREE_MAX_NOTES) {
            return $fail('notes are too long');
        }
        $values['notes'] = $v !== '' ? $v : null;
    } elseif (!$partial) {
        $values['notes'] = null;
    }

    return ['error' => null, 'values' => $values];
}

/** Normalise a game role claim; null for anything not in the list. */
function te_game_referee_role($raw): ?string
{
    $v = strtolower(trim((string) ($raw ?? '')));
    if ($v === '') {
        return 'referee';
    }
    return in_array($v, TE_GAME_REFEREE_ROLES, true) ? $v : null;
}

/** Rank of a grade string, or null when it has none. */
function te_referee_grade_rank(?string $grade): ?int
{
    $k = strtolower(trim((string) $grade));
    if ($k === '') {
        return null;
    }
    // "9" / "8" typed bare still count as the legacy scale.
    if (preg_match('/^[1-9]$/', $k)) {
        $k = 'grade ' . $k;
    }
    return TE_REFEREE_GRADE_RANK[$k] ?? null;
}

/**
 * Does a referee's grade meet a game's minimum? No minimum → yes. A minimum
 * with an unranked (blank / Other) grade → no: "we don't know" is not "yes".
 */
function te_referee_grade_meets(?string $grade, ?string $minimum): bool
{
    $min = te_referee_grade_rank($minimum);
    if ($min === null) {
        return true;
    }
    $have = te_referee_grade_rank($grade);
    return $have !== null && $have >= $min;
}

/** Normalise a game's minimum-grade claim: null/'' → null, one of the four → canonical, else null-with-error. */
function te_game_min_grade($raw): array
{
    $v = trim((string) ($raw ?? ''));
    if ($v === '' || strtolower($v) === 'any') {
        return ['error' => null, 'value' => null];
    }
    foreach (TE_GAME_MIN_GRADES as $g) {
        if (strcasecmp($g, $v) === 0) {
            return ['error' => null, 'value' => $g];
        }
    }
    return ['error' => 'min_referee_grade must be one of: Any, ' . implode(', ', TE_GAME_MIN_GRADES), 'value' => null];
}

// ---------------------------------------------------------------------------
// Reads
// ---------------------------------------------------------------------------

function te_referee_hydrate(array $row): array
{
    foreach (['id', 'club_id', 'user_id', 'created_by'] as $k) {
        if (array_key_exists($k, $row)) {
            $row[$k] = $row[$k] === null ? null : (int) $row[$k];
        }
    }
    if (array_key_exists('active', $row)) {
        $row['active'] = te_referee_is_true($row['active']);
    }
    $row['name'] = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
    return $row;
}

const TE_REFEREE_SELECT = '
    SELECT r.id, r.club_id, r.user_id, r.first_name, r.last_name, r.email, r.phone,
           r.grade, r.certification_level, r.notes, r.active, r.archived_at,
           r.created_by, r.created_at, r.updated_at
      FROM referees r';

function te_referee_find(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(TE_REFEREE_SELECT . ' WHERE r.id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? te_referee_hydrate($row) : null;
}

/** The club's directory. Archived rows only when asked. Ordered by name. */
function te_referee_list(PDO $pdo, int $clubId, bool $includeArchived = false): array
{
    $sql = TE_REFEREE_SELECT . ' WHERE r.club_id = ?';
    if (!$includeArchived) {
        $sql .= ' AND r.archived_at IS NULL';
    }
    $sql .= ' ORDER BY LOWER(r.last_name), LOWER(r.first_name), r.id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$clubId]);
    return array_map('te_referee_hydrate', $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/** Typeahead: active referees of the club whose name or email contains `$q`. */
function te_referee_search(PDO $pdo, int $clubId, string $q, int $limit = TE_REFEREE_SEARCH_LIMIT): array
{
    $q = mb_strtolower(trim($q));
    $sql = TE_REFEREE_SELECT . ' WHERE r.club_id = ? AND r.archived_at IS NULL';
    $params = [$clubId];
    if ($q !== '') {
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        $sql .= " AND (LOWER(r.first_name || ' ' || r.last_name) LIKE ? OR LOWER(COALESCE(r.email, '')) LIKE ?)";
        $params[] = $like;
        $params[] = $like;
    }
    $sql .= ' ORDER BY LOWER(r.last_name), LOWER(r.first_name), r.id LIMIT ' . (int) $limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return array_map('te_referee_hydrate', $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/** An existing referee in the club on this address (case-insensitive), or null. */
function te_referee_find_by_email(PDO $pdo, int $clubId, ?string $email, ?int $excludeId = null): ?array
{
    $email = strtolower(trim((string) $email));
    if ($email === '') {
        return null;
    }
    $sql = TE_REFEREE_SELECT . ' WHERE r.club_id = ? AND LOWER(r.email) = ?';
    $params = [$clubId, $email];
    if ($excludeId !== null) {
        $sql .= ' AND r.id <> ?';
        $params[] = $excludeId;
    }
    $stmt = $pdo->prepare($sql . ' ORDER BY r.id LIMIT 1');
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? te_referee_hydrate($row) : null;
}

/** The users.id holding this address, if any. The PERSON is the account. */
function te_referee_user_id_for_email(PDO $pdo, ?string $email): ?int
{
    $email = strtolower(trim((string) $email));
    if ($email === '') {
        return null;
    }
    $stmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = ? LIMIT 1');
    $stmt->execute([$email]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int) $id;
}

// ---------------------------------------------------------------------------
// Writes — directory
// ---------------------------------------------------------------------------

/**
 * Insert one directory row. If the address already belongs to an account (in
 * ANY club) the row links to it rather than a second person being made.
 *
 * @return int the new referees.id
 */
function te_referee_create(PDO $pdo, int $clubId, array $values, ?int $actorId): int
{
    $userId = te_referee_user_id_for_email($pdo, $values['email'] ?? null);
    $now = te_referees_now_sql($pdo);
    $stmt = $pdo->prepare(
        "INSERT INTO referees
            (club_id, user_id, first_name, last_name, email, phone, grade, certification_level, notes,
             active, created_by, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, {$now}, {$now})"
    );
    $stmt->execute([
        $clubId,
        $userId,
        $values['first_name'],
        $values['last_name'],
        $values['email'] ?? null,
        $values['phone'] ?? null,
        $values['grade'] ?? null,
        $values['certification_level'] ?? null,
        $values['notes'] ?? null,
        te_referees_bool_param($pdo, true),
        $actorId,
    ]);
    return (int) $pdo->lastInsertId();
}

/**
 * Rewrite the submitted fields only — a partial save cannot blank a field it
 * never sent. A changed email re-resolves the account link (never unlinks a
 * row whose address still matches an account it is linked to).
 */
function te_referee_update(PDO $pdo, int $id, array $values): void
{
    $allowed = ['first_name', 'last_name', 'email', 'phone', 'grade', 'certification_level', 'notes'];
    $sets = [];
    $params = [];
    foreach ($allowed as $k) {
        if (array_key_exists($k, $values)) {
            $sets[] = "{$k} = ?";
            $params[] = $values[$k];
        }
    }
    if (array_key_exists('email', $values)) {
        $sets[] = 'user_id = ?';
        $params[] = te_referee_user_id_for_email($pdo, $values['email']);
    }
    if (empty($sets)) {
        return;
    }
    $sets[] = 'updated_at = ' . te_referees_now_sql($pdo);
    $params[] = $id;
    $stmt = $pdo->prepare('UPDATE referees SET ' . implode(', ', $sets) . ' WHERE id = ?');
    $stmt->execute($params);
}

/** Archive (never delete) or restore. */
function te_referee_set_archived(PDO $pdo, int $id, bool $archived): void
{
    $now = te_referees_now_sql($pdo);
    $stmt = $pdo->prepare(
        $archived
            ? "UPDATE referees SET archived_at = {$now}, active = ?, updated_at = {$now} WHERE id = ?"
            : "UPDATE referees SET archived_at = NULL, active = ?, updated_at = {$now} WHERE id = ?"
    );
    $stmt->execute([te_referees_bool_param($pdo, !$archived), $id]);
}

/** Point one directory row at an account. */
function te_referee_set_user(PDO $pdo, int $id, int $userId): void
{
    $stmt = $pdo->prepare('UPDATE referees SET user_id = ?, updated_at = ' . te_referees_now_sql($pdo) . ' WHERE id = ?');
    $stmt->execute([$userId, $id]);
}

/**
 * Link every unlinked directory row on this address (in any club) to the
 * account. Called when a `referee` invitation is accepted. Returns the number
 * of rows linked; 0 is a real answer (the club has not added them yet).
 */
function te_referee_link_user_by_email(PDO $pdo, int $userId, string $email): int
{
    if (!te_referees_table_present($pdo)) {
        return 0;
    }
    $email = strtolower(trim($email));
    if ($email === '' || $userId <= 0) {
        return 0;
    }
    $stmt = $pdo->prepare(
        'UPDATE referees SET user_id = ?, updated_at = ' . te_referees_now_sql($pdo) . '
          WHERE user_id IS NULL AND LOWER(email) = ?'
    );
    $stmt->execute([$userId, $email]);
    return $stmt->rowCount();
}

// ---------------------------------------------------------------------------
// Game assignments
// ---------------------------------------------------------------------------

/** The game an assignment is about: id, club_id, type. Null when it does not exist. */
function te_game_for_assignment(PDO $pdo, int $eventId): ?array
{
    $grade = te_min_referee_grade_column_present($pdo) ? 'min_referee_grade' : 'NULL AS min_referee_grade';
    $stmt = $pdo->prepare("SELECT id, club_id, type, event_date, {$grade} FROM calendar_events WHERE id = ?");
    $stmt->execute([$eventId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    return [
        'id'                => (int) $row['id'],
        'club_id'           => $row['club_id'] === null ? null : (int) $row['club_id'],
        'type'              => (string) $row['type'],
        'event_date'        => substr((string) $row['event_date'], 0, 10),
        'min_referee_grade' => $row['min_referee_grade'] ?? null,
    ];
}

/** Is calendar_events.min_referee_grade (migration 099) live? Memoised per PDO. */
function te_min_referee_grade_column_present(PDO $pdo): bool
{
    static $memo = null;
    $memo ??= new WeakMap();
    if (isset($memo[$pdo])) {
        return $memo[$pdo];
    }
    try {
        $pdo->query('SELECT min_referee_grade FROM calendar_events LIMIT 1');
        return $memo[$pdo] = true;
    } catch (Throwable $e) {
        return $memo[$pdo] = false;
    }
}

/**
 * Why this referee cannot be assigned to this event, or null when they can.
 * Refusals: not a game (422), referee missing (404-ish, reported as 422 so a
 * refused caller learns nothing about which ids exist), archived, or a
 * referee from another club — a club admin of A cannot see or assign B's.
 */
function te_game_referee_assignability(array $event, ?array $referee): ?string
{
    if (($event['type'] ?? '') !== 'game') {
        return 'Referees can only be assigned to a game.';
    }
    if ($referee === null) {
        return 'That referee is not in this club\'s directory.';
    }
    if (($event['club_id'] ?? null) === null || (int) $referee['club_id'] !== (int) $event['club_id']) {
        return 'That referee is not in this club\'s directory.';
    }
    if (!empty($referee['archived_at'])) {
        return 'That referee is archived — restore them first.';
    }
    return null;
}

/**
 * Upsert one assignment (role updates on a second assign). `$selfAssigned`
 * marks a referee's own claim; `$gradeOverride` records that staff placed
 * someone below the game's minimum grade, knowingly.
 */
function te_game_referee_assign(PDO $pdo, int $eventId, int $refereeId, string $role, ?int $actorId, bool $selfAssigned = false, bool $gradeOverride = false): void
{
    $now = te_referees_now_sql($pdo);
    $stmt = $pdo->prepare('SELECT id FROM game_referees WHERE calendar_event_id = ? AND referee_id = ?');
    $stmt->execute([$eventId, $refereeId]);
    $existing = $stmt->fetchColumn();
    if ($existing !== false) {
        $stmt = $pdo->prepare(
            'UPDATE game_referees SET role = ?, assigned_by = ?, self_assigned = ?, grade_override = ?, assigned_at = ' . $now . ' WHERE id = ?'
        );
        $stmt->execute([$role, $actorId, te_referees_bool_param($pdo, $selfAssigned), te_referees_bool_param($pdo, $gradeOverride), (int) $existing]);
        return;
    }
    $stmt = $pdo->prepare(
        "INSERT INTO game_referees (calendar_event_id, referee_id, role, assigned_by, assigned_at, self_assigned, grade_override)
         VALUES (?, ?, ?, ?, {$now}, ?, ?)"
    );
    $stmt->execute([$eventId, $refereeId, $role, $actorId, te_referees_bool_param($pdo, $selfAssigned), te_referees_bool_param($pdo, $gradeOverride)]);
}

/** @return bool whether a row was removed */
function te_game_referee_unassign(PDO $pdo, int $eventId, int $refereeId): bool
{
    $stmt = $pdo->prepare('DELETE FROM game_referees WHERE calendar_event_id = ? AND referee_id = ?');
    $stmt->execute([$eventId, $refereeId]);
    return $stmt->rowCount() > 0;
}

/** The referees on one event, with grade, in assignment order. */
function te_game_referees_for_event(PDO $pdo, int $eventId): array
{
    $stmt = $pdo->prepare(
        'SELECT gr.id AS assignment_id, gr.role, gr.assigned_at, gr.self_assigned, gr.grade_override,
                r.id, r.club_id, r.user_id, r.first_name, r.last_name, r.email, r.phone,
                r.grade, r.certification_level, r.archived_at
           FROM game_referees gr
           JOIN referees r ON r.id = gr.referee_id
          WHERE gr.calendar_event_id = ?
          ORDER BY gr.id'
    );
    $stmt->execute([$eventId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row = te_referee_hydrate($row);
        $row['assignment_id'] = (int) $row['assignment_id'];
        $row['self_assigned'] = te_referee_is_true($row['self_assigned'] ?? false);
        $row['grade_override'] = te_referee_is_true($row['grade_override'] ?? false);
        $out[] = $row;
    }
    return $out;
}

/**
 * Apply a `referees: [{referee_id, role}]` list to a game as ONE step — used by
 * legacy/events-gateway.php on create (assign) and update (replace). The list is
 * validated in full before anything is written, so a bad entry fails the whole
 * request rather than half of it. Returns the number assigned.
 *
 * `$replace` removes assignments not in the list (the edit path). Create passes
 * false: there is nothing to remove.
 *
 * @throws InvalidArgumentException with the sentence to show, on a bad entry
 */
function te_game_referees_apply(PDO $pdo, array $event, array $list, ?int $actorId, bool $replace = false): int
{
    $wanted = [];
    foreach ($list as $entry) {
        if (!is_array($entry)) {
            throw new InvalidArgumentException('referees must be a list of {referee_id, role}');
        }
        $refereeId = (int) ($entry['referee_id'] ?? 0);
        if ($refereeId <= 0) {
            throw new InvalidArgumentException('referee_id is required on every referee');
        }
        $role = te_game_referee_role($entry['role'] ?? null);
        if ($role === null) {
            throw new InvalidArgumentException('role must be one of: ' . implode(', ', TE_GAME_REFEREE_ROLES));
        }
        $referee = te_referee_find($pdo, $refereeId);
        $reason = te_game_referee_assignability($event, $referee);
        if ($reason !== null) {
            throw new InvalidArgumentException($reason);
        }
        // Staff may place someone below the minimum; the row says so.
        $wanted[$refereeId] = [
            'role' => $role,
            'override' => !te_referee_grade_meets($referee['grade'] ?? null, $event['min_referee_grade'] ?? null),
        ];
    }

    if ($replace) {
        foreach (te_game_referees_for_event($pdo, (int) $event['id']) as $current) {
            if (!isset($wanted[$current['id']])) {
                te_game_referee_unassign($pdo, (int) $event['id'], (int) $current['id']);
            }
        }
    }
    foreach ($wanted as $refereeId => $w) {
        te_game_referee_assign($pdo, (int) $event['id'], $refereeId, $w['role'], $actorId, false, $w['override']);
    }
    return count($wanted);
}

// ---------------------------------------------------------------------------
// The referee's own view
// ---------------------------------------------------------------------------

/** Every club where a directory row carries this user id, with the row's grade. */
function te_referee_clubs_for_user(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT r.id AS referee_id, r.club_id, r.grade, r.certification_level, r.archived_at,
                cp.name AS club_name, cp.primary_color
           FROM referees r
           LEFT JOIN club_profile cp ON cp.id = r.club_id
          WHERE r.user_id = ? AND r.archived_at IS NULL
          ORDER BY LOWER(COALESCE(cp.name, \'\')), r.club_id'
    );
    $stmt->execute([$userId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[] = [
            'referee_id'          => (int) $row['referee_id'],
            'club_id'             => (int) $row['club_id'],
            'club_name'           => $row['club_name'] ?? null,
            'primary_color'       => $row['primary_color'] ?? null,
            'grade'               => $row['grade'],
            'certification_level' => $row['certification_level'],
        ];
    }
    return $out;
}

/**
 * The signed-in referee's games across EVERY club, split on a date-only
 * string: upcoming (today or later, soonest first) and past (newest first).
 * Dates are the stored YYYY-MM-DD; the client formats with utils/dateFormat.
 * Teams come from calendar_event_teams. There is no field on a calendar
 * event — venue plus the free-text location is what the product records.
 *
 * @return array{upcoming: array, past: array}
 */
function te_referee_my_games(PDO $pdo, int $userId, string $today): array
{
    $stmt = $pdo->prepare(
        'SELECT ce.id, ce.club_id, ce.name, ce.event_date, ce.start_time, ce.end_time,
                ce.opponent_name, ce.location, ce.status,
                gr.role, gr.self_assigned, r.id AS referee_id,
                cp.name AS club_name, cp.primary_color,
                v.name AS venue_name, v.address AS venue_address, v.city AS venue_city
           FROM game_referees gr
           JOIN referees r ON r.id = gr.referee_id
           JOIN calendar_events ce ON ce.id = gr.calendar_event_id
           LEFT JOIN club_profile cp ON cp.id = ce.club_id
           LEFT JOIN venues v ON v.id = ce.venue_id
          WHERE r.user_id = ? AND r.archived_at IS NULL
          ORDER BY ce.event_date, ce.start_time, ce.id'
    );
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $teamsByEvent = [];
    if ($rows) {
        // Bounded by the referee's own assignments, not by any club or roster.
        $gameIds = array_values(array_unique(array_map(fn($r) => (int) $r['id'], $rows)));
        $marks = implode(',', array_fill(0, count($gameIds), '?'));
        $tstmt = $pdo->prepare(
            "SELECT cet.event_id, t.id, t.name, t.primary_color
               FROM calendar_event_teams cet
               JOIN teams t ON t.id = cet.team_id
              WHERE cet.event_id IN ($marks)
              ORDER BY cet.event_id, t.id"
        );
        $tstmt->execute($gameIds);
        foreach ($tstmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $teamsByEvent[(int) $t['event_id']][] = [
                'id' => (int) $t['id'], 'name' => (string) $t['name'], 'primary_color' => $t['primary_color'] ?? null,
            ];
        }
    }

    $upcoming = [];
    $past = [];
    foreach ($rows as $r) {
        $date = substr((string) $r['event_date'], 0, 10);
        $game = [
            'id'            => (int) $r['id'],
            'club_id'       => $r['club_id'] === null ? null : (int) $r['club_id'],
            'club_name'     => $r['club_name'] ?? null,
            'primary_color' => $r['primary_color'] ?? null,
            'name'          => (string) $r['name'],
            'event_date'    => $date,
            'start_time'    => $r['start_time'],
            'end_time'      => $r['end_time'],
            'opponent_name' => $r['opponent_name'],
            'location'      => $r['location'],
            'status'        => $r['status'],
            'venue_name'    => $r['venue_name'] ?? null,
            'venue_address' => $r['venue_address'] ?? null,
            'venue_city'    => $r['venue_city'] ?? null,
            'role'          => (string) $r['role'],
            'self_assigned' => te_referee_is_true($r['self_assigned'] ?? false),
            'referee_id'    => (int) $r['referee_id'],
            'teams'         => $teamsByEvent[(int) $r['id']] ?? [],
        ];
        if (strcmp($date, $today) >= 0) {
            $upcoming[] = $game;
        } else {
            $past[] = $game;
        }
    }
    $past = array_reverse($past);
    return ['upcoming' => $upcoming, 'past' => $past];
}

// ---------------------------------------------------------------------------
// Open games and self-assignment
// ---------------------------------------------------------------------------

/**
 * A game is OPEN when it is upcoming and nobody holds `center` on it — a game
 * with zero referees is open, and a game with only assistants is still open
 * for center. `$role` asks about one specific position instead.
 */
function te_game_role_filled(array $assigned, string $role): bool
{
    foreach ($assigned as $a) {
        if (($a['role'] ?? '') === $role) {
            return true;
        }
    }
    return false;
}

/**
 * Upcoming games, in every club where the signed-in user has a directory row,
 * with no center referee yet, whose minimum grade the caller's grade IN THAT
 * CLUB meets. Each carries the referees already on it. Soonest first.
 */
function te_referee_open_games(PDO $pdo, int $userId, string $today): array
{
    $clubs = te_referee_clubs_for_user($pdo, $userId);
    if (empty($clubs)) {
        return [];
    }
    $gradeByClub = [];
    $myRefereeIdByClub = [];
    foreach ($clubs as $c) {
        $gradeByClub[$c['club_id']] = $c['grade'];
        $myRefereeIdByClub[$c['club_id']] = $c['referee_id'];
    }
    // Bounded by the clubs the referee works for — a handful.
    $clubIds = array_keys($gradeByClub);
    $marks = implode(',', array_fill(0, count($clubIds), '?'));
    $minGrade = te_min_referee_grade_column_present($pdo) ? 'ce.min_referee_grade' : 'NULL AS min_referee_grade';

    $stmt = $pdo->prepare(
        "SELECT ce.id, ce.club_id, ce.name, ce.event_date, ce.start_time, ce.end_time,
                ce.opponent_name, ce.location, ce.status, {$minGrade},
                cp.name AS club_name, cp.primary_color,
                v.name AS venue_name, v.address AS venue_address, v.city AS venue_city
           FROM calendar_events ce
           LEFT JOIN club_profile cp ON cp.id = ce.club_id
           LEFT JOIN venues v ON v.id = ce.venue_id
          WHERE ce.type = 'game'
            AND ce.event_date >= ?
            AND ce.club_id IN ($marks)
            AND NOT EXISTS (
                SELECT 1 FROM game_referees gr
                 WHERE gr.calendar_event_id = ce.id AND gr.role = 'center'
            )
          ORDER BY ce.event_date, ce.start_time, ce.id"
    );
    $stmt->execute(array_merge([$today], $clubIds));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        $clubId = (int) $r['club_id'];
        if (!te_referee_grade_meets($gradeByClub[$clubId] ?? null, $r['min_referee_grade'] ?? null)) {
            continue;
        }
        $assigned = te_game_referees_for_event($pdo, (int) $r['id']);
        $onIt = false;
        foreach ($assigned as $a) {
            if ((int) $a['id'] === (int) $myRefereeIdByClub[$clubId]) {
                $onIt = true;
            }
        }
        if ($onIt) {
            continue; // it is in My games already
        }
        $out[] = [
            'id'                => (int) $r['id'],
            'club_id'           => $clubId,
            'club_name'         => $r['club_name'] ?? null,
            'primary_color'     => $r['primary_color'] ?? null,
            'name'              => (string) $r['name'],
            'event_date'        => substr((string) $r['event_date'], 0, 10),
            'start_time'        => $r['start_time'],
            'end_time'          => $r['end_time'],
            'opponent_name'     => $r['opponent_name'],
            'location'          => $r['location'],
            'status'            => $r['status'],
            'min_referee_grade' => $r['min_referee_grade'] ?? null,
            'venue_name'        => $r['venue_name'] ?? null,
            'venue_address'     => $r['venue_address'] ?? null,
            'venue_city'        => $r['venue_city'] ?? null,
            'teams'             => te_game_teams($pdo, (int) $r['id']),
            'referees'          => array_map(fn($a) => [
                'id' => $a['id'], 'name' => $a['name'], 'role' => $a['role'], 'grade' => $a['grade'],
            ], $assigned),
            'open_roles'        => array_values(array_filter(TE_GAME_REFEREE_ROLES, fn($role) => !te_game_role_filled($assigned, $role))),
        ];
    }
    return $out;
}

/** The teams on one event. */
function te_game_teams(PDO $pdo, int $eventId): array
{
    $stmt = $pdo->prepare(
        'SELECT t.id, t.name, t.primary_color FROM calendar_event_teams cet JOIN teams t ON t.id = cet.team_id
          WHERE cet.event_id = ? ORDER BY t.id'
    );
    $stmt->execute([$eventId]);
    return array_map(fn($t) => ['id' => (int) $t['id'], 'name' => (string) $t['name'], 'primary_color' => $t['primary_color'] ?? null],
        $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Why the caller cannot claim this game in this role, as [status, sentence];
 * null when they can. Re-checked server-side on every claim — the list is
 * never trusted.
 *
 *   403  the club is not one they referee for
 *   422  not a game / already played / grade below the minimum
 *   409  they are already on it / the role is already filled
 *
 * @return array{0:int,1:string}|null
 */
function te_referee_claim_refusal(PDO $pdo, int $userId, array $event, string $role, string $today): ?array
{
    if (($event['type'] ?? '') !== 'game') {
        return [422, 'Only a game can be taken.'];
    }
    if (strcmp(substr((string) $event['event_date'], 0, 10), $today) < 0) {
        return [422, 'This game has already been played.'];
    }
    $mine = null;
    foreach (te_referee_clubs_for_user($pdo, $userId) as $c) {
        if ((int) $c['club_id'] === (int) ($event['club_id'] ?? 0)) {
            $mine = $c;
        }
    }
    if ($mine === null) {
        return [403, 'This game belongs to a club you do not referee for.'];
    }
    if (!te_referee_grade_meets($mine['grade'] ?? null, $event['min_referee_grade'] ?? null)) {
        return [422, sprintf(
            'This game needs a %s referee or higher; your grade on file with this club is %s.',
            (string) $event['min_referee_grade'],
            ($mine['grade'] ?? '') !== '' && $mine['grade'] !== null ? (string) $mine['grade'] : 'not set'
        )];
    }
    $assigned = te_game_referees_for_event($pdo, (int) $event['id']);
    foreach ($assigned as $a) {
        if ((int) $a['id'] === (int) $mine['referee_id']) {
            return [409, 'You are already on this game.'];
        }
    }
    if (te_game_role_filled($assigned, $role)) {
        return [409, 'That position is already filled on this game.'];
    }
    return null;
}

/** The caller's own referees.id in the event's club, or null. */
function te_referee_own_row_for_event(PDO $pdo, int $userId, array $event): ?int
{
    foreach (te_referee_clubs_for_user($pdo, $userId) as $c) {
        if ((int) $c['club_id'] === (int) ($event['club_id'] ?? 0)) {
            return (int) $c['referee_id'];
        }
    }
    return null;
}

/**
 * Why the caller cannot release their row on this game; null when they can.
 *   404  they are not on it
 *   403  the row was placed by staff — ask the club
 *   422  the game has been played
 *
 * @return array{0:int,1:string}|null
 */
function te_referee_release_refusal(PDO $pdo, int $userId, array $event, string $today): ?array
{
    $mine = te_referee_own_row_for_event($pdo, $userId, $event);
    $row = null;
    if ($mine !== null) {
        foreach (te_game_referees_for_event($pdo, (int) $event['id']) as $a) {
            if ((int) $a['id'] === $mine) {
                $row = $a;
            }
        }
    }
    if ($row === null) {
        return [404, 'You are not on this game.'];
    }
    if (strcmp(substr((string) $event['event_date'], 0, 10), $today) < 0) {
        return [422, 'This game has already been played.'];
    }
    if (empty($row['self_assigned'])) {
        return [403, 'The club placed you on this game — ask them to change it.'];
    }
    return null;
}

// ---------------------------------------------------------------------------
// The "needs ref" flag on staff game lists
// ---------------------------------------------------------------------------

/**
 * Two SELECT-list columns for an events query aliased `e`: referee_count and
 * center_referee_count, each one correlated subselect over game_referees —
 * never a per-row query. Empty string until 099 is applied, so the fields are
 * simply absent from older responses.
 */
function te_game_referee_status_columns(PDO $pdo, string $alias = 'e'): string
{
    if (!te_referees_table_present($pdo)) {
        return '';
    }
    return ",
        (SELECT COUNT(*) FROM game_referees gr_all WHERE gr_all.calendar_event_id = {$alias}.id) AS referee_count,
        (SELECT COUNT(*) FROM game_referees gr_c WHERE gr_c.calendar_event_id = {$alias}.id AND gr_c.role = 'center') AS center_referee_count";
}

/**
 * Fold the two counts into `referee_status`: 'covered' when a center referee
 * is assigned, 'needs_ref' for an UPCOMING game without one, absent for
 * anything that is not a game or is in the past (nothing to do about it).
 */
function te_game_referee_status_apply(array $event, string $today): array
{
    if (!array_key_exists('referee_count', $event)) {
        return $event;
    }
    $event['referee_count'] = (int) $event['referee_count'];
    $centers = (int) ($event['center_referee_count'] ?? 0);
    unset($event['center_referee_count']);
    if (($event['type'] ?? '') !== 'game') {
        return $event;
    }
    if ($centers > 0) {
        $event['referee_status'] = 'covered';
    } elseif (strcmp(substr((string) ($event['event_date'] ?? ''), 0, 10), $today) >= 0) {
        $event['referee_status'] = 'needs_ref';
    }
    return $event;
}
