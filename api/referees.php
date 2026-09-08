<?php
/**
 * Referees — club directory, invites, game assignments, and the referee's own
 * games (Maggie, 2026-09-08; migration 099). Every decision lives in
 * lib/referees.php; this file is the HTTP shape, and its handlers return
 * ['status' => int, 'body' => array] so RefereesTest executes the real thing
 * against SQLite (same seam as api/coach-access.php).
 *
 * ACTIONS
 *   GET  ?action=list&club_id=N[&include_archived=1]   staff (admin or coach)
 *   GET  ?action=search&club_id=N&q=                   staff — typeahead, active only
 *   POST ?action=create      {club_id, first_name, last_name, email?, phone?, grade?,
 *                             certification_level?, notes?, invite?}      club admin
 *   PUT  ?action=update      {id, ...fields}                              club admin
 *   POST ?action=archive     {id}      / ?action=restore {id}             club admin
 *   POST ?action=invite      {id}      account + `referee` role + 7-day link  club admin
 *   GET  ?action=for-event&event_id=N                  staff on the event
 *   POST ?action=assign      {event_id, referee_id, role?}                staff on the event
 *   POST ?action=unassign    {event_id, referee_id}                       staff on the event
 *   GET  ?action=my-games                              the signed-in referee (any club)
 *   GET  ?action=open-games                            upcoming games with no center ref, in
 *                                                      the caller's clubs, meeting their grade
 *   POST ?action=claim       {event_id, role?}         the referee takes an open game
 *   POST ?action=release     {event_id}                the referee drops a game they claimed
 *
 * RULES THAT BITE
 *  - A referee is NOT club staff. Nothing here admits the `referee` role to a
 *    directory or a game except `my-games`, which keys on referees.user_id.
 *  - `my-games` is built off the USER ID, never active_context: a referee holds
 *    the role in several clubs and the page lists all of them.
 *  - The invite is lib/coach_invite.php with role 'referee' — same token store,
 *    same ladder, and the token is never in a response.
 *  - A club admin of A cannot list, edit or assign B's referees: every handler
 *    resolves the club from the ROW (or the event) and checks standing there.
 */

// Test hook: defining this loads the collaborators and returns before any side
// effect. Never defined in production; must stay above everything with an effect.
if (defined('TE_REFEREES_LIB_ONLY')) {
    require_once __DIR__ . '/../config/env.php';
    require_once __DIR__ . '/../lib/referees.php';
    require_once __DIR__ . '/../lib/coach_invite.php';
    require_once __DIR__ . '/../lib/feature_flags.php';
    return;
}

require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/referees.php';
require_once __DIR__ . '/../lib/coach_invite.php';
require_once __DIR__ . '/../lib/feature_flags.php';
require_once __DIR__ . '/../lib/portal_status.php';

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

/** @return array{status:int, body:array} */
function referees_fail(int $status, string $message, array $extra = []): array
{
    return ['status' => $status, 'body' => array_merge(['success' => false, 'error' => $message], $extra)];
}

function referees_unavailable(): array
{
    return ['status' => 503, 'body' => ['success' => false, 'available' => false, 'error' => te_referees_unavailable_message()]];
}

function referees_actor($auth): ?int
{
    $id = (int) $auth->getUserId();
    return $id > 0 ? $id : null;
}

/** Resolve a referee by id and require ADMIN standing of ITS club. */
function referees_resolve_admin(PDO $pdo, $auth, $id): array
{
    $id = (int) $id;
    if ($id <= 0) {
        return ['ok' => false, 'fail' => referees_fail(400, 'id is required')];
    }
    $referee = te_referee_find($pdo, $id);
    // Standing is checked against the row's club; a refused caller learns
    // nothing about which ids exist, so missing and forbidden read the same.
    if ($referee === null || !te_is_club_admin($auth, (int) $referee['club_id'])) {
        return ['ok' => false, 'fail' => referees_fail(
            $referee === null ? 404 : 403,
            $referee === null ? 'Referee not found' : 'Only a club admin can manage referees'
        )];
    }
    return ['ok' => true, 'referee' => $referee];
}

