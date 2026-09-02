<?php
/**
 * The tier above the club: org_units, and who may reach what through it
 * (migration 090, GOTR G1).
 *
 * Girls on the Run is national -> division -> council -> site. A council IS a
 * `club_profile` row, so nothing below the club changes. What this file adds is
 * the tree above it and one answer to "which clubs can this user reach".
 *
 * ⚠️ THREE RULES THAT ARE NOT OPTIONAL
 *
 * 1. THE RESOLVER RETURNS A SUBQUERY, NEVER A LIST OF CLUB IDS.
 *    Every scope predicate in this codebase materialises ids into `IN (?,?,…)`;
 *    `AthleteScope::accessibleAthleteFilter` emits one placeholder per athlete.
 *    Postgres caps bind parameters at 65,535, and a division admin over 30
 *    councils is within an order of magnitude of that hard protocol error —
 *    planner time dies well before it. So `te_org_descendant_club_ids_sql()` and
 *    `te_org_scope_club_ids_sql()` hand back SQL to drop inside `IN (…)` or
 *    `EXISTS (…)`, with a bind count that depends on how many ORG UNITS the user
 *    holds (one, usually) rather than on how many clubs, athletes or teams are
 *    underneath them. A helper here that returns `int[]` of club ids would
 *    defeat the entire point of the file.
 *
 * 2. STANDING INHERITS DOWN THE TREE AND NEVER UP. An admin on a division is an
 *    admin over its councils; an admin on a council is nothing at all at the
 *    division, and nothing at a sibling council. That is a prefix test on the
 *    materialised path and it is written once, in te_user_org_standing().
 *
 * 3. EVERY FUNCTION TOLERATES THE TABLES BEING ABSENT. `main` is shared and
 *    deploys are by push, so this code reaches production the moment any session
 *    pushes — days before migration 090 is applied to Neon by hand. On Postgres a
 *    SELECT against a missing table is 42P01, a hard error that would take down
 *    whatever called it rather than hiding a feature nobody is using yet. The
 *    probe answers false on any failure and the degraded answer is always the
 *    NARROW one: no org units, no org standing, and a scope that is exactly
 *    today's `user_club_access`. Same shape as lib/program_ordering.php and
 *    lib/athlete_evaluations.php.
 *
 * Nothing in the product reads this yet. `te_org_scope_club_ids_sql()` is the
 * future replacement for `AuthMiddleware::getAccessibleClubIds()` and is
 * deliberately NOT wired into AuthMiddleware in this slice.
 */

/** Tables migration 090 creates. They arrive together or not at all. */
const TE_ORG_TABLES = ['org_units', 'user_org_access'];

/** The tiers. Ordered outermost first; the CHECK constraint carries the same three. */
const TE_ORG_UNIT_TYPES = ['national', 'division', 'council'];

/** Standing at a tier, strongest first. Also the precedence order. */
const TE_ORG_ROLES = ['org_admin', 'org_viewer'];

/**
 * Are the migration-090 objects live?
 *
 * Memoised per PDO instance via WeakMap rather than per process: the test suite
 * builds one connection with the tables and one without, and PHP reuses object
 * ids after an object is freed, so an id-keyed cache would let the first
 * connection's answer decide the second's.
 *
 * information_schema is the Postgres answer. SQLite has no information_schema,
 * so that throws and the fallback asks each table directly — safe there
 * precisely because SQLite has no transaction to poison.
 */
function te_org_tables_present(PDO $pdo): bool
{
    static $memo = null;
    $memo ??= new WeakMap();
    if (isset($memo[$pdo])) {
        return $memo[$pdo];
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.tables
              WHERE table_name IN ('org_units', 'user_org_access')"
        );
        $stmt->execute();
        return $memo[$pdo] = ((int) $stmt->fetchColumn() === count(TE_ORG_TABLES));
    } catch (Throwable $e) {
        try {
            foreach (TE_ORG_TABLES as $table) {
                $pdo->query("SELECT 1 FROM {$table} LIMIT 1");
            }
            return $memo[$pdo] = true;
        } catch (Throwable $e2) {
            return $memo[$pdo] = false;
        }
    }
}

