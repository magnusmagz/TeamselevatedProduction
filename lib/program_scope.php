<?php
/**
 * Program staffing scope — which programs a user runs, and who is signed up.
 *
 * Camps, clinics and drop-ins have registrants and no roster. `team_members` is
 * empty for them, so getCoachTeamIds() — the one place team scope is derived —
 * correctly answers "no teams" for the coach running a camp, and every scope
 * built on it then answers "nobody". That is not a bug in team scoping; programs
 * are simply a different axis, and this file is that axis.
 *
 * Two rules, both load-bearing:
 *
 * 1. **This is the ONLY source of program standing.** Nothing infers it from a
 *    club role, a team, or from having registered someone. Same reason
 *    `getCoachTeamIds()` is the only source of team scope: a second derivation
 *    is a second thing to fix when the rule changes, and the two will disagree.
 *
 * 2. **Every function tolerates `program_staff` being absent.** Migrations here
 *    are applied to Neon by hand and `main` is shared, so this code reaches
 *    production the moment any session pushes — potentially days before
 *    migration 086 runs. On Postgres a SELECT against a missing table is 42P01,
 *    a hard error that would take the calendar and the recipient typeahead down
 *    for everyone rather than merely hiding a new feature. The probe answers
 *    false on any failure, and the degraded answer is always the NARROW one:
 *    no programs, so nothing is widened. Same shape as
 *    lib/program_ordering.php's column probe.
 */

/**
 * Is the `program_staff` table live?
 *
 * Memoised per PDO instance rather than per process: the test suite builds one
 * connection with the table and one without, and a process-wide static would let
 * the first answer decide the second.
 *
 * The information_schema probe is the Postgres answer. SQLite (the test
 * database) has no information_schema at all, so that query throws and the
 * fallback asks the table directly — which is safe there precisely because
 * SQLite has no transaction to poison.
 */
function te_program_staff_table_present(PDO $pdo): bool
{
    // WeakMap, not an array keyed by spl_object_id: object ids are REUSED after
    // an object is freed, and the test suite builds one connection with the
    // table and one without. An id-keyed cache would let the first connection's
    // answer decide the second's.
    static $memo = null;
    $memo ??= new WeakMap();
    if (isset($memo[$pdo])) {
        return $memo[$pdo];
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM information_schema.tables
              WHERE table_name = 'program_staff' LIMIT 1"
        );
        $stmt->execute();
        return $memo[$pdo] = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        // No information_schema — ask the table itself. Safe here precisely
        // because the only database without one is the SQLite the tests run on,
        // where a failed statement has no transaction to poison.
        try {
            $pdo->query('SELECT 1 FROM program_staff LIMIT 1');
            return $memo[$pdo] = true;
        } catch (Throwable $e2) {
            return $memo[$pdo] = false;
        }
    }
}

/**
 * Programs this user staffs, across every club.
 *
 * Club scoping is deliberately NOT done here. Every caller already constrains
 * its own query by club — `a.club_id = ?` in the recipient search, `e.club_id`
 * in the calendar — so filtering again here would be a second club predicate
 * that could drift from the first. Callers that need the club must intersect,
 * not trust.
 *
 * @return int[] Program ids, re-indexed. `array_values` is not cosmetic: several
 *               callers pass the result straight to PDO::execute() as positional
 *               parameters, and PDO rejects a non-sequential array — that is the
 *               `getAccessibleClubIds()` bug, and it 500'd seven accounts.
 */
function te_program_ids_for_user(PDO $pdo, int $userId): array
{
    if ($userId <= 0 || !te_program_staff_table_present($pdo)) {
        return [];
    }

    try {
        $stmt = $pdo->prepare('SELECT DISTINCT program_id FROM program_staff WHERE user_id = ?');
        $stmt->execute([$userId]);
        return array_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: []));
    } catch (Throwable $e) {
        error_log('te_program_ids_for_user: ' . $e->getMessage());
        return [];
    }
}

