<?php
/**
 * Manual display order and archive-without-delete for programs (migration 084).
 *
 * ⚠️ Every function here tolerates the three columns being ABSENT. `main` is
 * shared and deploys are by push, so this code reaches production the moment
 * any session pushes, which may be days before migration 084 is applied to Neon
 * by hand. On Postgres a reference to a missing column is 42703 — a hard error
 * that would take the whole Programs list down for every club, not just hide a
 * new feature. So the column set is probed once per request and the SQL is built
 * around what is actually there.
 *
 * The same reasoning as the SAVEPOINT around registration consent capture: the
 * new thing must not be able to break the old thing while the schema catches up.
 */

/** The columns migration 084 adds. All three arrive together or not at all. */
const TE_PROGRAM_ORDER_COLUMNS = ['sort_order', 'archived_at', 'archived_by'];

/**
 * Are the migration-084 columns live?
 *
 * One information_schema query per request, memoised. A failed probe answers
 * false — the degraded path is always the safe one.
 */
function te_program_order_columns_present(PDO $pdo): bool
{
    static $present = null;
    if ($present !== null) {
        return $present;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT column_name FROM information_schema.columns
              WHERE table_name = 'programs'
                AND column_name IN ('sort_order', 'archived_at', 'archived_by')"
        );
        $stmt->execute();
        $found = $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
        $present = count(array_intersect(TE_PROGRAM_ORDER_COLUMNS, $found)) === count(TE_PROGRAM_ORDER_COLUMNS);
    } catch (Throwable $e) {
        error_log('te_program_order_columns_present: ' . $e->getMessage());
        $present = false;
    }

    return $present;
}

/**
 * ORDER BY body (no `ORDER BY` keyword) with the manual order in front of
 * whatever the caller already ordered by.
 *
 * `NULLS LAST` is explicit rather than relying on the Postgres ASC default: a
 * program nobody has dragged has sort_order NULL and must sink to the bottom of
 * its section, and the next person to read this query should not have to know
 * the default to see that.
 */
function te_program_order_by(PDO $pdo, string $existingOrder, string $alias = 'p'): string
{
    $existingOrder = trim($existingOrder);
    if (!te_program_order_columns_present($pdo)) {
        return $existingOrder;
    }
    $col = $alias === '' ? 'sort_order' : $alias . '.sort_order';
    return $col . ' ASC NULLS LAST' . ($existingOrder === '' ? '' : ', ' . $existingOrder);
}

/**
 * WHERE fragment excluding archived programs, or '' when archived rows are
 * wanted (or when the column does not exist yet).
 *
 * Returned pre-prefixed with ` AND ` so it drops into an existing WHERE.
 */
function te_program_archive_filter(PDO $pdo, bool $includeArchived, string $alias = 'p'): string
{
    if ($includeArchived || !te_program_order_columns_present($pdo)) {
        return '';
    }
    $col = $alias === '' ? 'archived_at' : $alias . '.archived_at';
    return ' AND ' . $col . ' IS NULL';
}

/**
 * Did the caller ask for archived rows? Accepts `1`/`true`/`yes`; anything else,
 * including absent, means no. A typo must not silently widen a list.
 */
function te_program_include_archived_requested($raw): bool
{
    if ($raw === null) {
        return false;
    }
    return in_array(strtolower(trim((string)$raw)), ['1', 'true', 'yes', 'on'], true);
}

/**
 * Archive or unarchive one program.
 *
 * @return array{ok: bool, reason?: string} `reason` is 'schema' when the
 *         columns are not there yet — a distinct answer from "not found", so the
 *         gateway can say "not available yet" instead of lying about the row.
 */
function te_program_set_archived(PDO $pdo, int $programId, bool $archived, ?int $actorId): array
{
    if (!te_program_order_columns_present($pdo)) {
        return ['ok' => false, 'reason' => 'schema'];
    }

    if ($archived) {
        $stmt = $pdo->prepare(
            'UPDATE programs SET archived_at = NOW(), archived_by = ? WHERE id = ?'
        );
        $stmt->execute([$actorId, $programId]);
    } else {
        $stmt = $pdo->prepare(
            'UPDATE programs SET archived_at = NULL, archived_by = NULL WHERE id = ?'
        );
        $stmt->execute([$programId]);
    }

    if ($stmt->rowCount() < 1) {
        return ['ok' => false, 'reason' => 'not_found'];
    }
    return ['ok' => true];
}

/**
 * Write `sort_order` = position for an ordered list of program ids.
 *
 * The caller has already established that $clubId is one the actor administers.
 * Every id is re-checked against that club here rather than trusted from the
 * body — the ids come from the browser, and a reorder that accepted a foreign id
 * would let one club's admin renumber another club's programs. Same rule as
 * every other gateway: bound what the endpoint accepts, not what the form sends.
 *
 * @return array{ok: bool, reason?: string, updated?: int, foreign?: int[]}
 */
function te_program_reorder(PDO $pdo, array $programIds, int $clubId): array
{
    if (!te_program_order_columns_present($pdo)) {
        return ['ok' => false, 'reason' => 'schema'];
    }

    $ids = [];
    foreach ($programIds as $raw) {
        $id = (int)$raw;
        if ($id > 0 && !in_array($id, $ids, true)) {
            $ids[] = $id;
        }
    }
    if (!$ids) {
        return ['ok' => false, 'reason' => 'empty'];
    }

    // array_fill(0, 0, '?') produces `IN ()`, which is a syntax error rather
    // than an empty result — the empty case is refused above.
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $check = $pdo->prepare("SELECT id FROM programs WHERE id IN ($placeholders) AND club_id = ?");
    $check->execute(array_merge($ids, [$clubId]));
    $mine = array_map('intval', $check->fetchAll(PDO::FETCH_COLUMN, 0) ?: []);

    $foreign = array_values(array_diff($ids, $mine));
    if ($foreign) {
        return ['ok' => false, 'reason' => 'foreign_club', 'foreign' => $foreign];
    }

    $update = $pdo->prepare('UPDATE programs SET sort_order = ? WHERE id = ? AND club_id = ?');
    $inTransaction = $pdo->inTransaction();
    if (!$inTransaction) {
        $pdo->beginTransaction();
    }
    try {
        foreach ($ids as $index => $id) {
            $update->execute([$index, $id, $clubId]);
        }
        if (!$inTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if (!$inTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return ['ok' => true, 'updated' => count($ids)];
}
