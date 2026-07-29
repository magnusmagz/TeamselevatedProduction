<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Use centralized database connection
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/AthleteScope.php';
require_once __DIR__ . '/../lib/Encryption.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// This endpoint previously had NO authentication of any kind — a child's
// allergies, medications, conditions, physician and insurance details were
// readable and writable by anyone who could guess an athlete_id. It only escaped
// notice because athlete_medical did not exist, so every request errored.
$auth = AuthMiddleware::requireAuth();

/**
 * Health data is the most sensitive record in the system, so reads are scoped as
 * tightly as writes: the athlete's club admins, a coach of one of their teams, or
 * one of their guardians. Same rule the athlete endpoints use.
 */
function medicalRequireAccess(PDO $pdo, AuthMiddleware $auth, int $athleteId): void
{
    if (!AthleteScope::userCanAccessAthlete($pdo, $auth, $athleteId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit;
    }
}

/**
 * Audit trail for health-record access, per COPPA-COMPLIANCE.md ('view_medical' /
 * 'edit_medical'). Never allowed to break the request it is recording.
 */
function medicalAudit(PDO $pdo, AuthMiddleware $auth, string $action, int $athleteId): void
{
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO audit_log (user_id, action, resource_type, resource_id, ip_address, user_agent, details, created_at)
             VALUES (?, ?, 'athlete_medical', ?, ?, ?, ?, NOW())"
        );
        $stmt->execute([
            (int) $auth->getUserId() ?: null,
            $action,
            $athleteId,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
            json_encode(['athlete_id' => $athleteId]),
        ]);
    } catch (Exception $e) {
        error_log('medical-gateway audit failed: ' . $e->getMessage());
    }
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

            medicalRequireAccess($pdo, $auth, $athleteId);

            // No try/catch swallowing here any more. The old code caught the
            // "table may not exist yet" error and returned an empty record, which
            // is precisely why a missing table looked like an athlete with no
            // medical info for months. A real read failure must surface.
            $stmt = $pdo->prepare("
                SELECT * FROM athlete_medical
                WHERE athlete_id = ?
                LIMIT 1
            ");
            $stmt->execute([$athleteId]);
            $medical = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($medical) {
                // Decrypt before anything reads these — the alert block below
                // interpolates epipen_location / inhaler_location / allergies into
                // human-facing messages.
                $medical = Encryption::decryptFields($medical, Encryption::athleteMedicalFields());
                medicalAudit($pdo, $auth, 'view_medical', $athleteId);
            }

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

            $athleteId = (int) $athleteId;
            medicalRequireAccess($pdo, $auth, $athleteId);

            // Postgres booleans reject PHP false (it binds as ''), which would fail
            // the whole save. Normalise the three boolean columns before binding.
            foreach (['has_asthma', 'has_epipen', 'emergency_treatment_consent'] as $boolField) {
                if (array_key_exists($boolField, $data)) {
                    $data[$boolField] = !empty($data[$boolField]) ? 'true' : 'false';
                }
            }

            // Encrypt the free-text PHI once, here, so both the UPDATE and INSERT
            // branches below bind ciphertext without needing to know about it.
            // Throws if no key is configured — we do not store health data in the
            // clear.
            $data = Encryption::encryptFields($data, Encryption::athleteMedicalFields());

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
                    $updateFields[] = 'is_encrypted = TRUE';
                    $updateFields[] = 'updated_at = NOW()';
                    $updateValues[] = $athleteId;
                    $stmt = $pdo->prepare("
                        UPDATE athlete_medical
                        SET " . implode(', ', $updateFields) . "
                        WHERE athlete_id = ?
                    ");
                    $stmt->execute($updateValues);
                }

                medicalAudit($pdo, $auth, 'edit_medical', $athleteId);
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
                        has_asthma, inhaler_location, has_epipen, epipen_location,
                        is_encrypted
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, TRUE)
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
                    // 'true'/'false' strings, not PHP booleans — PDO binds false as
                    // '' which Postgres rejects for a boolean column.
                    $data['emergency_treatment_consent'] ?? 'true',
                    $data['special_instructions'] ?? null,
                    $data['concussion_history'] ?? null,
                    $data['last_concussion_date'] ?? null,
                    $data['return_to_play_date'] ?? null,
                    $data['has_asthma'] ?? 'false',
                    $data['inhaler_location'] ?? null,
                    $data['has_epipen'] ?? 'false',
                    $data['epipen_location'] ?? null
                ]);

                // Creating a health record is as auditable as changing one — this
                // branch was missing its audit row while the update branch had one.
                medicalAudit($pdo, $auth, 'create_medical', $athleteId);
                echo json_encode(['success' => true, 'message' => 'Medical information created', 'id' => $pdo->lastInsertId()]);
            }
            break;

        case 'DELETE':
            // Delete medical information (rarely used, mainly for testing)
            $athleteId = isset($_GET['athlete_id']) ? (int)$_GET['athlete_id'] : null;

            if (!$athleteId) {
                throw new Exception('Athlete ID is required');
            }

            medicalRequireAccess($pdo, $auth, $athleteId);

            $stmt = $pdo->prepare("DELETE FROM athlete_medical WHERE athlete_id = ?");
            $stmt->execute([$athleteId]);

            medicalAudit($pdo, $auth, 'delete_medical', $athleteId);
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