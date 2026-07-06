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
require_once __DIR__ . '/../lib/event_recurrence.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/AthleteScope.php';

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

// Events (and the invite emails they trigger) are tenant data: every method
// requires a valid token. Reads are open to any authenticated club user
// (admins, coaches, parents all see the calendar); writes additionally
// require admin/coach standing — see te_can_manage_events().
$auth = AuthMiddleware::requireAuth();

/**
 * May this user create/update/delete calendar events?
 * super_admin, any club_admin, or a coach of any team. Parents/players: no.
 * (Same standing rule as athlete creation in legacy/athletes-gateway.php.)
 */
function te_can_manage_events(PDO $pdo, AuthMiddleware $auth): bool {
    if ($auth->isSuperAdmin()) {
        return true;
    }
    if (!empty(AthleteScope::clubAdminClubIds($auth))) {
        return true;
    }
    $uid = (int) $auth->getUserId();
    return $uid > 0 && !empty(AthleteScope::coachTeamIdsForUser($pdo, $uid));
}

/**
 * Resolve the club a new event belongs to (events created by the UI never
 * carried a club_id and fell back to the hardcoded default). Explicit club_id
 * the requester may use → their sole admin club → the sole club of the teams
 * they coach → legacy default.
 */
function te_resolve_event_club_id(PDO $pdo, AuthMiddleware $auth, $requested): int {
    $requested = is_numeric($requested) ? (int) $requested : null;

    if ($auth->isSuperAdmin() && $requested !== null) {
        return $requested;
    }

    $adminClubIds = AthleteScope::clubAdminClubIds($auth);
    if ($requested !== null && in_array($requested, $adminClubIds, true)) {
        return $requested;
    }
    if (count($adminClubIds) === 1) {
        return $adminClubIds[0];
    }

    $uid = (int) $auth->getUserId();
    if ($uid > 0) {
        $teamIds = AthleteScope::coachTeamIdsForUser($pdo, $uid);
        if (!empty($teamIds)) {
            $ph = implode(',', array_fill(0, count($teamIds), '?'));
            $stmt = $pdo->prepare("SELECT DISTINCT club_id FROM teams WHERE id IN ($ph) AND club_id IS NOT NULL");
            $stmt->execute(array_values($teamIds));
            $clubs = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (count($clubs) === 1) {
                return (int) $clubs[0];
            }
        }
    }

    return $requested ?? 1;
}

$method = $_SERVER['REQUEST_METHOD'];

