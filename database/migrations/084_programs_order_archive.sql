-- 084_programs_order_archive.sql
--
-- Manual display order + archive-without-delete for programs (CKU R89/R90).
--
-- ADDITIVE ONLY. Three nullable columns on `programs`; nothing existing is
-- altered or dropped, so an un-migrated database keeps working and code that
-- runs before this is applied degrades rather than 500s (see
-- lib/program_ordering.php — every read and write of these columns is gated on
-- an information_schema probe, because `main` is shared and deploys are by push).
--
--   sort_order   NULL = "never been ordered by hand". Ordering is
--                `sort_order NULLS LAST, <existing order>`, so a club that has
--                not touched the arrows sees exactly what it saw before.
--   archived_at  NULL = live. Archiving is the alternative to DELETE, which is
--                blocked for any program that has teams and which destroys the
--                registration history either way.
--   archived_by  users(id) of the admin who archived it. NULL is honest for a
--                row archived outside a request path.
--
-- REVERSE:
--   ALTER TABLE programs DROP COLUMN sort_order;
--   ALTER TABLE programs DROP COLUMN archived_at;
--   ALTER TABLE programs DROP COLUMN archived_by;

ALTER TABLE programs ADD COLUMN IF NOT EXISTS sort_order INTEGER;
ALTER TABLE programs ADD COLUMN IF NOT EXISTS archived_at TIMESTAMPTZ NULL;
ALTER TABLE programs ADD COLUMN IF NOT EXISTS archived_by INTEGER NULL REFERENCES users(id);

-- The list query filters on archived_at for every club on every page load.
CREATE INDEX IF NOT EXISTS idx_programs_club_archived ON programs (club_id, archived_at);
