<?php
/**
 * Invoice Payments API — the "who paid what" ledger for one invoice.
 *
 * GET /api/invoice-payments.php?invoice_id=N
 *   -> { success, payments: [{ payer_name, amount, payment_method, status,
 *        refunded, date }] }
 *
 * Scope: requester must be able to access the invoice's athlete (AthleteScope —
 * parents see their own family, club admins their club). Powers the split-pay
 * ledger view in the parent portal and (later) the admin refund UI.
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();


require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/AthleteScope.php';

$auth = AuthMiddleware::requireAuth();

try {
    $pdo = Database::getInstance()->getConnection();

    $invoiceId = (int) ($_GET['invoice_id'] ?? 0);
    if ($invoiceId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'invoice_id is required']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT athlete_id FROM invoices WHERE id = ?");
    $stmt->execute([$invoiceId]);
    $athleteId = $stmt->fetchColumn();
    if ($athleteId === false) {
        http_response_code(404);
        echo json_encode(['error' => 'Invoice not found']);
        exit;
    }

    if (!$auth->isSuperAdmin()) {
        $accessible = AthleteScope::accessibleAthleteIds($pdo, $auth);
        if (!in_array((int) $athleteId, $accessible, true)) {
            http_response_code(403);
            echo json_encode(['error' => 'You are not authorized to view this invoice']);
            exit;
        }
    }

    $q = $pdo->prepare("
        SELECT pa.amount AS applied, pt.id AS transaction_id, pt.payment_method,
               pt.status, pt.refund_amount, pt.created_at,
               u.first_name, u.last_name
        FROM payment_allocations pa
        JOIN payment_transactions pt ON pt.id = pa.payment_transaction_id
        LEFT JOIN users u ON u.id = pt.paid_by_user_id
        WHERE pa.invoice_id = ?
        ORDER BY pt.created_at DESC, pt.id DESC
    ");
    $q->execute([$invoiceId]);

    $payments = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        $payments[] = [
            'transaction_id' => (int) $row['transaction_id'],
            'payer_name' => $name !== '' ? $name : 'Online payment',
            'amount' => (float) $row['applied'],
            'payment_method' => $row['payment_method'],
            'status' => $row['status'],
            'refunded' => (float) $row['refund_amount'] > 0,
            'date' => $row['created_at'],
        ];
    }

    echo json_encode(['success' => true, 'payments' => $payments]);
} catch (Exception $e) {
    error_log('invoice-payments error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load payment history']);
}
