<?php
/**
 * Calendar Events Gateway API
 * Handles calendar events and sending calendar invitations
 */

header('Content-Type: application/json');

// Allow all origins for CORS
header('Access-Control-Allow-Origin: *');

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Email.php';

$db = Database::getInstance();
$conn = $db->getConnection();

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

/**
 * Send calendar invite for an event
 */
function handleSendCalendarInvite($conn, $input) {
    // Validate required fields
    if (empty($input['event_id'])) {
        http_response_code(400);
        return ['error' => 'Event ID is required'];
    }

    $eventId = $input['event_id'];
    $action = $input['invite_action'] ?? 'invite'; // 'invite' | 'update' | 'cancel'

    try {
        // Get event details
        $stmt = $conn->prepare('
            SELECT
                e.*,
                v.name as venue_name,
                v.address as venue_address
            FROM calendar_events e
            LEFT JOIN venues v ON e.venue_id = v.id
            WHERE e.id = :event_id
        ');
        $stmt->execute(['event_id' => $eventId]);
        $event = $stmt->fetch();

        if (!$event) {
            http_response_code(404);
            return ['error' => 'Event not found'];
        }

        // Get teams associated with this event
        $stmt = $conn->prepare('
            SELECT DISTINCT
                u.id as user_id,
                t.id,
                t.name,
                u.email,
                u.first_name,
                u.last_name
            FROM calendar_event_teams cet
            INNER JOIN teams t ON cet.team_id = t.id
            INNER JOIN team_players tp ON t.id = tp.team_id
            INNER JOIN users u ON tp.user_id = u.id
            WHERE cet.event_id = :event_id AND u.email IS NOT NULL AND u.email != ""
        ');
        $stmt->execute(['event_id' => $eventId]);
        $attendees = $stmt->fetchAll();

        if (empty($attendees)) {
            return [
                'success' => false,
                'message' => 'No attendees found for this event'
            ];
        }

        // Build attendees array and create/update attendee records with RSVP tokens
        $attendeesList = [];
        foreach ($attendees as $attendee) {
            $userId = $attendee['user_id'] ?? null;

            // Generate unique RSVP token
            $rsvpToken = bin2hex(random_bytes(32));

            // Create or update attendee record
            $stmt = $conn->prepare('
                INSERT INTO calendar_event_attendees (event_id, user_id, email, rsvp_token, created_at)
                VALUES (:event_id, :user_id, :email, :rsvp_token, CURRENT_TIMESTAMP)
                ON CONFLICT (event_id, user_id)
                DO UPDATE SET rsvp_token = :rsvp_token
                RETURNING rsvp_token
            ');
            $stmt->execute([
                'event_id' => $eventId,
                'user_id' => $userId,
                'email' => $attendee['email'],
                'rsvp_token' => $rsvpToken
            ]);
            $result = $stmt->fetch();
            $finalToken = $result['rsvp_token'];

            $attendeesList[] = [
                'name' => trim($attendee['first_name'] . ' ' . $attendee['last_name']),
                'email' => $attendee['email'],
                'rsvp_token' => $finalToken
            ];
        }

        // Combine date and time for startDateTime and endDateTime
        $startDateTime = $event['event_date'] . ' ' . ($event['start_time'] ?? '00:00:00');
        $endDateTime = $event['event_date'] . ' ' . ($event['end_time'] ?? '23:59:59');

        // Build location string
        $location = $event['venue_name'] ?? $event['location'] ?? 'TBD';
        if (!empty($event['venue_address'])) {
            $location .= ', ' . $event['venue_address'];
        }

        // Prepare event data for calendar invite
        $calendarEvent = [
            'summary' => $event['name'],
            'startDateTime' => $startDateTime,
            'endDateTime' => $endDateTime,
            'location' => $location,
            'description' => $event['description'] ?? '',
            'status' => strtoupper($event['status']),
            'organizerName' => 'Teams Elevated',
            'organizerEmail' => 'events@rsvp.eyeinteams.com',  // RSVP email for calendar REPLY parsing
            'attendees' => $attendeesList
        ];

        // Send calendar invite
        $email = new Email();
        $sent = $email->sendCalendarInvite($calendarEvent, $action);

        if ($sent) {
            return [
                'success' => true,
                'message' => 'Calendar invites sent successfully',
                'attendees_count' => count($attendeesList)
            ];
        } else {
            http_response_code(500);
            return [
                'success' => false,
                'error' => 'Failed to send calendar invites'
            ];
        }

    } catch (Exception $e) {
        error_log('Calendar invite error: ' . $e->getMessage());
        http_response_code(500);
        return [
            'error' => 'Failed to send calendar invites',
            'details' => getenv('APP_ENV') === 'development' ? $e->getMessage() : null
        ];
    }
}

// Route handler
try {
    if ($method === 'POST' && $action === 'send-invite') {
        $input = json_decode(file_get_contents('php://input'), true);
        $response = handleSendCalendarInvite($conn, $input);
        echo json_encode($response);
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
