<?php
/**
 * Open Tracking Pixel Endpoint
 *
 * Returns a 1x1 transparent GIF while recording the email open event.
 * Embedded in outbound emails as <img src="...?trackingId=xxx">
 *
 * No authentication required.
 * Always returns the GIF even if tracking_id is not found (don't break email display).
 */

require_once __DIR__ . '/../../config/database.php';

$db = Database::getInstance();
$connection = $db->getConnection();

$trackingId = $_GET['trackingId'] ?? null;

if ($trackingId) {
    try {
        // Look up communication_log by tracking_id
        $stmt = $connection->prepare(
            "SELECT id, contact_id
             FROM communication_log
             WHERE tracking_id = :tracking_id
             LIMIT 1"
        );
        $stmt->execute([':tracking_id' => $trackingId]);
        $logRecord = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($logRecord) {
            $logId = $logRecord['id'];
            $contactId = $logRecord['contact_id'];

            // Update open counts on communication_log
            $stmt = $connection->prepare(
                "UPDATE communication_log
                 SET open_count = open_count + 1,
                     opened_at = COALESCE(opened_at, NOW()),
                     last_opened_at = NOW(),
                     updated_at = NOW()
                 WHERE id = :id"
            );
            $stmt->execute([':id' => $logId]);

            // Insert email_events record
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

            $stmt = $connection->prepare(
                "INSERT INTO email_events (communication_log_id, contact_id, event_type, event_data, ip_address, user_agent, occurred_at, created_at)
                 VALUES (:log_id, :contact_id, 'open', :event_data, :ip_address, :user_agent, NOW(), NOW())"
            );
            $stmt->execute([
                ':log_id' => $logId,
                ':contact_id' => $contactId,
                ':event_data' => json_encode([
                    'source' => 'tracking_pixel',
                    'ip' => $ipAddress,
                    'useragent' => $userAgent,
                ]),
                ':ip_address' => $ipAddress,
                ':user_agent' => $userAgent,
            ]);
        }
    } catch (Exception $e) {
        // Log error but still return the GIF
        error_log("Tracking pixel error: " . $e->getMessage());
    }
}

// Return 1x1 transparent GIF with no-cache headers
header('Content-Type: image/gif');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
exit;
