-- 074_chat_moderation_alerts.sql
--
-- Tell club admins when chat is flagged. Phase 3 of
-- docs/chat-notifications-scope.md; the shape is set by
-- docs/chat-moderation-plan.md:328 — a weekly digest, PLUS individual alerts for
-- high severity only.
--
-- Auto-flagging has fired on every message since moderation shipped 2026-07-30
-- and nobody has ever been told. ChatModeration.tsx is pull-only, so a flag sits
-- unseen until an admin happens to open the page. That is a child-safety gap
-- that is open right now, which is why this moved ahead of web push.
--
-- One table for both kinds. A per-(admin, report) row rather than a column on
-- chat_message_reports, because a report is alerted to SEVERAL admins and one
-- failing send must not mark it handled for the others — the same reason phase 1
-- keeps its marker per person rather than per message.

BEGIN;

CREATE TABLE IF NOT EXISTS chat_moderation_alert_state (
    id         SERIAL PRIMARY KEY,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,

    -- Set for 'high_severity'. NULL for 'digest', which covers a period rather
    -- than one report.
    report_id  INTEGER REFERENCES chat_message_reports(id) ON DELETE CASCADE,

    club_id    INTEGER,
    kind       TEXT NOT NULL CHECK (kind IN ('high_severity', 'digest')),
    sent_at    TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- One high-severity alert per admin per report, enforced rather than merely
-- intended: the dispatcher runs on a timer, so without this a retry storm mails
-- the same flag repeatedly.
CREATE UNIQUE INDEX IF NOT EXISTS idx_chat_mod_alert_unique_report
    ON chat_moderation_alert_state (user_id, report_id)
    WHERE kind = 'high_severity';

-- "When did this admin last get a digest for this club" — the cadence check.
CREATE INDEX IF NOT EXISTS idx_chat_mod_alert_digest
    ON chat_moderation_alert_state (user_id, club_id, sent_at)
    WHERE kind = 'digest';

COMMIT;
