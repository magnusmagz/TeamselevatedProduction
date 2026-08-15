<?php
/**
 * Lite support ticketing.
 *
 * Two actions, and that is the whole surface:
 *   create      POST  file a ticket (+ optional screenshot), post it to Slack
 *   attachment  GET   serve a screenshot by signed token, unauthenticated
 *
 * There is no list, no assignment, no status workflow. **Slack is the queue.**
 * See SCOPE-Support-Tickets.md for the decisions behind that.
 *
 * AUTH NOTE — `create` is deliberately reachable without a token.
 * The single most valuable bug report is "I can't sign in", and requiring auth
 * makes exactly that report impossible to file. Anonymous tickets carry no
 * user_id, are rate limited by IP, and are labelled unverified in Slack so
 * nobody treats the name in them as identity. When a valid token IS present the
 * reporter is resolved from it and never from the request body — otherwise
 * anyone could file a ticket "as" someone else.
 */

header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/JWT.php';
require_once __DIR__ . '/../lib/Slack.php';
require_once __DIR__ . '/../lib/support_tickets.php';

$db = Database::getInstance()->getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

function supportBadRequest(string $msg, string $reason = 'invalid'): void {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $msg, 'reason' => $reason]);
    exit;
}

try {
    switch ($action) {
        case 'create':
            if ($method !== 'POST') { supportBadRequest('POST required', 'method'); }
            handleCreateTicket($db);
            break;

        case 'attachment':
            if ($method !== 'GET') { supportBadRequest('GET required', 'method'); }
            handleServeAttachment($db, $_GET['token'] ?? '');
            break;

        default:
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Action not found']);
    }
} catch (Throwable $e) {
    error_log('support-gateway: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not file the ticket']);
}

/**
 * Resolve the reporter from the Authorization header, if there is one.
 *
 * Returns nulls for an absent OR invalid token rather than 401ing: a user whose
 * session just expired is precisely the person who needs to report a problem.
 */
function supportResolveReporter($db): array
{
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $m)) {
        return ['user_id' => null, 'name' => null, 'email' => null, 'club_id' => null];
    }

    $payload = JWT::verify(trim($m[1]));
    if (!$payload || empty($payload->user_id)) {
        return ['user_id' => null, 'name' => null, 'email' => null, 'club_id' => null];
    }

    $userId = (int) $payload->user_id;
    $stmt = $db->prepare('SELECT id, first_name, last_name, email FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
        return ['user_id' => null, 'name' => null, 'email' => null, 'club_id' => null];
    }

    $clubId = null;
    if (isset($payload->active_context) && is_object($payload->active_context)
        && ($payload->active_context->scope_type ?? null) === 'club') {
        $clubId = (int) ($payload->active_context->scope_id ?? 0) ?: null;
    }

    return [
        'user_id' => (int) $u['id'],
        'name'    => trim($u['first_name'] . ' ' . $u['last_name']),
        'email'   => $u['email'],
        'club_id' => $clubId,
    ];
}

