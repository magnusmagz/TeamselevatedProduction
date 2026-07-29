<?php
/**
 * Payment Receipt API
 * Get receipt details and send confirmation emails
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();


require_once __DIR__ . '/../config/database.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    $action = $_GET['action'] ?? 'get';

    switch ($action) {
        case 'get':
            $transactionId = $_GET['transaction_id'] ?? null;

            if (!$transactionId) {
                throw new Exception('transaction_id is required');
            }

            // Build query based on whether ID is numeric (database row ID) or string (maverick ID)
            if (is_numeric($transactionId)) {
                $whereClause = "pt.id = :transaction_id";
            } else {
                $whereClause = "pt.maverick_transaction_id = :transaction_id";
            }

            // Get transaction details with related info
            $stmt = $pdo->prepare("
                SELECT
                    pt.id,
                    pt.maverick_transaction_id,
                    pt.amount,
                    pt.payment_method,
                    COALESCE(SUBSTRING(pt.maverick_charge_id FROM '.{4}$'), '****') as last_four,
                    pt.status,
                    pt.created_at as transaction_date,
                    ap.base_amount,
                    ap.discount_amount,
                    ap.final_amount,
                    a.first_name as athlete_first,
                    a.last_name as athlete_last,
                    g.first_name as guardian_first,
                    g.last_name as guardian_last,
                    g.email as guardian_email,
                    pi.name as item_name,
                    p.name as program_name
                FROM payment_transactions pt
                JOIN athlete_payments ap ON pt.athlete_payment_id = ap.id
                JOIN athletes a ON ap.athlete_id = a.id
                LEFT JOIN athlete_guardians ag ON a.id = ag.athlete_id AND ag.is_primary = true
                LEFT JOIN guardians g ON ag.guardian_id = g.id
                LEFT JOIN payment_items pi ON ap.payment_item_id = pi.id
                LEFT JOIN programs p ON ap.program_id = p.id
                WHERE $whereClause
            ");
            $stmt->execute(['transaction_id' => $transactionId]);
            $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$transaction) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Transaction not found']);
                exit;
            }

            // Use database ID as transaction_id if maverick_transaction_id is null
            $displayTransactionId = $transaction['maverick_transaction_id'] ?: ('TXN-' . str_pad($transaction['id'], 8, '0', STR_PAD_LEFT));

            echo json_encode([
                'success' => true,
                'receipt' => [
                    'transaction_id' => $displayTransactionId,
                    'transaction_date' => $transaction['transaction_date'],
                    'athlete_name' => $transaction['athlete_first'] . ' ' . $transaction['athlete_last'],
                    'guardian_name' => ($transaction['guardian_first'] ?? 'Parent') . ' ' . ($transaction['guardian_last'] ?? ''),
                    'guardian_email' => $transaction['guardian_email'] ?? '',
                    'item_name' => $transaction['item_name'] ?? 'Registration Fee',
                    'program_name' => $transaction['program_name'] ?? '',
                    'base_amount' => floatval($transaction['base_amount']),
                    'discount_amount' => floatval($transaction['discount_amount']),
                    'amount_paid' => floatval($transaction['amount']),
                    'payment_method' => ucfirst($transaction['payment_method'] ?: 'Card'),
                    'last_four' => $transaction['last_four'] ?: '4242',
                    'status' => $transaction['status']
                ]
            ]);
            break;

        case 'email':
            // Send receipt email
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit;
            }

            $data = json_decode(file_get_contents("php://input"), true);
            $transactionId = $data['transaction_id'] ?? null;

            if (!$transactionId) {
                throw new Exception('transaction_id is required');
            }

            // Build query based on ID type
            if (is_numeric($transactionId)) {
                $whereClause = "pt.id = :transaction_id";
            } else {
                $whereClause = "pt.maverick_transaction_id = :transaction_id";
            }

            // Get transaction and guardian email
            $stmt = $pdo->prepare("
                SELECT
                    pt.id,
                    pt.maverick_transaction_id,
                    pt.amount,
                    pt.created_at,
                    g.email as guardian_email,
                    g.first_name as guardian_first,
                    a.first_name as athlete_first,
                    a.last_name as athlete_last,
                    pi.name as item_name,
                    p.name as program_name
                FROM payment_transactions pt
                JOIN athlete_payments ap ON pt.athlete_payment_id = ap.id
                JOIN athletes a ON ap.athlete_id = a.id
                LEFT JOIN athlete_guardians ag ON a.id = ag.athlete_id AND ag.is_primary = true
                LEFT JOIN guardians g ON ag.guardian_id = g.id
                LEFT JOIN payment_items pi ON ap.payment_item_id = pi.id
                LEFT JOIN programs p ON ap.program_id = p.id
                WHERE $whereClause
            ");
            $stmt->execute(['transaction_id' => $transactionId]);
            $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$transaction) {
                throw new Exception('Transaction not found');
            }

            $guardianEmail = $transaction['guardian_email'] ?? 'demo@example.com';
            $displayTransactionId = $transaction['maverick_transaction_id'] ?: ('TXN-' . str_pad($transaction['id'], 8, '0', STR_PAD_LEFT));

            // In demo mode, just log and return success
            // In production, this would use a mail service
            $emailContent = "
                Payment Receipt

                Transaction ID: {$displayTransactionId}
                Date: {$transaction['created_at']}

                Athlete: {$transaction['athlete_first']} {$transaction['athlete_last']}
                Item: {$transaction['item_name']}
                Program: {$transaction['program_name']}

                Amount Paid: $" . number_format($transaction['amount'], 2) . "

                Thank you for your payment!
            ";

            // Log the email (in production, would actually send)
            error_log("DEMO: Would send receipt email to {$guardianEmail}");
            error_log("Email content: " . $emailContent);

            echo json_encode([
                'success' => true,
                'message' => 'Receipt sent to ' . $guardianEmail,
                'demo_mode' => true
            ]);
            break;

        case 'generate-for-payment':
            // Generate receipt for an athlete_payment after successful payment
            $athletePaymentId = $_GET['athlete_payment_id'] ?? null;

            if (!$athletePaymentId) {
                throw new Exception('athlete_payment_id is required');
            }

            // Get the most recent transaction for this payment
            $stmt = $pdo->prepare("
                SELECT id, maverick_transaction_id
                FROM payment_transactions
                WHERE athlete_payment_id = :id AND status = 'succeeded'
                ORDER BY created_at DESC
                LIMIT 1
            ");
            $stmt->execute(['id' => $athletePaymentId]);
            $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$transaction) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'No completed transaction found']);
                exit;
            }

            echo json_encode([
                'success' => true,
                'transaction_id' => $transaction['id'] // Return database ID
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
