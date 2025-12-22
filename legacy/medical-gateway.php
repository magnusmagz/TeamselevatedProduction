<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$servername = "localhost";
$username = "root";
$password = "root";
$dbname = "teams_elevated";
$socket = "/Applications/MAMP/tmp/mysql/mysql.sock";

try {
    $pdo = new PDO("mysql:host=$servername;dbname=$dbname;unix_socket=$socket", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            // Get medical information for an athlete
            $athleteId = isset($_GET['athlete_id']) ? (int)$_GET['athlete_id'] : null;

            if (!$athleteId) {
                throw new Exception('Athlete ID is required');
            }

            $stmt = $pdo->prepare("
                SELECT * FROM athlete_medical
                WHERE athlete_id = ?
                ORDER BY updated_at DESC
                LIMIT 1
            ");
            $stmt->execute([$athleteId]);
            $medical = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$medical) {
                // Return empty medical record structure if none exists
                $medical = [
                    'athlete_id' => $athleteId,
                    'exists' => false
                ];
            } else {
                $medical['exists'] = true;

                // Calculate age-appropriate alerts
                $alerts = [];

                // Check for critical allergies
                if ($medical['allergy_severity'] === 'life-threatening' || $medical['allergy_severity'] === 'severe') {
                    $alerts[] = [
                        'type' => 'critical',
                        'message' => "SEVERE ALLERGY: {$medical['allergies']}"
                    ];
                }

                // Check for EpiPen
                if ($medical['has_epipen']) {
                    $alerts[] = [
                        'type' => 'critical',
                        'message' => "EpiPen Required - Location: {$medical['epipen_location']}"
                    ];
                }

                // Check for asthma
                if ($medical['has_asthma']) {
                    $alerts[] = [
                        'type' => 'warning',
                        'message' => "Asthma - Inhaler Location: {$medical['inhaler_location']}"
                    ];
                }

                // Check physical expiry
                if ($medical['physical_expiry_date']) {
                    $expiryDate = new DateTime($medical['physical_expiry_date']);
                    $today = new DateTime();
                    $diff = $today->diff($expiryDate);

                    if ($expiryDate < $today) {
                        $alerts[] = [
                            'type' => 'warning',
                            'message' => 'Physical exam has expired'
                        ];
                    } elseif ($diff->days <= 30) {
                        $alerts[] = [
                            'type' => 'info',
                            'message' => "Physical expires in {$diff->days} days"
                        ];
                    }
                }

                // Check concussion protocol
                if ($medical['return_to_play_date'] && new DateTime($medical['return_to_play_date']) > new DateTime()) {
                    $alerts[] = [
                        'type' => 'critical',
                        'message' => 'Under concussion protocol - Not cleared to play'
                    ];
                }

                $medical['alerts'] = $alerts;
            }

            echo json_encode(['success' => true, 'medical' => $medical]);
            break;

        case 'POST':
        case 'PUT':
            // Create or update medical information
            $data = json_decode(file_get_contents('php://input'), true);
            $athleteId = $data['athlete_id'] ?? null;

            if (!$athleteId) {
                throw new Exception('Athlete ID is required');
            }

            // Check if record exists
            $checkStmt = $pdo->prepare("SELECT id FROM athlete_medical WHERE athlete_id = ?");
            $checkStmt->execute([$athleteId]);
            $exists = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($exists) {
                // Update existing record
                $updateFields = [];
                $updateValues = [];

                $allowedFields = [
                    'allergies', 'allergy_severity', 'medical_conditions', 'medications',
                    'physician_name', 'physician_phone', 'physician_address',
                    'insurance_provider', 'insurance_policy_number', 'insurance_group_number',
                    'last_physical_date', 'physical_expiry_date', 'height_inches', 'weight_lbs',
                    'blood_type', 'emergency_treatment_consent', 'special_instructions',
                    'concussion_history', 'last_concussion_date', 'return_to_play_date',
                    'has_asthma', 'inhaler_location', 'has_epipen', 'epipen_location'
                ];

                foreach ($allowedFields as $field) {
                    if (isset($data[$field])) {
                        $updateFields[] = "$field = ?";
                        $updateValues[] = $data[$field];
                    }
                }

                if (!empty($updateFields)) {
                    $updateValues[] = $athleteId;
                    $stmt = $pdo->prepare("
                        UPDATE athlete_medical
                        SET " . implode(', ', $updateFields) . "
                        WHERE athlete_id = ?
                    ");
                    $stmt->execute($updateValues);
                }

                echo json_encode(['success' => true, 'message' => 'Medical information updated']);
            } else {
                // Insert new record
                $stmt = $pdo->prepare("
                    INSERT INTO athlete_medical (
                        athlete_id, allergies, allergy_severity, medical_conditions, medications,
                        physician_name, physician_phone, physician_address,
                        insurance_provider, insurance_policy_number, insurance_group_number,
                        last_physical_date, physical_expiry_date, height_inches, weight_lbs,
                        blood_type, emergency_treatment_consent, special_instructions,
                        concussion_history, last_concussion_date, return_to_play_date,
                        has_asthma, inhaler_location, has_epipen, epipen_location
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $athleteId,
                    $data['allergies'] ?? null,
                    $data['allergy_severity'] ?? 'moderate',
                    $data['medical_conditions'] ?? null,
                    $data['medications'] ?? null,
                    $data['physician_name'] ?? null,
                    $data['physician_phone'] ?? null,
                    $data['physician_address'] ?? null,
                    $data['insurance_provider'] ?? null,
                    $data['insurance_policy_number'] ?? null,
                    $data['insurance_group_number'] ?? null,
                    $data['last_physical_date'] ?? null,
                    $data['physical_expiry_date'] ?? null,
                    $data['height_inches'] ?? null,
                    $data['weight_lbs'] ?? null,
                    $data['blood_type'] ?? null,
                    $data['emergency_treatment_consent'] ?? true,
                    $data['special_instructions'] ?? null,
                    $data['concussion_history'] ?? null,
                    $data['last_concussion_date'] ?? null,
                    $data['return_to_play_date'] ?? null,
                    $data['has_asthma'] ?? false,
                    $data['inhaler_location'] ?? null,
                    $data['has_epipen'] ?? false,
                    $data['epipen_location'] ?? null
                ]);

                echo json_encode(['success' => true, 'message' => 'Medical information created', 'id' => $pdo->lastInsertId()]);
            }
            break;

        case 'DELETE':
            // Delete medical information (rarely used, mainly for testing)
            $athleteId = isset($_GET['athlete_id']) ? (int)$_GET['athlete_id'] : null;

            if (!$athleteId) {
                throw new Exception('Athlete ID is required');
            }

            $stmt = $pdo->prepare("DELETE FROM athlete_medical WHERE athlete_id = ?");
            $stmt->execute([$athleteId]);

            echo json_encode(['success' => true, 'message' => 'Medical information deleted']);
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
?>