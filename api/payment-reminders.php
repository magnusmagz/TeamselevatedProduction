<?php
/**
 * Payment Reminders API
 * Send automated reminders for upcoming and overdue payments
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();


require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/financial_scope.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../lib/Email.php';
require_once __DIR__ . '/../lib/feature_flags.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    // Financial/admin endpoint — authentication required for all actions.
    $auth = AuthMiddleware::requireAuth();

    $action = $_GET['action'] ?? 'list';

    switch ($action) {
        case 'list':
            // Get payments needing reminders
            $league_id = $_GET['league_id'] ?? null;
            $type = $_GET['type'] ?? 'all'; // upcoming, overdue, all

            if (!$league_id) {
                throw new Exception('league_id is required');
            }

            // Admin-only, scoped to the caller's club.
            te_assert_financial_admin($auth, $pdo, ['league' => $league_id]);

            $whereClauses = [
                'p.club_id = :league_id',
                "ap.status IN ('pending', 'partial')",
                'ap.amount_remaining > 0'
            ];
            $params = ['league_id' => $league_id];

            if ($type === 'upcoming') {
                // Due in next 7 days
                $whereClauses[] = "ap.due_date IS NOT NULL";
                $whereClauses[] = "ap.due_date > CURRENT_DATE";
                $whereClauses[] = "ap.due_date <= CURRENT_DATE + INTERVAL '7 days'";
            } elseif ($type === 'overdue') {
                // Past due
                $whereClauses[] = "ap.due_date IS NOT NULL";
                $whereClauses[] = "ap.due_date < CURRENT_DATE";
            }

            $whereClause = implode(' AND ', $whereClauses);

            $query = "
                SELECT
                    ap.id as payment_id,
                    ap.athlete_id,
                    ap.due_date,
                    ap.final_amount,
                    ap.amount_paid,
                    ap.amount_remaining,
                    CASE
                        WHEN ap.due_date < CURRENT_DATE THEN CURRENT_DATE - ap.due_date::date
                        ELSE 0
                    END as days_overdue,
                    CASE
                        WHEN ap.due_date >= CURRENT_DATE THEN ap.due_date::date - CURRENT_DATE
                        ELSE 0
                    END as days_until_due,
                    a.first_name as athlete_first,
                    a.last_name as athlete_last,
                    g.first_name as guardian_first,
                    g.last_name as guardian_last,
                    g.email as guardian_email,
                    g.mobile_phone as guardian_phone,
                    pi.name as item_name,
                    p.name as program_name,
                    (
                        SELECT MAX(sent_at)
                        FROM payment_reminder_log
                        WHERE athlete_payment_id = ap.id
                    ) as last_reminder_sent
                FROM athlete_payments ap
                JOIN athletes a ON ap.athlete_id = a.id
                JOIN programs p ON ap.program_id = p.id
                LEFT JOIN athlete_guardians ag ON a.id = ag.athlete_id AND ag.is_primary = true
                LEFT JOIN guardians g ON ag.guardian_id = g.id
                LEFT JOIN payment_items pi ON ap.payment_item_id = pi.id
                WHERE $whereClause
                ORDER BY ap.due_date ASC NULLS LAST
            ";

            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'count' => count($payments),
                'payments' => $payments
            ]);
            break;

        case 'send':
            // Send a reminder for a specific payment
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit;
            }

            $data = json_decode(file_get_contents("php://input"), true);
            $paymentId = $data['payment_id'] ?? null;
            $reminderType = $data['type'] ?? 'manual'; // manual, scheduled, auto

            if (!$paymentId) {
                throw new Exception('payment_id is required');
            }

            // Admin-only, scoped to the payment's club.
            te_assert_financial_admin($auth, $pdo, ['payment' => $paymentId]);

            // Get payment details. Club comes off the program row, not the body.
            $stmt = $pdo->prepare("
                SELECT
                    ap.*,
                    a.first_name as athlete_first,
                    a.last_name as athlete_last,
                    g.email as guardian_email,
                    g.first_name as guardian_first,
                    pi.name as item_name,
                    p.name as program_name,
                    p.club_id as club_id,
                    cp.name as club_name
                FROM athlete_payments ap
                JOIN athletes a ON ap.athlete_id = a.id
                JOIN programs p ON ap.program_id = p.id
                LEFT JOIN athlete_guardians ag ON a.id = ag.athlete_id AND ag.is_primary = true
                LEFT JOIN guardians g ON ag.guardian_id = g.id
                LEFT JOIN payment_items pi ON ap.payment_item_id = pi.id
                LEFT JOIN club_profile cp ON cp.id = p.club_id
                WHERE ap.id = :payment_id
            ");
            $stmt->execute(['payment_id' => $paymentId]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$payment) {
                throw new Exception('Payment not found');
            }

            $guardianEmail = $payment['guardian_email'];
            if (!$guardianEmail) {
                throw new Exception('No email address on file');
            }

            $isOverdue = $payment['due_date'] && strtotime($payment['due_date']) < time();
            $athleteName = trim($payment['athlete_first'] . ' ' . $payment['athlete_last']);
            $clubId = $payment['club_id'] !== null ? (int)$payment['club_id'] : null;
            $clubName = $payment['club_name'] ?: 'Teams Elevated';
            $paymentLink = rtrim(Env::get('APP_URL', 'http://localhost:3003'), '/') . '/parent/payments';

            // No money in the subject — a reminder subject renders on a lock screen.
            $subject = $isOverdue
                ? "Payment overdue for {$payment['athlete_first']}"
                : "Payment reminder for {$payment['athlete_first']}";

            // Kill switch. Nothing is sent and, critically, nothing is logged:
            // payment_reminder_log is the record that a family was contacted.
            if (!te_feature_enabled('TRANSACTIONAL_EMAIL')) {
                echo json_encode(array_merge(
                    ['recipient' => $guardianEmail, 'logged' => false],
                    te_feature_disabled_response('TRANSACTIONAL_EMAIL')
                ));
                break;
            }

            $sent = (new Email())->forClub($pdo, $clubId)->sendPaymentReminder(
                $guardianEmail,
                $payment['guardian_first'] ?: 'there',
                $athleteName,
                $payment['item_name'] ?: 'Registration Fee',
                $payment['program_name'] ?: '',
                $payment['amount_remaining'],
                $payment['due_date'],
                $isOverdue,
                $clubName,
                $paymentLink
            );

            if (!$sent) {
                // ⚠️ No log row on a failed send. The old handler INSERTed first and
                // then logged "would send", so payment_reminder_log recorded contact
                // that never happened — and `list` reads MAX(sent_at) from it to
                // decide who still needs chasing.
                http_response_code(502);
                echo json_encode([
                    'success' => false,
                    'sent' => false,
                    'logged' => false,
                    'recipient' => $guardianEmail,
                    'error' => 'Reminder could not be sent. Nothing was recorded against this payment.'
                ]);
                break;
            }

            // Log the reminder — only now that it actually left.
            $stmt = $pdo->prepare("
                INSERT INTO payment_reminder_log
                (athlete_payment_id, reminder_type, sent_to, subject, message, sent_at)
                VALUES (:payment_id, :type, :email, :subject, :message, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([
                'payment_id' => $paymentId,
                'type' => $reminderType,
                'email' => $guardianEmail,
                'subject' => $subject,
                'message' => 'Payment ' . ($isOverdue ? 'overdue' : 'reminder')
                    . " notice for $athleteName ({$payment['item_name']})"
            ]);

            echo json_encode([
                'success' => true,
                'sent' => true,
                'logged' => true,
                'recipient' => $guardianEmail,
                'message' => "Reminder sent to $guardianEmail"
            ]);
            break;

        case 'send-batch':
            // Send reminders to multiple payments (for scheduled jobs)
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit;
            }

            $data = json_decode(file_get_contents("php://input"), true);
            $leagueId = $data['league_id'] ?? null;
            $batchType = $data['type'] ?? 'overdue'; // overdue, upcoming

            if (!$leagueId) {
                throw new Exception('league_id is required');
            }

            // Admin-only, scoped to the caller's club.
            te_assert_financial_admin($auth, $pdo, ['league' => $leagueId]);

            // Get payments needing reminders (not sent in last 3 days)
            $whereType = $batchType === 'overdue'
                ? "ap.due_date < CURRENT_DATE"
                : "ap.due_date > CURRENT_DATE AND ap.due_date <= CURRENT_DATE + INTERVAL '3 days'";

            $stmt = $pdo->prepare("
                SELECT ap.id
                FROM athlete_payments ap
                JOIN programs p ON ap.program_id = p.id
                WHERE p.club_id = :league_id
                AND ap.status IN ('pending', 'partial')
                AND ap.amount_remaining > 0
                AND ap.due_date IS NOT NULL
                AND $whereType
                -- The OR group MUST be parenthesised: AND binds tighter than OR, so
                -- without the outer parens the second branch carried no club, status,
                -- amount or due-date filter. Latent while payment_reminder_log was
                -- empty; it activates the SECOND time anyone sends batch reminders.
                AND (
                    (
                        SELECT MAX(sent_at)
                        FROM payment_reminder_log
                        WHERE athlete_payment_id = ap.id
                    ) IS NULL
                    OR (
                        SELECT MAX(sent_at)
                        FROM payment_reminder_log
                        WHERE athlete_payment_id = ap.id
                    ) < CURRENT_TIMESTAMP - INTERVAL '3 days'
                )
            ");
            $stmt->execute(['league_id' => $leagueId]);
            $paymentIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $sentCount = 0;
            $errors = [];

            $paymentLink = rtrim(Env::get('APP_URL', 'http://localhost:3003'), '/') . '/parent/payments';

            // Kill switch, checked once before the loop. Nothing sends and nothing
            // is written to payment_reminder_log.
            if (!te_feature_enabled('TRANSACTIONAL_EMAIL')) {
                echo json_encode(array_merge(
                    ['sent_count' => 0, 'failed_count' => 0, 'total_eligible' => count($paymentIds), 'errors' => []],
                    te_feature_disabled_response('TRANSACTIONAL_EMAIL')
                ));
                break;
            }

            foreach ($paymentIds as $paymentId) {
                try {
                    // Full details per payment — a reminder that cannot name the
                    // athlete or the amount is not worth sending. Club comes off
                    // the program row, same as the single-send path.
                    $stmt = $pdo->prepare("
                        SELECT
                            ap.id,
                            ap.due_date,
                            ap.amount_remaining,
                            a.first_name as athlete_first,
                            a.last_name as athlete_last,
                            g.email as guardian_email,
                            g.first_name as guardian_first,
                            pi.name as item_name,
                            p.name as program_name,
                            p.club_id as club_id,
                            cp.name as club_name
                        FROM athlete_payments ap
                        JOIN athletes a ON ap.athlete_id = a.id
                        JOIN programs p ON ap.program_id = p.id
                        LEFT JOIN athlete_guardians ag ON a.id = ag.athlete_id AND ag.is_primary = true
                        LEFT JOIN guardians g ON ag.guardian_id = g.id
                        LEFT JOIN payment_items pi ON ap.payment_item_id = pi.id
                        LEFT JOIN club_profile cp ON cp.id = p.club_id
                        WHERE ap.id = :payment_id
                    ");
                    $stmt->execute(['payment_id' => $paymentId]);
                    $payment = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$payment || !$payment['guardian_email']) {
                        $errors[] = "Payment $paymentId: no email address on file";
                        continue;
                    }

                    $isOverdue = $payment['due_date'] && strtotime($payment['due_date']) < time();
                    $athleteName = trim($payment['athlete_first'] . ' ' . $payment['athlete_last']);
                    $clubName = $payment['club_name'] ?: 'Teams Elevated';

                    $sent = (new Email())
                        ->forClub($pdo, $payment['club_id'] !== null ? (int)$payment['club_id'] : null)
                        ->sendPaymentReminder(
                            $payment['guardian_email'],
                            $payment['guardian_first'] ?: 'there',
                            $athleteName,
                            $payment['item_name'] ?: 'Registration Fee',
                            $payment['program_name'] ?: '',
                            $payment['amount_remaining'],
                            $payment['due_date'],
                            $isOverdue,
                            $clubName,
                            $paymentLink
                        );

                    if (!$sent) {
                        // No log row. See the single-send path: a row here is a
                        // record that the family was contacted.
                        $errors[] = "Payment $paymentId: provider rejected the message";
                        continue;
                    }

                    $stmt = $pdo->prepare("
                        INSERT INTO payment_reminder_log
                        (athlete_payment_id, reminder_type, sent_to, subject, message, sent_at)
                        VALUES (:payment_id, 'scheduled', :email, :subject, :message, CURRENT_TIMESTAMP)
                    ");
                    $stmt->execute([
                        'payment_id' => $paymentId,
                        'email' => $payment['guardian_email'],
                        'subject' => ($isOverdue ? 'Payment overdue for ' : 'Payment reminder for ')
                            . $payment['athlete_first'],
                        'message' => 'Scheduled payment ' . ($isOverdue ? 'overdue' : 'reminder')
                            . " notice for $athleteName"
                    ]);
                    $sentCount++;
                } catch (Exception $e) {
                    $errors[] = "Payment $paymentId: " . $e->getMessage();
                }
            }

            // A batch that mailed nobody must not answer success.
            $response = [
                'success' => count($errors) === 0,
                'sent_count' => $sentCount,
                'failed_count' => count($errors),
                'total_eligible' => count($paymentIds),
                'errors' => $errors
            ];
            if ($errors) {
                $response['error'] = count($errors) . ' of ' . count($paymentIds) . ' reminders could not be sent';
            }
            echo json_encode($response);
            break;

        case 'history':
            // Get reminder history for a payment
            $paymentId = $_GET['payment_id'] ?? null;

            if (!$paymentId) {
                throw new Exception('payment_id is required');
            }

            // Admin-only, scoped to the payment's club.
            te_assert_financial_admin($auth, $pdo, ['payment' => $paymentId]);

            $stmt = $pdo->prepare("
                SELECT *
                FROM payment_reminder_log
                WHERE athlete_payment_id = :payment_id
                ORDER BY sent_at DESC
            ");
            $stmt->execute(['payment_id' => $paymentId]);
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'history' => $history
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
