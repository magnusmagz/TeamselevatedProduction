-- 081_chat_pinned_messages.sql
--
-- Let a coach or club admin pin one message to the top of a conversation.
--
-- ONE PIN PER CONVERSATION, deliberately. Pinning a second replaces the first.
-- Several pins turn into a second inbox nobody prunes, and a stale pin is much
-- more visible when there is only ever one — "the pinned message" is either
-- current or obviously wrong, where "one of four pinned messages" is neither.
-- Enforced by a partial unique index rather than by the application.
--
-- Columns rather than a table: a pin is a property OF a message, there is at
-- most one pinner and one time, and a join table would need cleaning up
-- separately when a message is removed.

BEGIN;

ALTER TABLE chat_messages
    ADD COLUMN IF NOT EXISTS pinned_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS pinned_by INTEGER REFERENCES users(id);

-- At most one pinned message per conversation, in the database rather than in
-- the handler. Two admins pinning at the same moment is unlikely and would
-- otherwise leave a conversation with two "the" pinned messages.
CREATE UNIQUE INDEX IF NOT EXISTS idx_chat_messages_one_pin_per_conversation
    ON chat_messages (conversation_id)
    WHERE pinned_at IS NOT NULL;

COMMIT;
