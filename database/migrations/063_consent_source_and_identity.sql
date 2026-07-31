-- 063_consent_source_and_identity.sql
--
-- Let a consent record say WHERE it came from, and WHO gave it, without
-- depending on a user account that may not exist.
--
-- THE PROBLEM THIS SOLVES
-- The public registration form asks the parent for both COPPA consents and sends
-- the answers (`consent_data_collection`, `consent_medical_data`).
-- registrations-api.php has never read them, so a real parent's real consent has
-- been discarded at the exact moment with the most legal weight: consent at the
-- point of collection. A family that registers and never opens the parent portal
-- currently has no consent record at all.
--
-- It could not simply be written, because `consent_records.guardian_id` is
-- `NOT NULL REFERENCES users (id)` and public registration creates a `guardians`
-- row but NO user account. There is nothing to point at when the consent happens.
--
-- WHY guardian_id BECOMES NULLABLE
-- This is the one place this migration relaxes an existing constraint rather than
-- only adding to it. It is deliberate: requiring an account to record consent
-- means consent can only be captured from people who already have accounts, which
-- excludes every parent at the moment they sign their child up. The FK stays, so a
-- non-null value is still guaranteed to be a real user.
--
-- WHY THE IDENTITY IS COPIED IN, NOT JOINED
-- guardian_email / guardian_name freeze who consented at the time they consented.
-- A consent record is an evidentiary artifact, not a live relationship: if the
-- guardian row is later edited, merged, or the family's email changes, the record
-- of who agreed to what must not change with it. That is also why this does not
-- wait for the `user_guardians` link table — a join would give the CURRENT answer,
-- and the whole point is to preserve the answer as of the moment.
--
-- Safe to re-run.

-- Where the consent was captured. NULL means "recorded before this column
-- existed", which is knowable: every pre-existing row came from ConsentGate, so
-- the backfill below sets those to 'portal' rather than guessing at write time.
ALTER TABLE consent_records
    ADD COLUMN IF NOT EXISTS source VARCHAR(20);

-- Identity as recorded at the moment of consent. Deliberately plain text.
ALTER TABLE consent_records
    ADD COLUMN IF NOT EXISTS guardian_email VARCHAR(255);

ALTER TABLE consent_records
    ADD COLUMN IF NOT EXISTS guardian_name VARCHAR(255);

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'consent_records_source_check'
    ) THEN
        ALTER TABLE consent_records
            ADD CONSTRAINT consent_records_source_check CHECK (
                source IS NULL OR source IN ('registration', 'portal', 'staff')
            );
    END IF;
END $$;

-- A registering parent has no account yet — see the header.
ALTER TABLE consent_records
    ALTER COLUMN guardian_id DROP NOT NULL;

-- Every row that exists today was written by ConsentGate (api/consent.php
-- action=record), which is the only caller that has ever existed. Stamping them
-- rather than leaving NULL keeps "source IS NULL" meaningfully empty going
-- forward, so a NULL later is a bug and not a legacy row.
UPDATE consent_records SET source = 'portal' WHERE source IS NULL;

COMMENT ON COLUMN consent_records.source IS
    'Where the consent was captured: registration (public form, no account yet), portal (ConsentGate re-affirmation), staff.';
COMMENT ON COLUMN consent_records.guardian_email IS
    'Consenting adult''s email AS RECORDED at consent time. Frozen evidence — never refresh it from guardians/users.';
COMMENT ON COLUMN consent_records.guardian_id IS
    'Consenting adult''s user ACCOUNT, when they have one. NULL for consent captured at public registration, where no account exists yet; guardian_email/guardian_name carry the identity in that case.';
