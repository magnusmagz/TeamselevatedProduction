-- Migration 039: Athlete referential-integrity hardening
-- Applied manually to Neon 2026-05-27.
--
-- Context: athletes are soft-deleted (athletes.active_status = false); a hard
-- DELETE is a deliberate admin/merge operation only. These changes stop a hard
-- delete from silently destroying financial / medical-compliance history or
-- leaving orphaned registrations (registrations had no FK to athletes at all).

-- 1) Remove pre-existing orphan registrations (athletes deleted long ago, before
--    any FK existed on registrations.athlete_id).
DELETE FROM registrations
 WHERE athlete_id IS NOT NULL
   AND athlete_id NOT IN (SELECT id FROM athletes);

-- 2) registrations.athlete_id had NO foreign key -> add one, ON DELETE RESTRICT.
ALTER TABLE registrations
  ADD CONSTRAINT registrations_athlete_id_fkey
  FOREIGN KEY (athlete_id) REFERENCES athletes(id) ON DELETE RESTRICT;

-- 3) Financial / medical children: CASCADE -> RESTRICT so a hard athlete delete
--    is blocked while retention-sensitive records exist (must be repointed/handled
--    explicitly first, as in an admin merge). invoices, consent_records, and
--    scholarship_applications were already NO ACTION/RESTRICT.
ALTER TABLE athlete_payments
  DROP CONSTRAINT athlete_payments_athlete_id_fkey,
  ADD  CONSTRAINT athlete_payments_athlete_id_fkey
  FOREIGN KEY (athlete_id) REFERENCES athletes(id) ON DELETE RESTRICT;

ALTER TABLE medical_records
  DROP CONSTRAINT medical_records_athlete_id_fkey,
  ADD  CONSTRAINT medical_records_athlete_id_fkey
  FOREIGN KEY (athlete_id) REFERENCES athletes(id) ON DELETE RESTRICT;