/**
 * Id of the row just inserted.
 *
 * PDO_PGSQL needs the sequence name; PDO_SQLITE ignores the argument entirely,
 * so one call serves both drivers.
 */
function te_org_last_insert_id(PDO $pdo, string $sequence): int
{
    try {
        return (int) $pdo->lastInsertId($sequence);
    } catch (Throwable $e) {
        return (int) $pdo->lastInsertId();
    }
}

/** One org unit by id, or null. */
function te_org_unit(PDO $pdo, int $orgUnitId): ?array
{
    if (!te_org_tables_present($pdo) || $orgUnitId <= 0) {
        return null;
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT id, parent_id, type, name, external_code, path, depth
               FROM org_units WHERE id = ?'
        );
        $stmt->execute([$orgUnitId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        error_log('te_org_unit: ' . $e->getMessage());
        return null;
    }
}

/**
 * The whole tree, flat, with how many clubs hang off each node.
 *
 * Flat rather than nested: the caller (a super-admin page) needs both shapes and
 * a flat list with `path` and `depth` is trivially nestable in the browser,
 * while a nested payload is not trivially flattenable. Ordering by `path` puts
 * every node immediately after its parent and before its next sibling, which is
 * exactly the order a tree renders in.
 */
function te_org_unit_tree(PDO $pdo): array
{
    if (!te_org_tables_present($pdo)) {
        return [];
    }
    try {
        $rows = $pdo->query(
            'SELECT o.id, o.parent_id, o.type, o.name, o.external_code, o.path, o.depth,
                    (SELECT COUNT(*) FROM club_profile c WHERE c.org_unit_id = o.id) AS club_count
               FROM org_units o
              ORDER BY o.path'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('te_org_unit_tree: ' . $e->getMessage());
        return [];
    }

    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['parent_id'] = $row['parent_id'] === null ? null : (int) $row['parent_id'];
        $row['depth'] = (int) $row['depth'];
        $row['club_count'] = (int) $row['club_count'];
    }
    return $rows;
}

/** Clubs attached to org units, for the super-admin page's attach/detach panel. */
function te_org_attached_clubs(PDO $pdo): array
{
    if (!te_org_tables_present($pdo)) {
        return [];
    }
    try {
        $rows = $pdo->query(
            'SELECT c.id, c.name, c.org_unit_id
               FROM club_profile c
              WHERE c.org_unit_id IS NOT NULL
              ORDER BY c.name'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('te_org_attached_clubs: ' . $e->getMessage());
        return [];
    }
    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['org_unit_id'] = (int) $row['org_unit_id'];
    }
    return $rows;
}

// ---------------------------------------------------------------- writers ---

/**
 * Create an org unit.
 *
 * The path cannot be known before the id exists, so this is an INSERT with a
 * placeholder path followed by an UPDATE, inside a transaction. A row that
 * committed with the placeholder would be invisible to every prefix search —
 * present in the tree, absent from its own ancestors' scope — which is the worst
 * of the available failures, so the two statements are atomic.
 *
 * @return array{ok: bool, id?: int, path?: string, reason?: string}
 */
function te_org_unit_create(PDO $pdo, array $data, ?int $actorId = null): array
{
    if (!te_org_tables_present($pdo)) {
        return ['ok' => false, 'reason' => 'schema'];
    }

    $name = trim((string) ($data['name'] ?? ''));
    $type = strtolower(trim((string) ($data['type'] ?? '')));
    $code = trim((string) ($data['external_code'] ?? ''));
    $parentId = isset($data['parent_id']) && $data['parent_id'] !== '' && $data['parent_id'] !== null
        ? (int) $data['parent_id'] : null;

    if ($name === '') {
        return ['ok' => false, 'reason' => 'name_required'];
    }
    if (!in_array($type, TE_ORG_UNIT_TYPES, true)) {
        return ['ok' => false, 'reason' => 'bad_type'];
    }

    $parent = null;
    if ($parentId !== null) {
        $parent = te_org_unit($pdo, $parentId);
        if (!$parent) {
            return ['ok' => false, 'reason' => 'parent_not_found'];
        }
    }

    $inTransaction = $pdo->inTransaction();
    if (!$inTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO org_units (parent_id, type, name, external_code, path, depth)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        // '' is a deliberate placeholder that matches no prefix search, so a
        // half-written row can never be read as reachable.
        $stmt->execute([$parentId, $type, $name, $code === '' ? null : $code, '', 0]);
        $id = te_org_last_insert_id($pdo, 'org_units_id_seq');

        $path = te_org_child_path($parent['path'] ?? null, $id);
        $depth = te_org_path_depth($path);
        $pdo->prepare('UPDATE org_units SET path = ?, depth = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([$path, $depth, $id]);

        if (!$inTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if (!$inTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('te_org_unit_create: ' . $e->getMessage());
        return ['ok' => false, 'reason' => 'error'];
    }

    return ['ok' => true, 'id' => $id, 'path' => $path];
}

/** '/1/4/' + 17 => '/1/4/17/'. A root is '/17/'. */
function te_org_child_path(?string $parentPath, int $id): string
{
    $prefix = ($parentPath === null || $parentPath === '') ? '/' : $parentPath;
    return $prefix . $id . '/';
}

/** '/1/4/17/' has depth 2 — a root is depth 0. */
function te_org_path_depth(string $path): int
{
    return max(0, substr_count($path, '/') - 2);
}

/**
 * Rename / recode / retype a unit. Never re-parents — that is te_org_unit_move,
 * because moving is the operation that has to rewrite descendants and refuse
 * cycles, and folding it in here is how one of those gets skipped.
 *
 * Only submitted keys are written, so a partial save cannot blank a field it
 * never sent (the same rule as legacy/guardian-gateway.php).
 *
 * @return array{ok: bool, reason?: string}
 */
function te_org_unit_update(PDO $pdo, int $orgUnitId, array $fields): array
{
    if (!te_org_tables_present($pdo)) {
        return ['ok' => false, 'reason' => 'schema'];
    }
    if (!te_org_unit($pdo, $orgUnitId)) {
        return ['ok' => false, 'reason' => 'not_found'];
    }

    $set = [];
    $params = [];
    if (array_key_exists('name', $fields)) {
        $name = trim((string) $fields['name']);
        if ($name === '') {
            return ['ok' => false, 'reason' => 'name_required'];
        }
        $set[] = 'name = ?';
        $params[] = $name;
    }
    if (array_key_exists('type', $fields)) {
        $type = strtolower(trim((string) $fields['type']));
        if (!in_array($type, TE_ORG_UNIT_TYPES, true)) {
            return ['ok' => false, 'reason' => 'bad_type'];
        }
        $set[] = 'type = ?';
        $params[] = $type;
    }
    if (array_key_exists('external_code', $fields)) {
        $code = trim((string) $fields['external_code']);
        $set[] = 'external_code = ?';
        $params[] = $code === '' ? null : $code;
    }
    if (!$set) {
        return ['ok' => true];
    }

    try {
        $params[] = $orgUnitId;
        $pdo->prepare('UPDATE org_units SET ' . implode(', ', $set)
            . ', updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute($params);
    } catch (Throwable $e) {
        error_log('te_org_unit_update: ' . $e->getMessage());
        return ['ok' => false, 'reason' => 'error'];
    }
    return ['ok' => true];
}

/**
 * Re-parent a unit, rewriting the whole subtree's paths and depths.
 *
 * ⚠️ The descendants are rewritten in the SAME transaction, by string surgery in
 * SQL rather than by walking the tree in PHP: `path = <new> || SUBSTR(path, n)`
 * touches every level of the subtree in one statement, so there is no window in
 * which a grandchild still claims the old ancestry. A subtree left holding stale
 * paths is not a cosmetic bug — those councils silently drop out of the new
 * division's scope and stay inside the old one's.
 *
 * A move under one's own descendant is refused, not corrected. It is the only
 * input that can detach a subtree from the tree entirely.
 *
 * @return array{ok: bool, moved?: int, path?: string, reason?: string}
 */
function te_org_unit_move(PDO $pdo, int $orgUnitId, ?int $newParentId): array
{
    if (!te_org_tables_present($pdo)) {
        return ['ok' => false, 'reason' => 'schema'];
    }

    $node = te_org_unit($pdo, $orgUnitId);
    if (!$node) {
        return ['ok' => false, 'reason' => 'not_found'];
    }

    $parent = null;
    if ($newParentId !== null && $newParentId > 0) {
        if ($newParentId === $orgUnitId) {
            return ['ok' => false, 'reason' => 'cycle'];
        }
        $parent = te_org_unit($pdo, $newParentId);
        if (!$parent) {
            return ['ok' => false, 'reason' => 'parent_not_found'];
        }
        // The prefix test IS the cycle test: a descendant's path starts with
        // this node's path.
        if (strpos((string) $parent['path'], (string) $node['path']) === 0) {
            return ['ok' => false, 'reason' => 'cycle'];
        }
    }

    $oldPath = (string) $node['path'];
    $newPath = te_org_child_path($parent['path'] ?? null, $orgUnitId);
    if ($newPath === $oldPath && (int) ($node['parent_id'] ?? 0) === (int) ($newParentId ?? 0)) {
        return ['ok' => true, 'moved' => 0, 'path' => $newPath];
    }
    $depthDelta = te_org_path_depth($newPath) - te_org_path_depth($oldPath);

    $inTransaction = $pdo->inTransaction();
    if (!$inTransaction) {
        $pdo->beginTransaction();
    }
    try {
        // Descendants first, while the old prefix still identifies them.
        // SUBSTR is 1-based in both Postgres and SQLite, so the offset is
        // strlen(oldPath) + 1: '/1/4/17/' minus '/1/4/' leaves '17/'.
        $desc = $pdo->prepare(
            'UPDATE org_units
                SET path = ? || SUBSTR(path, ?), depth = depth + ?, updated_at = CURRENT_TIMESTAMP
              WHERE path LIKE ? AND id <> ?'
        );
        $desc->execute([$newPath, strlen($oldPath) + 1, $depthDelta, $oldPath . '%', $orgUnitId]);
        $moved = $desc->rowCount();

        $pdo->prepare(
            'UPDATE org_units SET parent_id = ?, path = ?, depth = ?, updated_at = CURRENT_TIMESTAMP
              WHERE id = ?'
        )->execute([$parent ? (int) $parent['id'] : null, $newPath, te_org_path_depth($newPath), $orgUnitId]);

        if (!$inTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if (!$inTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('te_org_unit_move: ' . $e->getMessage());
        return ['ok' => false, 'reason' => 'error'];
    }

    return ['ok' => true, 'moved' => $moved, 'path' => $newPath];
}

/**
 * Delete a unit, and refuse if anything hangs off it.
 *
 * Refusing is the point. `user_org_access` cascades, so a delete would silently
 * revoke people's standing; `club_profile.org_unit_id` does NOT cascade, so a
 * delete would either fail at the FK or (with a different FK) orphan a council
 * out of every rollup it belongs to. Detach the clubs and move or delete the
 * children first — deliberately, one at a time, with the counts visible.
 *
 * @return array{ok: bool, reason?: string, children?: int, clubs?: int}
 */
function te_org_unit_delete(PDO $pdo, int $orgUnitId): array
{
    if (!te_org_tables_present($pdo)) {
        return ['ok' => false, 'reason' => 'schema'];
    }
    if (!te_org_unit($pdo, $orgUnitId)) {
        return ['ok' => false, 'reason' => 'not_found'];
    }

    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM org_units WHERE parent_id = ?');
        $stmt->execute([$orgUnitId]);
        $children = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM club_profile WHERE org_unit_id = ?');
        $stmt->execute([$orgUnitId]);
        $clubs = (int) $stmt->fetchColumn();

        if ($children > 0 || $clubs > 0) {
            return ['ok' => false, 'reason' => 'not_empty', 'children' => $children, 'clubs' => $clubs];
        }

        $pdo->prepare('DELETE FROM org_units WHERE id = ?')->execute([$orgUnitId]);
    } catch (Throwable $e) {
        error_log('te_org_unit_delete: ' . $e->getMessage());
        return ['ok' => false, 'reason' => 'error'];
    }
    return ['ok' => true];
}

/**
 * Attach a club to an org unit, or (with null) detach it.
 *
 * @return array{ok: bool, reason?: string}
 */
function te_org_attach_club(PDO $pdo, int $clubId, ?int $orgUnitId): array
{
    if (!te_org_tables_present($pdo)) {
        return ['ok' => false, 'reason' => 'schema'];
    }
    if ($orgUnitId !== null && !te_org_unit($pdo, $orgUnitId)) {
        return ['ok' => false, 'reason' => 'not_found'];
    }
    try {
        $stmt = $pdo->prepare('UPDATE club_profile SET org_unit_id = ? WHERE id = ?');
        $stmt->execute([$orgUnitId, $clubId]);
        if ($stmt->rowCount() < 1) {
            return ['ok' => false, 'reason' => 'club_not_found'];
        }
    } catch (Throwable $e) {
        error_log('te_org_attach_club: ' . $e->getMessage());
        return ['ok' => false, 'reason' => 'error'];
    }
    return ['ok' => true];
}

/**
 * Grant standing at a tier.
 *
 * A previously revoked grant is REVIVED rather than duplicated — the UNIQUE
 * (user, unit, role) makes a second INSERT an error, and clearing the revocation
 * columns is what "grant it again" means. The audit columns keep the history;
 * `audit_log` keeps the event.
 *
 * @return array{ok: bool, reason?: string}
 */
function te_org_access_grant(PDO $pdo, int $userId, int $orgUnitId, string $role, ?int $actorId = null): array
{
    if (!te_org_tables_present($pdo)) {
        return ['ok' => false, 'reason' => 'schema'];
    }
    $role = strtolower(trim($role));
    if (!in_array($role, TE_ORG_ROLES, true)) {
        return ['ok' => false, 'reason' => 'bad_role'];
    }
    if (!te_org_unit($pdo, $orgUnitId)) {
        return ['ok' => false, 'reason' => 'not_found'];
    }

    try {
        $stmt = $pdo->prepare('SELECT id FROM user_org_access WHERE user_id = ? AND org_unit_id = ? AND role = ?');
        $stmt->execute([$userId, $orgUnitId, $role]);
        $existing = $stmt->fetchColumn();

        if ($existing) {
            $pdo->prepare(
                'UPDATE user_org_access
                    SET active = TRUE, revoked_at = NULL, revoked_by = NULL,
                        granted_at = CURRENT_TIMESTAMP, granted_by = ?
                  WHERE id = ?'
            )->execute([$actorId, (int) $existing]);
        } else {
            $pdo->prepare(
                'INSERT INTO user_org_access (user_id, org_unit_id, role, granted_by, active)
                 VALUES (?, ?, ?, ?, TRUE)'
            )->execute([$userId, $orgUnitId, $role, $actorId]);
        }
    } catch (Throwable $e) {
        error_log('te_org_access_grant: ' . $e->getMessage());
        return ['ok' => false, 'reason' => 'error'];
    }
    return ['ok' => true];
}

/**
 * Revoke standing. Marks the row; never deletes it — "who could see this council
 * in March" has to stay answerable.
 *
 * @return array{ok: bool, reason?: string}
 */
function te_org_access_revoke(PDO $pdo, int $userId, int $orgUnitId, string $role, ?int $actorId = null): array
{
    if (!te_org_tables_present($pdo)) {
        return ['ok' => false, 'reason' => 'schema'];
    }
    try {
        $stmt = $pdo->prepare(
            'UPDATE user_org_access
                SET active = FALSE, revoked_at = CURRENT_TIMESTAMP, revoked_by = ?
              WHERE user_id = ? AND org_unit_id = ? AND role = ?'
        );
        $stmt->execute([$actorId, $userId, $orgUnitId, strtolower(trim($role))]);
        if ($stmt->rowCount() < 1) {
            return ['ok' => false, 'reason' => 'not_found'];
        }
    } catch (Throwable $e) {
        error_log('te_org_access_revoke: ' . $e->getMessage());
        return ['ok' => false, 'reason' => 'error'];
    }
    return ['ok' => true];
}

/** Every live grant, with the person and the unit, for the super-admin page. */
function te_org_access_list(PDO $pdo): array
{
    if (!te_org_tables_present($pdo)) {
        return [];
    }
    try {
        return $pdo->query(
            "SELECT a.id, a.user_id, a.org_unit_id, a.role, a.granted_at,
                    u.email, u.first_name, u.last_name, o.name AS org_unit_name
               FROM user_org_access a
               JOIN users u ON u.id = a.user_id
               JOIN org_units o ON o.id = a.org_unit_id
              WHERE a.active = TRUE AND a.revoked_at IS NULL
              ORDER BY o.path, u.last_name, u.first_name"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('te_org_access_list: ' . $e->getMessage());
        return [];
    }
}

// --------------------------------------------------------------- resolvers ---

/**
 * A user's live org grants: active, not revoked, with the unit's path so the
 * caller can do prefix arithmetic without a second query.
 *
 * ⚠️ Both `active = TRUE` and `revoked_at IS NULL`. The two columns CAN disagree
 * — one live `user_club_access` row carried `active = TRUE` with `revoked_at`
 * set two months earlier — and when they do, the revocation is the newer fact.
 * lib/JWT.php filtered on `active` alone and minted a revoked role for a year.
 *
 * @return array<int, array{org_unit_id:int, role:string, path:string, depth:int, name:string, type:string}>
 */
function te_org_units_for_user(PDO $pdo, int $userId): array
{
    if (!te_org_tables_present($pdo) || $userId <= 0) {
        return [];
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT a.org_unit_id, a.role, o.path, o.depth, o.name, o.type
               FROM user_org_access a
               JOIN org_units o ON o.id = a.org_unit_id
              WHERE a.user_id = ? AND a.active = TRUE AND a.revoked_at IS NULL
              ORDER BY o.path'
        );
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('te_org_units_for_user: ' . $e->getMessage());
        return [];
    }

    foreach ($rows as &$row) {
        $row['org_unit_id'] = (int) $row['org_unit_id'];
        $row['depth'] = (int) $row['depth'];
    }
    return $rows;
}

/**
 * Standing at ONE org unit: 'org_admin', 'org_viewer' or null.
 *
 * Inherits DOWN and never up. The test is a path prefix: a grant applies when
 * the target's path starts with the granted unit's path, which is true for the
 * unit itself and everything beneath it, and false for its parent and for every
 * sibling. Trailing slashes make the prefix unambiguous — without them a grant
 * on '/1/4' would also cover '/1/40/'.
 *
 * A super admin is treated as org_admin everywhere, matching every other gate in
 * this codebase.
 */
function te_user_org_standing(PDO $pdo, $auth, int $orgUnitId): ?string
{
    if ($auth && method_exists($auth, 'isSuperAdmin') && $auth->isSuperAdmin()) {
        return 'org_admin';
    }
    if (!te_org_tables_present($pdo)) {
        return null;
    }

    $target = te_org_unit($pdo, $orgUnitId);
    if (!$target) {
        return null;
    }
    $targetPath = (string) $target['path'];
    if ($targetPath === '') {
        return null;
    }

    $userId = (int) ($auth && method_exists($auth, 'getUserId') ? $auth->getUserId() : 0);
    $best = null;
    foreach (te_org_units_for_user($pdo, $userId) as $grant) {
        $grantPath = (string) $grant['path'];
        if ($grantPath === '' || strpos($targetPath, $grantPath) !== 0) {
            continue;
        }
        if ($grant['role'] === 'org_admin') {
            return 'org_admin';
        }
        $best = $best ?? 'org_viewer';
    }
    return $best;
}

/**
 * SUBQUERY selecting every club under the given org units — the units
 * themselves included.
 *
 * ```sql
 * SELECT c.id
 *   FROM club_profile c
 *   JOIN org_units o ON o.id = c.org_unit_id
 *  WHERE EXISTS (SELECT 1 FROM org_units a
 *                 WHERE a.id IN (?, ?)
 *                   AND o.path LIKE a.path || '%')
 * ```
 *
 * Two decisions worth keeping:
 *
 * - **The prefixes are derived in SQL, from the ids, rather than being read into
 *   PHP first.** This function takes ids and no PDO, so it cannot look paths up;
 *   more usefully, deriving them in the statement means a re-parent that happens
 *   between building the query and running it cannot leave the caller scoped to
 *   a tree that no longer exists.
 * - **`%` matches the empty string**, so `LIKE '/1/4/%'` matches '/1/4/' itself.
 *   A division admin therefore reaches the division's own directly-attached
 *   clubs as well as its councils', which is what "everything under me" means.
 *
 * An empty id list returns a subquery that selects nothing — `1=0` rather than
 * `IN ()`, which is a syntax error rather than an empty result and has taken
 * this codebase down twice.
 *
 * @param int[] $orgUnitIds
 * @return array{sql: string, params: array}
 */
function te_org_descendant_club_ids_sql(array $orgUnitIds): array
{
    $ids = [];
    foreach ($orgUnitIds as $raw) {
        $id = (int) $raw;
        if ($id > 0 && !in_array($id, $ids, true)) {
            $ids[] = $id;
        }
    }
    if (!$ids) {
        return ['sql' => 'SELECT c.id FROM club_profile c WHERE 1=0', 'params' => []];
    }

    $placeholders = implode(', ', array_fill(0, count($ids), '?'));
    $sql = 'SELECT c.id'
        . ' FROM club_profile c'
        . ' JOIN org_units o ON o.id = c.org_unit_id'
        . ' WHERE EXISTS ('
        . 'SELECT 1 FROM org_units a'
        . " WHERE a.id IN ($placeholders)"
        . " AND o.path LIKE a.path || '%'"
        . ')';

    return ['sql' => $sql, 'params' => $ids];
}

/**
 * Every club this user can reach: the descendants of their org units UNION the
 * clubs they hold a direct `user_club_access` row in.
 *
 * This is the future replacement for `AuthMiddleware::getAccessibleClubIds()`,
 * which returns `int[]` and is spread across ten callers that bind it
 * positionally. It is deliberately NOT wired in yet — that swap is its own
 * slice, with its own revert.
 *
 * The union is the whole design. A GOTR division admin reaches 30 councils
 * through one org row; the same person may also be a coach at one club, which
 * has nothing to do with the tree. Neither half can be expressed as the other.
 *
 * A super admin gets every club, as an unfiltered subquery rather than a
 * sentinel: `getAccessibleClubIds()` returns NULL for super admins and every
 * caller has to remember what that means. A subquery does not need remembering.
 *
 * @return array{sql: string, params: array}
 */
function te_org_scope_club_ids_sql(PDO $pdo, $auth): array
{
    if ($auth && method_exists($auth, 'isSuperAdmin') && $auth->isSuperAdmin()) {
        return ['sql' => 'SELECT c.id FROM club_profile c', 'params' => []];
    }

    $userId = (int) ($auth && method_exists($auth, 'getUserId') ? $auth->getUserId() : 0);
    if ($userId <= 0) {
        return ['sql' => 'SELECT c.id FROM club_profile c WHERE 1=0', 'params' => []];
    }

    // Direct club roles. Always present, tables or no tables — with migration
    // 090 unapplied this is exactly today's scope, which is the point of the
    // degraded path.
    $sql = 'SELECT uca.club_profile_id FROM user_club_access uca'
        . ' WHERE uca.user_id = ? AND uca.active = TRUE AND uca.revoked_at IS NULL';
    $params = [$userId];

    $orgUnitIds = array_map(
        static fn (array $g): int => $g['org_unit_id'],
        te_org_units_for_user($pdo, $userId)
    );
    if ($orgUnitIds) {
        $descendants = te_org_descendant_club_ids_sql($orgUnitIds);
        $sql = $descendants['sql'] . ' UNION ' . $sql;
        $params = array_merge($descendants['params'], $params);
    }

    return ['sql' => $sql, 'params' => $params];
}
