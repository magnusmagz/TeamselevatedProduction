-- 054_athlete_jersey_size.sql
--
-- Add jersey size to the athlete record so uniform orders can be pulled from the
-- CRM instead of a side spreadsheet.
--
-- WHY ON athletes AND NOT team_members: jersey *number* is per-team (team_members
-- .jersey_number) because two teams assign numbers independently. Jersey *size* is
-- a body measurement — it does not change because a kid joins a second team, and
-- storing it per-membership would mean re-entering it on every roster add and then
-- reconciling disagreeing copies at order time.
--
-- WHY THE Y/A PREFIX: the stored codes are 'YM' / 'AM', never a bare 'M'. Youth
-- Medium and Adult Medium are wildly different garments, and an unprefixed size
-- column is the single most common cause of a mis-ordered uniform run. The prefix
-- makes every value unambiguous on its own, including in a CSV export handed to a
-- vendor.
--
-- The 12 values match the standard Nike / adidas club-kit ladder. AXS is included
-- deliberately: it is the real next step up from Youth XL for smaller teens, and
-- leaving it out forces staff to pick a size they know is wrong.
--
-- Safe to re-run.

ALTER TABLE athletes
    ADD COLUMN IF NOT EXISTS jersey_size VARCHAR(4);

-- NULL is always allowed — the size is genuinely unknown until someone asks the
-- family, and a blank must stay distinguishable from a guess. The application
-- normalizes '' to NULL before it reaches this constraint (lib/jersey_size.php).
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'athletes_jersey_size_check'
    ) THEN
        ALTER TABLE athletes
            ADD CONSTRAINT athletes_jersey_size_check CHECK (
                jersey_size IS NULL OR jersey_size IN (
                    'YXS', 'YS', 'YM', 'YL', 'YXL',
                    'AXS', 'AS', 'AM', 'AL', 'AXL', 'A2XL', 'A3XL'
                )
            );
    END IF;
END $$;

COMMENT ON COLUMN athletes.jersey_size IS
    'Uniform jersey size. Youth: YXS YS YM YL YXL. Adult: AXS AS AM AL AXL A2XL A3XL. Always Y/A-prefixed so a bare "M" can never be ambiguous. NULL = not yet collected. Canonical list: lib/jersey_size.php and frontend/src/utils/jerseySize.ts.';
