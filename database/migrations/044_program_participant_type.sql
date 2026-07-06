-- 044_program_participant_type.sql
-- Level 1 of coach/adult program registration: let a program register a coach or
-- other adult instead of an athlete-with-guardian. Additive + nullable → safe to
-- apply before the code deploy. (043 is program_venue; this is the next number.)

-- Who a program registers. 'athlete' preserves all existing behavior.
ALTER TABLE programs
  ADD COLUMN IF NOT EXISTS participant_type VARCHAR(20) NOT NULL DEFAULT 'athlete';

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE constraint_name = 'programs_participant_type_chk' AND table_name = 'programs'
    ) THEN
        ALTER TABLE programs
            ADD CONSTRAINT programs_participant_type_chk
            CHECK (participant_type IN ('athlete','coach','adult'));
    END IF;
END $$;

-- Denormalized identity for NON-athlete registrants (coach/adult). Athlete
-- registrations leave these NULL and resolve identity via athlete_id as before.
-- Enables dedup + admin display without a new person table, and is the backfill
-- source if/when Level 2 introduces a registrants table.
ALTER TABLE registrations ADD COLUMN IF NOT EXISTS registrant_first_name VARCHAR(100);
ALTER TABLE registrations ADD COLUMN IF NOT EXISTS registrant_last_name  VARCHAR(100);
ALTER TABLE registrations ADD COLUMN IF NOT EXISTS registrant_email      VARCHAR(255);

-- Dedup coach/adult registrations within a program (case-insensitive email).
CREATE INDEX IF NOT EXISTS idx_registrations_program_email
  ON registrations (program_id, lower(registrant_email))
  WHERE registrant_email IS NOT NULL;
