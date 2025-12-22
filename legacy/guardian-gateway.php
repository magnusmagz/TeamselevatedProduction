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
            // Get guardians for an athlete
            $athleteId = isset($_GET['athlete_id']) ? (int)$_GET['athlete_id'] : null;

            if ($athleteId) {
                // Get guardians for specific athlete
                $stmt = $pdo->prepare("
                    SELECT ag.id as relationship_id,
                           ag.relationship as relationship_type,
                           ag.is_primary as is_primary_contact,
                           ag.can_pickup,
                           ag.emergency_contact,
                           g.id as guardian_id,
                           g.first_name,
                           g.last_name,
                           g.email,
                           g.mobile_phone,
                           g.work_phone
                    FROM athlete_guardians ag
                    JOIN guardians g ON ag.guardian_id = g.id
                    WHERE ag.athlete_id = ?
                    ORDER BY ag.is_primary DESC, g.first_name ASC
                ");
                $stmt->execute([$athleteId]);
                $guardians = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode(['success' => true, 'guardians' => $guardians]);
            } else {
                // Get all guardians
                $stmt = $pdo->prepare("
                    SELECT g.id,
                           g.first_name,
                           g.last_name,
                           g.email,
                           g.mobile_phone,
                           g.work_phone,
                           COUNT(ag.id) as athlete_count
                    FROM guardians g
                    LEFT JOIN athlete_guardians ag ON g.id = ag.guardian_id AND ag.active_status = 1
                    GROUP BY g.id
                    ORDER BY g.first_name ASC, g.last_name ASC
                ");
                $stmt->execute();
                $guardians = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode(['success' => true, 'guardians' => $guardians]);
            }
            break;

        case 'POST':
            // Add new guardian to athlete
            $input = json_decode(file_get_contents('php://input'), true);
            $athleteId = $input['athlete_id'] ?? null;

            if (!$athleteId) {
                throw new Exception('Athlete ID is required');
            }

            // Required fields
            $first_name = $input['first_name'] ?? null;
            $last_name = $input['last_name'] ?? null;
            $email = $input['email'] ?? null;
            $mobile_phone = $input['mobile_phone'] ?? null;
            $relationship_type = $input['relationship_type'] ?? 'Other';

            if (!$first_name || !$last_name || !$email || !$mobile_phone) {
                throw new Exception('First name, last name, email, and mobile phone are required');
            }

            $pdo->beginTransaction();

            try {
                // Check if guardian already exists by email
                $stmt = $pdo->prepare("SELECT id FROM guardians WHERE email = ?");
                $stmt->execute([$email]);
                $existingGuardian = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($existingGuardian) {
                    $guardianId = $existingGuardian['id'];
                } else {
                    // Create new guardian
                    $stmt = $pdo->prepare("
                        INSERT INTO guardians (first_name, last_name, email, mobile_phone, work_phone)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $first_name,
                        $last_name,
                        $email,
                        $mobile_phone,
                        $input['work_phone'] ?? null
                    ]);
                    $guardianId = $pdo->lastInsertId();
                }

                // Link guardian to athlete
                $stmt = $pdo->prepare("
                    INSERT INTO athlete_guardians (
                        athlete_id, guardian_id, relationship,
                        is_primary, can_pickup, emergency_contact
                    ) VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $athleteId,
                    $guardianId,
                    $relationship_type,
                    $input['is_primary_contact'] ?? false,
                    $input['can_pickup'] ?? true,
                    $input['emergency_contact'] ?? false
                ]);

                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Guardian added successfully']);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        case 'PUT':
            // Update guardian relationship
            $input = json_decode(file_get_contents('php://input'), true);
            $relationshipId = $input['id'] ?? null;

            if (!$relationshipId) {
                throw new Exception('Guardian relationship ID is required');
            }

            $updateFields = [];
            $updateValues = [];

            $fieldMapping = [
                'relationship_type' => 'relationship',
                'is_primary_contact' => 'is_primary',
                'can_pickup' => 'can_pickup',
                'emergency_contact' => 'emergency_contact'
            ];

            foreach ($fieldMapping as $inputField => $dbField) {
                if (isset($input[$inputField])) {
                    $updateFields[] = "$dbField = ?";
                    $updateValues[] = $input[$inputField];
                }
            }

            if (!empty($updateFields)) {
                $updateValues[] = $relationshipId;
                $stmt = $pdo->prepare("
                    UPDATE athlete_guardians
                    SET " . implode(', ', $updateFields) . "
                    WHERE id = ?
                ");
                $stmt->execute($updateValues);
            }

            echo json_encode(['success' => true, 'message' => 'Guardian relationship updated successfully']);
            break;

        case 'DELETE':
            // Remove guardian relationship
            $id = isset($_GET['id']) ? (int)$_GET['id'] : null;

            if (!$id) {
                throw new Exception('Guardian relationship ID is required');
            }

            $stmt = $pdo->prepare("DELETE FROM athlete_guardians WHERE id = ?");
            $stmt->execute([$id]);

            echo json_encode(['success' => true, 'message' => 'Guardian relationship removed successfully']);
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