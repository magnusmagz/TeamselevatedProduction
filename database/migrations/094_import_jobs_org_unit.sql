-- ============================================================================
-- 094_import_jobs_org_unit.sql — GOTR G6: a multi-council import is scoped to an
-- org unit, not to one club
-- ============================================================================
-- A national or division admin uploads ONE coach roster carrying a
-- `council_code` column, and each row is attached to the club under their org
-- unit whose org_units.external_code matches. The job row therefore needs to
-- remember which org unit authorised it: the worker resolves codes against that
-- unit's subtree, and the status endpoint gates on standing at that unit.
--
-- Additive. `club_profile_id` keeps its NOT NULL — for a national job it holds
-- an ANCHOR club (the lowest club id under the unit) so the row can exist, and
-- nothing about the import is decided by it. `org_unit_id` is the real scope.
-- Every existing job leaves it NULL and is read exactly as before.
--
-- api/imports-gateway.php probes for this column and refuses a national upload
-- with 503 until it is applied, so the code is safe to ship first (main is
-- shared, deploys are by push).
--
-- REVERSE SQL (run top to bottom):
--   DROP INDEX IF EXISTS idx_import_jobs_org_unit;
--   ALTER TABLE import_jobs DROP COLUMN IF EXISTS org_unit_id;
-- ============================================================================

ALTER TABLE import_jobs ADD COLUMN IF NOT EXISTS org_unit_id INTEGER NULL REFERENCES org_units(id);
CREATE INDEX IF NOT EXISTS idx_import_jobs_org_unit ON import_jobs (org_unit_id);
