<?php
/**
 * guardian_identity — the ONE answer to "which guardian rows belong to this account".
 *
 * Phase 2 of docs/user-guardians-identity-plan.md.
 *
 * Until now that question was re-derived at every call site by string-comparing
 * `users.email` against `guardians.email` — two independently-editable columns in two
 * different tables. Seven incidents came out of that: Emily Govier's one capital letter,
 * Allix Boyce's @yahoo account against her @gmail guardian row, athlete shells holding a
 * parent's address, the Crew page reporting invites nobody sent. They all failed as
 * product statements ("no athletes are registered to you"), never as errors, which is why
 * each one survived until a family spoke up.
 *
 * Migration 072 records the relationship as a row (`user_guardians`). This file reads that
 * table **UNION** the old email match, so it is STRICTLY WIDER than what it replaces:
 * every guardian reachable today is still reachable, plus the ones whose addresses have
 * drifted apart. Phase 2 cannot cost anyone access, and that is the whole reason it is
 * shaped as a union.
 *
 * ⚠️ Do NOT narrow this to the table alone before phase 3 writes links at their source
 * (invite accept, registration, admin connect tool). 194 guardian emails have no account
 * yet; dropping the fallback first would land every newly-accepted family in an empty
 * portal — the exact bug this project exists to end, reintroduced by its own rollout.
 * te_guardian_match_source() exists so phase 4 can log email-only hits and prove the
 * divergence is zero before that line is deleted.
 *
 * ⚠️ `user_guardians.guardian_id` is a `guardians(id)`, like `athlete_guardians.guardian_id`.
 * `consent_records.guardian_id` is a `users(id)` — the outlier. Never join those two
 * because they share a name.
 *
 * Blank-email guardians (24 in production) stay unlinkable and unreachable by the email
 * branch: `guardians.email` is NOT NULL and holds `''`, and in SQL `'' = ''` is true, so an
 * account with no address would otherwise collapse into every one of them. The branch is
 * therefore guarded on the USER's address being non-blank, which cannot narrow anything for
 * a real account (users.email is UNIQUE and a login requires it).
 */

/**
 * SQL predicate linking a users row to a guardians row, for queries that already have
 * both in scope. No parameters — the aliases are interpolated, so pass literals only.
 *
 * Returned as a fragment rather than a list of ids so a call site that was one statement
 * stays one statement: turning a join into "fetch ids, then query" changes row
 * multiplication and locking behaviour for no benefit.
 *
 * @param string $userAlias     alias of the `users` table in the caller's query
 * @param string $guardianAlias alias of the `guardians` table in the caller's query
 */
function te_guardian_link_sql(string $userAlias = 'u', string $guardianAlias = 'g'): string
{
    $u = $userAlias;
    $g = $guardianAlias;

    return "(EXISTS (SELECT 1 FROM user_guardians ug"
         . " WHERE ug.user_id = {$u}.id AND ug.guardian_id = {$g}.id)"
         . " OR (TRIM({$u}.email) <> '' AND LOWER({$g}.email) = LOWER({$u}.email)))";
}

/**
 * Guardian rows belonging to this account: recorded links UNION the email match.
 *
 * @return int[] distinct guardians(id), ascending
 */
function te_guardian_ids_for_user(PDO $pdo, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }

    $sql = "
        SELECT ug.guardian_id AS id
        FROM user_guardians ug
        WHERE ug.user_id = :uid_link
        UNION
        SELECT g.id AS id
        FROM guardians g
        JOIN users u ON u.id = :uid_email
        WHERE TRIM(u.email) <> '' AND LOWER(g.email) = LOWER(u.email)
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':uid_link' => $userId, ':uid_email' => $userId]);

    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $ids = array_values(array_unique($ids));
    sort($ids);

    return $ids;
}

/**
 * Guardian rows reachable from a bare email address, for the paths that have no account
 * in hand (public registration, sibling detection). Widened the same way: guardians whose
 * own address matches, UNION guardians linked to any account holding that address.
 *
 * @return int[] distinct guardians(id), ascending
 */
