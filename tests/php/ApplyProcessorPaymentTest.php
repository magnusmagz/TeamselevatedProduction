<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;
use PaymentService;
use PaymentValidationException;

/**
 * Unit tests for PaymentService::applyProcessorPayment — the webhook-side
 * ledger writer (Phase 2). Verifies allocation math, transaction + allocation
 * rows, idempotent replays, overpayment capping, and rollback on bad input.
 */
class ApplyProcessorPaymentTest extends TestCase {

    private PDO $pdo;

    protected function setUp(): void {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->pdo->exec("
            CREATE TABLE invoices (
                id INTEGER PRIMARY KEY, athlete_id INTEGER, athlete_payment_id INTEGER,
                total_amount NUMERIC, amount_paid NUMERIC DEFAULT 0,
                status TEXT, paid_at TEXT, updated_at TEXT
            );
            CREATE TABLE payment_transactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                athlete_payment_id INTEGER, amount NUMERIC, payment_method TEXT,
                status TEXT, payment_type TEXT, paid_by_user_id INTEGER,
                processor TEXT, processor_transaction_id TEXT,
                processor_charge_id TEXT, processor_customer_id TEXT, processor_session_id TEXT,
                refund_amount NUMERIC DEFAULT 0.00, refunded_at TEXT, updated_at TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE payment_allocations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                payment_transaction_id INTEGER, invoice_id INTEGER, amount NUMERIC,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
        ");

        $this->pdo->exec("INSERT INTO invoices (id, athlete_id, athlete_payment_id, total_amount, amount_paid, status) VALUES
            (101, 1, 901, 500.00, 0,     'sent'),
            (102, 2, 902, 100.00, 25.00, 'partial')");
    }

    private function apply(array $overrides = []): array {
        return PaymentService::applyProcessorPayment($this->pdo, array_merge([
            'processor' => 'stripe',
            'transaction_id' => 'pi_test_1',
            'session_id' => 'cs_test_1',
            'amount' => 500.00,
            'invoice_ids' => [101],
            'paid_by_user_id' => 70,
        ], $overrides));
    }

    private function invoice(int $id): array {
        $stmt = $this->pdo->prepare("SELECT * FROM invoices WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public function testFullPaymentMarksInvoicePaidAndWritesLedger(): void {
        $result = $this->apply();

        $this->assertTrue($result['success']);
        $this->assertFalse($result['already_applied']);
        $this->assertSame(500.0, $result['amount_applied']);
        $this->assertSame(0.0, $result['amount_unapplied']);

        $inv = $this->invoice(101);
        $this->assertSame('paid', $inv['status']);
        $this->assertEquals(500.00, $inv['amount_paid']);
        $this->assertNotNull($inv['paid_at']);

        $txn = $this->pdo->query("SELECT * FROM payment_transactions")->fetch();
        $this->assertSame('stripe', $txn['processor']);
        $this->assertSame('pi_test_1', $txn['processor_transaction_id']);
        $this->assertSame('cs_test_1', $txn['processor_session_id']);
        $this->assertSame('full', $txn['payment_type']);
        $this->assertEquals(70, $txn['paid_by_user_id']);
        $this->assertEquals(901, $txn['athlete_payment_id']);

        $alloc = $this->pdo->query("SELECT * FROM payment_allocations")->fetchAll();
        $this->assertCount(1, $alloc);
        $this->assertEquals(101, $alloc[0]['invoice_id']);
        $this->assertEquals(500.00, $alloc[0]['amount']);
    }

    public function testPartialPaymentSetsPartialStatus(): void {
        $result = $this->apply(['amount' => 200.00, 'transaction_id' => 'pi_partial']);

        $this->assertSame(200.0, $result['amount_applied']);
        $inv = $this->invoice(101);
        $this->assertSame('partial', $inv['status']);
        $this->assertEquals(200.00, $inv['amount_paid']);
        $this->assertNull($inv['paid_at']);

        $txn = $this->pdo->query("SELECT payment_type FROM payment_transactions")->fetch();
        $this->assertSame('partial', $txn['payment_type']);
    }

    public function testMultiInvoiceAllocationInOrder(): void {
        // 550 covers 101 (500) fully, then 50 of 102's 75 remaining
        $result = $this->apply(['amount' => 550.00, 'invoice_ids' => [101, 102], 'transaction_id' => 'pi_multi']);

        $this->assertSame(550.0, $result['amount_applied']);
        $this->assertSame('paid', $this->invoice(101)['status']);
        $inv102 = $this->invoice(102);
        $this->assertSame('partial', $inv102['status']);
        $this->assertEquals(75.00, $inv102['amount_paid']); // 25 + 50

        $allocs = $this->pdo->query("SELECT invoice_id, amount FROM payment_allocations ORDER BY id")->fetchAll();
        $this->assertCount(2, $allocs);
        $this->assertEquals([101, 500.00], [(int) $allocs[0]['invoice_id'], (float) $allocs[0]['amount']]);
        $this->assertEquals([102, 50.00], [(int) $allocs[1]['invoice_id'], (float) $allocs[1]['amount']]);
    }

    public function testReplayIsIdempotent(): void {
        $first = $this->apply();
        $replay = $this->apply(); // same transaction_id

        $this->assertTrue($replay['already_applied']);
        $this->assertSame($first['transaction_id'], $replay['transaction_id']);

        // Ledger untouched by the replay
        $this->assertEquals(500.00, $this->invoice(101)['amount_paid']);
        $this->assertEquals(1, $this->pdo->query("SELECT COUNT(*) FROM payment_transactions")->fetchColumn());
        $this->assertEquals(1, $this->pdo->query("SELECT COUNT(*) FROM payment_allocations")->fetchColumn());
    }

    public function testOverpaymentIsCappedAndReportedUnapplied(): void {
        // Invoice 101 already fully paid by someone else — the race case
        $this->pdo->exec("UPDATE invoices SET amount_paid = 500.00, status = 'paid' WHERE id = 101");

        $result = $this->apply(['transaction_id' => 'pi_race']);

        $this->assertSame(0.0, $result['amount_applied']);
        $this->assertSame(500.0, $result['amount_unapplied']);
        // amount_paid never exceeds total
        $this->assertEquals(500.00, $this->invoice(101)['amount_paid']);
        // transaction still recorded (money WAS taken — needs the manual refund path)
        $this->assertEquals(1, $this->pdo->query("SELECT COUNT(*) FROM payment_transactions")->fetchColumn());
        $this->assertEquals(0, $this->pdo->query("SELECT COUNT(*) FROM payment_allocations")->fetchColumn());
    }

    // ---- applyProcessorRefund (charge.refunded webhook path) ----------------

    public function testFullRefundReversesInvoiceAndMarksTransactionRefunded(): void {
        $this->apply(); // pays 101 in full

        $result = PaymentService::applyProcessorRefund($this->pdo, 'stripe', 'pi_test_1', 500.00);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['full_refund']);
        $inv = $this->invoice(101);
        $this->assertSame('sent', $inv['status']);
        $this->assertEquals(0.00, (float) $inv['amount_paid']);
        $this->assertNull($inv['paid_at']);

        $txn = $this->pdo->query("SELECT status, refund_amount FROM payment_transactions")->fetch();
        $this->assertSame('refunded', $txn['status']);
        $this->assertEquals(500.00, (float) $txn['refund_amount']);
    }

    public function testPartialRefundSetsPartialStatusAndIsCumulativelyIdempotent(): void {
        $this->apply();

        // Stripe reports CUMULATIVE amount_refunded: first 100, then 150 total.
        PaymentService::applyProcessorRefund($this->pdo, 'stripe', 'pi_test_1', 100.00);
        $this->assertEquals(400.00, (float) $this->invoice(101)['amount_paid']);
        $this->assertSame('partial', $this->invoice(101)['status']);

        PaymentService::applyProcessorRefund($this->pdo, 'stripe', 'pi_test_1', 150.00);
        $this->assertEquals(350.00, (float) $this->invoice(101)['amount_paid']);

        // Replay of the same cumulative total is a no-op.
        $replay = PaymentService::applyProcessorRefund($this->pdo, 'stripe', 'pi_test_1', 150.00);
        $this->assertTrue($replay['already_applied']);
        $this->assertEquals(350.00, (float) $this->invoice(101)['amount_paid']);

        $txn = $this->pdo->query("SELECT status, refund_amount FROM payment_transactions")->fetch();
        $this->assertSame('succeeded', $txn['status']); // not fully refunded
        $this->assertEquals(150.00, (float) $txn['refund_amount']);
    }

    public function testMultiInvoiceRefundReversesNewestAllocationFirst(): void {
        // 550 pays 101 fully (500) then 50 onto 102
        $this->apply(['amount' => 550.00, 'invoice_ids' => [101, 102], 'transaction_id' => 'pi_multi']);

        // Refund 60: reverses 102's 50 first, then 10 from 101
        PaymentService::applyProcessorRefund($this->pdo, 'stripe', 'pi_multi', 60.00);

        $this->assertEquals(25.00, (float) $this->invoice(102)['amount_paid']); // back to pre-payment
        $this->assertSame('partial', $this->invoice(102)['status']);
        $this->assertEquals(490.00, (float) $this->invoice(101)['amount_paid']);
        $this->assertSame('partial', $this->invoice(101)['status']);
    }

    public function testRefundConsumesUnappliedPoolBeforeReversingAllocations(): void {
        // 101 has 500 total; someone else already paid 300 of it.
        $this->pdo->exec("UPDATE invoices SET amount_paid = 300.00, status = 'partial' WHERE id = 101");
        // Our payer pays 500 but only 200 fits — 300 overpaid (never allocated).
        $this->apply(['transaction_id' => 'pi_pool']);
        $this->assertEquals(500.00, (float) $this->invoice(101)['amount_paid']);

        // Auto-refund of the 300 excess: ledger must NOT move.
        PaymentService::applyProcessorRefund($this->pdo, 'stripe', 'pi_pool', 300.00);
        $this->assertEquals(500.00, (float) $this->invoice(101)['amount_paid']);
        $this->assertSame('paid', $this->invoice(101)['status']);

        // Refunding beyond the pool (total 400 = 300 pool + 100 real) reverses 100.
        PaymentService::applyProcessorRefund($this->pdo, 'stripe', 'pi_pool', 400.00);
        $this->assertEquals(400.00, (float) $this->invoice(101)['amount_paid']);
        $this->assertSame('partial', $this->invoice(101)['status']);
    }

    public function testRefundForUnknownTransactionReportsIt(): void {
        $result = PaymentService::applyProcessorRefund($this->pdo, 'stripe', 'pi_nope', 10.00);
        $this->assertFalse($result['success']);
        $this->assertTrue($result['unknown_transaction']);
    }

    public function testUnknownInvoiceRollsBackEverything(): void {
        try {
            $this->apply(['invoice_ids' => [101, 999], 'transaction_id' => 'pi_bad']);
            $this->fail('Expected PaymentValidationException');
        } catch (PaymentValidationException $e) {
            // expected
        }

        $this->assertEquals(0.00, (float) $this->invoice(101)['amount_paid']);
        $this->assertEquals(0, $this->pdo->query("SELECT COUNT(*) FROM payment_transactions")->fetchColumn());
        $this->assertFalse($this->pdo->inTransaction());
    }
}
