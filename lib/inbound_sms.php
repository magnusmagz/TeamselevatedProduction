<?php
require_once __DIR__ . '/suppression.php';

/**
 * Recording an inbound SMS.
 *
 * Extracted from the webhook so the routing rules are unit-testable without a
 * request — the webhook itself reads $_POST and echoes TwiML, which is not
 * reachable in-process.
 *
 * Threading is club-keyed, not sender-keyed. See migration 064.
 */

if (!function_exists('te_sms_conversation_id')) {
    /**
     * Stable thread key for a club and a contact.
     *
     * Normalizing the phone first is what makes it stable: the same person texting
     * from a number Twilio reports as +17855550100 must land in the same thread as
     * the outbound messages we addressed to "(785) 555-0100".
     */
    function te_sms_conversation_id(int $clubProfileId, ?string $phone): ?string
    {
        $normalized = te_normalize_sms_phone($phone);
        if ($normalized === null) {
            return null;
        }
        return substr(hash('sha256', $clubProfileId . '|' . $normalized), 0, 32);
    }
}

if (!function_exists('te_resolve_inbound_club')) {
    /**
     * Which club owns the number this text was sent TO.
     *
     * Exact, and only possible since per-club numbers shipped (migration 057).
     * With one shared platform number there was no way to know which club a reply
     * belonged to — that, not effort, is what blocked an inbox before now.
     *
     * Deliberately ignores `is_active`: a reply to a number a club has since
     * replaced still belongs to that club, and dropping it would lose a real
     * message because of an admin action taken afterwards.
     */
    function te_resolve_inbound_club(PDO $pdo, ?string $toNumber): ?int
    {
        $normalized = te_normalize_sms_phone($toNumber);
        if ($normalized === null) {
            return null;
        }

        $stmt = $pdo->prepare("
            SELECT club_profile_id
            FROM sms_phone_numbers
            WHERE phone_number IS NOT NULL
              AND right(regexp_replace(phone_number, '[^0-9]', '', 'g'), 10)
                = right(regexp_replace(?, '[^0-9]', '', 'g'), 10)
            ORDER BY is_active DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute([$normalized]);
        $club = $stmt->fetchColumn();

        return $club === false ? null : (int) $club;
    }
}

if (!function_exists('te_resolve_inbound_sender')) {
    /**
     * Who sent it, within that club.
     *
     * Checked in order guardian → athlete → coach, because crew are who actually
     * text a club. Matching is on DIGITS, since stored numbers are hand-entered in
     * every format and Twilio always reports E.164.
     *
     * Three outcomes, and the third is the one people get wrong:
     *   - one match     → attribute it
     *   - no match      → ['type' => 'user', 'id' => null], still recorded. A reply
     *                     from a number we do not recognise is still a person
     *                     saying something; dropping it is the one unacceptable
     *                     outcome.
     *   - several       → the first, plus ambiguous=true. A shared household mobile
     *                     matches two guardians; guessing silently would attribute
     *                     one parent's words to the other.
     *
     * @return array{type: string, id: ?int, name: ?string, athlete_id: ?int, ambiguous: bool}
     */
    function te_resolve_inbound_sender(PDO $pdo, int $clubProfileId, ?string $fromNumber): array
    {
        $unknown = ['type' => 'user', 'id' => null, 'name' => null, 'athlete_id' => null, 'ambiguous' => false];

        $normalized = te_normalize_sms_phone($fromNumber);
        if ($normalized === null) {
            return $unknown;
        }

        // Compare the LAST 10 DIGITS, not the whole string. Twilio reports E.164
        // ("+17855550100" -> 11 digits with the country code) while stored numbers
        // are hand-entered and usually 10 ("(785) 555-0100"). A whole-string digit
        // comparison never matches, so every sender resolves as unknown — which is
        // exactly what happened until a test caught it.
        $digits = substr(preg_replace('/[^0-9]/', '', $normalized), -10);

        // Guardians of a non-deleted athlete in this club.
        $stmt = $pdo->prepare("
            SELECT DISTINCT g.id, g.first_name, g.last_name,
                   (SELECT ag2.athlete_id FROM athlete_guardians ag2
                     WHERE ag2.guardian_id = g.id ORDER BY ag2.athlete_id LIMIT 1) AS athlete_id
            FROM guardians g
            JOIN athlete_guardians ag ON ag.guardian_id = g.id
            JOIN athletes a ON a.id = ag.athlete_id
            WHERE a.club_id = ? AND a.deleted_at IS NULL
              AND right(regexp_replace(COALESCE(g.mobile_phone,''), '[^0-9]', '', 'g'), 10) = ?
            ORDER BY g.id
        ");
        $stmt->execute([$clubProfileId, $digits]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            return [
                'type' => 'guardian',
                'id' => (int) $rows[0]['id'],
                'name' => trim($rows[0]['first_name'] . ' ' . $rows[0]['last_name']),
                'athlete_id' => $rows[0]['athlete_id'] !== null ? (int) $rows[0]['athlete_id'] : null,
                'ambiguous' => count($rows) > 1,
            ];
        }

        $stmt = $pdo->prepare("
            SELECT id, first_name, last_name FROM athletes
            WHERE club_id = ? AND deleted_at IS NULL
              AND right(regexp_replace(COALESCE(phone,''), '[^0-9]', '', 'g'), 10) = ?
            ORDER BY id
        ");
        $stmt->execute([$clubProfileId, $digits]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            return [
                'type' => 'athlete',
                'id' => (int) $rows[0]['id'],
                'name' => trim($rows[0]['first_name'] . ' ' . $rows[0]['last_name']),
                'athlete_id' => (int) $rows[0]['id'],
                'ambiguous' => count($rows) > 1,
            ];
        }

        // user_club_access is authoritative for club roles, not users.role.
        $stmt = $pdo->prepare("
            SELECT DISTINCT u.id, u.first_name, u.last_name
            FROM users u
            JOIN user_club_access uca ON uca.user_id = u.id
            WHERE uca.club_profile_id = ? AND uca.active = true
              AND right(regexp_replace(COALESCE(u.phone,''), '[^0-9]', '', 'g'), 10) = ?
            ORDER BY u.id
        ");
        $stmt->execute([$clubProfileId, $digits]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            return [
                'type' => 'coach',
                'id' => (int) $rows[0]['id'],
                'name' => trim($rows[0]['first_name'] . ' ' . $rows[0]['last_name']),
                'athlete_id' => null,
                'ambiguous' => count($rows) > 1,
            ];
        }

        return $unknown;
    }
}

/**
 * Twilio's carrier keywords, split by what they MEAN.
 *
 * The webhook previously treated all of these as one bucket — "say nothing" — which
 * was right for the auto-reply and useless for anything else. STOP and START are
 * opposites, and HELP is neither.
 */
if (!defined('TE_SMS_OPT_OUT_KEYWORDS')) {
    define('TE_SMS_OPT_OUT_KEYWORDS', ['stop', 'stopall', 'unsubscribe', 'cancel', 'end', 'quit']);
    define('TE_SMS_OPT_IN_KEYWORDS',  ['start', 'yes', 'unstop']);
    define('TE_SMS_HELP_KEYWORDS',    ['help', 'info']);
}

if (!function_exists('te_sms_keyword_intent')) {
    /**
     * @return 'opt_out'|'opt_in'|'help'|null  null = an ordinary message.
     *
     * Only the BARE keyword counts, after stripping trailing punctuation. "Can we
     * stop by the field at 6?" is a question about a field, not an opt-out, and
     * treating it as one would silently mute a family.
     */
    function te_sms_keyword_intent(?string $body): ?string
    {
        $kw = strtolower(trim((string) $body, " \t\n\r\0\x0B.!?,"));

        if (in_array($kw, TE_SMS_OPT_OUT_KEYWORDS, true)) return 'opt_out';
        if (in_array($kw, TE_SMS_OPT_IN_KEYWORDS, true))  return 'opt_in';
        if (in_array($kw, TE_SMS_HELP_KEYWORDS, true))    return 'help';

        return null;
    }
}

if (!function_exists('te_apply_sms_optout')) {
    /**
     * Record a STOP at the moment it arrives.
     *
     * Until now the only sync was REACTIVE — SmsSendService::handleStatusCallback
     * on Twilio error 21610, i.e. after a later send had already failed. Verified
     * on 2026-07-30: a guardian texted Stop then Start, Twilio blocked at the
     * carrier, and both email_suppressions and guardians.sms_opt_out stayed empty.
     * Between the STOP and the next send, preview counts overstated and the
     * eventual failure read as "failed" rather than "opted out".
     *
     * Writes BOTH:
     *  - `email_suppressions` — club-scoped, which is now meaningful because each
     *    club sends from its own number, so a STOP to one club's number is a STOP
     *    to that club.
     *  - `guardians.sms_opt_out` — person-level, matching what
     *    handleStatusCallback already does.
     *
     * ⚠️ Known limitation: sms_opt_out is a single boolean on the person and
     * cannot express "this club only", so a family in two clubs who stops one is
     * currently stopped for both. Erring toward respecting the opt-out is the
     * right side to be wrong on, but it is a real over-block. Fixing it means
     * moving person-level opt-out onto a club-scoped row; not worth doing until a
     * family is actually in two clubs.
     */
    function te_apply_sms_optout(PDO $pdo, int $clubProfileId, ?string $phone, array $sender, ?int $logId = null): void
    {
        $normalized = te_normalize_sms_phone($phone);
        if ($normalized === null) {
            return;
        }

        $stmt = $pdo->prepare("
            INSERT INTO email_suppressions
                (club_profile_id, phone, channel, reason, scope, communication_log_id, created_at)
            VALUES (?, ?, 'sms', 'twilio_stop', 'club', ?, CURRENT_TIMESTAMP)
            ON CONFLICT (club_profile_id, phone, scope, COALESCE(team_id, 0))
            WHERE channel = 'sms' AND phone IS NOT NULL
            DO NOTHING
        ");
        $stmt->execute([$clubProfileId, $normalized, $logId]);

        if (($sender['type'] ?? null) === 'guardian' && !empty($sender['id'])) {
            $pdo->prepare("
                UPDATE guardians
                SET sms_opt_out = TRUE, sms_opt_out_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ")->execute([$sender['id']]);
        }
    }
}

if (!function_exists('te_apply_sms_optin')) {
    /**
     * START / UNSTOP — the person is asking to hear from us again.
     *
     * Clears exactly what the opt-out set. Only `twilio_stop` suppressions are
     * removed: a hard bounce or a manual admin suppression is not something a
     * parent can undo by texting START, and deleting those would silently
     * resurrect an address the club deliberately stopped using.
     */
    function te_apply_sms_optin(PDO $pdo, int $clubProfileId, ?string $phone, array $sender): void
    {
        $normalized = te_normalize_sms_phone($phone);
        if ($normalized === null) {
            return;
        }

        $pdo->prepare("
            DELETE FROM email_suppressions
            WHERE club_profile_id = ? AND channel = 'sms'
              AND reason = 'twilio_stop'
              AND right(regexp_replace(COALESCE(phone,''), '[^0-9]', '', 'g'), 10)
                = right(regexp_replace(?, '[^0-9]', '', 'g'), 10)
        ")->execute([$clubProfileId, $normalized]);

        if (($sender['type'] ?? null) === 'guardian' && !empty($sender['id'])) {
            $pdo->prepare("
                UPDATE guardians
                SET sms_opt_out = FALSE, sms_opt_out_at = NULL
                WHERE id = ?
            ")->execute([$sender['id']]);
        }
    }
}

if (!function_exists('te_record_auto_reply')) {
    /**
     * Record the auto-reply we just sent back.
     *
     * The auto-reply leaves as TwiML in the webhook response, so Twilio sends it
     * and nothing here would otherwise know it happened. An admin opening the
     * thread later would see a family's question and no answer, and write a reply
     * that contradicts what the family already received.
     *
     * Machine-vs-human is `user_id`: an auto-reply is outbound with user_id NULL,
     * a human reply (M4) carries the admin who wrote it. That distinction is also
     * what keeps a thread in "needs reply" after a robot has answered it — a
     * robot answer is not an answer.
     */
    function te_record_auto_reply(PDO $pdo, int $clubProfileId, array $sender, string $toPhone, string $fromNumber, string $body): void
    {
        $stmt = $pdo->prepare("
            INSERT INTO communication_log (
                club_profile_id, user_id, channel, direction, recipient_type,
                recipient_id, recipient_phone, recipient_name, athlete_id,
                body, status, from_number, conversation_id,
                sent_at, delivered_at, created_at
            ) VALUES (
                ?, NULL, 'sms', 'outbound', ?,
                ?, ?, ?, ?,
                ?, 'sent', ?, ?,
                CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
        ");
        $stmt->execute([
            $clubProfileId,
            $sender['type'],
            $sender['id'],
            te_normalize_sms_phone($toPhone) ?? $toPhone,
            $sender['name'],
            $sender['athlete_id'],
            $body,
            $fromNumber,
            te_sms_conversation_id($clubProfileId, $toPhone),
        ]);
    }
}

if (!function_exists('te_record_inbound_sms')) {
    /**
     * Write the reply to communication_log.
     *
     * status is 'delivered' because the CHECK constraint offers no 'received' and
     * the message did arrive. Every analytics query filters
     * `direction = 'outbound'` (see buildCoachScope) precisely so these rows do not
     * count as messages the club sent.
     *
     * @return int|null communication_log id, or null if it could not be attributed
     *                  to a club (nothing is written in that case).
     */
    function te_record_inbound_sms(PDO $pdo, array $payload): ?int
    {
        $from = $payload['From'] ?? null;
        $to   = $payload['To'] ?? null;
        $body = (string) ($payload['Body'] ?? '');
        $sid  = $payload['MessageSid'] ?? null;

        $clubProfileId = te_resolve_inbound_club($pdo, $to);
        if ($clubProfileId === null) {
            // No club owns this number. Nothing sensible to attach it to, and
            // communication_log.club_profile_id is NOT NULL.
            error_log('[twilio-inbound] no club owns ' . (string) $to . ' — reply not recorded');
            return null;
        }

        $sender = te_resolve_inbound_sender($pdo, $clubProfileId, $from);
        $normalizedFrom = te_normalize_sms_phone($from) ?? (string) $from;

        $stmt = $pdo->prepare("
            INSERT INTO communication_log (
                club_profile_id, user_id, channel, direction, recipient_type,
                recipient_id, recipient_phone, recipient_name, athlete_id,
                body, status, from_number, conversation_id, twilio_message_sid,
                sent_at, delivered_at, created_at
            ) VALUES (
                ?, ?, 'sms', 'inbound', ?,
                ?, ?, ?, ?,
                ?, 'delivered', ?, ?, ?,
                CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
            RETURNING id
        ");
        $stmt->execute([
            $clubProfileId,
            // user_id means "the staff member who SENT this". An inbound reply has
            // none, so it is NULL — migration 064 drops the NOT NULL for exactly
            // this. Writing 0 violates the FK to users; naming a club admin would
            // credit them with words they did not write.
            null,
            $sender['type'],
            $sender['id'],
            $normalizedFrom,
            $sender['name'],
            $sender['athlete_id'],
            $body,
            te_normalize_sms_phone($to) ?? (string) $to,
            te_sms_conversation_id($clubProfileId, $from),
            $sid,
        ]);

        $logId = (int) $stmt->fetchColumn();

        // Act on a carrier keyword the moment it arrives, not after a later send
        // fails against it. Deliberately AFTER the insert so the opt-out row can
        // point at the message that caused it — that link is what lets someone
        // later answer "why is this family suppressed?".
        $intent = te_sms_keyword_intent($body);
        if ($intent === 'opt_out') {
            te_apply_sms_optout($pdo, $clubProfileId, $from, $sender, $logId);
        } elseif ($intent === 'opt_in') {
            te_apply_sms_optin($pdo, $clubProfileId, $from, $sender);
        }

        return $logId;
    }
}
