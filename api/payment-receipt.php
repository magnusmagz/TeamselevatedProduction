<?php
/**
 * Payment Receipt API
 * Get receipt details and send confirmation emails
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();


require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/AthleteScope.php';
require_once __DIR__ . '/../lib/Email.php';
require_once __DIR__ . '/../lib/feature_flags.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    $action = $_GET['action'] ?? 'get';

    switch ($action) {
        case 'get':
            $transactionId = $_GET['transaction_id'] ?? null;

            if (!$transactionId) {
                throw new Exception('transaction_id is required');
            }

            // Build query based on whether ID is numeric (database row ID) or string (maverick ID)
            if (is_numeric($transactionId)) {
                $whereClause = "pt.id = :transaction_id";
            } else {
                $whereClause = "pt.maverick_transaction_id = :transaction_id";
            }

            // Get transaction details with related info
            $stmt = $pdo->prepare("
                SELECT
                    pt.id,
                    ap.athlete_id,
                    pt.maverick_transaction_id,
                    pt.amount,
                    pt.payment_method,
                    COALESCE(SUBSTRING(pt.maverick_charge_id FROM '.{4}$'), '****') as last_four,
                    pt.status,
                    pt.created_at as transaction_date,
                    ap.base_amount,
                    ap.discount_amount,
                    ap.final_amount,
                    a.first_name as athlete_first,
                    a.last_name as athlete_last,
                    g.first_name as guardian_first,
                    g.last_name as guardian_last,
                    g.email as guardian_email,
                    pi.name as item_name,
                    p.name as program_name
                FROM payment_transactions pt
                JOIN athlete_payments ap ON pt.athlete_payment_id = ap.id
                JOIN athletes a ON ap.athlete_id = a.id
                -- A contact for the family. There is no primary guardian in this product
                -- (2026-09-02) — crew members are equal — so this is the FIRST crew member
                -- by link id rather than a ranked one. It used to join on
                -- `ag.is_primary = true`, which stops matching the moment nothing writes
                -- that column: every family added from then on would have had a blank
                -- billing contact on this query, silently. LATERAL, not a JOIN, so a
                -- two-parent household does not multiply the rows behind these totals.
                LEFT JOIN LATERAL (
                    SELECT gg.first_name, gg.last_name, gg.email, gg.mobile_phone
                    FROM athlete_guardians ag
                    JOIN guardians gg ON gg.id = ag.guardian_id
                    WHERE ag.athlete_id = a.id
                    ORDER BY ag.id
                    LIMIT 1
                ) g ON true
                LEFT JOIN payment_items pi ON ap.payment_item_id = pi.id
                LEFT JOIN programs p ON ap.program_id = p.id
                WHERE $whereClause
            ");
            $stmt->execute(['transaction_id' => $transactionId]);
            $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$transaction) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Transaction not found']);
                exit;
            }

            // A receipt names the guardian, the amounts and the payment method. Until
            // 2026-09-02 any transaction id fetched it with no token; the receipt page
            // has always sent a bearer, so this changes nothing for it. Same predicate
            // as action=email below.
            $auth = AuthMiddleware::requireAuth();
            if (!AthleteScope::userCanAccessAthlete($pdo, $auth, (int)$transaction['athlete_id'])) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'You do not have access to this receipt']);
                exit;
            }

            // Use database ID as transaction_id if maverick_transaction_id is null
            $displayTransactionId = $transaction['maverick_transaction_id'] ?: ('TXN-' . str_pad($transaction['id'], 8, '0', STR_PAD_LEFT));

            echo json_encode([
                'success' => true,
                'receipt' => [
                    'transaction_id' => $displayTransactionId,
                    'transaction_date' => $transaction['transaction_date'],
                    'athlete_name' => $transaction['athlete_first'] . ' ' . $transaction['athlete_last'],
                    'guardian_name' => ($transaction['guardian_first'] ?? 'Parent') . ' ' . ($transaction['guardian_last'] ?? ''),
                    'guardian_email' => $transaction['guardian_email'] ?? '',
                    'item_name' => $transaction['item_name'] ?? 'Registration Fee',
                    'program_name' => $transaction['program_name'] ?? '',
                    'base_amount' => floatval($transaction['base_amount']),
                    'discount_amount' => floatval($transaction['discount_amount']),
                    'amount_paid' => floatval($transaction['amount']),
                    'payment_method' => ucfirst($transaction['payment_method'] ?: 'Card'),
                    'last_four' => $transaction['last_four'] ?: '4242',
                    'status' => $transaction['status']
                ]
            ]);
            break;

        case 'email':
            // Send receipt email
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit;
            }

            // ⚠️ Authentication is on the SEND, not on the whole file.
            // Until 2026-09-02 this action only logged that it would have sent, so
            // an open endpoint cost nothing. It now puts real mail in
            // a guardian's inbox, and an unauthenticated caller who can guess
            // transaction ids would have an anonymous mail trigger. Scope is the
            // athlete READ predicate — club admin, the athlete's coach, or their
            // guardian — because "may I see this athlete's record" is exactly the
            // question "may I have their receipt re-sent" asks.
            try {
                $auth = AuthMiddleware::requireAuth();
            } catch (Exception $e) {
                http_response_code(401);
                echo json_encode(['success' => false, 'sent' => false, 'error' => 'Authentication required']);
                exit;
            }

            $data = json_decode(file_get_contents("php://input"), true);
            $transactionId = $data['transaction_id'] ?? null;

            if (!$transactionId) {
                throw new Exception('transaction_id is required');
            }

            // Build query based on ID type
            if (is_numeric($transactionId)) {
                $whereClause = "pt.id = :transaction_id";
            } else {
                $whereClause = "pt.maverick_transaction_id = :transaction_id";
            }

            // Get transaction and guardian email.
            // The club comes off the program / payment item / athlete row — never
            // off the request body, which the caller controls.
            $stmt = $pdo->prepare("
                SELECT
                    pt.id,
                    pt.maverick_transaction_id,
                    pt.amount,
                    pt.created_at,
                    ap.athlete_id,
                    g.email as guardian_email,
                    g.first_name as guardian_first,
                    a.first_name as athlete_first,
                    a.last_name as athlete_last,
                    pi.name as item_name,
                    p.name as program_name,
                    COALESCE(p.club_id, pi.club_id, a.club_id) as club_id,
                    cp.name as club_name
                FROM payment_transactions pt
                JOIN athlete_payments ap ON pt.athlete_payment_id = ap.id
                JOIN athletes a ON ap.athlete_id = a.id
                -- A contact for the family. There is no primary guardian in this product
                -- (2026-09-02) — crew members are equal — so this is the FIRST crew member
                -- by link id rather than a ranked one. It used to join on
                -- `ag.is_primary = true`, which stops matching the moment nothing writes
                -- that column: every family added from then on would have had a blank
                -- billing contact on this query, silently. LATERAL, not a JOIN, so a
                -- two-parent household does not multiply the rows behind these totals.
                LEFT JOIN LATERAL (
                    SELECT gg.first_name, gg.last_name, gg.email, gg.mobile_phone
                    FROM athlete_guardians ag
                    JOIN guardians gg ON gg.id = ag.guardian_id
                    WHERE ag.athlete_id = a.id
                    ORDER BY ag.id
                    LIMIT 1
                ) g ON true
                LEFT JOIN payment_items pi ON ap.payment_item_id = pi.id
                LEFT JOIN programs p ON ap.program_id = p.id
                LEFT JOIN club_profile cp ON cp.id = COALESCE(p.club_id, pi.club_id, a.club_id)
                WHERE $whereClause
            ");
            $stmt->execute(['transaction_id' => $transactionId]);
            $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$transaction) {
                throw new Exception('Transaction not found');
            }

            if (!AthleteScope::userCanAccessAthlete($pdo, $auth, (int)$transaction['athlete_id'])) {
                http_response_code(403);
                echo json_encode(['success' => false, 'sent' => false, 'error' => 'Not authorized for this athlete']);
                exit;
            }

            $guardianEmail = trim((string)($transaction['guardian_email'] ?? ''));
            $displayTransactionId = $transaction['maverick_transaction_id'] ?: ('TXN-' . str_pad($transaction['id'], 8, '0', STR_PAD_LEFT));

            // No placeholder address. The old code fell back to a made-up example.com
            // address, harmless while nothing sent and a misdirected receipt now.
            if ($guardianEmail === '') {
                echo json_encode([
                    'success' => false,
                    'sent' => false,
                    'error' => 'No email address on file for this athlete\'s primary guardian'
                ]);
                break;
            }

            $clubId = $transaction['club_id'] !== null ? (int)$transaction['club_id'] : null;
            $clubName = $transaction['club_name'] ?: 'Teams Elevated';
            $athleteName = trim($transaction['athlete_first'] . ' ' . $transaction['athlete_last']);

            // Kill switch: a bad template or a send storm is a config-var flip, not a
            // deploy. Off means we say so — never `sent: true` for mail that did not go.
            if (!te_feature_enabled('TRANSACTIONAL_EMAIL')) {
                echo json_encode(array_merge(
                    ['recipient' => $guardianEmail, 'transaction_id' => $displayTransactionId],
                    te_feature_disabled_response('TRANSACTIONAL_EMAIL')
                ));
                break;
            }

            $sent = (new Email())->forClub($pdo, $clubId)->sendPaymentTransactionReceipt(
                $guardianEmail,
                $transaction['guardian_first'] ?: 'there',
                $athleteName,
                $transaction['item_name'] ?: 'Registration Fee',
                $transaction['program_name'] ?: '',
                $transaction['amount'],
                $displayTransactionId,
                $transaction['created_at'],
                $clubName
            );

            if (!$sent) {
                // The provider refused it. Reporting success here is the bug this
                // whole slice exists to remove.
                http_response_code(502);
                echo json_encode([
                    'success' => false,
                    'sent' => false,
                    'recipient' => $guardianEmail,
                    'transaction_id' => $displayTransactionId,
                    'error' => 'Receipt could not be sent. Please try again shortly.'
                ]);
                break;
            }

            echo json_encode([
                'success' => true,
                'sent' => true,
                'recipient' => $guardianEmail,
                'transaction_id' => $displayTransactionId,
                'message' => 'Receipt sent to ' . $guardianEmail
            ]);
            break;

        case 'generate-for-payment':
            // Generate receipt for an athlete_payment after successful payment
            $athletePaymentId = $_GET['athlete_payment_id'] ?? null;

            if (!$athletePaymentId) {
                throw new Exception('athlete_payment_id is required');
            }

            // Get the most recent transaction for this payment
            $stmt = $pdo->prepare("
                SELECT id, maverick_transaction_id
                FROM payment_transactions
                WHERE athlete_payment_id = :id AND status = 'succeeded'
                ORDER BY created_at DESC
                LIMIT 1
            ");
            $stmt->execute(['id' => $athletePaymentId]);
            $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$transaction) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'No completed transaction found']);
                exit;
            }

            echo json_encode([
                'success' => true,
                'transaction_id' => $transaction['id'] // Return database ID
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage()
    ]);
}
