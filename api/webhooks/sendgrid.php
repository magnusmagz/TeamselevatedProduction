<?php
/**
 * SendGrid Event Webhook Handler
 *
 * Receives webhook events from SendGrid for email tracking:
 * delivered, open, click, bounce, spamreport, unsubscribe, dropped, deferred
 *
 * No authentication required — SendGrid sends these directly.
 * Always returns 200 OK to prevent SendGrid retries.
 */

require_once __DIR__ . '/../../lib/Cors.php';
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/env.php';

$db = Database::getInstance();
$connection = $db->getConnection();

// SendGrid sends an array of events
$events = json_decode(file_get_contents('php://input'), true);

if (!is_array($events)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payload']);
    exit;
}

foreach ($events as $event) {
    try {
        $eventType = $event['event'] ?? null;
        if (!$eventType) {
            continue;
        }

        // Extract identifiers
        $sgMessageId = $event['sg_message_id'] ?? null;
        // Clean sg_message_id — SendGrid sometimes appends ".filter..." suffix
        if ($sgMessageId && strpos($sgMessageId, '.') !== false) {
            $sgMessageId = explode('.', $sgMessageId)[0];
        }

        $trackingId = $event['tracking_id']
            ?? ($event['custom_args']['tracking_id'] ?? null)
            ?? ($event['unique_args']['tracking_id'] ?? null);

        // Look up communication_log record
        $logRecord = null;

        if ($sgMessageId) {
            $stmt = $connection->prepare(
                "SELECT id, club_profile_id, status, open_count, click_count
                 FROM communication_log
                 WHERE sendgrid_message_id = :sg_id
                 LIMIT 1"
            );
            $stmt->execute([':sg_id' => $sgMessageId]);
            $logRecord = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$logRecord && $trackingId) {
            $stmt = $connection->prepare(
                "SELECT id, club_profile_id, status, open_count, click_count
                 FROM communication_log
                 WHERE tracking_id = :tracking_id
                 LIMIT 1"
            );
            $stmt->execute([':tracking_id' => $trackingId]);
            $logRecord = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$logRecord) {
            error_log("SendGrid webhook: no communication_log found for sg_message_id={$sgMessageId}, tracking_id={$trackingId}");
            continue;
        }

        $logId = $logRecord['id'];
        // communication_log has no contact_id column. Selecting it threw
        // SQLSTATE 42703 and killed the whole event batch, so no SendGrid event
        // ever reached email_events. Kept as null so the helper signatures below
        // stay unchanged; the recipient is identified by communication_log_id.
        $contactId = null;
        $clubProfileId = $logRecord['club_profile_id'];
        $timestamp = isset($event['timestamp']) ? date('Y-m-d H:i:s', $event['timestamp']) : date('Y-m-d H:i:s');

        switch ($eventType) {
            case 'delivered':
                $stmt = $connection->prepare(
                    "UPDATE communication_log
                     SET status = 'delivered', delivered_at = NOW(), updated_at = NOW()
                     WHERE id = :id"
                );
                $stmt->execute([':id' => $logId]);

                insertEmailEvent($connection, $logId, $contactId, 'delivered', $timestamp, [
                    'response' => $event['response'] ?? null,
                ]);
                break;

            case 'open':
                $stmt = $connection->prepare(
                    "UPDATE communication_log
                     SET open_count = open_count + 1,
                         opened_at = COALESCE(opened_at, NOW()),
                         last_opened_at = NOW(),
                         updated_at = NOW()
                     WHERE id = :id"
                );
                $stmt->execute([':id' => $logId]);

                insertEmailEvent($connection, $logId, $contactId, 'open', $timestamp, [
                    'ip' => $event['ip'] ?? null,
                    'useragent' => $event['useragent'] ?? null,
                ]);
                break;

            case 'click':
                $stmt = $connection->prepare(
                    "UPDATE communication_log
                     SET click_count = click_count + 1,
                         clicked_at = COALESCE(clicked_at, NOW()),
                         updated_at = NOW()
                     WHERE id = :id"
                );
                $stmt->execute([':id' => $logId]);

                insertEmailEvent($connection, $logId, $contactId, 'click', $timestamp, [
                    'url' => $event['url'] ?? null,
                    'ip' => $event['ip'] ?? null,
                    'useragent' => $event['useragent'] ?? null,
                ]);
                break;

            case 'bounce':
                $stmt = $connection->prepare(
                    "UPDATE communication_log
                     SET status = 'bounced', bounced_at = NOW(), updated_at = NOW()
                     WHERE id = :id"
                );
                $stmt->execute([':id' => $logId]);

                $bounceType = $event['type'] ?? null;
                $bounceReason = $event['reason'] ?? null;
                $bounceClassification = $event['bounce_classification'] ?? null;

                insertEmailEvent($connection, $logId, $contactId, 'bounce', $timestamp, [
                    'bounce_type' => $bounceType,
                    'reason' => $bounceReason,
                    'bounce_classification' => $bounceClassification,
                ]);

                // Hard bounce or permanent classification — add to suppression list
                $isPermanent = ($bounceType === 'HardBounce')
                    || ($bounceClassification === 'permanent')
                    || (stripos($bounceType ?? '', 'hard') !== false);

                if ($isPermanent && $clubProfileId) {
                    $email = $event['email'] ?? null;
                    insertSuppression($connection, $contactId, $clubProfileId, $email, null, 'email', 'hard_bounce');
                }
                break;

            case 'spamreport':
                insertEmailEvent($connection, $logId, $contactId, 'spamreport', $timestamp, [
                    'ip' => $event['ip'] ?? null,
                    'useragent' => $event['useragent'] ?? null,
                ]);

                if ($clubProfileId) {
                    $email = $event['email'] ?? null;
                    insertSuppression($connection, $contactId, $clubProfileId, $email, null, 'email', 'spam_complaint');
                }
                break;

            case 'unsubscribe':
                $stmt = $connection->prepare(
                    "UPDATE communication_log
                     SET unsubscribed_at = NOW(), updated_at = NOW()
                     WHERE id = :id"
                );
                $stmt->execute([':id' => $logId]);

                insertEmailEvent($connection, $logId, $contactId, 'unsubscribe', $timestamp, []);

                if ($clubProfileId) {
                    $email = $event['email'] ?? null;
                    insertSuppression($connection, $contactId, $clubProfileId, $email, null, 'email', 'unsubscribe_all');
                }
                break;

            case 'dropped':
                $stmt = $connection->prepare(
                    "UPDATE communication_log
                     SET status = 'rejected', updated_at = NOW()
                     WHERE id = :id"
                );
                $stmt->execute([':id' => $logId]);

                insertEmailEvent($connection, $logId, $contactId, 'dropped', $timestamp, [
                    'reason' => $event['reason'] ?? null,
                ]);
                break;

            case 'deferred':
                insertEmailEvent($connection, $logId, $contactId, 'deferred', $timestamp, [
                    'response' => $event['response'] ?? null,
                    'attempt_num' => $event['attempt_num'] ?? null,
                ]);
                break;

            default:
                // Unknown event type — log and skip
                error_log("SendGrid webhook: unknown event type '{$eventType}' for log id={$logId}");
                break;
        }
    } catch (Exception $e) {
        // Log error but continue processing remaining events
        error_log("SendGrid webhook error processing event: " . $e->getMessage());
    }
}

