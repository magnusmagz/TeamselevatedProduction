<?php
/**
 * Contribution Links API — authenticated management of shareable pay-toward-
 * invoice links (Phase 4).
 *
 * POST ?action=create   body { invoice_id, display_name, message? }
 *   -> { success, link: { token, share_url, ... } }
 * GET  ?action=for-invoice&invoice_id=N
 *   -> { success, link: {...}|null, contributions: [...] }   (creator/family view)
 *
 * Scope: requester must own the invoice's athlete (guardian) or manage the club.
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
require_once __DIR__ . '/../lib/AuditLog.php';
require_once __DIR__ . '/../services/ContributionLinkService.php';

$auth = AuthMiddleware::requireAuth();

try {
    $pdo = Database::getInstance()->getConnection();
    $action = $_GET['action'] ?? '';
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $appUrl = rtrim(Env::get('APP_URL', ''), '/');

    $service = new ContributionLinkService($pdo);

    switch ($action) {
        case 'create':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit;
            }
            $link = $service->createLink(
                $auth,
                (int) ($body['invoice_id'] ?? 0),
                (string) ($body['display_name'] ?? ''),
                isset($body['message']) ? (string) $body['message'] : null
            );

            AuditLog::record($pdo, $auth->getUserId(), 'contribution_link.created',
                'contribution_link', $link['id'], ['invoice_id' => $link['invoice_id']]);

            $link['share_url'] = $appUrl . '/contribute/' . $link['token'];
            echo json_encode(['success' => true, 'link' => $link]);
            break;

        case 'for-invoice':
            $invoiceId = (int) ($_GET['invoice_id'] ?? 0);
            if ($invoiceId <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'invoice_id is required']);
                exit;
            }

            // Same scope contract as creation.
            require_once __DIR__ . '/../lib/AthleteScope.php';
            $athStmt = $pdo->prepare("SELECT athlete_id FROM invoices WHERE id = ?");
            $athStmt->execute([$invoiceId]);
            $athleteId = $athStmt->fetchColumn();
            if ($athleteId === false) {
                http_response_code(404);
                echo json_encode(['error' => 'Invoice not found']);
                exit;
            }
            if (!$auth->isSuperAdmin()) {
                $accessible = AthleteScope::accessibleAthleteIds($pdo, $auth);
                if (!in_array((int) $athleteId, $accessible, true)) {
                    http_response_code(403);
                    echo json_encode(['error' => 'You are not authorized to view this invoice']);
                    exit;
                }
            }

            $linkStmt = $pdo->prepare("
                SELECT id, token, invoice_id, display_name, message, status
                FROM contribution_links
                WHERE invoice_id = ? ORDER BY id DESC LIMIT 1
            ");
            $linkStmt->execute([$invoiceId]);
            $link = $linkStmt->fetch(PDO::FETCH_ASSOC) ?: null;

            $contributions = [];
            if ($link) {
                $link['share_url'] = $appUrl . '/contribute/' . $link['token'];
                $cStmt = $pdo->prepare("
                    SELECT ic.contributor_name, ic.is_anonymous, ic.comment, ic.created_at, pa.amount
                    FROM invoice_contributions ic
                    LEFT JOIN payment_allocations pa
                        ON pa.payment_transaction_id = ic.payment_transaction_id
                       AND pa.invoice_id = ?
                    WHERE ic.contribution_link_id = ?
                    ORDER BY ic.created_at DESC
                ");
                $cStmt->execute([$invoiceId, $link['id']]);
                foreach ($cStmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
                    $contributions[] = [
                        'name' => $c['is_anonymous'] ? 'Anonymous' : ($c['contributor_name'] ?: 'Contributor'),
                        'amount' => $c['amount'] !== null ? (float) $c['amount'] : null,
                        'comment' => $c['comment'],
                        'date' => $c['created_at'],
                    ];
                }
            }

            echo json_encode(['success' => true, 'link' => $link, 'contributions' => $contributions]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action']);
    }
} catch (PaymentValidationException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (OwnershipException $e) {
    http_response_code(403);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Exception $e) {
    error_log('contribution-links error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to process request']);
}
