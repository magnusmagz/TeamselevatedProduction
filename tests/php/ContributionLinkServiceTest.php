<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;
use AuthMiddleware;
use StripeGateway;
use ContributionLinkService;
use ContributionLinkException;
use OwnershipException;
use PaymentValidationException;

/**
 * Unit tests for ContributionLinkService (Phase 4 — invoice-bound contribution
 * links). Fixture mirrors StripeCheckoutServiceTest.
 */
class ContributionLinkServiceTest extends TestCase {

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
            CREATE TABLE programs (id INTEGER PRIMARY KEY, club_id INTEGER);
            CREATE TABLE club_profile (id INTEGER PRIMARY KEY, name TEXT);
            CREATE TABLE invoices (
                id INTEGER PRIMARY KEY, invoice_number TEXT, athlete_id INTEGER, program_id INTEGER,
                total_amount NUMERIC, amount_paid NUMERIC DEFAULT 0, status TEXT, paid_at TEXT, updated_at TEXT
            );
            CREATE TABLE club_payment_accounts (
                id INTEGER PRIMARY KEY, club_id INTEGER UNIQUE, stripe_account_id TEXT,
                charges_enabled INTEGER DEFAULT 0
            );
            CREATE TABLE contribution_links (
                id INTEGER PRIMARY KEY AUTOINCREMENT, token TEXT UNIQUE NOT NULL, invoice_id INTEGER NOT NULL,
                created_by_user_id INTEGER, display_name TEXT NOT NULL, message TEXT,
                status TEXT DEFAULT 'active', expires_at TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE invoice_contributions (
                id INTEGER PRIMARY KEY AUTOINCREMENT, contribution_link_id INTEGER NOT NULL,
                payment_transaction_id INTEGER, contributor_name TEXT, contributor_email TEXT,
                is_anonymous INTEGER DEFAULT 0, comment TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
        ");

        $this->pdo->exec("INSERT INTO athletes VALUES (1, 'Anna', 'Aaron', 32), (3, 'Ben', 'Brown', 32)");
        $this->pdo->exec("INSERT INTO guardians VALUES (200, 'alice@family-a.com'), (201, 'bob@family-b.com')");
        $this->pdo->exec("INSERT INTO athlete_guardians VALUES (1, 1, 200), (2, 3, 201)");
        $this->pdo->exec("INSERT INTO programs VALUES (10, 32)");
        $this->pdo->exec("INSERT INTO club_profile VALUES (32, 'Teams Elevated')");
        $this->pdo->exec("INSERT INTO invoices (id, invoice_number, athlete_id, program_id, total_amount, amount_paid, status)
                          VALUES (101, 'INV-1', 1, 10, 500.00, 100.00, 'partial')");
        $this->pdo->exec("INSERT INTO club_payment_accounts (club_id, stripe_account_id, charges_enabled)
                          VALUES (32, 'acct_club32', 1)");
    }

    private function alice(): AuthMiddleware {
        return AuthMiddleware::fromContext(['user_id' => 70, 'email' => 'alice@family-a.com', 'roles' => []]);
    }

    public function testCreateLinkAndReuseActiveOne(): void {
        $svc = new ContributionLinkService($this->pdo);
        $link = $svc->createLink($this->alice(), 101, 'Anna — Summer Camp', 'Help Anna get to camp!');

        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $link['token']);
        $this->assertSame('active', $link['status']);

        $again = $svc->createLink($this->alice(), 101, 'Different Name', null);
        $this->assertSame($link['token'], $again['token']); // one active link per invoice
        $this->assertEquals(1, $this->pdo->query("SELECT COUNT(*) FROM contribution_links")->fetchColumn());
    }

    public function testCreateLinkOutsideScopeRejected(): void {
        $bob = AuthMiddleware::fromContext(['user_id' => 71, 'email' => 'bob@family-b.com', 'roles' => []]);
        $this->expectException(OwnershipException::class);
        (new ContributionLinkService($this->pdo))->createLink($bob, 101, 'Nope', null);
    }

    public function testPublicStateIsPiiSafe(): void {
        $svc = new ContributionLinkService($this->pdo);
        $link = $svc->createLink($this->alice(), 101, 'Anna — Summer Camp', 'msg');

        $state = $svc->getPublicState($link['token']);

        $this->assertSame('Anna — Summer Camp', $state['display_name']);
        $this->assertSame('Teams Elevated', $state['club_name']);
        $this->assertSame(500.0, $state['goal']);
        $this->assertSame(100.0, $state['raised']);
        $this->assertSame(400.0, $state['remaining']);
        // No leakage: exactly these keys, no invoice id / athlete / guardian fields
        $this->assertEqualsCanonicalizing(
            ['display_name', 'message', 'club_name', 'status', 'goal', 'raised', 'remaining', 'contributor_count'],
            array_keys($state));
        $this->assertNull($svc->getPublicState(str_repeat('0', 32)));
    }

    public function testCheckoutCappedAtRemaining(): void {
        $svc0 = new ContributionLinkService($this->pdo);
        $link = $svc0->createLink($this->alice(), 101, 'Anna', null);

        $gateway = $this->createMock(StripeGateway::class);
        $gateway->expects($this->never())->method('createCheckoutSession');
        $svc = new ContributionLinkService($this->pdo, $gateway);

        $this->expectException(PaymentValidationException::class);
        $svc->createCheckout($link['token'], 400.01, [], 'https://s', 'https://c'); // remaining is 400
    }

    public function testCheckoutMintsGuestSessionWithContributionMetadata(): void {
        $svc0 = new ContributionLinkService($this->pdo);
        $link = $svc0->createLink($this->alice(), 101, 'Anna — Summer Camp', null);

        $gateway = $this->createMock(StripeGateway::class);
        $gateway->expects($this->once())
            ->method('createCheckoutSession')
            ->with($this->callback(function ($params) {
                return $params['line_items'][0]['price_data']['unit_amount'] === 5000
                    && $params['metadata']['invoice_ids'] === '101'
                    && $params['metadata']['contribution_link_id'] !== ''
                    && $params['metadata']['contributor_name'] === 'Grandma Jo'
                    && $params['customer_email'] === 'jo@example.com';
            }), 'acct_club32')
            ->willReturn(['id' => 'cs_x', 'url' => 'https://checkout.stripe.com/c/pay/z']);

        $result = (new ContributionLinkService($this->pdo, $gateway))->createCheckout(
            $link['token'], 50.00,
            ['name' => 'Grandma Jo', 'email' => 'jo@example.com', 'comment' => 'Go Anna!'],
            'https://s', 'https://c');

        $this->assertSame('https://checkout.stripe.com/c/pay/z', $result['url']);
    }

    public function testClosedLinkRejectsCheckout(): void {
        $svc = new ContributionLinkService($this->pdo, $this->createMock(StripeGateway::class));
        $link = (new ContributionLinkService($this->pdo))->createLink($this->alice(), 101, 'Anna', null);
        $this->pdo->exec("UPDATE contribution_links SET status = 'closed'");

        $this->expectException(ContributionLinkException::class);
        $svc->createCheckout($link['token'], 10.00, [], 'https://s', 'https://c');
    }

    public function testRecordContributionClosesLinkAtGoal(): void {
        $svc = new ContributionLinkService($this->pdo);
        $link = $svc->createLink($this->alice(), 101, 'Anna', null);

        // Webhook applied a payment that finished the invoice off.
        $this->pdo->exec("UPDATE invoices SET amount_paid = 500.00, status = 'paid' WHERE id = 101");
        $svc->recordContribution([
            'contribution_link_id' => (string) $link['id'],
            'contributor_name' => 'Grandma Jo',
            'contributor_email' => 'jo@example.com',
            'contributor_anonymous' => '0',
            'contributor_comment' => 'Go Anna!',
        ], 991);

        $row = $this->pdo->query("SELECT * FROM invoice_contributions")->fetch();
        $this->assertSame('Grandma Jo', $row['contributor_name']);
        $this->assertEquals(991, $row['payment_transaction_id']);

        $status = $this->pdo->query("SELECT status FROM contribution_links")->fetchColumn();
        $this->assertSame('completed', $status);
        $this->assertSame('completed', $svc->getPublicState($link['token'])['status']);
    }
}
