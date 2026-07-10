<?php
/**
 * Payment Failures API
 * Track and notify on failed payments
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
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/financial_scope.php';

try {
    $auth = AuthMiddleware::requireAuth();
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    $action = $_GET['action'] ?? 'list';

    switch ($action) {
        case 'list':
            // Get failed payments requiring attention
            $league_id = $_GET['league_id'] ?? null;
            $status = $_GET['status'] ?? 'pending'; // pending, resolved, all

            if (!$league_id) {
                throw new Exception('league_id is required');
            }

            // Scope: caller must be able to access the requested league.
            te_assert_financial_admin($auth, $pdo, ['league' => $league_id]);

            $whereClause = "p.club_id = :league_id AND pt.status = 'failed'";
            if ($status === 'pending') {
                $whereClause .= " AND (pt.resolved_at IS NULL)";
            } elseif ($status === 'resolved') {
                $whereClause .= " AND (pt.resolved_at IS NOT NULL)";
            }

            $query = "
                SELECT
                    pt.id as transaction_id,
                    pt.athlete_payment_id,
                    pt.amount,
                    pt.failure_reason,
                    pt.created_at as failed_at,
                    ap.athlete_id,
                    a.first_name as athlete_first,
                    a.last_name as athlete_last,
                    g.first_name as guardian_first,
                    g.last_name as guardian_last,
                    g.email as guardian_email,
                    g.mobile_phone as guardian_phone,
                    pi.name as item_name,
                    p.name as program_name,
                    pinstall.installment_number,
                    CASE
                        WHEN pinstall.id IS NOT NULL THEN 'installment'
                        ELSE 'one-time'
                    END as payment_type
                FROM payment_transactions pt
                JOIN athlete_payments ap ON pt.athlete_payment_id = ap.id
                JOIN athletes a ON ap.athlete_id = a.id
                JOIN programs p ON ap.program_id = p.id
                LEFT JOIN athlete_guardians ag ON a.id = ag.athlete_id AND ag.is_primary = true
                LEFT JOIN guardians g ON ag.guardian_id = g.id
                LEFT JOIN payment_items pi ON ap.payment_item_id = pi.id
                LEFT JOIN payment_installments pinstall ON ap.id = pinstall.athlete_payment_id
                    AND pt.installment_number = pinstall.installment_number
                WHERE $whereClause
                ORDER BY pt.created_at DESC
            ";

            $stmt = $pdo->prepare($query);
            $stmt->execute(['league_id' => $league_id]);
            $failures = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get summary stats
            $summaryStmt = $pdo->prepare("
                SELECT
                    COUNT(*) as total_failures,
                    SUM(pt.amount) as total_amount,
                    COUNT(CASE WHEN pt.created_at > CURRENT_TIMESTAMP - INTERVAL '24 hours' THEN 1 END) as last_24h
                FROM payment_transactions pt
                JOIN athlete_payments ap ON pt.athlete_payment_id = ap.id
                JOIN programs p ON ap.program_id = p.id
                WHERE p.club_id = :league_id AND pt.status = 'failed'
            ");
            $summaryStmt->execute(['league_id' => $league_id]);
            $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'summary' => $summary,
                'failures' => $failures
            ]);
            break;

        case 'notify':
            // Send failure notification
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit;
            }

            $data = json_decode(file_get_contents("php://input"), true);
            $transactionId = $data['transaction_id'] ?? null;
            $notifyParent = $data['notify_parent'] ?? true;
            $notifyAdmin = $data['notify_admin'] ?? true;

            if (!$transactionId) {
                throw new Exception('transaction_id is required');
            }

            // Get failure details
            $stmt = $pdo->prepare("
                SELECT
                    pt.*,
                    ap.athlete_id,
                    a.first_name as athlete_first,
                    a.last_name as athlete_last,
                    g.email as guardian_email,
                    g.first_name as guardian_first,
                    pi.name as item_name,
                    p.name as program_name,
                    l.email as league_email
                FROM payment_transactions pt
                JOIN athlete_payments ap ON pt.athlete_payment_id = ap.id
                JOIN athletes a ON ap.athlete_id = a.id
                JOIN programs p ON ap.program_id = p.id
                JOIN club_profile l ON p.club_id = l.id
                LEFT JOIN athlete_guardians ag ON a.id = ag.athlete_id AND ag.is_primary = true
                LEFT JOIN guardians g ON ag.guardian_id = g.id
                LEFT JOIN payment_items pi ON ap.payment_item_id = pi.id
                WHERE pt.id = :transaction_id
            ");
            $stmt->execute(['transaction_id' => $transactionId]);
            $failure = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$failure) {
                throw new Exception('Transaction not found');
            }

            $notifications = [];

            // Notify parent
            if ($notifyParent && $failure['guardian_email']) {
                $parentMessage = "
Dear {$failure['guardian_first']},

We were unable to process your payment for {$failure['athlete_first']} {$failure['athlete_last']}.

Payment Details:
- Program: {$failure['program_name']}
- Item: {$failure['item_name']}
- Amount: $" . number_format($failure['amount'], 2) . "
- Reason: {$failure['failure_reason']}

Please update your payment method or try again:
[Payment Link]

If you have any questions, please contact us.

Thank you,
Teams Elevated
                ";

                // Log notification (in production, would send email)
                error_log("DEMO: Would send failure notification to {$failure['guardian_email']}");

                $notifications[] = [
                    'type' => 'parent',
                    'email' => $failure['guardian_email'],
                    'sent' => true
                ];
            }

            // Notify admin/treasurer
            if ($notifyAdmin && $failure['league_email']) {
                $adminMessage = "
Payment Failure Alert

A payment has failed:
- Athlete: {$failure['athlete_first']} {$failure['athlete_last']}
- Program: {$failure['program_name']}
- Amount: $" . number_format($failure['amount'], 2) . "
- Reason: {$failure['failure_reason']}
- Parent Email: {$failure['guardian_email']}

Please follow up as needed.
                ";

                error_log("DEMO: Would send admin notification to {$failure['league_email']}");

                $notifications[] = [
                    'type' => 'admin',
                    'email' => $failure['league_email'],
                    'sent' => true
                ];
            }

            echo json_encode([
                'success' => true,
                'notifications' => $notifications,
                'demo_mode' => true
            ]);
            break;

        case 'resolve':
            // Mark failure as resolved
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit;
            }

            $data = json_decode(file_get_contents("php://input"), true);
            $transactionId = $data['transaction_id'] ?? null;
            $resolution = $data['resolution'] ?? 'retry_succeeded';
            $notes = $data['notes'] ?? null;

            if (!$transactionId) {
                throw new Exception('transaction_id is required');
            }

            // Note: In a real system, we'd add resolved_at, resolved_by, resolution columns
            // For now, we'll just log it
            error_log("DEMO: Marking transaction $transactionId as resolved: $resolution");

            echo json_encode([
                'success' => true,
                'message' => 'Failure marked as resolved',
                'demo_mode' => true
            ]);
            break;

        case 'retry':
            // Retry a failed payment
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

            // Get original transaction details
            $stmt = $pdo->prepare("
                SELECT pt.*, ap.athlete_id
                FROM payment_transactions pt
                JOIN athlete_payments ap ON pt.athlete_payment_id = ap.id
                WHERE pt.id = :transaction_id
            ");
            $stmt->execute(['transaction_id' => $transactionId]);
            $original = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$original) {
                throw new Exception('Original transaction not found');
            }

            // In production, would attempt to charge saved payment method again
            // For demo, just return info about what would happen
            echo json_encode([
                'success' => true,
                'message' => 'Retry would be attempted for saved payment method',
                'athlete_payment_id' => $original['athlete_payment_id'],
                'amount' => $original['amount'],
                'demo_mode' => true,
                'redirect_to_checkout' => "/payment/checkout/{$original['athlete_id']}/{$original['athlete_payment_id']}"
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
