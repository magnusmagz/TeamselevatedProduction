<?php
/**
 * Calendar REPLY Parser
 * Receives iCalendar REPLY emails from SendGrid Inbound Parse
 * Parses RSVP responses and updates database
 */

// Log all incoming requests for debugging
error_log('Calendar REPLY Parser: Request received');
error_log('Method: ' . $_SERVER['REQUEST_METHOD']);
error_log('Content-Type: ' . ($_SERVER['CONTENT_TYPE'] ?? 'not set'));

require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance();
$conn = $db->getConnection();

/**
 * Parse iCalendar REPLY content
 */
function parseCalendarReply($icsContent) {
    $data = [
        'uid' => null,
        'attendee_email' => null,
        'partstat' => null
    ];

    // Split into lines
    $lines = explode("\n", $icsContent);

    foreach ($lines as $line) {
        $line = trim($line);

        // Extract UID
        if (strpos($line, 'UID:') === 0) {
            $data['uid'] = trim(substr($line, 4));
        }

        // Extract ATTENDEE and PARTSTAT
        if (strpos($line, 'ATTENDEE') === 0) {
            // Extract email from MAILTO:
            if (preg_match('/MAILTO:([^\s;]+)/i', $line, $matches)) {
                $data['attendee_email'] = strtolower(trim($matches[1]));
            }

            // Extract PARTSTAT
            if (preg_match('/PARTSTAT=([A-Z-]+)/i', $line, $matches)) {
                $data['partstat'] = strtoupper(trim($matches[1]));
            }
        }
    }

    return $data;
}

/**
 * Map PARTSTAT to our RSVP status
 */
function mapPartstatToRsvpStatus($partstat) {
    $mapping = [
        'ACCEPTED' => 'accepted',
        'DECLINED' => 'declined',
        'TENTATIVE' => 'tentative',
        'NEEDS-ACTION' => 'pending'
    ];

    return $mapping[$partstat] ?? 'pending';
}

/**
 * Process the calendar REPLY
 */
function processCalendarReply($conn, $replyData) {
    if (!$replyData['uid'] || !$replyData['attendee_email'] || !$replyData['partstat']) {
        error_log('Calendar REPLY: Missing required data - UID: ' . ($replyData['uid'] ?: 'missing') .
                  ', Email: ' . ($replyData['attendee_email'] ?: 'missing') .
                  ', PARTSTAT: ' . ($replyData['partstat'] ?: 'missing'));
        return ['success' => false, 'error' => 'Missing required data in REPLY'];
    }

    $uid = $replyData['uid'];
    $attendeeEmail = $replyData['attendee_email'];
    $partstat = $replyData['partstat'];
    $rsvpStatus = mapPartstatToRsvpStatus($partstat);

    error_log("Calendar REPLY: Processing - UID: $uid, Email: $attendeeEmail, PARTSTAT: $partstat, RSVP Status: $rsvpStatus");

    try {
        // Find the event by UID (stored in calendar_events or we need to add it)
        // For now, we'll match by attendee email and find pending/recent events
        // In production, we should store the UID in the calendar_events table

        // Find the most recent pending attendee record
        $stmt = $conn->prepare('
            SELECT id, event_id
            FROM calendar_event_attendees
            WHERE email = :email
            AND rsvp_status = \'pending\'
            ORDER BY created_at DESC
            LIMIT 1
        ');
        $stmt->execute(['email' => $attendeeEmail]);
        $attendeeRecord = $stmt->fetch();

        if (!$attendeeRecord) {
            error_log("Calendar REPLY: No matching attendee found for email: $attendeeEmail");
            return [
                'success' => false,
                'error' => 'No matching attendee found',
                'email' => $attendeeEmail
            ];
        }

        // Update the attendee's RSVP status
        $stmt = $conn->prepare('
            UPDATE calendar_event_attendees
            SET rsvp_status = :rsvp_status, responded_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ');
        $result = $stmt->execute([
            'rsvp_status' => $rsvpStatus,
            'id' => $attendeeRecord['id']
        ]);

        if ($result) {
            error_log("Calendar REPLY: Successfully updated attendee ID: {$attendeeRecord['id']}, Event ID: {$attendeeRecord['event_id']}");

            return [
                'success' => true,
                'message' => 'RSVP updated successfully',
                'attendee_id' => $attendeeRecord['id'],
                'event_id' => $attendeeRecord['event_id'],
                'status' => $rsvpStatus
            ];
        }

        error_log("Calendar REPLY: Failed to update attendee");
        return [
            'success' => false,
            'error' => 'No matching attendee found',
            'email' => $attendeeEmail
        ];

    } catch (Exception $e) {
        error_log('Calendar REPLY: Database error - ' . $e->getMessage());
        return [
            'success' => false,
            'error' => 'Database error: ' . $e->getMessage()
        ];
    }
}

// Handle SendGrid Inbound Parse webhook
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // SendGrid sends the raw MIME message in the 'email' field
        $rawEmail = $_POST['email'] ?? '';

        if (empty($rawEmail)) {
            error_log('Calendar REPLY: No email content in POST data');
            error_log('POST keys: ' . implode(', ', array_keys($_POST)));
            http_response_code(400);
            echo json_encode(['error' => 'No email content received']);
            exit;
        }

        error_log('Calendar REPLY: Received email, length: ' . strlen($rawEmail) . ' bytes');

        // Extract the iCalendar part from the MIME message
        // Look for text/calendar content
        $icsContent = '';

        // Simple extraction - look for BEGIN:VCALENDAR ... END:VCALENDAR
        if (preg_match('/BEGIN:VCALENDAR.*?END:VCALENDAR/s', $rawEmail, $matches)) {
            $icsContent = $matches[0];
            error_log('Calendar REPLY: Found iCalendar content, length: ' . strlen($icsContent) . ' bytes');
        } else {
            error_log('Calendar REPLY: No iCalendar content found in email');
            error_log('Email preview (first 500 chars): ' . substr($rawEmail, 0, 500));
        }

        if (empty($icsContent)) {
            http_response_code(400);
            echo json_encode(['error' => 'No iCalendar content found in email']);
            exit;
        }

        // Parse the REPLY
        $replyData = parseCalendarReply($icsContent);
        error_log('Calendar REPLY: Parsed data - ' . json_encode($replyData));

        // Process the REPLY
        $result = processCalendarReply($conn, $replyData);

        http_response_code($result['success'] ? 200 : 400);
        echo json_encode($result);

    } else {
        // Health check endpoint
        http_response_code(200);
        echo json_encode([
            'status' => 'ok',
            'message' => 'Calendar REPLY parser is ready',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }

} catch (Exception $e) {
    error_log('Calendar REPLY: Fatal error - ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error',
        'message' => $e->getMessage()
    ]);
}
