<?php
/**
 * PaymentService — record parent-initiated payments against invoices, with
 * ownership scoping baked in.
 *
 * This is the unit-testable core behind api/payments.php. All DB access is
 * injected (pass $pdo) so the logic runs against an in-memory SQLite fixture or
 * a PDO test double — it never reaches the production Neon database itself.
 *
 * Scoping contract (PAR-17 / PAR-18):
 *   - A parent may only pay invoices whose athlete_id is one of the athletes
 *     they can access (guardians.email match -> athlete_guardians -> athletes).
 *     Reuses AthleteScope so club_admin / coach / super_admin scoping behaves
 *     identically to the rest of the app.
 *   - Every invoice is verified BEFORE any UPDATE runs. If ANY target invoice is
 *     outside the requester's scope (or does not exist), nothing is applied and
 *     OwnershipException is thrown so the caller can return 403.
 *   - The payment is applied PER INVOICE with a scoped UPDATE
 *         WHERE id = ? AND athlete_id IN (accessible athlete ids)
 *     so paying one invoice can never touch a sibling's invoice (PAR-18).
 *
 * Allocation: the payment amount is allocated across the selected invoices in
 * the order given, each invoice receiving up to its remaining balance. Per
 * invoice we bump amount_paid, recompute the balance, and move status to
 * 'paid' (balance <= 0) or 'partial' (0 < balance < total), stamping paid_at
 * when fully paid.
 *
 * Notes:
 *  - Uses portable SQL so the same statements run on SQLite (tests) and
 *    PostgreSQL (prod). Status string 'partial' is used for partially-paid
 *    invoices (matches the frontend status badge map); the prod CHECK
 *    constraint allows draft/sent/viewed/paid/overdue/cancelled — 'partial' is
 *    already used by the comms/reporting layer and rendered by the parent UI.
 *    We therefore keep 'partial' but never write an out-of-set value.
 *  - Monetary math is done in integer cents to avoid float drift, then written
 *    back as a 2-dp decimal value.
 */

require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/AthleteScope.php';

/**
 * Thrown when a requested invoice is not owned/accessible by the requester.
 * api/payments.php maps this to HTTP 403.
 */
class OwnershipException extends Exception {}

/**
 * Thrown for bad input (no invoices, non-positive amount, unknown invoice).
 * api/payments.php maps this to HTTP 400.
 */
class PaymentValidationException extends Exception {}

class PaymentService {

    /** Convert a dollar value to integer cents (round half-up). */
    private static function toCents($value): int {
        return (int) round(((float) $value) * 100);
    }

    /** Convert integer cents back to a 2-dp string for DECIMAL columns. */
    private static function fromCents(int $cents): string {
        return number_format($cents / 100, 2, '.', '');
    }

