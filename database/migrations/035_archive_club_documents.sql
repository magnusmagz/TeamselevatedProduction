-- Migration 035: Archive club_documents
--
-- All code that reads/writes club_documents has been replaced with the
-- documents / document_assignments / document_acknowledgments schema from
-- migration 032. Backfill into the new tables happened in 032.
--
-- This migration renames club_documents to _archive_club_documents to
-- preserve the data for forensic recovery if needed (matches the
-- _backup_league_documents and _pre_unified_documents_v1 patterns).
--
-- Safe to run because no application code references club_documents anymore
-- after Phase 5 cleanup. The rename is reversible via:
--   ALTER TABLE _archive_club_documents RENAME TO club_documents;
-- if anything turns out to need it.

DO $$
BEGIN
    IF EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'club_documents') THEN
        ALTER TABLE club_documents RENAME TO _archive_club_documents;
    END IF;
END $$;

-- Sanity check: documents and assignments still have the backfilled rows.
DO $$
DECLARE
    d_count INTEGER;
    a_count INTEGER;
BEGIN
    SELECT COUNT(*) INTO d_count FROM documents;
    SELECT COUNT(*) INTO a_count FROM document_assignments;

    RAISE NOTICE 'After archive:';
    RAISE NOTICE '  documents rows: %', d_count;
    RAISE NOTICE '  document_assignments rows: %', a_count;
END $$;
