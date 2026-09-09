-- 101_club_public_page.sql
--
-- Every club gets a public, mobile-first link page at /club/<slug> (Maggie,
-- 2026-09-09): name, contact, socials, sponsors, upcoming games, teams and
-- coaches, and a contact form that emails the club's administrators. No
-- athlete data ever appears on it.
--
-- Three things this adds:
--
--   1. club_profile.slug becomes REAL. The column has existed since before the
--      migration series with a plain index, no writer, no UI and no uniqueness
--      (verified in Neon 2026-09-09: 4 of 6 clubs NULL). The page is keyed on
--      it, so every club gets one here (lowercased name, [^a-z0-9]+ -> '-',
--      de-duplicated with -2, -3 ...) and a partial UNIQUE index on
--      lower(slug) stops two clubs sharing a URL. Existing non-null slugs
--      (`default-club`, `teams-elevated`) are kept as they are.
--   2. club_profile.public_page_enabled (default TRUE — the page is on for
--      every club, an admin turns it off) and public_page_tagline (one line
--      under the club name, optional).
--   3. club_contact_messages — every submission from the public contact form
--      is STORED before any email is attempted, so a SendGrid outage cannot
--      lose a family's message. `status` records whether the notification
--      went; `sent_to` records which admin addresses it went to.
--
-- Read by lib/club_public_page.php and api/club-public-gateway.php (public,
-- unauthenticated), written by legacy/club-profile-gateway.php (slug, enabled,
-- tagline). The gateway probes information_schema for public_page_enabled and
-- treats an absent column as "enabled", so the code tolerates deploying before
-- this file is applied; a club with a NULL slug simply has no page until the
-- backfill below runs.
--
-- Additive only. The backfill writes slug WHERE it is NULL or blank; nothing
-- already set is touched.
--
-- REVERSE:
--   DROP TABLE IF EXISTS club_contact_messages;
--   DROP INDEX IF EXISTS club_profile_slug_unique;
--   ALTER TABLE club_profile DROP COLUMN IF EXISTS public_page_tagline;
--   ALTER TABLE club_profile DROP COLUMN IF EXISTS public_page_enabled;
--   -- slug values written by the backfill are left in place: they are harmless
--   -- and the fundraiser URLs (/donate/<slug>/...) may already have been shared.

ALTER TABLE club_profile
    ADD COLUMN IF NOT EXISTS public_page_enabled BOOLEAN NOT NULL DEFAULT TRUE;

ALTER TABLE club_profile
    ADD COLUMN IF NOT EXISTS public_page_tagline VARCHAR(160) NULL;

COMMENT ON COLUMN club_profile.public_page_enabled IS
    'Is /club/<slug> reachable? Default on; a club admin turns it off on Club Profile > Public Page.';
COMMENT ON COLUMN club_profile.public_page_tagline IS
    'One optional line shown under the club name on the public link page.';

-- Backfill a slug for every club that has none. Same rule as
-- te_club_slug_from_name() in lib/club_public_page.php: lowercase, runs of
-- anything but [a-z0-9] become one dash, trimmed, capped at 60, at least 3
-- characters (a name that yields fewer becomes club-<id>), de-duplicated
-- case-insensitively against every other club with -2, -3 ...
DO $$
DECLARE
    r RECORD;
    base TEXT;
    candidate TEXT;
    n INTEGER;
BEGIN
    FOR r IN SELECT id, name FROM club_profile WHERE slug IS NULL OR btrim(slug) = '' ORDER BY id LOOP
        base := btrim(regexp_replace(lower(coalesce(r.name, '')), '[^a-z0-9]+', '-', 'g'), '-');
        base := left(base, 60);
        base := btrim(base, '-');
        IF length(base) < 3 THEN
            base := 'club-' || r.id::text;
        END IF;
        candidate := base;
        n := 1;
        WHILE EXISTS (SELECT 1 FROM club_profile c WHERE lower(c.slug) = candidate AND c.id <> r.id) LOOP
            n := n + 1;
            candidate := left(base, 60 - length('-' || n::text)) || '-' || n::text;
        END LOOP;
        UPDATE club_profile SET slug = candidate WHERE id = r.id;
    END LOOP;
END $$;

CREATE UNIQUE INDEX IF NOT EXISTS club_profile_slug_unique
    ON club_profile (lower(slug)) WHERE slug IS NOT NULL;

CREATE TABLE IF NOT EXISTS club_contact_messages (
    id          SERIAL PRIMARY KEY,
    club_id     INTEGER NOT NULL REFERENCES club_profile(id) ON DELETE CASCADE,
    name        VARCHAR(100) NOT NULL,
    email       VARCHAR(254) NOT NULL,
    phone       VARCHAR(40) NULL,
    message     TEXT NOT NULL,
    ip_address  VARCHAR(45) NULL,
    user_agent  VARCHAR(255) NULL,
    -- new: stored, notification not yet attempted; sent: at least one admin
    -- was mailed; failed: every send failed (or no admin has an address).
    status      VARCHAR(10) NOT NULL DEFAULT 'new' CHECK (status IN ('new', 'sent', 'failed')),
    sent_to     JSONB NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_club_contact_messages_club_created
    ON club_contact_messages (club_id, created_at DESC);

-- The public form's rate limit counts rows by ip_address in the last hour.
CREATE INDEX IF NOT EXISTS idx_club_contact_messages_ip_created
    ON club_contact_messages (ip_address, created_at DESC);

COMMENT ON TABLE club_contact_messages IS
    'Messages from the public club link page contact form. Stored before the admin notification is attempted.';
