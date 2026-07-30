<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../lib/RedisQueue.php';
require_once __DIR__ . '/../lib/suppression.php';
require_once __DIR__ . '/../lib/EmailBranding.php';

/**
 * Email Send Service
 *
 * Core service for user-initiated CRM emails (NOT transactional system emails).
 * Handles queuing, sending via SendGrid, link/open tracking, and unsubscribe management.
 */
class EmailSendService {
    private $pdo;
    private $mergeFieldService;

    public function __construct($pdo) {
        $this->pdo = $pdo;

        if (file_exists(__DIR__ . '/MergeFieldService.php')) {
            require_once __DIR__ . '/MergeFieldService.php';
            $this->mergeFieldService = new MergeFieldService($pdo);
        }
    }

    /**
     * Queue emails for a list of recipients.
     *
     * @param array $params {
     *   user_id: int,
     *   club_profile_id: int,
     *   recipients: array of {email, name, type, id, athlete_id},
     *   subject: string,
     *   html_body: string,
     *   body: string|null,
     *   template_id: int|null,
     *   event_id: int|null,
     *   broadcast_campaign_id: int|null
     * }
     * @return array {queued: int, skipped: int, skipped_details: array}
     */
    public function queueEmail($params) {
        $userId = $params['user_id'];
        $clubProfileId = $params['club_profile_id'];
        $recipients = $params['recipients'];
        $subject = $params['subject'];
        $htmlBody = $params['html_body'];
        $body = $params['body'] ?? null;
        $templateId = $params['template_id'] ?? null;
        $eventId = $params['event_id'] ?? null;
        $broadcastCampaignId = $params['broadcast_campaign_id'] ?? null;
        // Teams this send targets (team broadcast). Empty for a general/individual
        // send — used so a team-scoped unsubscribe only blocks that team's email.
        $teamIds = $params['team_ids'] ?? [];

        $queued = 0;
        $skipped = 0;
        $skippedDetails = [];

        $redis = RedisQueue::getInstance();

        foreach ($recipients as $recipient) {
            $recipientEmail = $recipient['email'];
            $recipientName = $recipient['name'] ?? '';
            $recipientType = $recipient['type'] ?? 'user';
            $recipientId = $recipient['id'] ?? null;
            $athleteId = $recipient['athlete_id'] ?? null;

            // Check suppression list (scope-aware: club-wide vs this send's teams)
            if ($this->isSuppressed($recipientEmail, $clubProfileId, $teamIds)) {
                $skipped++;
                $skippedDetails[] = [
                    'email' => $recipientEmail,
                    'name' => $recipientName,
                    'reason' => 'suppressed'
                ];
                continue;
            }

            // Generate unique tracking ID
            $trackingId = bin2hex(random_bytes(16));

            // Insert communication log record
            $stmt = $this->pdo->prepare(
                'INSERT INTO communication_log
                    (club_profile_id, user_id, channel, recipient_type, recipient_id,
                     recipient_email, recipient_name, athlete_id, subject, body, html_body,
                     status, tracking_id, broadcast_campaign_id, event_id, template_id, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                 RETURNING id'
            );
            $stmt->execute([
                $clubProfileId,
                $userId,
                'email',
                $recipientType,
                $recipientId,
                $recipientEmail,
                $recipientName,
                $athleteId,
                $subject,
                $body,
                $htmlBody,
                'queued',
                $trackingId,
                $broadcastCampaignId,
                $eventId,
                $templateId
            ]);
            $logId = $stmt->fetchColumn();

            // Process HTML: inject tracking pixel, wrap links, add unsubscribe footer
            $processedHtml = $this->processHtml($htmlBody, $trackingId, $logId, $clubProfileId);

            // Push job to Redis email queue
            $redis->push('email_queue', [
                'id' => uniqid('email_', true),
                'type' => 'send_email',
                'communication_log_id' => $logId,
                'processed_html' => $processedHtml,
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
     * Process a queued email job (called by the worker).
     *
     * @param array $payload Job payload from Redis
     * @throws Exception on send failure for worker retry logic
     */
    public function processJob($payload) {
        $logId = $payload['communication_log_id'];
        $processedHtml = $payload['processed_html'];

        // Fetch the communication log record
        $stmt = $this->pdo->prepare('SELECT * FROM communication_log WHERE id = ?');
        $stmt->execute([$logId]);
        $logRecord = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$logRecord) {
            error_log("EmailSendService::processJob — communication_log ID {$logId} not found, skipping.");
            return;
        }

        // Skip if already processed (idempotency guard)
        if (!in_array($logRecord['status'], ['queued', 'sending'], true)) {
            error_log("EmailSendService::processJob — communication_log ID {$logId} status is '{$logRecord['status']}', skipping.");
            return;
        }

        // Update status to 'sending'
        $stmt = $this->pdo->prepare('UPDATE communication_log SET status = ? WHERE id = ?');
        $stmt->execute(['sending', $logId]);

        // Fetch sender info (incl. their saved email signature).
        $stmt = $this->pdo->prepare('SELECT first_name, last_name, email, email_signature FROM users WHERE id = ?');
        $stmt->execute([$logRecord['user_id']]);
        $senderInfo = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$senderInfo) {
            $this->markFailed($logId, 'Sender user not found');
            throw new \Exception("Sender user ID {$logRecord['user_id']} not found");
        }

        // Append the sender's signature — the profile page promises it is "appended
        // to all outbound emails". nl2br keeps plain-text signatures readable and
        // leaves HTML signatures intact.
        if (!empty($senderInfo['email_signature'])) {
            $processedHtml .= '<div class="email-signature" style="margin-top:16px">'
                . nl2br($senderInfo['email_signature']) . '</div>';
        }

        try {
            $this->sendViaSendGrid($logRecord, $senderInfo, $processedHtml);

            // Update status to 'sent'
            $stmt = $this->pdo->prepare('UPDATE communication_log SET status = ?, sent_at = NOW() WHERE id = ?');
            $stmt->execute(['sent', $logId]);

            // Update last_contacted timestamp on the recipient record
            $this->updateLastContacted($logRecord);

        } catch (\Exception $e) {
            $this->markFailed($logId, $e->getMessage());
            throw $e; // Re-throw so the worker can handle retry logic
        }
    }

    /**
     * Send an email via SendGrid v3 API.
     *
     * @param array  $logRecord     The communication_log row
     * @param array  $senderInfo    {first_name, last_name, email}
     * @param string $processedHtml Fully processed HTML with tracking and unsubscribe
     * @return bool
     * @throws Exception on failure
     */
    public function sendViaSendGrid($logRecord, $senderInfo, $processedHtml) {
        $apiKey = Env::get('SENDGRID_API_KEY');
        if (empty($apiKey)) {
            throw new \Exception('SENDGRID_API_KEY is not configured');
        }

        $senderName = trim($senderInfo['first_name'] . ' ' . $senderInfo['last_name']);
        // BACKEND_URL, not APP_URL. Everything built here — the open pixel, the
        // click redirector, the unsubscribe page — is served by THIS PHP app.
        // APP_URL is the Netlify frontend, whose SPA catch-all returns index.html
        // with a 200 for any unknown path, so those links silently resolved to the
        // app shell: opens never recorded, clicks never recorded AND the recipient
        // never reached the link target, and unsubscribe links did nothing.
        // APP_URL stays correct for user-facing destinations (e.g. /parent).
        $appUrl = Env::get('BACKEND_URL', 'https://teamselevated-backend-0485388bd66e.herokuapp.com');
        $unsubscribeUrl = $appUrl . '/unsubscribe?token=' . $this->generateUnsubscribeToken(
            $logRecord['id'],
            $logRecord['recipient_email'],
            $logRecord['club_profile_id']
        );

        $payload = [
            'personalizations' => [[
                'to' => [[
                    'email' => $logRecord['recipient_email'],
                    'name' => $logRecord['recipient_name']
                ]],
                'custom_args' => [
                    'tracking_id' => $logRecord['tracking_id']
                ]
            ]],
            'from' => [
                'email' => Env::get('SENDGRID_FROM_EMAIL', 'notifications@teamselevated.com'),
                'name' => $senderName
            ],
            'reply_to' => [
                'email' => $senderInfo['email'],
                'name' => $senderName
            ],
            'subject' => $logRecord['subject'],
            'content' => [
                ['type' => 'text/plain', 'value' => $logRecord['body'] ?? strip_tags($processedHtml)],
                ['type' => 'text/html', 'value' => $processedHtml]
            ],
            'headers' => [
                'List-Unsubscribe' => '<' . $unsubscribeUrl . '>',
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'
            ],
            'tracking_settings' => [
                'click_tracking' => ['enable' => false],
                'open_tracking' => ['enable' => false]
            ]
        ];

        $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new \Exception('cURL error sending email: ' . $curlError);
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            // Extract X-Message-Id from response headers
            $headerString = substr($response, 0, $headerSize);
            $messageId = $this->extractHeader($headerString, 'X-Message-Id');

            if ($messageId) {
                $stmt = $this->pdo->prepare('UPDATE communication_log SET sendgrid_message_id = ? WHERE id = ?');
                $stmt->execute([$messageId, $logRecord['id']]);
            }

            error_log("Email sent successfully to {$logRecord['recipient_email']} (log ID: {$logRecord['id']})");
            return true;
        }

        $responseBody = substr($response, $headerSize);
        throw new \Exception("SendGrid API error (HTTP {$httpCode}): {$responseBody}");
    }

    /**
     * Process HTML for tracking: wrap links, inject pixel, add unsubscribe footer.
     *
     * @param string $html            Raw HTML body
     * @param string $trackingId      Unique tracking ID
     * @param int    $communicationLogId  communication_log record ID
     * @param int    $clubProfileId   Club profile ID
     * @return string Processed HTML
     */
    public function processHtml($html, $trackingId, $communicationLogId, $clubProfileId) {
        // BACKEND_URL, not APP_URL. Everything built here — the open pixel, the
        // click redirector, the unsubscribe page — is served by THIS PHP app.
        // APP_URL is the Netlify frontend, whose SPA catch-all returns index.html
        // with a 200 for any unknown path, so those links silently resolved to the
        // app shell: opens never recorded, clicks never recorded AND the recipient
        // never reached the link target, and unsubscribe links did nothing.
        // APP_URL stays correct for user-facing destinations (e.g. /parent).
        $appUrl = Env::get('BACKEND_URL', 'https://teamselevated-backend-0485388bd66e.herokuapp.com');

        // 1. Link wrapping — rewrite href attributes for click tracking
        $html = preg_replace_callback(
            '/<a\s([^>]*?)href=["\']([^"\']+)["\']/i',
            function ($matches) use ($appUrl, $trackingId, $communicationLogId) {
                $attributes = $matches[1];
                $originalUrl = $matches[2];

                // Skip mailto, tel, anchor, and unsubscribe links
                if (preg_match('/^(mailto:|tel:|#)/i', $originalUrl)) {
                    return $matches[0];
                }
                if (stripos($originalUrl, '/unsubscribe') !== false) {
                    return $matches[0];
                }

                // Generate a unique link ID and store it
                $linkId = bin2hex(random_bytes(8));

                $stmt = $this->pdo->prepare(
                    'INSERT INTO email_links (communication_log_id, link_id, original_url)
                     VALUES (?, ?, ?)'
                );
                $stmt->execute([$communicationLogId, $linkId, $originalUrl]);

                $trackUrl = $appUrl . '/api/track/click/' . $trackingId . '/' . $linkId;
                return '<a ' . $attributes . 'href="' . htmlspecialchars($trackUrl) . '"';
            },
            $html
        );

        // 2. Open tracking pixel
        $pixel = '<img src="' . htmlspecialchars($appUrl . '/api/track/pixel/' . $trackingId . '.gif')
               . '" width="1" height="1" style="display:none" alt="" />';

        // Anchor on the last </body> — templates with explanatory HTML comments
        // containing markup used to get a second pixel injected inside the comment.
        $html = \EmailBranding::appendToBody($html, $pixel);

        // 3. Club-branded header + footer.
        // Templates are shared across clubs (platform scope) so they carry no club
        // identity of their own — the sending club's logo, colour, socials and the
        // unsubscribe link are applied here, at send time. EmailBranding::wrap is
        // idempotent, so a body that already carries the branding markers is left
        // alone rather than double-branded.

        // Fetch the recipient email from the communication log for the unsubscribe token
        $recipientEmail = '';
        $stmt = $this->pdo->prepare('SELECT recipient_email FROM communication_log WHERE id = ?');
        $stmt->execute([$communicationLogId]);
        $logRow = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($logRow) {
            $recipientEmail = $logRow['recipient_email'];
        }

        $unsubscribeToken = $this->generateUnsubscribeToken($communicationLogId, $recipientEmail, $clubProfileId);
        $unsubscribeUrl = $appUrl . '/unsubscribe?token=' . urlencode($unsubscribeToken);

        // {{unsubscribe_link}} can only be filled here — the signed token needs the
        // communication_log row, which does not exist when MergeFieldService runs.
        // Templates that place their own unsubscribe link (the tournament ones)
        // would otherwise mail the raw tag.
        $html = str_replace('{{unsubscribe_link}}', htmlspecialchars($unsubscribeUrl), $html);

        $brand = \EmailBranding::forClub($this->pdo, $clubProfileId);

        return \EmailBranding::wrap($html, $brand, $unsubscribeUrl);
    }

    /**
     * Generate a signed unsubscribe token.
     *
     * @param int    $communicationLogId
     * @param string $email
     * @param int    $clubProfileId
     * @return string base64url(data):base64url(hmac)
     */
    public function generateUnsubscribeToken($communicationLogId, $email, $clubProfileId) {
        $data = $communicationLogId . '|' . $email . '|' . $clubProfileId;
        $secret = Env::get('JWT_SECRET', '');
        $hmac = hash_hmac('sha256', $data, $secret, true);

        return $this->base64urlEncode($data) . ':' . $this->base64urlEncode($hmac);
    }

    /**
     * Validate and decode an unsubscribe token.
     *
     * @param string $token
     * @return array|false {communication_log_id, email, club_profile_id} or false
     */
    public function validateUnsubscribeToken($token) {
        $parts = explode(':', $token, 2);
        if (count($parts) !== 2) {
            return false;
        }

        $data = $this->base64urlDecode($parts[0]);
        $providedHmac = $this->base64urlDecode($parts[1]);

        if ($data === false || $providedHmac === false) {
            return false;
        }

        $secret = Env::get('JWT_SECRET', '');
        $expectedHmac = hash_hmac('sha256', $data, $secret, true);

        if (!hash_equals($expectedHmac, $providedHmac)) {
            return false;
        }

        // Parse data string: communicationLogId|email|clubProfileId
        $segments = explode('|', $data, 3);
        if (count($segments) !== 3) {
            return false;
        }

        return [
            'communication_log_id' => (int) $segments[0],
            'email' => $segments[1],
            'club_profile_id' => (int) $segments[2]
        ];
    }

    /**
     * Check if an email address is suppressed for a given club.
     *
     * @param string $email
     * @param int    $clubProfileId
     * @return bool
     */
    private function isSuppressed($email, $clubProfileId, array $teamIds = []) {
        // Scope-aware — see lib/suppression.php (te_email_suppressed).
        return te_email_suppressed($this->pdo, $email, (int) $clubProfileId, $teamIds);
    }

    /**
     * Mark a communication log entry as permanently failed.
     *
     * @param int    $logId
     * @param string $reason
     */
    private function markFailed($logId, $reason) {
        $stmt = $this->pdo->prepare(
            'UPDATE communication_log SET status = ?, failed_at = NOW(), failure_reason = ? WHERE id = ?'
        );
        $stmt->execute(['failed', $reason, $logId]);
    }

    /**
     * Update the last_contacted timestamp on the recipient's record.
     *
     * @param array $logRecord communication_log row
     */
    private function updateLastContacted($logRecord) {
        $recipientType = $logRecord['recipient_type'];
        $recipientId = $logRecord['recipient_id'];

        if (!$recipientId) {
            return;
        }

        try {
            if ($recipientType === 'guardian') {
                $stmt = $this->pdo->prepare('UPDATE guardians SET last_contacted = NOW() WHERE id = ?');
                $stmt->execute([$recipientId]);
            } else {
                // athlete, coach, user — all stored in users table
                $stmt = $this->pdo->prepare('UPDATE users SET last_contacted = NOW() WHERE id = ?');
                $stmt->execute([$recipientId]);
            }
        } catch (\Exception $e) {
            // Non-critical — log but do not fail the send
            error_log("Failed to update last_contacted for {$recipientType} ID {$recipientId}: " . $e->getMessage());
        }
    }

    /**
     * Extract a specific header value from a raw HTTP header string.
     *
     * @param string $headerString Raw headers
     * @param string $headerName   Header to extract
     * @return string|null
     */
    private function extractHeader($headerString, $headerName) {
        $pattern = '/^' . preg_quote($headerName, '/') . ':\s*(.+)$/mi';
        if (preg_match($pattern, $headerString, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }

    /**
     * Base64url encode (URL-safe, no padding).
     *
     * @param string $data
     * @return string
     */
    private function base64urlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64url decode.
     *
     * @param string $data
     * @return string|false
     */
    private function base64urlDecode($data) {
        $padded = str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT);
        return base64_decode($padded, true);
    }
}
