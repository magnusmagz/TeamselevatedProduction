-- 050_emergency_contact_authorize_medical.sql
--
-- The athlete form's "Emergency & Medical" step has always asked whether each
-- emergency contact can authorize medical treatment, but emergency_contacts had
-- nowhere to put the answer. Add the column so the field round-trips.
--
-- Context: emergency contacts entered on the athlete form were never persisted at
-- all (the form did not send them, the gateway did not store them, and the only
-- code that tried used three column names this table does not have). This column
-- is the schema half of that fix.
--
-- Safe to re-run.

ALTER TABLE emergency_contacts
    ADD COLUMN IF NOT EXISTS can_authorize_medical BOOLEAN NOT NULL DEFAULT FALSE;

COMMENT ON COLUMN emergency_contacts.can_authorize_medical
    IS 'Whether this contact may authorize emergency medical treatment for the athlete.';

-- One row per athlete per priority slot, so a re-save replaces rather than
-- duplicates. Partial-free: priority_order is always set by the writer.
CREATE UNIQUE INDEX IF NOT EXISTS emergency_contacts_athlete_priority_idx
    ON emergency_contacts (athlete_id, priority_order);
