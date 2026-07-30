-- 057: Per-club SMS sending numbers
--
-- Until now every club on the platform sent from ONE number, the TWILIO_FROM_NUMBER
-- env var, read directly in SmsSendService::sendViaTwilio. That has a consequence
-- worse than a cosmetic one: carrier STOP handling blocks the (from-number,
-- recipient) PAIR, so a parent who replied STOP to one club became unreachable by
-- EVERY club on the platform, permanently, at the carrier — regardless of what our
-- club-scoped email_suppressions rows say.
--
-- user_id is nullable and unused today. It is here so the per-coach numbers in
-- docs/... unified-messaging-scope.md Phase 1 land as rows in this table rather than
-- a second table with a near-identical shape:
--   user_id IS NULL  → the club's number
--   user_id IS NOT NULL → that coach's own number
-- Sender resolution is then: coach's number → club's number → refuse.

CREATE TABLE IF NOT EXISTS sms_phone_numbers (
    id                     SERIAL PRIMARY KEY,
    club_profile_id        INTEGER NOT NULL REFERENCES club_profile(id) ON DELETE CASCADE,
    user_id                INTEGER REFERENCES users(id) ON DELETE SET NULL,

    -- E.164, e.g. +13605550199. Written only after Twilio confirms the account
    -- actually owns it, so a typo can never become a silently-failing sender.
    --
    -- Nullable because a Messaging Service is a complete sender on its own: it
    -- holds its own pool of numbers and Twilio picks one per message, so a club
    -- configured that way has no single bare number to record.
    phone_number           VARCHAR(20),
    twilio_phone_sid       VARCHAR(64),

    -- Preferred over phone_number when set. A2P 10DLC campaigns attach to a
    -- Messaging Service, not a bare long code, so this is the field that matters
    -- once registration is done. Twilio takes MessagingServiceSid in place of From.
    messaging_service_sid  VARCHAR(64),

    is_active              BOOLEAN NOT NULL DEFAULT TRUE,
    provisioned_at         TIMESTAMP,
    created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Both columns are individually optional, but a row with neither is not a
    -- sender — te_resolve_sms_sender returns null for it, which means the club
    -- silently cannot send while appearing configured in the UI. Reject it here
    -- rather than let that state exist.
    CONSTRAINT sms_phone_numbers_has_a_sender
        CHECK (phone_number IS NOT NULL OR messaging_service_sid IS NOT NULL)
);

-- At most ONE active club-level number per club. Partial index because a club may
-- keep deactivated rows as history, and because coach rows (user_id NOT NULL) are
-- not constrained by this.
CREATE UNIQUE INDEX IF NOT EXISTS sms_phone_numbers_one_active_per_club
    ON sms_phone_numbers (club_profile_id)
    WHERE user_id IS NULL AND is_active;

CREATE INDEX IF NOT EXISTS sms_phone_numbers_club_idx
    ON sms_phone_numbers (club_profile_id, is_active);

-- Which number a message actually went out from. Without this, a 21610
-- ("unsubscribed recipient") failure is undiagnosable after a club changes number:
-- you cannot tell whether the STOP was against the number in the row today or the
-- one in force at send time.
ALTER TABLE communication_log
    ADD COLUMN IF NOT EXISTS from_number VARCHAR(20);


-- ─────────────────────────────────────────────────────────────────────────────
-- NO BACKFILL — deliberate, decided 2026-07-30. Do not add one.
--
-- This table starts EMPTY, and te_resolve_sms_sender returns null for a club with
-- no row, which makes queueSms refuse. So on the day this lands, no club can send
-- SMS until an admin sets a number in Club Profile → Messaging. That is the
-- intended behavior, not an oversight.
--
-- The obvious softener is to seed every club with the old shared
-- TWILIO_FROM_NUMBER so nothing breaks at deploy time. It was considered and
-- rejected, because the thing it protects barely exists. Verified against Neon
-- 2026-07-30, before applying this migration:
--
--     communication_log channel='sms'  →  5 rows
--     email_suppressions channel='sms' →  0 rows
--
-- All 5 are test sends from 2026-03-21 and 2026-04-06, all club 32, all to
-- internal test numbers (the accounts in email-sms-test-plan.md). Nothing since.
-- No real family has ever received a text from this platform, and nobody has
-- opted out. So there is no working behavior to preserve — only the chance to
-- start all 5 clubs off sharing one number.
--
-- That matters more than it sounds. Carrier STOP blocks the (from-number,
-- recipient) PAIR. While clubs share a number, one family replying STOP to one
-- club goes unreachable for EVERY club on the platform, at the carrier, whatever
-- our club-scoped email_suppressions rows say. A backfill would have made that the
-- default state for any club that never visited the settings tab — permanently and
-- silently, since nothing would ever prompt them.
--
-- Starting empty means the only way to send is to configure a real number, and the
-- shared-sender failure mode can never exist at all.
--
-- If you are here because SMS is refusing and you want it working NOW: set the
-- club's number in Club Profile → Messaging. Do not seed this table by hand.