/** Resolve an event and require staff standing on it (admin of its club, or a coach of a team on it). */
function referees_resolve_event_staff(PDO $pdo, $auth, $eventId): array
{
    $eventId = (int) $eventId;
    if ($eventId <= 0) {
        return ['ok' => false, 'fail' => referees_fail(400, 'event_id is required')];
    }
    $standing = te_event_staff_standing($pdo, $auth, $eventId);
    if ($standing === null) {
        return ['ok' => false, 'fail' => referees_fail(404, 'Event not found')];
    }
    if ($standing === false) {
        return ['ok' => false, 'fail' => referees_fail(403, 'Only a coach of a team on this game, or a club admin, can assign referees')];
    }
    $event = te_game_for_assignment($pdo, $eventId);
    if ($event === null) {
        return ['ok' => false, 'fail' => referees_fail(404, 'Event not found')];
    }
    return ['ok' => true, 'event' => $event];
}

// ─────────────────────────────────────────────────────────────────────────────
// Directory
// ─────────────────────────────────────────────────────────────────────────────

/**
 * The directory, with portal status for rows that have an email. Status comes
 * from lib/portal_status.php against the `:coach_invite` token key, the same
 * evidence the Coaches page shows — one answer across screens.
 */
function referees_list(PDO $pdo, $auth, array $query): array
{
    $clubId = (int) ($query['club_id'] ?? 0);
    if ($clubId <= 0) {
        return referees_fail(400, 'club_id is required');
    }
    if (!te_is_club_staff($auth, $clubId)) {
        return referees_fail(403, 'Only club staff can see the referee directory');
    }
    if (!te_referees_table_present($pdo)) {
        return ['status' => 200, 'body' => ['success' => true, 'available' => false, 'referees' => [], 'grades' => TE_REFEREE_GRADES]];
    }
    $rows = te_referee_list($pdo, $clubId, te_referee_is_true($query['include_archived'] ?? false));
    $rows = referees_attach_portal_status($pdo, $rows);
    return ['status' => 200, 'body' => ['success' => true, 'available' => true, 'referees' => $rows, 'grades' => TE_REFEREE_GRADES]];
}

/** Portal status per row. Postgres only — the SQLite fixture has no audit_log; a probe failure leaves status absent. */
function referees_attach_portal_status(PDO $pdo, array $rows): array
{
    if (!function_exists('te_portal_status_columns') || empty($rows)) {
        return $rows;
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT r.id AS referee_id, ' . te_portal_status_columns('r.email', 'u', 'coach_invite') . '
               FROM referees r
               LEFT JOIN users u ON u.id = r.user_id
              WHERE r.id = ?'
        );
        foreach ($rows as &$row) {
            if ($row['user_id'] === null && empty($row['email'])) {
                $row['status'] = 'no_email';
                continue;
            }
            $stmt->execute([$row['id']]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $s = te_portal_status($r, (string) ($row['email'] ?? ''), 'coach');
            $row['status'] = $s['status'];
            $row['first_login_at'] = $s['first_login_at'];
            $row['invited_at'] = $s['invited_at'];
        }
        unset($row);
    } catch (Throwable $e) {
        error_log('referees: portal status unavailable: ' . $e->getMessage());
    }
    return $rows;
}

function referees_search(PDO $pdo, $auth, array $query): array
{
    $clubId = (int) ($query['club_id'] ?? 0);
    if ($clubId <= 0) {
        return referees_fail(400, 'club_id is required');
    }
    if (!te_is_club_staff($auth, $clubId)) {
        return referees_fail(403, 'Only club staff can search referees');
    }
    if (!te_referees_table_present($pdo)) {
        return ['status' => 200, 'body' => ['success' => true, 'available' => false, 'referees' => []]];
    }
    $rows = te_referee_search($pdo, $clubId, (string) ($query['q'] ?? ''));
    $out = array_map(fn($r) => [
        'id' => $r['id'], 'name' => $r['name'], 'first_name' => $r['first_name'], 'last_name' => $r['last_name'],
        'email' => $r['email'], 'grade' => $r['grade'], 'certification_level' => $r['certification_level'],
        'user_id' => $r['user_id'],
    ], $rows);
    return ['status' => 200, 'body' => ['success' => true, 'available' => true, 'referees' => $out]];
}

