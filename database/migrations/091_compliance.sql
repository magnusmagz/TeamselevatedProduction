-- ============================================================================
-- 091_compliance.sql — GOTR G3: person-level, expiring, verified compliance
-- ============================================================================
-- Girls on the Run needs to answer, for ~30,000 coaches and volunteers, "is
-- this person allowed to be on the field, and when does that stop being true".
-- Today the product answers it per (team, user) on `team_volunteers`, with
-- "any cleared row wins" and no expiry — so a coach cleared on a team they left
-- in 2023 is cleared everywhere, forever.
--
-- What this file adds is a PERSON-level record with an expiry date, against a
-- requirement somebody defined.
--
-- FOUR DECISIONS THIS SCHEMA ENCODES (docs/gotr-hierarchy-plan-2026-09.md §4)
--
-- 1. REQUIREMENTS ARE USER-DEFINED, NEVER A FIXED LIST. We cannot predict what
--    paperwork makes someone compliant — concussion protocol, SafeSport, a
--    state background check, a council's own training. So there is no
--    enumeration of check types anywhere: an admin names one, says what counts
--    as proof, how long it lasts, and which staff roles it applies to. `kind`
--    is a reporting CATEGORY with `custom` always available, not a vocabulary
--    the code branches on.
--
-- 2. A REQUIREMENT LIVES ON EXACTLY ONE OWNER — a tier (`org_unit_id`) or a
--    single club (`club_profile_id`) — and the CHECK enforces it. Requirements
--    INHERIT DOWN: "what does this person need" is every requirement on their
--    club plus every requirement on every ancestor org unit of that club.
--    A row with both set would be inherited twice by two different paths and a
--    row with neither would be inherited by nobody; both are worse than an
--    insert that fails.
--    Because a council IS a club_profile row, EVERY club admin gets this tool,
--    GOTR or not. CKU can define SafeSport and concussion protocol the same way.
--
-- 3. NO CHECKER IS BUILT. Nothing here runs a background check or talks to a
--    vendor. We record that a check was completed, when, and who accepted the
--    proof. `source` leaves room for a vendor or LMS feed later without the
--    model depending on one.
--
-- 4. EXPIRY IS COMPUTED, NEVER GUESSED. `expires_at = completed_at +
--    validity_days`, written once at upsert time by lib/compliance.php. It is
--    stored rather than derived on read so that an admin can override a single
--    person's date (a certificate issued with its own expiry) without editing
--    the requirement for everybody.
--
-- Additive and inert. Nothing in the product reads any of this unless
-- TE_FEATURE_COMPLIANCE is on AND these tables exist; lib/compliance.php probes
-- for them and every function degrades to an empty answer, so the code is safe
-- to ship before this file is applied by hand.
--
-- REVERSE SQL (run top to bottom):
--   DROP TABLE IF EXISTS compliance_reminder_log;
--   DROP TABLE IF EXISTS compliance_reminder_streams;
--   DROP INDEX IF EXISTS idx_person_credentials_expires;
--   DROP INDEX IF EXISTS idx_person_credentials_requirement;
--   DROP INDEX IF EXISTS idx_person_credentials_user;
--   DROP TABLE IF EXISTS person_credentials;
--   DROP INDEX IF EXISTS idx_club_staff_roles_club;
--   DROP TABLE IF EXISTS club_staff_roles;
--   DROP TABLE IF EXISTS compliance_requirement_roles;
--   DROP INDEX IF EXISTS idx_compliance_requirements_club;
--   DROP INDEX IF EXISTS idx_compliance_requirements_org_unit;
--   DROP TABLE IF EXISTS compliance_requirements;
-- ============================================================================

