<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;
use ScholarshipException;

require_once __DIR__ . '/../../lib/feature_flags.php';
require_once __DIR__ . '/../../lib/email_invoice_and_registration.php';

/**
 * Scholarship on an invoice — lib/scholarship.php (decided with Maggie 2026-09-12,
 * docs/scholarship-on-invoice-plan-2026-09.md).
 *
 * Runs against an in-memory SQLite DB whose `invoices` table mirrors the live
 * columns plus migration 102. Also parses api/invoices.php to pin WHICH
 * predicate gates the two new actions — this repo's recurring bug is a correct
 * predicate called from the wrong place.
 *
 * Fixture:
 *   invoice 101  athlete 1 (club 32)  subtotal 425, sibling discount 42.50, total 382.50, paid 0, status sent, payment row 501
 *   invoice 102  athlete 1 (club 32)  subtotal 300, total 300, paid 150, status partial
 *   invoice 103  athlete 2 (club 44)  subtotal 200, total 200, cancelled
 *   invoice 104  athlete 1 (club 32)  subtotal 100, total 100, paid 100, status paid (really paid)
 */
class ScholarshipAwardTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0); // AuditLogger writes NOW()
        $this->pdo->exec("
            CREATE TABLE athletes (id INTEGER PRIMARY KEY, club_id INTEGER, first_name TEXT, last_name TEXT);
            CREATE TABLE programs (id INTEGER PRIMARY KEY, club_id INTEGER, name TEXT);
            CREATE TABLE scholarships (id INTEGER PRIMARY KEY, name TEXT, club_id INTEGER);
            CREATE TABLE athlete_payments (
                id INTEGER PRIMARY KEY, athlete_id INTEGER, base_amount REAL, discount_amount REAL DEFAULT 0,
                scholarship_amount REAL DEFAULT 0, final_amount REAL, status TEXT, amount_paid REAL DEFAULT 0,
                amount_remaining REAL, updated_at TEXT
            );
            CREATE TABLE invoices (
                id INTEGER PRIMARY KEY, invoice_number TEXT, athlete_id INTEGER, athlete_payment_id INTEGER,
                program_id INTEGER, league_id INTEGER, invoice_date TEXT, due_date TEXT,
                subtotal REAL NOT NULL, discount_amount REAL DEFAULT 0, total_amount REAL NOT NULL,
                amount_paid REAL DEFAULT 0, status TEXT DEFAULT 'draft', memo TEXT, pdf_url TEXT,
                sent_at TEXT, viewed_at TEXT, paid_at TEXT, created_at TEXT, updated_at TEXT,
                scholarship_amount REAL NOT NULL DEFAULT 0, scholarship_label TEXT, scholarship_reason TEXT,
                scholarship_id INTEGER, scholarship_awarded_by INTEGER, scholarship_awarded_at TEXT
            );
            CREATE TABLE audit_log (
                id INTEGER PRIMARY KEY, user_id INTEGER, action TEXT, resource_type TEXT, resource_id INTEGER,
                ip_address TEXT, user_agent TEXT, details TEXT, created_at TEXT
            );
            CREATE TABLE payment_transactions (id INTEGER PRIMARY KEY, amount REAL, refund_amount REAL DEFAULT 0, created_at TEXT);
            CREATE TABLE payment_allocations (id INTEGER PRIMARY KEY, payment_transaction_id INTEGER, invoice_id INTEGER, amount REAL);
        ");
        $this->pdo->exec("INSERT INTO athletes VALUES (1, 32, 'Emma', 'Ortiz'), (2, 44, 'Liam', 'Park')");
        $this->pdo->exec("INSERT INTO programs VALUES (10, 32, 'Fall 2026'), (20, 44, 'Fall 2026')");
        $this->pdo->exec("INSERT INTO scholarships (id, name, club_id) VALUES (7, 'Board fund', 32)");
        $this->pdo->exec("INSERT INTO athlete_payments (id, athlete_id, base_amount, discount_amount, final_amount, status, amount_paid, amount_remaining)
            VALUES (501, 1, 425.00, 42.50, 382.50, 'pending', 0, 382.50)");
        $this->pdo->exec("INSERT INTO invoices (id, invoice_number, athlete_id, athlete_payment_id, program_id, due_date, subtotal, discount_amount, total_amount, amount_paid, status) VALUES
            (101, 'INV-1', 1, 501, 10, '2026-10-01', 425.00, 42.50, 382.50, 0, 'sent'),
            (102, 'INV-2', 1, NULL, 10, '2026-10-01', 300.00, 0, 300.00, 150.00, 'partial'),
            (103, 'INV-3', 2, NULL, 20, '2026-10-01', 200.00, 0, 200.00, 0, 'cancelled'),
            (104, 'INV-4', 1, NULL, 10, '2026-10-01', 100.00, 0, 100.00, 100.00, 'paid')");
        $this->pdo->exec("UPDATE invoices SET paid_at = '2026-09-01 10:00:00' WHERE id = 104");
    }

    private function invoice(int $id): array
    {
        $s = $this->pdo->prepare('SELECT * FROM invoices WHERE id = ?');
        $s->execute([$id]);
        return $s->fetch();
    }

    private function audits(string $action): array
    {
        $s = $this->pdo->prepare('SELECT * FROM audit_log WHERE action = ? ORDER BY id');
        $s->execute([$action]);
        return array_map(fn($r) => $r + ['d' => json_decode($r['details'], true)], $s->fetchAll());
    }

    private function award(int $invoiceId, array $over = []): array
    {
        return te_scholarship_apply($this->pdo, $invoiceId, $over + [
            'amount' => 200, 'label' => 'Scholarship', 'reason' => 'Board approved 9/3',
        ], 9);
    }

    // ---- the invariant -------------------------------------------------

    public function testAwardLowersTheTotalAndKeepsTheSiblingDiscountSeparate(): void
    {
        $r = $this->award(101);
        $row = $this->invoice(101);
        $this->assertSame(200.0, (float) $row['scholarship_amount']);
        $this->assertSame(42.5, (float) $row['discount_amount'], 'the sibling discount is not folded into the scholarship');
        $this->assertSame(182.5, (float) $row['total_amount'], 'total = subtotal - discount - scholarship');
        $this->assertSame('sent', $row['status'], 'a partial scholarship does not change the status');
        $this->assertSame('Scholarship', $row['scholarship_label']);
        $this->assertSame('Board approved 9/3', $row['scholarship_reason']);
        $this->assertSame(9, (int) $row['scholarship_awarded_by']);
        $this->assertNotEmpty($row['scholarship_awarded_at']);
        $this->assertSame(182.5, $r['total_amount']);
        $this->assertSame(182.5, $r['balance_due']);
    }

    public function testAwardMirrorsOntoThePaymentRow(): void
    {
        $this->award(101);
        $ap = $this->pdo->query('SELECT * FROM athlete_payments WHERE id = 501')->fetch();
        $this->assertSame(200.0, (float) $ap['scholarship_amount']);
        $this->assertSame(182.5, (float) $ap['final_amount']);
        $this->assertSame(182.5, (float) $ap['amount_remaining']);
        $this->assertSame('pending', $ap['status']);
    }

    // ---- rule 1: validated, not trusted ---------------------------------

    public function testAmountAboveTheInvoiceIsRefused(): void
    {
        $this->expectException(ScholarshipException::class);
        $this->expectExceptionMessage('cannot exceed the invoice amount of $382.50');
        $this->award(101, ['amount' => 400]);
    }

    public function testZeroNegativeAndNonNumericAmountsAreRefused(): void
    {
        foreach ([0, -5, 'abc', '', null] as $bad) {
            try {
                $this->award(101, ['amount' => $bad]);
                $this->fail("amount " . var_export($bad, true) . " was accepted");
            } catch (ScholarshipException $e) {
                $this->assertSame(422, $e->status);
            }
        }
    }

    public function testAReasonIsRequiredAndTheLabelDefaults(): void
    {
        try {
            $this->award(101, ['reason' => '   ']);
            $this->fail('a blank reason was accepted');
        } catch (ScholarshipException $e) {
            $this->assertStringContainsString('reason is required', $e->getMessage());
        }
        $this->award(101, ['label' => '']);
        $this->assertSame('Scholarship', $this->invoice(101)['scholarship_label']);
    }

    public function testPercentIsNotAccepted(): void
    {
        // The API takes dollars only; the modal converts. A percent string is a number, so
        // '50%' must be refused as non-numeric rather than read as $50.
        $this->expectException(ScholarshipException::class);
        $this->award(101, ['amount' => '50%']);
    }

    // ---- rule 2: never below what is paid --------------------------------

    public function testAwardBelowWhatWasAlreadyPaidIsRefusedNamingThePaidAmount(): void
    {
        try {
            $this->award(102, ['amount' => 200]); // 300 - 200 = 100 < 150 paid
            $this->fail('a credit was created');
        } catch (ScholarshipException $e) {
            $this->assertSame(422, $e->status);
            $this->assertStringContainsString('$150.00 has already been paid', $e->getMessage());
        }
        $this->assertSame(300.0, (float) $this->invoice(102)['total_amount'], 'nothing written');
        $this->assertCount(0, $this->audits('scholarship_awarded'));
    }

    public function testAwardExactlyDownToWhatWasPaidBecomesPaid(): void
    {
        $this->award(102, ['amount' => 150]); // total 150 == paid 150
        $row = $this->invoice(102);
        $this->assertSame('paid', $row['status']);
        $this->assertNotEmpty($row['paid_at']);
    }

    // ---- rule 3: fully covered => paid -----------------------------------

    public function testFullScholarshipMarksTheInvoicePaid(): void
    {
        $this->award(101, ['amount' => 382.50]);
        $row = $this->invoice(101);
        $this->assertSame(0.0, (float) $row['total_amount']);
        $this->assertSame('paid', $row['status']);
        $this->assertNotEmpty($row['paid_at']);
        $ap = $this->pdo->query('SELECT * FROM athlete_payments WHERE id = 501')->fetch();
        $this->assertSame('paid', $ap['status']);
        $this->assertSame(0.0, (float) $ap['amount_remaining']);
    }

    // ---- rule 4: revoke restores -----------------------------------------

    public function testRevokeRestoresTheTotalAndUnpaysAScholarshipOnlyPaid(): void
    {
        $this->award(101, ['amount' => 382.50]);
        $this->assertSame('paid', $this->invoice(101)['status']);
        $r = te_scholarship_revoke($this->pdo, 101, 'Family withdrew request', 9);
        $row = $this->invoice(101);
        $this->assertSame(0.0, (float) $row['scholarship_amount']);
        $this->assertSame(382.5, (float) $row['total_amount']);
        $this->assertSame('sent', $row['status'], 'nothing was paid, so back to sent — not partial');
        $this->assertNull($row['paid_at']);
        $this->assertNull($row['scholarship_label']);
        $this->assertNull($row['scholarship_reason']);
        $this->assertNull($row['scholarship_awarded_by']);
        $this->assertNull($row['scholarship_awarded_at']);
        $this->assertSame(382.5, $r['total_amount']);
        $ap = $this->pdo->query('SELECT * FROM athlete_payments WHERE id = 501')->fetch();
        $this->assertSame(382.5, (float) $ap['final_amount']);
    }

    public function testRevokeOnAPartiallyPaidInvoiceGoesBackToPartial(): void
    {
        $this->award(102, ['amount' => 150]);
        $this->assertSame('paid', $this->invoice(102)['status']);
        te_scholarship_revoke($this->pdo, 102, null, 9);
        $this->assertSame('partial', $this->invoice(102)['status']);
    }

    public function testRevokeDoesNotUnpayAGenuinelyPaidInvoice(): void
    {
        // 104 was paid with money. A scholarship on it is refused anyway (would go
        // below paid), so there is never a scholarship-only 'paid' to confuse it with.
        try {
            $this->award(104, ['amount' => 10]);
            $this->fail('accepted');
        } catch (ScholarshipException $e) {
            $this->assertStringContainsString('already been paid', $e->getMessage());
        }
        $this->assertSame('paid', $this->invoice(104)['status']);
        $this->assertSame('2026-09-01 10:00:00', $this->invoice(104)['paid_at']);
    }

    public function testRevokeWithNothingToRevokeIsA409(): void
    {
        try {
            te_scholarship_revoke($this->pdo, 101, null, 9);
            $this->fail('accepted');
        } catch (ScholarshipException $e) {
            $this->assertSame(409, $e->status);
        }
    }

    // ---- rule 5: replace, not stack --------------------------------------

    public function testASecondAwardReplacesTheFirst(): void
    {
        $this->award(101, ['amount' => 100]);
        $this->award(101, ['amount' => 150, 'reason' => 'Revised']);
        $row = $this->invoice(101);
        $this->assertSame(150.0, (float) $row['scholarship_amount'], 'not 250');
        $this->assertSame(232.5, (float) $row['total_amount']);
        $audits = $this->audits('scholarship_awarded');
        $this->assertCount(2, $audits);
        $this->assertTrue($audits[1]['d']['replaced']);
        $this->assertSame(100.0, (float) $audits[1]['d']['before']['scholarship_amount']);
    }

    // ---- cancelled / unknown / fund ---------------------------------------

    public function testCancelledInvoiceIsRefusedWith409(): void
    {
        try {
            $this->award(103);
            $this->fail('accepted');
        } catch (ScholarshipException $e) {
            $this->assertSame(409, $e->status);
        }
    }

    public function testUnknownInvoiceIs404(): void
    {
        try {
            $this->award(999);
            $this->fail('accepted');
        } catch (ScholarshipException $e) {
            $this->assertSame(404, $e->status);
        }
    }

    public function testAnUnknownFundIsRefusedAndAKnownOneIsRecorded(): void
    {
        try {
            $this->award(101, ['scholarship_id' => 999]);
            $this->fail('accepted');
        } catch (ScholarshipException $e) {
            $this->assertStringContainsString('fund does not exist', $e->getMessage());
        }
        $this->award(101, ['scholarship_id' => 7]);
        $this->assertSame(7, (int) $this->invoice(101)['scholarship_id']);
    }

    // ---- rule 7: audited -------------------------------------------------

    public function testAwardAndRevokeAreAuditedWithOldAndNewTotals(): void
    {
        $this->award(101);
        te_scholarship_revoke($this->pdo, 101, 'oops', 9);
        $a = $this->audits('scholarship_awarded')[0];
        $this->assertSame(9, (int) $a['user_id']);
        $this->assertSame('invoices', $a['resource_type']);
        $this->assertSame(101, (int) $a['resource_id']);
        $this->assertSame(1, $a['d']['athlete_id']);
        $this->assertSame('Board approved 9/3', $a['d']['reason']);
        $this->assertSame(382.5, (float) $a['d']['before']['total_amount']);
        $this->assertSame(182.5, (float) $a['d']['after']['total_amount']);
        $r = $this->audits('scholarship_revoked')[0];
        $this->assertSame('oops', $r['d']['reason']);
        $this->assertSame(200.0, (float) $r['d']['before']['scholarship_amount']);
        $this->assertSame(382.5, (float) $r['d']['after']['total_amount']);
    }

    // ---- rule 6: one transaction -----------------------------------------

    public function testARefusedAwardLeavesNoRowBehind(): void
    {
        try { $this->award(102, ['amount' => 200]); } catch (ScholarshipException $e) {}
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn());
        $this->assertFalse($this->pdo->inTransaction());
    }

    // ---- rule 8: reads are role-shaped -----------------------------------

    public function testStaffFieldsAreStrippedForNonFinancialReaders(): void
    {
        $this->award(101);
        $row = $this->invoice(101);
        $parent = te_scholarship_shape_row($row, false);
        $this->assertArrayHasKey('scholarship_amount', $parent);
        $this->assertArrayHasKey('scholarship_label', $parent);
        foreach (['scholarship_reason', 'scholarship_awarded_by', 'scholarship_awarded_at', 'scholarship_id'] as $f) {
            $this->assertArrayNotHasKey($f, $parent, "$f leaked to a non-financial reader");
        }
        $this->assertArrayHasKey('scholarship_reason', te_scholarship_shape_row($row, true));
    }

    public function testTheInvoiceEmailShowsTheLabelAndAmountAndNeverTheReason(): void
    {
        $c = te_invoice_email_content([
            'club_name' => 'Central Kansas United', 'guardian_first' => 'Ana', 'athlete_name' => 'Emma Ortiz',
            'invoice_number' => 'INV-1', 'total_amount' => 182.50, 'amount_paid' => 0, 'amount_due' => 182.50,
            'scholarship_amount' => 200, 'scholarship_label' => 'Booster Club Scholarship',
            'items' => [['description' => 'U12 Fall Registration', 'quantity' => 1, 'unit_price' => 425, 'line_total' => 425]],
        ]);
        $this->assertStringContainsString('Booster Club Scholarship', $c['html']);
        $this->assertStringContainsString('&minus;$200.00', $c['html']);
        $this->assertStringContainsString('Booster Club Scholarship: -$200.00', $c['text']);
        $this->assertStringNotContainsString('reason', strtolower($c['html']));

        $none = te_invoice_email_content(['total_amount' => 100, 'amount_due' => 100, 'amount_paid' => 0]);
        $this->assertStringNotContainsString('Scholarship', $none['html']);
    }

    // ---- reporting --------------------------------------------------------

    public function testTheTreasurerSummaryCountsScholarshipsPerClubBesideNotInsideNet(): void
    {
        $this->award(101, ['amount' => 200]);
        $this->award(102, ['amount' => 100]);
        $svc = new \PaymentReportService($this->pdo);
        $s = $svc->summary(32);
        $this->assertSame(300.0, $s['scholarships_awarded']);
        $this->assertSame(2, $s['scholarship_count']);
        $this->assertSame(0.0, $s['net'], 'a scholarship is not money in or out');
        $this->assertSame(0.0, $svc->summary(44)['scholarships_awarded'], 'club isolated');
        // Date range on awarded_at
        $this->assertSame(0.0, $svc->summary(32, '2020-01-01', '2020-12-31')['scholarships_awarded']);
    }

    public function testTheSummaryStillAnswersBeforeMigration102IsApplied(): void
    {
        $bare = new PDO('sqlite::memory:');
        $bare->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $bare->exec("CREATE TABLE athletes (id INTEGER PRIMARY KEY, club_id INTEGER);
            CREATE TABLE programs (id INTEGER PRIMARY KEY, club_id INTEGER);
            CREATE TABLE invoices (id INTEGER PRIMARY KEY, athlete_id INTEGER, program_id INTEGER, total_amount REAL, amount_paid REAL);
            CREATE TABLE payment_transactions (id INTEGER PRIMARY KEY, amount REAL, refund_amount REAL DEFAULT 0, created_at TEXT);
            CREATE TABLE payment_allocations (id INTEGER PRIMARY KEY, payment_transaction_id INTEGER, invoice_id INTEGER, amount REAL);");
        $s = (new \PaymentReportService($bare))->summary(32);
        $this->assertSame(0.0, $s['scholarships_awarded']);
    }

    // ---- the gateway: WHICH predicate, and the read shaping ---------------

    public function testTheGatewayGatesBothActionsOnTheFinancialAdminPredicate(): void
    {
        $src = file_get_contents(self::ROOT . '/api/invoices.php');
        $this->assertStringContainsString("require_once __DIR__ . '/../lib/scholarship.php'", $src);
        preg_match("/case 'award-scholarship':\s*case 'revoke-scholarship':(.*?)case 'family':/s", $src, $m);
        $this->assertNotEmpty($m, 'the two actions must be one case block ahead of family');
        $block = $m[1];
        $this->assertStringContainsString("te_assert_financial_admin(\$auth, \$pdo, ['invoice'", $block,
            'award/revoke must gate on te_assert_financial_admin (club admin / treasurer)');
        $this->assertStringNotContainsString('te_assert_financial_scope', $block,
            'te_assert_financial_scope admits a coach and must not gate a scholarship write');
        $this->assertStringContainsString("\$_SERVER['REQUEST_METHOD'] !== 'POST'", $block);
        $this->assertStringContainsString('te_scholarship_apply(', $block);
        $this->assertStringContainsString('te_scholarship_revoke(', $block);
        $this->assertStringContainsString('http_response_code($e->status)', $block, 'the sentence reaches the modal with its status');
        // The gate runs before the body is read.
        $this->assertLessThan(strpos($block, "file_get_contents('php://input')"), strpos($block, 'te_assert_financial_admin'));
    }

    public function testTheGatewayShapesReadsByStanding(): void
    {
        $src = file_get_contents(self::ROOT . '/api/invoices.php');
        preg_match("/case 'get':(.*?)case 'create':/s", $src, $get);
        $this->assertStringContainsString('te_scholarship_shape_row($invoice, ', $get[1], 'get must strip staff fields for non-financial readers');
        $this->assertStringContainsString('te_is_financial_admin($auth', $get[1]);
        preg_match("/case 'family':(.*?)default:/s", $src, $fam);
        $this->assertStringContainsString('te_scholarship_shape_row($row, false)', $fam[1], 'the family view never carries the reason');
        preg_match("/case 'list':(.*?)case 'get':/s", $src, $list);
        $this->assertStringContainsString('te_scholarship_columns_present($pdo)', $list[1], 'list is an explicit column list and must probe before naming the new columns');
        $this->assertStringNotContainsString('scholarship_reason', $list[1]);
    }

    public function testTheOnlyWritersOfTheScholarshipColumnsAreInTheLib(): void
    {
        $hits = [];
        foreach (['api', 'services', 'controllers', 'registration', 'legacy', 'workers'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . "/$dir"));
            foreach ($it as $f) {
                if ($f->getExtension() !== 'php') continue;
                $s = file_get_contents($f->getPathname());
                if (preg_match('/UPDATE\s+invoices[\s\S]{0,400}?SET[\s\S]{0,400}?scholarship_/i', $s)
                    || preg_match('/INSERT\s+INTO\s+invoices\s*\([^)]*scholarship_/i', $s)) {
                    $hits[] = substr($f->getPathname(), strlen(self::ROOT) + 1);
                }
            }
        }
        $this->assertSame([], $hits, 'invoices.scholarship_* is written by lib/scholarship.php only');
    }
}
