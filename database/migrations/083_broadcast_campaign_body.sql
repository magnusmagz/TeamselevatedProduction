-- 083_broadcast_campaign_body.sql
--
-- What a scheduled broadcast has to say, and what happened when it didn't.
--
-- `broadcast_campaigns` already carried scheduled_at and a status that accepts
-- 'scheduled', so the table looked ready for scheduled sends. It was not: there
-- was no `body` and no `html_body`, so a scheduled campaign recorded everything
-- about the send EXCEPT the message. For SMS the only surviving trace was `name`,
-- which is substr($body, 0, 80) — truncated, and only by accident. That is why
-- handleSendBroadcast has refused scheduling with a 400 since the 2026-07-06
-- silent-failure sweep. A dispatcher alone could not fix it; this comes first.
-- See docs/sms-scheduled-and-replies-scope.md Part 1.
--
-- ADDITIVE ONLY. Four nullable columns plus one index; nothing existing is
-- altered or dropped.
--
--   body            The plain-text message. Written for scheduled AND immediate
--                   sends. Immediate does not need it, but a campaign row that
--                   sometimes has a body and sometimes does not is a trap for
--                   whoever writes reporting later.
--   html_body       The rendered HTML for an email campaign. NULL for SMS.
--   event_id        calendar_events(id) the campaign's merge tags resolve
--                   against. The immediate path takes this off the request and
--                   passes it to queueEmail; without it a scheduled campaign
--                   loses every {{event_*}} tag between scheduling and firing.
--   failure_reason  Why a campaign ended in status='failed'. `failed_count`
--                   counts messages; this says why the CAMPAIGN did not run —
--                   the club had no SMS number at dispatch time, the scheduling
--                   user lost access to the team, or it was too late to send.
--                   A failed campaign with no reason is unexplainable later,
--                   which is the same gap migration 070 closed for guardian links.
--
-- ⚠️ `main` is shared and deploys are by push, so the code that writes these
-- columns reaches production the moment any session pushes — possibly days
-- before this file is applied to Neon by hand. On Postgres a reference to a
-- missing column is 42703, a hard error that would break every broadcast for
-- every club, not merely hide a new feature. So every read and write of these
-- four columns is gated on an information_schema probe
-- (te_broadcast_scheduled_columns_present in lib/broadcast_dispatcher.php), and
-- handleSendBroadcast keeps returning its existing 400 for scheduling until the
-- probe says the columns are there. Same reasoning as lib/program_ordering.php
-- and the SAVEPOINT around registration consent capture.
--
-- REVERSE:
--   DROP INDEX IF EXISTS idx_broadcast_campaigns_due;
--   ALTER TABLE broadcast_campaigns DROP COLUMN body;
--   ALTER TABLE broadcast_campaigns DROP COLUMN html_body;
--   ALTER TABLE broadcast_campaigns DROP COLUMN event_id;
--   ALTER TABLE broadcast_campaigns DROP COLUMN failure_reason;

ALTER TABLE broadcast_campaigns ADD COLUMN IF NOT EXISTS body TEXT;
ALTER TABLE broadcast_campaigns ADD COLUMN IF NOT EXISTS html_body TEXT;
ALTER TABLE broadcast_campaigns ADD COLUMN IF NOT EXISTS event_id INTEGER NULL REFERENCES calendar_events(id);
ALTER TABLE broadcast_campaigns ADD COLUMN IF NOT EXISTS failure_reason TEXT;

-- The dispatcher runs this predicate every 30 seconds for the life of the worker
-- dyno. Partial, because 'scheduled' is a handful of rows against a table that
-- accumulates every campaign a club has ever sent.
CREATE INDEX IF NOT EXISTS idx_broadcast_campaigns_due
    ON broadcast_campaigns (scheduled_at)
    WHERE status = 'scheduled';
