-- Log of club admins reading conversations they are not part of.
--
-- Club chat carries no expectation of privacy (product decision, 2026-07-30) and
-- admins can open a reported conversation to act on it. Once that is true,
-- READING is the sensitive action, not just removing — and a power nobody can
-- review is not oversight, it is just access.
--
-- Under that stance this table is a DEFENSIBILITY control rather than a privacy
-- one. It is how a club shows it exercised oversight when something goes wrong,
-- and how an admin shows they opened a thread because of a report rather than
-- out of curiosity.
--
-- Only flag-gated opens are recorded. An admin reading a team chat they already
-- belong to is ordinary participation and writes nothing.

CREATE TABLE IF NOT EXISTS chat_access_log (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id),
    conversation_id INTEGER NOT NULL REFERENCES conversations(id) ON DELETE CASCADE,

    -- The report that justified the open. NOT NULL by intent: there is no such
    -- thing as an unjustified entry here, because there is no way to open a
    -- conversation without one. ON DELETE SET NULL would quietly turn a
    -- justified read into an unexplained one.
    report_id INTEGER NOT NULL REFERENCES chat_message_reports(id) ON DELETE CASCADE,

    club_id INTEGER REFERENCES club_profile(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- "What has this admin looked at" and "who has looked at this conversation" are
-- both questions someone will ask after an incident.
CREATE INDEX IF NOT EXISTS idx_chat_access_log_user ON chat_access_log(user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_chat_access_log_conversation ON chat_access_log(conversation_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_chat_access_log_club ON chat_access_log(club_id, created_at DESC);

COMMENT ON TABLE chat_access_log IS
    'Every time a club admin opened a conversation they are not a participant of, and the report that authorised it.';
