<?php
/**
 * Athlete Payments API
 * Get payment status for an athlete
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();


require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/financial_scope.php';

try {
    $auth = AuthMiddleware::requireAuth();
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    $athlete_id = $_GET['athlete_id'] ?? null;
    $payment_id = $_GET['payment_id'] ?? null;

    // Scope: caller must be able to access the payment's / athlete's club.
    te_assert_financial_scope($auth, $pdo, ['payment' => $payment_id, 'athlete' => $athlete_id]);

    // If payment_id is provided, return just that payment
    if ($payment_id) {
        $stmt = $pdo->prepare("
            SELECT
                ap.*,
                a.first_name || ' ' || a.last_name as athlete_name,
                pi.name as item_name,
                pi.item_type,
                p.name as program_name
            FROM athlete_payments ap
            JOIN athletes a ON ap.athlete_id = a.id
            JOIN payment_items pi ON ap.payment_item_id = pi.id
            JOIN programs p ON ap.program_id = p.id
            WHERE ap.id = :payment_id
        ");
        $stmt->execute(['payment_id' => $payment_id]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$payment) {
            echo json_encode(['success' => false, 'error' => 'Payment not found']);
            exit;
        }

        echo json_encode([
            'success' => true,
            'payment' => $payment
        ]);
        exit;
    }

    if (!$athlete_id) {
        echo json_encode(['error' => 'athlete_id or payment_id is required']);
        exit;
    }

    // Get athlete info
    $athlete_query = "
        SELECT
            id,
            first_name,
            last_name,
            first_name || ' ' || last_name as name
        FROM athletes
        WHERE id = :athlete_id
    ";
    $stmt = $pdo->prepare($athlete_query);
    $stmt->execute(['athlete_id' => $athlete_id]);
    $athlete = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$athlete) {
        echo json_encode(['error' => 'Athlete not found']);
        exit;
    }

    // Get payment summary
    $summary_query = "
        SELECT
            COALESCE(SUM(final_amount), 0) as total_owed,
            COALESCE(SUM(amount_paid), 0) as total_paid,
            COALESCE(SUM(amount_remaining), 0) as total_remaining
        FROM athlete_payments
        WHERE athlete_id = :athlete_id
    ";
    $stmt = $pdo->prepare($summary_query);
    $stmt->execute(['athlete_id' => $athlete_id]);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get individual payments
    $payments_query = "
        SELECT
            ap.*,
            pi.name as item_name,
            pi.item_type,
            p.name as program_name,
            pp.name as payment_plan_name,
            pp.total_installments
        FROM athlete_payments ap
        JOIN payment_items pi ON ap.payment_item_id = pi.id
        JOIN programs p ON ap.program_id = p.id
        LEFT JOIN payment_plans pp ON ap.payment_plan_id = pp.id
        WHERE ap.athlete_id = :athlete_id
        ORDER BY p.name, pi.name
    ";
    $stmt = $pdo->prepare($payments_query);
    $stmt->execute(['athlete_id' => $athlete_id]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format payments with additional info
    foreach ($payments as &$payment) {
        $payment['on_payment_plan'] = !empty($payment['payment_plan_id']);
        $payment['is_overdue'] = $payment['status'] === 'pending' &&
                                 $payment['due_date'] &&
                                 strtotime($payment['due_date']) < time();
    }

    echo json_encode([
        'success' => true,
        'athlete' => array_merge($athlete, $summary),
        'payments' => $payments
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to fetch athlete payments',
        'message' => $e->getMessage()
    ]);
}
