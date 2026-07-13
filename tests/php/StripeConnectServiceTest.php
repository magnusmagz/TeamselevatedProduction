<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;
use StripeConnectService;
use StripeConnectException;
use StripeGateway;

/**
 * Unit tests for StripeConnectService (Phase 1, club onboarding onto Connect).
 *
 * Same fixture approach as PaymentServiceTest: in-memory SQLite PDO, schema
 * built in-test, dependencies injected. The Stripe API is a PHPUnit mock of the
 * thin StripeGateway wrapper — no network, no keys, never touches Stripe.
 */
class StripeConnectServiceTest extends TestCase {

    private PDO $pdo;

    protected function setUp(): void {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->pdo->exec("
            CREATE TABLE club_payment_accounts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                club_id INTEGER NOT NULL UNIQUE,
                stripe_account_id TEXT UNIQUE NOT NULL,
                onboarding_status TEXT DEFAULT 'pending',
                charges_enabled INTEGER DEFAULT 0,
                payouts_enabled INTEGER DEFAULT 0,
                details_submitted INTEGER DEFAULT 0,
                requirements TEXT,
                created_by INTEGER,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }

    private function mockGateway() {
        return $this->createMock(StripeGateway::class);
    }

    private function accountRow(int $clubId): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM club_payment_accounts WHERE club_id = ?");
        $stmt->execute([$clubId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    // ---- startOnboarding --------------------------------------------------

    public function testStartOnboardingCreatesAccountAndReturnsLink(): void {
        $gateway = $this->mockGateway();
        $gateway->expects($this->once())
            ->method('createExpressAccount')
            ->with($this->callback(function ($params) {
                return $params['business_profile']['name'] === 'Dynamo FC'
                    && $params['metadata']['club_id'] === '7';
            }))
            ->willReturn(['id' => 'acct_test123']);
        $gateway->expects($this->once())
            ->method('createAccountLink')
            ->with('acct_test123', 'https://app.test/refresh', 'https://app.test/return')
            ->willReturn(['url' => 'https://connect.stripe.com/setup/s/abc']);

        $service = new StripeConnectService($this->pdo, $gateway);
        $result = $service->startOnboarding(7, 42, 'Dynamo FC', 'admin@dynamo.test',
            'https://app.test/refresh', 'https://app.test/return');

        $this->assertSame('https://connect.stripe.com/setup/s/abc', $result['url']);
        $this->assertSame('acct_test123', $result['account']['stripe_account_id']);
        $this->assertSame('in_progress', $result['account']['onboarding_status']);
        $this->assertFalse($result['account']['charges_enabled']);

        $row = $this->accountRow(7);
        $this->assertNotNull($row);
        $this->assertSame('acct_test123', $row['stripe_account_id']);
        $this->assertEquals(42, $row['created_by']);
    }

    public function testStartOnboardingReusesExistingAccount(): void {
        $gateway = $this->mockGateway();
        $gateway->expects($this->once()) // account created only on the FIRST call
            ->method('createExpressAccount')
            ->willReturn(['id' => 'acct_once']);
        $gateway->expects($this->exactly(2)) // a fresh link is minted every call
            ->method('createAccountLink')
            ->willReturn(['url' => 'https://connect.stripe.com/setup/s/next']);

        $service = new StripeConnectService($this->pdo, $gateway);
        $service->startOnboarding(3, 1, 'Club', 'a@b.c', 'https://r', 'https://x');
        $service->startOnboarding(3, 1, 'Club', 'a@b.c', 'https://r', 'https://x');

        $count = $this->pdo->query("SELECT COUNT(*) FROM club_payment_accounts")->fetchColumn();
        $this->assertEquals(1, $count);
    }

    public function testStartOnboardingWithoutGatewayThrows(): void {
        $service = new StripeConnectService($this->pdo, null);
        $this->expectException(StripeConnectException::class);
        $service->startOnboarding(1, 1, 'Club', '', 'https://r', 'https://x');
    }

    // ---- refreshLink ------------------------------------------------------

    public function testRefreshLinkRequiresExistingAccount(): void {
        $service = new StripeConnectService($this->pdo, $this->mockGateway());
        $this->expectException(StripeConnectException::class);
        $service->refreshLink(99, 'https://r', 'https://x');
    }

    public function testRefreshLinkMintsNewLinkForExistingAccount(): void {
        $this->pdo->exec("INSERT INTO club_payment_accounts (club_id, stripe_account_id, onboarding_status)
                          VALUES (5, 'acct_ex', 'in_progress')");

        $gateway = $this->mockGateway();
        $gateway->expects($this->never())->method('createExpressAccount');
        $gateway->expects($this->once())
            ->method('createAccountLink')
            ->with('acct_ex', 'https://r', 'https://x')
            ->willReturn(['url' => 'https://connect.stripe.com/setup/s/again']);

        $service = new StripeConnectService($this->pdo, $gateway);
        $result = $service->refreshLink(5, 'https://r', 'https://x');

        $this->assertSame('https://connect.stripe.com/setup/s/again', $result['url']);
    }

    // ---- applyAccountUpdate (webhook path) ---------------------------------

    public function testApplyAccountUpdateMarksComplete(): void {
        $this->pdo->exec("INSERT INTO club_payment_accounts (club_id, stripe_account_id, onboarding_status)
                          VALUES (5, 'acct_hook', 'in_progress')");

        $service = new StripeConnectService($this->pdo); // no gateway needed for webhook path
        $found = $service->applyAccountUpdate([
            'id' => 'acct_hook',
            'charges_enabled' => true,
            'payouts_enabled' => true,
            'details_submitted' => true,
            'requirements' => ['currently_due' => []],
        ]);

        $this->assertTrue($found);
        $status = $service->getStatus(5);
        $this->assertSame('complete', $status['onboarding_status']);
        $this->assertTrue($status['charges_enabled']);
        $this->assertTrue($status['payouts_enabled']);

        $row = $this->accountRow(5);
        $this->assertSame(['currently_due' => []], json_decode($row['requirements'], true));
    }

    public function testApplyAccountUpdateDetailsSubmittedButChargesDisabledIsRestricted(): void {
        $this->pdo->exec("INSERT INTO club_payment_accounts (club_id, stripe_account_id)
                          VALUES (6, 'acct_r')");

        $service = new StripeConnectService($this->pdo);
        $service->applyAccountUpdate([
            'id' => 'acct_r',
            'charges_enabled' => false,
            'payouts_enabled' => false,
            'details_submitted' => true,
        ]);

        $this->assertSame('restricted', $service->getStatus(6)['onboarding_status']);
    }

    public function testApplyAccountUpdateUnknownAccountReturnsFalse(): void {
        $service = new StripeConnectService($this->pdo);
        $this->assertFalse($service->applyAccountUpdate(['id' => 'acct_nobody', 'charges_enabled' => true]));
        $this->assertFalse($service->applyAccountUpdate([]));
    }

    // ---- deriveOnboardingStatus matrix --------------------------------------

    public function testDeriveOnboardingStatus(): void {
        $this->assertSame('complete',    StripeConnectService::deriveOnboardingStatus(true, true, true));
        $this->assertSame('restricted',  StripeConnectService::deriveOnboardingStatus(false, false, true));
        $this->assertSame('restricted',  StripeConnectService::deriveOnboardingStatus(true, false, true));
        $this->assertSame('in_progress', StripeConnectService::deriveOnboardingStatus(false, false, false));
    }

    // ---- getStatus ----------------------------------------------------------

    public function testGetStatusReturnsNullWhenNeverOnboarded(): void {
        $service = new StripeConnectService($this->pdo);
        $this->assertNull($service->getStatus(123));
    }
}
