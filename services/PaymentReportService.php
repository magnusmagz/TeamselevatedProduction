<?php
/**
 * PaymentReportService — treasurer-grade reporting on the payment ledger.
 *
 * Everything money-in comes from OUR ledger (payment_transactions +
 * payment_allocations + invoices); payouts come from stripe_payouts, written
 * by the payout.paid/payout.failed webhook. Club attribution rides the
 * single-club-per-session invariant enforced at checkout creation: every
 * transaction's allocations belong to exactly one club.
 *
 * All SQL is SQLite/Postgres portable (tested against SQLite fixtures).
 * Authorization lives in api/payment-reports.php — club_admin or treasurer.
 */

class PaymentReportService {

    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /** Collected / refunded / net + counts for a club in a date range. */
    public function summary(int $clubId, ?string $from = null, ?string $to = null): array {
        [$dateSql, $dateParams] = $this->dateFilter($from, $to);

        // Club id is interpolated (already int-typed) rather than bound:
        // COALESCE(...) is an expression with no column affinity, so SQLite
        // would compare it to a string-bound parameter as INTEGER vs TEXT —
        // silently false forever. Columns get affinity conversion; expressions don't.
        $clubId = (int) $clubId;

        // Collected = allocation amounts landing on this club's invoices.
        $collectedStmt = $this->pdo->prepare("
            SELECT COALESCE(SUM(pa.amount), 0)
            FROM payment_allocations pa
            JOIN invoices i ON i.id = pa.invoice_id
            JOIN payment_transactions pt ON pt.id = pa.payment_transaction_id
            LEFT JOIN programs p ON p.id = i.program_id
            LEFT JOIN athletes a ON a.id = i.athlete_id
            WHERE COALESCE(p.club_id, a.club_id) = {$clubId}{$dateSql}
        ");
        $collectedStmt->execute($dateParams);
        $collected = (float) $collectedStmt->fetchColumn();

        // Refunds + counts per transaction (each transaction is single-club).
        $txnStmt = $this->pdo->prepare("
            SELECT COUNT(*) AS txn_count, COALESCE(SUM(pt.refund_amount), 0) AS refunded
            FROM payment_transactions pt
            WHERE pt.id IN (
                SELECT DISTINCT pa.payment_transaction_id
                FROM payment_allocations pa
                JOIN invoices i ON i.id = pa.invoice_id
                LEFT JOIN programs p ON p.id = i.program_id
                LEFT JOIN athletes a ON a.id = i.athlete_id
                WHERE COALESCE(p.club_id, a.club_id) = {$clubId}
            ){$dateSql}
        ");
        $txnStmt->execute($dateParams);
        $txn = $txnStmt->fetch(PDO::FETCH_ASSOC);

        $refunded = (float) $txn['refunded'];

        return [
            'collected' => round($collected, 2),
            'refunded' => round($refunded, 2),
            'net' => round($collected - $refunded, 2),
            'transaction_count' => (int) $txn['txn_count'],
        ];
    }

    /** Recent transactions for a club: payer, amount, method, refunds, invoices touched. */
    public function transactions(int $clubId, ?string $from = null, ?string $to = null, int $limit = 50): array {
        $limit = max(1, min(200, $limit));
        [$dateSql, $dateParams] = $this->dateFilter($from, $to);
        $clubId = (int) $clubId; // interpolated — see affinity note in summary()

        $stmt = $this->pdo->prepare("
            SELECT pt.id, pt.amount, pt.payment_method, pt.status, pt.payment_type,
                   pt.refund_amount, pt.created_at, pt.processor,
                   u.first_name, u.last_name
            FROM payment_transactions pt
            LEFT JOIN users u ON u.id = pt.paid_by_user_id
            WHERE pt.id IN (
                SELECT DISTINCT pa.payment_transaction_id
                FROM payment_allocations pa
                JOIN invoices i ON i.id = pa.invoice_id
                LEFT JOIN programs p ON p.id = i.program_id
                LEFT JOIN athletes a ON a.id = i.athlete_id
                WHERE COALESCE(p.club_id, a.club_id) = {$clubId}
            ){$dateSql}
            ORDER BY pt.created_at DESC, pt.id DESC
            LIMIT {$limit}
        ");
        $stmt->execute($dateParams);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return [];
        }

        // Invoice numbers per transaction, merged in PHP (portable, one query).
        $ids = array_column($rows, 'id');
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $invStmt = $this->pdo->prepare("
            SELECT pa.payment_transaction_id, i.invoice_number
            FROM payment_allocations pa JOIN invoices i ON i.id = pa.invoice_id
            WHERE pa.payment_transaction_id IN ($ph)
        ");
        $invStmt->execute($ids);
        $numbersByTxn = [];
        foreach ($invStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $numbersByTxn[(int) $r['payment_transaction_id']][] = $r['invoice_number'];
        }

        $out = [];
        foreach ($rows as $row) {
            $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
            $out[] = [
                'id' => (int) $row['id'],
                'payer_name' => $name !== '' ? $name : 'Online payment',
                'amount' => (float) $row['amount'],
                'refund_amount' => (float) $row['refund_amount'],
                'payment_method' => $row['payment_method'],
                'status' => $row['status'],
                'invoice_numbers' => $numbersByTxn[(int) $row['id']] ?? [],
                'date' => $row['created_at'],
            ];
        }
        return $out;
    }

    /** Payouts that hit (or failed to hit) the club's bank account. */
    public function payouts(int $clubId, int $limit = 24): array {
        $limit = max(1, min(100, $limit));
        $stmt = $this->pdo->prepare("
            SELECT stripe_payout_id, amount, currency, status, arrival_date, failure_message, created_at
            FROM stripe_payouts
            WHERE club_id = ?
            ORDER BY created_at DESC, id DESC
            LIMIT {$limit}
        ");
        $stmt->execute([$clubId]);
        return array_map(static function ($row) {
            return [
                'stripe_payout_id' => $row['stripe_payout_id'],
                'amount' => (float) $row['amount'],
                'currency' => $row['currency'],
                'status' => $row['status'],
                'arrival_date' => $row['arrival_date'],
                'failure_message' => $row['failure_message'],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Webhook-side: upsert a payout event (payout.paid / payout.failed) from a
     * connected account. Returns false when the account maps to no club.
     */
    public function applyPayoutEvent(?string $stripeAccountId, array $payout): bool {
        if (empty($stripeAccountId) || empty($payout['id'])) {
            return false;
        }

        $clubStmt = $this->pdo->prepare("SELECT club_id FROM club_payment_accounts WHERE stripe_account_id = ?");
        $clubStmt->execute([$stripeAccountId]);
        $clubId = $clubStmt->fetchColumn();
        if ($clubId === false) {
            return false;
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO stripe_payouts
                (stripe_payout_id, stripe_account_id, club_id, amount, currency, status, arrival_date, failure_message)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT (stripe_payout_id) DO UPDATE SET
                status = excluded.status,
                arrival_date = excluded.arrival_date,
                failure_message = excluded.failure_message,
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            $payout['id'],
            $stripeAccountId,
            (int) $clubId,
            number_format(((int) ($payout['amount'] ?? 0)) / 100, 2, '.', ''),
            $payout['currency'] ?? 'usd',
            $payout['status'] ?? 'paid',
            !empty($payout['arrival_date']) ? date('Y-m-d', (int) $payout['arrival_date']) : null,
            $payout['failure_message'] ?? null,
        ]);
        return true;
    }

    private function dateFilter(?string $from, ?string $to): array {
        $sql = '';
        $params = [];
        if ($from) {
            $sql .= " AND pt.created_at >= ?";
            $params[] = $from . ' 00:00:00';
        }
        if ($to) {
            $sql .= " AND pt.created_at <= ?";
            $params[] = $to . ' 23:59:59';
        }
        return [$sql, $params];
    }
}
