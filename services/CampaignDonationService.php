<?php
/**
 * CampaignDonationService — fundraiser donations on the Stripe Connect rail.
 *
 * Before 2026-09-14 the public campaign page POSTed a raw card number to
 * campaign-donations.php?action=create, which ran PaymentProcessorFactory in
 * PAYMENT_MODE=demo and recorded a pretend donation. Donations now go the way
 * contribution links do (ContributionLinkService): a Stripe-hosted Checkout
 * Session on the CLUB's connected account, and the donation row is written by
 * the webhook when Stripe says the session was paid — never by the browser.
 *
 * Idempotency: `campaign_donations.maverick_transaction_id` holds the Stripe
 * PaymentIntent id (the column predates Stripe; its name is legacy, its
 * meaning is "processor transaction id" — same reuse as payment_transactions
 * before migration 043 added the processor_* columns). A replayed webhook
 * finds the row and records nothing.
 *
 * Totals: `fundraiser_campaigns.amount_raised` / `donor_count` are maintained
 * by the trigger in migration 007 on succeeded rows — nothing here updates them.
 */

require_once __DIR__ . '/PaymentService.php';

class CampaignDonationException extends Exception {}

class CampaignDonationService {
    private PDO $pdo;
    private $gateway;
    private int $platformFeeBps;

    public const MIN_CENTS = 100;

    public function __construct(PDO $pdo, $gateway = null, int $platformFeeBps = 0) {
        $this->pdo = $pdo;
        $this->gateway = $gateway;
        $this->platformFeeBps = max(0, $platformFeeBps);
    }

    /** True when a Checkout Session's metadata marks it as a campaign donation. */
    public static function isCampaignSession(array $meta): bool {
        return (int) ($meta['campaign_id'] ?? 0) > 0;
    }

