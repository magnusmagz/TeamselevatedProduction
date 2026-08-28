-- 082: cache of images we have already uploaded to Canva
--
-- Canva's asset upload is rate limited to 30 requests per minute PER USER, and
-- under the headless model every club shares one user (see migration 069). So
-- that ceiling is platform-wide, not per-club: eleven clubs each generating a
-- graphic with a logo in the same minute would exhaust it between them.
--
-- Re-uploading the same unchanged club logo on every single generate spends that
-- budget on nothing. Canva assets persist, so the upload only has to happen when
-- the BYTES change.
--
-- Keyed on a content hash rather than "club 51's logo", deliberately: a club that
-- re-uploads its logo must not keep getting the old artwork, and a club that
-- changes it back should not pay to upload it twice. The hash answers both
-- without any cache invalidation logic to get wrong.

CREATE TABLE IF NOT EXISTS canva_assets (
    id                SERIAL PRIMARY KEY,

    -- Which image this is, for humans reading the table: 'club_logo',
    -- 'sponsor_logo'. Not part of the identity — the hash is.
    source_key        VARCHAR(64) NOT NULL,

    -- sha256 of the raw bytes as uploaded.
    content_hash      CHAR(64) NOT NULL,

    canva_asset_id    VARCHAR(128) NOT NULL,
    byte_size         INTEGER,
    mime_type         VARCHAR(64),

    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- One row per distinct image. Not scoped by club on purpose: two clubs sharing a
-- sponsor's logo file should share the upload, and the hash makes that safe.
CREATE UNIQUE INDEX IF NOT EXISTS canva_assets_content_hash
    ON canva_assets (content_hash);
