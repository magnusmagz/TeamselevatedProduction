<?php
/**
 * One-off: send the REAL parent-invite email to a test address so we can see how
 * it lands in a real inbox — branding, layout, and character encoding.
 *
 *   heroku run php scripts/send-parent-invite-test.php <to-email> [athlete-name]
 *
 * Renders lib/Email::sendParentInvite(), the same method api/auth-gateway.php
 * calls for a live invite, so the HTML is production's.
 *
 * SEND-ONLY — writes no DB rows and mints no real invite token. The "Set up your
 * account" button therefore points at a placeholder and is visual only; a
 * clickable invite has to come from the real endpoint against a real guardian.
 *
 * The recipient name deliberately carries an emoji, an em dash, a curly quote
 * and an accent, so any charset regression shows up immediately as mojibake
 * ("José" arriving as "JosÃ©"). See tests/php/MailerCharsetTest.php.
 */

require_once __DIR__ . '/../lib/Email.php';

$to = $argv[1] ?? '';
if (!$to) {
    fwrite(STDERR, "usage: php scripts/send-parent-invite-test.php <to-email> [athlete-name]\n");
    exit(1);
}
$athleteName = $argv[2] ?? 'José Muñoz-O’Brien';

// Non-ASCII on purpose — this is the charset canary.
$parentName = 'Renée D’Angelo — test 🎉';
$inviteLink = 'https://teams-elevated.netlify.app/parent-invite?token=PLACEHOLDER-VISUAL-ONLY';

$email = new Email();
$ok = $email->sendParentInvite($to, $parentName, $inviteLink, $athleteName);

echo ($ok ? 'SENT' : 'FAILED') . " parent invite to {$to}\n";
echo "  parent name: {$parentName}\n";
echo "  athlete:     {$athleteName}\n";
echo "  link:        placeholder (visual only)\n";
exit($ok ? 0 : 1);
