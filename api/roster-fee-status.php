<?php
/**
 * Roster Fee Status API
 * View roster with payment status for each athlete
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

    $program_id = $_GET['program_id'] ?? null;
    $team_id = $_GET['team_id'] ?? null;
    $league_id = $_GET['league_id'] ?? null;
    $club_id = $_GET['club_id'] ?? null;
    $status_filter = $_GET['status'] ?? null; // paid, partial, unpaid, all

    // Scope: caller must be able to access the requested program/team/league/club.
    te_assert_financial_admin($auth, $pdo, ['program' => $program_id, 'team' => $team_id, 'league' => $league_id, 'club' => $club_id]);

    // Build query based on grouping
    if ($program_id) {
        // Get roster for a specific program
        $query = "
            SELECT
                a.id as athlete_id,
                a.first_name,
                a.last_name,
                a.date_of_birth,
                g.first_name as guardian_first,
                g.last_name as guardian_last,
                g.email as guardian_email,
                g.mobile_phone as guardian_phone,
                r.status as registration_status,
                r.submitted_at as registered_at,
                COALESCE(SUM(ap.final_amount), 0) as total_owed,
                COALESCE(SUM(ap.amount_paid), 0) as total_paid,
                COALESCE(SUM(ap.amount_remaining), 0) as total_remaining,
                CASE
                    WHEN COALESCE(SUM(ap.amount_remaining), 0) = 0 AND COALESCE(SUM(ap.final_amount), 0) > 0 THEN 'paid'
                    WHEN COALESCE(SUM(ap.amount_paid), 0) > 0 THEN 'partial'
                    ELSE 'unpaid'
                END as payment_status
            FROM registrations r
            JOIN athletes a ON r.athlete_id = a.id
            LEFT JOIN athlete_guardians ag ON a.id = ag.athlete_id AND ag.is_primary = true
            LEFT JOIN guardians g ON ag.guardian_id = g.id
            LEFT JOIN athlete_payments ap ON a.id = ap.athlete_id AND ap.program_id = r.program_id
            WHERE r.program_id = :program_id
            AND r.status = 'approved'
            AND a.active_status = true
            GROUP BY a.id, a.first_name, a.last_name, a.date_of_birth,
                     g.first_name, g.last_name, g.email, g.mobile_phone,
                     r.status, r.submitted_at
            ORDER BY a.last_name, a.first_name
        ";
        $params = ['program_id' => $program_id];

        // Get program name for context
        $progStmt = $pdo->prepare("SELECT name FROM programs WHERE id = :id");
        $progStmt->execute(['id' => $program_id]);
        $programName = $progStmt->fetchColumn();

    } elseif ($team_id) {
        // Get roster for a specific team
        $query = "
            SELECT
                a.id as athlete_id,
                a.first_name,
                a.last_name,
                a.date_of_birth,
                g.first_name as guardian_first,
                g.last_name as guardian_last,
                g.email as guardian_email,
                g.mobile_phone as guardian_phone,
                tm.jersey_number,
                tm.status as roster_status,
                COALESCE(SUM(ap.final_amount), 0) as total_owed,
                COALESCE(SUM(ap.amount_paid), 0) as total_paid,
                COALESCE(SUM(ap.amount_remaining), 0) as total_remaining,
                CASE
                    WHEN COALESCE(SUM(ap.amount_remaining), 0) = 0 AND COALESCE(SUM(ap.final_amount), 0) > 0 THEN 'paid'
                    WHEN COALESCE(SUM(ap.amount_paid), 0) > 0 THEN 'partial'
                    ELSE 'unpaid'
                END as payment_status
            FROM team_members tm
            JOIN athletes a ON tm.athlete_id = a.id
            LEFT JOIN athlete_guardians ag ON a.id = ag.athlete_id AND ag.is_primary = true
            LEFT JOIN guardians g ON ag.guardian_id = g.id
            LEFT JOIN athlete_payments ap ON a.id = ap.athlete_id
            WHERE tm.team_id = :team_id
            AND a.active_status = true
            GROUP BY a.id, a.first_name, a.last_name, a.date_of_birth,
                     g.first_name, g.last_name, g.email, g.mobile_phone,
                     tm.jersey_number, tm.status
            ORDER BY a.last_name, a.first_name
        ";
        $params = ['team_id' => $team_id];

        $teamStmt = $pdo->prepare("SELECT name FROM teams WHERE id = :id");
        $teamStmt->execute(['id' => $team_id]);
        $programName = $teamStmt->fetchColumn();

    } elseif ($league_id) {
        // Get all athletes in league with payment status
        $query = "
            SELECT
                a.id as athlete_id,
                a.first_name,
                a.last_name,
                a.date_of_birth,
                g.first_name as guardian_first,
                g.last_name as guardian_last,
                g.email as guardian_email,
                COALESCE(SUM(ap.final_amount), 0) as total_owed,
                COALESCE(SUM(ap.amount_paid), 0) as total_paid,
                COALESCE(SUM(ap.amount_remaining), 0) as total_remaining,
                CASE
                    WHEN COALESCE(SUM(ap.amount_remaining), 0) = 0 AND COALESCE(SUM(ap.final_amount), 0) > 0 THEN 'paid'
                    WHEN COALESCE(SUM(ap.amount_paid), 0) > 0 THEN 'partial'
                    ELSE 'unpaid'
                END as payment_status,
                COUNT(DISTINCT ap.program_id) as program_count
            FROM athletes a
            LEFT JOIN athlete_guardians ag ON a.id = ag.athlete_id AND ag.is_primary = true
            LEFT JOIN guardians g ON ag.guardian_id = g.id
            LEFT JOIN athlete_payments ap ON a.id = ap.athlete_id
            LEFT JOIN programs p ON ap.program_id = p.id
            WHERE (a.league_id = :league_id OR p.league_id = :league_id)
            AND a.active_status = true
            GROUP BY a.id, a.first_name, a.last_name, a.date_of_birth,
                     g.first_name, g.last_name, g.email
            ORDER BY a.last_name, a.first_name
        ";
        $params = ['league_id' => $league_id];
        $programName = 'All Programs';

    } elseif ($club_id) {
        // Get all athletes in club with payment status
        $query = "
            SELECT
                a.id as athlete_id,
                a.first_name,
                a.last_name,
                a.date_of_birth,
                g.first_name as guardian_first,
                g.last_name as guardian_last,
                g.email as guardian_email,
                g.mobile_phone as guardian_phone,
                COALESCE(SUM(ap.final_amount), 0) as total_owed,
                COALESCE(SUM(ap.amount_paid), 0) as total_paid,
                COALESCE(SUM(ap.amount_remaining), 0) as total_remaining,
                CASE
                    WHEN COALESCE(SUM(ap.amount_remaining), 0) = 0 AND COALESCE(SUM(ap.final_amount), 0) > 0 THEN 'paid'
                    WHEN COALESCE(SUM(ap.amount_paid), 0) > 0 THEN 'partial'
                    ELSE 'unpaid'
                END as payment_status,
                COUNT(DISTINCT ap.program_id) as program_count
            FROM athletes a
            LEFT JOIN athlete_guardians ag ON a.id = ag.athlete_id AND ag.is_primary = true
            LEFT JOIN guardians g ON ag.guardian_id = g.id
            LEFT JOIN athlete_payments ap ON a.id = ap.athlete_id
            LEFT JOIN programs p ON ap.program_id = p.id
            WHERE p.club_id = :club_id
            AND a.active_status = true
            GROUP BY a.id, a.first_name, a.last_name, a.date_of_birth,
                     g.first_name, g.last_name, g.email, g.mobile_phone
            ORDER BY a.last_name, a.first_name
        ";
        $params = ['club_id' => $club_id];

        // Get club name for context
        $clubStmt = $pdo->prepare("SELECT name FROM club_profile WHERE id = :id");
        $clubStmt->execute(['id' => $club_id]);
        $programName = $clubStmt->fetchColumn() ?: 'All Athletes';

    } else {
        throw new Exception('program_id, team_id, league_id, or club_id is required');
    }

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $roster = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Apply status filter if provided
    if ($status_filter && $status_filter !== 'all') {
        $roster = array_filter($roster, function($athlete) use ($status_filter) {
            return $athlete['payment_status'] === $status_filter;
        });
        $roster = array_values($roster); // Re-index array
    }

    // Calculate summary
    $summary = [
        'total_athletes' => count($roster),
        'paid_count' => 0,
        'partial_count' => 0,
        'unpaid_count' => 0,
        'total_expected' => 0,
        'total_collected' => 0,
        'total_outstanding' => 0
    ];

    foreach ($roster as $athlete) {
        $summary['total_expected'] += floatval($athlete['total_owed']);
        $summary['total_collected'] += floatval($athlete['total_paid']);
        $summary['total_outstanding'] += floatval($athlete['total_remaining']);

        switch ($athlete['payment_status']) {
            case 'paid':
                $summary['paid_count']++;
                break;
            case 'partial':
                $summary['partial_count']++;
                break;
            case 'unpaid':
                $summary['unpaid_count']++;
                break;
        }
    }

    $summary['collection_rate'] = $summary['total_expected'] > 0
        ? round(($summary['total_collected'] / $summary['total_expected']) * 100, 1)
        : 0;

    echo json_encode([
        'success' => true,
        'context' => $programName ?? 'Roster',
        'summary' => $summary,
        'roster' => $roster
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage()
    ]);
}
