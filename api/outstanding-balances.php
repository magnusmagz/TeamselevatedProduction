<?php
/**
 * Outstanding Balances API
 * List families/athletes with outstanding payment balances
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
    $program_id = $_GET['program_id'] ?? null;
    $team_id = $_GET['team_id'] ?? null;
    $sort_by = $_GET['sort_by'] ?? 'amount'; // amount, days_overdue, name
    $sort_order = $_GET['sort_order'] ?? 'DESC';

    // Scope: caller must be able to access the requested league/program/team.
    te_assert_financial_admin($auth, $pdo, ['league' => $league_id, 'program' => $program_id, 'team' => $team_id]);

    // Validate sort order
    $sort_order = strtoupper($sort_order) === 'ASC' ? 'ASC' : 'DESC';

    // Build WHERE clause
    $where_conditions = ['ap.amount_remaining > 0'];
    $params = [];

    if ($league_id) {
        $where_conditions[] = 'a.club_id = :league_id';
        $params['league_id'] = $league_id;
    }
    if ($program_id) {
        $where_conditions[] = 'ap.program_id = :program_id';
        $params['program_id'] = $program_id;
    }
    if ($team_id) {
        $where_conditions[] = 'EXISTS (SELECT 1 FROM team_members tm WHERE tm.athlete_id = a.id AND tm.team_id = :team_id)';
        $params['team_id'] = $team_id;
    }

    $where_clause = implode(' AND ', $where_conditions);

    // Build ORDER BY
    $order_by = match($sort_by) {
        'days_overdue' => 'days_overdue ' . $sort_order,
        'name' => 'athlete_name ' . $sort_order,
        default => 'total_remaining ' . $sort_order
    };

    // Query for outstanding balances grouped by athlete
    $query = "
        SELECT
            a.id as athlete_id,
            a.first_name || ' ' || a.last_name as athlete_name,
            g.first_name || ' ' || g.last_name as guardian_name,
            g.email as guardian_email,
            g.mobile_phone as guardian_phone,
            COUNT(DISTINCT ap.id) as payment_count,
            COALESCE(SUM(ap.final_amount), 0) as total_owed,
            COALESCE(SUM(ap.amount_paid), 0) as total_paid,
            COALESCE(SUM(ap.amount_remaining), 0) as total_remaining,
            MIN(ap.due_date) as earliest_due_date,
            CASE
                WHEN MIN(ap.due_date) IS NOT NULL AND MIN(ap.due_date) < CURRENT_DATE
                THEN (CURRENT_DATE - MIN(ap.due_date)::date)
                ELSE 0
            END as days_overdue,
            bool_or(ap.due_date < CURRENT_DATE) as has_overdue,
            json_agg(json_build_object(
                'id', ap.id,
                'item_name', pi.name,
                'program_name', p.name,
                'amount', ap.amount_remaining,
                'due_date', ap.due_date,
                'status', ap.status
            )) as payments
        FROM athlete_payments ap
        JOIN athletes a ON ap.athlete_id = a.id
        JOIN payment_items pi ON ap.payment_item_id = pi.id
        JOIN programs p ON ap.program_id = p.id
        LEFT JOIN athlete_guardians ag ON a.id = ag.athlete_id AND ag.is_primary = true
        LEFT JOIN guardians g ON ag.guardian_id = g.id
        WHERE $where_clause
        GROUP BY a.id, a.first_name, a.last_name, g.first_name, g.last_name, g.email, g.mobile_phone
        ORDER BY $order_by
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $balances = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Parse JSON aggregated payments
    foreach ($balances as &$balance) {
        $balance['payments'] = json_decode($balance['payments'], true);
        $balance['days_overdue'] = (int)$balance['days_overdue'];
        $balance['has_overdue'] = (bool)$balance['has_overdue'];
    }

    // Summary stats
    $total_outstanding = array_sum(array_column($balances, 'total_remaining'));
    $families_count = count($balances);
    $overdue_count = count(array_filter($balances, fn($b) => $b['has_overdue']));

    echo json_encode([
        'success' => true,
        'summary' => [
            'total_outstanding' => $total_outstanding,
            'families_count' => $families_count,
            'overdue_count' => $overdue_count
        ],
        'balances' => $balances
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to fetch outstanding balances',
        'message' => $e->getMessage()
    ]);
}
