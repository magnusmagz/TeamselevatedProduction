<?php
/**
 * Scope as SQL, not as a list of ids.
 *
 * Every scope predicate in this codebase used to answer "which athletes / teams
 * may this person reach" by SELECTing the ids into PHP and interpolating one
 * placeholder per id:
 *
 *     $ph = implode(',', array_fill(0, count($ids), '?'));   // IN (?,?,?,…)
 *
 * That has three failure modes, in increasing order of how badly it ends:
 *
 * 1. `array_fill(0, 0, '?')` produces `IN ()`, which is a SYNTAX ERROR in
 *    Postgres rather than an empty result — a 500, not a refusal. Every site had
 *    to remember its own emptiness guard, and `handleChatSearch` did not (fixed
 *    2026-08-14).
 * 2. Two round trips where one would do, and the planner never sees the
 *    relationship between the ids and the table they came from.
 * 3. **Postgres caps bind parameters at 65,535.** `accessibleAthleteFilter()`
 *    inlined one placeholder per ATHLETE. A GOTR division admin over 30 councils
 *    is within an order of magnitude of a hard protocol error, and planner time
 *    dies long before the cap does (docs/gotr-hierarchy-plan-2026-09.md §5).
 *
 * So this file is the set-shaped answer: each function returns a SQL fragment
 * plus its (few, bounded) parameters, to be embedded as `IN (<sql>)` or inside
 * an `EXISTS`. The result is a single statement with a handful of binds
 * regardless of how many athletes, teams or families the caller can reach.
 *
 * ⚠️ **Club ids stay a materialised `IN (?,?,…)` list, deliberately.** A person
 * holds a role in a handful of clubs — the largest live case is 2 — and the list
 * comes from the JWT, which is already in memory. Turning that into a subquery
 * would add a join to `user_club_access` on every scoped query to save nothing.
 * `tests/php/NoScopeIdListsTest.php` allowlists those sites by name with a
 * reason each; it fails on any NEW athlete- or team-id list.
 *
 * ⚠️ **These fragments must stay byte-for-byte equivalent to the id-list
 * versions they replaced**, including their omissions. Two that bite:
 *   - `getCoachTeamIds()` (lib/coach_scope.php) has NO `deleted_at` filter and
 *     `AthleteScope::coachTeamIdsForUser()` HAS one. That divergence is
 *     pre-existing and deliberate ("tightening it changes who can send in both
 *     gateways at once and belongs in its own change"), so it is a parameter
 *     here rather than a decision this file makes.
 *   - The guardian chain is `te_guardian_link_sql()` and nothing else. Never
 *     re-inline the `users.email = guardians.email` comparison; that is the bug
 *     lib/guardian_identity.php exists to end.
 *
 * Positional `?` parameters throughout, because every call site merges these
 * into an existing positional list.
 *
 * Every set-valued fragment names its single column **`scope_id`**. Nothing
 * needs that for `IN (<subquery>)`, but te_scope_all_ids_within() does, and a
 * UNION takes its column names from the FIRST branch — so two fragments that
 * disagreed would work until the day they were unioned in the other order.
 */

require_once __DIR__ . '/guardian_identity.php';
require_once __DIR__ . '/program_scope.php';

/**
 * Is `athletes.club_id` present in this schema?
 *
 * AthleteScope used to answer this with a try/catch around the real query. That
 * works when the id list is built in PHP (the branch is simply skipped) and does
 * not when the branch is one arm of a single composite predicate: a missing
 * column would take the whole list query down instead of omitting one source of
 * athletes. Several older test fixtures omit the column, and on Postgres a
 * reference to one that is not there is 42703.
 *
 * Memoised per PDO instance in a WeakMap, for the reason spelled out in
 * te_program_staff_table_present(): spl_object_id values are REUSED after an
 * object is freed, and the suite builds one connection with the column and one
 * without.
 */
function te_scope_athletes_have_club_id(PDO $pdo): bool
{
    static $memo = null;
    $memo ??= new WeakMap();
    if (isset($memo[$pdo])) {
        return $memo[$pdo];
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM information_schema.columns
              WHERE table_name = 'athletes' AND column_name = 'club_id' LIMIT 1"
        );
        $stmt->execute();
        return $memo[$pdo] = (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        // No information_schema — ask the table itself. The only database
        // without one here is the SQLite the tests run on, where a failed
        // statement has no transaction to poison.
        try {
            $pdo->query('SELECT club_id FROM athletes LIMIT 0');
            return $memo[$pdo] = true;
        } catch (Throwable $e2) {
            return $memo[$pdo] = false;
        }
    }
}

