<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

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
                // Get specific athlete by ID with guardian data
                $id = (int)$_GET['id'];
                $stmt = $pdo->prepare("
                    SELECT a.*, u.email
                    FROM athletes a
                    LEFT JOIN users u ON u.id = a.id AND u.role = 'player'
                    WHERE a.id = ?
                ");
                $stmt->execute([$id]);
                $athlete = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($athlete) {
                    // Get guardian information from athlete_guardians and guardians tables
                    $guardianStmt = $pdo->prepare("
                        SELECT ag.id as id,
                               g.first_name,
                               g.last_name,
                               g.email,
                               g.mobile_phone,
                               g.work_phone,
                               ag.relationship,
                               ag.is_primary,
                               ag.can_pickup,
                               ag.emergency_contact
                        FROM athlete_guardians ag
                        JOIN guardians g ON ag.guardian_id = g.id
                        WHERE ag.athlete_id = ?
                        ORDER BY ag.is_primary DESC
                    ");
                    $guardianStmt->execute([$id]);
                    $guardians = $guardianStmt->fetchAll(PDO::FETCH_ASSOC);

                    $athlete['guardians'] = $guardians;
                    echo json_encode($athlete);
                } else {
                    http_response_code(404);
                    echo json_encode(['error' => 'Athlete not found']);
                }
            } else {
                // Get all athletes
                $stmt = $pdo->prepare("
                    SELECT a.id, a.first_name, a.middle_initial, a.last_name, a.preferred_name,
                           a.date_of_birth, a.gender, a.school_name, a.grade_level, a.active_status,
                           a.created_at, u.email,
                           g.first_name as guardian_first_name, g.last_name as guardian_last_name,
                           CONCAT(g.first_name, ' ', g.last_name) as primary_guardian_name,
                           g.email as primary_guardian_email,
                           g.mobile_phone as primary_guardian_phone
                    FROM athletes a
                    LEFT JOIN users u ON u.id = a.id AND u.role = 'player'
                    LEFT JOIN athlete_guardians ag ON ag.athlete_id = a.id AND ag.is_primary = true
                    LEFT JOIN guardians g ON g.id = ag.guardian_id
                    WHERE a.active_status = true
                    ORDER BY a.last_name, a.first_name
                ");
                $stmt->execute();
                $athletes = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode(['success' => true, 'athletes' => $athletes]);
            }
            break;

        case 'POST':
            // Create new athlete
            $input = json_decode(file_get_contents('php://input'), true);

            $first_name = $input['first_name'] ?? null;
            $last_name = $input['last_name'] ?? null;
            $email = $input['email'] ?? null;
            $middle_initial = $input['middle_initial'] ?? null;
            $preferred_name = $input['preferred_name'] ?? null;
            $date_of_birth = $input['date_of_birth'] ?? null;
            $gender = $input['gender'] ?? null;
            $school_name = $input['school_name'] ?? null;
            $grade_level = $input['grade_level'] ?? null;
            $password = $input['password'] ?? password_hash('defaultpass', PASSWORD_DEFAULT);

            if (!$first_name || !$last_name) {
                throw new Exception('First name and last name are required');
            }

            // Set defaults for required fields if not provided
            if (!$date_of_birth) {
                $date_of_birth = '2000-01-01'; // Default date
            }
            if (!$gender) {
                $gender = 'Male'; // Default gender
            }

            // Start transaction
            $pdo->beginTransaction();

            try {
                // Create user record if email provided
                $user_id = null;
                if ($email) {
                    $stmt = $pdo->prepare("
                        INSERT INTO users (first_name, last_name, email, password_hash, role)
                        VALUES (?, ?, ?, ?, 'player')
                        RETURNING id
                    ");
                    $stmt->execute([$first_name, $last_name, $email, $password]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $user_id = $result['id'];
                }

                // Create athlete record with required fields
                if ($user_id) {
                    // If we have a user_id, use it as the athlete id
                    $stmt = $pdo->prepare("
                        INSERT INTO athletes (
                            id, first_name, middle_initial, last_name, preferred_name,
                            date_of_birth, gender, home_address_line1, city, state, zip_code,
                            school_name, grade_level, active_status
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, true)
                    ");

                    $stmt->execute([
                        $user_id,
                        $first_name,
                        $middle_initial,
                        $last_name,
                        $preferred_name,
                        $date_of_birth,
                        $gender,
                        $input['home_address_line1'] ?? 'TBD',
                        $input['city'] ?? 'TBD',
                        $input['state'] ?? 'CA',
                        $input['zip_code'] ?? '00000',
                        $school_name,
                        $grade_level
                    ]);
                    $athlete_id = $user_id;
                } else {
                    // No user_id, let database generate athlete id
                    $stmt = $pdo->prepare("
                        INSERT INTO athletes (
                            first_name, middle_initial, last_name, preferred_name,
                            date_of_birth, gender, home_address_line1, city, state, zip_code,
                            school_name, grade_level, active_status
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, true)
                        RETURNING id
                    ");

                    $stmt->execute([
                        $first_name,
                        $middle_initial,
                        $last_name,
                        $preferred_name,
                        $date_of_birth,
                        $gender,
                        $input['home_address_line1'] ?? 'TBD',
                        $input['city'] ?? 'TBD',
                        $input['state'] ?? 'CA',
                        $input['zip_code'] ?? '00000',
                        $school_name,
                        $grade_level
                    ]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $athlete_id = $result['id'];
                }

                $pdo->commit();
                echo json_encode(['success' => true, 'athlete_id' => $athlete_id, 'message' => 'Athlete created successfully']);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        case 'PUT':
            // Update athlete
            $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
            $input = json_decode(file_get_contents('php://input'), true);

            if (!$id) {
                throw new Exception('Athlete ID is required');
            }

            $pdo->beginTransaction();

            try {
                // Update athlete table
                $athlete_fields = [];
                $athlete_values = [];

                $athlete_mapping = [
                    'first_name' => 'first_name',
                    'middle_initial' => 'middle_initial',
                    'last_name' => 'last_name',
                    'preferred_name' => 'preferred_name',
                    'date_of_birth' => 'date_of_birth',
                    'gender' => 'gender',
                    'school_name' => 'school_name',
                    'grade_level' => 'grade_level',
                    'home_address_line1' => 'home_address_line1',
                    'home_address_line2' => 'home_address_line2',
                    'city' => 'city',
                    'state' => 'state',
                    'zip_code' => 'zip_code'
                ];

                foreach ($athlete_mapping as $input_key => $db_field) {
                    if (isset($input[$input_key])) {
                        $athlete_fields[] = "$db_field = ?";
                        $athlete_values[] = $input[$input_key];
                    }
                }

                if (!empty($athlete_fields)) {
                    $athlete_values[] = $id;
                    $stmt = $pdo->prepare("UPDATE athletes SET " . implode(', ', $athlete_fields) . " WHERE id = ?");
                    $stmt->execute($athlete_values);
                }

                // Update user table if email, first_name, or last_name changed
                $user_fields = [];
                $user_values = [];

                $user_mapping = [
                    'first_name' => 'first_name',
                    'last_name' => 'last_name',
                    'email' => 'email'
                ];

                foreach ($user_mapping as $input_key => $db_field) {
                    if (isset($input[$input_key])) {
                        $user_fields[] = "$db_field = ?";
                        $user_values[] = $input[$input_key];
                    }
                }

                if (!empty($user_fields)) {
                    $user_values[] = $id;
                    $stmt = $pdo->prepare("UPDATE users SET " . implode(', ', $user_fields) . " WHERE id = ? AND role = 'player'");
                    $stmt->execute($user_values);
                }

                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Athlete updated successfully']);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        case 'DELETE':
            // Delete athlete (soft delete - set active_status to 0)
            $id = isset($_GET['id']) ? (int)$_GET['id'] : null;

            if (!$id) {
                throw new Exception('Athlete ID is required');
            }

            $pdo->beginTransaction();

            try {
                // Soft delete from athletes table
                $stmt = $pdo->prepare("UPDATE athletes SET active_status = false WHERE id = ?");
                $stmt->execute([$id]);

                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Athlete deactivated successfully']);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
?>