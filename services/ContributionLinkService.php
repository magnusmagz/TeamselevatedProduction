<?php
/**
 * ContributionLinkService — the "sphere of influence" contribution link (Phase 4).
 *
 * Compliance shape (docs/payments-stripe-implementation-plan.md): every link is
 * bound to ONE invoice; contributions are ordinary payments toward a fee owed
 * to the club (merchant of record = the club's connected account); each checkout
 * is capped at the live remaining balance; the link auto-completes at goal.
 * There is no code path that pays a family. Contributions are not tax-deductible
 * and the UI must say so.
 *
 * Privacy: the public payload exposes only what the link creator typed
 * (display_name, message) plus goal/raised numbers — never invoice ids,
 * athlete last names, or guardian details. The token (128-bit hex) is the only
 * public identifier.
 */

require_once __DIR__ . '/PaymentService.php'; // OwnershipException, PaymentValidationException
require_once __DIR__ . '/../lib/AthleteScope.php';

class ContributionLinkException extends Exception {}

class ContributionLinkService {

    private $pdo;
    private $gateway;
    private $platformFeeBps;

    public function __construct(PDO $pdo, $gateway = null, int $platformFeeBps = 0) {
        $this->pdo = $pdo;
        $this->gateway = $gateway;
        $this->platformFeeBps = max(0, $platformFeeBps);
    }

