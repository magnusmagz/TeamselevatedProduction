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
require_once __DIR__ . '/../lib/AuthMiddleware.php';

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
    if ($method === 'GET' && $action === 'upcoming') {
        // Get upcoming events, optionally filtered by athlete's teams
        $athlete_id = $_GET['athlete_id'] ?? null;
        $limit = $_GET['limit'] ?? 50;

        $params = [];

        if ($athlete_id) {
            // Get events for teams this athlete belongs to
            $query = "
                SELECT DISTINCT
                    ce.id, ce.name AS title, ce.type, ce.event_date AS date,
                    ce.start_time, ce.end_time, ce.location, ce.description,
                    ce.status,
                    t.id AS team_id, t.name AS team_name
                FROM calendar_events ce
                JOIN calendar_event_teams cet ON ce.id = cet.event_id
                JOIN teams t ON cet.team_id = t.id
                JOIN team_members tm ON t.id = tm.team_id
                WHERE tm.athlete_id = :athlete_id
                  AND ce.event_date >= CURRENT_DATE
                  AND (ce.status IS NULL OR ce.status != 'cancelled')
                ORDER BY ce.event_date ASC, ce.start_time ASC
                LIMIT :lim
            ";
            $params['athlete_id'] = $athlete_id;
        } else {
            // Get all upcoming events (for parents with multiple athletes, get all their teams)
            $query = "
                SELECT DISTINCT
                    ce.id, ce.name AS title, ce.type, ce.event_date AS date,
                    ce.start_time, ce.end_time, ce.location, ce.description,
                    ce.status,
                    t.id AS team_id, t.name AS team_name
                FROM calendar_events ce
                JOIN calendar_event_teams cet ON ce.id = cet.event_id
                JOIN teams t ON cet.team_id = t.id
                WHERE ce.event_date >= CURRENT_DATE
                  AND (ce.status IS NULL OR ce.status != 'cancelled')
                ORDER BY ce.event_date ASC, ce.start_time ASC
                LIMIT :lim
            ";
        }
        $params['lim'] = (int) $limit;

        $stmt = $conn->prepare($query);
        $stmt->execute($params);
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'events' => $events]);

    } elseif ($method === 'GET' && $action === 'get') {
        // Get single event with RSVP status for parent's athletes
        $auth = AuthMiddleware::requireAuth();
        $requestingUserId = $auth->getUserId();

        $event_id = $_GET['id'] ?? null;
        if (!$event_id) {
            http_response_code(400);
            echo json_encode(['error' => 'Event ID is required']);
            exit;
        }

        // Get event details
        $stmt = $conn->prepare("
            SELECT
                ce.id, ce.name AS title, ce.type, ce.event_date AS date,
                ce.start_time, ce.end_time, ce.location, ce.description,
                ce.status
            FROM calendar_events ce
            WHERE ce.id = :event_id
        ");
        $stmt->execute(['event_id' => $event_id]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$event) {
            http_response_code(404);
            echo json_encode(['error' => 'Event not found']);
            exit;
        }

        // Get teams for this event
        $stmt = $conn->prepare("
            SELECT t.id, t.name
            FROM calendar_event_teams cet
            JOIN teams t ON cet.team_id = t.id
            WHERE cet.event_id = :event_id
        ");
        $stmt->execute(['event_id' => $event_id]);
        $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($teams)) {
            $event['team_id'] = $teams[0]['id'];
            $event['team_name'] = implode(', ', array_column($teams, 'name'));
        }

        // Get RSVP status for athletes on these teams
        $teamIds = array_column($teams, 'id');
        $athletes_rsvp = [];

        if (!empty($teamIds)) {
            // Privileged viewers (super admin, club admin, or coach of any of these teams)
            // see RSVPs for ALL athletes on the team. Everyone else is scoped to athletes
            // they're linked to as guardian via athlete_guardians.
            $isPrivileged = $auth->isSuperAdmin() || $auth->hasRole('club_admin');
            if (!$isPrivileged) {
                $coachStmt = $conn->prepare("
                    SELECT 1
                    FROM teams t
                    LEFT JOIN team_members tm
                      ON tm.team_id = t.id
                     AND tm.user_id = ?
                     AND tm.role IN ('assistant_coach', 'team_manager')
                    WHERE t.id IN (" . implode(',', array_fill(0, count($teamIds), '?')) . ")
                      AND (t.primary_coach_id = ? OR tm.id IS NOT NULL)
                    LIMIT 1
                ");
                $coachStmt->execute(array_merge([$requestingUserId], $teamIds, [$requestingUserId]));
                $isPrivileged = (bool) $coachStmt->fetchColumn();
            }

            $teamPlaceholders = implode(',', array_fill(0, count($teamIds), '?'));
            $sql = "
                SELECT DISTINCT
                    a.id AS athlete_id,
                    a.first_name || ' ' || a.last_name AS athlete_name,
                    cea.rsvp_status AS status
                FROM team_members tm
                JOIN athletes a ON tm.athlete_id = a.id
                LEFT JOIN athlete_guardians ag ON a.id = ag.athlete_id
                LEFT JOIN guardians g ON ag.guardian_id = g.id
                LEFT JOIN users u ON g.email = u.email
                LEFT JOIN calendar_event_attendees cea ON cea.event_id = ? AND cea.user_id = u.id
                WHERE tm.team_id IN ($teamPlaceholders)
                  AND tm.athlete_id IS NOT NULL
            ";
            $executeParams = array_merge([$event_id], $teamIds);

            if (!$isPrivileged) {
                // Restrict to athletes the requesting user is linked to as guardian
                $sql .= "
                  AND a.id IN (
                      SELECT ag2.athlete_id
                      FROM athlete_guardians ag2
                      JOIN guardians g2 ON ag2.guardian_id = g2.id
                      JOIN users u2 ON g2.email = u2.email
                      WHERE u2.id = ?
                  )";
                $executeParams[] = $requestingUserId;
            }

            $stmt = $conn->prepare($sql);
            $stmt->execute($executeParams);
            $athletes_rsvp = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Map rsvp_status values to frontend format
            foreach ($athletes_rsvp as &$ar) {
                if ($ar['status'] === 'accepted') $ar['status'] = 'attending';
                elseif ($ar['status'] === 'declined') $ar['status'] = 'not_attending';
                elseif ($ar['status'] === 'tentative') $ar['status'] = 'maybe';
            }
        }

        $event['athletes_rsvp'] = $athletes_rsvp;

        echo json_encode(['success' => true, 'event' => $event]);

    } elseif ($method === 'POST' && $action === 'rsvp') {
        // Save RSVP for an athlete (via their guardian's user record)
        $input = json_decode(file_get_contents('php://input'), true);
        $event_id = $input['event_id'] ?? null;
        $athlete_id = $input['athlete_id'] ?? null;
        $status = $input['status'] ?? null;

        if (!$event_id || !$athlete_id || !$status) {
            http_response_code(400);
            echo json_encode(['error' => 'event_id, athlete_id, and status are required']);
            exit;
        }

        // Map frontend status to calendar attendee status
        $statusMap = [
            'attending' => 'accepted',
            'not_attending' => 'declined',
            'maybe' => 'tentative'
        ];
        $dbStatus = $statusMap[$status] ?? $status;

        // Find the guardian's user_id for this athlete
        $stmt = $conn->prepare("
            SELECT u.id AS user_id, u.email
            FROM athletes a
            JOIN athlete_guardians ag ON a.id = ag.athlete_id
            JOIN guardians g ON ag.guardian_id = g.id
            JOIN users u ON g.email = u.email
            WHERE a.id = :athlete_id
            LIMIT 1
        ");
        $stmt->execute(['athlete_id' => $athlete_id]);
        $guardian = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$guardian) {
            http_response_code(400);
            echo json_encode(['error' => 'No linked guardian user found for this athlete']);
            exit;
        }

        // Upsert RSVP
        $stmt = $conn->prepare("
            INSERT INTO calendar_event_attendees (event_id, user_id, email, rsvp_status, responded_at, created_at)
            VALUES (:event_id, :user_id, :email, :status, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ON CONFLICT (event_id, user_id)
            DO UPDATE SET rsvp_status = :status, responded_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            'event_id' => $event_id,
            'user_id' => $guardian['user_id'],
            'email' => $guardian['email'],
            'status' => $dbStatus
        ]);

        echo json_encode(['success' => true, 'message' => 'RSVP updated']);

    } elseif ($method === 'POST' && $action === 'send-invite') {
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
