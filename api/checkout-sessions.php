<?php
/**
 * Checkout Sessions API — start a hosted Stripe payment for one or more invoices.
 *
 * POST /api/checkout-sessions.php
 *   body: { invoice_ids: int[], amount: number }
 *   -> { success, url, session_id }   (redirect the payer to url)
 *
 * The webhook (api/webhooks/stripe-connect.php), not the success redirect,
 * applies the money to the ledger. Ownership, balance cap, and the club
 * charges_enabled gate are enforced in StripeCheckoutService.
 *
 * Errors: 400 bad input / exceeds balance · 401 unauthenticated ·
 *         403 invoice outside requester scope · 409 club can't accept payments ·
 *         503 Stripe/APP_URL not configured · 502 Stripe API failure · 500 other
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();


require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/StripeGateway.php';
require_once __DIR__ . '/../services/StripeCheckoutService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$auth = AuthMiddleware::requireAuth();

try {
    $pdo = Database::getInstance()->getConnection();
    $body = json_decode(file_get_contents('php://input'), true) ?: [];

    $invoiceIds = $body['invoice_ids'] ?? [];
    if (!is_array($invoiceIds)) {
        $invoiceIds = [$invoiceIds];
    }
    $amount = isset($body['amount']) ? (float) $body['amount'] : 0.0;

    $appUrl = rtrim(Env::get('APP_URL', ''), '/');
    if ($appUrl === '') {
        http_response_code(503);
        echo json_encode(['error' => 'APP_URL is not configured']);
        exit;
    }

    try {
        $gateway = new StripeGateway();
    } catch (RuntimeException $e) {
        http_response_code(503);
        echo json_encode(['error' => 'Online payments are not configured on this environment']);
        exit;
    }

    // Whitelisted return targets — never client-provided URLs (open-redirect guard).
    $returnPaths = [
        'parent' => '/parent/payments',
        'family' => '/payment/family-invoices',
    ];
    $returnPath = $returnPaths[$body['return_context'] ?? 'family'] ?? $returnPaths['family'];

    $service = new StripeCheckoutService($pdo, $gateway, (int) Env::get('PLATFORM_FEE_BPS', '0'));
    $result = $service->createInvoiceCheckout(
        $auth,
        $invoiceIds,
        $amount,
        $appUrl . $returnPath . '?checkout=success',
        $appUrl . $returnPath . '?checkout=cancelled'
    );

    echo json_encode(['success' => true, 'url' => $result['url'], 'session_id' => $result['session_id']]);
} catch (PaymentValidationException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (OwnershipException $e) {
    http_response_code(403);
    echo json_encode(['error' => $e->getMessage()]);
} catch (ClubNotPayableException $e) {
    http_response_code(409);
    echo json_encode(['error' => $e->getMessage()]);
} catch (\Stripe\Exception\ApiErrorException $e) {
    error_log('checkout-sessions Stripe error: ' . $e->getMessage());
    http_response_code(502);
    echo json_encode(['error' => 'Payment provider request failed — please try again']);
} catch (Exception $e) {
    error_log('checkout-sessions error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to start checkout']);
}