/**
 * @param callable|null $sender injected by tests; production mails as the club
 */
function referees_create(PDO $pdo, $auth, array $body, ?callable $sender = null): array
{
    $clubId = (int) ($body['club_id'] ?? 0);
    if ($clubId <= 0) {
        return referees_fail(400, 'club_id is required');
    }
    if (!te_is_club_admin($auth, $clubId)) {
        return referees_fail(403, 'Only a club admin can add referees');
    }
    if (!te_referees_table_present($pdo)) {
        return referees_unavailable();
    }
    $validated = te_referee_validate($body, false);
    if ($validated['error'] !== null) {
        return referees_fail(422, $validated['error']);
    }
    $values = $validated['values'];

    $dupe = te_referee_find_by_email($pdo, $clubId, $values['email']);
    if ($dupe !== null) {
        return referees_fail(409, "{$dupe['name']} is already in this club's referee directory with that email", [
            'existing_id' => $dupe['id'], 'existing_name' => $dupe['name'], 'existing_archived' => !empty($dupe['archived_at']),
        ]);
    }

    $actor = referees_actor($auth);
    $id = te_referee_create($pdo, $clubId, $values, $actor);
    $referee = te_referee_find($pdo, $id);

    AuditLogger::log($pdo, $actor, 'referee_created', 'referee', $id, [
        'club_id' => $clubId, 'email' => $values['email'], 'linked_user_id' => $referee['user_id'] ?? null,
    ]);

    $invite = null;
    if (te_referee_is_true($body['invite'] ?? false) && !empty($values['email'])) {
        $invite = referees_invite_row($pdo, $auth, $referee, $sender);
        $referee = te_referee_find($pdo, $id);
    }

    return ['status' => 201, 'body' => ['success' => true, 'id' => $id, 'referee' => $referee, 'invite' => $invite]];
}

function referees_update(PDO $pdo, $auth, array $body): array
{
    if (!te_referees_table_present($pdo)) {
        return referees_unavailable();
    }
    $r = referees_resolve_admin($pdo, $auth, $body['id'] ?? 0);
    if (!$r['ok']) {
        return $r['fail'];
    }
    $referee = $r['referee'];

    $validated = te_referee_validate($body, true);
    if ($validated['error'] !== null) {
        return referees_fail(422, $validated['error']);
    }
    $values = $validated['values'];

    if (array_key_exists('email', $values)) {
        $dupe = te_referee_find_by_email($pdo, (int) $referee['club_id'], $values['email'], (int) $referee['id']);
        if ($dupe !== null) {
            return referees_fail(409, "{$dupe['name']} is already in this club's referee directory with that email", [
                'existing_id' => $dupe['id'], 'existing_name' => $dupe['name'],
            ]);
        }
    }

    te_referee_update($pdo, (int) $referee['id'], $values);
    $after = te_referee_find($pdo, (int) $referee['id']);

    AuditLogger::log($pdo, referees_actor($auth), 'referee_updated', 'referee', (int) $referee['id'], [
        'club_id' => $referee['club_id'], 'fields' => array_keys($values),
    ]);

    return ['status' => 200, 'body' => ['success' => true, 'referee' => $after]];
}

