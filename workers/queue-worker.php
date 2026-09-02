<?php
/**
 * Long-running CLI queue worker for processing email and SMS jobs.
 *
 * Usage:
 *   php workers/queue-worker.php
 *
 * On Heroku, add to Procfile:
 *   worker: php workers/queue-worker.php
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/RedisQueue.php';
require_once __DIR__ . '/../services/EmailSendService.php';
require_once __DIR__ . '/../services/SmsSendService.php';
require_once __DIR__ . '/../services/ImportJobProcessor.php';
require_once __DIR__ . '/../services/CalendarSyncService.php';
require_once __DIR__ . '/../lib/chat_notification_dispatcher.php';
require_once __DIR__ . '/../lib/chat_moderation_alerts.php';
require_once __DIR__ . '/../lib/broadcast_dispatcher.php';

echo "[Worker] Starting queue worker...\n";

$queue = RedisQueue::getInstance();
$database = Database::getInstance();
$db = $database->getConnection();

/**
 * Every service holds the PDO handle it was constructed with. Neon's pooler
 * drops idle connections, so the boot-time handle dies overnight and PDO never
 * notices — which is why a quiet night used to leave the worker logging
 * "no connection to the server" once a minute until the dyno cycled, with any
 * job enqueued in that window failing three times into failed_jobs.
 *
 * Reconnecting is therefore only half the fix: a new PDO object does nothing
 * for services still pointing at the old one. They must be rebuilt together.
 */
$buildServices = function (PDO $db) {
    return [
        'email'    => new EmailSendService($db),
        'sms'      => new SmsSendService($db),
        'import'   => ImportJobProcessor::buildDefault($db),
        'calendar' => new CalendarSyncService($db),
    ];
};

$services = $buildServices($db);

/**
 * Call before ANY database work. Cheap when the connection is healthy (one
 * SELECT 1); rebuilds $db and every service when it is not.
 *
 * Throws PDOException if the database is genuinely unreachable. That is
 * deliberate: at the job site the existing catch turns it into a normal retry
 * with backoff, which is what should happen during a brief outage.
 */
$ensureDb = function () use ($database, $buildServices, &$db, &$services) {
    if ($database->ensureConnection()) {
        $db = $database->getConnection();
        $services = $buildServices($db);
        echo "[Worker] Database connection had dropped — reconnected, services rebuilt\n";
        error_log('[Worker] Neon connection was dead and has been re-established');
    }
};

$queues = ['email_queue', 'sms_queue', 'import_queue', 'calendar_sync_queue'];

// Graceful shutdown via signals (SIGTERM from Heroku dyno manager)
$running = true;

// Throttle (unix seconds) for the orphaned-import-job reconciliation sweep below.
$lastImportSweep = 0;

// Throttle for the chat-notification dispatch tick. A tick inside this worker
// rather than a new dyno — same reasoning as the scheduled-SMS scope: a separate
// scheduler process hits the cost wall that keeps calendar-sync-scheduler and
// waitlist-expiry-scheduler switched off.
$lastChatNotifySweep = 0;
$lastModAlertSweep = 0;

// Throttle for the scheduled-broadcast dispatch tick. Same reasoning again:
// docs/sms-scheduled-and-replies-scope.md Part 1 rules out a separate scheduler
// process because scheduled jobs do not yet justify a dyno.
$lastBroadcastSweep = 0;

/**
 * How often we look for chat messages to notify about.
 *
 * ⚠️ **This is the floor on push latency**, not the quiet period, which is now
 * zero. A push lands somewhere in 0..10s. Shortening this is what makes push
 * faster; truly instant needs the chat server to send it at message time
 * instead of a worker noticing afterwards.
 *
 * The query behind it is one indexed read over a short window, so 10s is cheap.
 */
const TE_CHAT_NOTIFY_TICK_SECONDS = 10;

/**
 * Moderation alerts stay on the slower cadence deliberately.
 *
 * That sweep does more work per pass (reports, then club admins per report) and
 * none of it is latency-sensitive — a flag reviewed 60 seconds later is no worse
 * than 10. Only chat notifications needed to get faster.
 */
const TE_CHAT_MOD_TICK_SECONDS = 60;

