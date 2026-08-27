<?php
/**
 * The in-app notification centre.
 *
 * Phase 5 of docs/chat-notifications-scope.md.
 *
 * AUTHORIZATION
 * Every action reads the account from the verified token and never from the
 * request. There is no action here that names another user, and no "all users"
 * branch in lib/notification_centre.php for one to call — which is what stops
 * this becoming a way to read someone else's notifications. Marking read is
 * scoped by user_id AND id, so passing a stranger's notification id silently
 * affects nothing rather than clearing their unread.
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/notification_centre.php';
require_once __DIR__ . '/../lib/chat_notification_scope.php';

function te_notifications_fail(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

try {
    $pdo = Database::getInstance()->getConnection();
} catch (Exception $e) {
    error_log('notifications: DB connection failed: ' . $e->getMessage());
    te_notifications_fail(500, 'Database connection failed');
}

$auth = AuthMiddleware::requireAuth();
$userId = (int) $auth->getUserId();

if ($userId <= 0) {
    te_notifications_fail(401, 'Not signed in');
}

$action = $_GET['action'] ?? 'list';

$raw = file_get_contents('php://input');
$body = $raw ? (json_decode($raw, true) ?: []) : [];

switch ($action) {
    case 'list': {
        echo json_encode([
            'success'       => true,
            'notifications' => te_notify_list($pdo, $userId, [
                'limit'       => (int) ($_GET['limit'] ?? 30),
                'unread_only' => ($_GET['unread_only'] ?? '') === '1',
            ]),
            'unread_count'  => te_notify_unread_count($pdo, $userId),
        ]);
        break;
    }

    /** Just the number, for the bell. Kept separate so the badge is one indexed count. */
    case 'unread-count': {
        echo json_encode([
            'success'      => true,
            'unread_count' => te_notify_unread_count($pdo, $userId),
        ]);
        break;
    }

    case 'mark-read': {
        // Absent `ids` means "all", which is the "mark all read" button. An
        // EMPTY array is a different statement — it means the caller had
        // nothing to mark — and must not be treated as "everything".
        $ids = array_key_exists('ids', $body) && is_array($body['ids']) ? $body['ids'] : null;

        echo json_encode([
            'success'      => true,
            'marked'       => te_notify_mark_read($pdo, $userId, $ids),
            'unread_count' => te_notify_unread_count($pdo, $userId),
        ]);
        break;
    }

    /**
     * "I came back because of a notification."
     *
     * Called by the app when it opens a conversation carrying the `tec`
     * parameter from a notification link. Chat notifications deliberately carry
     * no tracking pixel (see lib/chat_notification_dispatcher.php), so this is
     * the only click signal — and unlike a pixel it covers PUSH too, and
     * measures a person acting rather than a mail client loading an image.
     *
     * The user comes from the token, never the request, so nobody can record a
     * click against somebody else and skew the numbers.
     */
    case 'record-click': {
        $conversationId = (int) ($body['conversation_id'] ?? 0);
        $channel = (string) ($body['channel'] ?? '');

        if ($conversationId <= 0) {
            te_notifications_fail(400, 'conversation_id is required');
        }

        // An unrecognised channel is not worth a 4xx — the parameter comes off a
        // URL a person may have edited or a mail client may have mangled, and
        // failing the page load over a metric would be the wrong trade.
        $recorded = te_chat_record_click($pdo, $userId, $conversationId, $channel);

        echo json_encode(['success' => true, 'recorded' => $recorded]);
        break;
    }

    default:
        te_notifications_fail(400, 'Unknown action');
}
