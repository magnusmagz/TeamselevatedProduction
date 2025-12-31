<?php
/**
 * Payment Plans API
 * Get available payment plans and create installments
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

    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? 'list';

    switch ($action) {
        case 'list':
            // Get available payment plans for a league
            $league_id = $_GET['league_id'] ?? null;

            if (!$league_id) {
                throw new Exception('league_id is required');
            }

            $query = "
                SELECT
                    id,
                    name,
                    description,
                    total_installments,
                    frequency,
                    down_payment_percentage,
                    auto_pay_required,
                    late_fee_amount,
                    grace_period_days
                FROM payment_plans
                WHERE league_id = :league_id AND active = true
                ORDER BY total_installments ASC
            ";

            $stmt = $pdo->prepare($query);
            $stmt->execute(['league_id' => $league_id]);
            $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'plans' => $plans
            ]);
            break;

        case 'calculate':
            // Calculate installment schedule for a given plan and amount
            $plan_id = $_GET['plan_id'] ?? null;
            $amount = $_GET['amount'] ?? 0;

            if (!$plan_id || !$amount) {
                throw new Exception('plan_id and amount are required');
            }

            $stmt = $pdo->prepare("SELECT * FROM payment_plans WHERE id = :plan_id AND active = true");
            $stmt->execute(['plan_id' => $plan_id]);
            $plan = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$plan) {
                throw new Exception('Payment plan not found');
            }

            $amount = floatval($amount);
            $downPaymentPct = floatval($plan['down_payment_percentage']) / 100;
            $downPayment = round($amount * $downPaymentPct, 2);
            $remainingAmount = $amount - $downPayment;
            $installmentCount = intval($plan['total_installments']) - 1; // -1 for down payment
            $installmentAmount = $installmentCount > 0 ? round($remainingAmount / $installmentCount, 2) : 0;

            // Calculate due dates
            $installments = [];
            $today = new DateTime();

            // Down payment is due today
            $installments[] = [
                'number' => 1,
                'amount' => $downPayment,
                'due_date' => $today->format('Y-m-d'),
                'is_down_payment' => true
            ];

            // Future installments
            for ($i = 1; $i <= $installmentCount; $i++) {
                $dueDate = clone $today;

                if ($plan['frequency'] === 'weekly') {
                    $dueDate->modify("+{$i} week");
                } else {
                    $dueDate->modify("+{$i} month");
                }

                // Adjust last installment for rounding
                $amt = $installmentAmount;
                if ($i === $installmentCount) {
                    $amt = $remainingAmount - ($installmentAmount * ($installmentCount - 1));
                }

                $installments[] = [
                    'number' => $i + 1,
                    'amount' => round($amt, 2),
                    'due_date' => $dueDate->format('Y-m-d'),
                    'is_down_payment' => false
                ];
            }

            echo json_encode([
                'success' => true,
                'plan' => $plan,
                'total_amount' => $amount,
                'down_payment' => $downPayment,
                'installments' => $installments
            ]);
            break;

        case 'apply':
            // Apply a payment plan to an athlete payment
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit;
            }

            $data = json_decode(file_get_contents("php://input"), true);

            $athletePaymentId = $data['athlete_payment_id'] ?? null;
            $planId = $data['plan_id'] ?? null;

            if (!$athletePaymentId || !$planId) {
                throw new Exception('athlete_payment_id and plan_id are required');
            }

            // Get the athlete payment
            $stmt = $pdo->prepare("SELECT * FROM athlete_payments WHERE id = :id");
            $stmt->execute(['id' => $athletePaymentId]);
            $athletePayment = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$athletePayment) {
                throw new Exception('Athlete payment not found');
            }

            // Get the plan
            $stmt = $pdo->prepare("SELECT * FROM payment_plans WHERE id = :id AND active = true");
            $stmt->execute(['id' => $planId]);
            $plan = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$plan) {
                throw new Exception('Payment plan not found');
            }

            $pdo->beginTransaction();

            try {
                // Update athlete payment with plan reference
                $stmt = $pdo->prepare("
                    UPDATE athlete_payments
                    SET payment_plan_id = :plan_id, updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id
                ");
                $stmt->execute(['plan_id' => $planId, 'id' => $athletePaymentId]);

                // Calculate and create installments
                $amount = floatval($athletePayment['final_amount']);
                $downPaymentPct = floatval($plan['down_payment_percentage']) / 100;
                $downPayment = round($amount * $downPaymentPct, 2);
                $remainingAmount = $amount - $downPayment;
                $installmentCount = intval($plan['total_installments']) - 1;
                $installmentAmount = $installmentCount > 0 ? round($remainingAmount / $installmentCount, 2) : 0;

                $today = new DateTime();

                // Insert down payment installment
                $stmt = $pdo->prepare("
                    INSERT INTO payment_installments
                    (athlete_payment_id, payment_plan_id, installment_number, amount, due_date, status, auto_pay_enabled)
                    VALUES (:ap_id, :plan_id, 1, :amount, :due_date, 'scheduled', :auto_pay)
                ");
                $stmt->execute([
                    'ap_id' => $athletePaymentId,
                    'plan_id' => $planId,
                    'amount' => $downPayment,
                    'due_date' => $today->format('Y-m-d'),
                    'auto_pay' => $plan['auto_pay_required'] ? 'true' : 'false'
                ]);

                // Insert future installments
                for ($i = 1; $i <= $installmentCount; $i++) {
                    $dueDate = clone $today;

                    if ($plan['frequency'] === 'weekly') {
                        $dueDate->modify("+{$i} week");
                    } else {
                        $dueDate->modify("+{$i} month");
                    }

                    $amt = $installmentAmount;
                    if ($i === $installmentCount) {
                        $amt = $remainingAmount - ($installmentAmount * ($installmentCount - 1));
                    }

                    $stmt = $pdo->prepare("
                        INSERT INTO payment_installments
                        (athlete_payment_id, payment_plan_id, installment_number, amount, due_date, status, auto_pay_enabled)
                        VALUES (:ap_id, :plan_id, :num, :amount, :due_date, 'scheduled', :auto_pay)
                    ");
                    $stmt->execute([
                        'ap_id' => $athletePaymentId,
                        'plan_id' => $planId,
                        'num' => $i + 1,
                        'amount' => round($amt, 2),
                        'due_date' => $dueDate->format('Y-m-d'),
                        'auto_pay' => $plan['auto_pay_required'] ? 'true' : 'false'
                    ]);
                }

                $pdo->commit();

                echo json_encode([
                    'success' => true,
                    'message' => 'Payment plan applied successfully',
                    'down_payment' => $downPayment,
                    'total_installments' => $plan['total_installments']
                ]);

            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
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
