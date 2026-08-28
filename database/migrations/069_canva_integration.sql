-- 069: Canva Connect integration — token storage, per-club brand templates, generated media
--
-- Model (decided 2026-08-17): HEADLESS. Teams Elevated holds ONE Canva Connect token
-- for a service user inside our own Canva Enterprise org. Club staff never log into
-- Canva and never consume a Canva seat.
--
-- That is not a preference, it is what the API permits. The Autofill API requires the
-- token's user to be "a member of a Canva Enterprise organization" — the END user, not
-- just the integration developer. No youth club has Canva Enterprise, so the obvious
-- design (each club connects their own Canva account) cannot autofill anything, which
-- is the entire feature. Brand Kits are likewise org-scoped: they can be allocated to
-- groups and folders WITHIN an org, never shared outside it.
--
-- A per-club token column exists anyway (club_profile_id, nullable) so the future
-- "club admin holds a real seat in our org" model lands as rows here rather than a
-- second near-identical table. Nothing writes it today.


-- ─────────────────────────────────────────────────────────────────────────────
-- OAuth tokens
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS canva_integrations (
    id                       SERIAL PRIMARY KEY,

    -- NULL = the platform service account (the headless model).
    -- NOT NULL = a specific club's own connected Canva user (not used yet).
    club_profile_id          INTEGER REFERENCES club_profile(id) ON DELETE CASCADE,

    canva_user_id            VARCHAR(128),
    canva_team_id            VARCHAR(128),

    -- Encrypted via lib/Encryption.php (enc:v1:...). These are bearer credentials to
    -- an account holding every club's brand assets; a plaintext leak here is a leak of
    -- all of them at once. Encryption::encrypt() throws when unconfigured rather than
    -- silently storing plaintext, which is the behaviour we want on this column.
    access_token             TEXT NOT NULL,
    refresh_token            TEXT NOT NULL,

    access_token_expires_at  TIMESTAMP NOT NULL,

    -- Space-separated, as returned by the token endpoint. Recorded so a 403 from a
    -- newly-added endpoint is diagnosable as "we never asked for that scope" instead
    -- of being mistaken for a plan/permission problem.
    scopes                   TEXT,

    -- Bumped on every successful refresh. See the WARNING below.
    refresh_count            INTEGER NOT NULL DEFAULT 0,
    last_refreshed_at        TIMESTAMP,

    created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ⚠️ CANVA REFRESH TOKENS ARE SINGLE-USE AND ROTATE.
--
-- Every refresh returns a NEW refresh token and invalidates the one just used. So two
-- concurrent refreshes race: both read the same stored token, both call the endpoint,
-- one wins, and the loser's response contains a token that was already superseded —
-- which silently disconnects the integration until someone re-authorises by hand.
--
-- This is a live risk here, not a theoretical one: the queue worker and a web request
-- can both want a token in the same second. CanvaClient::accessToken() therefore takes
-- a row lock (SELECT ... FOR UPDATE) around the whole read-refresh-write cycle. Do not
-- "optimise" that lock away, and do not cache tokens in a process that skips it.
--
-- Exactly ONE platform row may exist, for the same reason — a second row is a second
-- refresh lineage competing for the same Canva session.
CREATE UNIQUE INDEX IF NOT EXISTS canva_integrations_one_platform_row
    ON canva_integrations ((club_profile_id IS NULL))
    WHERE club_profile_id IS NULL;

CREATE UNIQUE INDEX IF NOT EXISTS canva_integrations_one_per_club
    ON canva_integrations (club_profile_id)
    WHERE club_profile_id IS NOT NULL;


-- ─────────────────────────────────────────────────────────────────────────────
-- Which Canva brand template backs which club + graphic type
-- ─────────────────────────────────────────────────────────────────────────────
--
-- One row per (club, graphic_type). A club needs its OWN template per type because
-- the Autofill API can fill text, images, charts and sheets — but NOT colors. A club's
-- primary color therefore cannot be passed as data; it has to already be baked into
-- the template (or into its allocated Brand Kit). That single API limitation is what
-- makes this a per-club table instead of a platform-wide list of four templates.
--
-- Consequence to keep in view: N clubs x M graphic types templates to maintain by
-- hand. If that becomes the bottleneck, the escape hatch is neutral templates whose
-- only club-specific elements are autofillable IMAGES (logo, plus a pre-rendered
-- color bar TE generates per club) — fewer templates, less branding.
CREATE TABLE IF NOT EXISTS canva_brand_templates (
    id                       SERIAL PRIMARY KEY,
    club_profile_id          INTEGER NOT NULL REFERENCES club_profile(id) ON DELETE CASCADE,

    -- game_day | final_score | schedule | player_spotlight | tryout | sponsor_thanks
    -- Deliberately a plain VARCHAR, not a CHECK: the catalog is a product decision
    -- expected to churn weekly during the pilot, and a CHECK here means a migration
    -- every time someone adds a template.
    graphic_type             VARCHAR(50) NOT NULL,

    canva_brand_template_id  VARCHAR(128) NOT NULL,
    title                    TEXT,

    -- Cached response of GET /v1/brand-templates/{id}/dataset — the field names and
    -- types this template will accept. Cached because autofill fails wholesale on an
    -- unknown field name, so we validate our payload before spending the API call.
    -- Refreshed on demand; treat as a hint, never as authority. A designer editing the
    -- template in Canva changes the real dataset without telling us.
    dataset                  JSONB,
    dataset_fetched_at       TIMESTAMP,

    is_active                BOOLEAN NOT NULL DEFAULT TRUE,
    created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX IF NOT EXISTS canva_brand_templates_one_active_per_type
    ON canva_brand_templates (club_profile_id, graphic_type)
    WHERE is_active;


-- ─────────────────────────────────────────────────────────────────────────────
-- Generated graphics
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS club_media_assets (
    id                       SERIAL PRIMARY KEY,
    club_profile_id          INTEGER NOT NULL REFERENCES club_profile(id) ON DELETE CASCADE,
    created_by               INTEGER REFERENCES users(id) ON DELETE SET NULL,

    source                   VARCHAR(20) NOT NULL DEFAULT 'canva',
    graphic_type             VARCHAR(50),

    -- What the graphic is ABOUT. Nullable — a tryout flyer references no event.
    calendar_event_id        INTEGER REFERENCES calendar_events(id) ON DELETE SET NULL,
    team_id                  INTEGER REFERENCES teams(id) ON DELETE SET NULL,

    canva_design_id          VARCHAR(128),

    -- Canva's own edit URL for the generated design. Only useful to someone holding a
    -- seat in our org, so it is stored but not surfaced in the club-facing UI under
    -- the headless model.
    canva_edit_url           TEXT,

    -- ⚠️ Canva export URLs EXPIRE. Storing only the URL produces a media library whose
    -- thumbnails are all broken a day later, with no way to regenerate (the design may
    -- since have been deleted). So the bytes are downloaded and kept.
    --
    -- BYTEA rather than an object store because this codebase has no blob storage and
    -- adding S3 is a larger decision than this feature should force; club_profile.logo_png
    -- already set the precedent. Postgres TOASTs these out of the main heap so row reads
    -- that don't SELECT the column stay cheap — but never `SELECT *` from this table in a
    -- list view. Revisit if the pilot shows real volume.
    image_data               BYTEA,
    mime_type                VARCHAR(50),
    file_size                INTEGER,
    width                    INTEGER,
    height                   INTEGER,

    -- pending | rendering | ready | failed
    -- Autofill and export are both ASYNC jobs on Canva's side, so a row exists before
    -- there are any bytes. A UI that assumes image_data is present the moment the row
    -- appears will render broken images.
    status                   VARCHAR(20) NOT NULL DEFAULT 'pending',
    error_message            TEXT,

    created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS club_media_assets_club_idx
    ON club_media_assets (club_profile_id, created_at DESC);

CREATE INDEX IF NOT EXISTS club_media_assets_event_idx
    ON club_media_assets (calendar_event_id)
    WHERE calendar_event_id IS NOT NULL;


-- ─────────────────────────────────────────────────────────────────────────────
-- NOT IN THIS MIGRATION, ON PURPOSE
--
-- No media-release / likeness consent type is added here, and no athlete-featuring
-- graphic type should ship until one is. consent_records covers data collection and
-- medical data; it does not cover putting a minor's name and face on public social
-- media, and clubs will assume the platform handled that because the platform handles
-- the rest of consent. player_spotlight is listed in the graphic_type comment above as
-- a planned value, not an approved one.
--
-- The event-driven types (game_day, final_score, schedule) carry no athlete PII and
-- are safe to pilot now.
-- ─────────────────────────────────────────────────────────────────────────────
