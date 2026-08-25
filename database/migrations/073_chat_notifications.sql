-- 073_chat_notifications.sql
--
-- Out-of-app notification for missed chat messages (email now, web push next).
-- Scope: docs/chat-notifications-scope.md
--
-- Two tables, and the split matters:
--
--   chat_notification_state — what we have ALREADY told each person about.
--     Dedupe only. Without it a dispatcher that runs every minute re-sends the
--     same digest every minute, and the push/email fallback has no way to know a
--     push already went out.
--
--   chat_notification_prefs — what each person WANTS. Absent row means the
--     defaults below, so this needs no backfill and a new user is opted in
--     without anything having to write a row for them.
--
-- Per-conversation opt-out deliberately lives on the existing, unused
-- conversation_participants.muted rather than in a third table here. Muting is
-- an explicit act, so upserting that row at that moment is fine — unlike the
-- read watermark, which has to work for people who have no row at all.

BEGIN;

CREATE TABLE IF NOT EXISTS chat_notification_state (
    id                        SERIAL PRIMARY KEY,
    user_id                   INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    conversation_id           INTEGER NOT NULL REFERENCES conversations(id) ON DELETE CASCADE,

    -- High-water mark of what this person has been told about in this
    -- conversation, by ANY channel. One mark, not one per channel: the point is
    -- that a human was informed, and telling them twice by two routes is the
    -- exact failure the fallback rule exists to prevent.
    last_notified_message_id  INTEGER,
    last_notified_at          TIMESTAMPTZ,

    -- Which channel actually carried it, for support questions ("did she get
    -- anything?") and for measuring how much push displaces email. Not read as
    -- a permission by anything.
    last_notified_channel     TEXT CHECK (last_notified_channel IN ('email', 'push')),

    created_at                TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at                TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    UNIQUE (user_id, conversation_id)
);

-- The dispatcher's read is "state for these users in this conversation", so the
-- UNIQUE index above already serves it. This one serves the opposite direction:
-- expiring or reporting on stale state without scanning the table.
CREATE INDEX IF NOT EXISTS idx_chat_notification_state_notified_at
    ON chat_notification_state (last_notified_at);

CREATE TABLE IF NOT EXISTS chat_notification_prefs (
    user_id        INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,

    -- On by default, confirmed with Maggie 2026-08-25. A family that never opens
    -- the app is the reason this feature exists, so defaulting to off would make
    -- it reach precisely the people who did not need it.
    email_enabled  BOOLEAN NOT NULL DEFAULT TRUE,
    push_enabled   BOOLEAN NOT NULL DEFAULT TRUE,

    created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

COMMIT;
