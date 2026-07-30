<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/suppression.php';

/**
 * Which number a club's SMS goes out from.
 *
 * One resolver, used by the send path and by the settings UI, so "what will this
 * club send as" has exactly one answer.
 *
 * There is deliberately NO fallback to the platform TWILIO_FROM_NUMBER. Carrier
 * STOP handling blocks the (from-number, recipient) pair, so every club sharing one
 * number means a parent who replies STOP to one club is silently unreachable by all
 * of them — at the carrier, whatever our club-scoped suppression rows say. A
 * fallback would recreate that quietly for any club that never got configured.
 * Refusing is loud, and loud is recoverable.
 *
 * TWILIO_FROM_NUMBER is now used only by paths that are not club-scoped (if any);
 * queueSms will not send without a configured club sender.
 */

if (!function_exists('te_resolve_sms_sender')) {
    /**
     * @param int      $clubProfileId
     * @param int|null $userId  Reserved for per-coach numbers (unified-messaging
     *                          scope Phase 1). When set, that coach's own number
     *                          wins over the club's. No rows have user_id today.
     * @return array{from: ?string, messaging_service_sid: ?string, phone_number: ?string}|null
     *         Null when the club has no active sender — callers must refuse to send.
     */
    function te_resolve_sms_sender(PDO $pdo, int $clubProfileId, ?int $userId = null): ?array
    {
        // ORDER BY puts a coach-specific row ahead of the club row, so one query
        // covers both phases. `user_id IS NULL` sorts last under DESC NULLS LAST.
        $sql = "
            SELECT phone_number, messaging_service_sid, twilio_phone_sid, user_id
            FROM sms_phone_numbers
            WHERE club_profile_id = ?
              AND is_active
              AND (user_id IS NULL" . ($userId !== null ? " OR user_id = ?" : "") . ")
            ORDER BY user_id DESC NULLS LAST
            LIMIT 1
        ";

        $params = [$clubProfileId];
        if ($userId !== null) {
            $params[] = $userId;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $messagingServiceSid = $row['messaging_service_sid'] ?: null;
        $phoneNumber         = $row['phone_number'] ?: null;

        if ($messagingServiceSid === null && $phoneNumber === null) {
            return null;
        }

        return [
            // A Messaging Service wins when present: A2P 10DLC campaigns attach to
            // the service, not to a bare long code, and Twilio accepts
            // MessagingServiceSid in place of From.
            'from'                  => $messagingServiceSid ?: $phoneNumber,
            'messaging_service_sid' => $messagingServiceSid,
            'phone_number'          => $phoneNumber,
        ];
    }
}

if (!function_exists('te_verify_twilio_number')) {
    /**
     * Confirm the Twilio account actually owns this number, and that it can send SMS.
     *
     * Numbers are pasted in by an admin, not purchased through the app, so this is
     * the only thing standing between a typo and a club whose texts silently fail.
     * Twilio rejects an unowned From at send time with error 21606 — hours later, in
     * a worker log nobody is watching. Checking at save time turns that into a form
     * error the person who caused it is still looking at.
     *
     * @return array{ok: bool, sid: ?string, error: ?string}
     */
    function te_verify_twilio_number(string $e164Number): array
    {
        $accountSid = Env::get('TWILIO_ACCOUNT_SID');
        $authToken  = Env::get('TWILIO_AUTH_TOKEN');

        if (!$accountSid || !$authToken) {
            return [
                'ok'    => false,
                'sid'   => null,
                'error' => 'Twilio is not configured on this environment, so the number cannot be verified.',
            ];
        }

        $url = "https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/IncomingPhoneNumbers.json"
             . '?PhoneNumber=' . urlencode($e164Number);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_USERPWD        => "{$accountSid}:{$authToken}",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['ok' => false, 'sid' => null, 'error' => "Could not reach Twilio: {$curlError}"];
        }

        $data = json_decode($response, true);

        if ($httpCode < 200 || $httpCode >= 300) {
            $msg = $data['message'] ?? "HTTP {$httpCode}";
            return ['ok' => false, 'sid' => null, 'error' => "Twilio rejected the lookup: {$msg}"];
        }

        $matches = $data['incoming_phone_numbers'] ?? [];
        if (empty($matches)) {
            return [
                'ok'    => false,
                'sid'   => null,
                'error' => "{$e164Number} is not on this Twilio account. "
                         . 'Buy or transfer it in the Twilio console first, then save it here.',
            ];
        }

        $match = $matches[0];

        // A number can be voice-only. Sending SMS from one fails per-message at
        // Twilio, which looks exactly like a delivery problem rather than a
        // configuration one.
        $smsCapable = $match['capabilities']['sms'] ?? null;
        if ($smsCapable === false) {
            return [
                'ok'    => false,
                'sid'   => null,
                'error' => "{$e164Number} is on this Twilio account but is not SMS-capable.",
            ];
        }

        return ['ok' => true, 'sid' => $match['sid'] ?? null, 'error' => null];
    }
}

if (!function_exists('te_sms_sender_missing_message')) {
    /**
     * The one place this wording lives, so the API, the compose screen and the
     * queue all say the same thing.
     */
    function te_sms_sender_missing_message(): string
    {
        return 'This club has no SMS number configured. '
             . 'A club admin can set one in Club Profile → Messaging.';
    }
}