function referees_set_archived(PDO $pdo, $auth, array $body, bool $archived): array
{
    if (!te_referees_table_present($pdo)) {
        return referees_unavailable();
    }
    $r = referees_resolve_admin($pdo, $auth, $body['id'] ?? 0);
    if (!$r['ok']) {
        return $r['fail'];
    }
    $referee = $r['referee'];
    te_referee_set_archived($pdo, (int) $referee['id'], $archived);

    AuditLogger::log($pdo, referees_actor($auth), $archived ? 'referee_archived' : 'referee_restored', 'referee', (int) $referee['id'], [
        'club_id' => $referee['club_id'],
    ]);

    return ['status' => 200, 'body' => ['success' => true, 'referee' => te_referee_find($pdo, (int) $referee['id'])]];
}

// ─────────────────────────────────────────────────────────────────────────────
// Invite — account + `referee` role in this club + 7-day link
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Shared by `create` (with invite ticked) and `invite`. An address that already
 * has an account is ATTACHED — the role is added in this club and the
 * directory row is linked — never duplicated. The token is never returned.
 */
function referees_invite_row(PDO $pdo, $auth, array $referee, ?callable $sender = null): array
{
    if (empty($referee['email'])) {
        return ['status' => 'no_email', 'sent' => false, 'message' => 'This referee has no email address on file.'];
    }
    $clubId = (int) $referee['club_id'];
    $actor = referees_actor($auth);

    $ensure = te_coach_invite_ensure_user_and_token(
        $pdo,
        [
            'first_name' => $referee['first_name'], 'last_name' => $referee['last_name'],
            'email' => $referee['email'], 'phone' => $referee['phone'] ?? '',
        ],
        $clubId,
        $actor,
        'referees_page',
        'referee'
    );
    if ($ensure['status'] === 'error') {
        return ['status' => 'error', 'sent' => false, 'message' => $ensure['message']];
    }
    if ($ensure['status'] === 'access_revoked') {
        return ['status' => 'access_revoked', 'sent' => false, 'user_id' => $ensure['user_id'],
                'message' => 'This person\'s referee access to the club was revoked. Restore it rather than re-inviting them.'];
    }

    $userId = (int) $ensure['user_id'];
    if ((int) ($referee['user_id'] ?? 0) !== $userId) {
        te_referee_set_user($pdo, (int) $referee['id'], $userId);
    }

    if ($ensure['status'] === 'already_active') {
        AuditLogger::log($pdo, $actor, 'referee_invite_attached', 'referee', (int) $referee['id'], [
            'club_id' => $clubId, 'user_id' => $userId, 'access' => $ensure['access'] ?? null,
        ]);
        return ['status' => 'already_active', 'sent' => false, 'user_id' => $userId,
                'message' => 'They already have an account; they have been added to this club as a referee and can sign in as usual.'];
    }

    $mail = te_coach_invite_send($pdo, $userId, $clubId, $sender, $actor, 'coach_invite_sent', te_coach_invite_role_label('referee'));
    $sent = !empty($mail['sent']);
    return [
        'status' => 'invited', 'sent' => $sent, 'user_id' => $userId, 'reason' => $mail['reason'] ?? null,
        'feature_disabled' => $mail['feature_disabled'] ?? null,
        'message' => $sent
            ? 'An invitation to set their password has been emailed to them.'
            : 'The invitation email was not sent' . (!empty($mail['feature_disabled']) ? ' (invite emails are switched off).' : '. Try again later.'),
    ];
}

function referees_invite(PDO $pdo, $auth, array $body, ?callable $sender = null): array
{
    if (!te_referees_table_present($pdo)) {
        return referees_unavailable();
    }
    $r = referees_resolve_admin($pdo, $auth, $body['id'] ?? 0);
    if (!$r['ok']) {
        return $r['fail'];
    }
    $referee = $r['referee'];
    if (empty($referee['email'])) {
        return referees_fail(422, 'This referee has no email address on file — add one first.');
    }
    if (!empty($referee['archived_at'])) {
        return referees_fail(422, 'This referee is archived — restore them first.');
    }
    $invite = referees_invite_row($pdo, $auth, $referee, $sender);
    if ($invite['status'] === 'error') {
        return referees_fail(400, $invite['message']);
    }
    if ($invite['status'] === 'access_revoked') {
        return referees_fail(409, $invite['message'], ['reason' => 'access_revoked']);
    }
    return ['status' => 200, 'body' => ['success' => true, 'invite' => $invite, 'referee' => te_referee_find($pdo, (int) $referee['id'])]];
}

