<?php
/**
 * "Has this person actually used the platform?" — one answer, shared by the Crew
 * page and the Coaches page.
 *
 * REPLACES an inference that was wrong three ways. The old CASE was:
 *
 *     WHEN u.password_hash IS NOT NULL AND u.password_hash <> '' THEN 'active'
 *     WHEN EXISTS (unused token AND expires_at > NOW())          THEN 'invited'
 *     ELSE 'not_invited'
 *
 *   1. A PASSWORD IS NOT A LOGIN. Passwords are set by admins (coaches-gateway
 *      seeds a literal) and by auto-created athlete shells. Neither means the
 *      person did anything. On 2026-07-31 this displayed two coaches as portal
 *      active who had never signed in and had never been invited.
 *
 *   2. AN EXPIRED INVITE SILENTLY BECAME "NOT INVITED". The `expires_at > NOW()`
 *      test has no third branch, so a lapsed invite falls through to not_invited
 *      and the club loses the record that it ever reached out. 64 Central Kansas
 *      invites expire on 2026-08-07; without `invite_expired` those families would
 *      have gone from "invited" to "never contacted" overnight, turning a reminder
 *      into a wrong first contact.
 *
 *   3. THE JOIN IS ON EMAIL ALONE and cannot be fixed here — it is the missing
 *      `user_guardians` table (see CLAUDE.md). So this does not pretend to resolve
 *      it: it DISCLOSES it. `shared_account` marks a row whose evidence may belong
 *      to somebody else, and the UI marks it rather than asserting.
 *
 * Evidence used, in order:
 *   audit_log 'login_success'  →  users.last_login_at  →  magic_link_tokens
 *
 * ⚠️ audit_log.resource_type holds BOTH 'user' (68 rows, to 2026-07-29) and 'users'
 * (123 rows, since). Matching only one loses six people's first-login date entirely.
 * ⚠️ 15 users have last_login_at with no audit row at all, so the fallback is
 * load-bearing — dropping it would report them as never signed in.
 */

/**
 * SQL columns for the status of whoever `$emailExpr` identifies.
 * `$userAlias` must be a users row already LEFT JOINed by the caller.
 */
function te_portal_status_columns(string $emailExpr, string $userAlias = 'u'): string
{
    $e = "lower(btrim({$emailExpr}))";
    $u = $userAlias;

    return "
        -- First login. COALESCE order matters: the audit row is the precise one,
        -- last_login_at is the fallback for accounts that predate auditing.
        COALESCE(
            (SELECT min(al.created_at) FROM audit_log al
              WHERE al.action = 'login_success'
                AND al.resource_type IN ('user', 'users')
                AND al.resource_id = {$u}.id),
            {$u}.last_login_at
        ) AS first_login_at,
        {$u}.last_login_at AS last_login_at,

        (SELECT min(t.created_at) FROM magic_link_tokens t
          WHERE t.email = {$e} || ':parent_invite')                      AS invited_at,
        (SELECT min(t.used_at) FROM magic_link_tokens t
          WHERE t.email = {$e} || ':parent_invite')                      AS invite_used_at,
        (SELECT max(t.expires_at) FROM magic_link_tokens t
          WHERE t.email = {$e} || ':parent_invite' AND t.used_at IS NULL) AS invite_expires_at,

        ({$u}.password_hash IS NOT NULL AND {$u}.password_hash <> '')     AS has_password,

        -- Disclosure, not resolution: reasons the evidence may not be this person's.
        (SELECT count(*) FROM users u2 WHERE lower(u2.email) = {$e})      AS accounts_on_email,
        (SELECT count(*) FROM athletes a2 WHERE a2.user_id = {$u}.id)     AS athlete_shells,
        (SELECT string_agg(DISTINCT uca2.role, '/') FROM user_club_access uca2
          WHERE uca2.user_id = {$u}.id AND uca2.active AND uca2.role <> 'parent') AS other_roles
    ";
}

/**
 * Classify one row from the columns above.
 *
 * @param string $audience 'crew' or 'coach' — only affects which roles count as
 *                         "somebody else's account".
 * @return array{status:string,first_login_at:?string,shared_account:bool,shared_reason:?string}
 */
function te_portal_status(array $r, string $email, string $audience = 'crew'): array
{
    $first = $r['first_login_at'] ?? null;

    // A coach legitimately holds a coach role, so it is not evidence of a mix-up
    // for them; for crew it is exactly the Samantha Archer case.
    $reasons = [];
    if ((int) ($r['accounts_on_email'] ?? 0) > 1) {
        $reasons[] = $r['accounts_on_email'] . ' accounts use this email';
    }
    if ((int) ($r['athlete_shells'] ?? 0) > 0) {
        $reasons[] = 'an athlete record points at this account';
    }
    if ($audience === 'crew' && !empty($r['other_roles'])) {
        $reasons[] = 'this account is also a ' . str_replace('/', ' / ', $r['other_roles']);
    }

    if (trim((string) $email) === '') {
        $status = 'no_email';
    } elseif ($first !== null) {
        $status = 'active';
    } elseif (!empty($r['invite_used_at'])) {
        // Set a password from the invite but never came back.
        $status = 'account_never_used';
    } elseif (!empty($r['has_password'])) {
        // Credentials exist that this person never asked for — an admin made the
        // account. Distinct from the line above: they may not know it exists.
        $status = 'account_never_used';
    } elseif (!empty($r['invited_at'])) {
        $expires = $r['invite_expires_at'] ?? null;
        $status = ($expires !== null && strtotime($expires) > time()) ? 'invited' : 'invite_expired';
    } else {
        $status = 'not_invited';
    }

    return [
        'status'         => $status,
        'first_login_at' => $first,
        'invited_at'     => $r['invited_at'] ?? null,
        'shared_account' => $reasons !== [],
        'shared_reason'  => $reasons ? implode('; ', $reasons) : null,
    ];
}
