<?php
/**
 * Twilio Inbound SMS Handler — auto-reply only.
 *
 * Families WILL reply to a broadcast ("Ava can't make practice"). Before this
 * existed, Twilio accepted those messages and discarded them: no webhook, no
 * record, no response. The parent got silence and assumed they'd been heard.
 *
 * This is the deliberately smallest honest answer: tell them the number isn't
 * monitored and point them at the parent portal, where chat already exists.
 * Nothing is stored — the reply text is not written to communication_log, which
 * is exactly what the outgoing message promises. If that promise changes, this
 * file changes with it.
 *
 * Deliberately NOT here (see docs/broadcast-sms-scope.md and
 * unified-messaging-scope.md for the tiers above this one):
 *   - storing the reply, forwarding it to an admin, or threading it
 *
 * No auth, matching api/webhooks/twilio-status.php. No X-Twilio-Signature check
 * either, and that is a decision rather than an omission: this endpoint reads
 * nothing, writes nothing, and returns a fixed string. A forged request gets XML
 * back and achieves nothing — only Twilio can turn TwiML into a message. Adding
 * validation would buy no security and introduce a silent failure mode where a
 * signing mismatch stops every auto-reply with nothing to show for it.
 */

require_once __DIR__ . '/../../config/env.php';

/**
 * Kept under 160 GSM-7 characters so each auto-reply is ONE billed segment.
 * Straight apostrophes only — a curly quote or an em dash forces the whole
 * message to UCS-2, where the limit collapses to 70 and this becomes 3 segments.
 */
const TE_SMS_AUTOREPLY =
    'Thanks for your message! This number is not monitored. '
  . 'Chat is in our new parent portal - check your email for an invite '
  . 'or ask your coach.';

/**
 * Keywords Twilio's own opt-out handling owns.
 *
 * Twilio still forwards these to the webhook, but blocks any outbound message to
 * that number afterwards. Auto-replying to STOP would therefore fail silently at
 * best; at worst it reads as ignoring an opt-out. HELP has its own carrier-mandated
 * response. In both cases: say nothing and let Twilio do its job.
 */
const TE_SMS_CARRIER_KEYWORDS = [
    'stop', 'stopall', 'unsubscribe', 'cancel', 'end', 'quit',
    'start', 'yes', 'unstop',
    'help', 'info',
];

$body = trim((string) ($_POST['Body'] ?? ''));
$to   = trim((string) ($_POST['To'] ?? ''));

// Normalize the way carriers actually send keywords: any case, often with
// trailing punctuation or whitespace.
$keyword = strtolower(trim($body, " \t\n\r\0\x0B.!?,"));
$isCarrierKeyword = in_array($keyword, TE_SMS_CARRIER_KEYWORDS, true);

header('Content-Type: text/xml; charset=UTF-8');
http_response_code(200);

if ($isCarrierKeyword) {
    // Empty TwiML = acknowledge, send nothing.
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<Response></Response>';
    exit;
}

// Operational signal only — no phone number, no message text. Enough to answer
// "is anyone actually replying?" when deciding whether to build the tier that
// forwards these to a human, without recording who said what.
error_log(sprintf('[twilio-inbound] auto-replied to an inbound SMS on %s', $to !== '' ? $to : 'unknown number'));

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
   . '<Response><Message>'
   . htmlspecialchars(TE_SMS_AUTOREPLY, ENT_XML1 | ENT_QUOTES, 'UTF-8')
   . '</Message></Response>';
