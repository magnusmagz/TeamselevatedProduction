<?php
/**
 * Mid-year athlete evaluations + IDP goals (migration 086, CKU R76/R77).
 *
 * Everything the endpoint decides lives here rather than in
 * api/athlete-evaluations.php, for the reason ChatSearchCoachScopeTest exists:
 * a procedural script that reads php://input and echoes JSON cannot be executed
 * by a test, so the authorization it performs can only ever be asserted by
 * grepping its source. These functions take a PDO and an AuthMiddleware and
 * return values, so AthleteEvaluationsTest runs the real predicate against a
 * real (SQLite) fixture instead of matching strings.
 *
 * ⚠️ TWO RULES THAT ARE NOT OPTIONAL
 *
 * 1. READING AND WRITING ARE DIFFERENT PERMISSIONS, AND WRITING TAKES TWO.
 *    Reads gate on AthleteScope::userCanAccessAthlete, whose guardian branch is
 *    the point: a parent is entitled to see what their child's coach wrote about
 *    them. Writes gate on staffCanManageAthlete AND, on top of that, on actually
 *    coaching the athlete (or being a club admin). staffCanManageAthlete alone
 *    already covers every coach of every team the athlete is on, so the second
 *    predicate is not redundant for the club-admin case only — it is what stops
 *    a club admin's standing being inherited by anyone the codebase happens to
 *    call staff. The product rule from R76 is "players a coach directly
 *    coaches", and that is a narrower question than "may this person edit this
 *    athlete's record", so it is asked separately.
 *
 * 2. EVERY FUNCTION TOLERATES BOTH TABLES BEING ABSENT. `main` is shared and
 *    deploys are by push, so this code reaches production the moment any session
 *    pushes — days before migration 086 is applied to Neon by hand. On Postgres
 *    a SELECT against a missing table is 42P01, a hard error that would take the
 *    whole athlete profile down for every club rather than hiding one panel. The
 *    probe answers false on any failure and the degraded answer is always the
 *    narrow one. Same shape as lib/program_scope.php and lib/program_ordering.php.
 */

require_once __DIR__ . '/AthleteScope.php';

/** Both tables migration 086 creates. They arrive together or not at all. */
const TE_ATHLETE_EVALUATION_TABLES = ['athlete_evaluations', 'athlete_evaluation_scores'];

/** Most goals one evaluation may carry. The UI asks for 3-5; the server caps 5. */
const TE_IDP_MAX_GOALS = 5;

/**
 * Fallback criteria for a club that has never run a tryout.
 *
 * A club with no `tryout_evaluation_criteria` rows would otherwise get an
 * evaluation form with nothing on it — the feature would be invisible to exactly
 * the clubs that do not do tryouts, which is most of the ones that most want a
 * mid-year check-in. These are offered ONLY when the club has none of its own,
 * and the response says so (`source: 'default'`) so the UI can tell the coach
 * where the list came from rather than implying the club chose it.
 */
const TE_ATHLETE_EVALUATION_DEFAULT_CRITERIA = [
    ['name' => 'Technical Skills',    'description' => 'Ball control, passing, first touch',      'max_score' => 5, 'weight' => 1.0],
    ['name' => 'Tactical Awareness',  'description' => 'Decision making and game understanding',  'max_score' => 5, 'weight' => 1.0],
    ['name' => 'Physical',            'description' => 'Speed, endurance, strength',              'max_score' => 5, 'weight' => 1.0],
    ['name' => 'Attitude and Effort', 'description' => 'Coachability, work rate, teammate',       'max_score' => 5, 'weight' => 1.0],
];

/**
 * Are both migration-086 tables live?
 *
 * Memoised per PDO instance via WeakMap, not per process: the test suite builds
 * one connection with the tables and one without, and object ids are reused
 * after an object is freed, so an id-keyed cache would let the first connection's
 * answer decide the second's (the reason lib/program_scope.php does the same).
 *
 * The information_schema query is the Postgres answer. SQLite has no
 * information_schema, so that throws and the fallback asks each table directly —
 * safe there precisely because SQLite has no transaction to poison.
 */
