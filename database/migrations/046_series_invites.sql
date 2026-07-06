-- 046_series_invites.sql
-- RRULE-based calendar invites for recurring events (Phase 1).
-- A series row carries the iCal identity every ICS message about the series
-- must reference: one UID, one RRULE, one SEQUENCE counter. Invitation rows
-- in event_invitations are written against the series' first occurrence with
-- calendar_uid = the series UID.
-- Additive: safe to apply before the code deploy.

CREATE TABLE IF NOT EXISTS calendar_event_series (
    group_id VARCHAR(36) PRIMARY KEY,           -- = calendar_events.recurrence_group_id
    calendar_uid VARCHAR(120) NOT NULL,          -- iCal UID for all series ICS messages
    rrule VARCHAR(255),                          -- RRULE sent in the invite; NULL if none was sent
    ics_sequence INTEGER NOT NULL DEFAULT 0,     -- series-level iCal SEQUENCE
    invites_sent BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- Original slot of each occurrence exactly as the recurrence rule produced it
-- at creation. Editing an occurrence changes event_date/start_time but must
-- never change these — Phase 2's RECURRENCE-ID exceptions reference the
-- original slot, not the edited one.
ALTER TABLE calendar_events ADD COLUMN IF NOT EXISTS series_original_date DATE;
ALTER TABLE calendar_events ADD COLUMN IF NOT EXISTS series_original_time TIME;
