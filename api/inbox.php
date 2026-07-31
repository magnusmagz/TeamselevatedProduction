<?php
/**
 * SMS Inbox — read side (M3 of docs/sms-inbox-scope.md).
 *
 * Threads of inbound replies and the messages in them. Replying is M4; unknown-
 * sender attach and mark-done are M5.
 *
 * A separate gateway rather than more weight on communications-gateway.php, which
 * is ~1900 lines and serves sending. This serves reading.
 *
 * Thread state is DERIVED, never stored. `communication_log.status` already means
 * queued/sent/delivered/failed; a second stored status would drift out of step
 * with the messages it describes. "Needs reply" is computed from the messages
 * themselves each time.
 */

if (defined('TE_INBOX_LIB_ONLY')) {
    require_once __DIR__ . '/../lib/inbound_sms.php';
    return;
}

require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/inbound_sms.php';

try {
    $db = Database::getInstance();
    $connection = $db->getConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

$action = $_GET['action'] ?? null;

try {
    $auth = AuthMiddleware::requireAuth();

    switch ($action) {
        case 'threads': handleInboxThreads($auth, $connection); break;
        case 'thread':  handleInboxThread($auth, $connection);  break;
        case 'read':    handleInboxRead($auth, $connection);    break;
        case 'reply':   handleInboxReply($auth, $connection);   break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action. Valid: threads, thread, read, reply']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    error_log('Inbox Gateway Error: ' . $e->getMessage());
}

/**
 * Club access + admin + the per-club flag, in that order.
 *
 * The flag is checked HERE and not only in the UI: hiding a nav item is not an
 * access control, and a club that has not enabled the inbox has not agreed to
 * anyone reading their families' replies in this surface.
 *
 * @return array{code:int,error:string}|null
 */
function inboxAuthError($auth, PDO $pdo, $clubProfileId): ?array
{
    if (!$clubProfileId) {
        return ['code' => 400, 'error' => 'club_profile_id is required'];
    }
    if (!$auth->canAccessClub($clubProfileId)) {
        return ['code' => 403, 'error' => 'Access denied to this club'];
    }
    // Reading families' inbound messages is a club-records action, like editing
    // crew — not a coaching one. A coach messaging their team is unaffected.
    if (!$auth->hasRole('club_admin', $clubProfileId, 'club')) {
        return ['code' => 403, 'error' => 'Only club admins can open the SMS inbox'];
    }

    $stmt = $pdo->prepare("
        SELECT 1 FROM sms_phone_numbers
        WHERE club_profile_id = ? AND user_id IS NULL AND is_active AND inbox_enabled
        LIMIT 1
    ");
    $stmt->execute([$clubProfileId]);
    if (!$stmt->fetchColumn()) {
        return ['code' => 404, 'error' => 'The SMS inbox is not enabled for this club'];
    }

    return null;
}

/**
 * One SQL expression, used by both the list and its counts, for "the newest
 * message that a human or a family actually sent".
 *
 * Auto-replies are excluded deliberately: they are outbound with user_id NULL, and
 * a robot answering does not clear the thread. Without this, every thread would
 * fall out of "needs reply" the instant the auto-reply was recorded.
 */
function inboxLatestHumanMessageSql(): string
{
    return "(cl.direction = 'inbound' OR cl.user_id IS NOT NULL)";
}

function handleInboxThreads($auth, PDO $pdo): void
{
    $clubProfileId = $_GET['club_profile_id'] ?? null;
    $filter = $_GET['filter'] ?? 'needs_reply';

    if ($err = inboxAuthError($auth, $pdo, $clubProfileId)) {
        http_response_code($err['code']);
        echo json_encode(['error' => $err['error']]);
        return;
    }

    $human = inboxLatestHumanMessageSql();

    // One pass: newest message per thread, whether the newest human-or-family
    // message was inbound, and how many inbound rows are unread.
    $sql = "
        WITH threads AS (
            SELECT
                cl.conversation_id,
                MAX(cl.created_at) AS last_at,
                COUNT(*) FILTER (WHERE cl.direction = 'inbound' AND cl.read_at IS NULL) AS unread,
                COUNT(*) FILTER (WHERE cl.direction = 'inbound') AS inbound_count,
                (ARRAY_AGG(cl.recipient_name ORDER BY cl.created_at DESC)
                   FILTER (WHERE cl.recipient_name IS NOT NULL))[1] AS contact_name,
                (ARRAY_AGG(cl.recipient_phone ORDER BY cl.created_at DESC))[1] AS contact_phone,
                (ARRAY_AGG(cl.recipient_type ORDER BY cl.created_at DESC))[1] AS contact_type,
                (ARRAY_AGG(cl.recipient_id ORDER BY cl.created_at DESC))[1] AS contact_id,
                (ARRAY_AGG(cl.athlete_id ORDER BY cl.created_at DESC)
                   FILTER (WHERE cl.athlete_id IS NOT NULL))[1] AS athlete_id,
                (ARRAY_AGG(cl.body ORDER BY cl.created_at DESC))[1] AS last_body,
                (ARRAY_AGG(cl.direction ORDER BY cl.created_at DESC)
                   FILTER (WHERE {$human}))[1] AS last_human_direction
            FROM communication_log cl
            WHERE cl.club_profile_id = ?
              AND cl.channel = 'sms'
              AND cl.conversation_id IS NOT NULL
            GROUP BY cl.conversation_id
        )
        SELECT t.*,
               (t.last_human_direction = 'inbound') AS needs_reply,
               a.first_name || ' ' || a.last_name AS athlete_name
        FROM threads t
        LEFT JOIN athletes a ON a.id = t.athlete_id
        WHERE t.inbound_count > 0
    ";

    // A thread with no inbound message is not a conversation, it is a broadcast.
    if ($filter === 'needs_reply') {
        $sql .= " AND t.last_human_direction = 'inbound'";
    } elseif ($filter === 'unread') {
        $sql .= " AND t.unread > 0";
    }
    $sql .= " ORDER BY t.last_at DESC LIMIT 200";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$clubProfileId]);
    $threads = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Counts for the rail, always across ALL threads regardless of the filter —
    // otherwise the badge changes meaning depending on what you are looking at.
    $countStmt = $pdo->prepare("
        WITH threads AS (
            SELECT cl.conversation_id,
                   COUNT(*) FILTER (WHERE cl.direction = 'inbound' AND cl.read_at IS NULL) AS unread,
                   COUNT(*) FILTER (WHERE cl.direction = 'inbound') AS inbound_count,
                   (ARRAY_AGG(cl.direction ORDER BY cl.created_at DESC)
                      FILTER (WHERE {$human}))[1] AS last_human_direction
            FROM communication_log cl
            WHERE cl.club_profile_id = ? AND cl.channel = 'sms' AND cl.conversation_id IS NOT NULL
            GROUP BY cl.conversation_id
        )
        SELECT COUNT(*) FILTER (WHERE inbound_count > 0) AS all_threads,
               COUNT(*) FILTER (WHERE inbound_count > 0 AND last_human_direction = 'inbound') AS needs_reply,
               COUNT(*) FILTER (WHERE unread > 0) AS unread
        FROM threads
    ");
    $countStmt->execute([$clubProfileId]);
    $counts = $countStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => [
            'threads' => array_map(function ($t) {
                return [
                    'conversation_id' => $t['conversation_id'],
                    'contact_name'    => $t['contact_name'],
                    'contact_phone'   => $t['contact_phone'],
                    'contact_type'    => $t['contact_type'],
                    'contact_id'      => $t['contact_id'] !== null ? (int) $t['contact_id'] : null,
                    'athlete_name'    => $t['athlete_name'],
                    'last_body'       => $t['last_body'],
                    'last_at'         => $t['last_at'],
                    'unread'          => (int) $t['unread'],
                    'needs_reply'     => (bool) $t['needs_reply'],
                ];
            }, $threads),
            'counts' => [
                'all'         => (int) ($counts['all_threads'] ?? 0),
                'needs_reply' => (int) ($counts['needs_reply'] ?? 0),
                'unread'      => (int) ($counts['unread'] ?? 0),
            ],
        ],
    ]);
}

