<?php
/**
 * Scholarship on an invoice — the ONE write path (decided with Maggie 2026-09-12,
 * docs/scholarship-on-invoice-plan-2026-09.md).
 *
 * A club admin or treasurer (te_is_financial_admin) reduces what one family owes
 * on one invoice. The rules that bind, each pinned by ScholarshipAwardTest:
 *
 *   1. The amount is validated in dollars: > 0 and <= subtotal - discount_amount.
 *      Percent is a modal convenience; the API takes dollars so what is stored is
 *      what was shown.
 *   2. Never below what is already paid. A new total under amount_paid is refused
 *      naming the paid figure — staff refund first; no silent credits.
 *   3. A fully covered invoice becomes 'paid' with paid_at set, the rule
 *      PaymentService already applies at a zero balance. No 'waived' status.
 *   4. Revoke restores the previous total and moves a scholarship-only 'paid'
 *      invoice back to 'partial' or 'sent', derived from whether anything was paid.
 *   5. One scholarship per invoice: a second award replaces the first.
 *   6. One transaction across invoices + athlete_payments + audit_log.
 *   7. Audited as scholarship_awarded / scholarship_revoked with old and new
 *      amounts and totals and the reason.
 *   8. The reason and the awarding user are STAFF data — te_scholarship_public_fields()
 *      is what a non-financial-admin read may carry.
 *
 * Every query is SQLite/Postgres portable; the tests run against SQLite.
 */

require_once __DIR__ . '/AuditLogger.php';

const TE_SCHOLARSHIP_DEFAULT_LABEL = 'Scholarship';
const TE_SCHOLARSHIP_LABEL_MAX = 80;

/** Columns a non-financial-admin reader may see. Everything else stays with staff. */
const TE_SCHOLARSHIP_PUBLIC_FIELDS = ['scholarship_amount', 'scholarship_label'];
const TE_SCHOLARSHIP_STAFF_FIELDS = ['scholarship_reason', 'scholarship_id', 'scholarship_awarded_by', 'scholarship_awarded_at'];

class ScholarshipException extends Exception {
    public int $status;
    public function __construct(string $message, int $status = 422) {
        parent::__construct($message);
        $this->status = $status;
    }
}

/** Are the 102 columns present? Probes information_schema on Postgres, PRAGMA on SQLite. */
function te_scholarship_columns_present(PDO $pdo): bool {
    // WeakMap, not spl_object_id: an id is recycled once its PDO is freed, and a
    // recycled id would answer for a different database.
    static $cache = null;
    if ($cache === null) $cache = new WeakMap();
    if (isset($cache[$pdo])) return $cache[$pdo];
    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $cols = $pdo->query("PRAGMA table_info(invoices)")->fetchAll(PDO::FETCH_COLUMN, 1);
            $present = in_array('scholarship_amount', $cols, true);
        } else {
            $stmt = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_name = 'invoices' AND column_name = 'scholarship_amount'");
            $stmt->execute();
            $present = (bool) $stmt->fetchColumn();
        }
    } catch (Throwable $e) {
        $present = false;
    }
    $cache[$pdo] = $present;
    return $present;
}

function te_scholarship_cents($v): int {
    return (int) round(((float) $v) * 100);
}

function te_scholarship_money(int $cents): string {
    return '$' . number_format($cents / 100, 2);
}

/**
 * Strip the staff-only fields from an invoice row unless the reader is a financial
 * admin. Applied by every read that returns an invoice.
 */
function te_scholarship_shape_row(array $row, bool $financialAdmin): array {
    if ($financialAdmin) return $row;
    foreach (TE_SCHOLARSHIP_STAFF_FIELDS as $f) {
        unset($row[$f]);
    }
    return $row;
}

/**
 * Validate the request body for an award. Returns [amountCents, label, reason, scholarshipId].
 * Throws ScholarshipException(422) with a sentence the modal renders verbatim.
 */
function te_scholarship_validate_input(array $input): array {
    if (!array_key_exists('amount', $input) || $input['amount'] === '' || $input['amount'] === null) {
        throw new ScholarshipException('A scholarship amount is required.');
    }
    if (!is_numeric($input['amount'])) {
        throw new ScholarshipException('The scholarship amount must be a number of dollars.');
    }
    $cents = te_scholarship_cents($input['amount']);
    if ($cents <= 0) {
        throw new ScholarshipException('The scholarship amount must be greater than zero.');
    }

    $label = trim((string) ($input['label'] ?? ''));
    if ($label === '') $label = TE_SCHOLARSHIP_DEFAULT_LABEL;
    if (mb_strlen($label) > TE_SCHOLARSHIP_LABEL_MAX) {
        throw new ScholarshipException('The label must be ' . TE_SCHOLARSHIP_LABEL_MAX . ' characters or fewer.');
    }

    $reason = trim((string) ($input['reason'] ?? ''));
    if ($reason === '') {
        throw new ScholarshipException('A reason is required. It is kept for the club\'s records and never shown to the family.');
    }

    $scholarshipId = null;
    if (isset($input['scholarship_id']) && $input['scholarship_id'] !== '') {
        if (!ctype_digit((string) $input['scholarship_id'])) {
            throw new ScholarshipException('scholarship_id must be an integer.');
        }
        $scholarshipId = (int) $input['scholarship_id'];
    }

    return [$cents, $label, $reason, $scholarshipId];
}

