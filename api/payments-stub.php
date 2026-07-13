<?php
/**
 * Stub Payment Processing Endpoint (Demo Mode)
 * Handles payment processing without real charges
 */

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/PaymentProcessorFactory.php';

// Check if demo mode is enabled
$demoMode = Env::get('PAYMENT_MODE', 'demo') === 'demo';

if (!$demoMode) {
    http_response_code(403);
    echo json_encode(['error' => 'This endpoint is only available in demo mode']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

$processor = PaymentProcessorFactory::create();

try {
    $db = Database::getInstance();
    $connection = $db->getConnection();

    switch ($action) {
        case 'process-payment':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit();
            }

            $data = json_decode(file_get_contents("php://input"), true);

            $amount = $data['amount'] ?? 0;
            $paymentMethod = $data['payment_method'] ?? [];
            $athletePaymentId = $data['athlete_payment_id'] ?? null;
            $userId = $data['user_id'] ?? null;

            if ($amount <= 0) {
                throw new Exception('Invalid amount');
            }

            if (!$athletePaymentId) {
                throw new Exception('Athlete payment ID required');
            }

            // Process payment using stub
            $result = $processor->processPayment($amount, $paymentMethod);

            if ($result['success']) {
                // Record transaction in database
                $stmt = $connection->prepare("
                    INSERT INTO payment_transactions
                    (athlete_payment_id, amount, payment_method, maverick_transaction_id, maverick_charge_id, status, payment_type, paid_by_user_id, receipt_url)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $athletePaymentId,
                    $amount,
                    'credit_card',
                    $result['transaction_id'],
                    $result['charge_id'],
                    'succeeded',
                    'full',
                    $userId,
                    $result['receipt_url']
                ]);

                $transactionId = $connection->lastInsertId();

                // Update athlete_payments
                $stmt = $connection->prepare("
                    UPDATE athlete_payments
                    SET amount_paid = amount_paid + ?,
                        amount_remaining = final_amount - (amount_paid + ?),
                        status = CASE
                            WHEN (amount_paid + ?) >= final_amount THEN 'paid'
                            WHEN (amount_paid + ?) > 0 THEN 'partial'
                            ELSE 'pending'
                        END,
                        paid_at = CASE
                            WHEN (amount_paid + ?) >= final_amount THEN CURRENT_TIMESTAMP
                            ELSE paid_at
                        END
                    WHERE id = ?
                ");

                $stmt->execute([$amount, $amount, $amount, $amount, $amount, $athletePaymentId]);

                echo json_encode([
                    'success' => true,
                    'message' => 'Payment processed successfully (DEMO)',
                    'transaction_id' => $transactionId,
                    'demo_mode' => true,
                    'payment_result' => $result
                ]);
            } else {
                // Record failed transaction
                $stmt = $connection->prepare("
                    INSERT INTO payment_transactions
                    (athlete_payment_id, amount, payment_method, status, failure_reason, paid_by_user_id)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $athletePaymentId,
                    $amount,
                    'credit_card',
                    'failed',
                    $result['error_message'],
                    $userId
                ]);

                echo json_encode([
                    'success' => false,
                    'message' => $result['error_message'],
                    'error_code' => $result['error_code'],
                    'demo_mode' => true
                ]);
            }
            break;

        case 'save-payment-method':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit();
            }

            $data = json_decode(file_get_contents("php://input"), true);

            $userId = $data['user_id'] ?? null;
            $paymentMethod = $data['payment_method'] ?? [];

            if (!$userId) {
                throw new Exception('User ID required');
            }

            // Create or get customer
            $customerResult = $processor->createCustomer(
                $data['email'] ?? '',
                $data['name'] ?? '',
                ['user_id' => $userId]
            );

            // Save payment method
            $paymentMethodResult = $processor->savePaymentMethod(
                $customerResult['customer_id'],
                $paymentMethod
            );

            // Store in database (simplified - would store in users table)
            // For demo, just return the result

            echo json_encode([
                'success' => true,
                'message' => 'Payment method saved (DEMO)',
                'payment_method_id' => $paymentMethodResult['payment_method_id'],
                'customer_id' => $customerResult['customer_id'],
                'card_last4' => $paymentMethodResult['card_last4'],
                'demo_mode' => true
            ]);
            break;

        case 'charge-saved-method':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit();
            }

            $data = json_decode(file_get_contents("php://input"), true);

            $paymentMethodId = $data['payment_method_id'] ?? '';
            $amount = $data['amount'] ?? 0;
            $athletePaymentId = $data['athlete_payment_id'] ?? null;
            $description = $data['description'] ?? '';

            $result = $processor->chargeSavedPaymentMethod($paymentMethodId, $amount, $description);

            if ($result['success']) {
                // Record transaction
                $stmt = $connection->prepare("
                    INSERT INTO payment_transactions
                    (athlete_payment_id, amount, payment_method, maverick_transaction_id, maverick_payment_method_id, status, payment_type, paid_by_user_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $athletePaymentId,
                    $amount,
                    'credit_card',
                    $result['transaction_id'],
                    $paymentMethodId,
                    'succeeded',
                    'installment',
                    $data['user_id'] ?? null
                ]);

                echo json_encode([
                    'success' => true,
                    'message' => 'Auto-payment processed (DEMO)',
                    'transaction_id' => $result['transaction_id'],
                    'demo_mode' => true
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => $result['error_message'],
                    'demo_mode' => true
                ]);
            }
            break;

        case 'refund':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit();
            }

            $data = json_decode(file_get_contents("php://input"), true);

            $transactionId = $data['transaction_id'] ?? null;
            $amount = $data['amount'] ?? 0;
            $reason = $data['reason'] ?? '';

            if (!$transactionId) {
                throw new Exception('Transaction ID required');
            }

            // Get original transaction
            $stmt = $connection->prepare("SELECT * FROM payment_transactions WHERE id = ?");
            $stmt->execute([$transactionId]);
            $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$transaction) {
                throw new Exception('Transaction not found');
            }

            // Process refund
            $result = $processor->refund($transaction['maverick_transaction_id'], $amount, $reason);

            if ($result['success']) {
                // Update transaction record
                $stmt = $connection->prepare("
                    UPDATE payment_transactions
                    SET refund_amount = ?,
                        refund_reason = ?,
                        refunded_at = CURRENT_TIMESTAMP,
                        status = 'refunded'
                    WHERE id = ?
                ");

                $stmt->execute([$amount, $reason, $transactionId]);

                // Update athlete payment
                $stmt = $connection->prepare("
                    UPDATE athlete_payments
                    SET amount_paid = amount_paid - ?,
                        amount_remaining = amount_remaining + ?,
                        status = CASE
                            WHEN amount_paid - ? <= 0 THEN 'pending'
                            ELSE 'partial'
                        END
                    WHERE id = ?
                ");

                $stmt->execute([$amount, $amount, $amount, $transaction['athlete_payment_id']]);

                echo json_encode([
                    'success' => true,
                    'message' => 'Refund processed (DEMO)',
                    'refund_id' => $result['refund_id'],
                    'amount' => $amount,
                    'demo_mode' => true
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Refund failed',
                    'demo_mode' => true
                ]);
            }
            break;

        case 'test-cards':
            // Return list of test card numbers for UI
            echo json_encode([
                'success' => true,
                'test_cards' => StubPaymentProcessor::getTestCards(),
                'demo_mode' => true
            ]);
            break;

        case 'simulate-webhook':
            // Simulate a webhook event (for testing)
            $eventType = $_GET['event_type'] ?? 'payment.succeeded';
            $event = $processor->simulateWebhook($eventType);

            echo json_encode([
                'success' => true,
                'event' => $event,
                'demo_mode' => true
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode([
                'error' => 'Invalid action',
                'available_actions' => [
                    'process-payment',
                    'save-payment-method',
                    'charge-saved-method',
                    'refund',
                    'test-cards',
                    'simulate-webhook'
                ]
            ]);
            break;
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'demo_mode' => true
    ]);
}
