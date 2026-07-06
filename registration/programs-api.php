<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Database connection
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
try {
    $db = Database::getInstance();
    $connection = $db->getConnection();
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$path = $_GET['path'] ?? '';

// Authentication: the public registration widget endpoint (GET path=by-embed)
// stays public so embedded forms can load. Everything else requires a valid
// token; write operations additionally verify club access.
$auth = null;
$isPublic = ($method === 'GET' && $path === 'by-embed');
if (!$isPublic) {
    $auth = AuthMiddleware::requireAuth();
}

/**
 * Look up a program's club_id (for update/delete club-access checks).
 */
function getProgramClubId($connection, $programId) {
    $stmt = $connection->prepare("SELECT club_id FROM programs WHERE id = ?");
    $stmt->execute([$programId]);
    return $stmt->fetchColumn();
}

try {
    switch ($method) {
        case 'GET':
            if ($path === 'list') {
                // Get all programs for a club
                $club_id = $_GET['club_id'] ?? 1; // Default to club 1 for now
                $stmt = $connection->prepare("
                    SELECT p.*,
                           v.name as venue_name,
                           (SELECT COUNT(*) FROM registrations WHERE program_id = p.id AND status != 'rejected') as registration_count,
                           (SELECT COUNT(*) FROM registrations WHERE program_id = p.id AND status = 'pending') as pending_count
                    FROM programs p
                    LEFT JOIN venues v ON p.venue_id = v.id
                    WHERE p.club_id = ?
                    ORDER BY p.created_at DESC
                ");
                $stmt->execute([$club_id]);
                $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode($programs);
            } elseif ($path === 'details') {
                // Get single program with form fields
                $program_id = $_GET['id'] ?? 0;
                $stmt = $connection->prepare("
                    SELECT p.*,
                           c.name as club_name,
                           c.logo_url as club_logo,
                           c.primary_color as club_primary_color,
                           v.name as venue_name, v.address as venue_address,
                           v.city as venue_city, v.state as venue_state,
                           v.zip_code as venue_zip, v.map_url as venue_map_url
                    FROM programs p
                    LEFT JOIN club_profile c ON p.club_id = c.id
                    LEFT JOIN venues v ON p.venue_id = v.id
                    WHERE p.id = ?
                ");
                $stmt->execute([$program_id]);
                $program = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($program) {
                    // Get form fields
                    $stmt = $connection->prepare("
                        SELECT * FROM program_form_fields
                        WHERE program_id = ?
                        ORDER BY section, display_order
                    ");
                    $stmt->execute([$program_id]);
                    $program['form_fields'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }

                echo json_encode($program);
            } elseif ($path === 'by-embed') {
                // Get program by embed code (for widget)
                $embed_code = $_GET['code'] ?? '';
                $stmt = $connection->prepare("
                    SELECT p.*,
                           c.name as club_name,
                           c.logo_url as club_logo,
                           c.primary_color as club_primary_color,
                           v.name as venue_name, v.address as venue_address,
                           v.city as venue_city, v.state as venue_state,
                           v.zip_code as venue_zip, v.map_url as venue_map_url
                    FROM programs p
                    LEFT JOIN club_profile c ON p.club_id = c.id
                    LEFT JOIN venues v ON p.venue_id = v.id
                    WHERE p.embed_code = ? AND p.status = 'published'
                ");
                $stmt->execute([$embed_code]);
                $program = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($program) {
                    // Get form fields
                    $stmt = $connection->prepare("
                        SELECT * FROM program_form_fields
                        WHERE program_id = ?
                        ORDER BY section, display_order
                    ");
                    $stmt->execute([$program['id']]);
                    $program['form_fields'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    // Decode what_to_bring JSON
                    if (!empty($program['what_to_bring'])) {
                        $program['what_to_bring'] = json_decode($program['what_to_bring'], true);
                    }
                }

                echo json_encode($program);
            }
            break;

        case 'POST':
            if ($path === 'create') {
                $data = json_decode(file_get_contents("php://input"), true);

                // Derive the target club from the authenticated user — never trust the
                // body's club_id outright (the frontend historically hardcoded 1). If the
                // body supplies a club the caller can access, honor it; otherwise fall back
                // to the user's own org. Reject if the result is empty or inaccessible.
                if (isset($data['club_id']) && $auth->canAccessClub((int)$data['club_id'])) {
                    $clubId = (int)$data['club_id'];
                } else {
                    $clubId = $auth->getOrgId();
                }

                if (empty($clubId) || !$auth->canAccessClub((int)$clubId)) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Forbidden: no access to this club']);
                    exit();
                }

                // Generate unique embed code
                $embed_code = 'PRG' . strtoupper(bin2hex(random_bytes(8)));

                $stmt = $connection->prepare("
                    INSERT INTO programs (
                        club_id, name, type, description,
                        start_date, end_date, registration_opens, registration_closes,
                        min_age, max_age, capacity, status, embed_code, registration_fee, venue_id
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $clubId,
                    $data['name'],
                    $data['type'],
                    !empty($data['description']) ? $data['description'] : null,
                    !empty($data['start_date']) ? $data['start_date'] : null,
                    !empty($data['end_date']) ? $data['end_date'] : null,
                    !empty($data['registration_opens']) ? $data['registration_opens'] : null,
                    !empty($data['registration_closes']) ? $data['registration_closes'] : null,
                    !empty($data['min_age']) ? $data['min_age'] : null,
                    !empty($data['max_age']) ? $data['max_age'] : null,
                    !empty($data['capacity']) ? $data['capacity'] : null,
                    $data['status'] ?? 'draft',
                    $embed_code,
                    !empty($data['registration_fee']) ? $data['registration_fee'] : null,
                    !empty($data['venue_id']) ? $data['venue_id'] : null
                ]);

                $program_id = $connection->lastInsertId();

                // Add default form fields
                $default_fields = [
                    ['field_name' => 'athlete_first', 'field_label' => 'Athlete First', 'field_type' => 'text', 'required' => true, 'section' => 'athlete_info', 'display_order' => 1, 'options' => null],
                    ['field_name' => 'athlete_last', 'field_label' => 'Athlete Last', 'field_type' => 'text', 'required' => true, 'section' => 'athlete_info', 'display_order' => 2, 'options' => null],
                    ['field_name' => 'athlete_birthday', 'field_label' => 'Athlete Birthday', 'field_type' => 'date', 'required' => true, 'section' => 'athlete_info', 'display_order' => 3, 'options' => null],
                    ['field_name' => 'athlete_gender', 'field_label' => 'Athlete Gender', 'field_type' => 'select', 'required' => true, 'section' => 'athlete_info', 'display_order' => 4, 'options' => ['Male', 'Female', 'Non-binary', 'Prefer not to say']],
                    ['field_name' => 'athlete_grade', 'field_label' => 'Athlete Grade', 'field_type' => 'select', 'required' => true, 'section' => 'athlete_info', 'display_order' => 5, 'options' => ['Pre-K', 'Kindergarten', '1st', '2nd', '3rd', '4th', '5th', '6th', '7th', '8th', '9th', '10th', '11th', '12th']],
                    ['field_name' => 'guardian_first', 'field_label' => 'Guardian First', 'field_type' => 'text', 'required' => true, 'section' => 'parent_info', 'display_order' => 6, 'options' => null],
                    ['field_name' => 'guardian_last', 'field_label' => 'Guardian Last', 'field_type' => 'text', 'required' => true, 'section' => 'parent_info', 'display_order' => 7, 'options' => null],
                    ['field_name' => 'guardian_email', 'field_label' => 'Guardian Email', 'field_type' => 'email', 'required' => true, 'section' => 'parent_info', 'display_order' => 8, 'options' => null],
                    ['field_name' => 'mobile_phone', 'field_label' => 'Mobile Phone', 'field_type' => 'tel', 'required' => true, 'section' => 'parent_info', 'display_order' => 9, 'options' => null]
                ];

                $field_stmt = $connection->prepare("
                    INSERT INTO program_form_fields (
                        program_id, field_name, field_label, field_type, required, options, section, display_order
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");

                foreach ($default_fields as $field) {
                    $field_stmt->execute([
                        $program_id,
                        $field['field_name'],
                        $field['field_label'],
                        $field['field_type'],
                        $field['required'] ? 1 : 0,  // Convert boolean to integer
                        $field['options'] ? json_encode($field['options']) : null,
                        $field['section'],
                        $field['display_order']
                    ]);
                }

                echo json_encode([
                    'success' => true,
                    'id' => $program_id,
                    'embed_code' => $embed_code,
                    'message' => 'Program created successfully'
                ]);
            } elseif ($path === 'update-fields') {
                // Update form fields for a program
                $data = json_decode(file_get_contents("php://input"), true);
                $program_id = $data['program_id'];

                // Verify caller can manage the program's club.
                $programClubId = getProgramClubId($connection, $program_id);
                if ($programClubId === false || !$auth->canAccessClub($programClubId)) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Forbidden: no access to this program']);
                    exit();
                }

                // Delete existing fields
                $stmt = $connection->prepare("DELETE FROM program_form_fields WHERE program_id = ?");
                $stmt->execute([$program_id]);

                // Insert new fields
                $field_stmt = $connection->prepare("
                    INSERT INTO program_form_fields (
                        program_id, field_name, field_label, field_type,
                        required, options, section, display_order
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");

                foreach ($data['fields'] as $index => $field) {
                    $field_stmt->execute([
                        $program_id,
                        $field['field_name'],
                        $field['field_label'],
                        $field['field_type'],
                        isset($field['required']) ? ($field['required'] ? 1 : 0) : 0,  // Convert boolean to integer
                        isset($field['options']) ? json_encode($field['options']) : null,
                        $field['section'] ?? 'general',
                        $index
                    ]);
                }

                echo json_encode(['success' => true, 'message' => 'Form fields updated']);
            }
            break;

        case 'PUT':
            $data = json_decode(file_get_contents("php://input"), true);
            $program_id = $_GET['id'] ?? 0;

            // Verify caller can manage the program's club.
            $programClubId = getProgramClubId($connection, $program_id);
            if ($programClubId === false || !$auth->canAccessClub($programClubId)) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden: no access to this program']);
                exit();
            }

            $stmt = $connection->prepare("
                UPDATE programs SET
                    name = ?, type = ?, description = ?,
                    start_date = ?, end_date = ?,
                    registration_opens = ?, registration_closes = ?,
                    min_age = ?, max_age = ?, capacity = ?, status = ?, registration_fee = ?,
                    venue_id = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $data['name'],
                $data['type'],
                !empty($data['description']) ? $data['description'] : null,
                !empty($data['start_date']) ? $data['start_date'] : null,
                !empty($data['end_date']) ? $data['end_date'] : null,
                !empty($data['registration_opens']) ? $data['registration_opens'] : null,
                !empty($data['registration_closes']) ? $data['registration_closes'] : null,
                !empty($data['min_age']) ? $data['min_age'] : null,
                !empty($data['max_age']) ? $data['max_age'] : null,
                !empty($data['capacity']) ? $data['capacity'] : null,
                $data['status'] ?? 'draft',
                !empty($data['registration_fee']) ? $data['registration_fee'] : null,
                !empty($data['venue_id']) ? $data['venue_id'] : null,
                $program_id
            ]);

            echo json_encode(['success' => true, 'message' => 'Program updated']);
            break;

        case 'DELETE':
            $program_id = $_GET['id'] ?? 0;

            // Verify caller can manage the program's club.
            $programClubId = getProgramClubId($connection, $program_id);
            if ($programClubId === false || !$auth->canAccessClub($programClubId)) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden: no access to this program']);
                exit();
            }

            $stmt = $connection->prepare("DELETE FROM programs WHERE id = ?");
            $stmt->execute([$program_id]);
            echo json_encode(['success' => true, 'message' => 'Program deleted']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>