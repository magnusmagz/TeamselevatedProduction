<?php
/**
 * Twilio Inbound SMS Handler — record the reply, then auto-reply.
 *
 * Families reply to broadcasts. Before 2026-07-30 Twilio accepted those messages
 * and discarded them; the parent got silence and assumed they'd been heard. The
 * first version of this file answered them but still stored nothing, so the seven
 * real replies to the Central Kansas broadcast lived only in Twilio's message log
 * — four of them families asking where their portal invite was, invisible to
 * everyone in the product.
 *
 * M1 of docs/sms-inbox-scope.md: replies are now written to communication_log with
 * direction='inbound' and a conversation_id, so the inbox has something to show.
 *
 * Capture is deliberately NOT behind `inbox_enabled`. Storing is not monitoring,
 * so the current "this number is not monitored" wording stays true for a club with
 * no inbox, and when the flag is switched on the inbox opens with real history
 * rather than empty. The copy changes in M4, with the ability to reply.
 *
 * Still NOT here: reading, threading UI, replying, forwarding.
 *
 * No auth, matching api/webhooks/twilio-status.php. Still no X-Twilio-Signature
 * check: a forged request can now write a log row, which is the one thing that
 * changed, but it cannot send a message, read anything, or reach a family. The
 * cost of a bad row is an admin deleting it; the cost of a signing mismatch is
 * every genuine reply silently vanishing. Revisit if the inbox ever drives an
 * automated action.
 */

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../lib/inbound_sms.php';

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
define('TE_SMS_CARRIER_KEYWORDS', array_merge(
    TE_SMS_OPT_OUT_KEYWORDS, TE_SMS_OPT_IN_KEYWORDS, TE_SMS_HELP_KEYWORDS
));

$body = trim((string) ($_POST['Body'] ?? ''));
$to   = trim((string) ($_POST['To'] ?? ''));

// Normalize the way carriers actually send keywords: any case, often with
// trailing punctuation or whitespace.
$keyword = strtolower(trim($body, " \t\n\r\0\x0B.!?,"));
$isCarrierKeyword = in_array($keyword, TE_SMS_CARRIER_KEYWORDS, true);

header('Content-Type: text/xml; charset=UTF-8');
http_response_code(200);

// Record first, answer second — but never let a storage problem cost the family
// their reply. Twilio retries on a non-200, which would re-send the auto-reply;
// losing a log row is the better of the two bad outcomes.
//
// Note this does NOT use Database::getInstance(): config/database.php `die()`s a
// JSON error on connection failure, which would emit that JSON instead of TwiML
// and take the auto-reply down with the database. Connecting directly keeps the
// failure catchable. It is the same DSN, read from the same Env.
//
// Carrier keywords are recorded too: STOP is a message a person sent, and M2
// turns that row into the opt-out record.
try {
    $pdo = new PDO(
        sprintf(
            'pgsql:host=%s;port=%s;dbname=%s;sslmode=require',
            Env::get('DB_HOST'),
            Env::get('DB_PORT', '5432'),
            Env::get('DB_NAME')
        ),
        Env::get('DB_USER'),
        Env::get('DB_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
    );
    $inboundId = te_record_inbound_sms($pdo, $_POST);
} catch (Throwable $e) {
    $pdo = null;
    error_log('[twilio-inbound] could not record inbound SMS: ' . $e->getMessage());
}

if ($isCarrierKeyword) {
    // Empty TwiML = acknowledge, send nothing.
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<Response></Response>';
    exit;
}

// Operational signal only — no phone number, no message text. Enough to answer
// "is anyone actually replying?" when deciding whether to build the tier that
// forwards these to a human, without recording who said what.
error_log(sprintf('[twilio-inbound] auto-replied to an inbound SMS on %s', $to !== '' ? $to : 'unknown number'));

// Record what we are about to send, so the thread shows the family's question AND
// the machine answer they already got. Best-effort: the reply matters more than
// the record of it.
if (isset($pdo) && $pdo instanceof PDO) {
    try {
        $club = te_resolve_inbound_club($pdo, $_POST['To'] ?? null);
        if ($club !== null) {
            te_record_auto_reply(
                $pdo,
                $club,
                te_resolve_inbound_sender($pdo, $club, $_POST['From'] ?? null),
                (string) ($_POST['From'] ?? ''),
                te_normalize_sms_phone($_POST['To'] ?? null) ?? (string) ($_POST['To'] ?? ''),
                TE_SMS_AUTOREPLY
            );
        }
    } catch (Throwable $e) {
        error_log('[twilio-inbound] could not record auto-reply: ' . $e->getMessage());
    }
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
   . '<Response><Message>'
   . htmlspecialchars(TE_SMS_AUTOREPLY, ENT_XML1 | ENT_QUOTES, 'UTF-8')
   . '</Message></Response>';
