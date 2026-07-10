-- 047_venues_club_id.sql
-- Facilities (venues) had NO club ownership column, so every club saw every
-- facility (cross-club leak). Add club_id, backfill by usage where possible, and
-- assign the remainder to Teams Elevated (club_profile id 32) per owner decision
-- 2026-07-10. Idempotent.

ALTER TABLE venues ADD COLUMN IF NOT EXISTS club_id INTEGER;

-- Backfill only rows that don't yet have a club:
--   1) a team whose home field is this venue, else
--   2) a program using this venue, else
--   3) Teams Elevated (32).
UPDATE venues v SET club_id = COALESCE(
    (SELECT t.club_id FROM teams t    WHERE t.home_field_id = v.id AND t.club_id IS NOT NULL LIMIT 1),
    (SELECT p.club_id FROM programs p WHERE p.venue_id     = v.id AND p.club_id IS NOT NULL LIMIT 1),
    32
) WHERE v.club_id IS NULL;

CREATE INDEX IF NOT EXISTS idx_venues_club ON venues (club_id);

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'venues_club_id_fkey') THEN
        ALTER TABLE venues ADD CONSTRAINT venues_club_id_fkey
            FOREIGN KEY (club_id) REFERENCES club_profile(id) ON DELETE SET NULL;
    END IF;
END $$;
