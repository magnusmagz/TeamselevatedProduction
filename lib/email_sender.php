<?php
/**
 * Who every outbound email comes FROM.
 *
 * One address, the club's name. A parent should recognise "Central Kansas United"
 * in their inbox — not "Teams Elevated", and not the name of whichever staff
 * member happened to click send.
 *
 * BEFORE THIS FILE (2026-08-04) there were three senders across three paths, two
 * of them on hardcoded fallbacks nobody had chosen:
 *
 *   lib/Email.php              maggie@eyeinteams.com      "Teams Elevated"
 *   services/EmailSendService  notifications@teamselevated.com  <staff member's name>
 *   services/CalendarInvite    maggie@eyeinteams.com      "Maggie - Teams Elevated"
 *
 * So a family registering, being invited, and receiving a club broadcast saw mail
 * from two domains under three different names.
 *
 * ⚠️ The address must stay on a domain authenticated in SendGrid. Moving it is not
 * a cosmetic change: an unauthenticated From lands transactional mail — password
 * resets, invites — in spam. `teamselevated.com` is the authenticated one; that is
 * why this consolidates onto it rather than onto eyeinteams.com.
 */

require_once __DIR__ . '/EmailBranding.php';

/**
 * The single From address. Env-overridable so it can be moved without a deploy,
 * but the default is the real value rather than a placeholder — two of the three
 * paths were already relying on their defaults, and a default that is wrong is
 * worse than no default.
 */
function te_email_from_address(): string
{
    $configured = getenv('EMAIL_FROM') ?: getenv('SENDGRID_FROM_EMAIL') ?: '';
    $configured = trim((string) $configured);

    return $configured !== '' ? $configured : 'notifications@teamselevated.com';
}

/**
 * The From *name*: the club's own name, so the inbox line is familiar.
 *
 * Falls back to the platform name when there is genuinely no club to attribute
 * the mail to — password reset and magic-link sign-in from the login page, where
 * the person may belong to several clubs or none. Guessing one would be worse
 * than being generic.
 *
 * EmailBranding::forClub() answers 'Your Club' for an unknown id, which reads
 * fine as a page heading and terribly as a sender. That value is treated as
 * "unknown" here and never reaches an inbox.
 *
 * @param PDO|null $pdo    null when the caller has no database handle
 * @param int|null $clubId null when the message has no club context
 */
function te_email_from_name($pdo = null, ?int $clubId = null): string
{
    $fallback = 'Teams Elevated';

    if (!$pdo || !$clubId) {
        return $fallback;
    }

    try {
        $brand = EmailBranding::forClub($pdo, $clubId);
    } catch (Throwable $e) {
        error_log('te_email_from_name: branding lookup failed: ' . $e->getMessage());
        return $fallback;
    }

    $name = trim((string) ($brand['name'] ?? ''));

    // 'Your Club' is EmailBranding's own not-found placeholder, not a real club.
    if ($name === '' || $name === 'Your Club') {
        return $fallback;
    }

    return $name;
}

/**
 * Convenience for the SendGrid JSON payload shape used by two of the three paths.
 *
 * @return array{email:string,name:string}
 */
function te_email_from($pdo = null, ?int $clubId = null): array
{
    return [
        'email' => te_email_from_address(),
        'name'  => te_email_from_name($pdo, $clubId),
    ];
}