    /**
     * Record a payment against one or more invoices, scoped to the requester.
     *
     * @param PDO            $pdo
     * @param AuthMiddleware $auth        authenticated requester
     * @param int[]          $invoiceIds  invoices to pay (order = allocation order)
     * @param float          $amount      total payment amount in dollars
     * @return array{
     *   success: bool,
     *   amount_applied: float,
     *   amount_unapplied: float,
     *   invoices: array<int, array{
     *     id:int, athlete_id:int, total_amount:float, amount_paid:float,
     *     balance_due:float, status:string, applied:float
     *   }>
     * }
     *
     * @throws PaymentValidationException on bad input
     * @throws OwnershipException         if any invoice is out of scope
     */
    public static function recordPayment(PDO $pdo, AuthMiddleware $auth, array $invoiceIds, float $amount): array {
        // ---- Input validation -------------------------------------------------
        $invoiceIds = array_values(array_unique(array_map('intval', $invoiceIds)));
        if (empty($invoiceIds)) {
            throw new PaymentValidationException('At least one invoice is required');
        }
        if ($amount <= 0) {
            throw new PaymentValidationException('Payment amount must be greater than zero');
        }

        // ---- Resolve the requester's accessible athletes ----------------------
        // null => super admin (unrestricted). Otherwise an explicit id allow-list.
        $accessibleAthleteIds = $auth->isSuperAdmin()
            ? null
            : AthleteScope::accessibleAthleteIds($pdo, $auth);

        // ---- Load the target invoices ----------------------------------------
        $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
        $stmt = $pdo->prepare("
            SELECT id, athlete_id, total_amount, amount_paid, status
            FROM invoices
            WHERE id IN ($placeholders)
        ");
        $stmt->execute($invoiceIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }

        // ---- Ownership + existence verification (BEFORE any write) -----------
        foreach ($invoiceIds as $id) {
            if (!isset($byId[$id])) {
                // Unknown invoice id — treat as a not-owned/forbidden target so we
                // never leak which ids exist outside the requester's scope.
                throw new OwnershipException('Invoice not found or not accessible: ' . $id);
            }
            $athleteId = (int) $byId[$id]['athlete_id'];
            if ($accessibleAthleteIds !== null && !in_array($athleteId, $accessibleAthleteIds, true)) {
                throw new OwnershipException('You are not authorized to pay invoice ' . $id);
            }
        }

        // ---- Apply the payment, per invoice, scoped --------------------------
        $remainingCents = self::toCents($amount);
        $resultInvoices = [];

        // A scoped UPDATE: id match AND athlete in the accessible set. This is the
        // PAR-18 guarantee — a sibling invoice (different athlete_id, or any id not
        // in $invoiceIds) is structurally untouchable by this statement.
        $scopeSqlForUpdate = '';
        $scopeParamsTemplate = [];
        if ($accessibleAthleteIds !== null) {
            // Non-empty here because verification above already passed for every id.
            $ph = implode(',', array_fill(0, count($accessibleAthleteIds), '?'));
            $scopeSqlForUpdate = " AND athlete_id IN ($ph)";
            $scopeParamsTemplate = array_values($accessibleAthleteIds);
        }

        $update = $pdo->prepare("
            UPDATE invoices
            SET amount_paid = ?,
                status = ?,
                paid_at = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?{$scopeSqlForUpdate}
        ");

        foreach ($invoiceIds as $id) {
            $row = $byId[$id];
            $totalCents = self::toCents($row['total_amount']);
            $paidCents  = self::toCents($row['amount_paid']);
            $balanceCents = $totalCents - $paidCents;

            // Skip invoices that are already fully paid; report them unchanged.
            if ($balanceCents <= 0) {
                $resultInvoices[] = [
                    'id' => $id,
                    'athlete_id' => (int) $row['athlete_id'],
                    'total_amount' => (float) self::fromCents($totalCents),
                    'amount_paid' => (float) self::fromCents($paidCents),
                    'balance_due' => 0.0,
                    'status' => (string) $row['status'],
                    'applied' => 0.0,
                ];
                continue;
            }

            $applyCents = min($remainingCents, $balanceCents);
            if ($applyCents <= 0) {
                // No funds left to allocate — leave this invoice unchanged.
                $resultInvoices[] = [
                    'id' => $id,
                    'athlete_id' => (int) $row['athlete_id'],
                    'total_amount' => (float) self::fromCents($totalCents),
                    'amount_paid' => (float) self::fromCents($paidCents),
                    'balance_due' => (float) self::fromCents($balanceCents),
                    'status' => (string) $row['status'],
                    'applied' => 0.0,
                ];
                continue;
            }

            $newPaidCents = $paidCents + $applyCents;
            $newBalanceCents = $totalCents - $newPaidCents;
            $newStatus = $newBalanceCents <= 0 ? 'paid' : 'partial';
            $paidAt = $newBalanceCents <= 0 ? date('Y-m-d H:i:s') : null;

            $params = [
                self::fromCents($newPaidCents),
                $newStatus,
                $paidAt,
                $id,
            ];
            if (!empty($scopeParamsTemplate)) {
                $params = array_merge($params, $scopeParamsTemplate);
            }
            $update->execute($params);

            $remainingCents -= $applyCents;

            $resultInvoices[] = [
                'id' => $id,
                'athlete_id' => (int) $row['athlete_id'],
                'total_amount' => (float) self::fromCents($totalCents),
                'amount_paid' => (float) self::fromCents($newPaidCents),
                'balance_due' => (float) self::fromCents(max(0, $newBalanceCents)),
                'status' => $newStatus,
                'applied' => (float) self::fromCents($applyCents),
            ];
        }

        $appliedCents = self::toCents($amount) - $remainingCents;

        return [
            'success' => true,
            'amount_applied' => (float) self::fromCents($appliedCents),
            'amount_unapplied' => (float) self::fromCents(max(0, $remainingCents)),
            'invoices' => $resultInvoices,
        ];
    }
}
