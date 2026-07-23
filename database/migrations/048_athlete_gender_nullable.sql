-- 048_athlete_gender_nullable.sql
-- Applied to prod Neon 2026-07-23 (recorded here for migration hygiene).
--
-- Two fixes to athletes.gender, surfaced by GotSport CSV imports where most
-- rows leave gender blank:
--   1. The column was varchar(15) but its own CHECK constraint permits
--      'Prefer not to say' (17 chars) — a value it could never store. Widen it.
--   2. Gender is optional; blank should be stored as NULL, not defaulted.
--      Allow NULL (the existing CHECK already passes on NULL).
--
-- Widening a varchar length and dropping NOT NULL are both catalog-only,
-- non-rewriting operations in PostgreSQL. Safe to re-run.

ALTER TABLE athletes ALTER COLUMN gender TYPE varchar(20);
ALTER TABLE athletes ALTER COLUMN gender DROP NOT NULL;
