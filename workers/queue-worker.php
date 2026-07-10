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

echo "[Worker] Starting queue worker...\n";

$queue = RedisQueue::getInstance();
$db = Database::getInstance()->getConnection();
$emailService = new EmailSendService($db);
$smsService = new SmsSendService($db);
$importProcessor = ImportJobProcessor::buildDefault($db);
$calendarSyncService = new CalendarSyncService($db);

$queues = ['email_queue', 'sms_queue', 'import_queue', 'calendar_sync_queue'];

// Graceful shutdown via signals (SIGTERM from Heroku dyno manager)
$running = true;

// Throttle (unix seconds) for the orphaned-import-job reconciliation sweep below.
$lastImportSweep = 0;

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
                $stuck = $db->query(
                    "SELECT id FROM import_jobs
                     WHERE status = 'queued' AND started_at IS NULL
                       AND created_at < NOW() - INTERVAL '90 seconds'
                     ORDER BY id LIMIT 20"
                )->fetchAll(PDO::FETCH_COLUMN);
                foreach ($stuck as $stuckId) {
                    echo "[Worker] Recovering orphaned import job {$stuckId}\n";
                    $importProcessor->processJob(['job_id' => (int) $stuckId]);
                }
            } catch (Exception $e) {
                error_log("[Worker] import reconciliation error: " . $e->getMessage());
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

        // Dispatch to the appropriate service
        if ($fromQueue === 'email_queue') {
            $emailService->processJob($payload);
        } elseif ($fromQueue === 'sms_queue') {
            $smsService->processJob($payload);
        } elseif ($fromQueue === 'import_queue') {
            $importProcessor->processJob($payload);
        } elseif ($fromQueue === 'calendar_sync_queue') {
            $calendarSyncService->syncSubscription($payload['subscription_id']);
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
