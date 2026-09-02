<?php
/**
 * Connect a signed-in account to a guardian record when the two email addresses
 * do not match.
 *
 * Phase 3 (part 1) of docs/user-guardians-identity-plan.md — "write links at
 * their source". Phases 1 and 2 gave us the `user_guardians` table (migration
 * 072) and one resolver that reads it UNION the old email comparison
 * (lib/guardian_identity.php). Both are strictly additive: nothing that worked
 * before stopped working, and nothing new became reachable either, because
 * outside the 173-row backfill nothing writes links.
 *
 * This file is the first deliberate writer. It exists for the family the email
 * comparison cannot reach at all:
 *
 *   Allix Boyce signs in on @gmail. Her guardian row says @yahoo. She holds a
 *   valid `parent` role. `te_guardian_ids_for_user()` returns nothing, so the
 *   parent portal tells her no athletes are registered to her, and there is no
 *   self-service repair — she cannot edit the guardian row, and an admin
 *   "fixing" the address on one side moves the problem rather than recording
 *   the relationship.
 *
 * A club admin can now say, in one row, "this account is that guardian". That
 * assertion outlives both addresses, which is the entire point of the table.
 *
 * ⚠️ THE STANDING REQUIRED IS `te_is_club_admin`, NOT `canAccessClub`.
 * `AuthMiddleware::canAccessClub()` answers "does this user hold ANY role in
 * this club" and a `parent` row satisfies it — that is how `handleClubParents`
 * handed every guardian in club 32 to 13 parent accounts. Here the consequence
 * would be considerably worse than a disclosure: `link` decides which adult may
 * read a minor's record, edit their medical information and record parental
 * consent. Anything short of club admin must not be able to reach it.
 * Pinned by CrewLinkTest.
 *
 * ⚠️ THE WRITES ARE AUDITED TWICE, ON PURPOSE.
 *   1. Migration 072's `user_guardians_audit` trigger fires on every INSERT and
 *      DELETE regardless of who wrote it — including psql. It attributes to
 *      whoever `app.user_id` names, which is why `te_db_set_actor()` runs
 *      before the write and not after. Forgetting it does not break anything;
 *      it silently degrades the row to "nobody did this", which is exactly the
 *      state that made the 2026-07-31 link change permanently unexplainable.
 *   2. `AuditLogger` records the request-level facts the trigger cannot see:
 *      which club, which admin, and what the account could reach afterwards.
 *
 * ⚠️ THE UI SAYS "CREW", NEVER "PARENTS". The backend vocabulary is `parent`
 * (the role, the invite, the portal) and that is not changing; the strings a
 * club admin reads are Crew. Keep any message added here on that side.
 */

// Declared here, ABOVE the test hook, because `const` at file scope is a runtime
// statement and not hoisted the way function declarations are — below the guard's
// `return` they would simply not exist for the tests.
/**
 * How many name-matched guardians to offer per stuck account.
 *
 * Five, not "all of them". This is a suggestion list an admin skims, and a
 * common surname in a 150-family club returns enough rows to make the panel a
 * second search problem. Anything not offered is still reachable through the
 * search box, which draws on the whole club — so the cap hides nothing, it only
 * decides what is offered without asking.
 */
const TE_CREW_LINK_SUGGESTION_LIMIT = 5;

/**
 * Vocabulary from migration 072: source is one of
 * backfill_email | invite_accept | admin_link | registration, and confidence is
 * one of exact | household | manual.
 *
 * Every row this file writes is `admin_link` / `manual`, and that pairing is the
 * whole reason the columns are stored rather than derived. When the first wrong
 * link surfaces — and the plan says plainly that it will be a family seeing
 * another family's child — the question is how the row got there. A backfilled
 * string match and a named admin's deliberate click must stay distinguishable
 * forever, and `linked_by` says which admin.
 */
const TE_CREW_LINK_SOURCE = 'admin_link';
const TE_CREW_LINK_CONFIDENCE = 'manual';

// Test hook: defining this loads the collaborators and returns before any side
// effect — no CORS, no headers, no dispatch, no Neon connect. Top-level function
// declarations are still hoisted by the compiler, so every handler below is
// defined. Never defined in production; must stay above everything with an
// effect.
if (defined('TE_CREW_LINK_LIB_ONLY')) {
    require_once __DIR__ . '/../lib/guardian_identity.php';
    require_once __DIR__ . '/../lib/club_standing.php';
    require_once __DIR__ . '/../lib/db_actor.php';
    require_once __DIR__ . '/../lib/AuditLogger.php';
    return;
}