-- ------------------------------------------------------- the requirement ---
CREATE TABLE IF NOT EXISTS compliance_requirements (
    id               SERIAL PRIMARY KEY,

    -- Exactly one of these two. See decision 2 above.
    org_unit_id      INTEGER NULL REFERENCES org_units(id) ON DELETE CASCADE,
    club_profile_id  INTEGER NULL REFERENCES club_profile(id) ON DELETE CASCADE,

    -- A reporting category, not a behaviour switch. 'background_check' is the
    -- one value any code reads, and only so lib/background_check.php can find
    -- the rows that answer the existing volunteer gate.
    kind             VARCHAR(40) NOT NULL DEFAULT 'custom'
                     CHECK (kind IN ('background_check', 'cpr_first_aid', 'training',
                                     'document', 'custom')),

    name             VARCHAR(255) NOT NULL,
    description      TEXT,

    -- What counts as proof. 'document' is an upload, 'attested_date' is a date
    -- the person types in, 'external_link' sends them elsewhere to complete it
    -- and an admin records the result.
    proof            VARCHAR(20) NOT NULL DEFAULT 'attested_date'
                     CHECK (proof IN ('document', 'attested_date', 'external_link')),
    proof_url        TEXT,

    -- NULL means it never expires. Any number means expires_at is computed.
    validity_days    INTEGER NULL,

    -- Only a `required` row can make somebody non-compliant. An optional one
    -- still tracks, still expires and still reminds — it just does not gate.
    required         BOOLEAN NOT NULL DEFAULT TRUE,
    active           BOOLEAN NOT NULL DEFAULT TRUE,
    sort_order       INTEGER NOT NULL DEFAULT 0,

    created_by       INTEGER REFERENCES users(id),
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT compliance_requirements_one_owner CHECK (
        (org_unit_id IS NOT NULL AND club_profile_id IS NULL)
        OR (org_unit_id IS NULL AND club_profile_id IS NOT NULL)
    )
);

CREATE INDEX IF NOT EXISTS idx_compliance_requirements_org_unit
    ON compliance_requirements (org_unit_id);
CREATE INDEX IF NOT EXISTS idx_compliance_requirements_club
    ON compliance_requirements (club_profile_id);

-- ------------------------------------- which staff roles it applies to ---
-- A SET of roles, not one. Coaches and volunteers have overlapping but
-- different lists, and "head coach needs this, team helper does not" is the
-- whole reason GOTR cannot use a single per-club checklist.
--
-- A requirement with NO rows here applies to EVERY staff role in scope. That is
-- the useful default for a club admin who just wants "everyone does SafeSport",
-- and it means the roles table is an optional narrowing rather than a step
-- somebody can forget and thereby switch a requirement off for everybody.
CREATE TABLE IF NOT EXISTS compliance_requirement_roles (
    id             SERIAL PRIMARY KEY,
    requirement_id INTEGER NOT NULL REFERENCES compliance_requirements(id) ON DELETE CASCADE,
    -- The GOTR vocabulary plus the two existing user_club_access roles a person
    -- can be derived into when they have no club_staff_roles row yet. Same list
    -- as club_staff_roles.staff_role on purpose: a requirement must be able to
    -- name every role a person can actually hold, or it silently applies to
    -- nobody.
    staff_role     VARCHAR(30) NOT NULL
                   CHECK (staff_role IN ('head_coach', 'junior_coach', 'team_helper',
                                         'volunteer', 'coach', 'club_admin')),
    UNIQUE (requirement_id, staff_role)
);

-- ------------------------------------------ the GOTR role vocabulary ---
-- Additive to user_club_access, which stays exactly as it is. That table
-- answers "what may this person DO" (the permission system reads it); this one
-- answers "what is this person CALLED on the field", which is what decides
-- their paperwork. A junior coach is 16 years old and needs a different list
-- from a head coach, but both are `coach` to every permission check in the
-- product and must stay that way.
CREATE TABLE IF NOT EXISTS club_staff_roles (
    user_id         INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    club_profile_id INTEGER NOT NULL REFERENCES club_profile(id) ON DELETE CASCADE,
    staff_role      VARCHAR(30) NOT NULL
                    CHECK (staff_role IN ('head_coach', 'junior_coach', 'team_helper',
                                          'volunteer', 'coach', 'club_admin')),
    assigned_by     INTEGER REFERENCES users(id),
    assigned_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, club_profile_id, staff_role)
);

CREATE INDEX IF NOT EXISTS idx_club_staff_roles_club ON club_staff_roles (club_profile_id);