function te_athlete_evaluation_tables_present(PDO $pdo): bool
{
    static $memo = null;
    $memo ??= new WeakMap();
    if (isset($memo[$pdo])) {
        return $memo[$pdo];
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.tables
              WHERE table_name IN ('athlete_evaluations', 'athlete_evaluation_scores')"
        );
        $stmt->execute();
        return $memo[$pdo] = ((int) $stmt->fetchColumn() === count(TE_ATHLETE_EVALUATION_TABLES));
    } catch (Throwable $e) {
        try {
            foreach (TE_ATHLETE_EVALUATION_TABLES as $table) {
                $pdo->query("SELECT 1 FROM {$table} LIMIT 1");
            }
            return $memo[$pdo] = true;
        } catch (Throwable $e2) {
            return $memo[$pdo] = false;
        }
    }
}

/**
 * Is the requester a club admin (or super admin) over this athlete?
 *
 * Deliberately built from AthleteScope's own two helpers rather than a fresh
 * query, so "which clubs is this athlete in" has exactly one definition.
 */
function te_athlete_evaluation_is_club_admin(PDO $pdo, AuthMiddleware $auth, int $athleteId): bool
{
    if ($auth->isSuperAdmin()) {
        return true;
    }
    foreach (AthleteScope::athleteClubIds($pdo, $athleteId) as $clubId) {
        if ($auth->hasRole('club_admin', $clubId, 'club')) {
            return true;
        }
    }
    return false;
}

/**
 * May this requester RECORD an evaluation for this athlete?
 *
 * staffCanManageAthlete (the write predicate — never the read one, which a
 * guardian passes) AND directly coaching the athlete, or club admin standing.
 */
function te_athlete_evaluation_can_write(PDO $pdo, AuthMiddleware $auth, int $athleteId): bool
{
    if (!AthleteScope::staffCanManageAthlete($pdo, $auth, $athleteId)) {
        return false;
    }
    if (te_athlete_evaluation_is_club_admin($pdo, $auth, $athleteId)) {
        return true;
    }
    $userId = (int) $auth->getUserId();
    return $userId > 0 && AthleteScope::coachesAthlete($pdo, $userId, $athleteId);
}

/**
 * May this requester READ this athlete's evaluations?
 *
 * The read predicate, guardian branch included. A parent seeing their own
 * child's development plan is the feature, not a leak.
 */
function te_athlete_evaluation_can_read(PDO $pdo, AuthMiddleware $auth, int $athleteId): bool
{
    return AthleteScope::userCanAccessAthlete($pdo, $auth, $athleteId);
}

/**
 * The criteria a coach scores against: the distinct criterion NAMES defined on
 * any tryout program in the athlete's club(s).
 *
 * Names are deduplicated across programs because a club that runs U12 and U14
 * tryouts has "Technical Skills" twice and the coach should see it once. The
 * widest max_score wins, so a criterion scored out of 10 anywhere in the club is
 * offered out of 10 here rather than being silently truncated to 5.
 *
 * @return array{criteria: array<int, array{name:string,description:?string,max_score:float,weight:float}>, source: string}
 */
function te_athlete_evaluation_criteria(PDO $pdo, int $athleteId): array
{
    $clubIds = AthleteScope::athleteClubIds($pdo, $athleteId);
    if (!empty($clubIds)) {
        $ph = implode(',', array_fill(0, count($clubIds), '?'));
        try {
            $stmt = $pdo->prepare(
                "SELECT c.name AS name,
                        MIN(c.description) AS description,
                        MAX(c.max_score)   AS max_score,
                        MAX(c.weight)      AS weight,
                        MIN(c.display_order) AS display_order
                   FROM tryout_evaluation_criteria c
                   JOIN programs p ON p.id = c.program_id
                  WHERE p.club_id IN ({$ph})
                  GROUP BY c.name
                  ORDER BY MIN(c.display_order), c.name"
            );
            $stmt->execute(array_values($clubIds));
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (!empty($rows)) {
                $criteria = [];
                foreach ($rows as $row) {
                    $criteria[] = [
                        'name'        => (string) $row['name'],
                        'description' => $row['description'] !== null && $row['description'] !== ''
                            ? (string) $row['description'] : null,
                        'max_score'   => (float) ($row['max_score'] ?: 5),
                        'weight'      => (float) ($row['weight'] ?: 1),
                    ];
                }
                return ['criteria' => $criteria, 'source' => 'club'];
            }
        } catch (Throwable $e) {
            // The tryout tables are old and universal, but a fixture may lack
            // them. Falling through to the defaults is strictly better than
            // failing the panel.
            error_log('te_athlete_evaluation_criteria: ' . $e->getMessage());
        }
    }

    return [
        'criteria' => array_map(static fn(array $c): array => [
            'name'        => $c['name'],
            'description' => $c['description'],
            'max_score'   => (float) $c['max_score'],
            'weight'      => (float) $c['weight'],
        ], TE_ATHLETE_EVALUATION_DEFAULT_CRITERIA),
        'source' => 'default',
    ];
}

