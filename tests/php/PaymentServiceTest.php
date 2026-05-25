<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;
use AuthMiddleware;
use PaymentService;
use OwnershipException;
use PaymentValidationException;

/**
 * Unit tests for PaymentService::recordPayment (PAR-17 / PAR-18).
 *
 * Runs against an in-memory SQLite DB seeded with a fixture — never touches the
 * production Neon database. The SQL in PaymentService/AthleteScope is portable
 * (IN / JOIN / scoped UPDATE) so it behaves identically on SQLite and Postgres.
 *
 * Fixture (one parent with two kids + an unrelated family):
 *   Parent  : alice@family-a.com (guardian 200)
 *     - Athlete 1 -> invoice 101  ($100.00 total, $0 paid)
 *     - Athlete 2 -> invoice 102  ($50.00 total,  $0 paid)   <- the SIBLING
 *   Other family: bob@family-b.com (guardian 201)
 *     - Athlete 3 -> invoice 103  ($200.00 total, $0 paid)   <- NOT owned by alice
 */
class PaymentServiceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->createSchema();
        $this->seed();
    }

    private function createSchema(): void
    {
        $this->pdo->exec("
            CREATE TABLE teams (
                id INTEGER PRIMARY KEY,
                name TEXT,
                club_id INTEGER,
                primary_coach_id INTEGER,
                deleted_at TEXT
            );
            CREATE TABLE team_members (
                id INTEGER PRIMARY KEY,
                team_id INTEGER,
                user_id INTEGER,
                athlete_id INTEGER,
                role TEXT,
                status TEXT
            );
            CREATE TABLE athletes (
                id INTEGER PRIMARY KEY,
                first_name TEXT,
                last_name TEXT
            );
            CREATE TABLE guardians (
                id INTEGER PRIMARY KEY,
                email TEXT
            );
            CREATE TABLE athlete_guardians (
                id INTEGER PRIMARY KEY,
                athlete_id INTEGER,
                guardian_id INTEGER
            );
            CREATE TABLE invoices (
                id INTEGER PRIMARY KEY,
                athlete_id INTEGER,
                total_amount NUMERIC,
                amount_paid NUMERIC DEFAULT 0,
                status TEXT,
                paid_at TEXT,
                updated_at TEXT
            );
        ");
    }

    private function seed(): void
    {
        $this->pdo->exec("INSERT INTO athletes (id, first_name, last_name) VALUES
            (1, 'Anna', 'Aaron'),
            (2, 'Andy', 'Aaron'),
            (3, 'Ben', 'Brown')");

        $this->pdo->exec("INSERT INTO guardians (id, email) VALUES
            (200, 'alice@family-a.com'),
            (201, 'bob@family-b.com')");

        $this->pdo->exec("INSERT INTO athlete_guardians (id, athlete_id, guardian_id) VALUES
            (1, 1, 200),
            (2, 2, 200),
            (3, 3, 201)");

        // Invoices: 101 & 102 belong to alice's kids; 103 belongs to bob's kid.
        $this->pdo->exec("INSERT INTO invoices (id, athlete_id, total_amount, amount_paid, status) VALUES
            (101, 1, 100.00, 0, 'sent'),
            (102, 2,  50.00, 0, 'sent'),
            (103, 3, 200.00, 0, 'sent')");
    }

    private function parentAlice(): AuthMiddleware
    {
        return AuthMiddleware::fromContext([
            'user_id' => 70,
            'email' => 'alice@family-a.com',
            'roles' => [],
        ]);
    }

    private function invoiceRow(int $id): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM invoices WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // ---- PAR-17: record-payment marks the invoice paid & recomputes balance ----

    public function testFullPaymentMarksInvoicePaidAndZeroesBalance(): void
    {
        $auth = $this->parentAlice();
        $result = PaymentService::recordPayment($this->pdo, $auth, [101], 100.00);

        $this->assertTrue($result['success']);
        $this->assertSame(100.00, $result['amount_applied']);
        $this->assertSame(0.0, $result['amount_unapplied']);

        $inv = $result['invoices'][0];
        $this->assertSame(101, $inv['id']);
        $this->assertSame('paid', $inv['status']);
        $this->assertSame(0.0, $inv['balance_due']);
        $this->assertSame(100.00, $inv['amount_paid']);

        // Persisted to the DB, not just the return value.
        $db = $this->invoiceRow(101);
        $this->assertSame('paid', $db['status']);
        $this->assertEquals(100.00, (float) $db['amount_paid']);
        $this->assertNotNull($db['paid_at']);
    }

    public function testPartialPaymentSetsPartialStatusAndRemainingBalance(): void
    {
        $auth = $this->parentAlice();
        $result = PaymentService::recordPayment($this->pdo, $auth, [101], 40.00);

        $inv = $result['invoices'][0];
        $this->assertSame('partial', $inv['status']);
        $this->assertSame(40.00, $inv['amount_paid']);
        $this->assertSame(60.00, $inv['balance_due']);

        $db = $this->invoiceRow(101);
        $this->assertSame('partial', $db['status']);
        $this->assertEquals(40.00, (float) $db['amount_paid']);
        $this->assertNull($db['paid_at']); // not fully paid -> no paid_at stamp
    }

    public function testTopUpPartialInvoiceToPaid(): void
    {
        $auth = $this->parentAlice();
        PaymentService::recordPayment($this->pdo, $auth, [101], 40.00);
        $result = PaymentService::recordPayment($this->pdo, $auth, [101], 60.00);

        $inv = $result['invoices'][0];
        $this->assertSame('paid', $inv['status']);
        $this->assertSame(100.00, $inv['amount_paid']);
        $this->assertSame(0.0, $inv['balance_due']);
    }

    // ---- PAR-18: paying one invoice leaves a sibling invoice untouched --------

    public function testPayingOneInvoiceDoesNotTouchSibling(): void
    {
        $auth = $this->parentAlice();
        PaymentService::recordPayment($this->pdo, $auth, [101], 100.00);

        // Sibling invoice 102 (different athlete, same parent) is unchanged.
        $sibling = $this->invoiceRow(102);
        $this->assertSame('sent', $sibling['status']);
        $this->assertEquals(0, (float) $sibling['amount_paid']);
        $this->assertNull($sibling['paid_at']);
    }

    public function testMultiInvoicePaymentAllocatesPerInvoice(): void
    {
        $auth = $this->parentAlice();
        // $130 across invoice 101 ($100) then 102 ($50): 101 fully paid, 102 gets $30.
        $result = PaymentService::recordPayment($this->pdo, $auth, [101, 102], 130.00);

        $this->assertSame(130.00, $result['amount_applied']);

        $db101 = $this->invoiceRow(101);
        $db102 = $this->invoiceRow(102);
        $this->assertSame('paid', $db101['status']);
        $this->assertEquals(100.00, (float) $db101['amount_paid']);
        $this->assertSame('partial', $db102['status']);
        $this->assertEquals(30.00, (float) $db102['amount_paid']);
    }

    // ---- PAR-18: rejects a non-owned invoice with 403 (OwnershipException) -----

    public function testRejectsNonOwnedInvoice(): void
    {
        $auth = $this->parentAlice();

        try {
            // Invoice 103 belongs to bob's family — alice may not pay it.
            PaymentService::recordPayment($this->pdo, $auth, [103], 200.00);
            $this->fail('Expected OwnershipException for a non-owned invoice');
        } catch (OwnershipException $e) {
            $this->assertStringContainsString('103', $e->getMessage());
        }

        // Nothing was written to the non-owned invoice.
        $db = $this->invoiceRow(103);
        $this->assertSame('sent', $db['status']);
        $this->assertEquals(0, (float) $db['amount_paid']);
    }

    public function testMixedOwnedAndNonOwnedRejectsAndAppliesNothing(): void
    {
        $auth = $this->parentAlice();

        try {
            // 101 is owned, 103 is not — the whole request must be rejected,
            // and the owned invoice must NOT be charged.
            PaymentService::recordPayment($this->pdo, $auth, [101, 103], 150.00);
            $this->fail('Expected OwnershipException when any invoice is out of scope');
        } catch (OwnershipException $e) {
            $this->assertStringContainsString('103', $e->getMessage());
        }

        $db101 = $this->invoiceRow(101);
        $this->assertSame('sent', $db101['status']);
        $this->assertEquals(0, (float) $db101['amount_paid']);
    }

    public function testUnknownInvoiceIsRejected(): void
    {
        $auth = $this->parentAlice();
        $this->expectException(OwnershipException::class);
        PaymentService::recordPayment($this->pdo, $auth, [999999], 10.00);
    }

    // ---- Input validation -----------------------------------------------------

    public function testEmptyInvoiceListIsRejected(): void
    {
        $auth = $this->parentAlice();
        $this->expectException(PaymentValidationException::class);
        PaymentService::recordPayment($this->pdo, $auth, [], 10.00);
    }

    public function testNonPositiveAmountIsRejected(): void
    {
        $auth = $this->parentAlice();
        $this->expectException(PaymentValidationException::class);
        PaymentService::recordPayment($this->pdo, $auth, [101], 0.0);
    }
}
