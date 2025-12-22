<?php
/**
 * Test Script for Calendar Invite Functionality
 *
 * This script tests the calendar invite system without going through the UI
 * Usage: php test-invite.php
 */

require_once __DIR__ . '/services/CalendarInviteService.php';
require_once __DIR__ . '/services/RecipientService.php';

// Database connection
$servername = "localhost";
$username = "root";
$password = "root";
$dbname = "teams_elevated";
$socket = "/Applications/MAMP/tmp/mysql/mysql.sock";

try {
    $pdo = new PDO("mysql:host=$servername;dbname=$dbname;unix_socket=$socket", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✓ Database connected\n\n";
} catch(PDOException $e) {
    die("✗ Database connection failed: " . $e->getMessage() . "\n");
}

// Test configuration
$testEmail = "test@example.com"; // Replace with your test email address

echo "=== Calendar Invite System Test ===\n\n";

// 1. Test creating a test event
echo "1. Creating test event...\n";

$testEvent = [
    'id' => 999999, // Fake ID for testing
    'name' => 'Test Event - Calendar Invite System',
    'type' => 'meeting',
    'event_date' => date('Y-m-d', strtotime('+1 week')),
    'start_time' => '14:00:00',
    'end_time' => '15:00:00',
    'venue_name' => 'Test Field',
    'venue_address' => '123 Test Street, Test City',
    'description' => 'This is a test event to verify the calendar invite system is working correctly.',
    'status' => 'scheduled',
    'team_names' => 'Test Team A, Test Team B'
];

echo "   Event: {$testEvent['name']}\n";
echo "   Date: {$testEvent['event_date']}\n";
echo "   Time: {$testEvent['start_time']} - {$testEvent['end_time']}\n\n";

// 2. Test recipient collection
echo "2. Testing recipient collection...\n";

// Get a sample team ID from the database
$teamStmt = $pdo->query("SELECT id, name FROM teams LIMIT 1");
$team = $teamStmt->fetch(PDO::FETCH_ASSOC);

if ($team) {
    echo "   Using team: {$team['name']} (ID: {$team['id']})\n";

    $recipientService = new RecipientService($pdo);
    $recipients = $recipientService->getEventRecipients($testEvent['id'], [$team['id']]);

    echo "   Found " . count($recipients) . " recipients\n";

    if (count($recipients) > 0) {
        echo "\n   Recipients:\n";
        foreach ($recipients as $i => $r) {
            if ($i < 5) { // Show first 5 only
                echo "   - {$r['name']} ({$r['type']}): {$r['email']}\n";
            }
        }
        if (count($recipients) > 5) {
            echo "   ... and " . (count($recipients) - 5) . " more\n";
        }
    }
} else {
    echo "   No teams found in database\n";
}

echo "\n";

// 3. Test calendar invite generation
echo "3. Testing calendar invite generation...\n";

$testRecipient = [
    'email' => $testEmail,
    'name' => 'Test User',
    'type' => 'coach',
    'id' => 1
];

try {
    $inviteService = new CalendarInviteService($pdo);

    // Test iCal generation (without sending)
    $reflection = new ReflectionClass($inviteService);
    $method = $reflection->getMethod('generateCalendarInvite');
    $method->setAccessible(true);

    $uid = 'test-' . uniqid() . '@teamselevated.com';
    $ical = $method->invoke($inviteService, $testEvent, $uid, 'REQUEST', 0);

    echo "   ✓ iCalendar generated successfully\n";
    echo "   Length: " . strlen($ical) . " bytes\n";

    // Show first few lines
    $lines = explode("\n", $ical);
    echo "\n   First 10 lines of iCal:\n";
    for ($i = 0; $i < min(10, count($lines)); $i++) {
        echo "   " . trim($lines[$i]) . "\n";
    }

} catch (Exception $e) {
    echo "   ✗ Error: " . $e->getMessage() . "\n";
}

echo "\n";

// 4. Test email configuration
echo "4. Checking email configuration...\n";

$mailConfig = require(__DIR__ . '/config/mail.php');

echo "   SMTP Host: " . $mailConfig['smtp']['host'] . "\n";
echo "   SMTP Port: " . $mailConfig['smtp']['port'] . "\n";
echo "   From Email: " . $mailConfig['smtp']['from_email'] . "\n";
echo "   From Name: " . $mailConfig['smtp']['from_name'] . "\n";

// Check if credentials are set
$hasUsername = !empty($mailConfig['smtp']['username']) && $mailConfig['smtp']['username'] !== 'your-email@gmail.com';
$hasPassword = !empty($mailConfig['smtp']['password']) && $mailConfig['smtp']['password'] !== 'your-app-password';

if ($hasUsername && $hasPassword) {
    echo "   ✓ SMTP credentials configured\n";
} else {
    echo "   ⚠ SMTP credentials not configured - update config/mail.php or set environment variables\n";
    echo "   Set SMTP_USERNAME and SMTP_PASSWORD environment variables\n";
}

echo "\n";

// 5. Optional: Send test email (uncomment to test actual sending)
echo "5. Send test email?\n";
echo "   To send a test calendar invite to {$testEmail}, uncomment the code below\n";

/*
// UNCOMMENT THIS BLOCK TO SEND A TEST EMAIL
if ($hasUsername && $hasPassword) {
    echo "   Sending test invite to {$testEmail}...\n";
    try {
        $result = $inviteService->sendEventInvites($testEvent, [$testRecipient]);
        if ($result['sent'] > 0) {
            echo "   ✓ Test invite sent successfully!\n";
            echo "   Check {$testEmail} for the calendar invite\n";
        } else {
            echo "   ✗ Failed to send invite\n";
            if (!empty($result['errors'])) {
                foreach ($result['errors'] as $error) {
                    echo "   Error: " . $error['error'] . "\n";
                }
            }
        }
    } catch (Exception $e) {
        echo "   ✗ Error sending invite: " . $e->getMessage() . "\n";
    }
} else {
    echo "   ⚠ Skipping email test - SMTP not configured\n";
}
*/

echo "\n=== Test Complete ===\n";

// 6. Database verification
echo "\n6. Database Tables Status:\n";

$tables = ['event_invitations', 'invite_activity_log'];
foreach ($tables as $table) {
    $stmt = $pdo->query("SELECT COUNT(*) FROM $table");
    $count = $stmt->fetchColumn();
    echo "   {$table}: {$count} records\n";
}

echo "\nNOTE: To fully test email sending:\n";
echo "1. Update config/mail.php with valid SMTP credentials\n";
echo "2. Set \$testEmail to your email address\n";
echo "3. Uncomment the email sending section\n";
echo "4. Run: php test-invite.php\n";
?>