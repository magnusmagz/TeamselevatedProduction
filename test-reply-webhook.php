<?php
/**
 * Test Script: Simulate SendGrid Inbound Parse sending a calendar REPLY
 */

// Sample iCalendar REPLY that Google Calendar would send
$sampleReply = <<<ICS
Received: from mail-example.com
Subject: Accepted: Team Practice - Soccer U12
From: maggie@4msquared.com
To: events@rsvp.eyeinteams.com

BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Google Inc//Google Calendar 70.9054//EN
METHOD:REPLY
BEGIN:VEVENT
UID:68dfde87bbcc1@teams-elevated.com
DTSTAMP:20251003T143500Z
DTSTART:20251010T190000Z
DTEND:20251010T210000Z
SUMMARY:Team Practice - Soccer U12
ATTENDEE;CN=Maggie;PARTSTAT=ACCEPTED;RSVP=TRUE:MAILTO:maggie@4msquared.com
ORGANIZER:MAILTO:events@rsvp.eyeinteams.com
SEQUENCE:0
STATUS:CONFIRMED
END:VEVENT
END:VCALENDAR
ICS;

echo "Testing Calendar REPLY Parser\n";
echo "================================\n\n";

echo "Simulating SendGrid POST with calendar REPLY...\n";
echo "Target: https://teamselevated-backend-0485388bd66e.herokuapp.com/api/calendar-reply-parser.php\n\n";

// SendGrid sends the raw email in the 'email' POST field
$postData = ['email' => $sampleReply];

$ch = curl_init('https://teamselevated-backend-0485388bd66e.herokuapp.com/api/calendar-reply-parser.php');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/x-www-form-urlencoded'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Status: $httpCode\n";
echo "Response:\n";
echo json_encode(json_decode($response), JSON_PRETTY_PRINT) . "\n\n";

if ($httpCode === 200) {
    $result = json_decode($response, true);
    if ($result['success'] ?? false) {
        echo "✓ SUCCESS! Calendar REPLY was parsed and processed!\n";
        echo "✓ RSVP Status: " . ($result['status'] ?? 'unknown') . "\n";
    } else {
        echo "✗ FAILED: " . ($result['error'] ?? 'Unknown error') . "\n";
    }
} else {
    echo "✗ HTTP Error $httpCode\n";
    echo "Raw response: $response\n";
}

echo "\nTest completed!\n";