// Enforce write standing once, up front.
if (in_array($method, ['POST', 'PUT', 'DELETE'], true) && !te_can_manage_events($pdo, $auth)) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

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

            // Recurring events: expand the rule into concrete dates and insert
            // one calendar_events row per occurrence (materialized — RSVP,
            // invites, and attendance all stay per-event). A shared
            // recurrence_group_id ties the series together.
            $occurrenceDates = [$data['event_date']];
            $recurrenceGroupId = null;
            $recurrenceRule = null;
            if (!empty($data['recurrence']) && is_array($data['recurrence'])) {
                $occurrenceDates = te_expand_recurrence($data['event_date'], $data['recurrence']);
                if (empty($occurrenceDates)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'The repeat settings produce no event dates']);
                    exit;
                }
                if (count($occurrenceDates) > 1) {
                    $recurrenceGroupId = bin2hex(random_bytes(16));
                    $recurrenceRule = te_recurrence_label($data['event_date'], $data['recurrence'], count($occurrenceDates));
                }
            }

            $pdo->beginTransaction();

            try {
                $stmt = $pdo->prepare("
                    INSERT INTO calendar_events (
                        club_id, name, type, event_date, start_time, end_time,
                        program_id, venue_id, location, description, status, opponent_name,
                        recurrence_group_id, recurrence_rule, series_original_date, series_original_time
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $teamStmt = $pdo->prepare("INSERT INTO calendar_event_teams (event_id, team_id) VALUES (?, ?)");

                $eventClubId = te_resolve_event_club_id($pdo, $auth, $data['club_id'] ?? null);

                $eventId = null; // first occurrence id (kept for invites + response)
                foreach ($occurrenceDates as $occurrenceDate) {
                    $stmt->execute([
                        $eventClubId,
                        $data['name'],
                        $data['type'] ?? 'event',
                        $occurrenceDate,
                        $data['start_time'] ?? null,
                        $data['end_time'] ?? null,
                        $data['program_id'] ?? null,
                        $data['venue_id'] ?? null,
                        $data['location'] ?? null,
                        $data['description'] ?? null,
                        $data['status'] ?? 'scheduled',
                        $data['opponent_name'] ?? null,
                        $recurrenceGroupId,
                        $recurrenceRule,
                        // Original slot as the rule produced it — immutable
                        // even if the occurrence is later edited (Phase 2
                        // RECURRENCE-ID exceptions reference it).
                        $recurrenceGroupId !== null ? $occurrenceDate : null,
                        $recurrenceGroupId !== null ? ($data['start_time'] ?? null) : null
                    ]);

                    $occurrenceId = $pdo->lastInsertId();
                    if ($eventId === null) {
                        $eventId = $occurrenceId;
                    }

                    // Insert team associations
                    if (!empty($data['team_ids']) && is_array($data['team_ids'])) {
                        foreach ($data['team_ids'] as $teamId) {
                            if ($teamId) {
                                $teamStmt->execute([$occurrenceId, $teamId]);
                            }
                        }
                    }
                }

                // Series identity: one iCal UID + the RRULE for the whole
                // series. Only ship an RRULE that round-trips to exactly the
                // dates we materialized — otherwise recipients' calendars
                // would show a different pattern than our system.
                $seriesRrule = null;
                if ($recurrenceGroupId !== null) {
                    $candidate = te_recurrence_rrule($data['event_date'], $data['recurrence'], count($occurrenceDates));
                    if ($candidate !== null && te_expand_rrule($data['event_date'], $candidate) === $occurrenceDates) {
                        $seriesRrule = $candidate;
                    } else {
                        error_log("Series {$recurrenceGroupId}: RRULE round-trip mismatch — invites disabled for this series");
                    }
                    $seriesUid = "series-{$recurrenceGroupId}@teamselevated.com";
                    $seriesStmt = $pdo->prepare("
                        INSERT INTO calendar_event_series (group_id, calendar_uid, rrule)
                        VALUES (?, ?, ?)
                    ");
                    $seriesStmt->execute([$recurrenceGroupId, $seriesUid, $seriesRrule]);
                }

                $pdo->commit();

                // Series invites: ONE email per recipient carrying the RRULE
                // (the whole schedule as a single recurring calendar event).
                // Skipped when the RRULE failed its round-trip check.
                $seriesInviteResults = null;
                if ($recurrenceGroupId !== null && !empty($data['send_invites']) && !empty($data['team_ids'])) {
                    $data['send_invites'] = false; // never fall through to per-event invites
                    if ($seriesRrule !== null) {
                        try {
                            $eventStmt = $pdo->prepare("
                                SELECT e.*, v.name as venue_name, v.address as venue_address,
                                       STRING_AGG(t.name, ', ') as team_names
                                FROM calendar_events e
                                LEFT JOIN venues v ON e.venue_id = v.id
                                LEFT JOIN calendar_event_teams et ON e.id = et.event_id
                                LEFT JOIN teams t ON et.team_id = t.id
                                WHERE e.id = ?
                                GROUP BY e.id
                            ");
                            $eventStmt->execute([$eventId]);
                            $firstEvent = $eventStmt->fetch(PDO::FETCH_ASSOC);

                            $recipientService = new RecipientService($pdo);
                            $recipients = $recipientService->getEventRecipients($eventId, $data['team_ids']);

                            if (count($recipients) > 0) {
                                $testMode = file_exists(__DIR__ . '/.env.test') || getenv('APP_ENV') === 'test';
                                $inviteService = new CalendarInviteService($pdo, $testMode);
                                $seriesInviteResults = $inviteService->sendSeriesInvites($firstEvent, $recipients, [
                                    'group_id' => $recurrenceGroupId,
                                    'calendar_uid' => $seriesUid,
                                    'rrule' => $seriesRrule,
                                    'dates' => $occurrenceDates,
                                    'label' => $recurrenceRule,
                                ]);
                                $pdo->prepare("UPDATE calendar_event_series SET invites_sent = TRUE WHERE group_id = ?")
                                    ->execute([$recurrenceGroupId]);
                            } else {
                                $seriesInviteResults = ['sent' => 0, 'failed' => 0, 'message' => 'No recipients with valid emails found'];
                            }
                        } catch (Exception $inviteError) {
                            error_log("Failed to send series invites for group {$recurrenceGroupId}: " . $inviteError->getMessage());
                            $seriesInviteResults = ['error' => 'Failed to send invites: ' . $inviteError->getMessage()];
                        }
                    } else {
                        $seriesInviteResults = ['sent' => 0, 'message' => 'Invites unavailable for this repeat pattern'];
                    }
                }

                // Send calendar invites if requested
                $inviteResults = null;
                if (isset($data['send_invites']) && $data['send_invites'] === true && !empty($data['team_ids'])) {
                    try {
                        // Get full event details including venue
                        $eventStmt = $pdo->prepare("
                            SELECT e.*, v.name as venue_name, v.address as venue_address,
                                   STRING_AGG(t.name, ', ') as team_names
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
                    'count' => count($occurrenceDates),
                    'message' => count($occurrenceDates) > 1
                        ? 'Created ' . count($occurrenceDates) . ' events (' . $recurrenceRule . ')'
                        : 'Event created successfully'
                ];
                if ($recurrenceGroupId !== null) {
                    $response['recurrence_group_id'] = $recurrenceGroupId;
                }
                if ($seriesInviteResults) {
                    $response['invites'] = $seriesInviteResults;
                }

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
                        status = ?,
                        opponent_name = ?
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
                    $data['opponent_name'] ?? null,
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

                    if ($significantChange && !empty($originalEvent['recurrence_group_id'])) {
                        // Series occurrence: recipients hold ONE recurring
                        // calendar event under the series UID. A per-event ICS
                        // update/cancel would rewrite or remove their WHOLE
                        // series, so send a plain notification email instead.
                        // (Phase 2: RECURRENCE-ID exceptions.)
                        try {
                            $testMode = file_exists(__DIR__ . '/.env.test') || getenv('APP_ENV') === 'test';
                            $inviteService = new CalendarInviteService($pdo, $testMode);
                            $oldDate = date('l, F j', strtotime($originalEvent['event_date']));
                            $newDate = date('l, F j', strtotime($data['event_date']));
                            $newTime = !empty($data['start_time']) ? ' at ' . date('g:i A', strtotime($data['start_time'])) : '';
                            $detail = ($data['status'] ?? 'scheduled') === 'cancelled'
                                ? "<p><strong>{$data['name']}</strong> on {$oldDate} has been <strong>cancelled</strong>. Other dates in the series are unchanged.</p>"
                                : "<p><strong>{$data['name']}</strong> originally on {$oldDate} is now <strong>{$newDate}{$newTime}</strong>. Other dates in the series are unchanged.</p>";
                            $noticeResult = $inviteService->sendSeriesChangeNotice(
                                $originalEvent['recurrence_group_id'],
                                "Schedule change: {$data['name']}",
                                $detail
                            );
                            $updateResults = ['updated' => $noticeResult['sent'], 'message' => 'Change notices sent (series)'];
                        } catch (Exception $updateError) {
                            error_log("Failed to send series change notice for event {$_GET['id']}: " . $updateError->getMessage());
                            $updateResults = ['error' => 'Failed to send change notices'];
                        }
                    } elseif ($significantChange) {
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
                                               STRING_AGG(t.name, ', ') as team_names
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

            // Load the anchor event to know whether it belongs to a series.
            $stmt = $pdo->prepare("SELECT id, name, event_date, recurrence_group_id FROM calendar_events WHERE id = ?");
            $stmt->execute([$_GET['id']]);
            $anchor = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$anchor) {
                http_response_code(404);
                echo json_encode(['error' => 'Event not found']);
                exit;
            }

            $groupId = $anchor['recurrence_group_id'] ?: null;
            $testMode = file_exists(__DIR__ . '/.env.test') || getenv('APP_ENV') === 'test';

            // series=1: delete this occurrence AND all later ones in its
            // recurrence group (a wrongly-built series shouldn't need 50
            // one-by-one deletes). Falls back to single delete when the event
            // isn't part of a series.
            $seriesIds = [(int) $anchor['id']];
            $wholeSeries = false;
            if (!empty($_GET['series']) && $groupId !== null) {
                $stmt = $pdo->prepare("
                    SELECT id FROM calendar_events
                    WHERE recurrence_group_id = ? AND event_date >= ?
                ");
                $stmt->execute([$groupId, $anchor['event_date']]);
                $seriesIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

                // Deleting from the first remaining occurrence = the whole
                // series is going away.
                $stmt = $pdo->prepare("SELECT count(*) FROM calendar_events WHERE recurrence_group_id = ? AND event_date < ?");
                $stmt->execute([$groupId, $anchor['event_date']]);
                $wholeSeries = ((int) $stmt->fetchColumn()) === 0;
            }

            // Notify invitees BEFORE deleting.
            try {
                $inviteService = new CalendarInviteService($pdo, $testMode);
                if ($groupId !== null && $wholeSeries) {
                    // Entire series: one METHOD:CANCEL against the series UID
                    // removes the recurring event from recipients' calendars.
                    $inviteService->sendSeriesCancellation($groupId);
                } elseif ($groupId !== null) {
                    // Mid-series truncation or a single occurrence: recipients'
                    // calendars hold ONE recurring event under the series UID —
                    // per-event ICS cancels can't touch it, so send a plain
                    // notice. (Phase 2: RECURRENCE-ID / shortened-RRULE update.)
                    $dateList = [];
                    $ph = implode(',', array_fill(0, count($seriesIds), '?'));
                    $dstmt = $pdo->prepare("SELECT event_date FROM calendar_events WHERE id IN ($ph) ORDER BY event_date");
                    $dstmt->execute($seriesIds);
                    foreach ($dstmt->fetchAll(PDO::FETCH_COLUMN) as $d) {
                        $dateList[] = date('l, F j', strtotime($d));
                    }
                    $datesHtml = '<li>' . implode('</li><li>', $dateList) . '</li>';
                    $inviteService->sendSeriesChangeNotice(
                        $groupId,
                        "Cancelled dates: {$anchor['name']}",
                        "<p>The following date(s) of <strong>{$anchor['name']}</strong> have been cancelled:</p><ul>{$datesHtml}</ul><p>Other dates in the series are unchanged.</p>"
                    );
                } else {
                    $inviteService->sendEventCancellation($anchor['id']);
                }
            } catch (Exception $e) {
                error_log("Failed to send cancellation notices for event {$anchor['id']}: " . $e->getMessage());
            }

            $ph = implode(',', array_fill(0, count($seriesIds), '?'));
            $stmt = $pdo->prepare("DELETE FROM calendar_events WHERE id IN ($ph)");
            $stmt->execute($seriesIds);

            echo json_encode([
                'success' => true,
                'deleted' => count($seriesIds),
                'message' => count($seriesIds) > 1
                    ? count($seriesIds) . ' events deleted and cancellation notices sent'
                    : 'Event deleted and cancellation notices sent'
            ]);
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (InvalidArgumentException $e) {
    // Bad recurrence input (invalid frequency/end date) — client error, not 500.
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch(Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>