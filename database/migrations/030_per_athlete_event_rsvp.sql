-- Migration 030: Per-athlete RSVP on calendar events
--
-- Problem: calendar_event_attendees.UNIQUE(event_id, user_id) means a guardian
-- with multiple athletes on the same team gets only ONE RSVP slot per event,
-- and the second athlete's RSVP overwrites the first.
--
-- Fix: add athlete_id column. Replace the unique constraint with two partial
-- unique indexes:
--   - For parent-on-behalf-of-athlete RSVPs (athlete_id IS NOT NULL),
--     uniqueness is (event_id, user_id, athlete_id).
--   - For coach/staff RSVPs from email invites (athlete_id IS NULL),
--     uniqueness stays (event_id, user_id).
--
-- Backfill: where a guardian RSVP unambiguously maps to exactly one of their
-- athletes on the event's team, set athlete_id. Ambiguous rows stay NULL —
-- the parent will need to re-RSVP since we can't infer which athlete.

-- Step 1: add column
ALTER TABLE calendar_event_attendees
    ADD COLUMN IF NOT EXISTS athlete_id INTEGER REFERENCES athletes(id) ON DELETE CASCADE;

CREATE INDEX IF NOT EXISTS idx_cea_athlete ON calendar_event_attendees(athlete_id);

-- Step 2: backfill where unambiguous
-- Only updates rows that have an rsvp_status set AND where the guardian has
-- exactly one athlete on a team associated with the event.
WITH candidates AS (
    SELECT DISTINCT
        cea.id AS attendee_id,
        a.id AS athlete_id
    FROM calendar_event_attendees cea
    JOIN calendar_event_teams cet ON cet.event_id = cea.event_id
    JOIN team_members tm ON tm.team_id = cet.team_id AND tm.athlete_id IS NOT NULL
    JOIN athletes a ON a.id = tm.athlete_id
    JOIN athlete_guardians ag ON ag.athlete_id = a.id
    JOIN guardians g ON g.id = ag.guardian_id
    JOIN users u ON u.email = g.email
    WHERE u.id = cea.user_id
      AND cea.athlete_id IS NULL
      AND cea.rsvp_status IS NOT NULL
),
unambiguous AS (
    SELECT attendee_id, MIN(athlete_id) AS athlete_id
    FROM candidates
    GROUP BY attendee_id
    HAVING COUNT(*) = 1
)
UPDATE calendar_event_attendees cea
SET athlete_id = u.athlete_id
FROM unambiguous u
WHERE cea.id = u.attendee_id;

-- Step 3: drop the old unique constraint (handles either auto-generated name)
DO $$
DECLARE
    con_name TEXT;
BEGIN
    SELECT c.conname INTO con_name
    FROM pg_constraint c
    JOIN pg_class t ON c.conrelid = t.oid
    WHERE t.relname = 'calendar_event_attendees'
      AND c.contype = 'u'
      AND pg_get_constraintdef(c.oid) ILIKE '%event_id, user_id)'
      AND pg_get_constraintdef(c.oid) NOT ILIKE '%athlete_id%'
    LIMIT 1;

    IF con_name IS NOT NULL THEN
        EXECUTE format('ALTER TABLE calendar_event_attendees DROP CONSTRAINT %I', con_name);
    END IF;
END $$;

-- Also drop any pre-existing unique INDEX on (event_id, user_id) without athlete_id
DROP INDEX IF EXISTS calendar_event_attendees_event_id_user_id_key;

-- Step 4: add the two partial unique indexes
CREATE UNIQUE INDEX IF NOT EXISTS idx_cea_unique_athlete_rsvp
    ON calendar_event_attendees (event_id, user_id, athlete_id)
    WHERE athlete_id IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS idx_cea_unique_user_rsvp
    ON calendar_event_attendees (event_id, user_id)
    WHERE athlete_id IS NULL;
