<?php
/**
 * Register and remove this browser's web-push subscription.
 *
 * Phase 4 of docs/chat-notifications-scope.md.
 *
 * AUTHORIZATION
 * Everything here acts on the CALLER's own devices, and the user id comes from
 * the verified token — never from the request body. That is the whole access
 * model: there is no endpoint here that can name someone else. `unsubscribe`
 * deletes `WHERE user_id = ? AND endpoint = ?` for the same reason; an endpoint
 * string alone must not be able to remove another account's device.
 *
 * The one exception is `vapid-public-key`, which is unauthenticated on purpose.
 * The VAPID public key is public by definition — it ships inside the frontend
 * bundle and is sent to Google's and Mozilla's push services on every
 * subscribe. Serving it from here rather than baking it into the build means
 * there is one source of truth (the Heroku config var) and no Netlify build
 * variable to drift out of sync with it.
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/chat_push.php';

/** Emit a JSON error and stop. */
function te_push_fail(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

$action = $_GET['action'] ?? '';

// Served before auth — see the note above.
if ($action === 'vapid-public-key') {
    echo json_encode([
        'success'    => true,
        'configured' => te_push_is_configured(),
        'public_key' => te_push_public_key(),
    ]);
    exit;
}

try {
    $pdo = Database::getInstance()->getConnection();
} catch (Exception $e) {
    error_log('push-subscriptions: DB connection failed: ' . $e->getMessage());
    te_push_fail(500, 'Database connection failed');
}

$auth = AuthMiddleware::requireAuth();
$userId = (int) $auth->getUserId();

if ($userId <= 0) {
    te_push_fail(401, 'Not signed in');
}

$raw = file_get_contents('php://input');
$body = $raw ? (json_decode($raw, true) ?: []) : [];

switch ($action) {
    case 'subscribe': {
        $endpoint = trim((string) ($body['endpoint'] ?? ''));
        $p256dh   = trim((string) ($body['keys']['p256dh'] ?? ''));
        $authKey  = trim((string) ($body['keys']['auth'] ?? ''));

        // All three are required together. A subscription missing either key can
        // only ever carry an empty notification, so storing it would create a
        // device that looks reachable and silently never shows anything.
        if ($endpoint === '' || $p256dh === '' || $authKey === '') {
            te_push_fail(400, 'endpoint and both keys are required');
        }

        te_push_save_subscription(
            $pdo,
            $userId,
            $endpoint,
            $p256dh,
            $authKey,
            substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)
        );

        echo json_encode(['success' => true]);
        break;
    }

    case 'unsubscribe': {
        $endpoint = trim((string) ($body['endpoint'] ?? ''));
        if ($endpoint === '') {
            te_push_fail(400, 'endpoint is required');
        }

        te_push_delete_subscription($pdo, $userId, $endpoint);
        echo json_encode(['success' => true]);
        break;
    }

    /** Does this account have any device registered? Drives the settings toggle. */
    case 'status': {
        echo json_encode([
            'success'    => true,
            'configured' => te_push_is_configured(),
            'devices'    => count(te_push_subscriptions_for_user($pdo, $userId)),
        ]);
        break;
    }

    default:
        te_push_fail(400, 'Unknown action');
}
