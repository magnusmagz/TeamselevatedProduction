<?php
/**
 * Calendar Sync Scheduler
 *
 * Finds active feed subscriptions due for sync and enqueues them.
 * Designed to run via Heroku Scheduler every 10 minutes.
 *
 * Usage: php workers/calendar-sync-scheduler.php
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/RedisQueue.php';

echo "[CalendarSync] Checking for subscriptions due for sync...\n";

try {
    $db = Database::getInstance()->getConnection();
    $queue = RedisQueue::getInstance();

    $stmt = $db->prepare("
        SELECT id, name
        FROM calendar_subscriptions
        WHERE is_active = true
          AND source_type = 'feed'
          AND feed_url IS NOT NULL
          AND (
              last_synced_at IS NULL
              OR last_synced_at < NOW() - (sync_interval_minutes || ' minutes')::interval
          )
    ");
    $stmt->execute();
    $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $count = count($subscriptions);
    echo "[CalendarSync] Found {$count} subscription(s) due for sync\n";

    foreach ($subscriptions as $sub) {
        $queue->push('calendar_sync_queue', [
            'id'              => uniqid('calsync_'),
            'subscription_id' => (int)$sub['id'],
        ]);
        echo "[CalendarSync] Queued sync for '{$sub['name']}' (ID {$sub['id']})\n";
    }

    echo "[CalendarSync] Done\n";
} catch (Exception $e) {
    echo "[CalendarSync] Error: " . $e->getMessage() . "\n";
    exit(1);
}