// ─────────────────────────────────────────────────────────────────────────────
// Game assignments
// ─────────────────────────────────────────────────────────────────────────────

function referees_for_event(PDO $pdo, $auth, array $query): array
{
    $r = referees_resolve_event_staff($pdo, $auth, $query['event_id'] ?? 0);
    if (!$r['ok']) {
        return $r['fail'];
    }
    if (!te_referees_table_present($pdo)) {
        return ['status' => 200, 'body' => ['success' => true, 'available' => false, 'referees' => [], 'roles' => TE_GAME_REFEREE_ROLES]];
    }
    return ['status' => 200, 'body' => [
        'success' => true, 'available' => true,
        'referees' => te_game_referees_for_event($pdo, (int) $r['event']['id']),
        'roles' => TE_GAME_REFEREE_ROLES,
    ]];
}

function referees_assign(PDO $pdo, $auth, array $body): array
{
    $r = referees_resolve_event_staff($pdo, $auth, $body['event_id'] ?? 0);
    if (!$r['ok']) {
        return $r['fail'];
    }
    $event = $r['event'];
    if (!te_referees_table_present($pdo)) {
        return referees_unavailable();
    }
    $refereeId = (int) ($body['referee_id'] ?? 0);
    if ($refereeId <= 0) {
        return referees_fail(400, 'referee_id is required');
    }
    $role = te_game_referee_role($body['role'] ?? null);
    if ($role === null) {
        return referees_fail(422, 'role must be one of: ' . implode(', ', TE_GAME_REFEREE_ROLES));
    }
    $referee = te_referee_find($pdo, $refereeId);
    $reason = te_game_referee_assignability($event, $referee);
    if ($reason !== null) {
        return referees_fail(422, $reason);
    }

    // Staff may place someone below the game's minimum grade — they may know
    // better — and the row and the audit row both say so.
    $override = !te_referee_grade_qualifies($referee['grade'] ?? null, $event['min_referee_grade'] ?? null, $role);
    // Same for a time clash: allowed, recorded, and the response carries the warning.
    $conflict = te_referee_conflict_for($pdo, te_referee_row_ids_for_person($pdo, $referee), $event);
    te_game_referee_assign($pdo, (int) $event['id'], $refereeId, $role, referees_actor($auth), false, $override, $conflict !== null);
    AuditLogger::log($pdo, referees_actor($auth), 'referee_assigned_to_game', 'calendar_event', (int) $event['id'], [
        'club_id' => $event['club_id'], 'referee_id' => $refereeId, 'role' => $role,
        'grade_override' => $override, 'referee_grade' => $referee['grade'] ?? null,
        'min_referee_grade' => $event['min_referee_grade'] ?? null,
        'conflict_override' => $conflict !== null, 'conflicts_with_event_id' => $conflict['id'] ?? null,
    ]);
    $warnings = [];
    if ($override) {
        $warnings[] = 'Placed below the game\'s requirements (grade); the assignment is marked.';
    }
    if ($conflict !== null) {
        $warnings[] = te_referee_conflict_sentence($conflict, false) . ' The assignment is marked.';
    }
    return ['status' => 200, 'body' => ['success' => true, 'warnings' => $warnings, 'referees' => te_game_referees_for_event($pdo, (int) $event['id'])]];
}

