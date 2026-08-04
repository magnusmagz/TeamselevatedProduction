<?php
/**
 * Campaign Donations API
 * Process donations and retrieve donor information for fundraiser campaigns
 */

header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();


require_once '../config/database.php';
require_once '../lib/PaymentProcessorFactory.php';
require_once '../lib/AuthMiddleware.php';
require_once '../lib/Email.php';

$database = Database::getInstance();
$db = $database->getConnection();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';

// Check if demo mode is enabled
$demoMode = Env::get('PAYMENT_MODE', 'demo') === 'demo';

try {
    switch ($action) {
        // ========================================
        // CREATE: Process a new donation
        // ========================================
        case 'create':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit();
            }

            $data = json_decode(file_get_contents("php://input"), true);

            // Validate required fields
            $required = ['campaign_id', 'donor_name', 'donor_email', 'amount', 'payment_method'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    http_response_code(400);
                    echo json_encode(['error' => "$field is required"]);
                    exit();
                }
            }

            // Validate amount
            $amount = floatval($data['amount']);
            if ($amount < 1) {
                http_response_code(400);
                echo json_encode(['error' => 'Minimum donation amount is $1']);
                exit();
            }

            // Verify campaign exists and is active
            $stmt = $db->prepare("
                SELECT fc.*, cp.name AS club_name
                FROM fundraiser_campaigns fc
                JOIN club_profile cp ON fc.club_id = cp.id
                WHERE fc.id = ? AND fc.deleted_at IS NULL
            ");
            $stmt->execute([$data['campaign_id']]);
            $campaign = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$campaign) {
                http_response_code(404);
                echo json_encode(['error' => 'Campaign not found']);
                exit();
            }

            if ($campaign['status'] !== 'active') {
                http_response_code(400);
                echo json_encode(['error' => 'Campaign is not accepting donations']);
                exit();
            }

            if (strtotime($campaign['end_date']) < time()) {
                http_response_code(400);
                echo json_encode(['error' => 'Campaign has ended']);
                exit();
            }

            // Process payment
            $processor = PaymentProcessorFactory::create();
            $paymentResult = $processor->processPayment($amount, $data['payment_method']);

            // Create donation record
            $stmt = $db->prepare("
                INSERT INTO campaign_donations (
                    campaign_id, donor_name, donor_email, donor_phone,
                    is_anonymous, amount, comment,
                    maverick_transaction_id, maverick_charge_id, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $status = $paymentResult['success'] ? 'succeeded' : 'failed';

            $stmt->execute([
                $data['campaign_id'],
                $data['donor_name'],
                $data['donor_email'],
                $data['donor_phone'] ?? null,
                $data['is_anonymous'] ?? false,
                $amount,
                $data['comment'] ?? null,
                $paymentResult['transaction_id'] ?? null,
                $paymentResult['charge_id'] ?? null,
                $status
            ]);

            $donationId = $db->lastInsertId();

            if ($paymentResult['success']) {
                // Send receipt email
                try {
                    $email = (new Email())->forClub($db, $campaign['club_id'] ?? null);
                    $email->sendDonationReceipt(
                        $data['donor_email'],
                        $data['donor_name'],
                        $amount,
                        $campaign['title'],
                        $campaign['club_name'],
                        $donationId,
                        $paymentResult['transaction_id']
                    );

                    // Mark receipt as sent
                    $stmt = $db->prepare("UPDATE campaign_donations SET receipt_sent_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt->execute([$donationId]);
                } catch (Exception $e) {
                    // Log email error but don't fail the donation
                    error_log("Failed to send donation receipt: " . $e->getMessage());
                }

                echo json_encode([
                    'success' => true,
                    'donation_id' => $donationId,
                    'transaction_id' => $paymentResult['transaction_id'],
                    'message' => 'Thank you for your donation!',
                    'demo_mode' => $demoMode
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'donation_id' => $donationId,
                    'error' => $paymentResult['error_message'],
                    'error_code' => $paymentResult['error_code'],
                    'demo_mode' => $demoMode
                ]);
            }
            break;

        // ========================================
        // LIST: Get donations for a campaign
        // ========================================
        case 'list':
            if ($method !== 'GET') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit();
            }

            $campaignId = $_GET['campaign_id'] ?? null;
            if (!$campaignId) {
                http_response_code(400);
                echo json_encode(['error' => 'campaign_id parameter is required']);
                exit();
            }

            // Check if admin view (show all details) or public view (respect privacy)
            $isAdmin = isset($_GET['admin']) && $_GET['admin'] === 'true';

            // The admin view exposes donor PII — require auth + access to the campaign's club.
            if ($isAdmin) {
                $auth = AuthMiddleware::requireAuth();
                $cstmt = $db->prepare("SELECT club_id FROM fundraiser_campaigns WHERE id = ?");
                $cstmt->execute([$campaignId]);
                $campaignClubId = (int)$cstmt->fetchColumn();
                if (!$auth->canAccessClub($campaignClubId)) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Not authorized for this campaign']);
                    exit();
                }
            }
            $limit = intval($_GET['limit'] ?? 50);
            $offset = intval($_GET['offset'] ?? 0);

            // Get campaign settings
            $stmt = $db->prepare("
                SELECT show_donor_names, show_donor_amounts
                FROM fundraiser_campaigns
                WHERE id = ? AND deleted_at IS NULL
            ");
            $stmt->execute([$campaignId]);
            $settings = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$settings) {
                http_response_code(404);
                echo json_encode(['error' => 'Campaign not found']);
                exit();
            }

            // Build query based on admin vs public view
            if ($isAdmin) {
                $stmt = $db->prepare("
                    SELECT id, donor_name, donor_email, donor_phone, is_anonymous,
                           amount, comment, status, receipt_sent_at, created_at
                    FROM campaign_donations
                    WHERE campaign_id = ? AND deleted_at IS NULL
                    ORDER BY created_at DESC
                    LIMIT ? OFFSET ?
                ");
                $stmt->execute([$campaignId, $limit, $offset]);
            } else {
                $stmt = $db->prepare("
                    SELECT id, donor_name, is_anonymous, amount, comment, created_at
                    FROM campaign_donations
                    WHERE campaign_id = ? AND status = 'succeeded' AND deleted_at IS NULL
                    ORDER BY created_at DESC
                    LIMIT ? OFFSET ?
                ");
                $stmt->execute([$campaignId, $limit, $offset]);
            }

            $donations = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Apply privacy settings for public view
            if (!$isAdmin) {
                foreach ($donations as &$donation) {
                    if ($donation['is_anonymous'] || !$settings['show_donor_names']) {
                        $donation['donor_name'] = 'Anonymous';
                    }
                    if (!$settings['show_donor_amounts']) {
                        unset($donation['amount']);
                    }
                }
            }

            // Get total count
            $countSql = $isAdmin
                ? "SELECT COUNT(*) FROM campaign_donations WHERE campaign_id = ? AND deleted_at IS NULL"
                : "SELECT COUNT(*) FROM campaign_donations WHERE campaign_id = ? AND status = 'succeeded' AND deleted_at IS NULL";
            $stmt = $db->prepare($countSql);
            $stmt->execute([$campaignId]);
            $total = $stmt->fetchColumn();

            echo json_encode([
                'donations' => $donations,
                'total' => intval($total),
                'limit' => $limit,
                'offset' => $offset
            ]);
            break;

        // ========================================
        // DONOR-WALL: Get formatted donor wall data
        // ========================================
        case 'donor-wall':
            if ($method !== 'GET') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit();
            }

            $campaignId = $_GET['campaign_id'] ?? null;
            if (!$campaignId) {
                http_response_code(400);
                echo json_encode(['error' => 'campaign_id parameter is required']);
                exit();
            }

            // Get campaign settings
            $stmt = $db->prepare("
                SELECT show_donor_names, show_donor_amounts, allow_comments
                FROM fundraiser_campaigns
                WHERE id = ? AND deleted_at IS NULL
            ");
            $stmt->execute([$campaignId]);
            $settings = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$settings) {
                http_response_code(404);
                echo json_encode(['error' => 'Campaign not found']);
                exit();
            }

            $limit = intval($_GET['limit'] ?? 20);

            $stmt = $db->prepare("
                SELECT id, donor_name, is_anonymous, amount, comment, created_at
                FROM campaign_donations
                WHERE campaign_id = ? AND status = 'succeeded' AND deleted_at IS NULL
                ORDER BY created_at DESC
                LIMIT ?
            ");
            $stmt->execute([$campaignId, $limit]);
            $donors = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Apply privacy settings
            foreach ($donors as &$donor) {
                if ($donor['is_anonymous'] || !$settings['show_donor_names']) {
                    $donor['display_name'] = 'Anonymous';
                } else {
                    $donor['display_name'] = $donor['donor_name'];
                }

                if (!$settings['show_donor_amounts']) {
                    $donor['amount'] = null;
                }

                if (!$settings['allow_comments']) {
                    $donor['comment'] = null;
                }

                unset($donor['donor_name']);
                unset($donor['is_anonymous']);
            }

            echo json_encode($donors);
            break;

        // ========================================
        // RECEIPT: Get/regenerate donation receipt
        // ========================================
        case 'receipt':
            if ($method !== 'GET') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit();
            }

            $donationId = $_GET['id'] ?? null;
            $email = $_GET['email'] ?? null;

            if (!$donationId || !$email) {
                http_response_code(400);
                echo json_encode(['error' => 'id and email parameters are required']);
                exit();
            }

            // Verify donation exists and email matches
            $stmt = $db->prepare("
                SELECT cd.*, fc.title as campaign_title, cp.name AS club_name
                FROM campaign_donations cd
                JOIN fundraiser_campaigns fc ON cd.campaign_id = fc.id
                JOIN club_profile cp ON fc.club_id = cp.id
                WHERE cd.id = ? AND cd.donor_email = ? AND cd.status = 'succeeded'
            ");
            $stmt->execute([$donationId, $email]);
            $donation = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$donation) {
                http_response_code(404);
                echo json_encode(['error' => 'Donation not found or email does not match']);
                exit();
            }

            // Return receipt data
            echo json_encode([
                'donation_id' => $donation['id'],
                'amount' => $donation['amount'],
                'donor_name' => $donation['donor_name'],
                'donor_email' => $donation['donor_email'],
                'campaign_title' => $donation['campaign_title'],
                'club_name' => $donation['club_name'],
                'transaction_id' => $donation['maverick_transaction_id'],
                'date' => $donation['created_at'],
                'receipt_sent_at' => $donation['receipt_sent_at']
            ]);
            break;

        // ========================================
        // RESEND-RECEIPT: Resend receipt email
        // ========================================
        case 'resend-receipt':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit();
            }

            // Admin action (sends an email) — require authentication.
            AuthMiddleware::requireAuth();

            $data = json_decode(file_get_contents("php://input"), true);

            if (empty($data['donation_id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'donation_id is required']);
                exit();
            }

            // Get donation details
            $stmt = $db->prepare("
                SELECT cd.*, fc.title as campaign_title, cp.name AS club_name,
                       fc.club_id AS club_id
                FROM campaign_donations cd
                JOIN fundraiser_campaigns fc ON cd.campaign_id = fc.id
                JOIN club_profile cp ON fc.club_id = cp.id
                WHERE cd.id = ? AND cd.status = 'succeeded'
            ");
            $stmt->execute([$data['donation_id']]);
            $donation = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$donation) {
                http_response_code(404);
                echo json_encode(['error' => 'Donation not found']);
                exit();
            }

            // Send receipt email
            $email = (new Email())->forClub($db, $donation['club_id'] ?? null);
            $email->sendDonationReceipt(
                $donation['donor_email'],
                $donation['donor_name'],
                $donation['amount'],
                $donation['campaign_title'],
                $donation['club_name'],
                $donation['id'],
                $donation['maverick_transaction_id']
            );

            // Update receipt_sent_at
            $stmt = $db->prepare("UPDATE campaign_donations SET receipt_sent_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$data['donation_id']]);

            echo json_encode([
                'success' => true,
                'message' => 'Receipt sent successfully'
            ]);
            break;

        // ========================================
        // EXPORT: Export donations as CSV
        // ========================================
        case 'export':
            if ($method !== 'GET') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit();
            }

            $campaignId = $_GET['campaign_id'] ?? null;
            if (!$campaignId) {
                http_response_code(400);
                echo json_encode(['error' => 'campaign_id parameter is required']);
                exit();
            }

            // Admin export of donor PII — require auth + access to the campaign's club.
            $auth = AuthMiddleware::requireAuth();
            $stmt = $db->prepare("SELECT title, club_id FROM fundraiser_campaigns WHERE id = ?");
            $stmt->execute([$campaignId]);
            $campaign = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$campaign) {
                http_response_code(404);
                echo json_encode(['error' => 'Campaign not found']);
                exit();
            }
            if (!$auth->canAccessClub((int)$campaign['club_id'])) {
                http_response_code(403);
                echo json_encode(['error' => 'Not authorized for this campaign']);
                exit();
            }

            // Get all successful donations
            $stmt = $db->prepare("
                SELECT donor_name, donor_email, donor_phone, amount, comment,
                       is_anonymous, maverick_transaction_id, status, created_at
                FROM campaign_donations
                WHERE campaign_id = ? AND status = 'succeeded' AND deleted_at IS NULL
                ORDER BY created_at DESC
            ");
            $stmt->execute([$campaignId]);
            $donations = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Generate CSV
            $filename = 'donations-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($campaign['title'])) . '-' . date('Y-m-d') . '.csv';

            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="' . $filename . '"');

            $output = fopen('php://output', 'w');

            // Header row
            fputcsv($output, ['Name', 'Email', 'Phone', 'Amount', 'Comment', 'Anonymous', 'Transaction ID', 'Date']);

            // Data rows
            foreach ($donations as $donation) {
                fputcsv($output, [
                    $donation['donor_name'],
                    $donation['donor_email'],
                    $donation['donor_phone'],
                    '$' . number_format($donation['amount'], 2),
                    $donation['comment'],
                    $donation['is_anonymous'] ? 'Yes' : 'No',
                    $donation['maverick_transaction_id'],
                    $donation['created_at']
                ]);
            }

            fclose($output);
            exit();

        default:
            http_response_code(400);
            echo json_encode([
                'error' => 'Invalid action',
                'available_actions' => ['create', 'list', 'donor-wall', 'receipt', 'resend-receipt', 'export']
            ]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