/**
 * The weighted 0-100 roll-up.
 *
 * Same formula as calculateOverallScore() in registration/tryouts-api.php — a
 * score normalised to a percentage of its own max, then weighted — so a coach
 * who has used the tryout sheet reads the same number the same way. Criteria
 * with no score, no max, or no weight contribute nothing rather than dragging
 * the average toward zero: a partially completed evaluation must report on what
 * was actually assessed.
 *
 * Returns null when nothing scoreable was submitted. Null is not 0; storing 0
 * would put a fabricated low point on the year-over-year graph.
 *
 * @param array<int, array{score?:mixed, max_score?:mixed, weight?:mixed}> $rows
 */
function te_athlete_evaluation_overall(array $rows): ?float
{
    $weightedSum = 0.0;
    $totalWeight = 0.0;

    foreach ($rows as $row) {
        $score = $row['score'] ?? null;
        if ($score === null || $score === '' || !is_numeric($score)) {
            continue;
        }
        $max    = isset($row['max_score']) && is_numeric($row['max_score']) ? (float) $row['max_score'] : 0.0;
        $weight = isset($row['weight']) && is_numeric($row['weight']) ? (float) $row['weight'] : 0.0;
        if ($max <= 0 || $weight <= 0) {
            continue;
        }
        $weightedSum += (((float) $score / $max) * 100) * $weight;
        $totalWeight += $weight;
    }

    return $totalWeight > 0 ? round($weightedSum / $totalWeight, 2) : null;
}

/**
 * Clean a submitted score list into rows ready for athlete_evaluation_scores.
 *
 * A criterion with a blank name is dropped — the name IS the record here, since
 * nothing links back to tryout_evaluation_criteria, so an unnamed score could
 * never be read back. Duplicate names are collapsed (last wins) because the
 * table's UNIQUE(evaluation_id, criterion_name) would reject them anyway, and a
 * 23505 at INSERT time is a 500 rather than a message.
 *
 * @return array<int, array{criterion_name:string, score:?float, max_score:?float, weight:?float, comment:?string, display_order:int}>
 */
