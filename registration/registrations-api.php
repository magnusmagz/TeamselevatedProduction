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
            // Get registrations — filter by program_id or athlete_id
            $program_id = $_GET['program_id'] ?? null;
            $athlete_id = $_GET['athlete_id'] ?? null;

            if ($athlete_id) {
                // Get all registrations for a specific athlete
                $stmt = $connection->prepare("
                    SELECT r.id, r.program_id, r.athlete_id, r.status, r.submitted_at, r.reviewed_at,
                           p.name as program_name, p.season, p.start_date, p.end_date,
                           i.id as invoice_id, i.invoice_number, i.total_amount, i.amount_paid, i.status as invoice_status
                    FROM registrations r
                    LEFT JOIN programs p ON r.program_id = p.id
                    LEFT JOIN athlete_payments ap ON ap.athlete_id = r.athlete_id AND ap.program_id = r.program_id
                    LEFT JOIN invoices i ON i.athlete_payment_id = ap.id
                    WHERE r.athlete_id = ?
                    ORDER BY r.submitted_at DESC
                ");
                $stmt->execute([$athlete_id]);
            } else {
                // Get registrations for a program (existing behavior)
                $stmt = $connection->prepare("
                    SELECT r.*, p.name as program_name,
                           i.id as invoice_id, i.invoice_number, i.total_amount, i.status as invoice_status
                    FROM registrations r
                    LEFT JOIN programs p ON r.program_id = p.id
                    LEFT JOIN athlete_payments ap ON ap.athlete_id = r.athlete_id AND ap.program_id = r.program_id
                    LEFT JOIN invoices i ON i.athlete_payment_id = ap.id
                    WHERE r.program_id = ?
                    ORDER BY r.submitted_at DESC
                ");
                $stmt->execute([$program_id ?? 0]);
            }

            $registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Decode JSON form data if present
            foreach ($registrations as &$registration) {
                if (isset($registration['form_data'])) {
                    $registration['form_data'] = json_decode($registration['form_data'], true);
                }
            }

            echo json_encode($registrations);
            break;

        case 'POST':
            // Submit new registration
            $data = json_decode(file_get_contents("php://input"), true);

            // Validate program exists and is open for registration
            $stmt = $connection->prepare("
                SELECT id, status, registration_closes, registration_fee
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

                // Create athlete record (address will be collected later)
                $stmt = $connection->prepare("
                    INSERT INTO athletes (
                        first_name, last_name, date_of_birth, gender,
                        grade_level, created_at, active_status
                    ) VALUES (?, ?, ?, ?, ?, NOW(), TRUE)
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
                        athlete_id, guardian_id, relationship,
                        is_primary, created_at
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

                // Get payment item for this program (registration fee)
                $stmt = $connection->prepare("
                    SELECT id, base_price, allow_payment_plan
                    FROM payment_items
                    WHERE program_id = ? AND item_type = 'registration' AND active = true
                    LIMIT 1
                ");
                $stmt->execute([$data['program_id']]);
                $paymentItem = $stmt->fetch(PDO::FETCH_ASSOC);

                $athlete_payment_id = null;
                $payment_amount = 0;
                $sibling_discount = 0;
                $sibling_discount_applied = false;
                $payment_item_id = null;

                // Use payment_item if exists, otherwise fall back to program's registration_fee
                if ($paymentItem) {
                    $payment_amount = floatval($paymentItem['base_price']);
                    $payment_item_id = $paymentItem['id'];
                } elseif ($program['registration_fee']) {
                    $payment_amount = floatval($program['registration_fee']);
                }

                if ($payment_amount > 0) {
                    // Only process payment if there's a fee

                    // Check for sibling discount (only if using payment_item with discount settings)
                    if ($payment_item_id) {
                        $stmt = $connection->prepare("
                            SELECT sibling_discount_enabled, sibling_discount_type, sibling_discount_value
                            FROM payment_items WHERE id = ?
                        ");
                        $stmt->execute([$payment_item_id]);
                        $discountSettings = $stmt->fetch(PDO::FETCH_ASSOC);

                        if ($discountSettings && $discountSettings['sibling_discount_enabled']) {
                            // Find other athletes linked to the same guardian
                            $stmt = $connection->prepare("
                                SELECT DISTINCT ag.athlete_id
                                FROM athlete_guardians ag
                                WHERE ag.guardian_id = ? AND ag.athlete_id != ?
                            ");
                            $stmt->execute([$guardian_id, $athlete_id]);
                            $siblingIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

                            if (!empty($siblingIds)) {
                                // Check if any siblings are registered for the same program
                                $placeholders = implode(',', array_fill(0, count($siblingIds), '?'));
                                $stmt = $connection->prepare("
                                    SELECT COUNT(*) FROM registrations
                                    WHERE program_id = ? AND athlete_id IN ($placeholders) AND status = 'approved'
                                ");
                                $params = array_merge([$data['program_id']], $siblingIds);
                                $stmt->execute($params);
                                $registeredSiblingCount = $stmt->fetchColumn();

                                if ($registeredSiblingCount > 0) {
                                    // Apply sibling discount
                                    $discountType = $discountSettings['sibling_discount_type'];
                                    $discountValue = floatval($discountSettings['sibling_discount_value']);

                                    if ($discountType === 'percentage') {
                                        $sibling_discount = round($payment_amount * ($discountValue / 100), 2);
                                    } else {
                                        $sibling_discount = min($discountValue, $payment_amount);
                                    }
                                    $sibling_discount_applied = true;
                                }
                            }
                        }
                    }

                    $final_amount = $payment_amount - $sibling_discount;

                    // Create athlete_payments record
                    $stmt = $connection->prepare("
                        INSERT INTO athlete_payments (
                            athlete_id, payment_item_id, program_id,
                            base_amount, discount_amount, scholarship_amount, final_amount,
                            status, amount_paid, amount_remaining, created_at
                        ) VALUES (?, ?, ?, ?, ?, 0, ?, 'pending', 0, ?, NOW())
                    ");
                    $stmt->execute([
                        $athlete_id,
                        $payment_item_id,  // Can be null if using program's registration_fee
                        $data['program_id'],
                        $payment_amount,
                        $sibling_discount,
                        $final_amount,
                        $final_amount
                    ]);
                    $athlete_payment_id = $connection->lastInsertId();
                    $payment_amount = $final_amount; // Update for response
                }

                $connection->commit();

                // Send confirmation email (optional - implement later)
                // sendConfirmationEmail($formData);

                echo json_encode([
                    'success' => true,
                    'id' => $registration_id,
                    'athlete_id' => $athlete_id,
                    'guardian_id' => $guardian_id,
                    'athlete_payment_id' => $athlete_payment_id,
                    'payment_amount' => $payment_amount,
                    'sibling_discount_applied' => $sibling_discount_applied,
                    'sibling_discount_amount' => $sibling_discount,
                    'message' => $sibling_discount_applied
                        ? 'Registration submitted successfully! Sibling discount applied.'
                        : 'Registration submitted successfully'
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

            // Get registration details
            $stmt = $connection->prepare("
                SELECT r.*, p.registration_fee
                FROM registrations r
                JOIN programs p ON r.program_id = p.id
                WHERE r.id = ?
            ");
            $stmt->execute([$registration_id]);
            $registration = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$registration) {
                http_response_code(404);
                echo json_encode(['error' => 'Registration not found']);
                exit();
            }

            $connection->beginTransaction();

            try {
                // Update registration status
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

                $athlete_payment_id = null;

                // If approving, ensure athlete_payment exists
                if ($data['status'] === 'approved') {
                    // Check if athlete_payment already exists
                    $stmt = $connection->prepare("
                        SELECT id FROM athlete_payments
                        WHERE athlete_id = ? AND program_id = ?
                    ");
                    $stmt->execute([$registration['athlete_id'], $registration['program_id']]);
                    $existingPayment = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($existingPayment) {
                        $athlete_payment_id = $existingPayment['id'];
                    } elseif ($registration['registration_fee'] && floatval($registration['registration_fee']) > 0) {
                        // Create athlete_payment from program's registration_fee
                        $fee = floatval($registration['registration_fee']);
                        $stmt = $connection->prepare("
                            INSERT INTO athlete_payments (
                                athlete_id, payment_item_id, program_id,
                                base_amount, discount_amount, scholarship_amount, final_amount,
                                status, amount_paid, amount_remaining, created_at
                            ) VALUES (?, NULL, ?, ?, 0, 0, ?, 'pending', 0, ?, NOW())
                        ");
                        $stmt->execute([
                            $registration['athlete_id'],
                            $registration['program_id'],
                            $fee,
                            $fee,
                            $fee
                        ]);
                        $athlete_payment_id = $connection->lastInsertId();
                    }
                }

                $connection->commit();

                echo json_encode([
                    'success' => true,
                    'message' => 'Registration updated',
                    'athlete_payment_id' => $athlete_payment_id
                ]);

            } catch (Exception $e) {
                $connection->rollBack();
                http_response_code(500);
                echo json_encode(['error' => $e->getMessage()]);
            }
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