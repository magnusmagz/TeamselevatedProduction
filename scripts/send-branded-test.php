<?php
/**
 * One-off: send a branded calendar-invite email to a test address so we can see
 * how it lands in a real inbox. Renders the REAL CalendarInviteService HTML for a
 * given club (so logo + social icons + colors are live) and sends it through the
 * same PHPMailer/SendGrid path production uses. SEND-ONLY — writes no DB rows.
 *
 *   heroku run php scripts/send-branded-test.php <to-email> <club_id>
 *
 * The RSVP buttons render but point at a placeholder event, so they are visual
 * only in this test (a fully clickable RSVP needs a real event in the DB).
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/CalendarInviteService.php';

$to = $argv[1] ?? '';
$clubId = (int) ($argv[2] ?? 0);
if (!$to || !$clubId) {
    fwrite(STDERR, "usage: php scripts/send-branded-test.php <to-email> <club_id>\n");
    exit(1);
}

$pdo = Database::getInstance()->getConnection();
$svc = new CalendarInviteService($pdo);

// Sample event — realistic fields, not persisted.
$event = [
    'id' => 999999,
    'club_id' => $clubId,
    'name' => 'Team Practice',
    'team_names' => 'Tigers',
    'event_date' => date('Y-m-d', strtotime('+1 week')),
    'start_time' => '17:30:00',
    'end_time' => '19:00:00',
    'venue_name' => 'Riverside Park',
    'venue_address' => '200 Parkview Dr, Springfield, IL',
    'description' => 'Bring water and cleats. This is a test invite.',
];
$recipient = ['type' => 'guardian', 'id' => 1, 'email' => $to];

// Render the real branded body via the private generator.
$m = new ReflectionMethod('CalendarInviteService', 'generateHTMLBody');
$m->setAccessible(true);
$html = $m->invoke($svc, $event, 'new', $recipient);

$titleM = new ReflectionMethod('CalendarInviteService', 'eventDisplayTitle');
$titleM->setAccessible(true);
$subject = "[TEST] You're invited: " . $titleM->invoke($svc, $event);

// Reuse the service's already-configured SendGrid mailer.
$mp = new ReflectionProperty('CalendarInviteService', 'mailer');
$mp->setAccessible(true);
$mailer = $mp->getValue($svc);
if (!$mailer) {
    fwrite(STDERR, "mailer not initialized (running in test mode?)\n");
    exit(1);
}

try {
    $mailer->addAddress($to);
    $mailer->Subject = $subject;
    $mailer->isHTML(true);
    $mailer->Body = $html;
    $mailer->send();
    echo "SENT to {$to} as club {$clubId}: {$subject}\n";
} catch (Throwable $e) {
    fwrite(STDERR, "SEND FAILED: " . $e->getMessage() . "\n");
    exit(1);
}
