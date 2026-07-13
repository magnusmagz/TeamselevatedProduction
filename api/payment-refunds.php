<?php
/**
 * Payment Refunds API — club-admin refund of a Stripe payment.
 *
 * POST /api/payment-refunds.php
 *   body: { transaction_id: int, amount?: number }   (amount omitted = full remaining)
 *   -> { success, refund_id, amount }
 *
 * This endpoint only ASKS Stripe for the refund. The ledger reversal happens
 * when Stripe confirms via the charge.refunded webhook
 * (PaymentService::applyProcessorRefund) — one source of truth, no double
 * bookkeeping if the API call succeeds but our DB write wouldn't.
 *
 * Errors: 400 bad input/over-refund · 401 unauthenticated · 403 not club admin ·
 *         404 unknown transaction · 502 Stripe failure · 503 not configured
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/StripeGateway.php';
require_once __DIR__ . '/../lib/AuditLog.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$auth = AuthMiddleware::requireAuth();

try {
    $pdo = Database::getInstance()->getConnection();
    $body = json_decode(file_get_contents('php://input'), true) ?: [];

    $transactionId = (int) ($body['transaction_id'] ?? 0);
    if ($transactionId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'transaction_id is required']);
        exit;
    }

    // Refunds are the one place a human moves money — a reason is mandatory
    // and lands in the audit trail alongside who asked and from where.
    $reason = trim((string) ($body['reason'] ?? ''));
    if ($reason === '' || mb_strlen($reason) > 500) {
        http_response_code(400);
        echo json_encode(['error' => 'A reason is required (up to 500 characters)']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT id, amount, refund_amount, processor, processor_transaction_id
        FROM payment_transactions WHERE id = ?
    ");
    $stmt->execute([$transactionId]);
    $txn = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$txn) {
        http_response_code(404);
        echo json_encode(['error' => 'Transaction not found']);
        exit;
    }
    if ($txn['processor'] !== 'stripe' || empty($txn['processor_transaction_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Only Stripe payments can be refunded here']);
        exit;
    }

    // Resolve the club through the transaction's allocations (invoice -> program/athlete club).
    $clubStmt = $pdo->prepare("
        SELECT DISTINCT COALESCE(p.club_id, a.club_id) AS club_id
        FROM payment_allocations pa
        JOIN invoices i ON i.id = pa.invoice_id
        LEFT JOIN programs p ON p.id = i.program_id
        LEFT JOIN athletes a ON a.id = i.athlete_id
        WHERE pa.payment_transaction_id = ?
    ");
    $clubStmt->execute([$transactionId]);
    $clubIds = array_filter($clubStmt->fetchAll(PDO::FETCH_COLUMN));
    if (count($clubIds) !== 1) {
        http_response_code(400);
        echo json_encode(['error' => 'Cannot resolve a single club for this transaction']);
        exit;
    }
    $clubId = (int) $clubIds[0];

    if (!$auth->can('manage_club', $clubId, 'club')) {
        http_response_code(403);
        echo json_encode(['error' => 'You do not have permission to refund payments for this club']);
        exit;
    }

    $paidCents = (int) round(((float) $txn['amount']) * 100);
    $refundedCents = (int) round(((float) $txn['refund_amount']) * 100);
    $remainingCents = $paidCents - $refundedCents;
    $requestCents = isset($body['amount']) ? (int) round(((float) $body['amount']) * 100) : $remainingCents;

    if ($requestCents <= 0 || $requestCents > $remainingCents) {
        http_response_code(400);
        echo json_encode(['error' => 'Refund amount must be between $0.01 and the unrefunded balance']);
        exit;
    }

    $acctStmt = $pdo->prepare("SELECT stripe_account_id FROM club_payment_accounts WHERE club_id = ?");
    $acctStmt->execute([$clubId]);
    $stripeAccountId = $acctStmt->fetchColumn();
    if (!$stripeAccountId) {
        http_response_code(400);
        echo json_encode(['error' => 'Club has no payment account']);
        exit;
    }

    try {
        $gateway = new StripeGateway();
    } catch (RuntimeException $e) {
        http_response_code(503);
        echo json_encode(['error' => 'Stripe is not configured on this environment']);
        exit;
    }

    $refund = $gateway->refundPayment($txn['processor_transaction_id'], $requestCents, $stripeAccountId);

    AuditLog::record($pdo, $auth->getUserId(), 'payment.refund_requested', 'payment_transaction', $transactionId, [
        'amount' => $requestCents / 100,
        'reason' => $reason,
        'club_id' => $clubId,
        'stripe_refund_id' => $refund['id'] ?? null,
        'stripe_payment_intent' => $txn['processor_transaction_id'],
    ]);

    echo json_encode([
        'success' => true,
        'refund_id' => $refund['id'],
        'amount' => $requestCents / 100,
        'note' => 'Ledger updates when Stripe confirms via webhook',
    ]);
} catch (\Stripe\Exception\ApiErrorException $e) {
    error_log('payment-refunds Stripe error: ' . $e->getMessage());
    http_response_code(502);
    echo json_encode(['error' => 'Stripe refund request failed: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log('payment-refunds error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to process refund']);
}