// Always return 200 OK — SendGrid retries on non-200
http_response_code(200);
echo json_encode(['status' => 'ok', 'processed' => count($events)]);


/**
 * Insert a record into the email_events table
 */
function insertEmailEvent(PDO $connection, int $logId, ?int $contactId, string $eventType, string $timestamp, array $eventData): void
{
    $stmt = $connection->prepare(
        // email_events has neither contact_id nor created_at.
        "INSERT INTO email_events (communication_log_id, event_type, event_data, ip_address, user_agent, occurred_at)
         VALUES (:log_id, :event_type, :event_data, :ip_address, :user_agent, :occurred_at)"
    );
    $stmt->execute([
        ':log_id' => $logId,
        ':event_type' => $eventType,
        ':event_data' => json_encode($eventData),
        ':ip_address' => $eventData['ip'] ?? null,
        ':user_agent' => $eventData['useragent'] ?? null,
        ':occurred_at' => $timestamp,
    ]);
}

/**
 * Insert a record into the email_suppressions table (idempotent — skips duplicates)
 */
function insertSuppression(PDO $connection, ?int $contactId, int $clubProfileId, ?string $email, ?string $phone, string $channel, string $reason): void
{
    $stmt = $connection->prepare(
        // email_suppressions has neither contact_id nor suppressed_at.
        "INSERT INTO email_suppressions (club_profile_id, email, phone, channel, reason, created_at)
         VALUES (:club_profile_id, :email, :phone, :channel, :reason, NOW())
         ON CONFLICT DO NOTHING"
    );
    $stmt->execute([
        ':club_profile_id' => $clubProfileId,
        ':email' => $email,
        ':phone' => $phone,
        ':channel' => $channel,
        ':reason' => $reason,
    ]);
}