function referees_unassign(PDO $pdo, $auth, array $body): array
{
    $r = referees_resolve_event_staff($pdo, $auth, $body['event_id'] ?? 0);
    if (!$r['ok']) {
        return $r['fail'];
    }
    $event = $r['event'];
    if (!te_referees_table_present($pdo)) {
        return referees_unavailable();
    }
    $refereeId = (int) ($body['referee_id'] ?? 0);
    if ($refereeId <= 0) {
        return referees_fail(400, 'referee_id is required');
    }
    $removed = te_game_referee_unassign($pdo, (int) $event['id'], $refereeId);
    if ($removed) {
        AuditLogger::log($pdo, referees_actor($auth), 'referee_unassigned_from_game', 'calendar_event', (int) $event['id'], [
            'club_id' => $event['club_id'], 'referee_id' => $refereeId,
        ]);
    }
    return ['status' => 200, 'body' => ['success' => true, 'removed' => $removed, 'referees' => te_game_referees_for_event($pdo, (int) $event['id'])]];
}

// ─────────────────────────────────────────────────────────────────────────────
// The referee's own view
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Standing: any user with at least one referees row carrying their id — in
 * ANY club, whatever the token's active context. A user with no rows gets an
 * empty answer, not a 403: "the club has not connected you yet" is the message
 * the page shows, and it is a real state.
 */
function referees_my_games(PDO $pdo, $auth, string $today): array
{
    $userId = referees_actor($auth);
    if ($userId === null) {
        return referees_fail(401, 'Not signed in');
    }
    if (!te_referees_table_present($pdo)) {
        return ['status' => 200, 'body' => ['success' => true, 'available' => false, 'clubs' => [], 'upcoming' => [], 'past' => [], 'today' => $today]];
    }
    $clubs = te_referee_clubs_for_user($pdo, $userId);
    $games = te_referee_my_games($pdo, $userId, $today);
    return ['status' => 200, 'body' => [
        'success' => true, 'available' => true, 'today' => $today,
        'clubs' => $clubs, 'upcoming' => $games['upcoming'], 'past' => $games['past'],
    ]];
}

function referees_open_games(PDO $pdo, $auth, string $today): array
{
    $userId = referees_actor($auth);
    if ($userId === null) {
        return referees_fail(401, 'Not signed in');
    }
    if (!te_referees_table_present($pdo)) {
        return ['status' => 200, 'body' => ['success' => true, 'available' => false, 'games' => [], 'roles' => TE_GAME_REFEREE_ROLES, 'today' => $today]];
    }
    return ['status' => 200, 'body' => [
        'success' => true, 'available' => true, 'today' => $today,
        'games' => te_referee_open_games($pdo, $userId, $today), 'roles' => TE_GAME_REFEREE_ROLES,
    ]];
}

/** The referee takes an open game. Every refusal is re-derived here; the list is never trusted. */
function referees_claim(PDO $pdo, $auth, array $body, string $today): array
{
    $userId = referees_actor($auth);
    if ($userId === null) {
        return referees_fail(401, 'Not signed in');
    }
    if (!te_referees_table_present($pdo)) {
        return referees_unavailable();
    }
    $eventId = (int) ($body['event_id'] ?? 0);
    if ($eventId <= 0) {
        return referees_fail(400, 'event_id is required');
    }
    $role = te_game_referee_role($body['role'] ?? 'center');
    if ($role === null) {
        return referees_fail(422, 'role must be one of: ' . implode(', ', TE_GAME_REFEREE_ROLES));
    }
    $event = te_game_for_assignment($pdo, $eventId);
    if ($event === null) {
        return referees_fail(404, 'Game not found');
    }
    $refusal = te_referee_claim_refusal($pdo, $userId, $event, $role, $today);
    if ($refusal !== null) {
        return referees_fail($refusal[0], $refusal[1]);
    }
    $refereeId = te_referee_own_row_for_event($pdo, $userId, $event);
    te_game_referee_assign($pdo, $eventId, (int) $refereeId, $role, $userId, true, false);
    AuditLogger::log($pdo, $userId, 'referee_self_assigned', 'calendar_event', $eventId, [
        'club_id' => $event['club_id'], 'referee_id' => $refereeId, 'role' => $role,
    ]);
    return ['status' => 200, 'body' => ['success' => true, 'referees' => te_game_referees_for_event($pdo, $eventId)]];
}

