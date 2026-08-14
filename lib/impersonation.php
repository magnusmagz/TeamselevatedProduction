<?php
/**
 * Super-admin impersonation ("view as user").
 *
 * The whole design rests on one decision: an impersonation token carries the
 * TARGET's `user_id`. Authorization everywhere else in this codebase is derived
 * from that id — `AuthMiddleware::refreshRolesFromDb()` re-reads
 * `JWT::buildOrganizationalContext()` on every request — so an impersonated
 * session sees exactly what the target sees, with no per-endpoint changes and no
 * second code path that can drift from the real one. A design that instead kept
 * the admin's id and passed an "acting as" hint would have to be honoured by
 * every gateway, and the one that forgot would be a silent privilege escalation.
 *
 * What the token adds is the `imp` claim, which records WHO is behind the
 * session. It is evidence and an exit route, never a grant: nothing anywhere
 * grants access because `imp` is present.
 *
 * Three rules that are load-bearing:
 *
 * 1. **The token expires when the impersonation does.** `imp.exp` and the JWT's
 *    own `exp` are the same value, so an abandoned impersonation cannot outlive
 *    the window just because nobody clicked "stop". A 24h impersonation token is
 *    a 24h key to someone else's account.
 * 2. **A super admin may not be impersonated.** Otherwise revoking someone's
 *    super-admin badge is undone by anyone who still has one, and the audit
 *    trail stops being able to say which admin did what.
 * 3. **`imp` must survive re-minting.** `verify-session` and `switch-context`
 *    both issue a fresh token on every call; dropping the claim there would
 *    convert an impersonation into a permanent, unmarked login as the target,
 *    with no banner and no way back. Both call `te_carry_impersonation()`.
 */

/** How long a single impersonation session lasts, in seconds. */
const TE_IMPERSONATION_TTL = 3600; // 1 hour

/**
 * Build the `imp` claim for a new impersonation session.
 *
 * @param int         $adminId    The super admin doing the impersonating.
 * @param string|null $adminEmail
 * @param string|null $adminName
 * @param int|null    $now        Unix time; injectable for tests.
 * @return array Claims to merge into the JWT payload (includes `exp`).
 */
function te_impersonation_claims($adminId, $adminEmail, $adminName, $now = null)
{
    $now = $now ?? time();
    $expires = $now + TE_IMPERSONATION_TTL;

    return [
        'imp' => [
            'by' => (int) $adminId,
            'by_email' => $adminEmail,
            'by_name' => $adminName,
            'started_at' => $now,
            'exp' => $expires,
        ],
        // Overrides JWT::generate()'s 24h default — array_merge() puts additional
        // claims last, so this wins. See rule 1 in the file docblock.
        'exp' => $expires,
    ];
}

/**
 * Read a valid `imp` claim off a decoded JWT payload.
 *
 * Returns null for a token that is not an impersonation, and also for one whose
 * window has closed — callers treat both the same way, as "this is an ordinary
 * session", which is the safe reading.
 *
 * @param object|array|null $payload Decoded JWT payload.
 * @param int|null          $now
 * @return array|null ['by' => int, 'by_email' => ?string, 'by_name' => ?string,
 *                     'started_at' => int, 'exp' => int]
 */
function te_read_impersonation($payload, $now = null)
{
    $now = $now ?? time();

    $imp = null;
    if (is_object($payload)) {
        $imp = $payload->imp ?? null;
    } elseif (is_array($payload)) {
        $imp = $payload['imp'] ?? null;
    }

    if ($imp === null) {
        return null;
    }

    $imp = (array) $imp;
    $by = isset($imp['by']) ? (int) $imp['by'] : 0;
    $exp = isset($imp['exp']) ? (int) $imp['exp'] : 0;

    // A claim missing either half is malformed, not a session.
    if ($by <= 0 || $exp <= 0) {
        return null;
    }

    if ($exp <= $now) {
        return null;
    }

    return [
        'by' => $by,
        'by_email' => $imp['by_email'] ?? null,
        'by_name' => $imp['by_name'] ?? null,
        'started_at' => isset($imp['started_at']) ? (int) $imp['started_at'] : 0,
        'exp' => $exp,
    ];
}

/**
 * Carry an existing impersonation onto a re-minted token.
 *
 * `verify-session` and `switch-context` rebuild the payload from scratch; this
 * copies the original claim across unchanged — including its expiry, so
 * refreshing the page cannot extend the window.
 *
 * @param array             $claims      Claims for the new token.
 * @param object|array|null $oldPayload  Payload of the token being replaced.
 * @param int|null          $now
 * @return array The claims, with `imp`/`exp` added when one was in flight.
 */
function te_carry_impersonation(array $claims, $oldPayload, $now = null)
{
    $imp = te_read_impersonation($oldPayload, $now);
    if (!$imp) {
        return $claims;
    }

    $claims['imp'] = $imp;
    $claims['exp'] = $imp['exp'];

    return $claims;
}

/**
 * Decide whether $adminId may impersonate $target.
 *
 * @param array|false|null $target  Row from `users` (needs id, system_role), or
 *                                  false/null when no such user exists.
 * @param int              $adminId
 * @return string|null Machine-readable refusal reason, or null to allow.
 */
function te_impersonation_refusal($target, $adminId)
{
    if (!$target || !isset($target['id'])) {
        return 'user_not_found';
    }

    if ((int) $target['id'] === (int) $adminId) {
        return 'cannot_impersonate_self';
    }

    if (($target['system_role'] ?? 'user') === 'super_admin') {
        // Rule 2 in the file docblock.
        return 'cannot_impersonate_super_admin';
    }

    return null;
}

/** Human-facing message for a refusal reason. */
function te_impersonation_refusal_message($reason)
{
    switch ($reason) {
        case 'user_not_found':
            return 'User not found';
        case 'cannot_impersonate_self':
            return 'You are already signed in as this user';
        case 'cannot_impersonate_super_admin':
            return 'Super admins cannot be impersonated';
        default:
            return 'Impersonation is not allowed for this user';
    }
}
