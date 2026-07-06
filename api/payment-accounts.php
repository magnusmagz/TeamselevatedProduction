<?php
/**
 * Payment Accounts API — Stripe Connect onboarding per club.
 *
 * All actions are club_admin-gated via $auth->can('manage_club', ...): the
 * client passes club_id, the server trusts only the JWT scope.
 *
 * GET  ?action=status&club_id=N        -> { success, account: {...}|null }
 * POST ?action=create        body { club_id }  -> { success, url, account }   (starts/resumes onboarding)
 * POST ?action=refresh-link  body { club_id }  -> { success, url, account }   (new link, account must exist)
 *
 * Errors: 400 bad input · 401 unauthenticated · 403 not club admin ·
 *         503 Stripe/APP_URL not configured · 500 unexpected
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
require_once __DIR__ . '/../lib/StripeGateway.php';
require_once __DIR__ . '/../lib/AuditLog.php';
require_once __DIR__ . '/../services/StripeConnectService.php';

$auth = AuthMiddleware::requireAuth();

try {
    $pdo = Database::getInstance()->getConnection();

    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? 'status';
    $body = json_decode(file_get_contents('php://input'), true) ?: [];

    $clubId = (int) ($_GET['club_id'] ?? $body['club_id'] ?? 0);
    if ($clubId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'club_id is required']);
        exit;
    }

    if (!$auth->can('manage_club', $clubId, 'club')) {
        http_response_code(403);
        echo json_encode(['error' => 'You do not have permission to manage payment settings for this club']);
        exit;
    }

    // Onboarding return/refresh URLs land back on the Payments tab of club settings.
    $appUrl = rtrim(Env::get('APP_URL', ''), '/');

    switch ($action) {
        case 'status':
            if ($method !== 'GET') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit;
            }
            $service = new StripeConnectService($pdo);
            echo json_encode(['success' => true, 'account' => $service->getStatus($clubId)]);
            break;

        case 'create':
        case 'refresh-link':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit;
            }
            if ($appUrl === '') {
                http_response_code(503);
                echo json_encode(['error' => 'APP_URL is not configured — cannot build onboarding return links']);
                exit;
            }

            try {
                $gateway = new StripeGateway();
            } catch (RuntimeException $e) {
                http_response_code(503);
                echo json_encode(['error' => 'Stripe is not configured on this environment (STRIPE_SECRET_KEY missing)']);
                exit;
            }

            $refreshUrl = $appUrl . '/club-profile?tab=payments&onboarding=refresh';
            $returnUrl  = $appUrl . '/club-profile?tab=payments&onboarding=return';
            $service = new StripeConnectService($pdo, $gateway);

            if ($action === 'create') {
                $clubStmt = $pdo->prepare("SELECT name, email FROM club_profile WHERE id = ?");
                $clubStmt->execute([$clubId]);
                $club = $clubStmt->fetch(PDO::FETCH_ASSOC);
                if (!$club) {
                    http_response_code(404);
                    echo json_encode(['error' => 'Club not found']);
                    exit;
                }
                $result = $service->startOnboarding(
                    $clubId,
                    $auth->getUserId(),
                    $club['name'] ?? '',
                    $club['email'] ?? '',
                    $refreshUrl,
                    $returnUrl
                );
            } else {
                $result = $service->refreshLink($clubId, $refreshUrl, $returnUrl);
            }

            AuditLog::record($pdo, $auth->getUserId(),
                $action === 'create' ? 'payments.onboarding_started' : 'payments.onboarding_link_refreshed',
                'club_payment_account', $clubId, [
                    'stripe_account_id' => $result['account']['stripe_account_id'] ?? null,
                ]);

            echo json_encode(['success' => true, 'url' => $result['url'], 'account' => $result['account']]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action']);
    }
} catch (StripeConnectException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (\Stripe\Exception\ApiErrorException $e) {
    error_log('payment-accounts Stripe error: ' . $e->getMessage());
    http_response_code(502);
    echo json_encode(['error' => 'Stripe request failed — please try again']);
} catch (Exception $e) {
    error_log('payment-accounts error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to process payment account request']);
}
