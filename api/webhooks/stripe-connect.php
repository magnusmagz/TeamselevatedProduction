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
require_once __DIR__ . '/../../services/PaymentService.php';
require_once __DIR__ . '/../../services/ContributionLinkService.php';
require_once __DIR__ . '/../../services/PaymentReportService.php';
require_once __DIR__ . '/../../lib/Email.php';

/**
 * Gather everything the payment receipt email needs (reads only).
 * Recipient comes from the payer's user record, or — for guest contributions —
 * from the email/name passed through session metadata.
 */
function buildReceiptData(PDO $pdo, ?int $payerUserId, int $clubId, array $invoiceIds, float $amount, string $ref,
                          ?string $guestEmail = null, ?string $guestName = null): ?array {
    if ($payerUserId !== null && $payerUserId > 0) {
        $userStmt = $pdo->prepare("SELECT email, first_name FROM users WHERE id = ?");
        $userStmt->execute([$payerUserId]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $user = $guestEmail ? ['email' => $guestEmail, 'first_name' => $guestName] : null;
    }
    if (!$user || empty($user['email'])) {
        return null;
    }

    $clubName = 'your club';
    if ($clubId > 0) {
        $clubStmt = $pdo->prepare("SELECT name FROM club_profile WHERE id = ?");
        $clubStmt->execute([$clubId]);
        $clubName = $clubStmt->fetchColumn() ?: $clubName;
    }

    $ph = implode(',', array_fill(0, count($invoiceIds), '?'));
    $invStmt = $pdo->prepare("SELECT invoice_number FROM invoices WHERE id IN ($ph)");
    $invStmt->execute($invoiceIds);
    $numbers = $invStmt->fetchAll(PDO::FETCH_COLUMN);

    return [
        'to' => $user['email'],
        'name' => $user['first_name'] ?: 'there',
        'amount' => $amount,
        'invoice_numbers' => $numbers,
        'club_name' => $clubName,
        'ref' => $ref,
    ];
}

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
$receiptData = null;   // populated on a fresh successful payment; sent AFTER commit
$overpayRefund = null; // populated on a race overpayment; refunded AFTER commit
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

        case 'checkout.session.completed':
            // Direct charges: this event arrives from the club's connected account.
            // Applied inline INSIDE the surrounding transaction so dedup + ledger
            // commit atomically; PaymentService is additionally idempotent on
            // (processor, transaction_id) as a second line of defense.
            $session = $event->data->object->toArray();

            if (($session['payment_status'] ?? '') !== 'paid') {
                // Delayed payment methods (ACH, Phase 5) complete later via
                // async_payment_succeeded — nothing to apply yet.
                error_log('stripe-connect webhook: session ' . $session['id'] . ' completed but unpaid (async method?)');
                break;
            }

            $meta = $session['metadata'] ?? [];
            $invoiceIds = array_values(array_filter(array_map('intval', explode(',', $meta['invoice_ids'] ?? ''))));
            if (empty($invoiceIds)) {
                // Not one of our invoice sessions (or metadata lost) — log loudly, ack quietly.
                error_log('stripe-connect webhook: checkout.session.completed without invoice_ids metadata: ' . $session['id']);
                break;
            }

            $result = PaymentService::applyProcessorPayment($pdo, [
                'processor' => 'stripe',
                'transaction_id' => $session['payment_intent'],
                'session_id' => $session['id'],
                'customer_id' => $session['customer'] ?? null,
                'amount' => $session['amount_total'] / 100,
                'invoice_ids' => $invoiceIds,
                'paid_by_user_id' => isset($meta['payer_user_id']) ? (int) $meta['payer_user_id'] : null,
                'payment_method' => 'credit_card',
            ]);

            if (!empty($result['already_applied'])) {
                error_log('stripe-connect webhook: payment ' . $session['payment_intent'] . ' already applied — replay no-op');
            } elseif ($result['amount_unapplied'] > 0) {
                // Race overpayment (two payers, same balance): auto-refund the
                // excess after commit. Refunding never-allocated money reverses
                // nothing on the ledger (pool-first refund semantics).
                $overpayRefund = [
                    'payment_intent' => $session['payment_intent'],
                    'amount_cents' => (int) round($result['amount_unapplied'] * 100),
                    'account' => $event->account ?? null,
                ];
            }

            // Contribution-link payments: record the contributor and auto-close
            // the link at goal — inside the same transaction as the ledger.
            if (empty($result['already_applied']) && !empty($meta['contribution_link_id'])) {
                (new ContributionLinkService($pdo))->recordContribution($meta, (int) $result['transaction_id']);
            }

            // Collect receipt data now (inside the txn, cheap reads); send after commit.
            if (empty($result['already_applied'])) {
                $receiptData = buildReceiptData(
                    $pdo,
                    !empty($meta['payer_user_id']) ? (int) $meta['payer_user_id'] : null,
                    (int) ($meta['club_id'] ?? 0),
                    $invoiceIds,
                    $session['amount_total'] / 100,
                    $session['payment_intent'],
                    $meta['contributor_email'] ?? null,
                    ($meta['contributor_name'] ?? '') !== '' ? $meta['contributor_name'] : null
                );
            }
            break;

        case 'payout.paid':
        case 'payout.failed':
            $payout = $event->data->object->toArray();
            $reportService = new PaymentReportService($pdo);
            if (!$reportService->applyPayoutEvent($event->account ?? null, $payout)) {
                error_log('stripe-connect webhook: payout event for unknown account ' . ($event->account ?? '?'));
            }
            break;

        case 'charge.refunded':
            // Cumulative amount_refunded makes replays + partial sequences idempotent.
            $charge = $event->data->object->toArray();
            if (empty($charge['payment_intent'])) {
                break;
            }
            $refundResult = PaymentService::applyProcessorRefund(
                $pdo, 'stripe', $charge['payment_intent'], $charge['amount_refunded'] / 100);
            if (!empty($refundResult['unknown_transaction'])) {
                error_log('stripe-connect webhook: charge.refunded for unknown transaction ' . $charge['payment_intent']);
            }
            break;

        default:
            // Not subscribed intentionally — log so a misconfigured endpoint is visible.
            error_log('stripe-connect webhook: unhandled event type ' . $event->type);
    }

    $pdo->prepare("UPDATE stripe_webhook_events SET processed_at = CURRENT_TIMESTAMP WHERE event_id = ?")
        ->execute([$event->id]);

    $pdo->commit();

    // Overpayment auto-refund is post-commit: the excess was really charged, so
    // the refund must happen even though none of it reached the ledger. If the
    // Stripe call fails we log CRITICAL for manual follow-up — never a 500,
    // which would replay an already-committed event into the dedup wall.
    if ($overpayRefund !== null) {
        try {
            require_once __DIR__ . '/../../lib/StripeGateway.php';
            (new StripeGateway())->refundPayment(
                $overpayRefund['payment_intent'], $overpayRefund['amount_cents'], $overpayRefund['account']);
            error_log('stripe-connect webhook: auto-refunded overpayment of '
                . ($overpayRefund['amount_cents'] / 100) . ' on ' . $overpayRefund['payment_intent']);
        } catch (Exception $e) {
            error_log('stripe-connect webhook: CRITICAL — overpayment auto-refund FAILED for '
                . $overpayRefund['payment_intent'] . ' (' . ($overpayRefund['amount_cents'] / 100)
                . '): ' . $e->getMessage() . ' — refund manually');
        }
    }

    // Receipt is best-effort and post-commit: a mail hiccup must never make
    // Stripe retry (and re-litigate) an already-committed payment.
    if ($receiptData !== null) {
        try {
            (new Email())->sendPaymentReceipt(
                $receiptData['to'], $receiptData['name'], $receiptData['amount'],
                $receiptData['invoice_numbers'], $receiptData['club_name'], $receiptData['ref']);
        } catch (Exception $e) {
            error_log('stripe-connect webhook: receipt email failed: ' . $e->getMessage());
        }
    }

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
