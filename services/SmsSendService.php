<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../lib/RedisQueue.php';

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
     *   broadcast_campaign_id: int|null
     * }
     * @return array {queued: int, skipped: int, skipped_details: array}
     */
    public function queueSms($params) {
        $userId = $params['user_id'];
        $clubProfileId = $params['club_profile_id'];
        $recipients = $params['recipients'];
        $body = $params['body'];
        $broadcastCampaignId = $params['broadcast_campaign_id'] ?? null;

        $queued = 0;
        $skipped = 0;
        $skippedDetails = [];

        $redis = RedisQueue::getInstance();

        foreach ($recipients as $recipient) {
            $phone = $recipient['phone'] ?? null;
            $name = $recipient['name'] ?? '';
            $recipientType = $recipient['type'] ?? 'user';
            $recipientId = $recipient['id'] ?? null;
            $athleteId = $recipient['athlete_id'] ?? null;

            // 1. Validate and normalize phone number
            try {
                $phone = $this->normalizePhone($phone);
            } catch (\Exception $e) {
                $skipped++;
                $skippedDetails[] = [
                    'name' => $name,
                    'phone' => $recipient['phone'] ?? null,
                    'reason' => 'invalid_phone',
                    'detail' => $e->getMessage()
                ];
                continue;
            }

            // 2. Check email_suppressions for phone + channel='sms' + club
            $suppressionStmt = $this->pdo->prepare("
                SELECT id, reason FROM email_suppressions
                WHERE club_profile_id = ?
                    AND phone = ?
                    AND channel = 'sms'
                LIMIT 1
            ");
            $suppressionStmt->execute([$clubProfileId, $phone]);
            $suppression = $suppressionStmt->fetch(\PDO::FETCH_ASSOC);

            if ($suppression) {
                $skipped++;
                $skippedDetails[] = [
                    'name' => $name,
                    'phone' => $phone,
                    'reason' => 'suppressed',
                    'detail' => 'SMS suppression: ' . $suppression['reason']
                ];
                continue;
            }

            // 3. Check guardians.sms_opt_out if recipient_type is 'guardian'
            if ($recipientType === 'guardian' && $recipientId) {
                $optOutStmt = $this->pdo->prepare("
                    SELECT sms_opt_out FROM guardians
                    WHERE id = ?
                ");
                $optOutStmt->execute([$recipientId]);
                $guardian = $optOutStmt->fetch(\PDO::FETCH_ASSOC);

                if ($guardian && $guardian['sms_opt_out']) {
                    $skipped++;
                    $skippedDetails[] = [
                        'name' => $name,
                        'phone' => $phone,
                        'reason' => 'opted_out',
                        'detail' => 'Guardian has opted out of SMS'
                    ];
                    continue;
                }
            }

            // 4. Generate a unique tracking ID
            $trackingId = bin2hex(random_bytes(16));

            // 5. INSERT into communication_log
            $logStmt = $this->pdo->prepare("
                INSERT INTO communication_log (
                    club_profile_id, user_id, channel, recipient_type,
                    recipient_id, recipient_phone, recipient_name,
                    athlete_id, body, status, tracking_id,
                    broadcast_campaign_id, created_at
                ) VALUES (
                    ?, ?, 'sms', ?,
                    ?, ?, ?,
                    ?, ?, 'queued', ?,
                    ?, CURRENT_TIMESTAMP
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
                $body,
                $trackingId,
                $broadcastCampaignId
            ]);
            $communicationLogId = $logStmt->fetchColumn();

            // 6. Push job to Redis sms_queue
            $redis->push('sms_queue', [
                'id' => uniqid('sms_', true),
                'type' => 'send_sms',
                'communication_log_id' => $communicationLogId,
                'phone' => $phone,
                'body' => $body,
                'tracking_id' => $trackingId,
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

        // 1. Fetch communication_log record
        $stmt = $this->pdo->prepare("
            SELECT id, status, recipient_type, recipient_id
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

        try {
            // 3. Call sendViaTwilio
            $messageSid = $this->sendViaTwilio($phone, $body, $trackingId);

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
    public function sendViaTwilio($phone, $body, $trackingId = null) {
        $accountSid = Env::get('TWILIO_ACCOUNT_SID');
        $authToken = Env::get('TWILIO_AUTH_TOKEN');
        $fromNumber = Env::get('TWILIO_FROM_NUMBER');
        // BACKEND_URL: the Twilio StatusCallback below is handled by THIS PHP app.
        // APP_URL is the Netlify frontend, whose SPA catch-all swallows unknown
        // paths with a 200, so delivery statuses and STOP opt-outs never arrived.
        $appUrl = Env::get('BACKEND_URL', 'https://teamselevated-backend-0485388bd66e.herokuapp.com');

        if (!$accountSid || !$authToken || !$fromNumber) {
            throw new \Exception('Twilio credentials are not configured');
        }

        $url = "https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json";

        $postFields = [
            'To' => $phone,
            'From' => $fromNumber,
            'Body' => $body,
        ];

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
        if (empty($phone)) {
            throw new \Exception('Phone number is empty');
        }

        // Preserve leading + before stripping
        $hasPlus = (substr(trim($phone), 0, 1) === '+');

        // Strip all non-digit characters
        $digits = preg_replace('/[^\d]/', '', $phone);

        if (empty($digits)) {
            throw new \Exception('Phone number contains no digits');
        }

        // If already had a +, it's international format — validate and return
        if ($hasPlus) {
            if (strlen($digits) < 10 || strlen($digits) > 15) {
                throw new \Exception("Invalid international phone number: +{$digits}");
            }
            return '+' . $digits;
        }

        // 10 digits: assume US number, prepend +1
        if (strlen($digits) === 10) {
            return '+1' . $digits;
        }

        // 11 digits starting with 1: assume US number with country code
        if (strlen($digits) === 11 && $digits[0] === '1') {
            return '+' . $digits;
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