require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/AuditLogger.php';
require_once __DIR__ . '/../lib/guardian_identity.php';
require_once __DIR__ . '/../lib/club_standing.php';
require_once __DIR__ . '/../lib/db_actor.php';

// ─────────────────────────────────────────────────────────────────────────────
// Handlers. Each returns ['status' => int, 'body' => array] rather than echoing,
// so the tests can execute the real thing instead of re-running a copy of its
// SQL — the failure mode CrewContactUpdateTest's docblock describes and this
// file avoids.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Accounts in this club that the resolver cannot connect to any guardian.
 *
 * These are the stuck families: they hold a `parent` role, so somebody
 * deliberately gave them parent standing, and yet `te_guardian_ids_for_user()`
 * answers with nothing — so the portal shows them an empty house.
 *
 * The candidate set is narrowed in SQL and then CONFIRMED one account at a time
 * through `te_guardian_ids_for_user()`. That looks redundant and is not: the SQL
 * predicate and the resolver share `te_guardian_link_sql()`, but only the
 * resolver is the thing every other gate in the product actually calls. If they
 * ever disagree, the honest answer is the resolver's, because that is the
 * function deciding whether this family sees their child tonight. The confirm
 * loop runs over the stuck accounts only, which is a handful, not the club.
 */
function te_crew_link_candidates(PDO $pdo, $auth, int $clubId): array
{
    if ($clubId <= 0) {
        return ['status' => 400, 'body' => ['success' => false, 'error' => 'club_id is required']];
    }
    if (!te_is_club_admin($auth, $clubId)) {
        return ['status' => 403, 'body' => ['success' => false, 'error' => 'Only club admins can connect accounts to a family']];
    }

    $link = te_guardian_link_sql('u', 'g');
    $stmt = $pdo->prepare("
        SELECT u.id, u.first_name, u.last_name, u.email, u.last_login_at
        FROM users u
        JOIN user_club_access uca
          ON uca.user_id = u.id
         AND uca.club_profile_id = :club
         AND uca.role = 'parent'
         AND uca.active = TRUE
         AND uca.revoked_at IS NULL
        WHERE NOT EXISTS (SELECT 1 FROM guardians g WHERE {$link})
        ORDER BY u.last_name, u.first_name, u.id
    ");
    $stmt->execute([':club' => $clubId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $candidates = [];
    foreach ($rows as $row) {
        $userId = (int) $row['id'];

        // The resolver is the authority. See the docblock.
        if (!empty(te_guardian_ids_for_user($pdo, $userId))) {
            continue;
        }

        $candidates[] = [
            'user_id'       => $userId,
            'first_name'    => (string) $row['first_name'],
            'last_name'     => (string) $row['last_name'],
            'email'         => (string) $row['email'],
            'last_login_at' => $row['last_login_at'],
            'suggestions'   => te_crew_link_suggestions(
                $pdo,
                $clubId,
                (string) $row['first_name'],
                (string) $row['last_name']
            ),
        ];
    }

    return [
        'status' => 200,
        'body' => [
            'success'    => true,
            'club_id'    => $clubId,
            'candidates' => $candidates,
        ],
    ];
}

/**
 * Unclaimed guardians in this club whose name resembles the account holder's.
 *
 * Ranked, not scored cleverly: last name AND first name (3), last name (2),
 * first name (1). A shared surname is the strongest signal available and a
 * shared first name is weak but real — "Jennifer Smith" vs "Jennifer Smith-Ortiz"
 * is the shape of the problem, since the drift that strands these accounts is
 * usually a marriage, a remarriage or a second address, not a different person.
 *
 * ⚠️ NOTHING HERE DECIDES ANYTHING. Every suggestion is a name string match, and
 * name matching across families is precisely the reasoning the backfill refused
 * to automate — `carmenlynnhawk@gmail.com` spans two surnames and is one family;
 * `eli@teamselevated.com` spans four and is a staff address. So this list is
 * offered to a human who knows the club and never acted upon.
 *
 * A blank name part is not a match, it is an absence — comparing it would match
 * every guardian whose own field is blank, and 25 guardian rows carry an empty
 * string in production today.
 *
 * Guardians already carrying a recorded link are excluded: connecting a second
 * account to an asserted relationship is a decision, not a suggestion. It stays
 * possible through the search box, which is deliberate — migration 072's UNIQUE
 * constrains the pair rather than the guardian precisely because one guardian
 * legitimately holding two accounts is a real case (Allix Boyce had exactly
 * that, an invited @yahoo account and a self-created @gmail one).
 */
function te_crew_link_suggestions(PDO $pdo, int $clubId, string $firstName, string $lastName): array
{
    $first = strtolower(trim($firstName));
    $last = strtolower(trim($lastName));
    if ($first === '' && $last === '') {
        return [];
    }

    $where = [];
    $params = [':club' => $clubId];
    if ($last !== '') {
        $where[] = 'LOWER(TRIM(g.last_name)) = :last';
        $params[':last'] = $last;
    }
    if ($first !== '') {
        $where[] = 'LOWER(TRIM(g.first_name)) = :first';
        $params[':first'] = $first;
    }

    $nameClause = '(' . implode(' OR ', $where) . ')';

    $stmt = $pdo->prepare("
        SELECT g.id, g.first_name, g.last_name, g.email, g.mobile_phone
        FROM guardians g
        WHERE {$nameClause}
          AND EXISTS (
            SELECT 1 FROM athlete_guardians ag
            JOIN athletes a ON a.id = ag.athlete_id
            WHERE ag.guardian_id = g.id AND a.club_id = :club AND a.deleted_at IS NULL
          )
          AND NOT EXISTS (SELECT 1 FROM user_guardians ug WHERE ug.guardian_id = g.id)
        ORDER BY g.last_name, g.first_name, g.id
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $ranked = [];
    foreach ($rows as $row) {
        $lastHit = $last !== '' && strtolower(trim((string) $row['last_name'])) === $last;
        $firstHit = $first !== '' && strtolower(trim((string) $row['first_name'])) === $first;

        if ($lastHit && $firstHit) {
            $rank = 3;
            $match = 'first_and_last_name';
        } elseif ($lastHit) {
            $rank = 2;
            $match = 'last_name';
        } elseif ($firstHit) {
            $rank = 1;
            $match = 'first_name';
        } else {
            continue; // Unreachable given the WHERE, but the rank must not be invented.
        }

        $ranked[] = [
            'guardian_id'  => (int) $row['id'],
            'first_name'   => (string) $row['first_name'],
            'last_name'    => (string) $row['last_name'],
            'email'        => (string) $row['email'],
            'mobile_phone' => (string) ($row['mobile_phone'] ?? ''),
            'match'        => $match,
            '_rank'        => $rank,
        ];
    }

    usort($ranked, static function (array $a, array $b): int {
        if ($a['_rank'] !== $b['_rank']) {
            return $b['_rank'] <=> $a['_rank'];
        }
        return [$a['last_name'], $a['first_name'], $a['guardian_id']]
           <=> [$b['last_name'], $b['first_name'], $b['guardian_id']];
    });

    $ranked = array_slice($ranked, 0, TE_CREW_LINK_SUGGESTION_LIMIT);

    $ids = array_column($ranked, 'guardian_id');
    $athletes = te_crew_link_athletes_by_guardian($pdo, $clubId, $ids);
    $reachable = te_crew_link_reachable_by_email($pdo, $ids);

    foreach ($ranked as &$s) {
        unset($s['_rank']);
        $s['athletes'] = $athletes[$s['guardian_id']] ?? [];
        // Disclosed, never hidden. A guardian whose own address matches some
        // other account is already reachable by that account today, through the
        // email half of the resolver. That is usually the same human with two
        // logins — the case migration 072 says to link both times — but the
        // admin has to be able to see it before deciding, so it is shown rather
        // than filtered out or silently allowed.
        $s['already_reachable_by'] = $reachable[$s['guardian_id']] ?? null;
    }
    unset($s);

    return $ranked;
}

/**
 * Athlete names per guardian, for the guardian ids given.
 *
 * One query and assembled in PHP rather than aggregated in SQL: `GROUP_CONCAT()`
 * does not exist in Postgres (MysqlOnlySqlTest scans for exactly that), and
 * `string_agg()` does not exist in SQLite, where these tests run. Neither is
 * worth a portability problem for a list of at most five families.
 *
 * @param int[] $guardianIds
 * @return array<int, array<int, array{id:int, first_name:string, last_name:string}>>
 */
function te_crew_link_athletes_by_guardian(PDO $pdo, int $clubId, array $guardianIds): array
{
    if (empty($guardianIds)) {
        return [];
    }

    $clause = te_guardian_ids_in_clause('ag.guardian_id', $guardianIds, 'sg');
    $stmt = $pdo->prepare("
        SELECT ag.guardian_id, a.id, a.first_name, a.last_name
        FROM athlete_guardians ag
        JOIN athletes a ON a.id = ag.athlete_id
        WHERE {$clause['sql']} AND a.club_id = :club AND a.deleted_at IS NULL
        ORDER BY a.last_name, a.first_name, a.id
    ");
    $stmt->execute(array_merge($clause['params'], [':club' => $clubId]));

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int) $row['guardian_id']][] = [
            'id'         => (int) $row['id'],
            'first_name' => (string) $row['first_name'],
            'last_name'  => (string) $row['last_name'],
        ];
    }

    return $out;
}

/**
 * For each guardian id, an account that already reaches it via the email half of
 * the resolver — or nothing.
 *
 * @param int[] $guardianIds
 * @return array<int, array{user_id:int, email:string, first_name:string, last_name:string}>
 */
function te_crew_link_reachable_by_email(PDO $pdo, array $guardianIds): array
{
    if (empty($guardianIds)) {
        return [];
    }

    $clause = te_guardian_ids_in_clause('g.id', $guardianIds, 'rg');
    $stmt = $pdo->prepare("
        SELECT g.id AS guardian_id, u.id AS user_id, u.email, u.first_name, u.last_name
        FROM guardians g
        JOIN users u ON TRIM(u.email) <> '' AND LOWER(u.email) = LOWER(g.email)
        WHERE {$clause['sql']}
        ORDER BY u.id
    ");
    $stmt->execute($clause['params']);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $gid = (int) $row['guardian_id'];
        if (isset($out[$gid])) {
            continue; // First account is enough to warn with.
        }
        $out[$gid] = [
            'user_id'    => (int) $row['user_id'],
            'email'      => (string) $row['email'],
            'first_name' => (string) $row['first_name'],
            'last_name'  => (string) $row['last_name'],
        ];
    }

    return $out;
}

/**
 * Both parties must belong to the admin's club, established from different
 * evidence because they are different kinds of record.
 *
 * The USER's club is a `user_club_access` row — an account is in a club because
 * somebody granted it a role there.
 *
 * The GUARDIAN's club is the athlete chain (`athlete_guardians` → `athletes.club_id`),
 * because `guardians` carries no club at all. That is also the rule
 * `api/crew.php` uses, and it is why a club admin cannot reach an arbitrary
 * guardian by id.
 *
 * @return array{ok:bool, status?:int, error?:string}
 */
function te_crew_link_check_scope(PDO $pdo, int $clubId, int $userId, int $guardianId): array
{
    $stmt = $pdo->prepare("
        SELECT 1 FROM user_club_access
        WHERE user_id = :uid AND club_profile_id = :club
          AND active = TRUE AND revoked_at IS NULL
        LIMIT 1
    ");
    $stmt->execute([':uid' => $userId, ':club' => $clubId]);
    if ($stmt->fetchColumn() === false) {
        return ['ok' => false, 'status' => 404, 'error' => 'That account is not a member of this club'];
    }

    $stmt = $pdo->prepare("
        SELECT 1
        FROM guardians g
        WHERE g.id = :gid
          AND EXISTS (
            SELECT 1 FROM athlete_guardians ag
            JOIN athletes a ON a.id = ag.athlete_id
            WHERE ag.guardian_id = g.id AND a.club_id = :club AND a.deleted_at IS NULL
          )
        LIMIT 1
    ");
    $stmt->execute([':gid' => $guardianId, ':club' => $clubId]);
    if ($stmt->fetchColumn() === false) {
        return ['ok' => false, 'status' => 404, 'error' => 'That crew member is not in this club'];
    }

    return ['ok' => true];
}

/** The athletes an account reaches as family, with names, so a zero is visible. */
function te_crew_link_resolved_athletes(PDO $pdo, int $userId): array
{
    $ids = te_athlete_ids_for_user($pdo, $userId);
    if (empty($ids)) {
        return [];
    }

    $clause = te_guardian_ids_in_clause('a.id', $ids, 'aid');
    $stmt = $pdo->prepare("
        SELECT a.id, a.first_name, a.last_name
        FROM athletes a
        WHERE {$clause['sql']} AND a.deleted_at IS NULL
        ORDER BY a.last_name, a.first_name, a.id
    ");
    $stmt->execute($clause['params']);

    return array_map(static function (array $r): array {
        return [
            'id'         => (int) $r['id'],
            'first_name' => (string) $r['first_name'],
            'last_name'  => (string) $r['last_name'],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Record that this account is this guardian.
 *
 * Refuses a guardian already linked to a DIFFERENT account, with 409 and the
 * name of that account. The table would take the row — UNIQUE constrains the
 * pair, not the guardian — but a second account arriving on someone else's
 * recorded relationship is almost always a mistyped id or two people with the
 * same name, and the outcome of getting it wrong is one family reading another
 * family's child's medical record. So the refusal names who holds it and lets a
 * human decide, rather than either silently allowing or silently hiding it.
 */
function te_crew_link_connect(PDO $pdo, $auth, int $clubId, int $userId, int $guardianId): array
{
    if ($clubId <= 0 || $userId <= 0 || $guardianId <= 0) {
        return ['status' => 400, 'body' => ['success' => false, 'error' => 'club_id, user_id and guardian_id are required']];
    }
    if (!te_is_club_admin($auth, $clubId)) {
        return ['status' => 403, 'body' => ['success' => false, 'error' => 'Only club admins can connect accounts to a family']];
    }

    $scope = te_crew_link_check_scope($pdo, $clubId, $userId, $guardianId);
    if (!$scope['ok']) {
        return ['status' => $scope['status'], 'body' => ['success' => false, 'error' => $scope['error']]];
    }

    $stmt = $pdo->prepare("
        SELECT ug.id, ug.user_id, u.first_name, u.last_name, u.email
        FROM user_guardians ug
        JOIN users u ON u.id = ug.user_id
        WHERE ug.guardian_id = :gid
        ORDER BY ug.id
    ");
    $stmt->execute([':gid' => $guardianId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $existing) {
        if ((int) $existing['user_id'] === $userId) {
            return [
                'status' => 200,
                'body' => [
                    'success'       => true,
                    'already_linked' => true,
                    'link_id'       => (int) $existing['id'],
                    'user_id'       => $userId,
                    'guardian_id'   => $guardianId,
                    'athletes'      => te_crew_link_resolved_athletes($pdo, $userId),
                ],
            ];
        }

        return [
            'status' => 409,
            'body' => [
                'success'   => false,
                'reason'    => 'guardian_already_linked',
                'error'     => 'That crew member is already connected to another account.',
                'linked_to' => [
                    'user_id'    => (int) $existing['user_id'],
                    'first_name' => (string) $existing['first_name'],
                    'last_name'  => (string) $existing['last_name'],
                    'email'      => (string) $existing['email'],
                ],
            ],
        ];
    }

    // Before the write, not after: migration 072's trigger reads app.user_id at
    // INSERT time and has no other way to learn who is acting.
    te_db_set_actor($pdo, $auth->getUserId());

    $ins = $pdo->prepare('
        INSERT INTO user_guardians (user_id, guardian_id, source, confidence, linked_by)
        VALUES (:uid, :gid, :source, :confidence, :by)
    ');
    $ins->execute([
        ':uid'        => $userId,
        ':gid'        => $guardianId,
        ':source'     => TE_CREW_LINK_SOURCE,
        ':confidence' => TE_CREW_LINK_CONFIDENCE,
        ':by'         => $auth->getUserId(),
    ]);

    // Read the id back rather than asking PDO for it. On Postgres
    // `lastInsertId()` with no argument is `lastval()`, which returns the last
    // sequence touched by the SESSION — and migration 072's audit trigger has
    // just inserted an `audit_log` row on the way out of this statement. So
    // lastval() answers with the audit row's id, not the link's.
    $idStmt = $pdo->prepare('SELECT id FROM user_guardians WHERE user_id = :uid AND guardian_id = :gid');
    $idStmt->execute([':uid' => $userId, ':gid' => $guardianId]);
    $linkId = (int) $idStmt->fetchColumn();

    $athletes = te_crew_link_resolved_athletes($pdo, $userId);

    AuditLogger::log(
        $pdo,
        $auth->getUserId(),
        'guardian_account_linked',
        'user_guardians',
        $linkId,
        [
            'club_id'       => $clubId,
            'user_id'       => $userId,
            'guardian_id'   => $guardianId,
            'source'        => TE_CREW_LINK_SOURCE,
            'confidence'    => TE_CREW_LINK_CONFIDENCE,
            'linked_by'     => $auth->getUserId(),
            // What the account can now reach. The count is the fact worth having
            // later: a link that resolved to nothing is a link pointed at the
            // wrong guardian, and it is invisible without this.
            'athlete_ids'   => array_column($athletes, 'id'),
            'athlete_count' => count($athletes),
        ]
    );

    return [
        'status' => 201,
        'body' => [
            'success'     => true,
            'link_id'     => $linkId,
            'user_id'     => $userId,
            'guardian_id' => $guardianId,
            'source'      => TE_CREW_LINK_SOURCE,
            'confidence'  => TE_CREW_LINK_CONFIDENCE,
            'athletes'    => $athletes,
        ],
    ];
}

/**
 * Remove a recorded link.
 *
 * Same gates as connecting, because it is the same decision in reverse and its
 * blast radius is a family losing sight of their own child. The email half of
 * the resolver is untouched, so an account whose address still matches keeps
 * whatever it had — removing the row removes the assertion, not necessarily the
 * access, and the returned athlete list says which of the two happened.
 */
function te_crew_link_disconnect(PDO $pdo, $auth, int $clubId, int $userId, int $guardianId): array
{
    if ($clubId <= 0 || $userId <= 0 || $guardianId <= 0) {
        return ['status' => 400, 'body' => ['success' => false, 'error' => 'club_id, user_id and guardian_id are required']];
    }
    if (!te_is_club_admin($auth, $clubId)) {
        return ['status' => 403, 'body' => ['success' => false, 'error' => 'Only club admins can disconnect accounts from a family']];
    }

    $scope = te_crew_link_check_scope($pdo, $clubId, $userId, $guardianId);
    if (!$scope['ok']) {
        return ['status' => $scope['status'], 'body' => ['success' => false, 'error' => $scope['error']]];
    }

    $find = $pdo->prepare('SELECT id FROM user_guardians WHERE user_id = :uid AND guardian_id = :gid');
    $find->execute([':uid' => $userId, ':gid' => $guardianId]);
    $linkId = $find->fetchColumn();
    if ($linkId === false) {
        return ['status' => 404, 'body' => ['success' => false, 'error' => 'These two are not connected']];
    }

    te_db_set_actor($pdo, $auth->getUserId());

    $del = $pdo->prepare('DELETE FROM user_guardians WHERE id = :id');
    $del->execute([':id' => (int) $linkId]);

    $athletes = te_crew_link_resolved_athletes($pdo, $userId);

    AuditLogger::log(
        $pdo,
        $auth->getUserId(),
        'guardian_account_unlinked',
        'user_guardians',
        (int) $linkId,
        [
            'club_id'       => $clubId,
            'user_id'       => $userId,
            'guardian_id'   => $guardianId,
            'unlinked_by'   => $auth->getUserId(),
            'athlete_ids'   => array_column($athletes, 'id'),
            'athlete_count' => count($athletes),
        ]
    );

    return [
        'status' => 200,
        'body' => [
            'success'     => true,
            'user_id'     => $userId,
            'guardian_id' => $guardianId,
            'athletes'    => $athletes,
        ],
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Dispatch
// ─────────────────────────────────────────────────────────────────────────────

try {
    $pdo = Database::getInstance()->getConnection();
} catch (Exception $e) {
    error_log('crew-link: DB connection failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$auth = AuthMiddleware::requireAuth();

$action = $_GET['action'] ?? '';
$body = [];
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    $raw = file_get_contents('php://input');
    $body = $raw ? (json_decode($raw, true) ?: []) : [];
}

$clubId = (int) ($_GET['club_id'] ?? $body['club_id'] ?? 0);

try {
    switch ($action) {
        case 'candidates':
            $result = te_crew_link_candidates($pdo, $auth, $clubId);
            break;
        case 'link':
            $result = te_crew_link_connect(
                $pdo,
                $auth,
                $clubId,
                (int) ($body['user_id'] ?? 0),
                (int) ($body['guardian_id'] ?? 0)
            );
            break;
        case 'unlink':
            $result = te_crew_link_disconnect(
                $pdo,
                $auth,
                $clubId,
                (int) ($body['user_id'] ?? 0),
                (int) ($body['guardian_id'] ?? 0)
            );
            break;
        default:
            $result = [
                'status' => 400,
                'body' => ['success' => false, 'error' => 'Unknown action. Valid: candidates, link, unlink'],
            ];
    }
} catch (Throwable $e) {
    error_log('crew-link: ' . $e->getMessage());
    $result = ['status' => 500, 'body' => ['success' => false, 'error' => 'Could not complete the request']];
}

http_response_code($result['status']);
echo json_encode($result['body']);
