-- 079_chat_reaction_emoji_set.sql
--
-- Constrain message reactions to the six agreed emoji (Maggie, 2026-08-28):
--
--     👍 ❤️ 🎉 👏 😂 😮
--
-- Acknowledge, warmth, celebrate, well done, funny, surprise. Six fits one row
-- on a phone and covers a coach posting a result, a parent answering a schedule
-- change, and a team celebrating.
--
-- ⚠️ **Nothing negative, deliberately.** A thumbs-down or an angry face in a
-- parent group chat creates conflict a message never would, and once it is on
-- screen it gets used. Adding one later is a product decision, not a tweak.
--
-- WHY A CHECK AND NOT JUST A PICKER
-- `emoji` has been a bare VARCHAR(10) since migration 005, so any emoji at all
-- is storable — the picker is the only thing limiting it, and a picker is a
-- suggestion. Same reasoning as the jersey-size vocabulary: the set is enforced
-- where it is stored, not where it is offered.
--
-- Safe to apply: chat_reactions holds 0 rows (verified 2026-08-28 against live
-- Neon, across 366 messages), so nothing existing can violate it. Reactions have
-- never worked — the table, server handlers and client helpers all exist and
-- nothing ever called them.

BEGIN;

ALTER TABLE chat_reactions
    DROP CONSTRAINT IF EXISTS chat_reactions_emoji_check;

ALTER TABLE chat_reactions
    ADD CONSTRAINT chat_reactions_emoji_check
    CHECK (emoji IN ('👍', '❤️', '🎉', '👏', '😂', '😮'));

-- The read is always "every reaction on these messages", so this is the index
-- that serves it. UNIQUE(message_id, user_id, emoji) from migration 005 already
-- prevents a double reaction; it just leads with the wrong column for this.
CREATE INDEX IF NOT EXISTS idx_chat_reactions_message
    ON chat_reactions (message_id);

COMMIT;
