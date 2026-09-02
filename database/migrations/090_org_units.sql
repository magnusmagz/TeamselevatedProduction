-- ============================================================================
-- 090_org_units.sql — GOTR G1: a tree above the club (hierarchy, dark)
-- ============================================================================
-- Girls on the Run is national -> division -> council -> site. A council maps
-- onto an existing `club_profile` row, so every scope predicate, page, import
-- and permission in the product keeps working for council staff unchanged. What
-- is missing is the tier ABOVE the club, and a way to say "this person
-- administers everything under division 4".
--
-- Additive and inert. Nothing in the product reads any of this yet: `org_unit_id`
-- is nullable and every existing club leaves it NULL, so CKU and club 32 are
-- untouched. `lib/org_scope.php` is the only reader and it probes for these
-- objects, so the code is safe to ship before this file is applied by hand.
--
-- The shape is migration 001's `user_league_access` — the league tier that was
-- built and deliberately collapsed in 004 — with the audit columns it already
-- carried (granted_at/granted_by/revoked_at/revoked_by/active) and its
-- UNIQUE(user, scope, role).
--
-- `path` is a MATERIALISED PATH ('/1/4/17/'), maintained by the PHP writer in
-- lib/org_scope.php, not by a trigger. "Every council under division 4" is then
-- `path LIKE '/1/4/%'` against a text_pattern_ops index — no recursive CTE per
-- request and no closure table to keep consistent. The trailing slash is not
-- decoration: without it '/1/4%' also matches '/1/40/'. Because `%` matches the
-- empty string, '/1/4/%' also matches the division's own row, so a prefix search
-- includes the unit itself, which is what "everything under me" has to mean.
--
-- REVERSE SQL (run top to bottom):
--   DROP INDEX IF EXISTS idx_user_org_access_active;
--   DROP INDEX IF EXISTS idx_user_org_access_org_unit;
--   DROP INDEX IF EXISTS idx_user_org_access_user;
--   DROP TABLE IF EXISTS user_org_access;
--   DROP INDEX IF EXISTS idx_club_profile_org_unit;
--   ALTER TABLE club_profile DROP COLUMN IF EXISTS org_unit_id;
--   DROP INDEX IF EXISTS idx_org_units_parent;
--   DROP INDEX IF EXISTS idx_org_units_path;
--   DROP TABLE IF EXISTS org_units;
-- ============================================================================

-- ---------------------------------------------------------------- the tree ---
CREATE TABLE IF NOT EXISTS org_units (
    id            SERIAL PRIMARY KEY,
    parent_id     INTEGER NULL REFERENCES org_units(id),
    type          VARCHAR(20) NOT NULL CHECK (type IN ('national', 'division', 'council')),
    name          VARCHAR(255) NOT NULL,
    external_code VARCHAR(100),
    -- Materialised path, '/id/id/id/'. NOT NULL because a row whose path is
    -- unknown is invisible to every prefix search — it would silently drop out
    -- of its own ancestors' scope rather than fail loudly.
    path          TEXT NOT NULL,
    depth         INTEGER NOT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- text_pattern_ops, not the default opclass: on a non-C collation the default
-- btree opclass cannot serve `LIKE 'prefix%'` at all, so the index would exist
-- and never be used.
CREATE INDEX IF NOT EXISTS idx_org_units_path ON org_units (path text_pattern_ops);
CREATE INDEX IF NOT EXISTS idx_org_units_parent ON org_units (parent_id);

-- ------------------------------------------------- a council IS a club row ---
-- Nullable forever. Non-GOTR clubs never carry one, and the resolver treats a
-- NULL as "reachable only through user_club_access", which is today's behaviour.
ALTER TABLE club_profile ADD COLUMN IF NOT EXISTS org_unit_id INTEGER NULL REFERENCES org_units(id);
CREATE INDEX IF NOT EXISTS idx_club_profile_org_unit ON club_profile (org_unit_id);

-- ------------------------------------------------------- standing at a tier ---
-- Two roles only. `org_admin` manages (requirements, review, edit); `org_viewer`
-- reads rollups. Both INHERIT DOWN in lib/org_scope.php — an admin on a division
-- is an admin over its councils — so a national admin is one row, not 270.
CREATE TABLE IF NOT EXISTS user_org_access (
    id          SERIAL PRIMARY KEY,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    org_unit_id INTEGER NOT NULL REFERENCES org_units(id) ON DELETE CASCADE,
    role        VARCHAR(20) NOT NULL CHECK (role IN ('org_admin', 'org_viewer')),
    granted_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    granted_by  INTEGER REFERENCES users(id),
    revoked_at  TIMESTAMP,
    revoked_by  INTEGER REFERENCES users(id),
    active      BOOLEAN DEFAULT TRUE,
    UNIQUE (user_id, org_unit_id, role)
);

-- Revocation is a fact recorded next to the grant, never a DELETE: "who could
-- see this council in March" has to stay answerable. The resolver requires BOTH
-- `active` and `revoked_at IS NULL` — lib/JWT.php was minting a revoked role for
-- a year because those two columns can disagree and it only read one.
CREATE INDEX IF NOT EXISTS idx_user_org_access_user ON user_org_access (user_id);
CREATE INDEX IF NOT EXISTS idx_user_org_access_org_unit ON user_org_access (org_unit_id);
CREATE INDEX IF NOT EXISTS idx_user_org_access_active ON user_org_access (active);
