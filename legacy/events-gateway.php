<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Include service classes
require_once __DIR__ . '/../services/CalendarInviteService.php';
require_once __DIR__ . '/../services/RecipientService.php';

// Use centralized database connection
require_once __DIR__ . '/../config/database.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            if (isset($_GET['id'])) {
                // Get specific event
                $stmt = $pdo->prepare("
                    SELECT e.*,
                           t.name as team_name,
                           p.name as program_name,
                           v.name as venue_name,
                           v.address as venue_address
                    FROM calendar_events e
                    LEFT JOIN teams t ON e.team_id = t.id
                    LEFT JOIN programs p ON e.program_id = p.id
                    LEFT JOIN venues v ON e.venue_id = v.id
                    WHERE e.id = ?
                ");
                $stmt->execute([$_GET['id']]);
                $event = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($event) {
                    // Get teams for this event
                    $teamStmt = $pdo->prepare("
                        SELECT t.id, t.name, t.team_color as primary_color
                        FROM calendar_event_teams et
                        JOIN teams t ON et.team_id = t.id
                        WHERE et.event_id = ?
                    ");
                    $teamStmt->execute([$_GET['id']]);
                    $event['teams'] = $teamStmt->fetchAll(PDO::FETCH_ASSOC);

                    // Create comma-separated team names for backward compatibility
                    $teamNames = array_column($event['teams'], 'name');
                    $event['team_name'] = implode(', ', $teamNames);
                }

                echo json_encode($event);
            } else {
                // Get all events with filters
                $whereClause = "WHERE 1=1";
                $params = [];

                // Filter by date range
                if (isset($_GET['start_date'])) {
                    $whereClause .= " AND e.event_date >= ?";
                    $params[] = $_GET['start_date'];
                }
                if (isset($_GET['end_date'])) {
                    $whereClause .= " AND e.event_date <= ?";
                    $params[] = $_GET['end_date'];
                }

                // Filter by team
                if (isset($_GET['team_id'])) {
                    $whereClause .= " AND e.team_id = ?";
                    $params[] = $_GET['team_id'];
                }

                // Filter by program
                if (isset($_GET['program_id'])) {
                    $whereClause .= " AND e.program_id = ?";
                    $params[] = $_GET['program_id'];
                }

                // Filter by type
                if (isset($_GET['type'])) {
                    $whereClause .= " AND e.type = ?";
                    $params[] = $_GET['type'];
                }

                // Filter by status
                if (isset($_GET['status'])) {
                    $whereClause .= " AND e.status = ?";
                    $params[] = $_GET['status'];
                }

                $stmt = $pdo->prepare("
                    SELECT DISTINCT e.*,
                           p.name as program_name,
                           v.name as venue_name
                    FROM calendar_events e
                    LEFT JOIN programs p ON e.program_id = p.id
                    LEFT JOIN venues v ON e.venue_id = v.id
                    LEFT JOIN calendar_event_teams et ON e.id = et.event_id
                    LEFT JOIN teams t ON et.team_id = t.id
                    $whereClause
                    ORDER BY e.event_date ASC, e.start_time ASC
                ");
                $stmt->execute($params);
                $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Enrich events with team information
                foreach ($events as &$event) {
                    $teamStmt = $pdo->prepare("
                        SELECT t.id, t.name, t.team_color as primary_color
                        FROM calendar_event_teams et
                        JOIN teams t ON et.team_id = t.id
                        WHERE et.event_id = ?
                    ");
                    $teamStmt->execute([$event['id']]);
                    $event['teams'] = $teamStmt->fetchAll(PDO::FETCH_ASSOC);

                    // Create comma-separated team names and get primary color
                    $teamNames = array_column($event['teams'], 'name');
                    $event['team_name'] = implode(', ', $teamNames);
                    $event['team_color'] = !empty($event['teams']) ? $event['teams'][0]['primary_color'] : null;
                }

                echo json_encode(['success' => true, 'events' => $events]);
            }
            break;

        case 'POST':
            $data = json_decode(file_get_contents('php://input'), true);

            $pdo->beginTransaction();

            try {
                $stmt = $pdo->prepare("
                    INSERT INTO calendar_events (
                        club_id, name, type, event_date, start_time, end_time,
                        program_id, venue_id, location, description, status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $data['club_id'] ?? 1,
                    $data['name'],
                    $data['type'] ?? 'event',
                    $data['event_date'],
                    $data['start_time'] ?? null,
                    $data['end_time'] ?? null,
                    $data['program_id'] ?? null,
                    $data['venue_id'] ?? null,
                    $data['location'] ?? null,
                    $data['description'] ?? null,
                    $data['status'] ?? 'scheduled'
                ]);

                $eventId = $pdo->lastInsertId();

                // Insert team associations
                if (!empty($data['team_ids']) && is_array($data['team_ids'])) {
                    $teamStmt = $pdo->prepare("INSERT INTO calendar_event_teams (event_id, team_id) VALUES (?, ?)");
                    foreach ($data['team_ids'] as $teamId) {
                        if ($teamId) {
                            $teamStmt->execute([$eventId, $teamId]);
                        }
                    }
                }

                $pdo->commit();

                // Send calendar invites if requested
                $inviteResults = null;
                if (isset($data['send_invites']) && $data['send_invites'] === true && !empty($data['team_ids'])) {
                    try {
                        // Get full event details including venue
                        $eventStmt = $pdo->prepare("
                            SELECT e.*, v.name as venue_name, v.address as venue_address,
                                   GROUP_CONCAT(t.name SEPARATOR ', ') as team_names
                            FROM calendar_events e
                            LEFT JOIN venues v ON e.venue_id = v.id
                            LEFT JOIN calendar_event_teams et ON e.id = et.event_id
                            LEFT JOIN teams t ON et.team_id = t.id
                            WHERE e.id = ?
                            GROUP BY e.id
                        ");
                        $eventStmt->execute([$eventId]);
                        $fullEvent = $eventStmt->fetch(PDO::FETCH_ASSOC);

                        // Get recipients
                        $recipientService = new RecipientService($pdo);
                        $recipients = $recipientService->getEventRecipients($eventId, $data['team_ids']);

                        // Send invites (check for test mode)
                        if (count($recipients) > 0) {
                            $testMode = file_exists(__DIR__ . '/.env.test') || getenv('APP_ENV') === 'test';
                            $inviteService = new CalendarInviteService($pdo, $testMode);
                            $inviteResults = $inviteService->sendEventInvites($fullEvent, $recipients);
                        } else {
                            $inviteResults = ['sent' => 0, 'failed' => 0, 'message' => 'No recipients with valid emails found'];
                        }
                    } catch (Exception $inviteError) {
                        // Log error but don't fail the event creation
                        error_log("Failed to send invites for event {$eventId}: " . $inviteError->getMessage());
                        $inviteResults = ['error' => 'Failed to send invites: ' . $inviteError->getMessage()];
                    }
                }

                $response = [
                    'success' => true,
                    'id' => $eventId,
                    'message' => 'Event created successfully'
                ];

                if ($inviteResults) {
                    $response['invites'] = $inviteResults;
                }

                echo json_encode($response);
            } catch (Exception $e) {
                $pdo->rollback();
                throw $e;
            }
            break;

        case 'PUT':
            if (!isset($_GET['id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Event ID required']);
                exit;
            }

            $data = json_decode(file_get_contents('php://input'), true);

            // Get original event details for comparison
            $originalStmt = $pdo->prepare("
                SELECT * FROM calendar_events WHERE id = ?
            ");
            $originalStmt->execute([$_GET['id']]);
            $originalEvent = $originalStmt->fetch(PDO::FETCH_ASSOC);

            $pdo->beginTransaction();

            try {
                $stmt = $pdo->prepare("
                    UPDATE calendar_events SET
                        name = ?,
                        type = ?,
                        event_date = ?,
                        start_time = ?,
                        end_time = ?,
                        program_id = ?,
                        venue_id = ?,
                        location = ?,
                        description = ?,
                        status = ?
                    WHERE id = ?
                ");

                $stmt->execute([
                    $data['name'],
                    $data['type'] ?? 'event',
                    $data['event_date'],
                    $data['start_time'] ?? null,
                    $data['end_time'] ?? null,
                    $data['program_id'] ?? null,
                    $data['venue_id'] ?? null,
                    $data['location'] ?? null,
                    $data['description'] ?? null,
                    $data['status'] ?? 'scheduled',
                    $_GET['id']
                ]);

                // Delete existing team associations
                $deleteTeamStmt = $pdo->prepare("DELETE FROM calendar_event_teams WHERE event_id = ?");
                $deleteTeamStmt->execute([$_GET['id']]);

                // Insert new team associations
                if (!empty($data['team_ids']) && is_array($data['team_ids'])) {
                    $teamStmt = $pdo->prepare("INSERT INTO calendar_event_teams (event_id, team_id) VALUES (?, ?)");
                    foreach ($data['team_ids'] as $teamId) {
                        if ($teamId) {
                            $teamStmt->execute([$_GET['id'], $teamId]);
                        }
                    }
                }

                $pdo->commit();

                // Send update invites if requested and significant changes were made
                $updateResults = null;
                if (isset($data['send_updates']) && $data['send_updates'] === true) {
                    // Check for significant changes
                    $significantChange = (
                        $originalEvent['event_date'] != $data['event_date'] ||
                        $originalEvent['start_time'] != $data['start_time'] ||
                        $originalEvent['end_time'] != $data['end_time'] ||
                        $originalEvent['venue_id'] != ($data['venue_id'] ?? null) ||
                        $originalEvent['location'] != ($data['location'] ?? null) ||
                        $originalEvent['status'] != ($data['status'] ?? 'scheduled')
                    );

                    if ($significantChange) {
                        try {
                            // Handle cancellation separately
                            if ($data['status'] === 'cancelled') {
                                $testMode = file_exists(__DIR__ . '/.env.test') || getenv('APP_ENV') === 'test';
                                $inviteService = new CalendarInviteService($pdo, $testMode);
                                $inviteService->sendEventCancellation($_GET['id']);
                                $updateResults = ['message' => 'Cancellation notices sent'];
                            } else {
                                // Get existing invitations
                                $inviteStmt = $pdo->prepare("
                                    SELECT * FROM event_invitations
                                    WHERE event_id = ? AND status != 'cancelled'
                                ");
                                $inviteStmt->execute([$_GET['id']]);
                                $existingInvites = $inviteStmt->fetchAll(PDO::FETCH_ASSOC);

                                if (count($existingInvites) > 0) {
                                    // Get updated event details
                                    $eventStmt = $pdo->prepare("
                                        SELECT e.*, v.name as venue_name, v.address as venue_address,
                                               GROUP_CONCAT(t.name SEPARATOR ', ') as team_names
                                        FROM calendar_events e
                                        LEFT JOIN venues v ON e.venue_id = v.id
                                        LEFT JOIN calendar_event_teams et ON e.id = et.event_id
                                        LEFT JOIN teams t ON et.team_id = t.id
                                        WHERE e.id = ?
                                        GROUP BY e.id
                                    ");
                                    $eventStmt->execute([$_GET['id']]);
                                    $updatedEvent = $eventStmt->fetch(PDO::FETCH_ASSOC);

                                    // Send updates
                                    $testMode = file_exists(__DIR__ . '/.env.test') || getenv('APP_ENV') === 'test';
                                    $inviteService = new CalendarInviteService($pdo, $testMode);
                                    $updateCount = 0;
                                    foreach ($existingInvites as $invite) {
                                        $recipient = [
                                            'email' => $invite['recipient_email'],
                                            'name' => $invite['recipient_name'],
                                            'type' => $invite['recipient_type'],
                                            'id' => $invite['recipient_id']
                                        ];
                                        try {
                                            $inviteService->sendEventUpdate($updatedEvent, $recipient, $invite);
                                            $updateCount++;
                                        } catch (Exception $e) {
                                            error_log("Failed to send update to {$invite['recipient_email']}: " . $e->getMessage());
                                        }
                                    }
                                    $updateResults = ['updated' => $updateCount];
                                }
                            }
                        } catch (Exception $updateError) {
                            error_log("Failed to send updates for event {$_GET['id']}: " . $updateError->getMessage());
                            $updateResults = ['error' => 'Failed to send updates: ' . $updateError->getMessage()];
                        }
                    }
                }

                $response = [
                    'success' => true,
                    'message' => 'Event updated successfully'
                ];

                if ($updateResults) {
                    $response['updates'] = $updateResults;
                }

                echo json_encode($response);
            } catch (Exception $e) {
                $pdo->rollback();
                throw $e;
            }
            break;

        case 'DELETE':
            if (!isset($_GET['id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Event ID required']);
                exit;
            }

            // Send cancellation notices before deleting
            try {
                $testMode = file_exists(__DIR__ . '/.env.test') || getenv('APP_ENV') === 'test';
                $inviteService = new CalendarInviteService($pdo, $testMode);
                $inviteService->sendEventCancellation($_GET['id']);
            } catch (Exception $e) {
                error_log("Failed to send cancellation notices for event {$_GET['id']}: " . $e->getMessage());
            }

            $stmt = $pdo->prepare("DELETE FROM calendar_events WHERE id = ?");
            $stmt->execute([$_GET['id']]);

            echo json_encode([
                'success' => true,
                'message' => 'Event deleted and cancellation notices sent'
            ]);
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch(Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>