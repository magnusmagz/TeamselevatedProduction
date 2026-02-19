-- Conversations Schema Migration
-- Adds conversations, conversation_participants tables and links chat_messages

-- Conversations table: unified container for team chats, DMs, and group messages
CREATE TABLE IF NOT EXISTS conversations (
    id SERIAL PRIMARY KEY,
    type VARCHAR(10) NOT NULL CHECK (type IN ('team', 'direct', 'group')),
    team_id INTEGER REFERENCES teams(id),          -- set for type='team'
    club_id INTEGER NOT NULL REFERENCES club_profile(id),
    created_by INTEGER REFERENCES users(id),       -- coach/admin who created DM/group
    last_message_at TIMESTAMP,
    last_message_preview TEXT,                      -- first 100 chars
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Conversation participants: tracks membership, unread state, mute preferences
CREATE TABLE IF NOT EXISTS conversation_participants (
    id SERIAL PRIMARY KEY,
    conversation_id INTEGER NOT NULL REFERENCES conversations(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id),
    role VARCHAR(20) DEFAULT 'member',             -- 'creator' or 'member'
    display_name VARCHAR(100),
    last_read_message_id INTEGER,
    last_read_at TIMESTAMP,
    muted BOOLEAN DEFAULT FALSE,
    left_at TIMESTAMP,
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(conversation_id, user_id)
);

-- Link messages to conversations (nullable for backward compat with existing messages)
ALTER TABLE chat_messages ADD COLUMN IF NOT EXISTS conversation_id INTEGER REFERENCES conversations(id);

-- Indexes
CREATE INDEX IF NOT EXISTS idx_conversations_team ON conversations(team_id);
CREATE INDEX IF NOT EXISTS idx_conversations_club ON conversations(club_id);
CREATE INDEX IF NOT EXISTS idx_conversations_last_msg ON conversations(last_message_at DESC);
CREATE INDEX IF NOT EXISTS idx_conv_participants_user ON conversation_participants(user_id);
CREATE INDEX IF NOT EXISTS idx_conv_participants_conv ON conversation_participants(conversation_id);
CREATE INDEX IF NOT EXISTS idx_chat_messages_conv ON chat_messages(conversation_id);

-- Comments
COMMENT ON TABLE conversations IS 'Unified conversation container for team chats, DMs, and group messages';
COMMENT ON TABLE conversation_participants IS 'Tracks membership, unread state, and mute preferences for conversations';
COMMENT ON COLUMN conversations.type IS 'team = team chat, direct = 1:1 DM, group = ad-hoc group';
COMMENT ON COLUMN conversations.team_id IS 'Set only for type=team conversations';
COMMENT ON COLUMN conversations.created_by IS 'Coach/admin who created the DM or group conversation';
COMMENT ON COLUMN conversation_participants.role IS 'creator = conversation creator, member = regular participant';
