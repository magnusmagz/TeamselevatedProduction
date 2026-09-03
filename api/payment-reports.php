<?php
/**
 * Payment Reports API — treasurer-grade views (Phase 6, pulled forward).
 *
 * GET ?action=summary&club_id=N[&from=YYYY-MM-DD&to=YYYY-MM-DD]
 *   -> { success, summary: { collected, refunded, net, transaction_count } }
 * GET ?action=transactions&club_id=N[&from&to&limit]
 *   -> { success, transactions: [...] }
 * GET ?action=payouts&club_id=N
 *   -> { success, payouts: [...] }
 *
 * Access: club_admin (manage_club) OR an active 'treasurer' role on the club
 * in user_club_access — the table is authoritative, never users.role.
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();


require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/financial_scope.php';
require_once __DIR__ . '/../services/PaymentReportService.php';

$auth = AuthMiddleware::requireAuth();

try {
    $pdo = Database::getInstance()->getConnection();

    $clubId = (int) ($_GET['club_id'] ?? 0);
    if ($clubId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'club_id is required']);
        exit;
    }

    // club_admin OR treasurer — one predicate, shared with every other financial
    // endpoint through lib/financial_scope.php. The roles on $auth are re-derived
    // from user_club_access on every request, so no separate DB lookup is needed.
    if (!te_is_financial_admin($auth, $clubId)) {
        http_response_code(403);
        echo json_encode(['error' => 'You do not have permission to view financial reports for this club']);
        exit;
    }

    // Basic date validation — invalid formats are ignored rather than 400ing.
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : null;
    $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : null;

    $service = new PaymentReportService($pdo);

    switch ($_GET['action'] ?? 'summary') {
        case 'summary':
            echo json_encode(['success' => true, 'summary' => $service->summary($clubId, $from, $to)]);
            break;
        case 'transactions':
            echo json_encode(['success' => true,
                'transactions' => $service->transactions($clubId, $from, $to, (int) ($_GET['limit'] ?? 50))]);
            break;
        case 'payouts':
            echo json_encode(['success' => true, 'payouts' => $service->payouts($clubId)]);
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action']);
    }
} catch (Exception $e) {
    error_log('payment-reports error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load report']);
}
