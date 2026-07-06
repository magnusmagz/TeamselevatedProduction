<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;
use PaymentReportService;

/**
 * Unit tests for PaymentReportService — treasurer reporting math and payout
 * event upserts, with strict club isolation.
 */
class PaymentReportServiceTest extends TestCase {

    private PDO $pdo;
    private PaymentReportService $svc;

    protected function setUp(): void {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->pdo->exec("
            CREATE TABLE athletes (id INTEGER PRIMARY KEY, club_id INTEGER);
            CREATE TABLE programs (id INTEGER PRIMARY KEY, club_id INTEGER);
            CREATE TABLE users (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT);
            CREATE TABLE invoices (id INTEGER PRIMARY KEY, invoice_number TEXT, athlete_id INTEGER, program_id INTEGER);
            CREATE TABLE payment_transactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT, amount NUMERIC, payment_method TEXT,
                status TEXT, payment_type TEXT, paid_by_user_id INTEGER,
                processor TEXT, refund_amount NUMERIC DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE payment_allocations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                payment_transaction_id INTEGER, invoice_id INTEGER, amount NUMERIC
            );
            CREATE TABLE club_payment_accounts (id INTEGER PRIMARY KEY, club_id INTEGER, stripe_account_id TEXT UNIQUE);
            CREATE TABLE stripe_payouts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                stripe_payout_id TEXT UNIQUE NOT NULL, stripe_account_id TEXT NOT NULL,
                club_id INTEGER, amount NUMERIC, currency TEXT DEFAULT 'usd',
                status TEXT, arrival_date TEXT, failure_message TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
        ");

        // Club 32 and club 44, one invoice each.
        $this->pdo->exec("INSERT INTO athletes VALUES (1, 32), (2, 44)");
        $this->pdo->exec("INSERT INTO programs VALUES (10, 32)");
        $this->pdo->exec("INSERT INTO users VALUES (70, 'Alice', 'Aaron')");
        $this->pdo->exec("INSERT INTO invoices VALUES (101, 'INV-1', 1, 10), (201, 'INV-2', 2, NULL)");

        // Club 32: $500 payment ($50 refunded) + $100 payment. Club 44: $75.
        $this->pdo->exec("INSERT INTO payment_transactions (id, amount, payment_method, status, paid_by_user_id, processor, refund_amount)
            VALUES (1, 500.00, 'credit_card', 'succeeded', 70, 'stripe', 50.00),
                   (2, 100.00, 'credit_card', 'succeeded', NULL, 'stripe', 0),
                   (3, 75.00,  'credit_card', 'succeeded', NULL, 'stripe', 0)");
        $this->pdo->exec("INSERT INTO payment_allocations (payment_transaction_id, invoice_id, amount)
            VALUES (1, 101, 500.00), (2, 101, 100.00), (3, 201, 75.00)");

        $this->pdo->exec("INSERT INTO club_payment_accounts VALUES (1, 32, 'acct_club32')");

        $this->svc = new PaymentReportService($this->pdo);
    }

    public function testSummaryIsClubIsolated(): void {
        $s = $this->svc->summary(32);
        $this->assertSame(600.00, $s['collected']); // 500 + 100, NOT club 44's 75
        $this->assertSame(50.00, $s['refunded']);
        $this->assertSame(550.00, $s['net']);
        $this->assertSame(2, $s['transaction_count']);

        $other = $this->svc->summary(44);
        $this->assertSame(75.00, $other['collected']);
    }

    public function testTransactionsListWithInvoiceNumbersAndPayer(): void {
        $rows = $this->svc->transactions(32);
        $this->assertCount(2, $rows);
        $byId = array_column($rows, null, 'id');
        $this->assertSame('Alice Aaron', $byId[1]['payer_name']);
        $this->assertSame(['INV-1'], $byId[1]['invoice_numbers']);
        $this->assertSame(50.00, $byId[1]['refund_amount']);
        $this->assertSame('Online payment', $byId[2]['payer_name']);
    }

    public function testApplyPayoutEventUpsertsAndResolvesClub(): void {
        $ok = $this->svc->applyPayoutEvent('acct_club32', [
            'id' => 'po_1', 'amount' => 55000, 'currency' => 'usd',
            'status' => 'paid', 'arrival_date' => 1780000000,
        ]);
        $this->assertTrue($ok);

        $payouts = $this->svc->payouts(32);
        $this->assertCount(1, $payouts);
        $this->assertSame(550.00, $payouts[0]['amount']);
        $this->assertSame('paid', $payouts[0]['status']);

        // Replay with a status change updates in place (no duplicate).
        $this->svc->applyPayoutEvent('acct_club32', [
            'id' => 'po_1', 'amount' => 55000, 'status' => 'failed', 'failure_message' => 'account closed',
        ]);
        $payouts = $this->svc->payouts(32);
        $this->assertCount(1, $payouts);
        $this->assertSame('failed', $payouts[0]['status']);
        $this->assertSame('account closed', $payouts[0]['failure_message']);
    }

    public function testPayoutForUnknownAccountReturnsFalse(): void {
        $this->assertFalse($this->svc->applyPayoutEvent('acct_nobody', ['id' => 'po_x', 'amount' => 100]));
        $this->assertFalse($this->svc->applyPayoutEvent(null, ['id' => 'po_y']));
    }
}
