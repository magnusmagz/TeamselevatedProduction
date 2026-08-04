<?php
/**
 * Why a parent-invite link did or didn't work.
 *
 * Extracted from `handleSetParentPassword` in `api/auth-gateway.php` on
 * 2026-08-03 so the decision is unit-testable without standing up the auth
 * gateway — that file is otherwise do-not-modify, so the less logic living
 * inside it the better.
 *
 * THE BUG THIS EXISTS TO FIX
 * The handler looked the token up with `used_at IS NULL AND expires_at > NOW()`
 * folded into the WHERE clause, so a missing row, an already-used row, an expired
 * row and a row invalidated by a re-send were indistinguishable — all four
 * answered "Invalid or expired link".
 *
 * On 2026-08-03 a parent completed setup successfully, re-clicked his link,
 * was told it had expired, and emailed support four minutes after it had worked.
 * His token was valid for another four days; it was simply spent. Telling him
 * "you have already set this up" would have closed the ticket before it opened.
 * The password-reset flow next door has always distinguished these; the invite
 * flow just never got the same treatment.
 */

/** Outcomes of te_classify_parent_invite_token(). */
const TE_INVITE_TOKEN_VALID     = 'valid';
const TE_INVITE_TOKEN_NOT_FOUND = 'not_found';
const TE_INVITE_TOKEN_USED      = 'already_used';
const TE_INVITE_TOKEN_EXPIRED   = 'expired';

/**
 * Classify a parent-invite token row.
 *
 * ORDER MATTERS: used is checked before expired. A spent token whose window has
 * since closed is still, from the parent's point of view, an account they already
 * set up — telling them it expired sends them back to the club for a new invite
 * they do not need. "Already used" is the more actionable of the two truths.
 *
 * @param array|false|null $row  the magic_link_tokens row, or false/null if none
 * @param int|null         $now  unix time; injectable so tests need no clock
 * @return string one of the TE_INVITE_TOKEN_* constants
 */
function te_classify_parent_invite_token($row, ?int $now = null): string
{
    if (!is_array($row) || empty($row)) {
        return TE_INVITE_TOKEN_NOT_FOUND;
    }

    if (!empty($row['used_at'])) {
        return TE_INVITE_TOKEN_USED;
    }

    $expiresAt = $row['expires_at'] ?? null;
    if ($expiresAt === null || $expiresAt === '') {
        // A row with no expiry is malformed rather than valid — refuse it rather
        // than treating "unknown" as "forever".
        return TE_INVITE_TOKEN_EXPIRED;
    }

    $now = $now ?? time();
    $expiryTs = strtotime((string) $expiresAt);
    if ($expiryTs === false) {
        return TE_INVITE_TOKEN_EXPIRED;
    }

    return $expiryTs < $now ? TE_INVITE_TOKEN_EXPIRED : TE_INVITE_TOKEN_VALID;
}

/**
 * The message and machine-readable reason for a non-valid outcome.
 *
 * `reason` is what the UI should branch on; the string is what a parent reads.
 * The "already used" copy deliberately points at signing in rather than at the
 * club — that parent has a working account and needs no help from an admin.
 *
 * @return array{status:int, error:string, reason:string}
 */
function te_parent_invite_token_error(string $classification): array
{
    switch ($classification) {
        case TE_INVITE_TOKEN_USED:
            return [
                'status' => 400,
                'error'  => 'You have already set up this account. Please sign in with the password you chose.',
                'reason' => TE_INVITE_TOKEN_USED,
            ];

        case TE_INVITE_TOKEN_EXPIRED:
            return [
                'status' => 400,
                'error'  => 'This invite link has expired. Ask your club to send a new one.',
                'reason' => TE_INVITE_TOKEN_EXPIRED,
            ];

        default:
            // Unrecognised token. Deliberately vague — this is the one case that
            // could be someone guessing, so it must not confirm what exists.
            return [
                'status' => 400,
                'error'  => 'This invite link is not valid. Ask your club to send a new one.',
                'reason' => TE_INVITE_TOKEN_NOT_FOUND,
            ];
    }
}