function te_athlete_evaluation_normalize_scores($raw): array
{
    if (!is_array($raw)) {
        return [];
    }

    $byName = [];
    $order = 0;
    foreach ($raw as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $name = trim((string) ($entry['criterion_name'] ?? $entry['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $score = $entry['score'] ?? null;
        $max   = $entry['max_score'] ?? null;
        $weight = $entry['weight'] ?? null;
        $comment = isset($entry['comment']) ? trim((string) $entry['comment']) : '';

        $byName[$name] = [
            'criterion_name' => $name,
            'score'          => is_numeric($score) ? (float) $score : null,
            'max_score'      => is_numeric($max) ? (float) $max : null,
            'weight'         => is_numeric($weight) ? (float) $weight : null,
            'comment'        => $comment !== '' ? $comment : null,
            'display_order'  => $order++,
        ];
    }

    return array_values($byName);
}

/**
 * Clean submitted IDP goals into the JSONB array shape.
 *
 * Blank goals are dropped rather than stored, so a coach who fills two of five
 * rows gets two goals and not three empty promises on the parent's screen. More
 * than TE_IDP_MAX_GOALS is an error the caller must report, not something to
 * truncate silently — a plan quietly missing its last goal is the silent-failure
 * shape this codebase keeps rediscovering.
 *
 * @return array{goals: array<int, array{goal:string, target_date:?string}>, error: ?string}
 */
function te_athlete_evaluation_normalize_goals($raw): array
{
    if ($raw === null) {
        return ['goals' => [], 'error' => null];
    }
    if (!is_array($raw)) {
        return ['goals' => [], 'error' => 'idp_goals must be a list of goals'];
    }

    $goals = [];
    foreach ($raw as $entry) {
        $text = null;
        $target = null;
        if (is_string($entry)) {
            $text = trim($entry);
        } elseif (is_array($entry)) {
            $text = trim((string) ($entry['goal'] ?? ''));
            $rawTarget = $entry['target_date'] ?? null;
            if (is_string($rawTarget) && trim($rawTarget) !== '') {
                $target = trim($rawTarget);
                // A date-only value is stored as the submitted YYYY-MM-DD string
                // and never parsed into a DateTime here — see the date-only rule
                // in CLAUDE.md. Anything else is refused rather than coerced.
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $target)) {
                    return ['goals' => [], 'error' => 'Goal target dates must be YYYY-MM-DD'];
                }
            }
        }
        if ($text === null || $text === '') {
            continue;
        }
        $goals[] = ['goal' => $text, 'target_date' => $target];
    }

    if (count($goals) > TE_IDP_MAX_GOALS) {
        return ['goals' => [], 'error' => 'An IDP can hold at most ' . TE_IDP_MAX_GOALS . ' goals'];
    }

    return ['goals' => $goals, 'error' => null];
}

/**
 * Every evaluation recorded for an athlete, newest first, with its scores and
 * goals attached.
 *
 * Two queries, not a join: joining the scores on would multiply the evaluation
 * row once per criterion and every consumer would have to un-multiply it — the
 * same reason buildTeamFilter() in the analytics gateway uses EXISTS.
 *
 * Returns [] when the tables are absent. The CALLER must report that as
 * `available: false` rather than as "no evaluations yet" — an empty list and a
 * missing feature are opposite answers, and conflating them tells a parent their
 * child has never been evaluated.
 */
function te_athlete_evaluation_list(PDO $pdo, int $athleteId): array
{
    if (!te_athlete_evaluation_tables_present($pdo)) {
        return [];
    }

    $stmt = $pdo->prepare(
        "SELECT e.id, e.athlete_id, e.team_id, e.evaluator_id, e.evaluated_at,
                e.season_label, e.overall_score, e.notes, e.idp_goals,
                e.created_at, e.updated_at,
                u.first_name AS evaluator_first_name,
                u.last_name  AS evaluator_last_name,
                t.name       AS team_name
           FROM athlete_evaluations e
           LEFT JOIN users u ON u.id = e.evaluator_id
           LEFT JOIN teams t ON t.id = e.team_id
          WHERE e.athlete_id = ?
          ORDER BY e.evaluated_at DESC, e.id DESC"
    );
    $stmt->execute([$athleteId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (empty($rows)) {
        return [];
    }

    $ids = array_map(static fn(array $r): int => (int) $r['id'], $rows);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT evaluation_id, criterion_name, score, max_score, weight, comment
           FROM athlete_evaluation_scores
          WHERE evaluation_id IN ({$ph})
          ORDER BY display_order, id"
    );
    $stmt->execute($ids);

    $scoresById = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $s) {
        $scoresById[(int) $s['evaluation_id']][] = [
            'criterion_name' => (string) $s['criterion_name'],
            'score'          => $s['score'] === null ? null : (float) $s['score'],
            'max_score'      => $s['max_score'] === null ? null : (float) $s['max_score'],
            'weight'         => $s['weight'] === null ? null : (float) $s['weight'],
            'comment'        => $s['comment'],
        ];
    }

    $out = [];
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $goals = [];
        if (!empty($row['idp_goals'])) {
            $decoded = is_array($row['idp_goals']) ? $row['idp_goals'] : json_decode((string) $row['idp_goals'], true);
            if (is_array($decoded)) {
                $goals = $decoded;
            }
        }
        $out[] = [
            'id'            => $id,
            'athlete_id'    => (int) $row['athlete_id'],
            'team_id'       => $row['team_id'] === null ? null : (int) $row['team_id'],
            'team_name'     => $row['team_name'],
            'evaluator_id'  => (int) $row['evaluator_id'],
            'evaluator_name'=> trim(($row['evaluator_first_name'] ?? '') . ' ' . ($row['evaluator_last_name'] ?? '')) ?: null,
            // Emitted as the stored YYYY-MM-DD and never parsed, so this path has
            // no timezone behaviour to get wrong.
            'evaluated_at'  => $row['evaluated_at'] === null ? null : substr((string) $row['evaluated_at'], 0, 10),
            'season_label'  => (string) $row['season_label'],
            'overall_score' => $row['overall_score'] === null ? null : (float) $row['overall_score'],
            'notes'         => $row['notes'],
            'idp_goals'     => $goals,
            'scores'        => $scoresById[$id] ?? [],
            'created_at'    => $row['created_at'],
            'updated_at'    => $row['updated_at'],
        ];
    }

    return $out;
}