/**
 * Athletes registered to a program.
 *
 * `status <> 'rejected'` rather than `status = 'approved'`: a camp coach needs
 * to reach the family that signed up this morning and has not been reviewed, and
 * 'pending' is where every registration starts (registrations-api.php). Rejected
 * is the one state that means "this family is not in this program".
 *
 * NULL `athlete_id` rows are skipped. A public registration can be submitted
 * before an athlete record exists (registrant_first_name / registrant_email carry
 * it instead), and there is no athlete to scope to yet — returning nothing for
 * those is narrower than guessing, which is the right direction to be wrong in.
 *
 * @return int[] Athlete ids, re-indexed for the same PDO reason as above.
 */
function te_program_registrant_athlete_ids(PDO $pdo, int $programId): array
{
    if ($programId <= 0) {
        return [];
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT DISTINCT r.athlete_id
               FROM registrations r
              WHERE r.program_id = ?
                AND r.athlete_id IS NOT NULL
                AND (r.status IS NULL OR LOWER(r.status) <> 'rejected')"
        );
        $stmt->execute([$programId]);
        return array_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: []));
    } catch (Throwable $e) {
        error_log('te_program_registrant_athlete_ids: ' . $e->getMessage());
        return [];
    }
}

/**
 * Does this user hold coach or club_admin standing in this club?
 *
 * The gate on assign-staff. A parent must never be assignable — program staff is
 * a reach grant, and handing it to a guardian would give one family the contact
 * details of every other family in the camp.
 *
 * Both branches of "who is a coach here" are accepted, matching
 * `legacy/coaches-gateway.php?action=available` — the list the assignment UI
 * picks from. A user_club_access row is the authoritative form, but a person who
 * is only ever `teams.primary_coach_id` appears in that list and would otherwise
 * be offered and then refused.
 *
 * `revoked_at IS NULL` alongside `active`: the two columns can disagree, and when
 * they do the revocation is the newer fact (lib/JWT.php learned this the same
 * way).
 */
