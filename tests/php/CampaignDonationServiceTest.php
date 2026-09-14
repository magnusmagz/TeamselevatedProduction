<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;
use StripeGateway;
use CampaignDonationService;
use CampaignDonationException;
use PaymentValidationException;

/**
 * Fundraiser donations on the Stripe Connect rail (2026-09-14). Fixture mirrors
 * ContributionLinkServiceTest; the campaign_donations columns are the live ones
 * (tests/fixtures/production-schema.json).
 */
class CampaignDonationServiceTest extends TestCase {

    private PDO $pdo;

    protected function setUp(): void {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec("
            CREATE TABLE club_profile (id INTEGER PRIMARY KEY, name TEXT, slug TEXT);
            CREATE TABLE club_payment_accounts (
                id INTEGER PRIMARY KEY, club_id INTEGER UNIQUE, stripe_account_id TEXT, charges_enabled INTEGER DEFAULT 0
            );
            CREATE TABLE fundraiser_campaigns (
                id INTEGER PRIMARY KEY, club_id INTEGER, title TEXT, slug TEXT, status TEXT,
                goal_amount NUMERIC, amount_raised NUMERIC DEFAULT 0, donor_count INTEGER DEFAULT 0,
                start_date TEXT, end_date TEXT, allow_exceed_goal INTEGER DEFAULT 1, deleted_at TEXT
            );
            CREATE TABLE campaign_donations (
                id INTEGER PRIMARY KEY AUTOINCREMENT, campaign_id INTEGER, donor_name TEXT, donor_email TEXT,
                donor_phone TEXT, is_anonymous INTEGER DEFAULT 0, amount NUMERIC, comment TEXT,
                maverick_transaction_id TEXT, maverick_charge_id TEXT, status TEXT,
                receipt_sent_at TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, deleted_at TEXT
            );
        ");
        $this->pdo->exec("INSERT INTO club_profile VALUES (53, 'West Ham United Foundation', 'west-ham-united-foundation')");
        $this->pdo->exec("INSERT INTO club_payment_accounts (club_id, stripe_account_id, charges_enabled) VALUES (53, 'acct_club53', 1)");
        $this->pdo->exec("INSERT INTO fundraiser_campaigns (id, club_id, title, slug, status, goal_amount, amount_raised, start_date, end_date, allow_exceed_goal)
                          VALUES (7, 53, 'London Stadium', 'london-stadium', 'active', 1000.00, 900.00, '2026-09-01', '2099-12-31', 1)");
    }

    private function donor(array $over = []): array {
        return array_merge(['name' => 'Grandma Jo', 'email' => 'Jo@Example.com', 'comment' => 'Go team!'], $over);
    }

    public function testCheckoutMintsAGuestSessionOnTheClubsAccountWithCampaignMetadata(): void {
        $gateway = $this->createMock(StripeGateway::class);
        $gateway->expects($this->once())->method('createCheckoutSession')
            ->with($this->callback(function ($p) {
                return $p['mode'] === 'payment'
                    && $p['line_items'][0]['price_data']['unit_amount'] === 2500
                    && $p['metadata']['campaign_id'] === '7'
                    && $p['metadata']['club_id'] === '53'
                    && $p['metadata']['donor_name'] === 'Grandma Jo'
                    && $p['metadata']['donor_email'] === 'jo@example.com'   // lowercased
                    && $p['metadata']['donor_anonymous'] === '0'
                    && $p['payment_intent_data']['metadata']['campaign_id'] === '7'
                    && $p['customer_email'] === 'jo@example.com'
                    && $p['success_url'] === 'https://s' && $p['cancel_url'] === 'https://c'
                    && !isset($p['payment_intent_data']['application_fee_amount']);
            }), 'acct_club53')
            ->willReturn(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/c/pay/1']);

        $r = (new CampaignDonationService($this->pdo, $gateway))->createCheckout(7, 25.00, $this->donor(), 'https://s', 'https://c');
        $this->assertSame('https://checkout.stripe.com/c/pay/1', $r['url']);
        $this->assertSame('cs_1', $r['session_id']);
    }

    public function testPlatformFeeRidesOnThePaymentIntent(): void {
        $gateway = $this->createMock(StripeGateway::class);
        $gateway->expects($this->once())->method('createCheckoutSession')
            ->with($this->callback(fn($p) => $p['payment_intent_data']['application_fee_amount'] === 125), 'acct_club53')
            ->willReturn(['id' => 'cs_1', 'url' => 'u']);
        (new CampaignDonationService($this->pdo, $gateway, 500))->createCheckout(7, 25.00, $this->donor(), 's', 'c');
    }

    /** @dataProvider refusals */
    public function testCheckoutRefusesBeforeTouchingStripe(callable $arrange, float $amount, array $donor, string $exception): void {
        $arrange($this->pdo);
        $gateway = $this->createMock(StripeGateway::class);
        $gateway->expects($this->never())->method('createCheckoutSession');
        $this->expectException($exception);
        (new CampaignDonationService($this->pdo, $gateway))->createCheckout(7, $amount, $donor, 's', 'c');
    }

    public static function refusals(): array {
        $noop = fn() => null;
        return [
            'below minimum' => [$noop, 0.99, ['name' => 'A', 'email' => 'a@b.co'], PaymentValidationException::class],
            'no name' => [$noop, 5, ['name' => '  ', 'email' => 'a@b.co'], PaymentValidationException::class],
            'bad email' => [$noop, 5, ['name' => 'A', 'email' => 'not-an-email'], PaymentValidationException::class],
            'draft campaign' => [fn($pdo) => $pdo->exec("UPDATE fundraiser_campaigns SET status='draft'"), 5, ['name' => 'A', 'email' => 'a@b.co'], CampaignDonationException::class],
            'ended campaign' => [fn($pdo) => $pdo->exec("UPDATE fundraiser_campaigns SET end_date='2020-01-01'"), 5, ['name' => 'A', 'email' => 'a@b.co'], CampaignDonationException::class],
            'deleted campaign' => [fn($pdo) => $pdo->exec("UPDATE fundraiser_campaigns SET deleted_at='2026-01-01'"), 5, ['name' => 'A', 'email' => 'a@b.co'], CampaignDonationException::class],
            'club cannot charge' => [fn($pdo) => $pdo->exec("UPDATE club_payment_accounts SET charges_enabled=0"), 5, ['name' => 'A', 'email' => 'a@b.co'], CampaignDonationException::class],
            'no connected account' => [fn($pdo) => $pdo->exec("DELETE FROM club_payment_accounts"), 5, ['name' => 'A', 'email' => 'a@b.co'], CampaignDonationException::class],
            'over the goal when exceeding is off' => [fn($pdo) => $pdo->exec("UPDATE fundraiser_campaigns SET allow_exceed_goal=0"), 100.01, ['name' => 'A', 'email' => 'a@b.co'], PaymentValidationException::class],
            'goal already met when exceeding is off' => [fn($pdo) => $pdo->exec("UPDATE fundraiser_campaigns SET allow_exceed_goal=0, amount_raised=1000"), 5, ['name' => 'A', 'email' => 'a@b.co'], CampaignDonationException::class],
        ];
    }

    public function testTheGoalCapAllowsExactlyTheRemainder(): void {
        $this->pdo->exec("UPDATE fundraiser_campaigns SET allow_exceed_goal=0");
        $gateway = $this->createMock(StripeGateway::class);
        $gateway->expects($this->once())->method('createCheckoutSession')->willReturn(['id' => 'cs', 'url' => 'u']);
        (new CampaignDonationService($this->pdo, $gateway))->createCheckout(7, 100.00, $this->donor(), 's', 'c');
    }

    public function testEndDateIsComparedAsADateString(): void {
        // Today is still a valid day to give — a strtotime()+time() compare would refuse it after midnight UTC.
        $this->pdo->exec("UPDATE fundraiser_campaigns SET end_date='" . date('Y-m-d') . "'");
        $gateway = $this->createMock(StripeGateway::class);
        $gateway->expects($this->once())->method('createCheckoutSession')->willReturn(['id' => 'cs', 'url' => 'u']);
        (new CampaignDonationService($this->pdo, $gateway))->createCheckout(7, 5.00, $this->donor(), 's', 'c');
    }

    // ---- webhook side --------------------------------------------------------

    private function paidSession(array $over = []): array {
        return array_merge([
            'id' => 'cs_paid', 'payment_intent' => 'pi_123', 'amount_total' => 2500, 'payment_status' => 'paid',
            'customer_details' => ['email' => 'jo@example.com'],
        ], $over);
    }

    private function meta(array $over = []): array {
        return array_merge([
            'campaign_id' => '7', 'club_id' => '53', 'donor_name' => 'Grandma Jo', 'donor_email' => 'jo@example.com',
            'donor_phone' => '', 'donor_anonymous' => '0', 'donor_comment' => 'Go team!',
        ], $over);
    }

    public function testRecordDonationWritesASucceededRowKeyedOnThePaymentIntent(): void {
        $svc = new CampaignDonationService($this->pdo);
        $receipt = $svc->recordDonation($this->meta(), $this->paidSession());

        $row = $this->pdo->query("SELECT * FROM campaign_donations")->fetch();
        $this->assertSame('succeeded', $row['status']);
        $this->assertSame('pi_123', $row['maverick_transaction_id']);
        $this->assertEquals(25.00, (float) $row['amount']);
        $this->assertSame('Grandma Jo', $row['donor_name']);
        $this->assertSame('jo@example.com', $row['donor_email']);
        $this->assertSame('Go team!', $row['comment']);
        $this->assertSame(0, (int) $row['is_anonymous']);

        $this->assertSame((int) $row['id'], $receipt['donation_id']);
        $this->assertSame('jo@example.com', $receipt['to']);
        $this->assertSame('London Stadium', $receipt['campaign_title']);
        $this->assertSame('West Ham United Foundation', $receipt['club_name']);
        $this->assertSame(53, $receipt['club_id']);
        $this->assertSame('pi_123', $receipt['transaction_id']);
    }

    public function testAReplayedWebhookRecordsNothing(): void {
        $svc = new CampaignDonationService($this->pdo);
        $this->assertNotNull($svc->recordDonation($this->meta(), $this->paidSession()));
        $this->assertNull($svc->recordDonation($this->meta(), $this->paidSession()));
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM campaign_donations")->fetchColumn());
    }

    public function testAnonymousAndPhoneAreCarried(): void {
        (new CampaignDonationService($this->pdo))->recordDonation(
            $this->meta(['donor_anonymous' => '1', 'donor_phone' => '+15555550100', 'donor_comment' => '']),
            $this->paidSession());
        $row = $this->pdo->query("SELECT * FROM campaign_donations")->fetch();
        $this->assertSame(1, (int) $row['is_anonymous']);
        $this->assertSame('+15555550100', $row['donor_phone']);
        $this->assertNull($row['comment']);
    }

    public function testAnInvoiceSessionIsNotACampaignDonation(): void {
        $this->assertFalse(CampaignDonationService::isCampaignSession(['invoice_ids' => '101', 'club_id' => '32']));
        $this->assertNull((new CampaignDonationService($this->pdo))->recordDonation(['invoice_ids' => '101'], $this->paidSession()));
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM campaign_donations")->fetchColumn());
    }

    public function testAPaidSessionForAnUnknownCampaignThrowsSoStripeRetries(): void {
        $this->expectException(CampaignDonationException::class);
        (new CampaignDonationService($this->pdo))->recordDonation($this->meta(['campaign_id' => '999']), $this->paidSession());
    }
}
