-- 068: Lite support ticketing.
--
-- A "Report an issue" button anywhere in the app. Captures who / what / where plus
-- an optional screenshot, stores it, and posts to Slack. Slack IS the queue —
-- there is deliberately no in-app list, no assignment and no status workflow.
-- See SCOPE-Support-Tickets.md.

CREATE TABLE IF NOT EXISTS support_tickets (
    id            SERIAL PRIMARY KEY,

    -- Both nullable ON PURPOSE. The most valuable report is from someone who
    -- cannot sign in, and that report is currently impossible to file. Those
    -- rows carry no user and are marked unverified in the Slack post.
    user_id       INTEGER REFERENCES users(id) ON DELETE SET NULL,
    club_id       INTEGER REFERENCES club_profile(id) ON DELETE SET NULL,

    -- Captured even when user_id is null, so an anonymous report is still
    -- answerable. Free text: whatever the reporter typed, or their session's.
    reporter_name  TEXT,
    reporter_email TEXT,

    description   TEXT NOT NULL,
    page_url      TEXT,
    device_info   JSONB,
    ip_address    TEXT,

    -- Slack message timestamp, so a future version could thread a resolution
    -- onto the original post rather than starting a new one.
    slack_ts      TEXT,

    -- Not worked in-app; kept so "how many did we get last month" is answerable.
    status        TEXT NOT NULL DEFAULT 'new',

    created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    resolved_at   TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS idx_support_tickets_created ON support_tickets (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_support_tickets_user    ON support_tickets (user_id);

-- Screenshots live as base64 in Postgres. api/upload.php writes to local disk,
-- and Heroku's filesystem is ephemeral — wiped on every deploy and dyno restart —
-- so an uploaded screenshot would silently vanish. There is no S3 in this stack.
-- The precedent is club_profile.logo_png, which stores base64 the same way.
--
-- Client-side downscaling keeps this viable: a raw phone screenshot is 3-6 MB,
-- ~300 KB after. The 2 MB cap is enforced server-side regardless.
CREATE TABLE IF NOT EXISTS support_ticket_attachments (
    id          SERIAL PRIMARY KEY,
    ticket_id   INTEGER NOT NULL REFERENCES support_tickets(id) ON DELETE CASCADE,
    filename    TEXT,
    mime_type   TEXT NOT NULL,
    byte_size   INTEGER NOT NULL,
    data        TEXT NOT NULL,

    -- The signed link handed to Slack. Unauthenticated by design so the team can
    -- open it from a phone; random_bytes, never a sequential id, and it expires.
    token       TEXT NOT NULL UNIQUE,
    expires_at  TIMESTAMPTZ NOT NULL,

    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_support_attach_ticket ON support_ticket_attachments (ticket_id);
CREATE INDEX IF NOT EXISTS idx_support_attach_token  ON support_ticket_attachments (token);