function te_guardian_ids_for_email(PDO $pdo, string $email): array
{
    $email = trim($email);
    if ($email === '') {
        return [];
    }

    $sql = "
        SELECT g.id AS id
        FROM guardians g
        WHERE LOWER(g.email) = LOWER(:email_direct)
        UNION
        SELECT ug.guardian_id AS id
        FROM user_guardians ug
        JOIN users u ON u.id = ug.user_id
        WHERE LOWER(u.email) = LOWER(:email_linked)
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':email_direct' => $email, ':email_linked' => $email]);

    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $ids = array_values(array_unique($ids));
    sort($ids);

    return $ids;
}

/**
 * Athletes this account reaches as a guardian. Family — never a coach's roster.
 *
 * @return int[] distinct athletes(id), ascending
 */
function te_athlete_ids_for_user(PDO $pdo, int $userId): array
{
    $guardianIds = te_guardian_ids_for_user($pdo, $userId);
    if (empty($guardianIds)) {
        return [];
    }

    $clause = te_guardian_ids_in_clause('ag.guardian_id', $guardianIds);
    $stmt = $pdo->prepare(
        "SELECT DISTINCT ag.athlete_id FROM athlete_guardians ag WHERE {$clause['sql']}"
    );
    $stmt->execute($clause['params']);

    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $ids = array_values(array_unique($ids));
    sort($ids);

    return $ids;
}

/**
 * Is this account a guardian of this athlete?
 *
 * One statement on purpose: this is a security predicate (it gates consent recording,
 * medical edits, jersey writes and RSVP), and a two-step version invites a caller to
 * reimplement the second half.
 */
function te_user_is_guardian_of_athlete(PDO $pdo, int $userId, int $athleteId): bool
{
    if ($userId <= 0 || $athleteId <= 0) {
        return false;
    }

    $link = te_guardian_link_sql('u', 'g');
    $sql = "
        SELECT 1
        FROM users u
        JOIN guardians g ON {$link}
        JOIN athlete_guardians ag ON ag.guardian_id = g.id
        WHERE u.id = :uid AND ag.athlete_id = :aid
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':uid' => $userId, ':aid' => $athleteId]);

    return $stmt->fetch() !== false;
}

/**
 * Which branch answered — for phase 4, which retires the email match.
 *
 * `email` holds guardian ids the email comparison found; `link` holds the ones
 * `user_guardians` records. An id in `email` and not in `link` is a family who would
 * LOSE access the day the fallback is deleted, so the retirement criterion is that this
 * difference is empty for real accounts over a sustained period — not a code review.
 *
 * @return array{link:int[], email:int[]}
 */
function te_guardian_match_source(PDO $pdo, int $userId): array
{
    if ($userId <= 0) {
        return ['link' => [], 'email' => []];
    }

    $linkStmt = $pdo->prepare(
        'SELECT ug.guardian_id FROM user_guardians ug WHERE ug.user_id = :uid'
    );
    $linkStmt->execute([':uid' => $userId]);
    $link = array_map('intval', $linkStmt->fetchAll(PDO::FETCH_COLUMN));

    $emailStmt = $pdo->prepare("
        SELECT g.id
        FROM guardians g
        JOIN users u ON u.id = :uid
        WHERE TRIM(u.email) <> '' AND LOWER(g.email) = LOWER(u.email)
    ");
    $emailStmt->execute([':uid' => $userId]);
    $email = array_map('intval', $emailStmt->fetchAll(PDO::FETCH_COLUMN));

    sort($link);
    sort($email);

    return [
        'link' => array_values(array_unique($link)),
        'email' => array_values(array_unique($email)),
    ];
}

/**
 * An IN clause over resolved ids, with the empty case spelled `1=0`.
 *
 * `IN ()` is a syntax error in Postgres, not an empty result — the same trap
 * `getTeamFilterClause()` and `array_fill(0, 0, '?')` already carry. A caller that
 * forgets the empty case gets a 500 rather than a refusal, so the guard lives here.
 *
 * @param int[] $ids
 * @return array{sql:string, params:array<string,int>} bare predicate; caller supplies AND/WHERE
 */
function te_guardian_ids_in_clause(string $column, array $ids, string $prefix = 'gid'): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (empty($ids)) {
        return ['sql' => '1=0', 'params' => []];
    }

    $placeholders = [];
    $params = [];
    foreach ($ids as $i => $id) {
        $placeholders[] = ":{$prefix}{$i}";
        $params[":{$prefix}{$i}"] = $id;
    }

    return [
        'sql' => $column . ' IN (' . implode(',', $placeholders) . ')',
        'params' => $params,
    ];
}
