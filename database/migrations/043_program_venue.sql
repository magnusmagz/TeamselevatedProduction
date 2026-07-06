-- 043_program_venue.sql
-- Headline facility for a program (camp/clinic/tryout). Per-session location/venue
-- already exist on tryout_sessions and override this for multi-site events.
-- Additive + nullable: safe to apply before the code deploy.

ALTER TABLE programs ADD COLUMN IF NOT EXISTS venue_id INTEGER;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE constraint_name = 'programs_venue_fk' AND table_name = 'programs'
    ) THEN
        ALTER TABLE programs
            ADD CONSTRAINT programs_venue_fk FOREIGN KEY (venue_id)
            REFERENCES venues(id) ON DELETE SET NULL;
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_programs_venue_id ON programs(venue_id);
