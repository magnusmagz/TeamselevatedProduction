-- Chat Schema Migration
-- Real-time messaging for coaches and admins

-- Chat messages table
CREATE TABLE IF NOT EXISTS chat_messages (
    id SERIAL PRIMARY KEY,

    -- Scope: club-wide or team-specific
    scope_type VARCHAR(10) NOT NULL,  -- 'club' or 'team'
    scope_id INTEGER NOT NULL,        -- club_id or team_id
    channel VARCHAR(50) DEFAULT 'general',

    -- Message content
    message_text TEXT NOT NULL,

    -- Sender info (denormalized for query performance)
    sender_id INTEGER NOT NULL REFERENCES users(id),
    sender_name VARCHAR(100) NOT NULL,
    sender_role VARCHAR(50),

    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP  -- Soft delete for moderation
);

-- Check constraint for scope_type
ALTER TABLE chat_messages ADD CONSTRAINT chat_messages_scope_check
    CHECK (scope_type IN ('club', 'team'));

-- Chat reactions (emoji reactions to messages)
CREATE TABLE IF NOT EXISTS chat_reactions (
    id SERIAL PRIMARY KEY,
    message_id INTEGER NOT NULL REFERENCES chat_messages(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id),
    emoji VARCHAR(10) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- One reaction per user per emoji per message
    UNIQUE(message_id, user_id, emoji)
);

-- Chat read receipts (track who has read up to which message)
CREATE TABLE IF NOT EXISTS chat_read_receipts (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id),
    scope_type VARCHAR(10) NOT NULL,
    scope_id INTEGER NOT NULL,
    channel VARCHAR(50) DEFAULT 'general',
    last_read_message_id INTEGER REFERENCES chat_messages(id),
    last_read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- One receipt per user per channel per scope
    UNIQUE(user_id, scope_type, scope_id, channel)
);

-- Indexes for performance
CREATE INDEX IF NOT EXISTS idx_chat_messages_scope ON chat_messages(scope_type, scope_id);
CREATE INDEX IF NOT EXISTS idx_chat_messages_channel ON chat_messages(scope_type, scope_id, channel);
CREATE INDEX IF NOT EXISTS idx_chat_messages_created ON chat_messages(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_chat_messages_sender ON chat_messages(sender_id);
CREATE INDEX IF NOT EXISTS idx_chat_reactions_message ON chat_reactions(message_id);
CREATE INDEX IF NOT EXISTS idx_chat_read_receipts_user ON chat_read_receipts(user_id);

-- Comments
COMMENT ON TABLE chat_messages IS 'Real-time chat messages for club/team communication';
COMMENT ON TABLE chat_reactions IS 'Emoji reactions to chat messages';
COMMENT ON TABLE chat_read_receipts IS 'Track read status for unread message counts';
COMMENT ON COLUMN chat_messages.scope_type IS 'club = club-wide chat, team = team-specific chat';
COMMENT ON COLUMN chat_messages.channel IS 'Channel within the scope (default: general)';