-- ------------------------------------------------ the person's record ---
CREATE TABLE IF NOT EXISTS person_credentials (
    id              SERIAL PRIMARY KEY,
    user_id         INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    requirement_id  INTEGER NOT NULL REFERENCES compliance_requirements(id) ON DELETE CASCADE,

    -- 'missing' is storable but rarely stored: the absence of a row is already
    -- missing. It exists so an admin can record "we asked, they have not got
    -- one" as a fact with a note rather than as silence.
    status          VARCHAR(20) NOT NULL DEFAULT 'missing'
                    CHECK (status IN ('missing', 'submitted', 'verified', 'rejected', 'expired')),

    -- DATE, not TIMESTAMP. A certificate is completed on a day, in the holder's
    -- own timezone, and storing an instant would make it land on the previous
    -- day for half the country. Read and written as the stored 'YYYY-MM-DD'
    -- string, never parsed into a zone-bearing value.
    completed_at    DATE,
    expires_at      DATE,

    document_id     INTEGER NULL REFERENCES documents(id) ON DELETE SET NULL,
    submitted_at    TIMESTAMP,
    verified_by     INTEGER REFERENCES users(id),
    verified_at     TIMESTAMP,
    rejection_reason TEXT,

    source          VARCHAR(20) NOT NULL DEFAULT 'admin'
                    CHECK (source IN ('portal', 'admin', 'import', 'lms', 'email')),
    notes           TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- One row per person per requirement. A renewal UPDATES the row; the
    -- history of previous certificates lives in audit_log, not in a pile of
    -- rows the rollup would then have to pick a winner from. Picking a winner
    -- is precisely the bug in team_volunteers' "any cleared row wins".
    UNIQUE (user_id, requirement_id)
);

CREATE INDEX IF NOT EXISTS idx_person_credentials_user ON person_credentials (user_id);
CREATE INDEX IF NOT EXISTS idx_person_credentials_requirement ON person_credentials (requirement_id);
-- The reminder tick and the expiry sweep both scan by date over live rows only.
CREATE INDEX IF NOT EXISTS idx_person_credentials_expires
    ON person_credentials (expires_at) WHERE status = 'verified';

-- --------------------------------------- reminder streams: data, not code ---
-- Modelled now, dispatched in a later phase (G7). An admin attaches a stream to
-- a requirement: a sequence of offsets before expiry, each with its own subject
-- and body. Stored as JSONB rather than a steps table because a stream is
-- edited and read as one whole object and never queried step-wise, and because
-- the copy is authored by a human in one form.
CREATE TABLE IF NOT EXISTS compliance_reminder_streams (
    id              SERIAL PRIMARY KEY,
    requirement_id  INTEGER NOT NULL REFERENCES compliance_requirements(id) ON DELETE CASCADE,
    -- Which tier activated it. Both NULL means the stream belongs to the
    -- requirement itself wherever that is inherited; a value narrows it to one
    -- council that wants its own copy.
    org_unit_id     INTEGER NULL REFERENCES org_units(id) ON DELETE CASCADE,
    club_profile_id INTEGER NULL REFERENCES club_profile(id) ON DELETE CASCADE,
    active          BOOLEAN NOT NULL DEFAULT FALSE,
    -- [{days_before, subject, body_markdown}, ...]
    steps           JSONB NOT NULL DEFAULT '[]'::jsonb,
    created_by      INTEGER REFERENCES users(id),
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- One send per person per step, ever. The UNIQUE is the dedupe: a tick that
-- runs twice, or a dyno that restarts mid-sweep, inserts a duplicate key and
-- fails rather than mailing 30,000 people twice.
CREATE TABLE IF NOT EXISTS compliance_reminder_log (
    id            SERIAL PRIMARY KEY,
    credential_id INTEGER NOT NULL REFERENCES person_credentials(id) ON DELETE CASCADE,
    stream_id     INTEGER NOT NULL REFERENCES compliance_reminder_streams(id) ON DELETE CASCADE,
    days_before   INTEGER NOT NULL,
    sent_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (credential_id, stream_id, days_before)
);