/**
 * A `?,?,…` placeholder list, or null when there is nothing to place.
 *
 * Returning null rather than an empty string is the whole point: `IN ()` is a
 * syntax error, so a caller is forced to branch instead of silently emitting one.
 *
 * @param array<int|string> $values
 */
function te_scope_placeholders(array $values): ?string
{
    return $values ? implode(',', array_fill(0, count($values), '?')) : null;
}

/**
 * Subquery selecting the team ids this user coaches.
 *
 * Mirrors getCoachTeamIds() / AthleteScope::coachTeamIdsForUser(): primary coach
 * of the team, OR an active assistant_coach / team_manager membership.
 *
 * @param int      $userId
 * @param int|null $clubId         constrain to one club (getCoachTeamIds does; AthleteScope does not)
 * @param bool     $excludeDeleted filter soft-deleted teams (AthleteScope does; getCoachTeamIds does NOT)
 * @param string   $tag            alias suffix, so two of these can sit in one query
 * @return array{sql:string, params:array<int,int>}
 */
function te_scope_coach_team_ids_sql(
    int $userId,
    ?int $clubId = null,
    bool $excludeDeleted = false,
    string $tag = 'ct'
): array {
    $t  = "t_{$tag}";
    $tm = "tm_{$tag}";

    $sql = "SELECT DISTINCT {$t}.id AS scope_id
              FROM teams {$t}
              LEFT JOIN team_members {$tm}
                     ON {$tm}.team_id = {$t}.id
                    AND {$tm}.user_id = ?
                    AND {$tm}.role IN ('assistant_coach','team_manager')
                    AND {$tm}.status = 'active'
             WHERE ({$t}.primary_coach_id = ? OR {$tm}.id IS NOT NULL)";
    $params = [$userId, $userId];

    if ($clubId !== null) {
        $sql .= " AND {$t}.club_id = ?";
        $params[] = $clubId;
    }
    if ($excludeDeleted) {
        $sql .= " AND {$t}.deleted_at IS NULL";
    }

    return ['sql' => $sql, 'params' => $params];
}

/**
 * Subquery selecting the team ids this user reaches as a GUARDIAN — the teams
 * their own children are on. Mirrors te_chat_parent_team_ids().
 *
 * @return array{sql:string, params:array<int,int>}
 */
function te_scope_guardian_team_ids_sql(int $userId, ?int $clubId = null, string $tag = 'gt'): array
{
    $u  = "u_{$tag}";
    $g  = "g_{$tag}";
    $ag = "ag_{$tag}";
    $tm = "tm_{$tag}";
    $t  = "t_{$tag}";
    $link = te_guardian_link_sql($u, $g);

    $sql = "SELECT DISTINCT {$tm}.team_id AS scope_id
              FROM users {$u}
              JOIN guardians {$g} ON {$link}
              JOIN athlete_guardians {$ag} ON {$ag}.guardian_id = {$g}.id
              JOIN team_members {$tm} ON {$tm}.athlete_id = {$ag}.athlete_id AND {$tm}.status = 'active'";
    $params = [];

    if ($clubId !== null) {
        $sql .= " JOIN teams {$t} ON {$t}.id = {$tm}.team_id AND {$t}.club_id = ? AND {$t}.deleted_at IS NULL";
        $params[] = $clubId;
    }

    $sql .= " WHERE {$u}.id = ?";
    $params[] = $userId;

    return ['sql' => $sql, 'params' => $params];
}

/**
 * EXISTS predicate: is `$athleteColumn` an athlete this user is a guardian of?
 *
 * The guardian chain in one statement — user_guardians links UNION the email
 * match, via te_guardian_link_sql(), then athlete_guardians. Same set as
 * te_athlete_ids_for_user(), without materialising it.
 *
 * @param string $athleteColumn a column reference in the OUTER query
 * @return array{sql:string, params:array<int,int>}
 */
function te_scope_guardian_athlete_exists_sql(string $athleteColumn, int $userId, string $tag = 'ga'): array
{
    $u  = "u_{$tag}";
    $g  = "g_{$tag}";
    $ag = "ag_{$tag}";
    $link = te_guardian_link_sql($u, $g);

    $sql = "EXISTS (SELECT 1
                      FROM athlete_guardians {$ag}
                      JOIN guardians {$g} ON {$g}.id = {$ag}.guardian_id
                      JOIN users {$u} ON {$u}.id = ?
                     WHERE {$ag}.athlete_id = {$athleteColumn}
                       AND {$link})";

    return ['sql' => $sql, 'params' => [$userId]];
}

