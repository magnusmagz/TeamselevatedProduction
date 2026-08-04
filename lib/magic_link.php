<?php
/**
 * Minting a magic sign-in link.
 *
 * Two callers, deliberately different TTLs:
 *
 *  - `auth-gateway.php?action=send-magic-link` — the LOGIN PAGE's "email me a
 *    link". Self-service, unauthenticated (it only ever reaches the account
 *    owner), and short-lived because the person is sitting at their inbox.
 *  - `api/portal-access.php?action=send-login-link` — a CLUB ADMIN sending a
 *    link to a family. Asynchronous by nature: the admin clicks now, the parent
 *    reads tonight. A 15-minute link would be dead on arrival and would recreate
 *    exactly the "expired" confusion the 2026-08-03 invite fix removed.
 *
 * The token row shape is identical either way, so `verify-magic-link` needs no
 * knowledge of which path minted it. That is the whole reason this lives in one
 * file: the codebase has repeatedly been bitten by a second implementation of
 * something (phone normalisation, suppression, coach scoping) drifting from the
 * first.
 */

/** Login page: the user is at their inbox right now. */
const TE_MAGIC_LINK_TTL_SELF_SERVICE = 15 * 60;

/**
 * Admin-initiated: the parent reads it whenever they read email. 24h is the
 * point of the feature — at 15 minutes an admin-sent link is usually expired
 * before it is opened, which is worse than not offering the button.
 */
const TE_MAGIC_LINK_TTL_ADMIN_SENT = 24 * 60 * 60;

/**
 * Create a magic-link token row and return the token.
 *
 * Does NOT send the email and does NOT check whether the account exists — both
 * are the caller's business, because the two callers differ on exactly that:
 * the login page must not reveal whether an address is registered, while an
 * admin acting on a named guardian is entitled to a real answer.
 *
 * @param string $email  the account's address, already lowercased/trimmed
 * @param int    $ttlSeconds one of the TE_MAGIC_LINK_TTL_* constants
 * @return string the token to put in the link
 */
function te_mint_magic_link_token(PDO $db, string $email, int $ttlSeconds): string
{
    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + $ttlSeconds);

    $stmt = $db->prepare(
        'INSERT INTO magic_link_tokens (email, token, expires_at, created_at)
         VALUES (?, ?, ?, CURRENT_TIMESTAMP)'
    );
    $stmt->execute([$email, $token, $expiresAt]);

    return $token;
}

/**
 * The URL a magic-link token belongs in.
 *
 * `verify-magic-link` is the only page that consumes these, so the route lives
 * here rather than being spelled out at each call site.
 */
function te_magic_link_url(string $token): string
{
    $appUrl = rtrim(Env::get('APP_URL', 'http://localhost:3003'), '/');
    return $appUrl . '/verify-magic-link?token=' . urlencode($token);
}

/**
 * Human phrase for a TTL, so the UI and the email cannot disagree with the
 * token they describe. The 2026-08-03 invite ticket happened because a parent
 * was told one thing and the system did another.
 */
function te_magic_link_ttl_phrase(int $ttlSeconds): string
{
    if ($ttlSeconds >= 3600) {
        $hours = (int) round($ttlSeconds / 3600);
        return $hours === 1 ? '1 hour' : "$hours hours";
    }
    $minutes = (int) round($ttlSeconds / 60);
    return $minutes === 1 ? '1 minute' : "$minutes minutes";
}