/** One evaluation row (no scores), or null. Used to authorize update/delete. */
function te_athlete_evaluation_find(PDO $pdo, int $id): ?array
{
    if (!te_athlete_evaluation_tables_present($pdo)) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT id, athlete_id, evaluator_id FROM athlete_evaluations WHERE id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

/**
 * Insert an evaluation and its scores.
 *
 * One transaction: an evaluation whose scores failed to write is worse than no
 * evaluation, because the overall_score would stand with nothing behind it.
 *
 * @return int new evaluation id
 */
function te_athlete_evaluation_create(PDO $pdo, int $athleteId, int $evaluatorId, array $data): int
{
    $scores = te_athlete_evaluation_normalize_scores($data['scores'] ?? []);
    $overall = te_athlete_evaluation_overall($scores);
    $goals = $data['idp_goals'] ?? [];

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO athlete_evaluations
                (athlete_id, team_id, evaluator_id, evaluated_at, season_label,
                 overall_score, notes, idp_goals, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ' . te_athlete_evaluation_now_sql($pdo) . ')'
        );
        $stmt->execute([
            $athleteId,
            $data['team_id'] ?? null,
            $evaluatorId,
            $data['evaluated_at'],
            $data['season_label'],
            $overall,
            $data['notes'] ?? null,
            empty($goals) ? null : json_encode($goals),
        ]);
        $id = (int) $pdo->lastInsertId();

        te_athlete_evaluation_write_scores($pdo, $id, $scores);

        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Replace an evaluation's editable fields and its whole score list.
 *
 * Scores are deleted and re-inserted rather than upserted, because removing a
 * criterion from the form must remove it from the record — an upsert would leave
 * the dropped criterion behind, still counted in nothing and still displayed.
 */
function te_athlete_evaluation_update(PDO $pdo, int $id, array $data): void
{
    $scores = te_athlete_evaluation_normalize_scores($data['scores'] ?? []);
    $overall = te_athlete_evaluation_overall($scores);
    $goals = $data['idp_goals'] ?? [];

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'UPDATE athlete_evaluations
                SET team_id = ?, evaluated_at = ?, season_label = ?, overall_score = ?,
                    notes = ?, idp_goals = ?, updated_at = ' . te_athlete_evaluation_now_sql($pdo) . '
              WHERE id = ?'
        );
        $stmt->execute([
            $data['team_id'] ?? null,
            $data['evaluated_at'],
            $data['season_label'],
            $overall,
            $data['notes'] ?? null,
            empty($goals) ? null : json_encode($goals),
            $id,
        ]);

        $del = $pdo->prepare('DELETE FROM athlete_evaluation_scores WHERE evaluation_id = ?');
        $del->execute([$id]);
        te_athlete_evaluation_write_scores($pdo, $id, $scores);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Delete an evaluation. Scores go with it via ON DELETE CASCADE. */
function te_athlete_evaluation_delete(PDO $pdo, int $id): void
{
    // SQLite does not enforce FKs unless asked, so the child rows are removed
    // explicitly. On Postgres this is a no-op the cascade would have done.
    $stmt = $pdo->prepare('DELETE FROM athlete_evaluation_scores WHERE evaluation_id = ?');
    $stmt->execute([$id]);
    $stmt = $pdo->prepare('DELETE FROM athlete_evaluations WHERE id = ?');
    $stmt->execute([$id]);
}

/** @internal */
function te_athlete_evaluation_write_scores(PDO $pdo, int $evaluationId, array $scores): void
{
    if (empty($scores)) {
        return;
    }
    $stmt = $pdo->prepare(
        'INSERT INTO athlete_evaluation_scores
            (evaluation_id, criterion_name, score, max_score, weight, comment, display_order)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($scores as $s) {
        $stmt->execute([
            $evaluationId,
            $s['criterion_name'],
            $s['score'],
            $s['max_score'],
            $s['weight'],
            $s['comment'],
            $s['display_order'],
        ]);
    }
}

/**
 * NOW() on Postgres, CURRENT_TIMESTAMP on SQLite.
 *
 * @internal Both are standard, but SQLite has no NOW() at all, and the tests are
 * the only reason this seam exists.
 */
function te_athlete_evaluation_now_sql(PDO $pdo): string
{
    try {
        return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'CURRENT_TIMESTAMP' : 'NOW()';
    } catch (Throwable $e) {
        return 'CURRENT_TIMESTAMP';
    }
}
