-- 052_program_season_fields.sql
--
-- Add three columns the application has always written to and which have never
-- existed. Unlike the other phantom-column fixes in this pass, these are not
-- misnamed versions of existing columns — the data has nowhere to go at all, so
-- the columns are added rather than the code stripped.
--
--   programs.season_year   filtered on, ordered by, inserted and updated in
--                          legacy/programs-gateway.php
--   programs.is_recurring  inserted and updated in the same file
--   seasons.created_by     inserted by controllers/SeasonController.php
--
-- Every one of those statements raised 42703 and was swallowed, so program
-- create/update has been failing silently.
--
-- seasons.is_active, teams.updated_by/last_modified_at, team_audit_log's
-- per-field columns and athlete_guardians.active_status are deliberately NOT
-- added here — each already has a real equivalent (status, updated_at, the
-- generic audit shape, and outright deletion), so the code was corrected instead.
--
-- Safe to re-run.

ALTER TABLE programs ADD COLUMN IF NOT EXISTS season_year  INTEGER;
ALTER TABLE programs ADD COLUMN IF NOT EXISTS is_recurring BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE seasons  ADD COLUMN IF NOT EXISTS created_by   INTEGER;

COMMENT ON COLUMN programs.season_year  IS 'Calendar year the program belongs to; used for filtering and ordering the program list.';
COMMENT ON COLUMN programs.is_recurring IS 'Whether this program repeats across seasons.';
COMMENT ON COLUMN seasons.created_by    IS 'users.id of the creator. No FK: historical rows predate the column and some creators may since have been removed.';

-- Programs are listed newest-season-first; without this the added ORDER BY
-- column would force a sort on every page load.
CREATE INDEX IF NOT EXISTS programs_season_year_idx ON programs (season_year DESC);
