<?php
/**
 * Click Tracking Redirect Endpoint
 *
 * Records a link click event and redirects the user to the original URL.
 * Used in outbound emails where links are rewritten to pass through this tracker.
 *
 * No authentication required.
 * If link not found, redirects to APP_URL as fallback.
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/env.php';

$db = Database::getInstance();
$connection = $db->getConnection();

$trackingId = $_GET['trackingId'] ?? null;
$linkId = $_GET['linkId'] ?? null;

$fallbackUrl = Env::get('APP_URL', 'https://teamselevated.com');
$originalUrl = $fallbackUrl;

if ($linkId) {
    try {
        // Look up email_links by link_id
        $stmt = $connection->prepare(
            "SELECT id, communication_log_id, original_url
             FROM email_links
             WHERE link_id = :link_id
             LIMIT 1"
        );
        $stmt->execute([':link_id' => $linkId]);
        $linkRecord = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($linkRecord) {
            $originalUrl = $linkRecord['original_url'];
            $emailLinkId = $linkRecord['id'];
            $communicationLogId = $linkRecord['communication_log_id'];

            // Update click counts on email_links
            $stmt = $connection->prepare(
                "UPDATE email_links
                 SET click_count = click_count + 1,
                     first_clicked_at = COALESCE(first_clicked_at, NOW()),
                     last_clicked_at = NOW()
                 WHERE id = :id"
            );
            $stmt->execute([':id' => $emailLinkId]);

            // Look up the parent communication_log to update its click count
            $logRecord = null;
            if ($communicationLogId) {
                // communication_log has no contact_id column — selecting it threw
                // SQLSTATE 42703, caught below. The redirect and the email_links
                // increment above already happened, so clicks looked fine to the
                // recipient while communication_log.clicked_at and the
                // email_events row — what the reporting UI reads — never landed.
                $stmt = $connection->prepare(
                    "SELECT id
                     FROM communication_log
                     WHERE id = :id
                     LIMIT 1"
                );
                $stmt->execute([':id' => $communicationLogId]);
                $logRecord = $stmt->fetch(PDO::FETCH_ASSOC);
            }

            if ($logRecord) {
                // Update click counts on communication_log
                $stmt = $connection->prepare(
                    "UPDATE communication_log
                     SET click_count = click_count + 1,
                         clicked_at = COALESCE(clicked_at, NOW()),
                         updated_at = NOW()
                     WHERE id = :id"
                );
                $stmt->execute([':id' => $logRecord['id']]);

                // Insert email_events record
                $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
                $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

                $stmt = $connection->prepare(
                    // email_events has neither contact_id nor created_at.
                    "INSERT INTO email_events (communication_log_id, event_type, event_data, ip_address, user_agent, occurred_at)
                     VALUES (:log_id, 'click', :event_data, :ip_address, :user_agent, NOW())"
                );
                $stmt->execute([
                    ':log_id' => $logRecord['id'],
                    ':event_data' => json_encode([
                        'url' => $originalUrl,
                        'link_id' => $linkId,
                        'ip' => $ipAddress,
                        'useragent' => $userAgent,
                    ]),
                    ':ip_address' => $ipAddress,
                    ':user_agent' => $userAgent,
                ]);
            }
        } else {
            error_log("Click tracking: no email_links record found for linkId={$linkId}");
        }
    } catch (Exception $e) {
        error_log("Click tracking error: " . $e->getMessage());
    }
}

// 302 redirect to the original URL (or fallback)
header('Location: ' . $originalUrl, true, 302);
exit;