function handleInboxThread($auth, PDO $pdo): void
{
    $clubProfileId  = $_GET['club_profile_id'] ?? null;
    $conversationId = $_GET['conversation_id'] ?? null;

    if ($err = inboxAuthError($auth, $pdo, $clubProfileId)) {
        http_response_code($err['code']);
        echo json_encode(['error' => $err['error']]);
        return;
    }
    if (!$conversationId) {
        http_response_code(400);
        echo json_encode(['error' => 'conversation_id is required']);
        return;
    }

    // club_profile_id in the WHERE is the scope check, not decoration: a
    // conversation_id from another club must return nothing rather than someone
    // else's family's messages.
    $stmt = $pdo->prepare("
        SELECT cl.id, cl.direction, cl.body, cl.status, cl.created_at, cl.read_at,
               cl.user_id, cl.from_number, cl.recipient_phone, cl.recipient_name,
               cl.recipient_type, cl.recipient_id, cl.athlete_id,
               u.first_name || ' ' || u.last_name AS sent_by,
               a.first_name || ' ' || a.last_name AS athlete_name
        FROM communication_log cl
        LEFT JOIN users u ON u.id = cl.user_id
        LEFT JOIN athletes a ON a.id = cl.athlete_id
        WHERE cl.club_profile_id = ? AND cl.conversation_id = ? AND cl.channel = 'sms'
        ORDER BY cl.created_at ASC, cl.id ASC
    ");
    $stmt->execute([$clubProfileId, $conversationId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        http_response_code(404);
        echo json_encode(['error' => 'Conversation not found']);
        return;
    }

    $messages = array_map(function ($r) {
        return [
            'id'        => (int) $r['id'],
            'direction' => $r['direction'],
            'body'      => $r['body'],
            'status'    => $r['status'],
            'created_at' => $r['created_at'],
            // Outbound with no user is the machine. Shown, and shown AS the
            // machine, so an admin never mistakes it for a colleague's answer.
            'automated' => $r['direction'] === 'outbound' && $r['user_id'] === null,
            'sent_by'   => $r['sent_by'],
        ];
    }, $rows);

    $last = end($rows);

    echo json_encode([
        'success' => true,
        'data' => [
            'conversation_id' => $conversationId,
            'contact' => [
                'name'       => $last['recipient_name'],
                'phone'      => $last['recipient_phone'],
                'type'       => $last['recipient_type'],
                'id'         => $last['recipient_id'] !== null ? (int) $last['recipient_id'] : null,
                'athlete_id' => $last['athlete_id'] !== null ? (int) $last['athlete_id'] : null,
                'athlete_name' => $last['athlete_name'],
            ],
            'sending_number' => $last['from_number'],
            'messages' => $messages,
        ],
    ]);
}

/**
 * Reply to a thread, as a text, from the club's own number.
 *
 * Goes through SmsSendService::queueSms rather than talking to Twilio here, so a
 * reply inherits everything already built and tested: per-club sender resolution,
 * the suppression/opt-out predicate, segment counting, from_number recording, the
 * Redis queue and its retries. A reply is an ordinary outbound message that
 * happens to carry a conversation_id — which queueSms now sets for every send.
 *
 * The auto-reply keeps firing on inbound regardless. A family that texts still
 * gets an immediate acknowledgement; this adds a human one after it.
 */
function handleInboxReply($auth, PDO $pdo): void
{
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $clubProfileId  = $data['club_profile_id'] ?? null;
    $conversationId = $data['conversation_id'] ?? null;
    $body           = trim((string) ($data['body'] ?? ''));

    if ($err = inboxAuthError($auth, $pdo, $clubProfileId)) {
        http_response_code($err['code']);
        echo json_encode(['error' => $err['error']]);
        return;
    }
    if (!$conversationId || $body === '') {
        http_response_code(400);
        echo json_encode(['error' => 'conversation_id and a message body are required']);
        return;
    }

    // Resolve the recipient FROM the thread, never from the request. Trusting a
    // client-supplied phone number here would let a crafted request send from the
    // club's number to anyone at all.
    $stmt = $pdo->prepare("
        SELECT recipient_phone, recipient_type, recipient_id, recipient_name, athlete_id
        FROM communication_log
        WHERE club_profile_id = ? AND conversation_id = ? AND channel = 'sms'
        ORDER BY created_at DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([$clubProfileId, $conversationId]);
    $contact = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$contact || empty($contact['recipient_phone'])) {
        http_response_code(404);
        echo json_encode(['error' => 'Conversation not found']);
        return;
    }

    require_once __DIR__ . '/../services/SmsSendService.php';
    $sms = new SmsSendService($pdo);

    try {
        $result = $sms->queueSms([
            'user_id'         => $auth->getUserId(),
            'club_profile_id' => $clubProfileId,
            'body'            => $body,
            'recipients'      => [[
                'phone'      => $contact['recipient_phone'],
                'name'       => $contact['recipient_name'],
                'type'       => $contact['recipient_type'] ?: 'user',
                'id'         => $contact['recipient_id'],
                'athlete_id' => $contact['athlete_id'],
            ]],
        ]);
    } catch (RuntimeException $e) {
        // The club has no configured sender. A configuration problem, not a fault.
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
        return;
    }

    // queueSms SKIPS a suppressed or opted-out recipient rather than failing, so a
    // reply to someone who texted STOP would otherwise report success and send
    // nothing. Say what actually happened.
    if (($result['queued'] ?? 0) === 0) {
        $detail = $result['skipped_details'][0]['detail'] ?? 'This contact cannot receive texts.';
        http_response_code(409);
        echo json_encode(['error' => $detail, 'skipped' => true]);
        return;
    }

    echo json_encode(['success' => true, 'data' => ['queued' => $result['queued']]]);
}

function handleInboxRead($auth, PDO $pdo): void
{
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $clubProfileId  = $data['club_profile_id'] ?? null;
    $conversationId = $data['conversation_id'] ?? null;

    if ($err = inboxAuthError($auth, $pdo, $clubProfileId)) {
        http_response_code($err['code']);
        echo json_encode(['error' => $err['error']]);
        return;
    }
    if (!$conversationId) {
        http_response_code(400);
        echo json_encode(['error' => 'conversation_id is required']);
        return;
    }

    // Only inbound rows carry read state — an outbound message has nobody on our
    // side to read it.
    $stmt = $pdo->prepare("
        UPDATE communication_log
        SET read_at = CURRENT_TIMESTAMP
        WHERE club_profile_id = ? AND conversation_id = ?
          AND direction = 'inbound' AND read_at IS NULL
    ");
    $stmt->execute([$clubProfileId, $conversationId]);

    echo json_encode(['success' => true, 'data' => ['marked_read' => $stmt->rowCount()]]);
}
