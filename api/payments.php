<?php
/**
 * Payments API
 *
 * Parent-facing payment recording. The compose UI (parent-portal MakePaymentPage)
 * POSTs here to record a (demo) payment against one or more of the family's
 * invoices. Ownership is enforced server-side via PaymentService/AthleteScope —
 * the frontend selection is never trusted.
 *
 * POST /api/payments.php
 *   body: { invoice_ids: int[], amount: number, payment_method_id?: string }
 *   -> { success, amount_applied, amount_unapplied, invoices: [...] }
 *
 * Errors:
 *   401 — not authenticated
 *   400 — bad input (no invoices / non-positive amount)
 *   403 — one or more invoices are outside the requester's scope
 *   500 — unexpected error
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();


require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../services/PaymentService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Authenticated requester (sends 401 + exits on failure).
$auth = AuthMiddleware::requireAuth();

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    $data = json_decode(file_get_contents('php://input'), true) ?: [];

    $invoiceIds = $data['invoice_ids'] ?? [];
    if (!is_array($invoiceIds)) {
        $invoiceIds = [$invoiceIds];
    }
    $amount = isset($data['amount']) ? (float) $data['amount'] : 0.0;

    $result = PaymentService::recordPayment($pdo, $auth, $invoiceIds, $amount);

    echo json_encode($result);
} catch (PaymentValidationException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (OwnershipException $e) {
    http_response_code(403);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to record payment']);
}
