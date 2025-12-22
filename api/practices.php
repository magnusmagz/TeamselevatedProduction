<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

$database = Database::getInstance();
$db = $database->getConnection();

$method = $_SERVER['REQUEST_METHOD'];
$request_uri = $_SERVER['REQUEST_URI'];
$uri_parts = explode('/', parse_url($request_uri, PHP_URL_PATH));

try {
    switch ($method) {
        case 'POST':
            if (isset($uri_parts[4]) && $uri_parts[4] === 'generate') {
                // Generate practices from pattern
                $data = json_decode(file_get_contents("php://input"), true);

                $team_id = $data['team_id'];
                $days_of_week = $data['days_of_week']; // Array of day names
                $start_time = $data['start_time'];
                $end_time = $data['end_time'];
                $venue_id = $data['venue_id'];
                $field_id = $data['field_id'];
                $start_date = $data['start_date'];
                $end_date = $data['end_date'];

                $generated_practices = [];
                $conflicts = [];

                // Map day names to PHP day numbers (0=Sunday, 6=Saturday)
                $day_map = [
                    'sunday' => 0, 'monday' => 1, 'tuesday' => 2,
                    'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6
                ];

                $selected_days = array_map(function($day) use ($day_map) {
                    return $day_map[strtolower($day)];
                }, $days_of_week);

                // Generate all practice dates
                $current = new DateTime($start_date);
                $end = new DateTime($end_date);
                $end->modify('+1 day'); // Include end date

                while ($current <= $end) {
                    $day_of_week = (int)$current->format('w');

                    if (in_array($day_of_week, $selected_days)) {
                        $practice_date = $current->format('Y-m-d');
                        $start_datetime = $practice_date . ' ' . $start_time;
                        $end_datetime = $practice_date . ' ' . $end_time;

                        // Check for conflicts
                        $conflict_stmt = $db->prepare("
                            SELECT e.*, t.name as team_name
                            FROM events e
                            JOIN teams t ON e.team_id = t.id
                            WHERE e.field_id = ?
                            AND e.status != 'cancelled'
                            AND (
                                (e.start_datetime <= ? AND e.end_datetime > ?) OR
                                (e.start_datetime < ? AND e.end_datetime >= ?) OR
                                (e.start_datetime >= ? AND e.end_datetime <= ?)
                            )
                        ");

                        $conflict_stmt->execute([
                            $field_id,
                            $start_datetime, $start_datetime,
                            $end_datetime, $end_datetime,
                            $start_datetime, $end_datetime
                        ]);

                        $conflict = $conflict_stmt->fetch(PDO::FETCH_ASSOC);

                        $practice = [
                            'date' => $practice_date,
                            'day' => $current->format('D'),
                            'start_time' => $start_time,
                            'end_time' => $end_time,
                            'start_datetime' => $start_datetime,
                            'end_datetime' => $end_datetime,
                            'venue_id' => $venue_id,
                            'field_id' => $field_id,
                            'has_conflict' => !empty($conflict),
                            'conflict_details' => $conflict ? [
                                'team' => $conflict['team_name'],
                                'type' => $conflict['event_type'],
                                'time' => substr($conflict['start_datetime'], 11, 5) . '-' . substr($conflict['end_datetime'], 11, 5)
                            ] : null
                        ];

                        $generated_practices[] = $practice;

                        if (!empty($conflict)) {
                            $conflicts[] = $practice;
                        }
                    }

                    $current->modify('+1 day');
                }

                echo json_encode([
                    'success' => true,
                    'practices' => $generated_practices,
                    'total' => count($generated_practices),
                    'conflicts' => count($conflicts),
                    'conflict_details' => $conflicts
                ]);

            } elseif (isset($uri_parts[4]) && $uri_parts[4] === 'bulk-create') {
                // Create multiple practices at once
                $data = json_decode(file_get_contents("php://input"), true);

                $db->beginTransaction();

                try {
                    $stmt = $db->prepare("
                        INSERT INTO events (
                            team_id, event_type, title, description,
                            start_datetime, end_datetime,
                            venue_id, field_id, status, created_by
                        ) VALUES (?, 'practice', ?, ?, ?, ?, ?, ?, 'scheduled', ?)
                    ");

                    $created_count = 0;
                    $team_id = $data['team_id'];
                    $created_by = $data['created_by'] ?? null;

                    // Get team name for practice title
                    $team_stmt = $db->prepare("SELECT name FROM teams WHERE id = ?");
                    $team_stmt->execute([$team_id]);
                    $team = $team_stmt->fetch(PDO::FETCH_ASSOC);
                    $team_name = $team['name'];

                    foreach ($data['practices'] as $practice) {
                        if (!isset($practice['skip']) || !$practice['skip']) {
                            $title = $team_name . ' Practice';
                            $description = $practice['notes'] ?? '';

                            $stmt->execute([
                                $team_id,
                                $title,
                                $description,
                                $practice['start_datetime'],
                                $practice['end_datetime'],
                                $practice['venue_id'],
                                $practice['field_id'],
                                $created_by
                            ]);

                            $created_count++;
                        }
                    }

                    // Save the pattern for future reference
                    if (isset($data['save_pattern']) && $data['save_pattern']) {
                        $pattern_stmt = $db->prepare("
                            INSERT INTO practice_patterns (
                                team_id, pattern_name, days_of_week,
                                start_time, end_time, venue_id, field_id,
                                start_date, end_date, created_by
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");

                        $pattern_stmt->execute([
                            $team_id,
                            $data['pattern_name'] ?? 'Regular Practice',
                            implode(',', $data['days_of_week']),
                            $data['start_time'],
                            $data['end_time'],
                            $data['venue_id'],
                            $data['field_id'],
                            $data['start_date'],
                            $data['end_date'],
                            $created_by
                        ]);
                    }

                    $db->commit();

                    echo json_encode([
                        'success' => true,
                        'message' => "$created_count practices created successfully",
                        'created' => $created_count
                    ]);

                } catch (Exception $e) {
                    $db->rollBack();
                    throw $e;
                }
            }
            break;

        case 'GET':
            if (isset($_GET['team_id']) && isset($_GET['check_availability'])) {
                // Check field availability for a time range
                $team_id = $_GET['team_id'];
                $field_id = $_GET['field_id'];
                $start_datetime = $_GET['start_datetime'];
                $end_datetime = $_GET['end_datetime'];

                $stmt = $db->prepare("
                    SELECT COUNT(*) as conflicts
                    FROM events
                    WHERE field_id = ?
                    AND status != 'cancelled'
                    AND (
                        (start_datetime <= ? AND end_datetime > ?) OR
                        (start_datetime < ? AND end_datetime >= ?) OR
                        (start_datetime >= ? AND end_datetime <= ?)
                    )
                ");

                $stmt->execute([
                    $field_id,
                    $start_datetime, $start_datetime,
                    $end_datetime, $end_datetime,
                    $start_datetime, $end_datetime
                ]);

                $result = $stmt->fetch(PDO::FETCH_ASSOC);

                echo json_encode([
                    'available' => $result['conflicts'] == 0,
                    'conflicts' => $result['conflicts']
                ]);

            } elseif (isset($_GET['team_id'])) {
                // Get all practices for a team
                $team_id = $_GET['team_id'];

                $stmt = $db->prepare("
                    SELECT e.*, v.name as venue_name, f.name as field_name
                    FROM events e
                    LEFT JOIN venues v ON e.venue_id = v.id
                    LEFT JOIN fields f ON e.field_id = f.id
                    WHERE e.team_id = ?
                    AND e.event_type = 'practice'
                    ORDER BY e.start_datetime
                ");

                $stmt->execute([$team_id]);
                $practices = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode($practices);
            }
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>