function te_user_holds_club_staff_standing(PDO $pdo, int $userId, int $clubId): bool
{
    if ($userId <= 0 || $clubId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare(
        "SELECT 1
           FROM user_club_access uca
          WHERE uca.user_id = ?
            AND uca.club_profile_id = ?
            AND uca.role IN ('club_admin', 'coach')
            AND uca.active = true
            AND uca.revoked_at IS NULL
          LIMIT 1"
    );
    $stmt->execute([$userId, $clubId]);
    if ($stmt->fetchColumn()) {
        return true;
    }

    $stmt = $pdo->prepare(
        'SELECT 1 FROM teams
          WHERE primary_coach_id = ? AND club_id = ? AND deleted_at IS NULL
          LIMIT 1'
    );
    $stmt->execute([$userId, $clubId]);
    return (bool)$stmt->fetchColumn();
}

/**
 * The program's club, or null when the program does not exist.
 *
 * Authorisation for every write below is against THIS club, never against a
 * club_id in the request body — the same rule pg_programClubId() already
 * enforces for archive and reorder. An admin naming their own club must not be
 * able to staff another club's camp.
 */
function te_program_club_id(PDO $pdo, int $programId): ?int
{
    if ($programId <= 0) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT club_id FROM programs WHERE id = ?');
    $stmt->execute([$programId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    return $row['club_id'] === null ? null : (int)$row['club_id'];
}

/** The three values migration 086's CHECK constraint accepts. */
const TE_PROGRAM_STAFF_ROLES = ['coach', 'assistant', 'manager'];

/** Staff assigned to one program, newest last. */
function te_program_staff_list(PDO $pdo, int $programId): array
{
    if ($programId <= 0 || !te_program_staff_table_present($pdo)) {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT ps.user_id, ps.role, ps.assigned_by, ps.created_at,
                u.first_name, u.last_name, u.email
           FROM program_staff ps
           JOIN users u ON u.id = ps.user_id
          WHERE ps.program_id = ?
          ORDER BY u.last_name, u.first_name'
    );
    $stmt->execute([$programId]);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = [
            'user_id'     => (int)$r['user_id'],
            'first_name'  => $r['first_name'],
            'last_name'   => $r['last_name'],
            'email'       => $r['email'],
            'role'        => $r['role'],
            'assigned_by' => $r['assigned_by'] === null ? null : (int)$r['assigned_by'],
            'created_at'  => $r['created_at'],
        ];
    }
    return $out;
}

/**
 * Assign someone to a program.
 *
 * The authorisation lives here rather than in the gateway so it is reachable by
 * a test: the gateway connects to Neon at load and requires a token, which is
 * exactly why `AthleteController`'s guardian writes went unaudited for so long —
 * nothing could exercise them.
 *
 * Two refusals, and they are different failures:
 *   'forbidden'    — the ACTOR is not a club admin of this program's club. That
 *                    covers the cross-club case: the club is read off the
 *                    program, so an admin of another club fails here.
 *   'not_staff'    — the TARGET holds no coach or club_admin standing in the
 *                    club. A parent must never be assignable: this row is a
 *                    reach grant over every registered family's contact details.
 *
 * @return array{ok: bool, status: int, reason?: string, club_id?: int, role?: string}
 */
function te_program_staff_assign(PDO $pdo, $auth, int $programId, int $userId, string $role, ?int $actorId): array
{
    require_once __DIR__ . '/club_standing.php';

    $role = strtolower(trim($role)) ?: 'coach';
    if (!in_array($role, TE_PROGRAM_STAFF_ROLES, true)) {
        return ['ok' => false, 'status' => 400, 'reason' => 'bad_role'];
    }
    if ($userId <= 0) {
        return ['ok' => false, 'status' => 400, 'reason' => 'bad_user'];
    }

    $clubId = te_program_club_id($pdo, $programId);
    if ($clubId === null) {
        return ['ok' => false, 'status' => 404, 'reason' => 'not_found'];
    }
    if (!te_is_club_admin($auth, $clubId)) {
        return ['ok' => false, 'status' => 403, 'reason' => 'forbidden', 'club_id' => $clubId];
    }

    // Checked AFTER the actor's standing so a stranger cannot use the response
    // to probe who holds what role in a club they have no business in.
    if (!te_user_holds_club_staff_standing($pdo, $userId, $clubId)) {
        return ['ok' => false, 'status' => 422, 'reason' => 'not_staff', 'club_id' => $clubId];
    }

    if (!te_program_staff_table_present($pdo)) {
        return ['ok' => false, 'status' => 503, 'reason' => 'schema', 'club_id' => $clubId];
    }

    // Upsert, not insert: UNIQUE(program_id, user_id) means a re-assign with a
    // different role would otherwise be a constraint violation the admin cannot
    // act on, and a second row would outlive the removal of the first.
    $stmt = $pdo->prepare(
        'INSERT INTO program_staff (program_id, user_id, role, assigned_by)
         VALUES (?, ?, ?, ?)
         ON CONFLICT (program_id, user_id)
         DO UPDATE SET role = EXCLUDED.role, assigned_by = EXCLUDED.assigned_by'
    );
    $stmt->execute([$programId, $userId, $role, $actorId]);

    return ['ok' => true, 'status' => 200, 'club_id' => $clubId, 'role' => $role];
}

/**
 * Remove someone from a program.
 *
 * `removed` is false when there was no row, which is a distinct answer from a
 * refusal — the admin gets "already not assigned" rather than an error about
 * something they cannot fix.
 *
 * @return array{ok: bool, status: int, reason?: string, club_id?: int, removed?: bool}
 */
function te_program_staff_remove(PDO $pdo, $auth, int $programId, int $userId, ?int $actorId): array
{
    require_once __DIR__ . '/club_standing.php';

    if ($userId <= 0) {
        return ['ok' => false, 'status' => 400, 'reason' => 'bad_user'];
    }

    $clubId = te_program_club_id($pdo, $programId);
    if ($clubId === null) {
        return ['ok' => false, 'status' => 404, 'reason' => 'not_found'];
    }
    if (!te_is_club_admin($auth, $clubId)) {
        return ['ok' => false, 'status' => 403, 'reason' => 'forbidden', 'club_id' => $clubId];
    }
    if (!te_program_staff_table_present($pdo)) {
        return ['ok' => false, 'status' => 503, 'reason' => 'schema', 'club_id' => $clubId];
    }

    $stmt = $pdo->prepare('DELETE FROM program_staff WHERE program_id = ? AND user_id = ?');
    $stmt->execute([$programId, $userId]);

    return ['ok' => true, 'status' => 200, 'club_id' => $clubId, 'removed' => $stmt->rowCount() > 0];
}
