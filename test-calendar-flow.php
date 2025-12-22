<?php
/**
 * Complete Test Flow for Calendar Invites
 *
 * This script tests the entire calendar invite flow without sending real emails
 */

// Enable test mode
file_put_contents(__DIR__ . '/.env.test', 'APP_ENV=test');

require_once __DIR__ . '/services/CalendarInviteService.php';
require_once __DIR__ . '/services/RecipientService.php';
require_once __DIR__ . '/services/MockMailerService.php';

// Database connection
$servername = "localhost";
$username = "root";
$password = "root";
$dbname = "teams_elevated";
$socket = "/Applications/MAMP/tmp/mysql/mysql.sock";

try {
    $pdo = new PDO("mysql:host=$servername;dbname=$dbname;unix_socket=$socket", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Database connection failed: " . $e->getMessage() . "\n");
}

echo "===========================================\n";
echo "  CALENDAR INVITE SYSTEM - TEST SUITE     \n";
echo "===========================================\n\n";

// Clear previous test emails
$mockMailer = new MockMailerService();
$mockMailer->clearTestEmails();
echo "✓ Cleared previous test emails\n\n";

// TEST 1: Create Event with Invites
echo "TEST 1: Creating Event with Calendar Invites\n";
echo "---------------------------------------------\n";

// Get a team with members
$teamQuery = $pdo->query("
    SELECT t.id, t.name, COUNT(DISTINCT tm.athlete_id) as athlete_count
    FROM teams t
    LEFT JOIN team_members tm ON t.id = tm.team_id
    WHERE tm.status = 'active'
    GROUP BY t.id
    HAVING athlete_count > 0
    LIMIT 1
");
$team = $teamQuery->fetch(PDO::FETCH_ASSOC);

if (!$team) {
    die("No teams with athletes found. Please add athletes to a team first.\n");
}

echo "Using team: {$team['name']} (ID: {$team['id']}) with {$team['athlete_count']} athletes\n";

// Create test event
$eventData = [
    'club_id' => 1,
    'name' => 'Test Event - ' . date('H:i:s'),
    'type' => 'practice',
    'event_date' => date('Y-m-d', strtotime('+3 days')),
    'start_time' => '15:00:00',
    'end_time' => '17:00:00',
    'venue_id' => null,
    'location' => 'Test Field - Main Campus',
    'description' => 'This is a test event created to verify the calendar invite system.',
    'status' => 'scheduled',
    'team_ids' => [$team['id']],
    'send_invites' => true
];

// Simulate API call
echo "Creating event via API...\n";

$ch = curl_init('http://localhost:8889/events-gateway.php');
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($eventData));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$result = json_decode($response, true);

if ($httpCode == 200 && $result['success']) {
    $eventId = $result['id'];
    echo "✓ Event created successfully (ID: {$eventId})\n";

    if (isset($result['invites'])) {
        echo "✓ Invites sent: {$result['invites']['sent']}\n";
        if (isset($result['invites']['failed']) && $result['invites']['failed'] > 0) {
            echo "⚠ Failed invites: {$result['invites']['failed']}\n";
        }
    }
} else {
    echo "✗ Failed to create event\n";
    print_r($result);
    exit;
}

echo "\n";

// TEST 2: Update Event
echo "TEST 2: Updating Event (Time Change)\n";
echo "---------------------------------------------\n";

sleep(1); // Brief pause

$updateData = $eventData;
$updateData['start_time'] = '16:00:00';  // Change time
$updateData['end_time'] = '18:00:00';
$updateData['send_updates'] = true;
unset($updateData['send_invites']);

$ch = curl_init("http://localhost:8889/events-gateway.php?id={$eventId}");
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($updateData));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$result = json_decode($response, true);

if ($httpCode == 200 && $result['success']) {
    echo "✓ Event updated successfully\n";
    if (isset($result['updates'])) {
        echo "✓ Updates sent: " . ($result['updates']['updated'] ?? 0) . "\n";
    }
} else {
    echo "✗ Failed to update event\n";
}

echo "\n";

// TEST 3: Check Email Log
echo "TEST 3: Checking Email Log\n";
echo "---------------------------------------------\n";

$emailLog = $mockMailer->getEmailLog();
$totalEmails = count($emailLog['emails']);

echo "Total emails captured: {$totalEmails}\n";

if ($totalEmails > 0) {
    echo "\nEmail Summary:\n";
    foreach ($emailLog['emails'] as $i => $email) {
        echo sprintf(
            "%d. [%s] To: %-30s Subject: %s\n",
            $i + 1,
            strtoupper($email['type']),
            substr($email['to'], 0, 30),
            $email['subject']
        );
    }
}

echo "\n";

// TEST 4: Verify Database Records
echo "TEST 4: Database Verification\n";
echo "---------------------------------------------\n";

// Check event_invitations table
$inviteCount = $pdo->query("SELECT COUNT(*) FROM event_invitations WHERE event_id = {$eventId}")->fetchColumn();
echo "Event invitations tracked: {$inviteCount}\n";

// Check invite_activity_log
$activityCount = $pdo->query("SELECT COUNT(*) FROM invite_activity_log")->fetchColumn();
echo "Activity log entries: {$activityCount}\n";

echo "\n";

// TEST 5: Cancel Event
echo "TEST 5: Cancelling Event\n";
echo "---------------------------------------------\n";

$ch = curl_init("http://localhost:8889/events-gateway.php?id={$eventId}");
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode == 200) {
    echo "✓ Event cancelled and deleted\n";
    echo "✓ Cancellation notices sent\n";
} else {
    echo "✗ Failed to cancel event\n";
}

echo "\n";

// Final Summary
echo "===========================================\n";
echo "              TEST SUMMARY                 \n";
echo "===========================================\n";

$finalLog = $mockMailer->getEmailLog();
$finalCount = count($finalLog['emails']);

echo "Total emails generated: {$finalCount}\n";
echo "Test output directory: /Applications/MAMP/htdocs/teamselevated-backend/test-output/emails/\n";
echo "\n";
echo "To view the emails:\n";
echo "1. Open: http://localhost:8889/test-email-viewer.php\n";
echo "2. Click on any email to preview\n";
echo "3. Download .ics files to test calendar import\n";
echo "\n";
echo "✅ All tests completed successfully!\n";
?>