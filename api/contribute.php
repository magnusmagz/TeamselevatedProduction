<?php
/**
 * Public Contribute API — no authentication; the 128-bit token is the capability.
 *
 * GET  ?action=state&token=...
 *   -> { success, campaign: { display_name, message, club_name, status,
 *        goal, raised, remaining, contributor_count } }      (PII-safe payload)
 * POST ?action=checkout&token=...   body { amount, name?, email?, anonymous?, comment? }
 *   -> { success, url }                                       (Stripe-hosted checkout)
 *
 * Compliance: contributions are payments toward a fee owed to the club —
 * capped at the live remaining balance, settling to the club's connected
 * account. Not donations; not tax-deductible (the page says so).
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/StripeGateway.php';
require_once __DIR__ . '/../services/ContributionLinkService.php';

try {
    $pdo = Database::getInstance()->getConnection();
    $action = $_GET['action'] ?? 'state';

    $token = (string) ($_GET['token'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        http_response_code(404);
        echo json_encode(['error' => 'Link not found']);
        exit;
    }

    switch ($action) {
        case 'state':
            $service = new ContributionLinkService($pdo);
            $state = $service->getPublicState($token);
            if ($state === null) {
                http_response_code(404);
                echo json_encode(['error' => 'Link not found']);
                exit;
            }
            echo json_encode(['success' => true, 'campaign' => $state]);
            break;

        case 'checkout':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit;
            }
            $body = json_decode(file_get_contents('php://input'), true) ?: [];

            $appUrl = rtrim(Env::get('APP_URL', ''), '/');
            if ($appUrl === '') {
                http_response_code(503);
                echo json_encode(['error' => 'Payments are not configured']);
                exit;
            }
            try {
                $gateway = new StripeGateway();
            } catch (RuntimeException $e) {
                http_response_code(503);
                echo json_encode(['error' => 'Payments are not configured']);
                exit;
            }

            $service = new ContributionLinkService($pdo, $gateway, (int) Env::get('PLATFORM_FEE_BPS', '0'));
            $result = $service->createCheckout(
                $token,
                (float) ($body['amount'] ?? 0),
                [
                    'name' => $body['name'] ?? '',
                    'email' => $body['email'] ?? '',
                    'anonymous' => !empty($body['anonymous']),
                    'comment' => $body['comment'] ?? '',
                ],
                $appUrl . '/contribute/' . $token . '?c=success',
                $appUrl . '/contribute/' . $token . '?c=cancelled'
            );

            echo json_encode(['success' => true, 'url' => $result['url']]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action']);
    }
} catch (PaymentValidationException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (ContributionLinkException $e) {
    http_response_code(410); // link closed/completed/unavailable
    echo json_encode(['error' => $e->getMessage()]);
} catch (\Stripe\Exception\ApiErrorException $e) {
    error_log('contribute Stripe error: ' . $e->getMessage());
    http_response_code(502);
    echo json_encode(['error' => 'Payment provider request failed — please try again']);
} catch (Exception $e) {
    error_log('contribute error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Something went wrong — please try again']);
}
