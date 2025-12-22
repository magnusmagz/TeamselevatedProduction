<?php
/**
 * API for Test Email Viewer
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/services/MockMailerService.php';

$action = $_GET['action'] ?? 'list';
$mockMailer = new MockMailerService();

try {
    switch ($action) {
        case 'list':
            $log = $mockMailer->getEmailLog();
            echo json_encode([
                'success' => true,
                'emails' => $log['emails'] ?? []
            ]);
            break;

        case 'clear':
            $mockMailer->clearTestEmails();
            echo json_encode([
                'success' => true,
                'message' => 'All test emails cleared'
            ]);
            break;

        case 'enable_test':
            // Set test mode environment variable
            file_put_contents(__DIR__ . '/.env.test', 'APP_ENV=test');
            echo json_encode([
                'success' => true,
                'message' => 'Test mode enabled'
            ]);
            break;

        case 'disable_test':
            // Remove test mode
            if (file_exists(__DIR__ . '/.env.test')) {
                unlink(__DIR__ . '/.env.test');
            }
            echo json_encode([
                'success' => true,
                'message' => 'Test mode disabled'
            ]);
            break;

        default:
            echo json_encode([
                'success' => false,
                'error' => 'Invalid action'
            ]);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>