<?php
/**
 * Event Attendance API
 * Tracks athlete attendance at team events
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/database.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

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
                    'summary' => ['present' => 0, 'absent' => 0, 'total' => 0]
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
                ORDER BY a.last_name, a.first_name
            ";
            $stmt = $pdo->prepare($athleteQuery);
            $stmt->execute($teamIds);
            $athletes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get existing attendance records
            $attendanceQuery = "
                SELECT
                    athlete_id,
                    status,
                    notes,
                    marked_at,
                    marked_by
                FROM event_attendance
                WHERE event_id = :event_id
            ";
            $stmt = $pdo->prepare($attendanceQuery);
            $stmt->execute(['event_id' => $event_id]);
            $attendanceRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Index attendance by athlete_id
            $attendanceByAthlete = [];
            foreach ($attendanceRecords as $record) {
                $attendanceByAthlete[$record['athlete_id']] = $record;
            }

            // Calculate summary
            $present = 0;
            $absent = 0;
            foreach ($attendanceRecords as $record) {
                if ($record['status'] === 'present') {
                    $present++;
                } else {
                    $absent++;
                }
            }

            echo json_encode([
                'success' => true,
                'event' => $event,
                'athletes' => $athletes,
                'attendance' => $attendanceByAthlete,
                'summary' => [
                    'present' => $present,
                    'absent' => $absent,
                    'total' => count($athletes),
                    'not_marked' => count($athletes) - count($attendanceRecords)
                ]
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

            // Upsert attendance records
            $upsertQuery = "
                INSERT INTO event_attendance (event_id, athlete_id, status, marked_by, marked_at, notes)
                VALUES (:event_id, :athlete_id, :status, :marked_by, CURRENT_TIMESTAMP, :notes)
                ON CONFLICT (event_id, athlete_id)
                DO UPDATE SET
                    status = EXCLUDED.status,
                    marked_by = EXCLUDED.marked_by,
                    marked_at = CURRENT_TIMESTAMP,
                    notes = EXCLUDED.notes
            ";
            $stmt = $pdo->prepare($upsertQuery);

            $savedCount = 0;
            foreach ($attendance as $record) {
                $athleteId = $record['athlete_id'] ?? null;
                $status = $record['status'] ?? 'present';
                $notes = $record['notes'] ?? null;

                if (!$athleteId) continue;

                // Validate status
                if (!in_array($status, ['present', 'absent'])) {
                    $status = 'present';
                }

                $stmt->execute([
                    'event_id' => $event_id,
                    'athlete_id' => $athleteId,
                    'status' => $status,
                    'marked_by' => $marked_by,
                    'notes' => $notes
                ]);
                $savedCount++;
            }

            // Get updated summary
            $summaryQuery = "
                SELECT
                    COUNT(*) FILTER (WHERE status = 'present') as present,
                    COUNT(*) FILTER (WHERE status = 'absent') as absent,
                    COUNT(*) as total
                FROM event_attendance
                WHERE event_id = :event_id
            ";
            $stmt = $pdo->prepare($summaryQuery);
            $stmt->execute(['event_id' => $event_id]);
            $summary = $stmt->fetch(PDO::FETCH_ASSOC);

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

            // Calculate stats
            $statsQuery = "
                SELECT
                    COUNT(*) FILTER (WHERE status = 'present') as present_count,
                    COUNT(*) FILTER (WHERE status = 'absent') as absent_count,
                    COUNT(*) as total_events
                FROM event_attendance
                WHERE athlete_id = :athlete_id
            ";
            $stmt = $pdo->prepare($statsQuery);
            $stmt->execute(['athlete_id' => $athlete_id]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);

            $attendanceRate = $stats['total_events'] > 0
                ? round(($stats['present_count'] / $stats['total_events']) * 100, 1)
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