/**
 * Subquery selecting athlete ids registered to programs this user STAFFS.
 *
 * The program axis (lib/program_scope.php): camps and clinics have registrants
 * and no roster, so team scope correctly answers "no teams" and this is what
 * answers instead. Returns null — not an empty subquery — when the user staffs
 * nothing or `program_staff` has not been migrated yet, so the caller omits the
 * branch entirely rather than emitting a subquery that can only be false.
 *
 * Club scoping is deliberately NOT applied, matching te_search_program_athlete_ids():
 * every caller already carries its own `a.club_id = ?`.
 *
 * @return array{sql:string, params:array<int,int>}|null
 */
function te_scope_program_athlete_ids_sql(PDO $pdo, int $userId, string $tag = 'pa'): ?array
{
    if ($userId <= 0 || !te_program_staff_table_present($pdo)) {
        return null;
    }

    $r  = "r_{$tag}";
    $ps = "ps_{$tag}";

    return [
        'sql' => "SELECT DISTINCT {$r}.athlete_id AS scope_id
                    FROM registrations {$r}
                    JOIN program_staff {$ps} ON {$ps}.program_id = {$r}.program_id AND {$ps}.user_id = ?
                   WHERE {$r}.athlete_id IS NOT NULL
                     AND ({$r}.status IS NULL OR LOWER({$r}.status) <> 'rejected')",
        'params' => [$userId],
    ];
}

/**
 * Subquery selecting program ids this user staffs. Null when there are none, for
 * the same reason as above.
 *
 * @return array{sql:string, params:array<int,int>}|null
 */
function te_scope_program_ids_sql(PDO $pdo, int $userId, string $tag = 'pi'): ?array
{
    if ($userId <= 0 || !te_program_staff_table_present($pdo)) {
        return null;
    }

    $ps = "ps_{$tag}";

    return [
        'sql' => "SELECT DISTINCT {$ps}.program_id AS scope_id FROM program_staff {$ps} WHERE {$ps}.user_id = ?",
        'params' => [$userId],
    ];
}

/**
 * Union two or more of the fragments above into one set-valued subquery.
 *
 * `UNION`, not `UNION ALL`: the parts overlap (a coach-parent's own child's team
 * is in both), and this replaces an `array_unique()` over the merged PHP lists.
 *
 * ⚠️ Each part must have been built with a DIFFERENT `$tag`, or its aliases
 * collide inside the combined statement.
 *
 * @param array{sql:string, params:array} ...$parts
 * @return array{sql:string, params:array}
 */
function te_scope_union_sql(array ...$parts): array
{
    $sql = [];
    $params = [];
    foreach ($parts as $part) {
        $sql[] = $part['sql'];
        $params = array_merge($params, $part['params']);
    }
    return ['sql' => implode("\n UNION \n", $sql), 'params' => $params];
}

/**
 * Does this scope subquery match anything?
 *
 * The one thing that genuinely cannot be answered in SQL at the call site: which
 * BRANCHES of a predicate to emit is a PHP decision, and "is this person a coach
 * at all" decides it. Answered with `LIMIT 1` rather than by fetching the set —
 * the point of the rewrite is that the set is never materialised, and emptiness
 * is one row's worth of work.
 *
 * ⚠️ Standing still comes from ROLE where a role exists. This answers "do they
 * hold any team", which is a different question and must never be the only test
 * for "are they a coach" — a coach with no team assigned is still a coach, and
 * conflating the two emptied nine accounts' typeahead in 2026-08-14.
 *
 * @param array{sql:string, params:array} $subquery
 */
function te_scope_subquery_has_rows(PDO $pdo, array $subquery): bool
{
    $stmt = $pdo->prepare("SELECT 1 FROM ({$subquery['sql']}) te_scope_probe LIMIT 1");
    $stmt->execute($subquery['params']);
    return $stmt->fetchColumn() !== false;
}

/**
 * Are ALL of these ids inside the scope subquery?
 *
 * For the "you asked for team 7, may you have it" checks that used to fetch the
 * caller's whole team list and `in_array()` against it in PHP. The requested
 * list comes from the browser and is small; the scope list is the unbounded one,
 * so the comparison belongs in the database.
 *
 * True for an empty $ids — nothing was asked for, so nothing is refused. The
 * caller decides whether an empty request is itself an error (both current
 * callers reject it earlier, with their own message).
 *
 * @param int[] $ids
 * @param array{sql:string, params:array} $subquery
 */
function te_scope_all_ids_within(PDO $pdo, array $ids, array $subquery): bool
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) {
        return true;
    }

    $ph = te_scope_placeholders($ids);
    $stmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT te_scope_probe.scope_id)
           FROM ({$subquery['sql']}) te_scope_probe
          WHERE te_scope_probe.scope_id IN ({$ph})"
    );
    $stmt->execute(array_merge($subquery['params'], $ids));

    return (int) $stmt->fetchColumn() === count($ids);
}
