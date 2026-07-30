-- Chat conversation archive (per-participant view state)
--
-- Archive is deliberately NOT delete. A user hides a conversation from their own
-- list; nothing is removed, no other participant is affected, and a new message
-- brings it back. There is no user-facing delete in chat at all — the only removal
-- path is admin moderation, which tombstones and writes audit_log.
--
-- This column must be written with an UPSERT, never a bare UPDATE. Team
-- conversations are created by ensureTeamConversation() with NO participant rows;
-- members reach them through the team-id branch of getUserConversations. So the
-- row this column lives on frequently does not exist yet at archive time.

ALTER TABLE conversation_participants
    ADD COLUMN IF NOT EXISTS archived_at TIMESTAMP;

-- Supports both "my unarchived list" and "my archived list"; user_id leads
-- because every read is already scoped to a single user.
CREATE INDEX IF NOT EXISTS idx_conv_participants_archived
    ON conversation_participants(user_id, archived_at);

COMMENT ON COLUMN conversation_participants.archived_at IS
    'When this user archived the conversation (hidden from their list only). NULL = not archived. Cleared automatically when a new message arrives.';
