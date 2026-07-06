<?php
/**
 * StripeCheckoutService — mint Stripe Checkout Sessions for invoice payments.
 *
 * Phase 2 of docs/payments-stripe-implementation-plan.md. A session is a
 * direct charge on the club's connected account: funds settle to the club,
 * Stripe hosts the payment page (PCI SAQ-A), and the webhook — not the
 * redirect — is what applies money to the ledger via
 * PaymentService::applyProcessorPayment.
 *
 * Enforces, server-side:
 *   - requester owns every invoice (same AthleteScope contract as PaymentService)
 *   - amount ≤ combined remaining balance (no overcollection at source)
 *   - all invoices resolve to ONE club, and that club has charges_enabled
 *
 * Dependencies injected (PDO + StripeGateway) — unit-testable with SQLite and
 * a mocked gateway, same as StripeConnectService.
 */

require_once __DIR__ . '/PaymentService.php'; // OwnershipException, PaymentValidationException
require_once __DIR__ . '/../lib/AthleteScope.php';

class ClubNotPayableException extends Exception {}

class StripeCheckoutService {

    private $pdo;
    private $gateway;
    private $platformFeeBps; // basis points of the payment taken as application fee (0 = none)

    public function __construct(PDO $pdo, $gateway, int $platformFeeBps = 0) {
        $this->pdo = $pdo;
        $this->gateway = $gateway;
        $this->platformFeeBps = max(0, $platformFeeBps);
    }

    /**
     * Create a Checkout Session paying $amount toward the given invoices.
     * Returns ['url' => hosted checkout URL, 'session_id' => cs_...].
     */
    public function createInvoiceCheckout(AuthMiddleware $auth, array $invoiceIds, float $amount,
                                          string $successUrl, string $cancelUrl): array {
        $invoiceIds = array_values(array_unique(array_map('intval', $invoiceIds)));
        if (empty($invoiceIds)) {
            throw new PaymentValidationException('At least one invoice is required');
        }
        if ($amount <= 0) {
            throw new PaymentValidationException('Payment amount must be greater than zero');
        }
        $amountCents = (int) round($amount * 100);
        if ($amountCents < 100) {
            throw new PaymentValidationException('Minimum online payment is $1.00');
        }

        // ---- Load invoices with club resolution (program's club, else athlete's) ----
        $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
        $stmt = $this->pdo->prepare("
            SELECT i.id, i.athlete_id, i.invoice_number, i.total_amount, i.amount_paid,
                   COALESCE(p.club_id, a.club_id) AS club_id
            FROM invoices i
            LEFT JOIN programs p ON p.id = i.program_id
            LEFT JOIN athletes a ON a.id = i.athlete_id
            WHERE i.id IN ($placeholders)
        ");
        $stmt->execute($invoiceIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }

        // ---- Ownership (PAR-17 contract: verify everything before acting) ----
        $accessibleAthleteIds = $auth->isSuperAdmin()
            ? null
            : AthleteScope::accessibleAthleteIds($this->pdo, $auth);
        foreach ($invoiceIds as $id) {
            if (!isset($byId[$id])) {
                throw new OwnershipException('Invoice not found or not accessible: ' . $id);
            }
            $athleteId = (int) $byId[$id]['athlete_id'];
            if ($accessibleAthleteIds !== null && !in_array($athleteId, $accessibleAthleteIds, true)) {
                throw new OwnershipException('You are not authorized to pay invoice ' . $id);
            }
        }

        // ---- Amount cap: cannot exceed combined remaining balance ----
        $remainingCents = 0;
        foreach ($invoiceIds as $id) {
            $remainingCents += max(0,
                (int) round(((float) $byId[$id]['total_amount']) * 100)
                - (int) round(((float) $byId[$id]['amount_paid']) * 100));
        }
        if ($remainingCents <= 0) {
            throw new PaymentValidationException('These invoices are already paid in full');
        }
        if ($amountCents > $remainingCents) {
            throw new PaymentValidationException('Payment exceeds the remaining balance');
        }

        // ---- One club per session, and it must be able to take money ----
        $clubIds = array_unique(array_map(fn($id) => $byId[$id]['club_id'], $invoiceIds));
        if (count($clubIds) !== 1 || $clubIds[0] === null) {
            throw new PaymentValidationException('Invoices must belong to a single club');
        }
        $clubId = (int) $clubIds[0];

        $acctStmt = $this->pdo->prepare("
            SELECT stripe_account_id, charges_enabled FROM club_payment_accounts WHERE club_id = ?
        ");
        $acctStmt->execute([$clubId]);
        $acct = $acctStmt->fetch(PDO::FETCH_ASSOC);
        if (!$acct || !$acct['charges_enabled']) {
            throw new ClubNotPayableException('This club is not yet set up to accept online payments');
        }

        // ---- Build the session (direct charge on the connected account) ----
        $numbers = array_map(fn($id) => $byId[$id]['invoice_number'], $invoiceIds);
        $label = 'Payment toward ' . implode(', ', array_slice($numbers, 0, 3))
               . (count($numbers) > 3 ? ' +' . (count($numbers) - 3) . ' more' : '');

        $payerEmail = $auth->getPayload()->email ?? null;
        $metadata = [
            'invoice_ids' => implode(',', $invoiceIds),
            'payer_user_id' => (string) $auth->getUserId(),
            'club_id' => (string) $clubId,
        ];

        $params = [
            'mode' => 'payment',
            'line_items' => [[
                'price_data' => [
                    'currency' => 'usd',
                    'product_data' => ['name' => $label],
                    'unit_amount' => $amountCents,
                ],
                'quantity' => 1,
            ]],
            'metadata' => $metadata,
            'payment_intent_data' => ['metadata' => $metadata],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'expires_at' => time() + 1800, // Stripe minimum: 30 minutes
        ];
        if (!empty($payerEmail)) {
            $params['customer_email'] = $payerEmail;
        }
        if ($this->platformFeeBps > 0) {
            $params['payment_intent_data']['application_fee_amount'] =
                (int) round($amountCents * $this->platformFeeBps / 10000);
        }

        $session = $this->gateway->createCheckoutSession($params, $acct['stripe_account_id']);

        return ['url' => $session['url'], 'session_id' => $session['id'], 'club_id' => $clubId];
    }
}
