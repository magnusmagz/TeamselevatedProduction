<?php
/**
 * Stripe Connect webhook receiver.
 *
 * Configure in the Stripe dashboard as a CONNECT webhook endpoint:
 *   {API_URL}/api/webhooks/stripe-connect.php    events: account.updated
 *
 * Behavior contract (docs/payments-stripe-implementation-plan.md, Phase 1):
 *   - invalid/missing signature  -> 400 (Stripe will retry; never process unsigned payloads)
 *   - replayed event             -> 200, idempotent no-op (stripe_webhook_events dedup)
 *   - unknown connected account  -> 200, logged (never 4xx — Stripe would retry forever)
 *   - handled                    -> 200
 *
 * Follows the house webhook structure (api/webhooks/sendgrid.php): bootstrap ->
 * parse -> guarded processing -> error_log -> respond 200. Unlike sendgrid, this
 * endpoint verifies signatures — payloads here gate whether a club can take money.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../services/StripeConnectService.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$secret = Env::get('STRIPE_CONNECT_WEBHOOK_SECRET');
if (empty($secret)) {
    error_log('stripe-connect webhook: STRIPE_CONNECT_WEBHOOK_SECRET not configured — rejecting');
    http_response_code(500);
    echo json_encode(['error' => 'Webhook not configured']);
    exit;
}

$payload = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

try {
    $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $secret);
} catch (\UnexpectedValueException | \Stripe\Exception\SignatureVerificationException $e) {
    error_log('stripe-connect webhook: signature verification failed: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

$pdo = null;
try {
    $pdo = Database::getInstance()->getConnection();

    // Dedup insert + processing + processed_at stamp are one transaction: if
    // processing throws, the rollback removes the dedup row so Stripe's retry
    // can process the event instead of no-oping against a half-done record.
    $pdo->beginTransaction();

    // Idempotency gate: first writer wins, replays no-op.
    $dedup = $pdo->prepare("
        INSERT INTO stripe_webhook_events (event_id, event_type)
        VALUES (?, ?)
        ON CONFLICT (event_id) DO NOTHING
    ");
    $dedup->execute([$event->id, $event->type]);
    if ($dedup->rowCount() === 0) {
        $pdo->rollBack();
        echo json_encode(['status' => 'duplicate', 'event_id' => $event->id]);
        exit;
    }

    switch ($event->type) {
        case 'account.updated':
            $account = $event->data->object->toArray();
            $service = new StripeConnectService($pdo);
            if (!$service->applyAccountUpdate($account)) {
                error_log('stripe-connect webhook: account.updated for unknown account ' . ($account['id'] ?? '?'));
            }
            break;

        default:
            // Not subscribed intentionally — log so a misconfigured endpoint is visible.
            error_log('stripe-connect webhook: unhandled event type ' . $event->type);
    }

    $pdo->prepare("UPDATE stripe_webhook_events SET processed_at = CURRENT_TIMESTAMP WHERE event_id = ?")
        ->execute([$event->id]);

    $pdo->commit();

    echo json_encode(['status' => 'ok', 'event_id' => $event->id]);
} catch (Exception $e) {
    if ($pdo !== null && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('stripe-connect webhook error: ' . $e->getMessage());
    // 500 so Stripe retries — the event was not fully processed.
    http_response_code(500);
    echo json_encode(['error' => 'Processing failed']);
}
