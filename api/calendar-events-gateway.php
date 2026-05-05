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
                ON CONFLICT (event_id, user_id) WHERE athlete_id IS NULL
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
        // Get upcoming events. For parents/players, scope to teams their own athletes
        // are on (no athlete_id = "all my athletes"). Admins/coaches see all events.
        $auth = AuthMiddleware::requireAuth();
        $requestingUserId = $auth->getUserId();
        $athlete_id = $_GET['athlete_id'] ?? null;
        $limit = $_GET['limit'] ?? 50;

        $isPrivileged = $auth->isSuperAdmin() || $auth->hasRole('club_admin');
        if (!$isPrivileged) {
            // Coach of any team in any club they have access to → privileged
            $coachCheck = $conn->prepare("
                SELECT 1 FROM teams t
                LEFT JOIN team_members tm
                  ON tm.team_id = t.id
                 AND tm.user_id = ?
                 AND tm.role IN ('assistant_coach','team_manager')
                 AND tm.status = 'active'
                WHERE (t.primary_coach_id = ? OR tm.id IS NOT NULL)
                LIMIT 1
            ");
            $coachCheck->execute([$requestingUserId, $requestingUserId]);
            $isPrivileged = (bool) $coachCheck->fetchColumn();
        }

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
        } elseif ($isPrivileged) {
            // Admin / coach: see all upcoming events
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
        } else {
            // Parent / player: scope to teams the requesting user's athletes are on.
            $query = "
                SELECT DISTINCT
                    ce.id, ce.name AS title, ce.type, ce.event_date AS date,
                    ce.start_time, ce.end_time, ce.location, ce.description,
                    ce.status,
                    t.id AS team_id, t.name AS team_name
                FROM calendar_events ce
                JOIN calendar_event_teams cet ON ce.id = cet.event_id
                JOIN teams t ON cet.team_id = t.id
                JOIN team_members tm ON tm.team_id = t.id AND tm.athlete_id IS NOT NULL
                JOIN athletes a ON a.id = tm.athlete_id
                JOIN athlete_guardians ag ON ag.athlete_id = a.id
                JOIN guardians g ON g.id = ag.guardian_id
                JOIN users u ON u.email = g.email
                WHERE u.id = :requesting_user_id
                  AND ce.event_date >= CURRENT_DATE
                  AND (ce.status IS NULL OR ce.status != 'cancelled')
                ORDER BY ce.event_date ASC, ce.start_time ASC
                LIMIT :lim
            ";
            $params['requesting_user_id'] = $requestingUserId;
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
            // For non-privileged viewers, scope the cea match to the requesting user's
            // own RSVP rows. For admins/coaches, return any RSVP attached to the athlete
            // (the most recent one, in case multiple guardians have RSVPed).
            if ($isPrivileged) {
                $sql = "
                    SELECT
                        a.id AS athlete_id,
                        a.first_name || ' ' || a.last_name AS athlete_name,
                        (
                            SELECT cea.rsvp_status
                            FROM calendar_event_attendees cea
                            WHERE cea.event_id = ?
                              AND cea.athlete_id = a.id
                            ORDER BY cea.responded_at DESC NULLS LAST
                            LIMIT 1
                        ) AS status
                    FROM team_members tm
                    JOIN athletes a ON tm.athlete_id = a.id
                    WHERE tm.team_id IN ($teamPlaceholders)
                      AND tm.athlete_id IS NOT NULL
                    GROUP BY a.id, a.first_name, a.last_name
                ";
                $executeParams = array_merge([$event_id], $teamIds);
            } else {
                $sql = "
                    SELECT DISTINCT
                        a.id AS athlete_id,
                        a.first_name || ' ' || a.last_name AS athlete_name,
                        cea.rsvp_status AS status
                    FROM team_members tm
                    JOIN athletes a ON tm.athlete_id = a.id
                    LEFT JOIN calendar_event_attendees cea
                        ON cea.event_id = ?
                        AND cea.user_id = ?
                        AND cea.athlete_id = a.id
                    WHERE tm.team_id IN ($teamPlaceholders)
                      AND tm.athlete_id IS NOT NULL
                      AND a.id IN (
                          SELECT ag2.athlete_id
                          FROM athlete_guardians ag2
                          JOIN guardians g2 ON ag2.guardian_id = g2.id
                          JOIN users u2 ON g2.email = u2.email
                          WHERE u2.id = ?
                      )
                ";
                $executeParams = array_merge([$event_id, $requestingUserId], $teamIds, [$requestingUserId]);
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
        // Save RSVP for an athlete under the authenticated user's record.
        $auth = AuthMiddleware::requireAuth();
        $userId = $auth->getUserId();

        $input = json_decode(file_get_contents('php://input'), true);
        $event_id = $input['event_id'] ?? null;
        $athlete_id = $input['athlete_id'] ?? null;
        $status = $input['status'] ?? null;

        if (!$event_id || !$athlete_id || !$status) {
            http_response_code(400);
            echo json_encode(['error' => 'event_id, athlete_id, and status are required']);
            exit;
        }

        // Verify the requesting user is a guardian of this athlete (or admin/coach).
        // For this version, parents/players must be linked via athlete_guardians;
        // privileged roles are handled by the broader admin RSVP path elsewhere.
        $stmt = $conn->prepare("
            SELECT u.email
            FROM users u
            WHERE u.id = :uid
              AND EXISTS (
                  SELECT 1
                  FROM guardians g
                  JOIN athlete_guardians ag ON ag.guardian_id = g.id
                  WHERE g.email = u.email AND ag.athlete_id = :aid
              )
        ");
        $stmt->execute(['uid' => $userId, 'aid' => $athlete_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            http_response_code(403);
            echo json_encode(['error' => 'Not authorized to RSVP for this athlete']);
            exit;
        }

        // Map frontend status to calendar attendee status
        $statusMap = [
            'attending' => 'accepted',
            'not_attending' => 'declined',
            'maybe' => 'tentative'
        ];
        $dbStatus = $statusMap[$status] ?? $status;

        // Upsert RSVP — keyed on (event_id, user_id, athlete_id) so each guardian
        // tracks their own RSVP for each of their athletes.
        $stmt = $conn->prepare("
            INSERT INTO calendar_event_attendees (event_id, user_id, athlete_id, email, rsvp_status, responded_at, created_at)
            VALUES (:event_id, :user_id, :athlete_id, :email, :status, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ON CONFLICT (event_id, user_id, athlete_id) WHERE athlete_id IS NOT NULL
            DO UPDATE SET rsvp_status = :status, responded_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            'event_id' => $event_id,
            'user_id' => $userId,
            'athlete_id' => $athlete_id,
            'email' => $row['email'],
            'status' => $dbStatus
        ]);

        echo json_encode(['success' => true, 'message' => 'RSVP updated']);

    } elseif ($method === 'POST' && $action === 'send-invite') {
        $input = json_decode(file_get_contents('php://input'), true);
        $response = handleSendCalendarInvite($conn, $input);
        echo json_encode($response);

    } elseif ($method === 'POST' && $action === 'send-rsvp-reminders') {
        $auth = AuthMiddleware::requireAuth();
        $input = json_decode(file_get_contents('php://input'), true);
        $event_id = $input['event_id'] ?? null;

        if (!$event_id) {
            http_response_code(400);
            echo json_encode(['error' => 'event_id is required']);
            exit;
        }

        // Load event + linked teams (need club_id for permission + email scoping)
        $stmt = $conn->prepare("
            SELECT
                ce.id, ce.name, ce.event_date, ce.start_time, ce.end_time, ce.location,
                ce.type, t.id AS team_id, t.club_id, t.primary_coach_id
            FROM calendar_events ce
            JOIN calendar_event_teams cet ON ce.id = cet.event_id
            JOIN teams t ON cet.team_id = t.id
            WHERE ce.id = ?
        ");
        $stmt->execute([$event_id]);
        $teamRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($teamRows)) {
            http_response_code(404);
            echo json_encode(['error' => 'Event not found or has no teams']);
            exit;
        }

        $event = $teamRows[0];
        $teamIds = array_unique(array_column($teamRows, 'team_id'));
        $clubId = $event['club_id'];

        // Permission check: super admin, club admin of this club, or coach/manager of one of the teams
        $userId = $auth->getUserId();
        $isPrivileged = $auth->isSuperAdmin() || $auth->hasRole('club_admin', $clubId, 'club');

        if (!$isPrivileged) {
            $teamPlaceholders = implode(',', array_fill(0, count($teamIds), '?'));
            $coachStmt = $conn->prepare("
                SELECT 1
                FROM teams t
                LEFT JOIN team_members tm
                  ON tm.team_id = t.id
                 AND tm.user_id = ?
                 AND tm.role IN ('assistant_coach', 'team_manager')
                WHERE t.id IN ($teamPlaceholders)
                  AND (t.primary_coach_id = ? OR tm.id IS NOT NULL)
                LIMIT 1
            ");
            $coachStmt->execute(array_merge([$userId], $teamIds, [$userId]));
            $isPrivileged = (bool) $coachStmt->fetchColumn();
        }

        if (!$isPrivileged) {
            http_response_code(403);
            echo json_encode(['error' => 'Not authorized to send reminders for this event']);
            exit;
        }

        // Find non-respondents: athletes on these teams whose linked guardian-user
        // has NOT submitted an actual RSVP (accepted/declined/tentative) for this athlete.
        // A row in calendar_event_attendees with status 'pending' or NULL counts as not responded.
        $teamPlaceholders = implode(',', array_fill(0, count($teamIds), '?'));
        $sql = "
            SELECT DISTINCT
                a.id AS athlete_id,
                a.first_name AS athlete_first_name,
                a.last_name AS athlete_last_name,
                u.id AS user_id,
                u.first_name AS guardian_first_name,
                u.last_name AS guardian_last_name,
                u.email AS guardian_email
            FROM team_members tm
            JOIN athletes a ON a.id = tm.athlete_id
            JOIN athlete_guardians ag ON ag.athlete_id = a.id
            JOIN guardians g ON g.id = ag.guardian_id
            JOIN users u ON u.email = g.email
            LEFT JOIN calendar_event_attendees cea
                ON cea.event_id = ?
                AND cea.user_id = u.id
                AND cea.athlete_id = a.id
            WHERE tm.team_id IN ($teamPlaceholders)
              AND tm.athlete_id IS NOT NULL
              AND u.email IS NOT NULL
              AND u.email <> ''
              AND (cea.id IS NULL OR cea.rsvp_status IS NULL OR cea.rsvp_status = 'pending')
        ";
        $stmt = $conn->prepare($sql);
        $stmt->execute(array_merge([$event_id], $teamIds));
        $nonRespondents = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($nonRespondents)) {
            echo json_encode([
                'success' => true,
                'reminders_sent' => 0,
                'message' => 'All athletes have already responded'
            ]);
            exit;
        }

        // Build email per (guardian, athlete) pair
        require_once __DIR__ . '/../services/EmailSendService.php';
        require_once __DIR__ . '/../config/env.php';
        $emailService = new EmailSendService($conn);
        $appUrl = Env::get('APP_URL', 'https://teamselevated.netlify.app');

        $rsvpUrl = rtrim($appUrl, '/') . '/parent/schedule/rsvp/' . (int) $event_id;
        $eventDateLabel = date('l, F j, Y', strtotime($event['event_date']));
        $eventTimeLabel = $event['start_time']
            ? date('g:i A', strtotime($event['start_time']))
            : '';
        $locationLabel = $event['location'] ? ' at ' . htmlspecialchars($event['location']) : '';

        $recipients = [];
        foreach ($nonRespondents as $row) {
            $recipients[] = [
                'email' => $row['guardian_email'],
                'name' => trim($row['guardian_first_name'] . ' ' . $row['guardian_last_name']),
                'type' => 'guardian',
                'id' => (int) $row['user_id'],
                'athlete_id' => (int) $row['athlete_id'],
                // merge fields for per-recipient personalization
                '_athlete_first_name' => $row['athlete_first_name'],
                '_guardian_first_name' => $row['guardian_first_name'],
            ];
        }

        // Use a single subject/body; merge {{guardian_first_name}} and {{athlete_first_name}}
        // if MergeFieldService picks them up. EmailSendService doesn't support per-recipient
        // body templating today, so we send a generic body that references "your athlete".
        $subject = 'RSVP Reminder: ' . $event['name'] . ' on ' . $eventDateLabel;

        $eventTimeBlock = $eventTimeLabel
            ? '<p style="margin: 4px 0;"><strong>Time:</strong> ' . htmlspecialchars($eventTimeLabel) . '</p>'
            : '';
        $locationBlock = $event['location']
            ? '<p style="margin: 4px 0;"><strong>Location:</strong> ' . htmlspecialchars($event['location']) . '</p>'
            : '';

        $htmlBody = '
            <div style="font-family: Arial, sans-serif; max-width: 560px; margin: 0 auto; padding: 24px; color: #1f2937;">
                <h2 style="color: #1f2937; margin-top: 0;">RSVP Reminder</h2>
                <p>Hi,</p>
                <p>This is a reminder that we have not yet received an RSVP for one of your athletes for the event below.</p>
                <div style="background: #f3f4f6; border-radius: 8px; padding: 16px; margin: 16px 0;">
                    <p style="margin: 4px 0;"><strong>Event:</strong> ' . htmlspecialchars($event['name']) . '</p>
                    <p style="margin: 4px 0;"><strong>Date:</strong> ' . htmlspecialchars($eventDateLabel) . '</p>
                    ' . $eventTimeBlock . $locationBlock . '
                </div>
                <p style="margin: 24px 0;">
                    <a href="' . htmlspecialchars($rsvpUrl) . '"
                       style="display: inline-block; background: #1f2937; color: #ffffff; padding: 12px 24px; border-radius: 6px; text-decoration: none; font-weight: 600;">
                        RSVP Now
                    </a>
                </p>
                <p style="color: #6b7280; font-size: 14px;">
                    Or paste this link into your browser: ' . htmlspecialchars($rsvpUrl) . '
                </p>
            </div>
        ';

        $textBody = "RSVP Reminder\n\n"
            . "We have not yet received an RSVP for one of your athletes for the event below.\n\n"
            . "Event: {$event['name']}\n"
            . "Date: {$eventDateLabel}\n"
            . ($eventTimeLabel ? "Time: {$eventTimeLabel}\n" : '')
            . ($event['location'] ? "Location: {$event['location']}\n" : '')
            . "\nRSVP here: {$rsvpUrl}\n";

        $result = $emailService->queueEmail([
            'user_id'         => $userId,
            'club_profile_id' => $clubId,
            'recipients'      => $recipients,
            'subject'         => $subject,
            'html_body'       => $htmlBody,
            'body'            => $textBody,
            'event_id'        => (int) $event_id,
        ]);

        echo json_encode([
            'success' => true,
            'reminders_sent' => $result['queued'] ?? 0,
            'skipped' => $result['skipped'] ?? 0,
            'skipped_details' => $result['skipped_details'] ?? [],
            'non_respondents_total' => count($nonRespondents),
        ]);

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
