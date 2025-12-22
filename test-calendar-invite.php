<?php
/**
 * Test Calendar Invite Script
 *
 * Run this to test sending a calendar invitation
 */

require_once __DIR__ . '/lib/Email.php';
require_once __DIR__ . '/lib/CalendarInvite.php';
require_once __DIR__ . '/config/env.php';

echo "Testing Calendar Invite System\n";
echo "================================\n\n";

// Test event data
$event = [
    'summary' => 'Team Practice - Soccer U12',
    'startDateTime' => '2025-10-10 15:00:00',
    'endDateTime' => '2025-10-10 17:00:00',
    'location' => 'Main Field, Sports Complex',
    'description' => 'Regular practice session. Please arrive 15 minutes early.',
    'status' => 'CONFIRMED',
    'organizerName' => 'Teams Elevated',
    'organizerEmail' => 'events@rsvp.eyeinteams.com',  // RSVP email for calendar REPLY parsing
    'attendees' => [
        [
            'name' => 'Test User',
            'email' => 'maggie@4msquared.com',  // Use your email for testing
            'rsvp_token' => bin2hex(random_bytes(32))  // Generate test RSVP token
        ]
    ]
];

echo "Event Details:\n";
echo "- Title: {$event['summary']}\n";
echo "- Start: {$event['startDateTime']}\n";
echo "- End: {$event['endDateTime']}\n";
echo "- Location: {$event['location']}\n";
echo "- Attendees: " . count($event['attendees']) . "\n\n";

// Generate iCalendar content
echo "Generating iCalendar (.ics) content...\n";
try {
    $icsContent = CalendarInvite::generate($event);
    echo "✓ iCalendar generated successfully\n";
    echo "Sample:\n" . substr($icsContent, 0, 200) . "...\n\n";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}

// Send email
echo "Sending calendar invitation email...\n";
try {
    $email = new Email();
    $sent = $email->sendCalendarInvite($event, 'invite');

    if ($sent) {
        echo "✓ Calendar invite sent successfully!\n";
        echo "✓ Check your email: " . $event['attendees'][0]['email'] . "\n";
        echo "\nWhat to expect:\n";
        echo "1. You should receive an email with event details\n";
        echo "2. The event should appear in your calendar app\n";
        echo "3. You should see Accept/Decline/Tentative buttons\n";
    } else {
        echo "✗ Failed to send calendar invite\n";
        exit(1);
    }
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nTest completed!\n";
