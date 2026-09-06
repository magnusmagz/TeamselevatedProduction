-- ============================================================================
-- 098_compliance_intake.sql — GOTR G7: credential intake feeds.
-- ============================================================================
-- An LMS (Cornerstone, or whatever a council uses) posts "this person
-- completed this requirement on this date" to api/compliance-intake.php and
-- the product records it as a person_credentials row with source='lms'.
-- Nothing here runs a check or talks to a vendor — decision 3 in migration
-- 091 stands. This is the mailbox the results arrive in.
--
-- TWO TABLES, AND WHY EACH IS SHAPED THE WAY IT IS
--
-- 1. compliance_intake_keys — one bearer key per feed, owned by an ORG UNIT.
--    The key is what authenticates the feed, so it is stored HASHED (sha256)
--    and shown to the admin exactly once at creation. The org unit is the
--    scope: a key can only write credentials for people who hold a staff role
--    in a club under that unit, and can only be minted or revoked by an
--    org_admin of it. A key is never on a club, because the feed is a
--    council-or-above integration — a club that wants one is a council.
--    Revocation is a timestamp, never a delete: which key wrote a credential
--    must stay answerable after the key is gone.
--
-- 2. compliance_intake_unmatched — what arrived that could not be applied.
--    An LMS row for an email nobody under the unit has, or for a requirement
--    key nothing resolves to, is NOT an error to the LMS (it gets a 202) and
--    NOT silently dropped: it lands here so an admin can see it and match it
--    to a person by hand. `matched_at` / `credential_id` record that the admin
--    did, so the list is a queue and not a log. `payload` keeps the raw body
--    because the reason it did not match is usually visible only in the raw.
--
-- NEVER CREATES A USER. An unknown email is an unmatched row, full stop. A feed
-- that could mint accounts would be a way for anyone holding a key to put
-- people on the platform, and the coach onboarding path (G6) is the one place
-- accounts are made.
--
-- ADDITIVE ONLY. Two new tables, nothing altered. lib/compliance_intake.php
-- probes for them and the gateway answers 503 with a sentence until this is
-- applied — `main` is shared and deploys are by push, so the code will reach
-- production first.
--
-- REVERSE SQL (run top to bottom):
--   DROP INDEX IF EXISTS idx_compliance_intake_unmatched_unit;
--   DROP TABLE IF EXISTS compliance_intake_unmatched;
--   DROP INDEX IF EXISTS idx_compliance_intake_keys_unit;
--   DROP TABLE IF EXISTS compliance_intake_keys;
-- ============================================================================

CREATE TABLE IF NOT EXISTS compliance_intake_keys (
    id            SERIAL PRIMARY KEY,
    org_unit_id   INTEGER NOT NULL REFERENCES org_units(id) ON DELETE CASCADE,
    -- What the admin called it ("Cornerstone prod"). Display only.
    name          VARCHAR(120) NOT NULL,
    -- sha256 of the plaintext key. The plaintext is shown once and not stored.
    key_hash      VARCHAR(64) NOT NULL UNIQUE,
    -- The first few characters of the plaintext, so an admin can tell two
    -- keys apart in a list without either being recoverable.
    key_prefix    VARCHAR(12) NOT NULL,
    created_by    INTEGER REFERENCES users(id),
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_used_at  TIMESTAMP,
    revoked_at    TIMESTAMP,
    revoked_by    INTEGER REFERENCES users(id)
);

CREATE INDEX IF NOT EXISTS idx_compliance_intake_keys_unit
    ON compliance_intake_keys (org_unit_id);

CREATE TABLE IF NOT EXISTS compliance_intake_unmatched (
    id               SERIAL PRIMARY KEY,
    org_unit_id      INTEGER NOT NULL REFERENCES org_units(id) ON DELETE CASCADE,
    key_id           INTEGER NULL REFERENCES compliance_intake_keys(id) ON DELETE SET NULL,
    email            VARCHAR(255) NOT NULL,
    requirement_key  VARCHAR(120) NOT NULL,
    -- DATE, not TIMESTAMP: a completion is a calendar day (see 091).
    completed_on     DATE NULL,
    external_id      VARCHAR(255),
    -- Why it did not apply: no_person, no_requirement, bad_date.
    reason           VARCHAR(30) NOT NULL,
    payload          JSONB,
    received_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    matched_user_id  INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    credential_id    INTEGER NULL REFERENCES person_credentials(id) ON DELETE SET NULL,
    matched_by       INTEGER NULL REFERENCES users(id),
    matched_at       TIMESTAMP NULL
);

-- The admin list is "everything still open under my unit", newest first.
CREATE INDEX IF NOT EXISTS idx_compliance_intake_unmatched_unit
    ON compliance_intake_unmatched (org_unit_id, received_at DESC)
    WHERE matched_at IS NULL;
