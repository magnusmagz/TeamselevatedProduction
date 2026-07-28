<?php
/**
 * Twilio Status Callback Handler
 *
 * Receives delivery status updates from Twilio for outbound SMS.
 * Handles: queued, sent, delivered, failed, undelivered statuses.
 * Also detects STOP opt-outs via error code 21610.
 *
 * No authentication required — Twilio sends these directly.
 * Returns 200 with empty body as Twilio expects.
 */

require_once __DIR__ . '/../../lib/Cors.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/env.php';

$db = Database::getInstance();
$connection = $db->getConnection();

// Twilio sends form-encoded POST
$messageSid = $_POST['MessageSid'] ?? null;
$messageStatus = $_POST['MessageStatus'] ?? null;
$to = $_POST['To'] ?? null;
$errorCode = $_POST['ErrorCode'] ?? null;
$errorMessage = $_POST['ErrorMessage'] ?? null;

if (!$messageSid || !$messageStatus) {
    http_response_code(200);
    exit;
}

try {
    // Look up communication_log by twilio_message_sid
    $stmt = $connection->prepare(
        // communication_log has no contact_id column — selecting it threw
        // SQLSTATE 42703, so no Twilio delivery status or STOP opt-out was ever
        // recorded (on top of the callback URL pointing at the frontend).
        "SELECT id, club_profile_id, status
         FROM communication_log
         WHERE twilio_message_sid = :sid
         LIMIT 1"
    );
    $stmt->execute([':sid' => $messageSid]);
    $logRecord = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$logRecord) {
        error_log("Twilio status callback: no communication_log found for MessageSid={$messageSid}");
        http_response_code(200);
        exit;
    }

    $logId = $logRecord['id'];
    $contactId = null; // no such column; recipient is identified by communication_log_id
    $clubProfileId = $logRecord['club_profile_id'];
    $currentStatus = $logRecord['status'];

    // Map Twilio status to our status and update accordingly
    switch ($messageStatus) {
        case 'delivered':
            $stmt = $connection->prepare(
                "UPDATE communication_log
                 SET status = 'delivered', delivered_at = NOW(), updated_at = NOW()
                 WHERE id = :id"
            );
            $stmt->execute([':id' => $logId]);
            break;

        case 'sent':
            // Only update to 'sent' if current status is still queued or sending
            if (in_array($currentStatus, ['queued', 'sending'], true)) {
                $stmt = $connection->prepare(
                    "UPDATE communication_log
                     SET status = 'sent', sent_at = COALESCE(sent_at, NOW()), updated_at = NOW()
                     WHERE id = :id"
                );
                $stmt->execute([':id' => $logId]);
            }
            break;

        case 'failed':
        case 'undelivered':
            $stmt = $connection->prepare(
                "UPDATE communication_log
                 SET status = 'failed',
                     failed_at = NOW(),
                     failure_reason = :failure_reason,
                     updated_at = NOW()
                 WHERE id = :id"
            );
            $failureReason = $errorMessage ?: "Twilio status: {$messageStatus}";
            if ($errorCode) {
                $failureReason = "[{$errorCode}] {$failureReason}";
            }
            $stmt->execute([
                ':id' => $logId,
                ':failure_reason' => $failureReason,
            ]);
            break;

        default:
            // Other statuses (queued, sending, etc.) — log but don't update
            error_log("Twilio status callback: status '{$messageStatus}' for MessageSid={$messageSid}");
            break;
    }

    // Handle STOP opt-out (error code 21610)
    if ($errorCode === '21610' && $to) {
        // Normalize phone number for lookup (strip + prefix if present)
        $normalizedPhone = $to;
        $phoneVariants = [$to];
        if (strpos($to, '+') === 0) {
            $phoneVariants[] = substr($to, 1);
        }
        if (strpos($to, '+1') === 0) {
            $phoneVariants[] = substr($to, 2);
        }

        // Update guardian record — set sms_opt_out
        foreach ($phoneVariants as $phoneVariant) {
            $stmt = $connection->prepare(
                "UPDATE guardians
                 SET sms_opt_out = TRUE, sms_opt_out_at = NOW()
                 WHERE mobile_phone = :phone"
            );
            $stmt->execute([':phone' => $phoneVariant]);
        }

        // Also insert into email_suppressions for the suppression list
        if ($clubProfileId) {
            $stmt = $connection->prepare(
                // email_suppressions has neither contact_id nor suppressed_at.
                "INSERT INTO email_suppressions (club_profile_id, email, phone, channel, reason, created_at)
                 VALUES (:club_profile_id, NULL, :phone, 'sms', 'twilio_stop', NOW())
                 ON CONFLICT DO NOTHING"
            );
            $stmt->execute([
                ':club_profile_id' => $clubProfileId,
                ':phone' => $to,
            ]);
        }

        error_log("Twilio STOP opt-out recorded for phone={$to}, communication_log_id={$logId}");
    }

} catch (Exception $e) {
    error_log("Twilio status callback error: " . $e->getMessage());
}

// Always return 200 with empty body — Twilio expects this
http_response_code(200);
exit;
