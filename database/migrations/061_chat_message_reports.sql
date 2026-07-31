-- Chat moderation queue.
--
-- One table for BOTH human reports and automated flags, so club admins get one
-- inbox rather than two. `source` distinguishes them; everything downstream —
-- the queue, dismissal, the access grant in M3 — treats them identically.
--
-- This is also what gates admin read: a club admin may open a conversation
-- because an open report exists on it, not at will. So this table is the
-- authorisation record, not just a work list.

CREATE TABLE IF NOT EXISTS chat_message_reports (
    id SERIAL PRIMARY KEY,

    -- CASCADE: when retention hard-deletes a removed message after its 90 days,
    -- the report goes with it. A report pointing at a message nobody can read is
    -- not evidence of anything.
    message_id INTEGER NOT NULL REFERENCES chat_messages(id) ON DELETE CASCADE,
    conversation_id INTEGER REFERENCES conversations(id) ON DELETE CASCADE,

    -- Denormalised so the queue can be club-scoped without joining through
    -- conversations, whose club_id is NULL for pre-conversations legacy messages.
    club_id INTEGER REFERENCES club_profile(id),

    source VARCHAR(10) NOT NULL DEFAULT 'user',   -- 'user' | 'auto'
    reported_by INTEGER REFERENCES users(id),     -- NULL for source='auto'
    rule VARCHAR(40),                             -- which auto rule fired
    severity VARCHAR(10) NOT NULL DEFAULT 'medium',
    note TEXT,                                    -- reporter's own words

    status VARCHAR(12) NOT NULL DEFAULT 'open',   -- 'open' | 'actioned' | 'dismissed'
    reviewed_by INTEGER REFERENCES users(id),
    reviewed_at TIMESTAMP,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE chat_message_reports
    ADD CONSTRAINT chat_message_reports_source_check CHECK (source IN ('user', 'auto'));
ALTER TABLE chat_message_reports
    ADD CONSTRAINT chat_message_reports_severity_check CHECK (severity IN ('low', 'medium', 'high'));
ALTER TABLE chat_message_reports
    ADD CONSTRAINT chat_message_reports_status_check CHECK (status IN ('open', 'actioned', 'dismissed'));

-- Deduplication, split by source.
--
-- Partial UNIQUE indexes rather than one table constraint: `reported_by` is NULL
-- for auto flags and `rule` is NULL for human reports, and Postgres treats NULLs
-- as distinct in a UNIQUE constraint — so a single combined constraint would
-- dedupe neither. One person reporting twice, or one rule firing twice on the
-- same message, must not flood the queue.
CREATE UNIQUE INDEX IF NOT EXISTS idx_chat_reports_user_dedupe
    ON chat_message_reports(message_id, reported_by) WHERE source = 'user';
CREATE UNIQUE INDEX IF NOT EXISTS idx_chat_reports_auto_dedupe
    ON chat_message_reports(message_id, rule) WHERE source = 'auto';

-- The queue's own read: open items for a club, newest first.
CREATE INDEX IF NOT EXISTS idx_chat_reports_queue
    ON chat_message_reports(club_id, status, created_at DESC);

-- M3 asks "is there an open report on this conversation?" on every admin open.
CREATE INDEX IF NOT EXISTS idx_chat_reports_conversation_open
    ON chat_message_reports(conversation_id) WHERE status = 'open';

COMMENT ON TABLE chat_message_reports IS
    'Moderation queue for chat: human reports and automated flags together. An open row is also what authorises a club admin to read the conversation.';
