<?php
/**
 * Web push delivery.
 *
 * Phase 4 of docs/chat-notifications-scope.md. Push is the FIRST choice and
 * email is the fallback: see te_chat_dispatch_notifications().
 *
 * ⚠️ **On iPhone this only reaches a PWA the person has added to their home
 * screen.** Safari delivers web push to installed web apps only. Maggie was told
 * this and chose to build it anyway (2026-08-25) — do not re-raise it as a
 * blocker. It IS the reason email stays the fallback rather than being replaced,
 * and the reason the install prompt matters as much as this file does.
 *
 * ⚠️ **Subscriptions are disposable and MUST be pruned.** A push service answers
 * 404 or 410 when an endpoint dies — cleared site data, a reinstall, a new phone
 * — and there is no advance warning. Left in place, dead rows accumulate forever
 * and every send burns a request on each one.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

/** Is push configured at all? Absent keys is a normal state, not an error. */
function te_push_is_configured(): bool
{
    return Env::get('VAPID_PUBLIC_KEY', '') !== '' && Env::get('VAPID_PRIVATE_KEY', '') !== '';
}

/** The key the browser needs to subscribe. Public by design — it ships in the bundle. */
function te_push_public_key(): string
{
    return (string) Env::get('VAPID_PUBLIC_KEY', '');
}

/** Every device this person has enabled notifications on. */
function te_push_subscriptions_for_user(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = ? ORDER BY id'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Store a device's subscription.
 *
 * UPSERT on endpoint, because the browser hands back the SAME endpoint when the
 * same device re-subscribes. Inserting blindly would either violate the unique
 * constraint or, without one, grow a duplicate row per page load — and then
 * every notification would arrive two, three, four times.
 *
 * The user_id is updated too: a shared family tablet can legitimately move from
 * one account to another, and the newer sign-in is the correct owner.
 */
function te_push_save_subscription(
    PDO $pdo,
    int $userId,
    string $endpoint,
    string $p256dh,
    string $auth,
    ?string $userAgent = null
): void {
    $stmt = $pdo->prepare("
        INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth, user_agent)
        VALUES (:user_id, :endpoint, :p256dh, :auth, :user_agent)
        ON CONFLICT (endpoint) DO UPDATE SET
            user_id    = EXCLUDED.user_id,
            p256dh     = EXCLUDED.p256dh,
            auth       = EXCLUDED.auth,
            user_agent = EXCLUDED.user_agent
    ");
    $stmt->execute([
        ':user_id'    => $userId,
        ':endpoint'   => $endpoint,
        ':p256dh'     => $p256dh,
        ':auth'       => $auth,
        ':user_agent' => $userAgent,
    ]);
}

/** Remove one device. Scoped to the owner so an endpoint cannot be used to delete someone else's. */
function te_push_delete_subscription(PDO $pdo, int $userId, string $endpoint): void
{
    $stmt = $pdo->prepare('DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint = ?');
    $stmt->execute([$userId, $endpoint]);
}

/** Drop an endpoint the push service has told us is gone. Not owner-scoped: it is dead for everyone. */
function te_push_prune_subscription(PDO $pdo, string $endpoint): void
{
    $stmt = $pdo->prepare('DELETE FROM push_subscriptions WHERE endpoint = ?');
    $stmt->execute([$endpoint]);
}

/**
 * Push a payload to every device a user has.
 *
 * @param array $payload title / body / url — what the service worker renders.
 * @param array $opts    'sender' (callable) to substitute delivery in tests.
 * @return array{delivered:int,pruned:int,failed:int}
 *
 * `delivered` is "the push service accepted it", which is the strongest claim
 * available — whether a phone was on, or the person looked, is unknowable from
 * here. The caller uses it only to decide whether to ALSO send an email.
 */
function te_push_send_to_user(PDO $pdo, int $userId, array $payload, array $opts = []): array
{
    $out = ['delivered' => 0, 'pruned' => 0, 'failed' => 0];

    if (!te_push_is_configured()) {
        return $out;
    }

    $subscriptions = te_push_subscriptions_for_user($pdo, $userId);
    if (!$subscriptions) {
        return $out;
    }

    $sender = $opts['sender'] ?? function (array $row, array $payload): array {
        $webPush = new WebPush([
            'VAPID' => [
                'subject'    => Env::get('VAPID_SUBJECT', 'mailto:notifications@teamselevated.com'),
                'publicKey'  => Env::get('VAPID_PUBLIC_KEY', ''),
                'privateKey' => Env::get('VAPID_PRIVATE_KEY', ''),
            ],
        ]);

        $report = $webPush->sendOneNotification(
            Subscription::create([
                'endpoint'        => $row['endpoint'],
                'publicKey'       => $row['p256dh'],
                'authToken'       => $row['auth'],
                'contentEncoding' => 'aes128gcm',
            ]),
            json_encode($payload)
        );

        return [
            'success' => $report->isSuccess(),
            // 404/410. The library classifies this for us, which is worth using
            // rather than re-deriving from status codes.
            'expired' => $report->isSubscriptionExpired(),
            'reason'  => (string) $report->getReason(),
        ];
    };

    foreach ($subscriptions as $row) {
        // Per device. One dead endpoint must not stop the person's other devices
        // being reached, and must not escape into the worker's queue loop.
        try {
            $result = $sender($row, $payload);

            if (!empty($result['success'])) {
                $out['delivered']++;
                $stmt = $pdo->prepare('UPDATE push_subscriptions SET last_used_at = ? WHERE id = ?');
                $stmt->execute([
                    $opts['now'] ?? (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                    $row['id'],
                ]);
                continue;
            }

            if (!empty($result['expired'])) {
                te_push_prune_subscription($pdo, $row['endpoint']);
                $out['pruned']++;
                continue;
            }

            $out['failed']++;
        } catch (Throwable $e) {
            $out['failed']++;
            error_log('[ChatPush] endpoint failed: ' . $e->getMessage());
        }
    }

    return $out;
}
