<?php
/**
 * StripeConnectService — club onboarding onto Stripe Connect (Express accounts).
 *
 * Phase 1 of docs/payments-stripe-implementation-plan.md. Owns the
 * club_payment_accounts table: one connected account per club, with local flags
 * (charges_enabled / payouts_enabled / details_submitted) kept in sync by
 * api/webhooks/stripe-connect.php calling applyAccountUpdate().
 *
 * Dependencies are injected (PDO + StripeGateway) so the logic runs against an
 * in-memory SQLite fixture and a mocked gateway in tests — same contract as
 * PaymentService. SQL is portable between SQLite and PostgreSQL.
 *
 * Authorization is NOT enforced here — api/payment-accounts.php gates every
 * action with $auth->can('manage_club', $clubId, 'club') before calling in.
 */

class StripeConnectException extends Exception {}

class StripeConnectService {

    private $pdo;
    private $gateway; // StripeGateway|null — null is fine for webhook-only use (applyAccountUpdate)

    public function __construct(PDO $pdo, $gateway = null) {
        $this->pdo = $pdo;
        $this->gateway = $gateway;
    }

    /** The club's payment account row, or null if onboarding was never started. */
    public function getStatus(int $clubId): ?array {
        $stmt = $this->pdo->prepare("
            SELECT id, club_id, stripe_account_id, onboarding_status,
                   charges_enabled, payouts_enabled, details_submitted,
                   created_at, updated_at
            FROM club_payment_accounts
            WHERE club_id = ?
        ");
        $stmt->execute([$clubId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->normalizeRow($row);
    }

    /**
     * Start (or resume) onboarding for a club: create the Express account on
     * first call, reuse it afterwards, and mint a fresh Account Link either way.
     * Returns ['url' => onboarding URL, 'account' => status row].
     */
    public function startOnboarding(int $clubId, int $userId, string $clubName, string $clubEmail,
                                    string $refreshUrl, string $returnUrl): array {
        $this->requireGateway();

        $existing = $this->getStatus($clubId);

        if ($existing) {
            $accountId = $existing['stripe_account_id'];
        } else {
            $account = $this->gateway->createExpressAccount([
                'business_profile' => ['name' => $clubName],
                'email' => $clubEmail ?: null,
                'metadata' => ['club_id' => (string) $clubId],
            ]);
            $accountId = $account['id'];

            $stmt = $this->pdo->prepare("
                INSERT INTO club_payment_accounts
                    (club_id, stripe_account_id, onboarding_status, created_by)
                VALUES (?, ?, 'in_progress', ?)
            ");
            $stmt->execute([$clubId, $accountId, $userId]);
        }

        $link = $this->gateway->createAccountLink($accountId, $refreshUrl, $returnUrl);

        return [
            'url' => $link['url'],
            'account' => $this->getStatus($clubId),
        ];
    }

    /**
     * Mint a new onboarding link for a club that already has an account
     * (Account Links are single-use and expire quickly).
     */
    public function refreshLink(int $clubId, string $refreshUrl, string $returnUrl): array {
        $this->requireGateway();

        $existing = $this->getStatus($clubId);
        if (!$existing) {
            throw new StripeConnectException('No payment account exists for this club — start onboarding first');
        }

        $link = $this->gateway->createAccountLink($existing['stripe_account_id'], $refreshUrl, $returnUrl);

        return [
            'url' => $link['url'],
            'account' => $existing,
        ];
    }

    /**
     * Sync local flags from a Stripe account payload (webhook account.updated,
     * or a manual retrieveAccount reconcile). Returns false if the account id
     * doesn't belong to any club — caller logs and moves on.
     */
    public function applyAccountUpdate(array $account): bool {
        if (empty($account['id'])) {
            return false;
        }

        $charges = !empty($account['charges_enabled']);
        $payouts = !empty($account['payouts_enabled']);
        $details = !empty($account['details_submitted']);

        $stmt = $this->pdo->prepare("
            UPDATE club_payment_accounts
            SET charges_enabled = ?,
                payouts_enabled = ?,
                details_submitted = ?,
                onboarding_status = ?,
                requirements = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE stripe_account_id = ?
        ");
        $stmt->execute([
            $charges ? 1 : 0,
            $payouts ? 1 : 0,
            $details ? 1 : 0,
            self::deriveOnboardingStatus($charges, $payouts, $details),
            isset($account['requirements']) ? json_encode($account['requirements']) : null,
            $account['id'],
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * complete    — fully onboarded, may accept live payments
     * restricted  — submitted everything but Stripe hasn't enabled charges
     *               (outstanding requirements / under review / disabled)
     * in_progress — onboarding started, details not yet submitted
     */
    public static function deriveOnboardingStatus(bool $charges, bool $payouts, bool $details): string {
        if ($charges && $payouts && $details) {
            return 'complete';
        }
        if ($details) {
            return 'restricted';
        }
        return 'in_progress';
    }

    /** Cast SQLite/Postgres driver differences to stable JSON types. */
    private function normalizeRow(array $row): array {
        foreach (['charges_enabled', 'payouts_enabled', 'details_submitted'] as $flag) {
            $row[$flag] = (bool) $row[$flag];
        }
        $row['id'] = (int) $row['id'];
        $row['club_id'] = (int) $row['club_id'];
        return $row;
    }

    private function requireGateway(): void {
        if ($this->gateway === null) {
            throw new StripeConnectException('Stripe gateway not configured for this operation');
        }
    }
}
