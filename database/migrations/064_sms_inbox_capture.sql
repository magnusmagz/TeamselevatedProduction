-- 064: SMS inbox — capture inbound replies (M1 of docs/sms-inbox-scope.md)
--
-- Until now inbound SMS was answered and thrown away. api/webhooks/twilio-inbound.php
-- returns an auto-reply and stores nothing, deliberately, so the seven real replies
-- to the 2026-07-30 Central Kansas broadcast exist only in Twilio's message log.
-- Reading them required four database queries and an API call; four of them were
-- families asking where their portal invite was, and nobody in the product could see it.
--
-- ⚠️ This is the FIRST migration to alter `communication_log`, which holds real
-- production history (500+ rows, including a live club's entire send record).
-- Every earlier migration added to a new or quiet table. It is additive and
-- backfill-free by design — see the DEFAULT below.
--
-- Numbering note: this was "060" throughout the scope doc. 060-063 were claimed by
-- the chat-moderation and consent work while it was being written. Check
-- `ls database/migrations/` in BOTH checkouts before claiming a number.

-- Direction of travel. DEFAULT 'outbound' is what makes this safe on a live table:
-- every existing row IS outbound, so they all become correct without a backfill,
-- and no window exists where a row has no direction.
ALTER TABLE communication_log
    ADD COLUMN IF NOT EXISTS direction VARCHAR(10) NOT NULL DEFAULT 'outbound';

-- Added separately from the column so re-running is safe and a pre-existing bad
-- row surfaces as a constraint failure rather than a silently skipped ADD COLUMN.
ALTER TABLE communication_log
    DROP CONSTRAINT IF EXISTS communication_log_direction_check;
ALTER TABLE communication_log
    ADD CONSTRAINT communication_log_direction_check
    CHECK (direction IN ('outbound', 'inbound'));

-- Groups a back-and-forth into a thread. Hash of club_profile_id + the NORMALIZED
-- contact phone.
--
-- Keyed on the CLUB, not the sender. The club owns the number today, so every
-- admin looking at a thread is looking at the same conversation. When per-coach
-- numbers arrive (unified-messaging-scope Phase 1) the key gains the user id and
-- existing threads keep working, because a club-keyed thread stays valid — a
-- sender-keyed one would have to be rebuilt.
ALTER TABLE communication_log
    ADD COLUMN IF NOT EXISTS conversation_id VARCHAR(64);

-- NULL = unread. Only ever set on inbound rows; an outbound message has no one
-- to read it on our side.
ALTER TABLE communication_log
    ADD COLUMN IF NOT EXISTS read_at TIMESTAMP;

-- user_id means "the staff member who SENT this", and is NOT NULL with an FK to
-- users. An inbound reply has no such person. The alternatives were both wrong:
-- writing 0 violates the FK (caught rehearsing the insert), and attributing the
-- message to some club admin invents a sender who did not write it.
--
-- Relaxing NOT NULL cannot invalidate an existing row — all 510 already have a
-- value. Readers must cope with NULL, which is why analytics filters to
-- direction='outbound' and never sees these rows at all.
ALTER TABLE communication_log
    ALTER COLUMN user_id DROP NOT NULL;

-- The inbox's two hot queries: list threads by recency, and open one thread.
CREATE INDEX IF NOT EXISTS communication_log_conversation_idx
    ON communication_log (conversation_id, created_at DESC)
    WHERE conversation_id IS NOT NULL;

-- Per-club feature flag. Lives here rather than on club_profile, mirroring
-- club_payment_accounts.charges_enabled and payment_items.sibling_discount_enabled
-- — this repo has no global flag system, it puts capability booleans on the row
-- that owns the capability. An inbox cannot exist without a number.
--
-- It must be per club because it drives the auto-reply COPY: "someone will get
-- back to you here" is only true where someone is watching. A global toggle would
-- promise that to every club at once.
--
-- Capture (this migration) is NOT gated by it. Storing is not monitoring, so the
-- current "this number is not monitored" wording stays true for an unflagged club,
-- and the inbox opens with real history instead of empty.
ALTER TABLE sms_phone_numbers
    ADD COLUMN IF NOT EXISTS inbox_enabled BOOLEAN NOT NULL DEFAULT FALSE;
