<?php
/**
 * Event Attendance API
 * Tracks athlete attendance at team events
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();


require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../services/AttendanceService.php';

// All attendance actions (get/save/athlete-history) require a valid token.
$auth = AuthMiddleware::requireAuth();

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
    $attendanceService = new AttendanceService($pdo);

    $action = $_GET['action'] ?? 'get';
    $method = $_SERVER['REQUEST_METHOD'];

    switch ($action) {
        case 'get':
            // Get attendance for an event with full athlete roster
            $event_id = $_GET['event_id'] ?? null;
            if (!$event_id) {
                throw new Exception('event_id is required');
            }

            // Get event details with teams
            $eventQuery = "
                SELECT
                    ce.id,
                    ce.name,
                    ce.type,
                    ce.event_date,
                    ce.start_time,
                    ce.end_time,
                    ce.status,
                    array_agg(DISTINCT cet.team_id) as team_ids
                FROM calendar_events ce
                LEFT JOIN calendar_event_teams cet ON ce.id = cet.event_id
                WHERE ce.id = :event_id
                GROUP BY ce.id
            ";
            $stmt = $pdo->prepare($eventQuery);
            $stmt->execute(['event_id' => $event_id]);
            $event = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$event) {
                throw new Exception('Event not found');
            }

            // Parse team_ids from PostgreSQL array
            $teamIds = [];
            if ($event['team_ids'] && $event['team_ids'] !== '{NULL}') {
                $teamIds = explode(',', trim($event['team_ids'], '{}'));
                $teamIds = array_filter($teamIds, fn($id) => $id && $id !== 'NULL');
            }

            if (empty($teamIds)) {
                // No teams assigned to event
                echo json_encode([
                    'success' => true,
                    'event' => $event,
                    'athletes' => [],
                    'attendance' => [],
                    'summary' => ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0, 'total' => 0, 'not_marked' => 0]
                ]);
                exit;
            }

            // Get all athletes on the assigned teams
            $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
            $athleteQuery = "
                SELECT DISTINCT
                    a.id as athlete_id,
                    a.first_name,
                    a.last_name,
                    tm.jersey_number,
                    tm.primary_position,
                    tm.team_id,
                    t.name as team_name
                FROM athletes a
                INNER JOIN team_members tm ON a.id = tm.athlete_id
                INNER JOIN teams t ON tm.team_id = t.id
                WHERE tm.team_id IN ($placeholders)
                  AND tm.status = 'active'
                  AND tm.role = 'player'
                  AND a.active_status = true
                ORDER BY a.last_name, a.first_name
            ";
            $stmt = $pdo->prepare($athleteQuery);
            $stmt->execute($teamIds);
            $athletes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Existing attendance records, indexed by athlete_id (all 4 statuses)
            $attendanceByAthlete = $attendanceService->getRecordsByAthlete((int) $event_id);

            // Summary across all 4 statuses, scoped to the event roster size
            $summary = $attendanceService->getSummary((int) $event_id, count($athletes));

            echo json_encode([
                'success' => true,
                'event' => $event,
                'athletes' => $athletes,
                'attendance' => $attendanceByAthlete,
                'summary' => $summary
            ]);
            break;

        case 'save':
            // Bulk save/update attendance
            if ($method !== 'POST') {
                throw new Exception('POST method required');
            }

            $data = json_decode(file_get_contents('php://input'), true);

            $event_id = $data['event_id'] ?? null;
            $attendance = $data['attendance'] ?? [];
            $marked_by = $data['marked_by'] ?? null;

            if (!$event_id) {
                throw new Exception('event_id is required');
            }

            if (empty($attendance)) {
                throw new Exception('attendance data is required');
            }

            // Verify event exists
            $stmt = $pdo->prepare("SELECT id FROM calendar_events WHERE id = :id");
            $stmt->execute(['id' => $event_id]);
            if (!$stmt->fetch()) {
                throw new Exception('Event not found');
            }

            // Upsert attendance records (present / absent / late / excused)
            $savedCount = $attendanceService->saveAttendance(
                (int) $event_id,
                $attendance,
                $marked_by !== null ? (int) $marked_by : null
            );

            // Updated summary across all 4 statuses (marked-only total here)
            $summary = $attendanceService->getSummary((int) $event_id);

            echo json_encode([
                'success' => true,
                'message' => "Saved attendance for $savedCount athletes",
                'saved_count' => $savedCount,
                'summary' => $summary
            ]);
            break;

        case 'athlete-history':
            // Get attendance history for a specific athlete
            $athlete_id = $_GET['athlete_id'] ?? null;
            if (!$athlete_id) {
                throw new Exception('athlete_id is required');
            }

            $historyQuery = "
                SELECT
                    ea.event_id,
                    ea.status,
                    ea.notes,
                    ea.marked_at,
                    ce.name as event_name,
                    ce.type as event_type,
                    ce.event_date
                FROM event_attendance ea
                INNER JOIN calendar_events ce ON ea.event_id = ce.id
                WHERE ea.athlete_id = :athlete_id
                ORDER BY ce.event_date DESC
                LIMIT 50
            ";
            $stmt = $pdo->prepare($historyQuery);
            $stmt->execute(['athlete_id' => $athlete_id]);
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Calculate stats across all 4 statuses (portable SUM/CASE, not FILTER)
            $statsQuery = "
                SELECT
                    SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_count,
                    SUM(CASE WHEN status = 'absent'  THEN 1 ELSE 0 END) as absent_count,
                    SUM(CASE WHEN status = 'late'    THEN 1 ELSE 0 END) as late_count,
                    SUM(CASE WHEN status = 'excused' THEN 1 ELSE 0 END) as excused_count,
                    COUNT(*) as total_events
                FROM event_attendance
                WHERE athlete_id = :athlete_id
            ";
            $stmt = $pdo->prepare($statsQuery);
            $stmt->execute(['athlete_id' => $athlete_id]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);

            // Normalize null SUMs (no rows) to 0
            foreach (['present_count', 'absent_count', 'late_count', 'excused_count', 'total_events'] as $k) {
                $stats[$k] = (int) ($stats[$k] ?? 0);
            }

            // "Attended" counts present + late toward the attendance rate.
            $attendedCount = $stats['present_count'] + $stats['late_count'];
            $attendanceRate = $stats['total_events'] > 0
                ? round(($attendedCount / $stats['total_events']) * 100, 1)
                : 0;

            echo json_encode([
                'success' => true,
                'athlete_id' => $athlete_id,
                'history' => $history,
                'stats' => array_merge($stats, ['attendance_rate' => $attendanceRate])
            ]);
            break;

        default:
            throw new Exception('Unknown action: ' . $action);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
