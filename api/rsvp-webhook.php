<?php
/**
 * RSVP Webhook API
 * Handles RSVP responses from calendar invitations
 */

header('Content-Type: application/json');

// Allow all origins for CORS
header('Access-Control-Allow-Origin: *');

header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance();
$conn = $db->getConnection();

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

/**
 * Handle RSVP response via URL click
 */
function handleRSVPResponse($conn, $token, $response) {
    if (empty($token) || empty($response)) {
        http_response_code(400);
        return ['error' => 'Token and response are required'];
    }

    // Validate response
    $validResponses = ['accepted', 'declined', 'tentative'];
    if (!in_array($response, $validResponses)) {
        http_response_code(400);
        return ['error' => 'Invalid response type'];
    }

    try {
        // Find the attendee by token
        $stmt = $conn->prepare('
            SELECT id, event_id, user_id, email, rsvp_status
            FROM calendar_event_attendees
            WHERE rsvp_token = :token
        ');
        $stmt->execute(['token' => $token]);
        $attendee = $stmt->fetch();

        if (!$attendee) {
            http_response_code(404);
            return ['error' => 'Invalid or expired RSVP token'];
        }

        // Update RSVP status
        $stmt = $conn->prepare('
            UPDATE calendar_event_attendees
            SET rsvp_status = :status, responded_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ');
        $stmt->execute([
            'status' => $response,
            'id' => $attendee['id']
        ]);

        // Get event details for response
        $stmt = $conn->prepare('
            SELECT name, event_date, start_time
            FROM calendar_events
            WHERE id = :event_id
        ');
        $stmt->execute(['event_id' => $attendee['event_id']]);
        $event = $stmt->fetch();

        return [
            'success' => true,
            'message' => 'RSVP recorded successfully',
            'rsvp_status' => $response,
            'event' => [
                'name' => $event['name'],
                'date' => $event['event_date'],
                'time' => $event['start_time']
            ]
        ];

    } catch (Exception $e) {
        error_log('RSVP webhook error: ' . $e->getMessage());
        http_response_code(500);
        return [
            'error' => 'Failed to record RSVP',
            'details' => getenv('APP_ENV') === 'development' ? $e->getMessage() : null
        ];
    }
}

/**
 * Get RSVP status for an event
 */
function handleGetRSVPStatus($conn, $eventId) {
    if (empty($eventId)) {
        http_response_code(400);
        return ['error' => 'Event ID is required'];
    }

    try {
        $stmt = $conn->prepare('
            SELECT
                cea.id,
                cea.email,
                u.first_name,
                u.last_name,
                cea.rsvp_status,
                cea.responded_at
            FROM calendar_event_attendees cea
            LEFT JOIN users u ON cea.user_id = u.id
            WHERE cea.event_id = :event_id
            ORDER BY cea.created_at ASC
        ');
        $stmt->execute(['event_id' => $eventId]);
        $attendees = $stmt->fetchAll();

        // Count by status
        $counts = [
            'accepted' => 0,
            'declined' => 0,
            'tentative' => 0,
            'pending' => 0
        ];

        $attendeeList = [];
        foreach ($attendees as $attendee) {
            $status = $attendee['rsvp_status'] ?? 'pending';
            $counts[$status]++;

            $attendeeList[] = [
                'name' => trim(($attendee['first_name'] ?? '') . ' ' . ($attendee['last_name'] ?? '')),
                'email' => $attendee['email'],
                'status' => $status,
                'responded_at' => $attendee['responded_at']
            ];
        }

        return [
            'success' => true,
            'event_id' => $eventId,
            'counts' => $counts,
            'attendees' => $attendeeList
        ];

    } catch (Exception $e) {
        error_log('Get RSVP status error: ' . $e->getMessage());
        http_response_code(500);
        return [
            'error' => 'Failed to get RSVP status',
            'details' => getenv('APP_ENV') === 'development' ? $e->getMessage() : null
        ];
    }
}

// Route handler
try {
    if ($method === 'POST' && $action === 'respond') {
        $token = $_GET['token'] ?? '';
        $response = $_GET['response'] ?? '';
        $result = handleRSVPResponse($conn, $token, $response);
        echo json_encode($result);
    } elseif ($method === 'GET' && $action === 'status') {
        $eventId = $_GET['event_id'] ?? '';
        $result = handleGetRSVPStatus($conn, $eventId);
        echo json_encode($result);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Endpoint not found']);
    }
} catch (Exception $e) {
    error_log('API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error',
        'details' => getenv('APP_ENV') === 'development' ? $e->getMessage() : null
    ]);
}
