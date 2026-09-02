<?php
/**
 * Payment Failures API
 * Track and notify on failed payments
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();


require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/financial_scope.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../lib/Email.php';
require_once __DIR__ . '/../lib/feature_flags.php';

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

            // Get failure details. The club comes off the program row, never off
            // the request body.
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
                    p.club_id as club_id,
                    l.name as club_name,
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

            // ⚠️ Only `list` was scoped. This action takes a transaction_id from the
            // body and now sends real mail, so it needs the same admin gate — an
            // authenticated parent must not be able to mail another family.
            te_assert_financial_admin($auth, $pdo, ['payment' => $failure['athlete_payment_id']]);

            $clubId = $failure['club_id'] !== null ? (int)$failure['club_id'] : null;
            $clubName = $failure['club_name'] ?: 'Teams Elevated';
            $athleteName = trim($failure['athlete_first'] . ' ' . $failure['athlete_last']);
            $paymentLink = rtrim(Env::get('APP_URL', 'http://localhost:3003'), '/') . '/parent/payments';

            $notifications = [];

            // Kill switch: report the skip, never a send that did not happen.
            if (!te_feature_enabled('TRANSACTIONAL_EMAIL')) {
                echo json_encode(array_merge(
                    ['notifications' => $notifications],
                    te_feature_disabled_response('TRANSACTIONAL_EMAIL')
                ));
                break;
            }

            // Notify parent
            if ($notifyParent && $failure['guardian_email']) {
                $parentSent = (new Email())->forClub($pdo, $clubId)->sendPaymentFailureNotice(
                    $failure['guardian_email'],
                    $failure['guardian_first'] ?: 'there',
                    $athleteName,
                    $failure['item_name'] ?: 'Registration Fee',
                    $failure['program_name'] ?: '',
                    $failure['amount'],
                    $failure['failure_reason'],
                    $clubName,
                    $paymentLink,
                    false
                );

                $notifications[] = array_filter([
                    'type' => 'parent',
                    'email' => $failure['guardian_email'],
                    'sent' => $parentSent,
                    'error' => $parentSent ? null : 'Provider rejected the message'
                ], function ($v) { return $v !== null; });
            }

            // Notify admin/treasurer
            if ($notifyAdmin && $failure['league_email']) {
                $adminSent = (new Email())->forClub($pdo, $clubId)->sendPaymentFailureNotice(
                    $failure['league_email'],
                    $clubName,
                    $athleteName,
                    $failure['item_name'] ?: 'Registration Fee',
                    $failure['program_name'] ?: '',
                    $failure['amount'],
                    $failure['failure_reason'],
                    $clubName,
                    $paymentLink,
                    true
                );

                $notifications[] = array_filter([
                    'type' => 'admin',
                    'email' => $failure['league_email'],
                    'sent' => $adminSent,
                    'error' => $adminSent ? null : 'Provider rejected the message'
                ], function ($v) { return $v !== null; });
            }

            // `sent` per row is the truth; `success` is false if ANY attempted send
            // failed, so a caller that only reads the top level is not misled.
            $failed = array_filter($notifications, function ($n) { return empty($n['sent']); });
            $response = [
                'success' => count($failed) === 0,
                'notifications' => $notifications,
                'sent_count' => count($notifications) - count($failed)
            ];
            if ($failed) {
                http_response_code(502);
                $response['error'] = count($failed) . ' of ' . count($notifications) . ' notifications could not be sent';
            }
            echo json_encode($response);
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

            $stmt = $pdo->prepare("SELECT athlete_payment_id FROM payment_transactions WHERE id = :transaction_id");
            $stmt->execute(['transaction_id' => $transactionId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new Exception('Transaction not found');
            }
            te_assert_financial_admin($auth, $pdo, ['payment' => $row['athlete_payment_id']]);

            // ⚠️ There is nowhere to record this. payment_transactions has no
            // resolved_at / resolution / resolution_notes column in live Neon
            // (checked against tests/fixtures/production-schema.json), so the old
            // handler logged a line and answered "Failure marked as resolved" —
            // a claim about stored state that does not exist. The `list` action
            // above already filters on pt.resolved_at, which is why
            // ?action=list&status=pending errors out.
            //
            // Saying so is the honest answer until a migration adds the columns.
            error_log("payment-failures: resolve requested for transaction $transactionId ($resolution) — not recorded, no column exists");
            http_response_code(501);
            echo json_encode([
                'success' => false,
                'resolved' => false,
                'error' => 'Resolution cannot be recorded: payment_transactions has no resolved_at column yet.'
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

            te_assert_financial_admin($auth, $pdo, ['payment' => $original['athlete_payment_id']]);

            // Nothing is charged here and nothing pretends to be. This hands back
            // the checkout URL the family has to complete themselves; `retried`
            // says plainly that no charge was attempted.
            echo json_encode([
                'success' => true,
                'retried' => false,
                'message' => 'No charge was attempted. Send the family to checkout to pay again.',
                'athlete_payment_id' => $original['athlete_payment_id'],
                'amount' => $original['amount'],
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