/** The referee drops a game they claimed themselves. Staff-placed rows are the club's to change. */
function referees_release(PDO $pdo, $auth, array $body, string $today): array
{
    $userId = referees_actor($auth);
    if ($userId === null) {
        return referees_fail(401, 'Not signed in');
    }
    if (!te_referees_table_present($pdo)) {
        return referees_unavailable();
    }
    $eventId = (int) ($body['event_id'] ?? 0);
    if ($eventId <= 0) {
        return referees_fail(400, 'event_id is required');
    }
    $event = te_game_for_assignment($pdo, $eventId);
    if ($event === null) {
        return referees_fail(404, 'Game not found');
    }
    $refusal = te_referee_release_refusal($pdo, $userId, $event, $today);
    if ($refusal !== null) {
        return referees_fail($refusal[0], $refusal[1]);
    }
    $refereeId = (int) te_referee_own_row_for_event($pdo, $userId, $event);
    te_game_referee_unassign($pdo, $eventId, $refereeId);
    AuditLogger::log($pdo, $userId, 'referee_released', 'calendar_event', $eventId, [
        'club_id' => $event['club_id'], 'referee_id' => $refereeId,
    ]);
    return ['status' => 200, 'body' => ['success' => true, 'referees' => te_game_referees_for_event($pdo, $eventId)]];
}

// ─────────────────────────────────────────────────────────────────────────────
// Dispatch
// ─────────────────────────────────────────────────────────────────────────────

try {
    $pdo = Database::getInstance()->getConnection();
} catch (Exception $e) {
    error_log('referees: DB connection failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$auth = AuthMiddleware::requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$action = (string) ($_GET['action'] ?? '');
$input = [];
if ($method !== 'GET') {
    $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
}
// "Today" is a date-only string in the server's zone, compared against
// calendar_events.event_date as a string. Never a DateTime.
$today = date('Y-m-d');

switch ($action) {
    case 'list':
        $result = referees_list($pdo, $auth, $_GET);
        break;
    case 'search':
        $result = referees_search($pdo, $auth, $_GET);
        break;
    case 'create':
        $result = $method === 'POST' ? referees_create($pdo, $auth, $input) : referees_fail(405, 'Method not allowed');
        break;
    case 'update':
        $result = in_array($method, ['PUT', 'POST'], true) ? referees_update($pdo, $auth, $input) : referees_fail(405, 'Method not allowed');
        break;
    case 'archive':
        $result = $method === 'POST' ? referees_set_archived($pdo, $auth, $input, true) : referees_fail(405, 'Method not allowed');
        break;
    case 'restore':
        $result = $method === 'POST' ? referees_set_archived($pdo, $auth, $input, false) : referees_fail(405, 'Method not allowed');
        break;
    case 'invite':
        $result = $method === 'POST' ? referees_invite($pdo, $auth, $input) : referees_fail(405, 'Method not allowed');
        break;
    case 'for-event':
        $result = referees_for_event($pdo, $auth, $_GET);
        break;
    case 'assign':
        $result = $method === 'POST' ? referees_assign($pdo, $auth, $input) : referees_fail(405, 'Method not allowed');
        break;
    case 'unassign':
        $result = $method === 'POST' ? referees_unassign($pdo, $auth, $input) : referees_fail(405, 'Method not allowed');
        break;
    case 'my-games':
        $result = referees_my_games($pdo, $auth, $today);
        break;
    case 'open-games':
        $result = referees_open_games($pdo, $auth, $today);
        break;
    case 'claim':
        $result = $method === 'POST' ? referees_claim($pdo, $auth, $input, $today) : referees_fail(405, 'Method not allowed');
        break;
    case 'release':
        $result = $method === 'POST' ? referees_release($pdo, $auth, $input, $today) : referees_fail(405, 'Method not allowed');
        break;
    default:
        $result = referees_fail(400, 'Unknown action');
}

http_response_code($result['status']);
echo json_encode($result['body']);
