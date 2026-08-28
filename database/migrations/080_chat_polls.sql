-- 080_chat_polls.sql
--
-- Polls in chat. Scope and decisions: docs/chat-polls-scope.md
--
-- A poll is for CHOOSING BETWEEN OPTIONS — "team dinner Friday at 7 or Thursday
-- at 6:30". It is deliberately NOT a signup sheet: "who is bringing oranges" is
-- assignment, where each answer is a commitment to do something and the useful
-- view is names against tasks (Maggie, 2026-08-28).

BEGIN;

-- ── The message type discriminator ───────────────────────────────────────────
-- chat has only ever had one kind of message. A DEFAULT rather than a backfill:
-- every existing row is text, so nothing needs rewriting.
ALTER TABLE chat_messages
    ADD COLUMN IF NOT EXISTS message_type TEXT NOT NULL DEFAULT 'text';

ALTER TABLE chat_messages
    DROP CONSTRAINT IF EXISTS chat_messages_message_type_check;
ALTER TABLE chat_messages
    ADD CONSTRAINT chat_messages_message_type_check
    CHECK (message_type IN ('text', 'poll'));

-- ── The poll ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS chat_polls (
    id          SERIAL PRIMARY KEY,
    message_id  INTEGER NOT NULL UNIQUE
                REFERENCES chat_messages(id) ON DELETE CASCADE,
    question    TEXT NOT NULL,

    -- ⚠️ FIXED AT CREATION, never editable. Flipping an anonymous poll to named
    -- exposes votes cast under a promise; the other way discards what people
    -- expected to see. Anonymity must also hold in the DATABASE, not just on
    -- screen: a vote still records who cast it (that is how changing your vote
    -- works), so the protection is that nothing ever reads it back for one of
    -- these. That is a discipline, and it is tested rather than assumed.
    is_anonymous        BOOLEAN NOT NULL DEFAULT FALSE,

    -- Editable. Revealing results later exposes nothing that was promised
    -- private, since the votes are already whatever is_anonymous says.
    results_before_vote BOOLEAN NOT NULL DEFAULT TRUE,

    -- Schema-ready, no UI at launch. One column now avoids a migration later.
    allow_multiple      BOOLEAN NOT NULL DEFAULT FALSE,

    -- Editable, and it may move EARLIER as well as later. Evaluated at read
    -- time — never a background job flipping a status, which would be a second
    -- thing to break and wrong whenever the worker is behind.
    closes_at   TIMESTAMPTZ,

    created_by  INTEGER NOT NULL REFERENCES users(id),
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS chat_poll_options (
    id          SERIAL PRIMARY KEY,
    poll_id     INTEGER NOT NULL REFERENCES chat_polls(id) ON DELETE CASCADE,
    label       TEXT NOT NULL,
    sort_order  INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX IF NOT EXISTS idx_chat_poll_options_poll
    ON chat_poll_options (poll_id, sort_order);

CREATE TABLE IF NOT EXISTS chat_poll_votes (
    id          SERIAL PRIMARY KEY,
    option_id   INTEGER NOT NULL REFERENCES chat_poll_options(id) ON DELETE CASCADE,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    -- ⚠️ THE CORRECTNESS BOUNDARY, and it is here rather than in the app. Two
    -- taps on a slow connection is the ordinary case, not an edge case, and a
    -- vote counted twice is the one bug that makes the whole feature
    -- untrustworthy. Let the insert conflict.
    UNIQUE (option_id, user_id)
);

CREATE INDEX IF NOT EXISTS idx_chat_poll_votes_option
    ON chat_poll_votes (option_id);

-- Results are always counted from these rows. Deliberately no vote_count column
-- to drift out of step with them.

COMMIT;