    /**
     * Create (or return the existing active) link for an invoice. One active
     * link per invoice — split progress bars help nobody.
     * Requester must own the invoice's athlete (guardian) or manage the club.
     */
    public function createLink(AuthMiddleware $auth, int $invoiceId, string $displayName, ?string $message): array {
        $invoice = $this->loadInvoice($invoiceId);
        if (!$invoice) {
            throw new OwnershipException('Invoice not found or not accessible: ' . $invoiceId);
        }

        $accessible = $auth->isSuperAdmin() ? null : AthleteScope::accessibleAthleteIds($this->pdo, $auth);
        if ($accessible !== null && !in_array((int) $invoice['athlete_id'], $accessible, true)) {
            throw new OwnershipException('You are not authorized to share this invoice');
        }

        if ($this->remainingCents($invoice) <= 0) {
            throw new PaymentValidationException('This invoice is already paid in full');
        }

        $displayName = trim($displayName);
        if ($displayName === '' || mb_strlen($displayName) > 120) {
            throw new PaymentValidationException('A display name up to 120 characters is required');
        }

        $existing = $this->pdo->prepare("
            SELECT * FROM contribution_links WHERE invoice_id = ? AND status = 'active'
        ");
        $existing->execute([$invoiceId]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $this->linkRow($row);
        }

        $token = bin2hex(random_bytes(16)); // 128-bit
        $insert = $this->pdo->prepare("
            INSERT INTO contribution_links (token, invoice_id, created_by_user_id, display_name, message)
            VALUES (?, ?, ?, ?, ?)
        ");
        $insert->execute([$token, $invoiceId, $auth->getUserId(), $displayName,
            $message !== null ? mb_substr(trim($message), 0, 1000) : null]);

        $fetch = $this->pdo->prepare("SELECT * FROM contribution_links WHERE token = ?");
        $fetch->execute([$token]);
        return $this->linkRow($fetch->fetch(PDO::FETCH_ASSOC));
    }

    /**
     * PII-safe public payload for the /contribute/{token} page.
     * Returns null for unknown tokens (caller 404s / 410s).
     */
    public function getPublicState(string $token): ?array {
        $stmt = $this->pdo->prepare("
            SELECT cl.*, i.total_amount, i.amount_paid,
                   COALESCE(p.club_id, a.club_id) AS club_id
            FROM contribution_links cl
            JOIN invoices i ON i.id = cl.invoice_id
            LEFT JOIN programs p ON p.id = i.program_id
            LEFT JOIN athletes a ON a.id = i.athlete_id
            WHERE cl.token = ?
        ");
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $goalCents = (int) round(((float) $row['total_amount']) * 100);
        $raisedCents = (int) round(((float) $row['amount_paid']) * 100);
        $remainingCents = max(0, $goalCents - $raisedCents);

        $status = $row['status'];
        if ($status === 'active' && $remainingCents <= 0) {
            $status = 'completed';
            $this->setStatus((int) $row['id'], 'completed');
        }
        if ($status === 'active' && !empty($row['expires_at']) && strtotime($row['expires_at']) < time()) {
            $status = 'expired';
            $this->setStatus((int) $row['id'], 'expired');
        }

        $clubName = null;
        if (!empty($row['club_id'])) {
            $clubStmt = $this->pdo->prepare("SELECT name FROM club_profile WHERE id = ?");
            $clubStmt->execute([$row['club_id']]);
            $clubName = $clubStmt->fetchColumn() ?: null;
        }

        $countStmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM invoice_contributions WHERE contribution_link_id = ?
        ");
        $countStmt->execute([$row['id']]);

        return [
            'display_name' => $row['display_name'],
            'message' => $row['message'],
            'club_name' => $clubName,
            'status' => $status,
            'goal' => (float) ($goalCents / 100),
            'raised' => (float) ($raisedCents / 100),
            'remaining' => (float) ($remainingCents / 100),
            'contributor_count' => (int) $countStmt->fetchColumn(),
        ];
    }

    /**
     * Mint a guest Checkout Session for a contribution. Amount is validated
     * against the LIVE remaining balance; the webhook re-validates under lock
     * and auto-refunds any race overage (Phase 3 machinery).
     */
    public function createCheckout(string $token, float $amount, array $contributor,
                                   string $successUrl, string $cancelUrl): array {
        if ($this->gateway === null) {
            throw new ContributionLinkException('Stripe gateway not configured');
        }

        $stmt = $this->pdo->prepare("
            SELECT cl.*, i.total_amount, i.amount_paid, i.invoice_number,
                   COALESCE(p.club_id, a.club_id) AS club_id
            FROM contribution_links cl
            JOIN invoices i ON i.id = cl.invoice_id
            LEFT JOIN programs p ON p.id = i.program_id
            LEFT JOIN athletes a ON a.id = i.athlete_id
            WHERE cl.token = ?
        ");
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ContributionLinkException('This link is no longer available');
        }
        if ($row['status'] !== 'active') {
            throw new ContributionLinkException('This link is closed');
        }

        $goalCents = (int) round(((float) $row['total_amount']) * 100);
        $remainingCents = max(0, $goalCents - (int) round(((float) $row['amount_paid']) * 100));
        if ($remainingCents <= 0) {
            $this->setStatus((int) $row['id'], 'completed');
            throw new ContributionLinkException('The goal has been reached — thank you!');
        }

        $amountCents = (int) round($amount * 100);
        if ($amountCents < 100) {
            throw new PaymentValidationException('Minimum contribution is $1.00');
        }
        if ($amountCents > $remainingCents) {
            throw new PaymentValidationException(
                'Only $' . number_format($remainingCents / 100, 2) . ' is still needed');
        }

        $acctStmt = $this->pdo->prepare("
            SELECT stripe_account_id, charges_enabled FROM club_payment_accounts WHERE club_id = ?
        ");
        $acctStmt->execute([$row['club_id']]);
        $acct = $acctStmt->fetch(PDO::FETCH_ASSOC);
        if (!$acct || !$acct['charges_enabled']) {
            throw new ContributionLinkException('This club is not yet set up to accept online payments');
        }

        $metadata = [
            'invoice_ids' => (string) $row['invoice_id'],
            'club_id' => (string) $row['club_id'],
            'contribution_link_id' => (string) $row['id'],
            'contributor_name' => mb_substr(trim((string) ($contributor['name'] ?? '')), 0, 200),
            'contributor_email' => mb_substr(trim((string) ($contributor['email'] ?? '')), 0, 255),
            'contributor_anonymous' => !empty($contributor['anonymous']) ? '1' : '0',
            'contributor_comment' => mb_substr(trim((string) ($contributor['comment'] ?? '')), 0, 200),
        ];

        $params = [
            'mode' => 'payment',
            'line_items' => [[
                'price_data' => [
                    'currency' => 'usd',
                    'product_data' => ['name' => 'Contribution — ' . $row['display_name']],
                    'unit_amount' => $amountCents,
                ],
                'quantity' => 1,
            ]],
            'metadata' => $metadata,
            'payment_intent_data' => ['metadata' => $metadata],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'expires_at' => time() + 1800,
        ];
        if (!empty($metadata['contributor_email'])) {
            $params['customer_email'] = $metadata['contributor_email'];
        }
        if ($this->platformFeeBps > 0) {
            $params['payment_intent_data']['application_fee_amount'] =
                (int) round($amountCents * $this->platformFeeBps / 10000);
        }

        $session = $this->gateway->createCheckoutSession($params, $acct['stripe_account_id']);
        return ['url' => $session['url'], 'session_id' => $session['id']];
    }

    /**
     * Webhook-side: record the contributor against the applied payment and
     * auto-complete the link when the invoice is paid off. Runs inside the
     * webhook's transaction.
     */
    public function recordContribution(array $meta, int $paymentTransactionId): void {
        $linkId = (int) ($meta['contribution_link_id'] ?? 0);
        if ($linkId <= 0) {
            return;
        }

        $insert = $this->pdo->prepare("
            INSERT INTO invoice_contributions
                (contribution_link_id, payment_transaction_id, contributor_name,
                 contributor_email, is_anonymous, comment)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $insert->execute([
            $linkId,
            $paymentTransactionId,
            ($meta['contributor_name'] ?? '') !== '' ? $meta['contributor_name'] : null,
            ($meta['contributor_email'] ?? '') !== '' ? $meta['contributor_email'] : null,
            !empty($meta['contributor_anonymous']) && $meta['contributor_anonymous'] === '1' ? 1 : 0,
            ($meta['contributor_comment'] ?? '') !== '' ? $meta['contributor_comment'] : null,
        ]);

        $check = $this->pdo->prepare("
            SELECT i.total_amount, i.amount_paid
            FROM contribution_links cl JOIN invoices i ON i.id = cl.invoice_id
            WHERE cl.id = ?
        ");
        $check->execute([$linkId]);
        $inv = $check->fetch(PDO::FETCH_ASSOC);
        if ($inv && (float) $inv['amount_paid'] >= (float) $inv['total_amount']) {
            $this->setStatus($linkId, 'completed');
        }
    }

    // ---- internals ---------------------------------------------------------

    private function loadInvoice(int $invoiceId): ?array {
        $stmt = $this->pdo->prepare("SELECT id, athlete_id, total_amount, amount_paid FROM invoices WHERE id = ?");
        $stmt->execute([$invoiceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function remainingCents(array $invoice): int {
        return max(0, (int) round(((float) $invoice['total_amount']) * 100)
            - (int) round(((float) $invoice['amount_paid']) * 100));
    }

    private function setStatus(int $linkId, string $status): void {
        $stmt = $this->pdo->prepare("
            UPDATE contribution_links SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?
        ");
        $stmt->execute([$status, $linkId]);
    }

    private function linkRow(array $row): array {
        return [
            'id' => (int) $row['id'],
            'token' => $row['token'],
            'invoice_id' => (int) $row['invoice_id'],
            'display_name' => $row['display_name'],
            'message' => $row['message'],
            'status' => $row['status'],
        ];
    }
}
