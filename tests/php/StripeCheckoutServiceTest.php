<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;
use AuthMiddleware;
use StripeGateway;
use StripeCheckoutService;
use ClubNotPayableException;
use OwnershipException;
use PaymentValidationException;

/**
 * Unit tests for StripeCheckoutService (Phase 2 — hosted checkout for invoices).
 * SQLite fixture mirrors PaymentServiceTest's family setup, extended with
 * programs/club resolution and club_payment_accounts.
 */
class StripeCheckoutServiceTest extends TestCase {

    private PDO $pdo;

    protected function setUp(): void {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->pdo->exec("
            CREATE TABLE teams (id INTEGER PRIMARY KEY, name TEXT, club_id INTEGER, primary_coach_id INTEGER, deleted_at TEXT);
            CREATE TABLE team_members (id INTEGER PRIMARY KEY, team_id INTEGER, user_id INTEGER, athlete_id INTEGER, role TEXT, status TEXT);
            CREATE TABLE athletes (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, club_id INTEGER);
            CREATE TABLE guardians (id INTEGER PRIMARY KEY, email TEXT);
            CREATE TABLE athlete_guardians (id INTEGER PRIMARY KEY, athlete_id INTEGER, guardian_id INTEGER);
            CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT);
            CREATE TABLE user_guardians (id INTEGER PRIMARY KEY, user_id INTEGER, guardian_id INTEGER, source TEXT, confidence TEXT);
            CREATE TABLE programs (id INTEGER PRIMARY KEY, club_id INTEGER);
            CREATE TABLE invoices (
                id INTEGER PRIMARY KEY, invoice_number TEXT, athlete_id INTEGER,
                athlete_payment_id INTEGER, program_id INTEGER,
                total_amount NUMERIC, amount_paid NUMERIC DEFAULT 0,
                status TEXT, paid_at TEXT, updated_at TEXT
            );
            CREATE TABLE club_payment_accounts (
                id INTEGER PRIMARY KEY, club_id INTEGER UNIQUE, stripe_account_id TEXT,
                onboarding_status TEXT, charges_enabled INTEGER DEFAULT 0,
                payouts_enabled INTEGER DEFAULT 0, details_submitted INTEGER DEFAULT 0
            );
        ");

        $this->pdo->exec("INSERT INTO athletes (id, first_name, last_name, club_id) VALUES
            (1, 'Anna', 'Aaron', 32), (2, 'Andy', 'Aaron', 32), (3, 'Ben', 'Brown', 44)");
        $this->pdo->exec("INSERT INTO guardians (id, email) VALUES
            (200, 'alice@family-a.com'), (201, 'bob@family-b.com')");
        $this->pdo->exec("INSERT INTO athlete_guardians (id, athlete_id, guardian_id) VALUES
            (1, 1, 200), (2, 2, 200), (3, 3, 201)");
        // Guardian standing is resolved from the ACCOUNT (lib/guardian_identity.php).
        $this->pdo->exec("INSERT INTO users (id, email) VALUES (70,'alice@family-a.com'),(71,'bob@family-b.com')");
        $this->pdo->exec("INSERT INTO programs (id, club_id) VALUES (10, 32), (11, 44)");
        // 101/102: alice's kids, club 32. 103: bob's kid, club 44 (via athlete fallback, no program).
        $this->pdo->exec("INSERT INTO invoices (id, invoice_number, athlete_id, program_id, total_amount, amount_paid, status) VALUES
            (101, 'INV-202607-00001', 1, 10, 500.00, 0,      'sent'),
            (102, 'INV-202607-00002', 2, 10, 100.00, 25.00,  'partial'),
            (103, 'INV-202607-00003', 3, NULL, 200.00, 0,    'sent')");
        $this->pdo->exec("INSERT INTO club_payment_accounts (club_id, stripe_account_id, charges_enabled) VALUES
            (32, 'acct_club32', 1)");
    }

    private function alice(): AuthMiddleware {
        return AuthMiddleware::fromContext(['user_id' => 70, 'email' => 'alice@family-a.com', 'roles' => []]);
    }

    private function service($gateway, int $feeBps = 0): StripeCheckoutService {
        return new StripeCheckoutService($this->pdo, $gateway, $feeBps);
    }

    public function testCreatesSessionOnClubConnectedAccount(): void {
        $gateway = $this->createMock(StripeGateway::class);
        $gateway->expects($this->once())
            ->method('createCheckoutSession')
            ->with(
                $this->callback(function ($params) {
                    return $params['mode'] === 'payment'
                        && $params['line_items'][0]['price_data']['unit_amount'] === 57500 // 500 + 75 remaining
                        && $params['metadata']['invoice_ids'] === '101,102'
                        && $params['metadata']['payer_user_id'] === '70'
                        && $params['metadata']['club_id'] === '32'
                        && $params['payment_intent_data']['metadata']['invoice_ids'] === '101,102'
                        && !isset($params['payment_intent_data']['application_fee_amount']);
                }),
                'acct_club32'
            )
            ->willReturn(['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/x']);

        $result = $this->service($gateway)->createInvoiceCheckout(
            $this->alice(), [101, 102], 575.00, 'https://app/success', 'https://app/cancel');

        $this->assertSame('https://checkout.stripe.com/c/pay/x', $result['url']);
        $this->assertSame('cs_test_1', $result['session_id']);
        $this->assertSame(32, $result['club_id']);
    }

    public function testAmountAboveRemainingBalanceRejected(): void {
        $gateway = $this->createMock(StripeGateway::class);
        $gateway->expects($this->never())->method('createCheckoutSession');

        $this->expectException(PaymentValidationException::class);
        // remaining on 101+102 is 575.00
        $this->service($gateway)->createInvoiceCheckout(
            $this->alice(), [101, 102], 575.01, 'https://s', 'https://c');
    }

    public function testInvoiceOutsideScopeRejected(): void {
        $gateway = $this->createMock(StripeGateway::class);
        $gateway->expects($this->never())->method('createCheckoutSession');

        $this->expectException(OwnershipException::class);
        $this->service($gateway)->createInvoiceCheckout(
            $this->alice(), [103], 100.00, 'https://s', 'https://c'); // bob's invoice
    }

    public function testClubWithoutChargesEnabledRejected(): void {
        $this->pdo->exec("UPDATE club_payment_accounts SET charges_enabled = 0 WHERE club_id = 32");
        $gateway = $this->createMock(StripeGateway::class);
        $gateway->expects($this->never())->method('createCheckoutSession');

        $this->expectException(ClubNotPayableException::class);
        $this->service($gateway)->createInvoiceCheckout(
            $this->alice(), [101], 100.00, 'https://s', 'https://c');
    }

    public function testClubWithoutAccountRowRejected(): void {
        $bob = AuthMiddleware::fromContext(['user_id' => 71, 'email' => 'bob@family-b.com', 'roles' => []]);
        $gateway = $this->createMock(StripeGateway::class);

        $this->expectException(ClubNotPayableException::class); // club 44 never onboarded
        $this->service($gateway)->createInvoiceCheckout($bob, [103], 100.00, 'https://s', 'https://c');
    }

    public function testFullyPaidInvoicesRejected(): void {
        $this->pdo->exec("UPDATE invoices SET amount_paid = total_amount, status = 'paid' WHERE id = 101");
        $gateway = $this->createMock(StripeGateway::class);

        $this->expectException(PaymentValidationException::class);
        $this->service($gateway)->createInvoiceCheckout($this->alice(), [101], 10.00, 'https://s', 'https://c');
    }

    public function testPlatformFeeAppliedWhenConfigured(): void {
        $gateway = $this->createMock(StripeGateway::class);
        $gateway->expects($this->once())
            ->method('createCheckoutSession')
            ->with($this->callback(function ($params) {
                // 100.00 at 325 bps => 325 cents
                return $params['payment_intent_data']['application_fee_amount'] === 325;
            }), 'acct_club32')
            ->willReturn(['id' => 'cs_test_2', 'url' => 'https://checkout.stripe.com/c/pay/y']);

        $this->service($gateway, 325)->createInvoiceCheckout(
            $this->alice(), [101], 100.00, 'https://s', 'https://c');
    }
}
