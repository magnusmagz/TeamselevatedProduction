-- Migration 035: Pre-open waitlist flag
--
-- A team that submits the registration form BEFORE the tournament's
-- registration_open_date is parked as status='waitlisted' (reusing the existing
-- enum). This flag distinguishes those rows from the post-fill waitlist created
-- by migration 034 — pre-open rows must NOT be offered cascading slots, and
-- they're auto-promoted to status='pending' when registration officially opens
-- via the tournament-promote-pre-open-waitlist action.

ALTER TABLE tournament_registrations
    ADD COLUMN IF NOT EXISTS waitlist_pre_open BOOLEAN NOT NULL DEFAULT false;

CREATE INDEX IF NOT EXISTS idx_tournament_registrations_pre_open_waitlist
    ON tournament_registrations (tournament_id)
    WHERE waitlist_pre_open = true;

COMMENT ON COLUMN tournament_registrations.waitlist_pre_open IS
    'true = team submitted the registration form before registration_open_date. Promoted to status=pending when open date arrives.';
