<?php
/**
 * Payment Reminders API
 * Send automated reminders for upcoming and overdue payments
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/financial_scope.php';

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
                'p.league_id = :league_id',
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
                        WHEN ap.due_date < CURRENT_DATE THEN CURRENT_DATE - ap.due_date
                        ELSE 0
                    END as days_overdue,
                    CASE
                        WHEN ap.due_date >= CURRENT_DATE THEN ap.due_date - CURRENT_DATE
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

            // Get payment details
            $stmt = $pdo->prepare("
                SELECT
                    ap.*,
                    a.first_name as athlete_first,
                    a.last_name as athlete_last,
                    g.email as guardian_email,
                    g.first_name as guardian_first,
                    pi.name as item_name,
                    p.name as program_name
                FROM athlete_payments ap
                JOIN athletes a ON ap.athlete_id = a.id
                JOIN programs p ON ap.program_id = p.id
                LEFT JOIN athlete_guardians ag ON a.id = ag.athlete_id AND ag.is_primary = true
                LEFT JOIN guardians g ON ag.guardian_id = g.id
                LEFT JOIN payment_items pi ON ap.payment_item_id = pi.id
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

            // Build reminder message
            $isOverdue = $payment['due_date'] && strtotime($payment['due_date']) < time();
            $subject = $isOverdue
                ? "Payment Overdue: {$payment['item_name']} for {$payment['athlete_first']}"
                : "Payment Reminder: {$payment['item_name']} for {$payment['athlete_first']}";

            $message = "
Dear {$payment['guardian_first']},

This is a " . ($isOverdue ? "reminder that your payment is overdue" : "friendly reminder about an upcoming payment") . ".

Payment Details:
- Athlete: {$payment['athlete_first']} {$payment['athlete_last']}
- Program: {$payment['program_name']}
- Item: {$payment['item_name']}
- Amount Due: $" . number_format($payment['amount_remaining'], 2) . "
" . ($payment['due_date'] ? "- Due Date: " . date('F j, Y', strtotime($payment['due_date'])) : "") . "

To make a payment, please log in to your account or click here:
[Payment Link]

If you have any questions, please contact us.

Thank you,
Teams Elevated
            ";

            // Log the reminder
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
                'message' => $message
            ]);

            // In demo mode, just log (in production, would send email)
            error_log("DEMO: Would send reminder email to $guardianEmail");
            error_log("Subject: $subject");

            echo json_encode([
                'success' => true,
                'message' => "Reminder sent to $guardianEmail",
                'demo_mode' => true
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
                WHERE p.league_id = :league_id
                AND ap.status IN ('pending', 'partial')
                AND ap.amount_remaining > 0
                AND ap.due_date IS NOT NULL
                AND $whereType
                AND (
                    SELECT MAX(sent_at)
                    FROM payment_reminder_log
                    WHERE athlete_payment_id = ap.id
                ) IS NULL OR (
                    SELECT MAX(sent_at)
                    FROM payment_reminder_log
                    WHERE athlete_payment_id = ap.id
                ) < CURRENT_TIMESTAMP - INTERVAL '3 days'
            ");
            $stmt->execute(['league_id' => $leagueId]);
            $paymentIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $sentCount = 0;
            $errors = [];

            foreach ($paymentIds as $paymentId) {
                try {
                    // Reuse single send logic (simplified for batch)
                    $stmt = $pdo->prepare("
                        SELECT ap.id, g.email
                        FROM athlete_payments ap
                        JOIN athletes a ON ap.athlete_id = a.id
                        LEFT JOIN athlete_guardians ag ON a.id = ag.athlete_id AND ag.is_primary = true
                        LEFT JOIN guardians g ON ag.guardian_id = g.id
                        WHERE ap.id = :payment_id
                    ");
                    $stmt->execute(['payment_id' => $paymentId]);
                    $payment = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($payment && $payment['email']) {
                        $stmt = $pdo->prepare("
                            INSERT INTO payment_reminder_log
                            (athlete_payment_id, reminder_type, sent_to, subject, message, sent_at)
                            VALUES (:payment_id, 'scheduled', :email, 'Payment Reminder', 'Batch reminder', CURRENT_TIMESTAMP)
                        ");
                        $stmt->execute([
                            'payment_id' => $paymentId,
                            'email' => $payment['email']
                        ]);
                        $sentCount++;
                    }
                } catch (Exception $e) {
                    $errors[] = "Payment $paymentId: " . $e->getMessage();
                }
            }

            echo json_encode([
                'success' => true,
                'sent_count' => $sentCount,
                'total_eligible' => count($paymentIds),
                'errors' => $errors,
                'demo_mode' => true
            ]);
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
