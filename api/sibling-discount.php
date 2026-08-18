<?php
/**
 * Sibling Discount API
 * Detects siblings and calculates automatic discounts
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();


require_once __DIR__ . '/../config/database.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    $action = $_GET['action'] ?? 'check';

    switch ($action) {
        case 'check':
            // Check if sibling discount applies for a given athlete and program
            $athleteId = $_GET['athlete_id'] ?? null;
            $programId = $_GET['program_id'] ?? null;

            if (!$athleteId || !$programId) {
                throw new Exception('athlete_id and program_id are required');
            }

            // Step 1: Get payment item for this program and check if sibling discount is enabled
            $stmt = $pdo->prepare("
                SELECT id, base_price, sibling_discount_enabled, sibling_discount_type, sibling_discount_value
                FROM payment_items
                WHERE program_id = :program_id AND item_type = 'registration' AND active = true
                LIMIT 1
            ");
            $stmt->execute(['program_id' => $programId]);
            $paymentItem = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$paymentItem || !$paymentItem['sibling_discount_enabled']) {
                echo json_encode([
                    'success' => true,
                    'applies' => false,
                    'reason' => 'Sibling discount not enabled for this program'
                ]);
                exit;
            }

            // Step 2: Find guardians for this athlete
            $stmt = $pdo->prepare("
                SELECT guardian_id FROM athlete_guardians WHERE athlete_id = :athlete_id
            ");
            $stmt->execute(['athlete_id' => $athleteId]);
            $guardianIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (empty($guardianIds)) {
                echo json_encode([
                    'success' => true,
                    'applies' => false,
                    'reason' => 'No guardians found for this athlete'
                ]);
                exit;
            }

            // Step 3: Find siblings (other athletes with the same guardians)
            $placeholders = implode(',', array_fill(0, count($guardianIds), '?'));
            $stmt = $pdo->prepare("
                SELECT DISTINCT ag.athlete_id
                FROM athlete_guardians ag
                WHERE ag.guardian_id IN ($placeholders) AND ag.athlete_id != ?
            ");
            $params = array_merge($guardianIds, [$athleteId]);
            $stmt->execute($params);
            $siblingIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (empty($siblingIds)) {
                echo json_encode([
                    'success' => true,
                    'applies' => false,
                    'reason' => 'No siblings found'
                ]);
                exit;
            }

            // Step 4: Check if any siblings are registered for the same program
            $siblingPlaceholders = implode(',', array_fill(0, count($siblingIds), '?'));
            $stmt = $pdo->prepare("
                SELECT r.athlete_id, a.first_name, a.last_name
                FROM registrations r
                JOIN athletes a ON r.athlete_id = a.id
                WHERE r.program_id = ? AND r.athlete_id IN ($siblingPlaceholders) AND r.status = 'approved'
            ");
            $params = array_merge([$programId], $siblingIds);
            $stmt->execute($params);
            $registeredSiblings = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($registeredSiblings)) {
                echo json_encode([
                    'success' => true,
                    'applies' => false,
                    'reason' => 'No siblings registered for this program',
                    'siblings_found' => count($siblingIds)
                ]);
                exit;
            }

            // Step 5: Calculate discount
            $basePrice = floatval($paymentItem['base_price']);
            $discountType = $paymentItem['sibling_discount_type'];
            $discountValue = floatval($paymentItem['sibling_discount_value']);

            if ($discountType === 'percentage') {
                $discountAmount = round($basePrice * ($discountValue / 100), 2);
            } else {
                $discountAmount = min($discountValue, $basePrice);
            }

            $siblingNames = array_map(function($s) {
                return $s['first_name'] . ' ' . $s['last_name'];
            }, $registeredSiblings);

            echo json_encode([
                'success' => true,
                'applies' => true,
                'discount_type' => $discountType,
                'discount_value' => $discountValue,
                'discount_amount' => $discountAmount,
                'base_price' => $basePrice,
                'final_price' => $basePrice - $discountAmount,
                'registered_siblings' => $siblingNames,
                'sibling_count' => count($registeredSiblings),
                'message' => 'Sibling discount applied! ' . ($discountType === 'percentage' ? $discountValue . '%' : '$' . $discountValue) . ' off for having siblings in the program.'
            ]);
            break;

        case 'detect-by-guardian':
            // Detect siblings by guardian email (for new registrations before athlete is created)
            $guardianEmail = $_GET['guardian_email'] ?? null;
            $programId = $_GET['program_id'] ?? null;

            if (!$guardianEmail || !$programId) {
                throw new Exception('guardian_email and program_id are required');
            }

            // Find guardian by email
            $stmt = $pdo->prepare("SELECT id FROM guardians WHERE LOWER(email) = LOWER(:email) LIMIT 1");
            $stmt->execute(['email' => $guardianEmail]);
            $guardian = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$guardian) {
                echo json_encode([
                    'success' => true,
                    'applies' => false,
                    'reason' => 'Guardian not found'
                ]);
                exit;
            }

            // Find athletes linked to this guardian
            $stmt = $pdo->prepare("
                SELECT ag.athlete_id, a.first_name, a.last_name
                FROM athlete_guardians ag
                JOIN athletes a ON ag.athlete_id = a.id
                WHERE ag.guardian_id = :guardian_id
            ");
            $stmt->execute(['guardian_id' => $guardian['id']]);
            $athletes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($athletes)) {
                echo json_encode([
                    'success' => true,
                    'applies' => false,
                    'reason' => 'No athletes linked to this guardian'
                ]);
                exit;
            }

            // Check if any are registered for this program
            $athleteIds = array_column($athletes, 'athlete_id');
            $placeholders = implode(',', array_fill(0, count($athleteIds), '?'));
            $stmt = $pdo->prepare("
                SELECT r.athlete_id, a.first_name, a.last_name
                FROM registrations r
                JOIN athletes a ON r.athlete_id = a.id
                WHERE r.program_id = ? AND r.athlete_id IN ($placeholders) AND r.status = 'approved'
            ");
            $params = array_merge([$programId], $athleteIds);
            $stmt->execute($params);
            $registeredSiblings = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get payment item discount info
            $stmt = $pdo->prepare("
                SELECT base_price, sibling_discount_enabled, sibling_discount_type, sibling_discount_value
                FROM payment_items
                WHERE program_id = :program_id AND item_type = 'registration' AND active = true
                LIMIT 1
            ");
            $stmt->execute(['program_id' => $programId]);
            $paymentItem = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$paymentItem || !$paymentItem['sibling_discount_enabled'] || empty($registeredSiblings)) {
                echo json_encode([
                    'success' => true,
                    'applies' => false,
                    'existing_children' => array_map(function($a) { return $a['first_name'] . ' ' . $a['last_name']; }, $athletes),
                    'registered_in_program' => count($registeredSiblings)
                ]);
                exit;
            }

            $basePrice = floatval($paymentItem['base_price']);
            $discountType = $paymentItem['sibling_discount_type'];
            $discountValue = floatval($paymentItem['sibling_discount_value']);

            if ($discountType === 'percentage') {
                $discountAmount = round($basePrice * ($discountValue / 100), 2);
            } else {
                $discountAmount = min($discountValue, $basePrice);
            }

            echo json_encode([
                'success' => true,
                'applies' => true,
                'discount_type' => $discountType,
                'discount_value' => $discountValue,
                'discount_amount' => $discountAmount,
                'base_price' => $basePrice,
                'final_price' => $basePrice - $discountAmount,
                'registered_siblings' => array_map(function($s) { return $s['first_name'] . ' ' . $s['last_name']; }, $registeredSiblings),
                'message' => 'Sibling discount available! ' . ($discountType === 'percentage' ? $discountValue . '%' : '$' . $discountValue) . ' off.'
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage()
    ]);
}
