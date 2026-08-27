-- 078_chat_notification_clicks.sql
--
-- Did the notification actually bring anyone back?
--
-- Chat notifications deliberately bypass EmailSendService (see
-- lib/chat_notification_dispatcher.php), which is where the tracking pixel and
-- link rewriting live — so they carry NO open or click tracking, and nothing in
-- Email Reporting covers them. That trade was made for good reasons (a
-- communication_log row per chat message would drown the campaign metrics, and
-- the marketing suppression list would silently drop alerts) but it left the
-- feature unmeasurable. Asked for 2026-08-27.
--
-- Two columns rather than a new table: the sent side already lives on
-- chat_notification_state, one row per person per conversation, and the click
-- belongs to that same row. A separate table would need joining back on the
-- same key to answer any question worth asking.
--
-- ⚠️ This measures a CLICK-THROUGH, not an open. That is deliberate and it is
-- better: a tracking pixel measures whether an image loaded, which most mail
-- clients now block or preload on the reader's behalf, so open rates have become
-- close to meaningless. It also works for PUSH, which a pixel could never see.

BEGIN;

ALTER TABLE chat_notification_state
    ADD COLUMN IF NOT EXISTS clicked_at TIMESTAMPTZ,
    -- Which channel the person came back from. Not assumed to match
    -- last_notified_channel: they may click yesterday's email after today's
    -- push, and knowing which one earned the return is the whole question.
    ADD COLUMN IF NOT EXISTS clicked_channel TEXT
        CHECK (clicked_channel IN ('email', 'push', 'in_app'));

-- "How many of the notifications we sent were acted on", without scanning.
CREATE INDEX IF NOT EXISTS idx_chat_notification_state_clicked
    ON chat_notification_state (clicked_at)
    WHERE clicked_at IS NOT NULL;

COMMIT;
