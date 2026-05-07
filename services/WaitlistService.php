<?php
/**
 * WaitlistService
 *
 * Auto-cascade for tournament waitlists. When an accepted spot opens (team
 * withdraws or is rejected after acceptance), the next-in-line waitlisted
 * registration gets emailed an offer with a 48-hour deadline. Decline /
 * no-response cascades to the next team automatically.
 *
 * State machine on tournament_registrations.waitlist_offer_state:
 *   none      → row is waitlisted but hasn't been offered a spot yet
 *   offered   → spot offered, waiting for response (waitlist_offer_expires_at)
 *   declined  → team explicitly declined
 *   expired   → deadline passed without response
 *
 * `status` flips to `accepted` only on accept; otherwise stays `waitlisted`
 * across decline/expiry so the row keeps its waitlist_position and can be
 * re-offered later if more spots open up. Director can manually override
 * via promote/skip in the registration manager.
 */
class WaitlistService {
    private $db;

    /**
     * Default acceptance window (hours). Overrideable per-call but most
     * callers should use the default so the experience is consistent.
     */
    const DEFAULT_OFFER_WINDOW_HOURS = 48;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Promote the next eligible waitlisted registration in this division.
     * Returns the registration_id that was offered, or null if no one was
     * eligible. The caller is responsible for triggering the offer email
     * (see TournamentNotificationService::notifyWaitlistOffer).
     *
     * Eligible = status='waitlisted' AND offer_state IN ('none','declined','expired')
     * AND tournament status hasn't progressed past 'registration_closed'
     * (no point offering a spot in a tournament that's already scheduling).
     */
    public function promoteNextWaitlist(int $divisionId, int $offerWindowHours = self::DEFAULT_OFFER_WINDOW_HOURS): ?int {
        // Skip if tournament has progressed past where new registrations make
        // sense. Director can still manually promote via promoteSpecific()
        // if they want to force it.
        $stopStatuses = ['scheduling', 'in_progress', 'weather_delay', 'completed', 'cancelled'];
        $tStmt = $this->db->prepare("
            SELECT t.status
            FROM tournament_divisions td
            JOIN tournaments t ON t.id = td.tournament_id
            WHERE td.id = ?
        ");
        $tStmt->execute([$divisionId]);
        $tournamentStatus = $tStmt->fetchColumn();
        if ($tournamentStatus !== false && in_array($tournamentStatus, $stopStatuses, true)) {
            return null;
        }

        // Find the lowest-position eligible row.
        $sel = $this->db->prepare("
            SELECT id
            FROM tournament_registrations
            WHERE division_id = ?
              AND status = 'waitlisted'
              AND waitlist_offer_state IN ('none', 'declined', 'expired')
            ORDER BY
                CASE WHEN waitlist_position IS NULL THEN 1 ELSE 0 END,
                waitlist_position,
                created_at,
                id
            LIMIT 1
        ");
        $sel->execute([$divisionId]);
        $registrationId = $sel->fetchColumn();
        if (!$registrationId) return null;

        return $this->markOffered((int)$registrationId, $offerWindowHours);
    }

    /**
     * Director-triggered: jump a specific row to the front of the line.
     * Used by the "Promote now" action on the waitlist tab. Bypasses the
     * tournament-status gate since the director has explicitly chosen to
     * offer this spot.
     */
    public function promoteSpecific(int $registrationId, int $offerWindowHours = self::DEFAULT_OFFER_WINDOW_HOURS): ?int {
        $row = $this->db->prepare("SELECT id, status FROM tournament_registrations WHERE id = ?");
        $row->execute([$registrationId]);
        $r = $row->fetch(PDO::FETCH_ASSOC);
        if (!$r || $r['status'] !== 'waitlisted') return null;
        return $this->markOffered($registrationId, $offerWindowHours);
    }

    /**
     * Mark a registration as offered. Generates a fresh token. Returns the
     * registration_id on success, null if something went sideways.
     */
    private function markOffered(int $registrationId, int $offerWindowHours): ?int {
        $token = bin2hex(random_bytes(24)); // 48 hex chars
        $up = $this->db->prepare("
            UPDATE tournament_registrations
            SET waitlist_offer_state = 'offered',
                waitlist_offered_at = NOW(),
                waitlist_offer_expires_at = NOW() + (INTERVAL '1 hour' * ?),
                waitlist_offer_token = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $up->execute([$offerWindowHours, $token, $registrationId]);
        return $up->rowCount() > 0 ? $registrationId : null;
    }

    /**
     * Token-based accept handler (called from the public response endpoint).
     * Returns ['ok' => true] on success or ['ok' => false, 'error' => '...']
     * with a user-facing reason. Race-safe: an `expires_at > NOW()` guard
     * inside the UPDATE prevents accepting an expired offer.
     */
    public function acceptByToken(string $token): array {
        $row = $this->db->prepare("
            SELECT id, division_id, waitlist_offer_state, waitlist_offer_expires_at
            FROM tournament_registrations
            WHERE waitlist_offer_token = ?
        ");
        $row->execute([$token]);
        $r = $row->fetch(PDO::FETCH_ASSOC);
        if (!$r) return ['ok' => false, 'error' => 'This link is no longer valid.'];

        if ($r['waitlist_offer_state'] === 'declined') {
            return ['ok' => false, 'error' => 'This offer was already declined.'];
        }
        if ($r['waitlist_offer_state'] === 'expired') {
            return ['ok' => false, 'error' => 'This offer has expired and the spot has gone to the next team on the waitlist.'];
        }
        if ($r['waitlist_offer_state'] !== 'offered') {
            return ['ok' => false, 'error' => 'This offer is no longer active.'];
        }

        // Guarded UPDATE: expires_at > NOW() inside the WHERE clause means
        // a cron expiry that fires concurrently can't beat the user's accept.
        $up = $this->db->prepare("
            UPDATE tournament_registrations
            SET status = 'accepted',
                waitlist_offer_state = 'none',
                waitlist_offer_token = NULL,
                waitlist_position = NULL,
                updated_at = NOW()
            WHERE id = ?
              AND waitlist_offer_state = 'offered'
              AND waitlist_offer_expires_at > NOW()
        ");
        $up->execute([$r['id']]);
        if ($up->rowCount() === 0) {
            return ['ok' => false, 'error' => 'This offer has just expired. The spot has gone to the next team.'];
        }

        return ['ok' => true, 'registration_id' => (int)$r['id'], 'division_id' => (int)$r['division_id']];
    }

    /**
     * Token-based decline handler. Marks state=declined and returns the
     * division_id so the caller can cascade. Idempotent — declining an
     * already-declined offer is a no-op success.
     */
    public function declineByToken(string $token): array {
        $row = $this->db->prepare("
            SELECT id, division_id, waitlist_offer_state
            FROM tournament_registrations
            WHERE waitlist_offer_token = ?
        ");
        $row->execute([$token]);
        $r = $row->fetch(PDO::FETCH_ASSOC);
        if (!$r) return ['ok' => false, 'error' => 'This link is no longer valid.'];

        if ($r['waitlist_offer_state'] === 'declined') {
            // Idempotent — already declined, treat as success and skip cascade.
            return ['ok' => true, 'registration_id' => (int)$r['id'], 'division_id' => (int)$r['division_id'], 'already' => true];
        }
        if (!in_array($r['waitlist_offer_state'], ['offered', 'expired'], true)) {
            return ['ok' => false, 'error' => 'This offer is no longer active.'];
        }

        $up = $this->db->prepare("
            UPDATE tournament_registrations
            SET waitlist_offer_state = 'declined',
                waitlist_offer_token = NULL,
                updated_at = NOW()
            WHERE id = ?
        ");
        $up->execute([$r['id']]);

        return ['ok' => true, 'registration_id' => (int)$r['id'], 'division_id' => (int)$r['division_id']];
    }

    /**
     * Cron entry point — find offers whose deadline has passed and mark
     * them expired. Returns an array of [registration_id, division_id]
     * tuples so the caller can cascade-promote each affected division.
     */
    public function expireDueOffers(): array {
        $rows = $this->db->prepare("
            SELECT id, division_id
            FROM tournament_registrations
            WHERE waitlist_offer_state = 'offered'
              AND waitlist_offer_expires_at IS NOT NULL
              AND waitlist_offer_expires_at <= NOW()
        ");
        $rows->execute();
        $affected = $rows->fetchAll(PDO::FETCH_ASSOC);
        if (empty($affected)) return [];

        $up = $this->db->prepare("
            UPDATE tournament_registrations
            SET waitlist_offer_state = 'expired',
                waitlist_offer_token = NULL,
                updated_at = NOW()
            WHERE id = ?
        ");
        foreach ($affected as $r) {
            $up->execute([$r['id']]);
        }
        return array_map(function ($r) {
            return ['registration_id' => (int)$r['id'], 'division_id' => (int)$r['division_id']];
        }, $affected);
    }

    /**
     * Assign a waitlist_position to a newly-waitlisted registration, slotted
     * after the current MAX in the division. Called from the
     * registration-create / status-update flow when status flips to
     * waitlisted.
     */
    public function assignNextPosition(int $registrationId, int $divisionId): void {
        $next = $this->db->prepare("
            SELECT COALESCE(MAX(waitlist_position), 0) + 1
            FROM tournament_registrations
            WHERE division_id = ? AND waitlist_position IS NOT NULL
        ");
        $next->execute([$divisionId]);
        $position = (int)$next->fetchColumn();
        $up = $this->db->prepare("
            UPDATE tournament_registrations
            SET waitlist_position = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $up->execute([$position, $registrationId]);
    }

    /**
     * Returns the waitlist-offer context block consumed by
     * TournamentNotificationService merge-field rendering. Pulls the
     * registration's display name + tournament + division context plus the
     * accept/decline URLs so the email template can fill them in.
     */
    public function getOfferContext(int $registrationId, string $appUrl): ?array {
        $stmt = $this->db->prepare("
            SELECT tr.id              AS registration_id,
                   tr.team_id,
                   tr.division_id,
                   tr.tournament_id,
                   tr.waitlist_offer_token,
                   tr.waitlist_offer_expires_at,
                   tr.waitlist_position,
                   COALESCE(NULLIF(tr.team_name_override, ''), t.name) AS team_name,
                   td.name              AS division_name,
                   td.age_group         AS division_age_group,
                   td.gender            AS division_gender,
                   tour.name            AS tournament_name,
                   tour.start_date      AS tournament_start_date,
                   tour.end_date        AS tournament_end_date,
                   tour.club_id         AS club_profile_id,
                   v.name               AS venue_name
            FROM tournament_registrations tr
            JOIN teams t                 ON t.id = tr.team_id
            JOIN tournament_divisions td ON td.id = tr.division_id
            JOIN tournaments tour        ON tour.id = tr.tournament_id
            LEFT JOIN venues v           ON v.id = tour.venue_id
            WHERE tr.id = ?
        ");
        $stmt->execute([$registrationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        $base = rtrim($appUrl, '/');
        $token = $row['waitlist_offer_token'];
        $row['accept_url']   = $token ? "{$base}/tournament-waitlist/respond?token={$token}&action=accept" : null;
        $row['decline_url']  = $token ? "{$base}/tournament-waitlist/respond?token={$token}&action=decline" : null;
        $row['offer_expires_at'] = $row['waitlist_offer_expires_at'];

        return $row;
    }
}
