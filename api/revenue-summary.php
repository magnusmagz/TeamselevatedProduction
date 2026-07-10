<?php
/**
 * Revenue Summary API
 * Get revenue summary for league/club
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

    $league_id = $_GET['league_id'] ?? null;
    $club_id = $_GET['club_id'] ?? null;

    // Scope: caller must be able to access the requested league/club.
    te_assert_financial_admin($auth, $pdo, ['league' => $league_id, 'club' => $club_id]);

    // Build WHERE clause based on scope
    $where_clause = '1=1';
    $params = [];

    if ($league_id) {
        $where_clause .= ' AND a.club_id = :league_id';
        $params['league_id'] = $league_id;
    }
    if ($club_id) {
        $where_clause .= ' AND a.club_id = :club_id';
        $params['club_id'] = $club_id;
    }

    // Summary totals
    $summary_query = "
        SELECT
            COALESCE(SUM(ap.final_amount), 0) as total_revenue,
            COALESCE(SUM(ap.amount_paid), 0) as collected,
            COALESCE(SUM(ap.amount_remaining), 0) as outstanding,
            CASE
                WHEN SUM(ap.final_amount) > 0
                THEN ROUND((SUM(ap.amount_paid) / SUM(ap.final_amount)) * 100, 1)
                ELSE 0
            END as collection_rate
        FROM athlete_payments ap
        JOIN athletes a ON ap.athlete_id = a.id
        WHERE $where_clause
    ";
    $stmt = $pdo->prepare($summary_query);
    $stmt->execute($params);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);

    // By program
    $by_program_query = "
        SELECT
            p.id,
            p.name,
            COUNT(DISTINCT ap.athlete_id) as athletes,
            COALESCE(SUM(ap.final_amount), 0) as revenue,
            COALESCE(SUM(ap.amount_paid), 0) as collected,
            COALESCE(SUM(ap.amount_remaining), 0) as outstanding,
            CASE
                WHEN SUM(ap.final_amount) > 0
                THEN ROUND((SUM(ap.amount_paid) / SUM(ap.final_amount)) * 100, 1)
                ELSE 0
            END as collection_rate
        FROM athlete_payments ap
        JOIN athletes a ON ap.athlete_id = a.id
        JOIN programs p ON ap.program_id = p.id
        WHERE $where_clause
        GROUP BY p.id, p.name
        ORDER BY revenue DESC
    ";
    $stmt = $pdo->prepare($by_program_query);
    $stmt->execute($params);
    $by_program = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // By status
    $by_status_query = "
        SELECT
            ap.status,
            COUNT(*) as count,
            COALESCE(SUM(ap.final_amount), 0) as amount
        FROM athlete_payments ap
        JOIN athletes a ON ap.athlete_id = a.id
        WHERE $where_clause
        GROUP BY ap.status
        ORDER BY
            CASE ap.status
                WHEN 'paid' THEN 1
                WHEN 'partial' THEN 2
                WHEN 'pending' THEN 3
                ELSE 4
            END
    ";
    $stmt = $pdo->prepare($by_status_query);
    $stmt->execute($params);
    $by_status_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format by_status as object
    $by_status = [];
    foreach ($by_status_rows as $row) {
        $by_status[$row['status']] = [
            'count' => (int)$row['count'],
            'amount' => (float)$row['amount']
        ];
    }

    echo json_encode([
        'success' => true,
        'summary' => $summary,
        'by_program' => $by_program,
        'by_status' => $by_status
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to fetch revenue summary',
        'message' => $e->getMessage()
    ]);
}
