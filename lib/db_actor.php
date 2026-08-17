<?php
/**
 * Tell the database who is acting, so database-level audit triggers can attribute.
 *
 * Migration 070 audits every change to athlete_guardians with a trigger, because that
 * is the only way to see writes made outside PHP — and a link removed outside PHP is
 * exactly the event that went unexplained on 2026-07-31. A trigger cannot see the
 * logged-in user, so the request tells it, once, via a session GUC.
 *
 * Call this immediately after resolving the authenticated user, before any write.
 * Forgetting it degrades the audit row to user_id NULL; it never breaks the write.
 * That is the intended failure mode: an unattributed row still records that the change
 * happened and when, which is strictly more than existed before.
 *
 * set_config(..., false) is session scope, not transaction scope. SET LOCAL would be
 * discarded outside a transaction, which is where most of these gateways run. PHP-FPM
 * hands each request its own connection here, so session scope ends with the request.
 */

/**
 * @param int|null $userId users(id) of the acting person, or null to clear.
 */
function te_db_set_actor(PDO $pdo, ?int $userId): void
{
    try {
        $stmt = $pdo->prepare('SELECT set_config(?, ?, false)');
        $stmt->execute(['app.user_id', $userId !== null ? (string) $userId : '']);
    } catch (Throwable $e) {
        // Never let bookkeeping break the request it is describing — same contract as
        // AuditLogger, which also swallows and logs.
        error_log('te_db_set_actor: ' . $e->getMessage());
    }
}

/**
 * Read back what the connection currently believes. Diagnostics and tests only.
 */
function te_db_get_actor(PDO $pdo): ?int
{
    try {
        $stmt = $pdo->query("SELECT NULLIF(current_setting('app.user_id', true), '')");
        $value = $stmt->fetchColumn();
        return ($value === false || $value === null || $value === '') ? null : (int) $value;
    } catch (Throwable $e) {
        return null;
    }
}
