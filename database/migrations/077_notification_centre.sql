-- 077_notification_centre.sql
--
-- Phase 5 of docs/chat-notifications-scope.md: the in-app notification centre.
--
-- The `notifications` table already exists in Neon and has never been read or
-- written by anything — it predates this work. Nothing here recreates it; this
-- only adds the indexes the centre needs and widens one CHECK.
--
-- WHY 'in_app' JOINS THE CHANNEL LIST
-- chat_notification_state.last_notified_channel records which route actually
-- carried a notification. Push and email were the only two possible when 073 was
-- written. Now a person with no address and no device still gets told — in the
-- app — and that has to close the item, or the dispatcher re-derives it as owed
-- on every tick forever. Recording it as 'email' would be a lie in the one place
-- someone would later look to find out what happened.

BEGIN;

-- The centre's only hot query: "my unread, newest first".
CREATE INDEX IF NOT EXISTS idx_notifications_user_unread
    ON notifications (user_id, created_at DESC)
    WHERE read_at IS NULL;

CREATE INDEX IF NOT EXISTS idx_notifications_user_created
    ON notifications (user_id, created_at DESC);

ALTER TABLE chat_notification_state
    DROP CONSTRAINT IF EXISTS chat_notification_state_last_notified_channel_check;

ALTER TABLE chat_notification_state
    ADD CONSTRAINT chat_notification_state_last_notified_channel_check
    CHECK (last_notified_channel IN ('email', 'push', 'in_app'));

COMMIT;
