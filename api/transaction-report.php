<?php
/**
 * Transaction Report API
 * Generate transaction reports with filtering and export capabilities
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

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

    // Filter parameters
    $league_id = $_GET['league_id'] ?? null;
    $program_id = $_GET['program_id'] ?? null;
    $status = $_GET['status'] ?? null;
    $date_from = $_GET['date_from'] ?? null;
    $date_to = $_GET['date_to'] ?? null;
    $payment_type = $_GET['payment_type'] ?? null;
    $export_format = $_GET['export'] ?? null;

    // Scope: caller must be able to access the requested league/program.
    te_assert_financial_admin($auth, $pdo, ['league' => $league_id, 'program' => $program_id]);

    if (!$league_id) {
        throw new Exception('league_id is required');
    }

    // Build query with filters
    $whereClauses = ['p.club_id = :league_id'];
    $params = ['league_id' => $league_id];

    if ($program_id) {
        $whereClauses[] = 'ap.program_id = :program_id';
        $params['program_id'] = $program_id;
    }

    if ($status) {
        $whereClauses[] = 'pt.status = :status';
        $params['status'] = $status;
    }

    if ($date_from) {
        $whereClauses[] = 'DATE(pt.created_at) >= :date_from';
        $params['date_from'] = $date_from;
    }

    if ($date_to) {
        $whereClauses[] = 'DATE(pt.created_at) <= :date_to';
        $params['date_to'] = $date_to;
    }

    if ($payment_type) {
        $whereClauses[] = 'pt.payment_type = :payment_type';
        $params['payment_type'] = $payment_type;
    }

    $whereClause = implode(' AND ', $whereClauses);

    // Main transaction query
    $query = "
        SELECT
            pt.id,
            pt.maverick_transaction_id,
            pt.amount,
            pt.payment_method,
            pt.status,
            pt.payment_type,
            pt.installment_number,
            pt.refund_amount,
            pt.refund_reason,
            pt.refunded_at,
            pt.created_at,
            a.id as athlete_id,
            a.first_name as athlete_first,
            a.last_name as athlete_last,
            g.first_name as guardian_first,
            g.last_name as guardian_last,
            g.email as guardian_email,
            pi.name as item_name,
            pi.item_type,
            pi.accounting_code,
            p.name as program_name
        FROM payment_transactions pt
        JOIN athlete_payments ap ON pt.athlete_payment_id = ap.id
        JOIN athletes a ON ap.athlete_id = a.id
        LEFT JOIN athlete_guardians ag ON a.id = ag.athlete_id AND ag.is_primary = true
        LEFT JOIN guardians g ON ag.guardian_id = g.id
        LEFT JOIN payment_items pi ON ap.payment_item_id = pi.id
        LEFT JOIN programs p ON ap.program_id = p.id
        WHERE $whereClause
        ORDER BY pt.created_at DESC
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate summary
    $summary = [
        'total_transactions' => count($transactions),
        'total_amount' => 0,
        'total_refunds' => 0,
        'net_amount' => 0,
        'by_status' => [],
        'by_payment_type' => [],
        'by_method' => []
    ];

    foreach ($transactions as $txn) {
        $amount = floatval($txn['amount']);
        $refund = floatval($txn['refund_amount'] ?? 0);

        if ($txn['status'] === 'succeeded') {
            $summary['total_amount'] += $amount;
        }
        $summary['total_refunds'] += $refund;

        // By status
        $status = $txn['status'];
        if (!isset($summary['by_status'][$status])) {
            $summary['by_status'][$status] = ['count' => 0, 'amount' => 0];
        }
        $summary['by_status'][$status]['count']++;
        $summary['by_status'][$status]['amount'] += $amount;

        // By payment type
        $type = $txn['payment_type'] ?? 'full';
        if (!isset($summary['by_payment_type'][$type])) {
            $summary['by_payment_type'][$type] = ['count' => 0, 'amount' => 0];
        }
        $summary['by_payment_type'][$type]['count']++;
        $summary['by_payment_type'][$type]['amount'] += $amount;

        // By method
        $method = $txn['payment_method'] ?? 'unknown';
        if (!isset($summary['by_method'][$method])) {
            $summary['by_method'][$method] = ['count' => 0, 'amount' => 0];
        }
        $summary['by_method'][$method]['count']++;
        $summary['by_method'][$method]['amount'] += $amount;
    }

    $summary['net_amount'] = $summary['total_amount'] - $summary['total_refunds'];

    // Handle export
    if ($export_format === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="transactions-' . date('Y-m-d') . '.csv"');

        $output = fopen('php://output', 'w');

        // Headers
        fputcsv($output, [
            'Transaction ID',
            'Date',
            'Athlete',
            'Guardian',
            'Email',
            'Program',
            'Item',
            'Accounting Code',
            'Type',
            'Amount',
            'Status',
            'Payment Method',
            'Refund Amount',
            'Refund Reason'
        ]);

        // Data rows
        foreach ($transactions as $txn) {
            $displayId = $txn['maverick_transaction_id'] ?: ('TXN-' . str_pad($txn['id'], 8, '0', STR_PAD_LEFT));
            fputcsv($output, [
                $displayId,
                $txn['created_at'],
                $txn['athlete_first'] . ' ' . $txn['athlete_last'],
                ($txn['guardian_first'] ?? '') . ' ' . ($txn['guardian_last'] ?? ''),
                $txn['guardian_email'] ?? '',
                $txn['program_name'] ?? '',
                $txn['item_name'] ?? '',
                $txn['accounting_code'] ?? '',
                $txn['payment_type'] ?? 'full',
                number_format($txn['amount'], 2),
                $txn['status'],
                $txn['payment_method'] ?? '',
                $txn['refund_amount'] ? number_format($txn['refund_amount'], 2) : '',
                $txn['refund_reason'] ?? ''
            ]);
        }

        fclose($output);
        exit;
    }

    // Format transactions for JSON response
    $formattedTransactions = array_map(function($txn) {
        return [
            'id' => $txn['id'],
            'transaction_id' => $txn['maverick_transaction_id'] ?: ('TXN-' . str_pad($txn['id'], 8, '0', STR_PAD_LEFT)),
            'date' => $txn['created_at'],
            'athlete_id' => $txn['athlete_id'],
            'athlete_name' => $txn['athlete_first'] . ' ' . $txn['athlete_last'],
            'guardian_name' => ($txn['guardian_first'] ?? '') . ' ' . ($txn['guardian_last'] ?? ''),
            'guardian_email' => $txn['guardian_email'] ?? '',
            'program_name' => $txn['program_name'] ?? '',
            'item_name' => $txn['item_name'] ?? '',
            'item_type' => $txn['item_type'] ?? '',
            'accounting_code' => $txn['accounting_code'] ?? '',
            'amount' => floatval($txn['amount']),
            'status' => $txn['status'],
            'payment_type' => $txn['payment_type'] ?? 'full',
            'payment_method' => $txn['payment_method'] ?? '',
            'installment_number' => $txn['installment_number'],
            'refund_amount' => floatval($txn['refund_amount'] ?? 0),
            'refund_reason' => $txn['refund_reason'],
            'refunded_at' => $txn['refunded_at']
        ];
    }, $transactions);

    echo json_encode([
        'success' => true,
        'summary' => $summary,
        'transactions' => $formattedTransactions,
        'filters' => [
            'league_id' => $league_id,
            'program_id' => $program_id,
            'status' => $status,
            'date_from' => $date_from,
            'date_to' => $date_to,
            'payment_type' => $payment_type
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage()
    ]);
}
