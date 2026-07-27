<?php
/**
 * Public one-click RSVP from an invite email.
 *
 *   GET /api/event-rsvp.php?token=<signed>&r=yes|no|maybe
 *
 * The signed token authorizes an RSVP for an event by a guardian (all their
 * athletes on that event's team) or a single athlete. Writes into
 * calendar_event_attendees — the same table the in-app RSVP and coach views use.
 * Renders a branded confirmation page with one-tap options to change the answer.
 *
 * No login required (the token is the credential). GET is used so it works from
 * an email button; the page always shows the current answer + change options, so
 * an accidental/prefetch click is recoverable in one tap.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/RsvpToken.php';
require_once __DIR__ . '/../config/env.php';

$appUrl = rtrim(Env::get('APP_URL', 'https://teams-elevated.netlify.app'), '/');
$selfBase = rtrim(Env::get('BACKEND_URL', 'https://teamselevated-backend-0485388bd66e.herokuapp.com'), '/');

$RESPONSE_MAP = [
    'yes' => 'accepted', 'attending' => 'accepted', 'accepted' => 'accepted',
    'no' => 'declined', 'not_attending' => 'declined', 'declined' => 'declined',
    'maybe' => 'tentative', 'tentative' => 'tentative',
];
$LABEL = ['accepted' => 'Attending', 'declined' => 'Not attending', 'tentative' => 'Maybe'];

$token = $_GET['token'] ?? '';
$r = strtolower(trim($_GET['r'] ?? ''));

function renderPage(string $appUrl, string $title, string $bodyHtml): void {
    $safeTitle = htmlspecialchars($title, ENT_QUOTES);
    echo <<<HTML
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{$safeTitle}</title>
<style>
  body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;background:#f4f8f6;color:#17251f;margin:0;padding:0}
  .card{max-width:520px;margin:8vh auto;background:#fff;border:1px solid #dde7e2;border-radius:10px;overflow:hidden;box-shadow:0 10px 30px rgba(10,42,37,.12)}
  .head{background:#12443e;color:#fff;padding:22px 24px}
  .head h1{margin:0;font-size:20px}
  .body{padding:24px}
  .body p{margin:0 0 14px;line-height:1.55}
  .opts{display:flex;gap:10px;flex-wrap:wrap;margin-top:8px}
  .opt{display:inline-block;padding:10px 16px;border-radius:8px;text-decoration:none;font-weight:700;font-size:14px;border:1px solid #cdd8d2;color:#12443e;background:#fff}
  .opt.sel{background:#12443e;color:#fff;border-color:#12443e}
  .muted{color:#5a6b64;font-size:13px}
  .app{display:inline-block;margin-top:18px;color:#12443e;font-weight:700;text-decoration:none}
</style></head>
<body><div class="card"><div class="head"><h1>{$safeTitle}</h1></div><div class="body">{$bodyHtml}</div></div></body></html>
HTML;
}

try {
    $payload = $token ? RsvpToken::verify($token) : null;
    if (!$payload || empty($payload['e'])) {
        http_response_code(400);
        renderPage($appUrl, 'RSVP link invalid', '<p>This RSVP link is invalid or has expired. You can always RSVP inside the app.</p><a class="app" href="' . htmlspecialchars($appUrl, ENT_QUOTES) . '">Open Teams Elevated →</a>');
        exit;
    }

    $db = Database::getInstance()->getConnection();
    $eventId = (int) $payload['e'];

    // Event exists?
    $evStmt = $db->prepare("SELECT id, name, event_date FROM calendar_events WHERE id = ?");
    $evStmt->execute([$eventId]);
    $event = $evStmt->fetch(PDO::FETCH_ASSOC);
    if (!$event) {
        http_response_code(404);
        renderPage($appUrl, 'Event not found', '<p>We couldn\'t find that event — it may have been cancelled.</p>');
        exit;
    }
    $eventName = htmlspecialchars($event['name'], ENT_QUOTES);

    // Resolve the athlete(s) this token can RSVP for, on this event's teams.
    $email = null;
    $userId = null;
    if (!empty($payload['g'])) {
        $gStmt = $db->prepare("SELECT email FROM guardians WHERE id = ?");
        $gStmt->execute([(int) $payload['g']]);
        $email = $gStmt->fetchColumn() ?: null;
        $aStmt = $db->prepare("
            SELECT DISTINCT a.id, a.first_name, a.last_name
            FROM athlete_guardians ag
            JOIN athletes a ON a.id = ag.athlete_id
            JOIN team_members tm ON tm.athlete_id = a.id
            JOIN calendar_event_teams cet ON cet.team_id = tm.team_id
            WHERE ag.guardian_id = ? AND cet.event_id = ?
            ORDER BY a.first_name
        ");
        $aStmt->execute([(int) $payload['g'], $eventId]);
    } elseif (!empty($payload['a'])) {
        $aStmt = $db->prepare("
            SELECT DISTINCT a.id, a.first_name, a.last_name, a.email
            FROM athletes a
            JOIN team_members tm ON tm.athlete_id = a.id
            JOIN calendar_event_teams cet ON cet.team_id = tm.team_id
            WHERE a.id = ? AND cet.event_id = ?
        ");
        $aStmt->execute([(int) $payload['a'], $eventId]);
    } else {
        http_response_code(400);
        renderPage($appUrl, 'RSVP link invalid', '<p>This RSVP link is missing who it\'s for.</p>');
        exit;
    }
    $athletes = $aStmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($athletes)) {
        renderPage($appUrl, 'Nothing to RSVP', '<p>There are no athletes linked to this invite for <strong>' . $eventName . '</strong> anymore.</p>');
        exit;
    }
    if ($email === null && !empty($athletes[0]['email'])) {
        $email = $athletes[0]['email'];
    }
    if ($email) {
        $uStmt = $db->prepare("SELECT id FROM users WHERE lower(email) = lower(?) LIMIT 1");
        $uStmt->execute([$email]);
        $userId = $uStmt->fetchColumn() ?: null;
    }

    // Build the change-answer option links (same token, different r).
    $tokenQ = urlencode($token);
    $optLinks = '';
    foreach (['yes' => 'Attending', 'maybe' => 'Maybe', 'no' => "Can't make it"] as $rk => $rlabel) {
        $selStatus = $RESPONSE_MAP[$rk];
        $sel = (isset($status) && $status === $selStatus) ? ' sel' : '';
        $optLinks .= '<a class="opt' . $sel . '" href="' . $selfBase . '/api/event-rsvp.php?token=' . $tokenQ . '&r=' . $rk . '">' . $rlabel . '</a>';
    }

    // No/invalid response yet → just show the options (no write).
    if (!isset($RESPONSE_MAP[$r])) {
        $names = htmlspecialchars(implode(' & ', array_map(fn($a) => $a['first_name'], $athletes)), ENT_QUOTES);
        renderPage($appUrl, 'RSVP: ' . $event['name'],
            '<p>Will <strong>' . $names . '</strong> attend <strong>' . $eventName . '</strong>?</p>'
            . '<div class="opts">' . $optLinks . '</div>');
        exit;
    }

    // Write the RSVP for each athlete.
    $status = $RESPONSE_MAP[$r];
    foreach ($athletes as $a) {
        $aid = (int) $a['id'];
        if ($userId) {
            $up = $db->prepare("
                INSERT INTO calendar_event_attendees (event_id, user_id, athlete_id, email, rsvp_status, responded_at, created_at)
                VALUES (:e, :uid, :aid, :email, :st, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ON CONFLICT (event_id, user_id, athlete_id) WHERE athlete_id IS NOT NULL
                DO UPDATE SET rsvp_status = :st, responded_at = CURRENT_TIMESTAMP
            ");
            $up->execute(['e' => $eventId, 'uid' => $userId, 'aid' => $aid, 'email' => $email, 'st' => $status]);
        } else {
            $upd = $db->prepare("UPDATE calendar_event_attendees SET rsvp_status = :st, responded_at = CURRENT_TIMESTAMP
                                 WHERE event_id = :e AND athlete_id = :aid AND lower(email) = lower(:email) AND user_id IS NULL");
            $upd->execute(['e' => $eventId, 'aid' => $aid, 'email' => $email, 'st' => $status]);
            if ($upd->rowCount() === 0) {
                $ins = $db->prepare("INSERT INTO calendar_event_attendees (event_id, user_id, athlete_id, email, rsvp_status, responded_at, created_at)
                                     VALUES (:e, NULL, :aid, :email, :st, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
                $ins->execute(['e' => $eventId, 'aid' => $aid, 'email' => $email, 'st' => $status]);
            }
        }
    }

    // Re-render options with the just-saved answer highlighted.
    $optLinks = '';
    foreach (['yes' => 'Attending', 'maybe' => 'Maybe', 'no' => "Can't make it"] as $rk => $rlabel) {
        $sel = ($RESPONSE_MAP[$rk] === $status) ? ' sel' : '';
        $optLinks .= '<a class="opt' . $sel . '" href="' . $selfBase . '/api/event-rsvp.php?token=' . $tokenQ . '&r=' . $rk . '">' . $rlabel . '</a>';
    }
    $names = htmlspecialchars(implode(' & ', array_map(fn($a) => $a['first_name'], $athletes)), ENT_QUOTES);
    renderPage($appUrl, 'Thanks — RSVP saved',
        '<p>Got it. <strong>' . $names . '</strong> marked as <strong>' . $LABEL[$status] . '</strong> for <strong>' . $eventName . '</strong>.</p>'
        . '<p class="muted">Wrong answer? Tap to change:</p>'
        . '<div class="opts">' . $optLinks . '</div>'
        . '<a class="app" href="' . htmlspecialchars($appUrl, ENT_QUOTES) . '/parent/schedule/rsvp/' . $eventId . '">Manage in the app →</a>');
} catch (Throwable $e) {
    error_log('event-rsvp error: ' . $e->getMessage());
    http_response_code(500);
    renderPage($appUrl, 'Something went wrong', '<p>We couldn\'t save your RSVP just now. Please try again, or RSVP in the app.</p>');
}
