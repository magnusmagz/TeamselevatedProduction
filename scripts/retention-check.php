<?php
/**
 * Data retention enforcement.
 *
 * DATA-RETENTION-SETUP.md describes this script; it is absent from the tree, so
 * `data_retention_policy` has been seeded and read by nothing. Every policy row —
 * including the athlete_medical one added with migration 051 — has been inert.
 *
 *   heroku run php scripts/retention-check.php            # report only (default)
 *   heroku run php scripts/retention-check.php --purge    # actually delete
 *   heroku run php scripts/retention-check.php --type=medical_records
 *
 * REPORT BY DEFAULT. Deleting families' records is not something a scheduled job
 * should do because someone forgot a flag, so --purge is required to remove
 * anything, and a policy additionally has to have auto_delete = TRUE. Both.
 *
 * Every purge is written to audit_log. A retention deletion nobody can reconstruct
 * afterwards is indistinguishable from data loss.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/AuditLogger.php';

$purge  = in_array('--purge', $argv, true);
$onlyType = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--type=')) $onlyType = substr($a, 7);
}

/**
 * How each policy identifies expired rows.
 *
 * Keyed by data_retention_policy.data_type. `sql` must select ids to delete;
 * `delete` removes them. Anything without an entry here is reported as
 * unsupported rather than guessed at — a wrong DELETE is unrecoverable.
 *
 * All of these are scoped to INACTIVE athletes: retention never touches a child
 * who is still with the club, regardless of how old their record is.
 */
function retentionPlans(): array
{
    return [
        'athlete_medical' => [
            'label'  => 'Athlete health profiles',
            'count'  => "SELECT count(*) FROM athlete_medical m JOIN athletes a ON a.id = m.athlete_id
                         WHERE a.active_status = FALSE AND a.deleted_at IS NOT NULL
                           AND a.deleted_at < NOW() - (:days || ' days')::interval",
            'delete' => "DELETE FROM athlete_medical WHERE athlete_id IN (
                             SELECT a.id FROM athletes a
                             WHERE a.active_status = FALSE AND a.deleted_at IS NOT NULL
                               AND a.deleted_at < NOW() - (:days || ' days')::interval)",
        ],
        'medical_records' => [
            'label'  => 'Medical documents',
            'count'  => "SELECT count(*) FROM medical_records m JOIN athletes a ON a.id = m.athlete_id
                         WHERE a.active_status = FALSE AND a.deleted_at IS NOT NULL
                           AND a.deleted_at < NOW() - (:days || ' days')::interval",
            'delete' => "DELETE FROM medical_records WHERE athlete_id IN (
                             SELECT a.id FROM athletes a
                             WHERE a.active_status = FALSE AND a.deleted_at IS NOT NULL
                               AND a.deleted_at < NOW() - (:days || ' days')::interval)",
        ],
        'consent_records' => [
            'label'  => 'Revoked consents',
            'count'  => "SELECT count(*) FROM consent_records
                         WHERE revoked_at IS NOT NULL
                           AND revoked_at < NOW() - (:days || ' days')::interval",
            'delete' => "DELETE FROM consent_records
                         WHERE revoked_at IS NOT NULL
                           AND revoked_at < NOW() - (:days || ' days')::interval",
        ],
        'audit_logs' => [
            'label'  => 'Audit log entries',
            'count'  => "SELECT count(*) FROM audit_log WHERE created_at < NOW() - (:days || ' days')::interval",
            'delete' => "DELETE FROM audit_log WHERE created_at < NOW() - (:days || ' days')::interval",
        ],
    ];
}

$db = Database::getInstance()->getConnection();

$policies = $db->query(
    'SELECT data_type, retention_days, description, auto_delete FROM data_retention_policy ORDER BY data_type'
)->fetchAll(PDO::FETCH_ASSOC);

if (!$policies) {
    fwrite(STDERR, "No retention policies configured.\n");
    exit(1);
}

$plans = retentionPlans();
printf("Retention check — mode: %s\n\n", $purge ? 'PURGE' : 'report only');
printf("  %-22s %8s  %8s  %-11s %s\n", 'policy', 'days', 'expired', 'auto_delete', 'action');
printf("  %s\n", str_repeat('-', 74));

$totalExpired = 0;
$totalPurged  = 0;

foreach ($policies as $p) {
    $type = $p['data_type'];
    if ($onlyType !== null && $type !== $onlyType) continue;

    $days = (int) $p['retention_days'];
    $auto = in_array($p['auto_delete'], [true, 't', '1', 1], true);

    if (!isset($plans[$type])) {
        printf("  %-22s %8d  %8s  %-11s %s\n", $type, $days, '-', $auto ? 'yes' : 'no', 'UNSUPPORTED — no rule defined');
        continue;
    }

    try {
        $stmt = $db->prepare($plans[$type]['count']);
        $stmt->execute([':days' => $days]);
        $expired = (int) $stmt->fetchColumn();
    } catch (Exception $e) {
        printf("  %-22s %8d  %8s  %-11s ERROR: %s\n", $type, $days, '?', $auto ? 'yes' : 'no',
               explode("\n", $e->getMessage())[0]);
        continue;
    }

    $totalExpired += $expired;

    // Both gates: the operator asked to purge AND the policy allows it.
    if ($expired > 0 && $purge && $auto) {
        try {
            $del = $db->prepare($plans[$type]['delete']);
            $del->execute([':days' => $days]);
            $n = $del->rowCount();
            $totalPurged += $n;
            AuditLogger::log($db, null, 'retention_purge', $type, null, [
                'retention_days' => $days, 'rows_deleted' => $n,
            ]);
            printf("  %-22s %8d  %8d  %-11s PURGED %d\n", $type, $days, $expired, 'yes', $n);
        } catch (Exception $e) {
            printf("  %-22s %8d  %8d  %-11s PURGE FAILED: %s\n", $type, $days, $expired, 'yes',
                   explode("\n", $e->getMessage())[0]);
        }
        continue;
    }

    $action = $expired === 0 ? 'nothing to do'
            : (!$purge ? 'would purge (use --purge)' : 'skipped (auto_delete = FALSE)');
    printf("  %-22s %8d  %8d  %-11s %s\n", $type, $days, $expired, $auto ? 'yes' : 'no', $action);
}

printf("\n  %d row(s) past retention; %d deleted.\n", $totalExpired, $totalPurged);
if (!$purge && $totalExpired > 0) {
    print("  Re-run with --purge to delete (policies with auto_delete = FALSE stay untouched).\n");
}
