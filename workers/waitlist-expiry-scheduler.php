<?php
/**
 * Waitlist Expiry Scheduler
 *
 * Finds waitlist offers whose acceptance deadline has passed without a
 * response, marks them expired, and cascades the spot to the next team
 * on each affected division's waitlist.
 *
 * Designed to run via Heroku Scheduler every 15 minutes. The cascade
 * still fires immediately on explicit decline (handled inside the
 * tournament-gateway response endpoint) — this scheduler only catches
 * silent timeouts.
 *
 * Usage: php workers/waitlist-expiry-scheduler.php
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/WaitlistService.php';
require_once __DIR__ . '/../services/TournamentNotificationService.php';

echo "[WaitlistExpiry] Checking for expired waitlist offers...\n";

try {
    $db = Database::getInstance()->getConnection();
    $waitlist = new WaitlistService($db);
    $notifications = new TournamentNotificationService($db);

    // Mark all due-for-expiry offers in one pass and get back the list of
    // (registration_id, division_id) tuples we just expired so we can
    // cascade-promote each affected division.
    $expired = $waitlist->expireDueOffers();

    $count = count($expired);
    echo "[WaitlistExpiry] Expired {$count} offer(s)\n";

    if ($count === 0) {
        echo "[WaitlistExpiry] Done\n";
        exit(0);
    }

    // Notify the expired teams (courtesy email — your offer expired, you
    // remain on the waitlist) and dedupe divisions for cascade so a
    // division doesn't get hit by promote-next twice in one run.
    $divisionsToCascade = [];
    foreach ($expired as $row) {
        $regId = $row['registration_id'];
        $divisionId = $row['division_id'];
        try {
            $notifications->notifyWaitlistOfferExpired($regId);
        } catch (\Throwable $e) {
            // Don't let a notification failure block cascading.
            error_log("[WaitlistExpiry] notifyWaitlistOfferExpired failed for reg {$regId}: " . $e->getMessage());
        }
        $divisionsToCascade[$divisionId] = true;
    }

    foreach (array_keys($divisionsToCascade) as $divisionId) {
        $promotedId = $waitlist->promoteNextWaitlist((int)$divisionId);
        if ($promotedId) {
            echo "[WaitlistExpiry] Promoted registration {$promotedId} in division {$divisionId}\n";
            try {
                // Actor user id is null here — this is the system acting,
                // not a person. NotificationService handles null actor.
                $notifications->notifyWaitlistOffer($promotedId, null);
            } catch (\Throwable $e) {
                error_log("[WaitlistExpiry] notifyWaitlistOffer failed for reg {$promotedId}: " . $e->getMessage());
            }
        } else {
            echo "[WaitlistExpiry] No eligible team to promote in division {$divisionId}\n";
        }
    }

    echo "[WaitlistExpiry] Done\n";
} catch (Exception $e) {
    echo "[WaitlistExpiry] Error: " . $e->getMessage() . "\n";
    exit(1);
}
