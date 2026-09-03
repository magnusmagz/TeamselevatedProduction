-- ============================================================================
-- 093_compliance_default_reminder_stream.sql — GOTR G4: the default reminder
-- stream, and the dedupe that makes it safe to run every six hours.
-- ============================================================================
-- Migration 091 modelled admin-authored reminder streams (G7) and wrote the log
-- that stops a step sending twice:
--
--   compliance_reminder_log (credential_id, stream_id NOT NULL, days_before,
--                            UNIQUE (credential_id, stream_id, days_before))
--
-- G4 ships the reminders BEFORE anybody can author a stream. The 90/60/30/7
-- cadence in lib/compliance_reminders.php is the DEFAULT stream: it belongs to
-- no `compliance_reminder_streams` row because there is no row to belong to, and
-- inventing one per requirement would put a fabricated author, a fabricated
-- copy and a fabricated activation date in a table an admin is about to start
-- editing for real in G7.
--
-- So the default stream is `stream_id IS NULL`, and that needs two changes.
--
-- 1. DROP NOT NULL. This is an ALTER on an existing table, which CLAUDE.md
--    otherwise forbids. It is the same shape as the one approved exception
--    (migration 063 dropping NOT NULL from consent_records.guardian_id): the
--    column is an FK to a row that does not and should not exist yet, so
--    requiring it means the feature can only send reminders for streams nobody
--    has written — i.e. never. The FK is untouched, so a non-null value is
--    still guaranteed to be a real stream. Table holds 0 rows; migration 091 is
--    not yet applied to Neon at time of writing, so this is additive in
--    practice as well as in intent.
--
-- 2. ⚠️ ADD A PARTIAL UNIQUE INDEX, because the existing UNIQUE does not
--    dedupe a NULL. In Postgres two NULLs are not equal, so
--    UNIQUE (credential_id, stream_id, days_before) admits an unlimited number
--    of (7, NULL, 30) rows — the constraint that exists precisely to stop a
--    restarted dyno mailing 30,000 people twice would silently stop applying to
--    the only stream that sends. The partial index below is the real dedupe for
--    the default stream, and the tick's insert-then-send ordering leans on it:
--    a duplicate key is caught and the send is skipped.
--
-- REVERSE SQL (run top to bottom):
--   DROP INDEX IF EXISTS idx_compliance_reminder_log_default_stream;
--   -- Only safe once every stream_id IS NULL row is gone, which is why the
--   -- delete comes first. Without it the ALTER fails and leaves the index
--   -- dropped, which is the worst of the two states.
--   DELETE FROM compliance_reminder_log WHERE stream_id IS NULL;
--   ALTER TABLE compliance_reminder_log ALTER COLUMN stream_id SET NOT NULL;
-- ============================================================================

ALTER TABLE compliance_reminder_log ALTER COLUMN stream_id DROP NOT NULL;

-- One send per person per threshold, ever, for the default stream.
CREATE UNIQUE INDEX IF NOT EXISTS idx_compliance_reminder_log_default_stream
    ON compliance_reminder_log (credential_id, days_before)
    WHERE stream_id IS NULL;

-- The tick scans by (credential, threshold) to decide what is still owed; the
-- partial unique index above already serves the default stream, and this one
-- covers the authored-stream lookups G7 will add.
CREATE INDEX IF NOT EXISTS idx_compliance_reminder_log_credential
    ON compliance_reminder_log (credential_id, days_before);
