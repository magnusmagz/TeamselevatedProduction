<?php
/**
 * Complete RSVP Parser Test
 * Creates event + attendee in database, then sends invite
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/lib/Email.php';
require_once __DIR__ . '/lib/CalendarInvite.php';

echo "RSVP Parser End-to-End Test\n";
echo "============================\n\n";

$db = Database::getInstance();
$conn = $db->getConnection();

// Step 1: Create event in database
echo "Step 1: Creating event in database...\n";
$stmt = $conn->prepare('
    INSERT INTO calendar_events (club_id, name, type, event_date, start_time, end_time, location, description, status, created_at)
    VALUES (:club_id, :name, :type, :event_date, :start_time, :end_time, :location, :description, :status, CURRENT_TIMESTAMP)
    RETURNING id
');
$stmt->execute([
    'club_id' => 9,
    'name' => 'RSVP Test Event - ' . date('H:i:s'),
    'type' => 'practice',
    'event_date' => '2025-10-10',
    'start_time' => '15:00:00',
    'end_time' => '17:00:00',
    'location' => 'Main Field, Sports Complex',
    'description' => 'Testing RSVP email parser',
    'status' => 'scheduled'
]);
$result = $stmt->fetch();
$eventId = $result['id'];
echo "✓ Event created (ID: $eventId)\n\n";

// Step 2: Create attendee record
echo "Step 2: Creating attendee record...\n";
$rsvpToken = bin2hex(random_bytes(32));
$stmt = $conn->prepare('
    INSERT INTO calendar_event_attendees (event_id, user_id, email, rsvp_status, rsvp_token, created_at)
    VALUES (:event_id, :user_id, :email, :rsvp_status, :rsvp_token, CURRENT_TIMESTAMP)
    RETURNING id
');
$stmt->execute([
    'event_id' => $eventId,
    'user_id' => 16,
    'email' => 'maggie@4msquared.com',
    'rsvp_status' => 'pending',
    'rsvp_token' => $rsvpToken
]);
$result = $stmt->fetch();
$attendeeId = $result['id'];
echo "✓ Attendee created (ID: $attendeeId)\n";
echo "  Email: maggie@4msquared.com\n";
echo "  RSVP Token: $rsvpToken\n\n";

// Step 3: Generate and send calendar invite
echo "Step 3: Sending calendar invite...\n";

$calendarEvent = [
    'summary' => 'RSVP Test Event - ' . date('H:i:s'),
    'startDateTime' => '2025-10-10 15:00:00',
    'endDateTime' => '2025-10-10 17:00:00',
    'location' => 'Main Field, Sports Complex',
    'description' => 'Testing RSVP email parser. Please click Yes/No/Maybe in your calendar app.',
    'status' => 'CONFIRMED',
    'organizerName' => 'Teams Elevated',
    'organizerEmail' => 'events@rsvp.eyeinteams.com',
    'attendees' => [
        [
            'name' => 'Maggie',
            'email' => 'maggie@4msquared.com',
            'rsvp_token' => $rsvpToken
        ]
    ]
];

$email = new Email();
$sent = $email->sendCalendarInvite($calendarEvent, 'invite');

if ($sent) {
    echo "✓ Calendar invite sent!\n\n";

    echo "Next Steps:\n";
    echo "===========\n";
    echo "1. Check email: maggie@4msquared.com\n";
    echo "2. Open the calendar invite\n";
    echo "3. Click 'Yes' (or Maybe/No) in your calendar app\n";
    echo "4. Wait 30-60 seconds\n";
    echo "5. Check database:\n\n";
    echo "   SELECT email, rsvp_status, responded_at\n";
    echo "   FROM calendar_event_attendees\n";
    echo "   WHERE id = $attendeeId;\n\n";
    echo "6. Check Heroku logs:\n";
    echo "   heroku logs --app teamselevated-backend | grep 'Calendar REPLY'\n\n";

    echo "Database Record Created:\n";
    echo "  Event ID: $eventId\n";
    echo "  Attendee ID: $attendeeId\n";
    echo "  Email: maggie@4msquared.com\n";
    echo "  Current Status: pending\n";
} else {
    echo "✗ Failed to send invite\n";
}