function handleCreateTicket($db): void
{
    $input = json_decode(file_get_contents('php://input'), true) ?: [];

    $description = trim((string) ($input['description'] ?? ''));
    if ($description === '') {
        supportBadRequest('Please describe what went wrong', 'description_required');
    }
    if (mb_strlen($description) > TE_SUPPORT_MAX_DESCRIPTION) {
        $description = mb_substr($description, 0, TE_SUPPORT_MAX_DESCRIPTION);
    }

    $reporter = supportResolveReporter($db);
    $ip = te_support_client_ip();

    // Rate limit. Per user when we know them, per IP when we don't — an
    // unauthenticated endpoint that writes rows needs a ceiling.
    if (te_support_is_rate_limited($db, $reporter['user_id'], $ip)) {
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'reason'  => 'rate_limited',
            'error'   => 'You have filed several reports just now — give us a few minutes to read them.',
        ]);
        return;
    }

    // An anonymous reporter may tell us who they are; it is stored as a claim,
    // never as identity. A signed-in reporter's own details always win.
    $name  = $reporter['name']  ?? (trim((string) ($input['reporter_name'] ?? '')) ?: null);
    $email = $reporter['email'] ?? (trim((string) ($input['reporter_email'] ?? '')) ?: null);

    $device = is_array($input['device_info'] ?? null) ? $input['device_info'] : [];
    $device['server_time'] = gmdate('c');

    $attachment = null;
    if (!empty($input['screenshot'])) {
        $attachment = te_support_decode_attachment(
            (string) $input['screenshot'],
            (string) ($input['screenshot_name'] ?? 'screenshot')
        );
        if (isset($attachment['error'])) {
            supportBadRequest($attachment['error'], $attachment['reason']);
        }
    }

    $db->beginTransaction();
    try {
        $stmt = $db->prepare('
            INSERT INTO support_tickets
                (user_id, club_id, reporter_name, reporter_email, description,
                 page_url, device_info, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            RETURNING id
        ');
        $stmt->execute([
            $reporter['user_id'],
            $reporter['club_id'],
            $name,
            $email,
            $description,
            substr((string) ($input['page_url'] ?? ''), 0, 500) ?: null,
            json_encode($device),
            $ip,
        ]);
        $ticketId = (int) $stmt->fetchColumn();

        $token = null;
        if ($attachment) {
            $token = bin2hex(random_bytes(24));
            $stmt = $db->prepare('
                INSERT INTO support_ticket_attachments
                    (ticket_id, filename, mime_type, byte_size, data, token, expires_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW() + INTERVAL \'' . TE_SUPPORT_LINK_TTL_DAYS . ' days\')
            ');
            $stmt->execute([
                $ticketId,
                $attachment['filename'],
                $attachment['mime'],
                $attachment['size'],
                $attachment['base64'],
                $token,
            ]);
        }

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    // Slack comes AFTER the commit and never affects the response. A failed post
    // is logged and swallowed — see lib/Slack.php.
    $clubName = null;
    if ($reporter['club_id']) {
        $s = $db->prepare('SELECT name FROM club_profile WHERE id = ?');
        $s->execute([$reporter['club_id']]);
        $clubName = $s->fetchColumn() ?: null;
    }

    $screenshotUrl = $token
        ? rtrim(Env::get('APP_URL', ''), '/') . '/api/support-gateway.php?action=attachment&token=' . $token
        : null;

    te_slack_post(te_slack_support_ticket_payload([
        'id'             => $ticketId,
        'user_id'        => $reporter['user_id'],
        'reporter_name'  => $name,
        'reporter_email' => $email,
        'club_name'      => $clubName,
        'description'    => $description,
        'page_url'       => $input['page_url'] ?? null,
        'device_summary' => te_support_device_summary($device),
    ], $screenshotUrl));

    echo json_encode(['success' => true, 'ticket_id' => $ticketId]);
}

/**
 * Serve a screenshot by its signed token.
 *
 * Unauthenticated on purpose so the team can open it from Slack on a phone. The
 * token is 24 random bytes and expires; an expired or unknown token is a flat
 * 404 that says nothing about which of the two it was.
 */
function handleServeAttachment($db, string $token): void
{
    $token = trim($token);
    if ($token === '' || !preg_match('/^[a-f0-9]{48}$/', $token)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Not found']);
        return;
    }

    $stmt = $db->prepare('
        SELECT mime_type, data, filename
        FROM support_ticket_attachments
        WHERE token = ? AND expires_at > NOW()
        LIMIT 1
    ');
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'This link has expired']);
        return;
    }

    $bytes = base64_decode($row['data'], true);
    if ($bytes === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Attachment could not be read']);
        return;
    }

    // Replace the JSON content-type set at the top of this file.
    header('Content-Type: ' . $row['mime_type']);
    header('Content-Length: ' . strlen($bytes));
    header('Content-Disposition: inline; filename="' . basename((string) $row['filename']) . '"');
    // Private: this is someone's screenshot, and it may contain another family's
    // data. No shared-cache copies.
    header('Cache-Control: private, max-age=300');
    header('X-Content-Type-Options: nosniff');
    echo $bytes;
}
