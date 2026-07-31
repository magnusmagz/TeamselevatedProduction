<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../lib/RedisQueue.php';
require_once __DIR__ . '/../lib/suppression.php';
require_once __DIR__ . '/../lib/sms_sender.php';
require_once __DIR__ . '/../lib/inbound_sms.php';

/**
 * SMS Send Service
 *
 * Handles SMS sending via Twilio, including queueing, processing,
 * delivery status tracking, and opt-out management.
 */
class SmsSendService {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Queue SMS messages for one or more recipients.
     *
     * @param array $params {
     *   user_id: int,
     *   club_profile_id: int,
     *   recipients: array of {phone, name, type, id, athlete_id},
     *   body: string,
     *   broadcast_campaign_id: int|null,
     *   team_ids: int[]|null  Teams this send targets, for team-scoped opt-outs
     * }
     * @return array {queued: int, skipped: int, skipped_details: array}
     */
    public function queueSms($params) {
        $userId = $params['user_id'];
        $clubProfileId = $params['club_profile_id'];
        $recipients = $params['recipients'];
        $body = $params['body'];
        $broadcastCampaignId = $params['broadcast_campaign_id'] ?? null;

        $teamIds = $params['team_ids'] ?? [];

        $queued = 0;
        $skipped = 0;
        $skippedDetails = [];

        // Resolve the sending number BEFORE touching Redis or communication_log.
        // No fallback to the shared TWILIO_FROM_NUMBER: carrier STOP blocks the
        // (from-number, recipient) pair, so a shared sender makes one club's STOP
        // silently mute every other club. Refuse loudly instead of queueing
        // messages that would either fail at Twilio or go out as the wrong sender.
        $sender = te_resolve_sms_sender($this->pdo, (int) $clubProfileId);
        if ($sender === null) {
            throw new \RuntimeException(te_sms_sender_missing_message());
        }

        $redis = RedisQueue::getInstance();

        // Load both skip inputs ONCE for the whole batch. These used to be two
        // queries per recipient inside the loop, which a club-wide broadcast turns
        // into 600+ round trips to Neon. te_sms_skip_reason is the same predicate
        // handlePreviewBroadcast uses, so the count shown before sending is the
        // count that actually sends.
        $suppressionMap = te_sms_suppression_map($this->pdo, (int) $clubProfileId);
        $optedOutIds    = te_sms_opted_out_guardian_ids($this->pdo);

        foreach ($recipients as $recipient) {
            $name = $recipient['name'] ?? '';
            $recipientType = $recipient['type'] ?? 'user';
            $recipientId = $recipient['id'] ?? null;
            $athleteId = $recipient['athlete_id'] ?? null;

            $skip = te_sms_skip_reason($recipient, $suppressionMap, $optedOutIds, $teamIds);
            if ($skip !== null) {
                $skipped++;
                $skippedDetails[] = [
                    'name'   => $name,
                    'phone'  => $recipient['phone'] ?? null,
                    'reason' => $skip['reason'],
                    'detail' => $skip['detail'],
                ];
                continue;
            }

            // Guaranteed non-null: te_sms_skip_reason already rejected anything
            // that fails to normalize.
            $phone = te_normalize_sms_phone($recipient['phone'] ?? null);

            // Per-recipient body, set by resolveSmsBodies() in the gateway. Merge
            // tags resolve to different text for each person, so the body cannot be
            // shared across the batch — and communication_log must record what that
            // person actually received, not the template it came from.
            $recipientBody = $recipient['_resolved_body'] ?? $body;

            // 4. Generate a unique tracking ID
            $trackingId = bin2hex(random_bytes(16));

            // 5. INSERT into communication_log
            $logStmt = $this->pdo->prepare("
                INSERT INTO communication_log (
                    club_profile_id, user_id, channel, recipient_type,
                    recipient_id, recipient_phone, recipient_name,
                    athlete_id, body, status, tracking_id,
                    broadcast_campaign_id, from_number, conversation_id, created_at
                ) VALUES (
                    ?, ?, 'sms', ?,
                    ?, ?, ?,
                    ?, ?, 'queued', ?,
                    ?, ?, ?, CURRENT_TIMESTAMP
                )
                RETURNING id
            ");
            $logStmt->execute([
                $clubProfileId,
                $userId,
                $recipientType,
                $recipientId,
                $phone,
                $name,
                $athleteId,
                $recipientBody,
                $trackingId,
                $broadcastCampaignId,
                // The number in force at SEND time, not whatever the club's row says
                // when you read the log later. Without this a 21610 after a number
                // change is undiagnosable.
                $sender['phone_number'] ?? $sender['from'],
                // Thread EVERY outbound message, not just inbox replies. It is what
                // gives a reply its context: an admin opening "I did not receive an
                // email" needs the broadcast that prompted it sitting above.
                te_sms_conversation_id((int) $clubProfileId, $phone),
            ]);
            $communicationLogId = $logStmt->fetchColumn();

            // 6. Push job to Redis sms_queue. The sender rides along so a job
            // queued under one number cannot be sent under another after an admin
            // changes it mid-flight.
            $redis->push('sms_queue', [
                'id' => uniqid('sms_', true),
                'type' => 'send_sms',
                'communication_log_id' => $communicationLogId,
                'phone' => $phone,
                'body' => $recipientBody,
                'tracking_id' => $trackingId,
                'from' => $sender['from'],
                'messaging_service_sid' => $sender['messaging_service_sid'],
                'attempts' => 0,
                'max_attempts' => 3,
                'created_at' => time()
            ]);

            $queued++;
        }

        return [
            'queued' => $queued,
            'skipped' => $skipped,
            'skipped_details' => $skippedDetails
        ];
    }

    /**
     * Process a job from the sms_queue. Called by the worker.
     *
     * @param array $payload Job payload from Redis
     * @throws \Exception On failure (for retry handling)
     */
    public function processJob($payload) {
        $communicationLogId = $payload['communication_log_id'];
        $phone = $payload['phone'];
        $body = $payload['body'];
        $trackingId = $payload['tracking_id'] ?? null;
        $from = $payload['from'] ?? null;
        $messagingServiceSid = $payload['messaging_service_sid'] ?? null;

        // 1. Fetch communication_log record
        $stmt = $this->pdo->prepare("
            SELECT id, status, recipient_type, recipient_id, club_profile_id, from_number
            FROM communication_log
            WHERE id = ?
        ");
        $stmt->execute([$communicationLogId]);
        $logRecord = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$logRecord) {
            throw new \Exception("Communication log record not found: {$communicationLogId}");
        }

        // 2. Update status to 'sending'
        $updateStmt = $this->pdo->prepare("
            UPDATE communication_log
            SET status = 'sending', updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $updateStmt->execute([$communicationLogId]);

        // Jobs queued before per-club numbers shipped have no sender in the payload.
        // Re-resolve from the log's club rather than reaching for the old shared
        // env var — a drained-but-not-empty queue must not become the one path that
        // still sends as everybody.
        if ($from === null && $messagingServiceSid === null) {
            $sender = te_resolve_sms_sender($this->pdo, (int) $logRecord['club_profile_id']);
            if ($sender === null) {
                throw new \Exception(
                    "No SMS sender configured for club {$logRecord['club_profile_id']} "
                    . "(communication_log {$communicationLogId})"
                );
            }
            $from = $sender['from'];
            $messagingServiceSid = $sender['messaging_service_sid'];
        }

        try {
            // 3. Call sendViaTwilio
            $messageSid = $this->sendViaTwilio($phone, $body, $trackingId, $from, $messagingServiceSid);

            // 4. On success: update status='sent', sent_at, twilio_message_sid
            $successStmt = $this->pdo->prepare("
                UPDATE communication_log
                SET status = 'sent',
                    sent_at = CURRENT_TIMESTAMP,
                    twilio_message_sid = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $successStmt->execute([$messageSid, $communicationLogId]);

            // 5. Update recipient's last_contacted
            $recipientType = $logRecord['recipient_type'];
            $recipientId = $logRecord['recipient_id'];

            if ($recipientId) {
                if ($recipientType === 'guardian') {
                    $contactStmt = $this->pdo->prepare("
                        UPDATE guardians
                        SET last_contacted = CURRENT_TIMESTAMP
                        WHERE id = ?
                    ");
                    $contactStmt->execute([$recipientId]);
                } elseif ($recipientType === 'coach' || $recipientType === 'user') {
                    $contactStmt = $this->pdo->prepare("
                        UPDATE users
                        SET last_contacted = CURRENT_TIMESTAMP
                        WHERE id = ?
                    ");
                    $contactStmt->execute([$recipientId]);
                }
            }

        } catch (\Exception $e) {
            // 6. On failure: update status='failed', failure_reason
            $failStmt = $this->pdo->prepare("
                UPDATE communication_log
                SET status = 'failed',
                    failed_at = CURRENT_TIMESTAMP,
                    failure_reason = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $failStmt->execute([$e->getMessage(), $communicationLogId]);

            // Re-throw so the worker can handle retry logic
            throw $e;
        }
    }

    /**
     * Send an SMS via Twilio REST API using cURL.
     *
     * @param string $phone Recipient phone in E.164 format
     * @param string $body Message body
     * @param string|null $trackingId Tracking ID for status callback correlation
     * @return string Twilio Message SID
     * @throws \Exception On API error or non-2xx response
     */
    public function sendViaTwilio($phone, $body, $trackingId = null, $from = null, $messagingServiceSid = null) {
        $accountSid = Env::get('TWILIO_ACCOUNT_SID');
        $authToken = Env::get('TWILIO_AUTH_TOKEN');
        // $from is the club's configured sender, resolved upstream. The env var is
        // only a last resort for callers that predate per-club numbers; queueSms
        // never relies on it and refuses instead.
        $fromNumber = $from ?: Env::get('TWILIO_FROM_NUMBER');
        // BACKEND_URL: the Twilio StatusCallback below is handled by THIS PHP app.
        // APP_URL is the Netlify frontend, whose SPA catch-all swallows unknown
        // paths with a 200, so delivery statuses and STOP opt-outs never arrived.
        $appUrl = Env::get('BACKEND_URL', 'https://teamselevated-backend-0485388bd66e.herokuapp.com');

        if (!$accountSid || !$authToken || (!$fromNumber && !$messagingServiceSid)) {
            throw new \Exception('Twilio credentials are not configured');
        }

        $url = "https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json";

        $postFields = [
            'To' => $phone,
            'Body' => $body,
        ];

        // Twilio takes MessagingServiceSid INSTEAD OF From, never both. The service
        // is what an A2P 10DLC campaign registers against, so it wins when set.
        if ($messagingServiceSid) {
            $postFields['MessagingServiceSid'] = $messagingServiceSid;
        } else {
            $postFields['From'] = $fromNumber;
        }

        if ($appUrl) {
            $postFields['StatusCallback'] = $appUrl . '/api/webhooks/twilio-status';
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postFields),
            CURLOPT_USERPWD => "{$accountSid}:{$authToken}",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded'
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new \Exception("Twilio cURL error: {$curlError}");
        }

        $data = json_decode($response, true);

        if ($httpCode < 200 || $httpCode >= 300) {
            $errorMsg = isset($data['message']) ? $data['message'] : "HTTP {$httpCode}";
            $errorCode = isset($data['code']) ? " (code: {$data['code']})" : '';
            throw new \Exception("Twilio API error: {$errorMsg}{$errorCode}");
        }

        if (isset($data['error_code']) && $data['error_code']) {
            $errorMsg = isset($data['error_message']) ? $data['error_message'] : 'Unknown error';
            throw new \Exception("Twilio error {$data['error_code']}: {$errorMsg}");
        }

        if (!isset($data['sid'])) {
            throw new \Exception('Twilio response missing message SID');
        }

        return $data['sid'];
    }

    /**
     * Normalize a phone number to E.164 format.
     *
     * @param string $phone Raw phone number
     * @return string Normalized E.164 phone number
     * @throws \Exception If phone number is invalid
     */
    public function normalizePhone($phone) {
        // The rules live in te_normalize_sms_phone (lib/suppression.php) so the
        // sender and the suppression lookup can never disagree about what a phone
        // number "is" — a mismatch there silently changes who gets a message.
        // This wrapper keeps the throwing contract its existing callers rely on.
        $normalized = te_normalize_sms_phone($phone);

        if ($normalized !== null) {
            return $normalized;
        }

        if ($phone === null || trim((string) $phone) === '') {
            throw new \Exception('Phone number is empty');
        }

        $digits = preg_replace('/[^\d]/', '', (string) $phone);
        if ($digits === '') {
            throw new \Exception('Phone number contains no digits');
        }

        if (substr(trim((string) $phone), 0, 1) === '+') {
            throw new \Exception("Invalid international phone number: +{$digits}");
        }

        throw new \Exception("Unable to normalize phone number: {$phone} ({$digits})");
    }

    /**
     * Handle a Twilio status callback webhook.
     *
     * @param array $data POST data from Twilio
     * @return bool True if processed successfully
     */
    public function handleStatusCallback($data) {
        $messageSid = $data['MessageSid'] ?? null;
        $messageStatus = $data['MessageStatus'] ?? null;
        $to = $data['To'] ?? null;
        $errorCode = $data['ErrorCode'] ?? null;
        $errorMessage = $data['ErrorMessage'] ?? null;

        if (!$messageSid || !$messageStatus) {
            return false;
        }

        // Look up communication_log by twilio_message_sid
        $stmt = $this->pdo->prepare("
            SELECT id, status, recipient_phone, recipient_type, recipient_id, club_profile_id
            FROM communication_log
            WHERE twilio_message_sid = ?
        ");
        $stmt->execute([$messageSid]);
        $logRecord = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$logRecord) {
            // Unknown message SID — nothing to update
            return false;
        }

        $logId = $logRecord['id'];
        $currentStatus = $logRecord['status'];

        // Map Twilio statuses to our statuses
        switch ($messageStatus) {
            case 'delivered':
                $updateStmt = $this->pdo->prepare("
                    UPDATE communication_log
                    SET status = 'delivered',
                        delivered_at = CURRENT_TIMESTAMP,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $updateStmt->execute([$logId]);
                break;

            case 'sent':
                // Only update to 'sent' if not already delivered
                if ($currentStatus !== 'delivered') {
                    $updateStmt = $this->pdo->prepare("
                        UPDATE communication_log
                        SET status = 'sent',
                            sent_at = COALESCE(sent_at, CURRENT_TIMESTAMP),
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                    ");
                    $updateStmt->execute([$logId]);
                }
                break;

            case 'failed':
            case 'undelivered':
                $failureReason = $errorMessage ?: "Status: {$messageStatus}";
                if ($errorCode) {
                    $failureReason = "Error {$errorCode}: {$failureReason}";
                }

                $updateStmt = $this->pdo->prepare("
                    UPDATE communication_log
                    SET status = 'failed',
                        failed_at = CURRENT_TIMESTAMP,
                        failure_reason = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $updateStmt->execute([$failureReason, $logId]);
                break;
        }

        // Handle STOP opt-out (ErrorCode 21610)
        if ($errorCode == '21610') {
            $phone = $to ?: $logRecord['recipient_phone'];
            $clubProfileId = $logRecord['club_profile_id'];

            if ($phone) {
                // Set guardians.sms_opt_out = TRUE for matching phone
                $optOutStmt = $this->pdo->prepare("
                    UPDATE guardians
                    SET sms_opt_out = TRUE,
                        sms_opt_out_at = CURRENT_TIMESTAMP
                    WHERE mobile_phone = ?
                ");
                $optOutStmt->execute([$phone]);

                // Also check without country code for matching
                $phoneWithout1 = $phone;
                if (strlen($phone) === 12 && substr($phone, 0, 2) === '+1') {
                    $phoneWithout1 = substr($phone, 2);
                }
                if ($phoneWithout1 !== $phone) {
                    $optOutStmt->execute([$phoneWithout1]);
                }

                // Add to email_suppressions (channel='sms', reason='twilio_stop')
                $suppressStmt = $this->pdo->prepare("
                    INSERT INTO email_suppressions (
                        club_profile_id, phone, channel, reason,
                        scope, communication_log_id, created_at
                    ) VALUES (
                        ?, ?, 'sms', 'twilio_stop',
                        'club', ?, CURRENT_TIMESTAMP
                    )
                    ON CONFLICT (club_profile_id, email, channel, scope, COALESCE(team_id, 0))
                    DO NOTHING
                ");
                $suppressStmt->execute([$clubProfileId, $phone, $logId]);
            }
        }

        return true;
    }
}
