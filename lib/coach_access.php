<?php
/**
 * Coach access — the pieces shared between api/coach-access.php, the profile
 * endpoint and the tests.
 *
 *  - te_coach_access_action_for_status(): which control the Coaches page draws
 *    for a portal status. Mirrored in frontend/src/utils/coachAccess.ts; the
 *    backend re-derives the state from the database and never trusts the
 *    status the client thinks the row is in.
 *  - te_password_set_by_admin_column_present(): the migration-097 probe. `main`
 *    is shared, deploys are by push and migrations run by hand, so this code
 *    reaches production before the column does. Naming a missing column is
 *    42703 and 500s the request; probing once and degrading is the rule
 *    (same shape as te_signature_format_column_present()).
 */

const TE_COACH_TEMP_PASSWORD_MIN_LENGTH = 10;

/**
 * @return 'invite'|'resend'|'login_link'|null  null means "nothing to press",
 *         and the page says why (no email).
 */
function te_coach_access_action_for_status(?string $status): ?string
{
    switch ($status) {
        case 'not_invited':
            return 'invite';
        case 'invited':
        case 'invite_expired':
            return 'resend';
        case 'active':
        case 'account_never_used':
            // Both mean a password exists — a sign-in link is the thing that
            // helps. An invite would be refused as already_active.
            return 'login_link';
        default:
            return null;
    }
}

/** Is `users.password_set_by_admin_at` (migration 097) in the live schema? */
function te_password_set_by_admin_column_present(PDO $pdo): bool
{
    $override = te_password_set_by_admin_probe_override();
    if ($override !== null) {
        return $override;
    }

    static $present = null;
    if ($present !== null) {
        return $present;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM information_schema.columns
              WHERE table_name = 'users' AND column_name = 'password_set_by_admin_at'"
        );
        $stmt->execute();
        $present = (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        // SQLite (the test fixtures) has no information_schema; absent is the
        // safe answer — nothing is written and no banner is shown.
        error_log('te_password_set_by_admin_column_present: ' . $e->getMessage());
        $present = false;
    }

    return $present;
}

/**
 * Test seam: force the probe's answer, or pass null to clear. Explicit rather
 * than reaching into the static, so a test exercising the column-absent path
 * says so in one line and no production caller can reach it by accident.
 */
function te_password_set_by_admin_probe_override(?bool $value = null): ?bool
{
    static $override = null;
    if (func_num_args() > 0) {
        $override = $value;
    }
    return $override;
}