/** Lock and load the invoice row. Null when it does not exist. */
function te_scholarship_load_invoice(PDO $pdo, int $invoiceId): ?array {
    $forUpdate = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
    $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ?{$forUpdate}");
    $stmt->execute([$invoiceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Status after a total change. 'paid' at zero balance (rule 3); otherwise a
 * scholarship-only 'paid' falls back to 'partial' if anything was paid, else to
 * 'sent' (rule 4). Any other status is kept — draft stays draft, viewed stays viewed.
 */
function te_scholarship_next_status(string $current, int $newTotalCents, int $paidCents): string {
    if ($newTotalCents - $paidCents <= 0) return 'paid';
    if ($current === 'paid') return $paidCents > 0 ? 'partial' : 'sent';
    return $current;
}

/**
 * Write the new scholarship onto the invoice (and mirror onto athlete_payments).
 * Shared by award and revoke. Caller holds the transaction.
 */
function te_scholarship_write(PDO $pdo, array $invoice, int $newScholarshipCents, ?string $label, ?string $reason, ?int $scholarshipId, ?int $actorId, string $nowSql): array {
    $subtotalCents = te_scholarship_cents($invoice['subtotal']);
    $discountCents = te_scholarship_cents($invoice['discount_amount'] ?? 0);
    $paidCents     = te_scholarship_cents($invoice['amount_paid'] ?? 0);
    $newTotalCents = $subtotalCents - $discountCents - $newScholarshipCents;

    if ($newTotalCents < 0) {
        throw new ScholarshipException('The scholarship cannot exceed the invoice amount of '
            . te_scholarship_money($subtotalCents - $discountCents) . ' after the existing discount.');
    }
    if ($newTotalCents < $paidCents) {
        // Rule 2. Never a credit: the refund path exists and is audited.
        throw new ScholarshipException(te_scholarship_money($paidCents)
            . ' has already been paid on this invoice; a scholarship cannot take the total below that. Refund first.');
    }

    $newStatus = te_scholarship_next_status((string) $invoice['status'], $newTotalCents, $paidCents);
    $paidAtSql = $newStatus === 'paid'
        ? ($invoice['paid_at'] ? 'paid_at' : $nowSql)
        : 'NULL';

    $awarded = $newScholarshipCents > 0;
    $stmt = $pdo->prepare("
        UPDATE invoices
        SET scholarship_amount = ?,
            scholarship_label = ?,
            scholarship_reason = ?,
            scholarship_id = ?,
            scholarship_awarded_by = ?,
            scholarship_awarded_at = " . ($awarded ? $nowSql : 'NULL') . ",
            total_amount = ?,
            status = ?,
            paid_at = {$paidAtSql},
            updated_at = {$nowSql}
        WHERE id = ?
    ");
    $stmt->execute([
        $newScholarshipCents / 100,
        $awarded ? $label : null,
        $awarded ? $reason : null,
        $awarded ? $scholarshipId : null,
        $awarded ? $actorId : null,
        $newTotalCents / 100,
        $newStatus,
        (int) $invoice['id'],
    ]);

    // Mirror onto the payment row so the older payments dashboard and the demo
    // processor agree with the invoice.
    if (!empty($invoice['athlete_payment_id'])) {
        $pdo->prepare("
            UPDATE athlete_payments
            SET scholarship_amount = ?,
                final_amount = ?,
                amount_remaining = ? - amount_paid,
                status = CASE WHEN amount_paid >= ? THEN 'paid' WHEN amount_paid > 0 THEN 'partial' ELSE 'pending' END,
                updated_at = {$nowSql}
            WHERE id = ?
        ")->execute([
            $newScholarshipCents / 100,
            $newTotalCents / 100,
            $newTotalCents / 100,
            $newTotalCents / 100,
            (int) $invoice['athlete_payment_id'],
        ]);
    }

    return [
        'scholarship_amount' => $newScholarshipCents / 100,
        'total_amount'       => $newTotalCents / 100,
        'amount_paid'        => $paidCents / 100,
        'balance_due'        => max(0, $newTotalCents - $paidCents) / 100,
        'status'             => $newStatus,
    ];
}

function te_scholarship_now_sql(PDO $pdo): string {
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? "datetime('now')" : 'NOW()';
}

/**
 * Award (or replace) the scholarship on an invoice.
 *
 * @throws ScholarshipException 404 unknown invoice, 409 cancelled, 422 rule violation
 */
function te_scholarship_apply(PDO $pdo, int $invoiceId, array $input, ?int $actorId): array {
    [$cents, $label, $reason, $scholarshipId] = te_scholarship_validate_input($input);
    $nowSql = te_scholarship_now_sql($pdo);

    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $invoice = te_scholarship_load_invoice($pdo, $invoiceId);
        if (!$invoice) throw new ScholarshipException('Invoice not found.', 404);
        if ($invoice['status'] === 'cancelled') {
            throw new ScholarshipException('This invoice is cancelled; a scholarship cannot be applied to it.', 409);
        }
        if ($scholarshipId !== null) {
            $chk = $pdo->prepare('SELECT id FROM scholarships WHERE id = ?');
            $chk->execute([$scholarshipId]);
            if (!$chk->fetchColumn()) throw new ScholarshipException('That scholarship fund does not exist.');
        }

        $before = [
            'scholarship_amount' => (float) ($invoice['scholarship_amount'] ?? 0),
            'total_amount'       => (float) $invoice['total_amount'],
            'status'             => $invoice['status'],
        ];
        $after = te_scholarship_write($pdo, $invoice, $cents, $label, $reason, $scholarshipId, $actorId, $nowSql);

        AuditLogger::log($pdo, $actorId, 'scholarship_awarded', 'invoices', $invoiceId, [
            'athlete_id'  => (int) $invoice['athlete_id'],
            'label'       => $label,
            'reason'      => $reason,
            'scholarship_id' => $scholarshipId,
            'replaced'    => $before['scholarship_amount'] > 0,
            'before'      => $before,
            'after'       => ['scholarship_amount' => $after['scholarship_amount'], 'total_amount' => $after['total_amount'], 'status' => $after['status']],
        ]);

        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    return $after + ['scholarship_label' => $label, 'invoice_id' => $invoiceId];
}

/**
 * Remove the scholarship from an invoice, restoring the previous total.
 *
 * @throws ScholarshipException 404 unknown invoice, 409 nothing to revoke
 */
function te_scholarship_revoke(PDO $pdo, int $invoiceId, ?string $reason, ?int $actorId): array {
    $nowSql = te_scholarship_now_sql($pdo);
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $invoice = te_scholarship_load_invoice($pdo, $invoiceId);
        if (!$invoice) throw new ScholarshipException('Invoice not found.', 404);
        $currentCents = te_scholarship_cents($invoice['scholarship_amount'] ?? 0);
        if ($currentCents <= 0) {
            throw new ScholarshipException('This invoice has no scholarship to remove.', 409);
        }

        $before = [
            'scholarship_amount' => $currentCents / 100,
            'label'              => $invoice['scholarship_label'],
            'reason'             => $invoice['scholarship_reason'],
            'total_amount'       => (float) $invoice['total_amount'],
            'status'             => $invoice['status'],
        ];
        $after = te_scholarship_write($pdo, $invoice, 0, null, null, null, null, $nowSql);

        AuditLogger::log($pdo, $actorId, 'scholarship_revoked', 'invoices', $invoiceId, [
            'athlete_id' => (int) $invoice['athlete_id'],
            'reason'     => trim((string) $reason) !== '' ? trim((string) $reason) : null,
            'before'     => $before,
            'after'      => ['scholarship_amount' => 0, 'total_amount' => $after['total_amount'], 'status' => $after['status']],
        ]);

        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    return $after + ['scholarship_label' => null, 'invoice_id' => $invoiceId];
}

/**
 * Sum of scholarships awarded on a club's invoices, optionally within a date
 * range on scholarship_awarded_at. Used by PaymentReportService::summary.
 * Returns 0.0 when the 102 columns are not applied yet.
 */
function te_scholarship_awarded_total(PDO $pdo, int $clubId, ?string $from = null, ?string $to = null): array {
    if (!te_scholarship_columns_present($pdo)) {
        return ['scholarships_awarded' => 0.0, 'scholarship_count' => 0];
    }
    $sql = "
        SELECT COALESCE(SUM(i.scholarship_amount), 0) AS total, COUNT(*) AS n
        FROM invoices i
        LEFT JOIN programs p ON p.id = i.program_id
        LEFT JOIN athletes a ON a.id = i.athlete_id
        WHERE i.scholarship_amount > 0
          AND COALESCE(p.club_id, a.club_id) = " . (int) $clubId;
    $params = [];
    if ($from) { $sql .= " AND i.scholarship_awarded_at >= ?"; $params[] = $from . ' 00:00:00'; }
    if ($to)   { $sql .= " AND i.scholarship_awarded_at <= ?"; $params[] = $to . ' 23:59:59'; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return [
        'scholarships_awarded' => round((float) ($row['total'] ?? 0), 2),
        'scholarship_count'    => (int) ($row['n'] ?? 0),
    ];
}
