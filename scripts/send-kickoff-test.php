<?php
/**
 * One-off live test of the club-wide Season Kickoff send:
 *   heroku run php scripts/send-kickoff-test.php <to-email> [club_id]
 *
 * Builds two household recipients sharing <to-email> (John + Jane), runs the
 * SAME dedupe + merge-resolve + unresolved-tag guard the send-email endpoint
 * uses, then delivers the one combined email. Confirms {{recipient_first_name}}
 * resolves to "John & Jane" and {{club_name}} fills, against real deployed code.
 * Send-only; writes no DB rows.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/MergeFieldService.php';
require_once __DIR__ . '/../lib/NameFormatter.php';
require_once __DIR__ . '/../services/CalendarInviteService.php';

$to = $argv[1] ?? '';
$clubId = (int) ($argv[2] ?? 32);
if (!$to) { fwrite(STDERR, "usage: send-kickoff-test.php <to-email> [club_id]\n"); exit(1); }

$pdo = Database::getInstance()->getConnection();

// Load the Season Kickoff template (canonical platform id 12).
$t = $pdo->prepare("SELECT subject, html_output FROM email_templates WHERE id = 12");
$t->execute();
$tpl = $t->fetch(PDO::FETCH_ASSOC);
if (!$tpl) { fwrite(STDERR, "template 12 not found\n"); exit(1); }
$subject = $tpl['subject'];
$html    = $tpl['html_output'];

// Two people, one address, same last name -> "John & Jane Test".
$recipients = [
    ['name' => 'John Test', 'email' => $to, 'type' => 'guardian', 'id' => 0],
    ['name' => 'jane test', 'email' => $to, 'type' => 'guardian', 'id' => 0],
];

// Dedupe + combine (mirrors communications-gateway handleSendEmail).
$combine = !preg_match('/\{\{(athlete_|guardian_)/', $subject . $html);
$byEmail = [];
foreach ($recipients as $r) {
    $e = strtolower(trim($r['email']));
    if ($e === '') continue;
    if (!isset($byEmail[$e])) { $byEmail[$e] = $r; $byEmail[$e]['_people'] = []; }
    $nm = trim($r['name']);
    if ($nm !== '') $byEmail[$e]['_people'][mb_strtolower($nm)] = NameFormatter::splitName($nm);
}
$one = array_values($byEmail)[0];
$people = array_values($one['_people']);
$firstCombined = NameFormatter::combineFirstNames(array_column($people, 'first'));
$fullCombined  = NameFormatter::combineFullNames($people);

$svc = new MergeFieldService($pdo);
$ctx = [
    'club_profile_id'      => $clubId,
    'recipient_first_name' => $firstCombined,
    'recipient_name'       => $fullCombined,
];
$rSubject = $svc->resolveVariables($subject, $ctx);
$rHtml    = $svc->resolveVariables($html, $ctx, true);

// Guard: refuse to send if any {{tag}} survived.
$blob = $rSubject . ' ' . $rHtml;
if (preg_match_all('/\{\{[a-zA-Z0-9_]+\}\}/', $blob, $mm)) {
    fwrite(STDERR, "GUARD BLOCKED — unresolved: " . implode(', ', array_unique($mm[0])) . "\n");
    exit(1);
}

echo "combine=" . ($combine ? 'yes' : 'no') . " recipient_first_name=\"{$firstCombined}\"\n";
echo "subject: {$rSubject}\n";

// Deliver via the production mailer.
$cal = new CalendarInviteService($pdo);
$mp = new ReflectionProperty('CalendarInviteService', 'mailer');
$mp->setAccessible(true);
$mailer = $mp->getValue($cal);
try {
    $mailer->addAddress($to, $fullCombined);
    $mailer->Subject = $rSubject;
    $mailer->isHTML(true);
    $mailer->Body = $rHtml;
    $mailer->send();
    echo "SENT to {$to} (as \"{$fullCombined}\", club {$clubId})\n";
} catch (Throwable $e) {
    fwrite(STDERR, "SEND FAILED: " . $e->getMessage() . "\n");
    exit(1);
}
