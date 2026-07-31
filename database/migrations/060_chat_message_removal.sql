-- Admin moderation removal of chat messages.
--
-- `chat_messages.deleted_at` has existed since migration 005 and every read path
-- already filters it, but nothing has ever written it. This adds the two columns
-- needed to answer "who removed this, and why" and turns the column into a real
-- moderation action.
--
-- Removal is a SOFT delete, always. The row survives, participants see a
-- tombstone rather than a gap, and the text stays available for the 90-day
-- reversal window before `chat_messages_removed` in lib/retention_plans.php
-- hard-deletes it. There is still no user-facing delete anywhere in chat.

ALTER TABLE chat_messages
    ADD COLUMN IF NOT EXISTS deleted_by INTEGER REFERENCES users(id),
    ADD COLUMN IF NOT EXISTS removal_reason VARCHAR(40);

-- Moderation queues read "what was removed in this club recently".
CREATE INDEX IF NOT EXISTS idx_chat_messages_removed
    ON chat_messages(deleted_at) WHERE deleted_at IS NOT NULL;

COMMENT ON COLUMN chat_messages.deleted_by IS
    'Club admin who removed this message. NULL while deleted_at is NULL.';
COMMENT ON COLUMN chat_messages.removal_reason IS
    'Why it was removed, chosen by the admin. Drives nothing yet; recorded so a pattern is visible later.';
