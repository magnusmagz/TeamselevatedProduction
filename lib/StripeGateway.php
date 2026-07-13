<?php
/**
 * Thin wrapper over \Stripe\StripeClient.
 *
 * Exists so services can take "the Stripe API" as an injected dependency and unit
 * tests can hand in a PHPUnit mock of this class (StripeClient itself resolves its
 * service properties via __get magic, which makes it awkward to mock directly).
 * Keep this class logic-free: every method is a straight pass-through returning
 * plain arrays. Business logic belongs in services/StripeConnectService.php etc.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/env.php';

class StripeGateway {
    private $client;

    public function __construct(?string $apiKey = null) {
        $key = $apiKey ?? Env::get('STRIPE_SECRET_KEY');
        if (empty($key)) {
            throw new RuntimeException('STRIPE_SECRET_KEY is not configured');
        }
        $this->client = new \Stripe\StripeClient($key);
    }

    /** Create a Stripe Connect Express account. Returns the account as an array. */
    public function createExpressAccount(array $params): array {
        return $this->client->accounts->create(array_merge(['type' => 'express'], $params))->toArray();
    }

    /** Mint a one-time onboarding Account Link for a connected account. */
    public function createAccountLink(string $accountId, string $refreshUrl, string $returnUrl): array {
        return $this->client->accountLinks->create([
            'account' => $accountId,
            'refresh_url' => $refreshUrl,
            'return_url' => $returnUrl,
            'type' => 'account_onboarding',
        ])->toArray();
    }

    /** Fetch the current state of a connected account. */
    public function retrieveAccount(string $accountId): array {
        return $this->client->accounts->retrieve($accountId)->toArray();
    }

    /**
     * Create a Checkout Session. When $connectedAccount is given the session is
     * created ON that account (direct charge — funds settle to the club).
     */
    public function createCheckoutSession(array $params, ?string $connectedAccount = null): array {
        $opts = $connectedAccount ? ['stripe_account' => $connectedAccount] : [];
        return $this->client->checkout->sessions->create($params, $opts)->toArray();
    }

    /**
     * Refund a PaymentIntent, fully (null amount) or partially (cents).
     * Direct charges live on the connected account, so the refund must too.
     */
    public function refundPayment(string $paymentIntentId, ?int $amountCents = null, ?string $connectedAccount = null): array {
        $params = ['payment_intent' => $paymentIntentId];
        if ($amountCents !== null) {
            $params['amount'] = $amountCents;
        }
        $opts = $connectedAccount ? ['stripe_account' => $connectedAccount] : [];
        return $this->client->refunds->create($params, $opts)->toArray();
    }
}
