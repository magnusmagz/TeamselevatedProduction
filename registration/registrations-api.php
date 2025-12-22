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
try {
    $db = Database::getInstance();
    $connection = $db->getConnection();
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            // Get registrations for a program
            $program_id = $_GET['program_id'] ?? 0;

            $stmt = $connection->prepare("
                SELECT r.*, p.name as program_name
                FROM registrations r
                LEFT JOIN programs p ON r.program_id = p.id
                WHERE r.program_id = ?
                ORDER BY r.submitted_at DESC
            ");
            $stmt->execute([$program_id]);
            $registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Decode JSON form data
            foreach ($registrations as &$registration) {
                $registration['form_data'] = json_decode($registration['form_data'], true);
            }

            echo json_encode($registrations);
            break;

        case 'POST':
            // Submit new registration
            $data = json_decode(file_get_contents("php://input"), true);

            // Validate program exists and is open for registration
            $stmt = $connection->prepare("
                SELECT id, status, registration_closes
                FROM programs
                WHERE id = ? AND status = 'published'
            ");
            $stmt->execute([$data['program_id']]);
            $program = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$program) {
                http_response_code(400);
                echo json_encode(['error' => 'Program not available for registration']);
                exit();
            }

            // Check if registration is still open
            if ($program['registration_closes']) {
                $closes = new DateTime($program['registration_closes']);
                $now = new DateTime();
                if ($now > $closes) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Registration period has ended']);
                    exit();
                }
            }

            $connection->beginTransaction();

            try {
                $formData = $data['form_data'];

                // Extract guardian information
                $guardianEmail = $formData['guardian_email'] ?? null;
                $guardianFirst = $formData['guardian_first'] ?? null;
                $guardianLast = $formData['guardian_last'] ?? null;
                $mobilePhone = $formData['mobile_phone'] ?? null;

                if (!$guardianEmail || !$guardianFirst || !$guardianLast || !$mobilePhone) {
                    throw new Exception('Guardian information is required');
                }

                // Check if guardian exists by email
                $stmt = $connection->prepare("SELECT id FROM guardians WHERE email = ?");
                $stmt->execute([$guardianEmail]);
                $existingGuardian = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($existingGuardian) {
                    $guardian_id = $existingGuardian['id'];
                } else {
                    // Create new guardian
                    $stmt = $connection->prepare("
                        INSERT INTO guardians (first_name, last_name, email, mobile_phone, created_at)
                        VALUES (?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([$guardianFirst, $guardianLast, $guardianEmail, $mobilePhone]);
                    $guardian_id = $connection->lastInsertId();
                }

                // Extract athlete information
                $athleteFirst = $formData['athlete_first'] ?? null;
                $athleteLast = $formData['athlete_last'] ?? null;
                $athleteBirthday = $formData['athlete_birthday'] ?? null;
                $athleteGender = $formData['athlete_gender'] ?? null;
                $athleteGrade = $formData['athlete_grade'] ?? null;

                if (!$athleteFirst || !$athleteLast || !$athleteBirthday || !$athleteGender) {
                    throw new Exception('Athlete information is required');
                }

                // Map grade to grade_level integer
                $gradeMap = [
                    'Pre-K' => 0, 'Kindergarten' => 0,
                    '1st' => 1, '2nd' => 2, '3rd' => 3, '4th' => 4,
                    '5th' => 5, '6th' => 6, '7th' => 7, '8th' => 8,
                    '9th' => 9, '10th' => 10, '11th' => 11, '12th' => 12
                ];
                $gradeLevel = $gradeMap[$athleteGrade] ?? null;

                // Create athlete record (with placeholder address since it's required)
                $stmt = $connection->prepare("
                    INSERT INTO athletes (
                        first_name, last_name, date_of_birth, gender,
                        home_address_line1, city, state, zip_code,
                        grade_level, created_at, active_status
                    ) VALUES (?, ?, ?, ?, 'TBD', 'TBD', 'TBD', 'TBD', ?, NOW(), TRUE)
                ");
                $stmt->execute([
                    $athleteFirst,
                    $athleteLast,
                    $athleteBirthday,
                    $athleteGender,
                    $gradeLevel
                ]);
                $athlete_id = $connection->lastInsertId();

                // Link athlete to guardian
                $stmt = $connection->prepare("
                    INSERT INTO athlete_guardians (
                        athlete_id, guardian_id, relationship_type,
                        is_primary_contact, created_at
                    ) VALUES (?, ?, 'Guardian', TRUE, NOW())
                ");
                $stmt->execute([$athlete_id, $guardian_id]);

                // Insert registration with athlete and guardian references
                $stmt = $connection->prepare("
                    INSERT INTO registrations (
                        program_id, athlete_id, guardian_id, form_data, status, submitted_at
                    ) VALUES (?, ?, ?, ?, 'pending', NOW())
                ");
                $stmt->execute([
                    $data['program_id'],
                    $athlete_id,
                    $guardian_id,
                    json_encode($formData)
                ]);

                $registration_id = $connection->lastInsertId();

                $connection->commit();

                // Send confirmation email (optional - implement later)
                // sendConfirmationEmail($formData);

                echo json_encode([
                    'success' => true,
                    'id' => $registration_id,
                    'athlete_id' => $athlete_id,
                    'guardian_id' => $guardian_id,
                    'message' => 'Registration submitted successfully'
                ]);

            } catch (Exception $e) {
                $connection->rollBack();
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'PUT':
            // Update registration status
            $registration_id = $_GET['id'] ?? 0;
            $data = json_decode(file_get_contents("php://input"), true);

            $stmt = $connection->prepare("
                UPDATE registrations
                SET status = ?, reviewed_at = NOW(), reviewed_by = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $data['status'],
                $data['reviewed_by'] ?? null,
                $registration_id
            ]);

            echo json_encode(['success' => true, 'message' => 'Registration updated']);
            break;

        case 'DELETE':
            // Delete registration
            $registration_id = $_GET['id'] ?? 0;

            $stmt = $connection->prepare("DELETE FROM registrations WHERE id = ?");
            $stmt->execute([$registration_id]);

            echo json_encode(['success' => true, 'message' => 'Registration deleted']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>