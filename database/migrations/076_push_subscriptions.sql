-- 076_push_subscriptions.sql
--
-- Web push. Phase 4 of docs/chat-notifications-scope.md.
--
-- PER DEVICE, not per user. One person has a phone, a laptop and a work machine,
-- each with its own endpoint and its own keys, and a notification has to reach
-- all of them. Keying this on user_id alone would silently unsubscribe the phone
-- every time they enabled it on a laptop.
--
-- ⚠️ Rows here are DISPOSABLE and must be pruned. Push services answer 404 or 410
-- when a subscription dies — cleared browser data, a reinstall, a new phone — and
-- nothing tells us in advance. Without deleting on that response the table fills
-- with endpoints that can never be delivered to, and every send wastes a request
-- on each. lib/chat_push.php does the pruning; PushSubscriptionTest pins it.
--
-- The endpoint is the identity: the browser generates it, it is already unique
-- per (device, origin), and re-subscribing on the same device returns the same
-- one. So an UPSERT on endpoint is what makes "enable notifications" idempotent.

BEGIN;

CREATE TABLE IF NOT EXISTS push_subscriptions (
    id           SERIAL PRIMARY KEY,
    user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,

    -- The push service URL. Long by nature (FCM endpoints run past 200 chars),
    -- so TEXT rather than a guessed VARCHAR ceiling.
    endpoint     TEXT NOT NULL,

    -- The browser's own keypair for payload encryption. Without both, a
    -- notification can only be sent empty, which no useful message can be.
    p256dh       TEXT NOT NULL,
    auth         TEXT NOT NULL,

    -- Recorded for support ("she says her phone stopped buzzing"), never read as
    -- a permission.
    user_agent   TEXT,

    created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    last_used_at TIMESTAMPTZ,

    UNIQUE (endpoint)
);

CREATE INDEX IF NOT EXISTS idx_push_subscriptions_user
    ON push_subscriptions (user_id);

COMMIT;