/**
 * How often we look for scheduled broadcasts whose time has come.
 *
 * This is the floor on how late a scheduled send can be under normal running: a
 * campaign due at 8:00:00 goes out somewhere in 8:00:00–8:00:30. Nobody schedules a
 * club broadcast to the second, and the query behind it is one indexed read of a
 * partial index over the handful of rows in status='scheduled', so there is no
 * reason to make it tighter or looser.
 *
 * It is NOT the staleness ceiling — that is TE_BROADCAST_MAX_LATENESS_SECONDS in
 * lib/broadcast_dispatcher.php, and it covers the case this throttle cannot: the
 * worker being down for hours.
 */
const TE_BROADCAST_TICK_SECONDS = 30;

if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);

    $shutdownHandler = function () use (&$running) {
        $running = false;
        echo "[Worker] Received shutdown signal, finishing current job...\n";
    };

    pcntl_signal(SIGTERM, $shutdownHandler);
    pcntl_signal(SIGINT, $shutdownHandler);
} else {
    echo "[Worker] pcntl not available — running without signal handling\n";
}

while ($running) {
    try {
        // Sweep retry queues — move any due jobs back to their main queues
        foreach ($queues as $q) {
            $queue->sweepRetries($q);
        }

        // Recover orphaned import jobs: rows enqueued to Redis but lost before a
        // worker consumed them (e.g. a worker/Redis restart between the upload and
        // processing) would otherwise sit in 'queued' forever. Re-drive any that are
        // still 'queued' after 90s. Single worker + processJob claiming the row
        // ('processing') makes this safe from double-processing. Throttled to 1/min.
        if (time() - $lastImportSweep > 60) {
            $lastImportSweep = time();
            try {
                $ensureDb();
                $stuck = $db->query(
                    "SELECT id FROM import_jobs
                     WHERE status = 'queued' AND started_at IS NULL
                       AND created_at < NOW() - INTERVAL '90 seconds'
                     ORDER BY id LIMIT 20"
                )->fetchAll(PDO::FETCH_COLUMN);
                foreach ($stuck as $stuckId) {
                    echo "[Worker] Recovering orphaned import job {$stuckId}\n";
                    $services['import']->processJob(['job_id' => (int) $stuckId]);
                }
            } catch (Exception $e) {
                error_log("[Worker] import reconciliation error: " . $e->getMessage());
            }
        }

        // Chat notifications: tell people about messages they have missed.
        //
        // ⚠️ The catch is not optional. This worker also drives email, SMS,
        // imports and calendar sync; an uncaught throw here stops all four. The
        // dispatcher already guards each individual send, so anything reaching
        // this handler is a failure of the whole sweep (a dead connection, a
        // missing table) and must still leave the queues running.
        if (time() - $lastChatNotifySweep > TE_CHAT_NOTIFY_TICK_SECONDS) {
            $lastChatNotifySweep = time();
            try {
                $ensureDb();
                $notified = te_chat_dispatch_notifications($db);
                // Count PUSHED and IN_APP too. The first version tested only
                // sent/failed, so a push — now the common case — printed nothing
                // at all and the log read as though the tick had done no work.
                // A delivery channel that leaves no trace is the one you cannot
                // debug at 11pm when a family says they got nothing.
                if ($notified['sent'] || $notified['pushed'] || $notified['in_app'] || $notified['failed']) {
                    echo sprintf(
                        "[Worker] chat notifications: %d pushed, %d emailed, %d in-app, %d failed, %d skipped\n",
                        $notified['pushed'],
                        $notified['sent'],
                        $notified['in_app'],
                        $notified['failed'],
                        $notified['skipped']
                    );
                }
                foreach ($notified['errors'] as $chatError) {
                    error_log('[Worker] chat notification error: ' . $chatError);
                }
            } catch (Throwable $e) {
                error_log('[Worker] chat notification sweep error: ' . $e->getMessage());
            }

        }

        // Moderation alerts: their own throttle AND their own catch. Sharing a
        // catch would mean a failure in the family-facing digests silently skips
        // the child-safety alerts, which is the wrong thing to couple together.
        if (time() - $lastModAlertSweep > TE_CHAT_MOD_TICK_SECONDS) {
            $lastModAlertSweep = time();
            try {
                $ensureDb();
                $modAlerts = te_chat_dispatch_moderation_alerts($db);
                if ($modAlerts['alerts_sent'] || $modAlerts['digests_sent'] || $modAlerts['failed']) {
                    echo sprintf(
                        "[Worker] moderation alerts: %d high-severity, %d digests, %d failed\n",
                        $modAlerts['alerts_sent'],
                        $modAlerts['digests_sent'],
                        $modAlerts['failed']
                    );
                }
                foreach ($modAlerts['errors'] as $modError) {
                    error_log('[Worker] moderation alert error: ' . $modError);
                }
            } catch (Throwable $e) {
                error_log('[Worker] moderation alert sweep error: ' . $e->getMessage());
            }
        }

        // Scheduled broadcasts: send the campaigns whose time has come.
        //
        // ⚠️ Its own catch, and the dispatcher catches per campaign inside that.
        // Both layers are load-bearing. A club that cleared its SMS number makes
        // queueSms throw a RuntimeException, and an uncaught throw here stops email,
        // SMS, imports and calendar sync along with it — one club's misconfiguration
        // taking down every queue for every club.
        //
        // Services come from $services, which $ensureDb() rebuilds through
        // $buildServices() when the Neon handle has died. Constructing an
        // EmailSendService or SmsSendService here instead would pin this tick to the
        // boot-time handle, so four queues would recover from an overnight drop and
        // this one silently would not.
        if (time() - $lastBroadcastSweep > TE_BROADCAST_TICK_SECONDS) {
            $lastBroadcastSweep = time();
            try {
                $ensureDb();
                $broadcasts = te_broadcast_dispatch_due(
                    $db,
                    $services['email'],
                    $services['sms'],
                    function (string $line) { echo "[Worker] {$line}\n"; }
                );
                if ($broadcasts['sent'] || $broadcasts['failed']) {
                    echo sprintf(
                        "[Worker] scheduled broadcasts: %d sent (%d queued, %d skipped), %d failed, %d too late\n",
                        $broadcasts['sent'],
                        $broadcasts['queued'],
                        $broadcasts['skipped'],
                        $broadcasts['failed'],
                        $broadcasts['stale']
                    );
                }
                foreach ($broadcasts['errors'] as $broadcastError) {
                    error_log('[Worker] scheduled broadcast error: ' . $broadcastError);
                }
            } catch (Throwable $e) {
                error_log('[Worker] scheduled broadcast sweep error: ' . $e->getMessage());
            }
        }

        // Block-pop from both queues (2 second timeout so we can check $running)
        $job = $queue->pop($queues, 2);

        if ($job === null) {
            continue;
        }

        list($fromQueue, $payload) = $job;
        $payload['attempts'] = ($payload['attempts'] ?? 0) + 1;

        echo "[Worker] Processing job {$payload['id']} from {$fromQueue} (attempt {$payload['attempts']})\n";

        // A job may be the first database work in hours. Verify the handle before
        // handing it to a service, or the send fails on a dead connection and
        // burns a retry attempt for a reason that has nothing to do with the job.
        $ensureDb();

        // Dispatch to the appropriate service
        if ($fromQueue === 'email_queue') {
            $services['email']->processJob($payload);
        } elseif ($fromQueue === 'sms_queue') {
            $services['sms']->processJob($payload);
        } elseif ($fromQueue === 'import_queue') {
            $services['import']->processJob($payload);
        } elseif ($fromQueue === 'calendar_sync_queue') {
            $services['calendar']->syncSubscription($payload['subscription_id']);
        } else {
            echo "[Worker] Unknown queue: {$fromQueue}, skipping\n";
            continue;
        }

        echo "[Worker] Job {$payload['id']} completed\n";

    } catch (Exception $e) {
        error_log("[Worker] Job failed: " . $e->getMessage());

        // Guard against undefined $payload (e.g. if pop succeeded but decode failed)
        if (!isset($payload) || !is_array($payload)) {
            echo "[Worker] Could not determine job payload for retry\n";
            continue;
        }

        $maxAttempts = $payload['max_attempts'] ?? 3;

        if (($payload['attempts'] ?? 0) < $maxAttempts) {
            // Exponential backoff: 30s, 120s, 300s
            $delays = [30, 120, 300];
            $delayIndex = min(($payload['attempts'] ?? 1) - 1, count($delays) - 1);
            $delay = $delays[$delayIndex];

            $queue->retry($fromQueue, $payload, $delay);
            echo "[Worker] Job {$payload['id']} scheduled for retry in {$delay}s\n";
        } else {
            $queue->fail($payload, $e->getMessage());
            echo "[Worker] Job {$payload['id']} permanently failed after {$maxAttempts} attempts\n";
            // TODO: Create in-app notification for the sender
        }
    }
}

echo "[Worker] Worker stopped.\n";