    /**
     * Mint a guest Checkout Session for a donation. The amount is validated
     * against the campaign (active, not ended, goal cap when the campaign does
     * not allow exceeding it) and the club's connected account must be able
     * to take charges. Returns ['url', 'session_id'].
     */
    public function createCheckout(int $campaignId, float $amount, array $donor,
                                   string $successUrl, string $cancelUrl): array {
        if ($this->gateway === null) {
            throw new CampaignDonationException('Stripe gateway not configured');
        }

        $stmt = $this->pdo->prepare("
            SELECT fc.id, fc.club_id, fc.title, fc.status, fc.end_date, fc.goal_amount,
                   fc.amount_raised, fc.allow_exceed_goal, cp.name AS club_name
            FROM fundraiser_campaigns fc
            JOIN club_profile cp ON cp.id = fc.club_id
            WHERE fc.id = ? AND fc.deleted_at IS NULL
        ");
        $stmt->execute([$campaignId]);
        $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$campaign) {
            throw new CampaignDonationException('Campaign not found');
        }
        if ($campaign['status'] !== 'active') {
            throw new CampaignDonationException('This campaign is not accepting donations');
        }
        // Date-only compare on the string — never strtotime() a YYYY-MM-DD (CLAUDE.md).
        if (substr((string) $campaign['end_date'], 0, 10) < date('Y-m-d')) {
            throw new CampaignDonationException('This campaign has ended');
        }

        $amountCents = (int) round($amount * 100);
        if ($amountCents < self::MIN_CENTS) {
            throw new PaymentValidationException('Minimum donation is $1.00');
        }

        $name = mb_substr(trim((string) ($donor['name'] ?? '')), 0, 200);
        $email = mb_substr(strtolower(trim((string) ($donor['email'] ?? ''))), 0, 255);
        if ($name === '') {
            throw new PaymentValidationException('Please tell us your name');
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new PaymentValidationException('A valid email address is required for your receipt');
        }

        $allowExceed = filter_var($campaign['allow_exceed_goal'] ?? true, FILTER_VALIDATE_BOOLEAN);
        if (!$allowExceed) {
            $goalCents = (int) round(((float) $campaign['goal_amount']) * 100);
            $raisedCents = (int) round(((float) $campaign['amount_raised']) * 100);
            $remainingCents = max(0, $goalCents - $raisedCents);
            if ($remainingCents <= 0) {
                throw new CampaignDonationException('This campaign has reached its goal — thank you!');
            }
            if ($amountCents > $remainingCents) {
                throw new PaymentValidationException(
                    'Only $' . number_format($remainingCents / 100, 2) . ' is still needed to reach the goal');
            }
        }

        $acctStmt = $this->pdo->prepare("
            SELECT stripe_account_id, charges_enabled FROM club_payment_accounts WHERE club_id = ?
        ");
        $acctStmt->execute([$campaign['club_id']]);
        $acct = $acctStmt->fetch(PDO::FETCH_ASSOC);
        if (!$acct || !$acct['charges_enabled']) {
            throw new CampaignDonationException('This club is not yet set up to accept online donations');
        }

        $metadata = [
            'campaign_id' => (string) $campaign['id'],
            'club_id' => (string) $campaign['club_id'],
            'donor_name' => $name,
            'donor_email' => $email,
            'donor_phone' => mb_substr(trim((string) ($donor['phone'] ?? '')), 0, 30),
            'donor_anonymous' => !empty($donor['anonymous']) ? '1' : '0',
            'donor_comment' => mb_substr(trim((string) ($donor['comment'] ?? '')), 0, 500),
        ];

        $params = [
            'mode' => 'payment',
            'line_items' => [[
                'price_data' => [
                    'currency' => 'usd',
                    'product_data' => ['name' => 'Donation — ' . $campaign['title']],
                    'unit_amount' => $amountCents,
                ],
                'quantity' => 1,
            ]],
            'customer_email' => $email,
            'metadata' => $metadata,
            'payment_intent_data' => ['metadata' => $metadata],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'expires_at' => time() + 1800,
        ];
        if ($this->platformFeeBps > 0) {
            $params['payment_intent_data']['application_fee_amount'] =
                (int) round($amountCents * $this->platformFeeBps / 10000);
        }

        $session = $this->gateway->createCheckoutSession($params, $acct['stripe_account_id']);
        return ['url' => $session['url'], 'session_id' => $session['id']];
    }

    /**
     * Webhook-side. Writes the succeeded donation row from a paid session and
     * returns what the receipt email needs, or null when the session is not a
     * campaign donation or the PaymentIntent was already recorded (replay).
     * Runs inside the webhook's transaction.
     */
    public function recordDonation(array $meta, array $session): ?array {
        if (!self::isCampaignSession($meta)) {
            return null;
        }
        $campaignId = (int) $meta['campaign_id'];
        $paymentIntent = (string) ($session['payment_intent'] ?? '');
        if ($paymentIntent === '') {
            throw new CampaignDonationException('Paid session without a payment_intent');
        }

        $dup = $this->pdo->prepare("SELECT id FROM campaign_donations WHERE maverick_transaction_id = ?");
        $dup->execute([$paymentIntent]);
        if ($dup->fetchColumn()) {
            return null;
        }

        $cstmt = $this->pdo->prepare("
            SELECT fc.id, fc.club_id, fc.title, cp.name AS club_name
            FROM fundraiser_campaigns fc JOIN club_profile cp ON cp.id = fc.club_id
            WHERE fc.id = ?
        ");
        $cstmt->execute([$campaignId]);
        $campaign = $cstmt->fetch(PDO::FETCH_ASSOC);
        if (!$campaign) {
            throw new CampaignDonationException('Paid session for unknown campaign ' . $campaignId);
        }

        $amount = ((int) ($session['amount_total'] ?? 0)) / 100;
        $name = ($meta['donor_name'] ?? '') !== '' ? $meta['donor_name'] : 'Anonymous';
        $email = ($meta['donor_email'] ?? '') !== '' ? $meta['donor_email']
            : (string) ($session['customer_details']['email'] ?? $session['customer_email'] ?? '');

        $insert = $this->pdo->prepare("
            INSERT INTO campaign_donations (
                campaign_id, donor_name, donor_email, donor_phone,
                is_anonymous, amount, comment,
                maverick_transaction_id, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'succeeded')
        ");
        $insert->execute([
            $campaignId,
            $name,
            $email,
            ($meta['donor_phone'] ?? '') !== '' ? $meta['donor_phone'] : null,
            ($meta['donor_anonymous'] ?? '0') === '1' ? 1 : 0,
            $amount,
            ($meta['donor_comment'] ?? '') !== '' ? $meta['donor_comment'] : null,
            $paymentIntent,
        ]);
        $donationId = (int) $this->pdo->lastInsertId();

        return [
            'donation_id' => $donationId,
            'to' => $email,
            'name' => $name,
            'amount' => $amount,
            'campaign_title' => $campaign['title'],
            'club_name' => $campaign['club_name'],
            'club_id' => (int) $campaign['club_id'],
            'transaction_id' => $paymentIntent,
        ];
    }
}
