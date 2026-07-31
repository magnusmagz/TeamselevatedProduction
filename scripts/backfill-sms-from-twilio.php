<?php
/**
 * Backfill SMS history from Twilio into communication_log.
 *
 * Inbound replies were discarded until M1 shipped on 2026-07-31, and auto-replies
 * were never recorded at all, so Twilio holds messages the product cannot show —
 * including the seven real replies to the 2026-07-30 Central Kansas broadcast, four
 * of them families asking where their portal invite was.
 *
 * Imports BOTH directions:
 *   inbound          — what families sent us
 *   outbound-reply   — TwiML auto-replies, which nothing here ever stored
 *   outbound-api     — our own sends; already in the table, skipped by SID
 *
 * Without the outbound half a thread reads as though nobody answered, which is the
 * same trap as hiding the auto-reply in the UI: an admin would write a reply
 * contradicting what the family already received.
 *
 * Idempotent on twilio_message_sid, so running it twice imports nothing twice.
 *
 * Deliberately does NOT replay carrier-keyword intent. Sarina Patrick texted STOP
 * then START on 2026-07-30; the database already reflects the net result, and
 * re-applying historical opt-outs risks suppressing someone who is currently
 * reachable. These are records, not instructions.
 *
 *   php scripts/backfill-sms-from-twilio.php            # dry run
 *   php scripts/backfill-sms-from-twilio.php --apply
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../lib/inbound_sms.php';

$apply = in_array('--apply', $argv, true);

$accountSid = Env::get('TWILIO_ACCOUNT_SID');
$authToken  = Env::get('TWILIO_AUTH_TOKEN');
if (!$accountSid || !$authToken) {
    fwrite(STDERR, "Twilio credentials are not configured.\n");
    exit(1);
}

$pdo = new PDO(
    sprintf('pgsql:host=%s;port=%s;dbname=%s;sslmode=require',
        Env::get('DB_HOST'), Env::get('DB_PORT', '5432'), Env::get('DB_NAME')),
    Env::get('DB_USER'),
    Env::get('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

/**
 * Every DISTINCT number, without a club attached.
 *
 * The club is resolved per message by te_resolve_inbound_club — the same function
 * the webhook uses — and NOT by reading club_profile_id off these rows. A number
 * legitimately appears under more than one club: setting a club's number
 * deactivates the previous row rather than deleting it, so a number moved between
 * clubs leaves history on both. Club 32 briefly held +17854654221 before it moved
 * to Central Kansas, and iterating rows attributed every Kansas family's reply to
 * club 32 — one club reading another's private messages, which is precisely what
 * the resolver's `ORDER BY is_active DESC` exists to prevent.
 */
$numbers = $pdo->query(
    "SELECT DISTINCT phone_number
     FROM sms_phone_numbers WHERE phone_number IS NOT NULL AND phone_number <> ''"
)->fetchAll(PDO::FETCH_COLUMN);

if (!$numbers) {
    fwrite(STDERR, "No club SMS numbers configured — nothing to back-fill.\n");
    exit(1);
}

function twilioGet(string $url, string $sid, string $tok): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_USERPWD => "{$sid}:{$tok}",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        throw new RuntimeException("Twilio returned HTTP {$code}");
    }
    return json_decode($body, true) ?: [];
}

$have = $pdo->prepare("SELECT 1 FROM communication_log WHERE twilio_message_sid = ? LIMIT 1");

$insert = $pdo->prepare("
    INSERT INTO communication_log (
        club_profile_id, user_id, channel, direction, recipient_type,
        recipient_id, recipient_phone, recipient_name, athlete_id,
        body, status, from_number, conversation_id, twilio_message_sid,
        sent_at, delivered_at, created_at
    ) VALUES (
        ?, NULL, 'sms', ?, ?,
        ?, ?, ?, ?,
        ?, ?, ?, ?, ?,
        ?, ?, ?
    )
");

$imported = 0; $skipped = 0; $unmatched = 0;
// A club can own several numbers, and Twilio's To/From passes overlap, so the same
// SID can surface twice in one run. The database check alone would not catch that
// during a dry run — nothing has been written yet — and the dry run is exactly
// where the count needs to be trustworthy.
$seen = [];

foreach ($numbers as $clubNumber) {
    $clubId = te_resolve_inbound_club($pdo, $clubNumber);
    if ($clubId === null) {
        fwrite(STDERR, "  no live club owns {$clubNumber} — skipping\n");
        continue;
    }

    // Two passes: messages TO this number (inbound) and FROM it (our sends and
    // auto-replies). Twilio filters on one side at a time.
    foreach (['To' => $clubNumber, 'From' => $clubNumber] as $param => $value) {
        $url = "https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json"
             . '?' . $param . '=' . urlencode($value) . '&PageSize=200';

        $messages = twilioGet($url, $accountSid, $authToken)['messages'] ?? [];

        foreach ($messages as $m) {
            $sid = $m['sid'] ?? null;
            if (!$sid) { continue; }

            if (isset($seen[$sid])) { continue; }
            $seen[$sid] = true;

            $have->execute([$sid]);
            if ($have->fetchColumn()) { $skipped++; continue; }

            $isInbound = str_starts_with((string) ($m['direction'] ?? ''), 'inbound');
            // The family is whichever end is not the club.
            $contactPhone = $isInbound ? ($m['from'] ?? null) : ($m['to'] ?? null);

            $sender = te_resolve_inbound_sender($pdo, $clubId, $contactPhone);
            if ($sender['id'] === null) { $unmatched++; }

            // Twilio's own timestamps, not now() — importing yesterday's replies
            // with today's clock would scramble every thread.
            $at = $m['date_sent'] ?? $m['date_created'] ?? null;
            $at = $at ? date('Y-m-d H:i:s', strtotime($at)) : date('Y-m-d H:i:s');

            $status = $isInbound
                ? 'delivered'
                : (in_array($m['status'] ?? '', ['delivered', 'sent', 'failed', 'undelivered'], true)
                    ? ($m['status'] === 'undelivered' ? 'failed' : $m['status'])
                    : 'sent');

            printf(
                "%s club=%-3d %-9s %-16s %s\n",
                $apply ? 'IMPORT' : '  would',
                $clubId,
                $isInbound ? 'inbound' : 'outbound',
                $sender['name'] ?? ($contactPhone ?? '?'),
                substr(str_replace("\n", ' ', (string) ($m['body'] ?? '')), 0, 52)
            );

            if ($apply) {
                $insert->execute([
                    $clubId,
                    $isInbound ? 'inbound' : 'outbound',
                    $sender['type'],
                    $sender['id'],
                    te_normalize_sms_phone($contactPhone) ?? $contactPhone,
                    $sender['name'],
                    $sender['athlete_id'],
                    (string) ($m['body'] ?? ''),
                    $status,
                    te_normalize_sms_phone($clubNumber) ?? $clubNumber,
                    te_sms_conversation_id($clubId, $contactPhone),
                    $sid,
                    $at, $at, $at,
                ]);
            }
            $imported++;
        }
    }
}

printf(
    "\n%s: %d message(s), %d already present, %d could not be matched to a person.\n",
    $apply ? 'Imported' : 'Dry run',
    $imported, $skipped, $unmatched
);
if (!$apply) {
    echo "Re-run with --apply to write them.\n";
}
