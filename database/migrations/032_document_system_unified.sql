-- Migration 032: Unified document system (Path B from project_document_system_rebuild.md)
--
-- Replaces the half-finished polymorphic `documents` table (entity_type/entity_id)
-- with a 3-table model:
--
--   documents              — single source of truth for document metadata + file/link
--   document_assignments   — many-to-many: a doc can target multiple clubs/teams/athletes/users
--   document_acknowledgments — coach signing for compliance (matches project_coach_document_compliance.md)
--
-- target_type='user' covers BOTH coaches AND volunteers — they're all in the users table.
--
-- Existing club_documents data is copied into documents + document_assignments
-- (target_type='club'). The original club_documents table is left intact for
-- the monitoring period; cleanup happens in a follow-on migration.
--
-- The pre-existing empty `documents` table (entity_type/entity_id model) is
-- renamed to _pre_unified_documents_v1 rather than dropped, matching the
-- _backup_league_documents pattern from migration 004.

-- =====================================================
-- PHASE 1: PRESERVE THE OLD documents TABLE
-- =====================================================

DO $$
BEGIN
    IF EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'documents') THEN
        ALTER TABLE documents RENAME TO _pre_unified_documents_v1;
    END IF;
END $$;

-- =====================================================
-- PHASE 2: NEW documents TABLE
-- =====================================================

CREATE TABLE IF NOT EXISTS documents (
    id              SERIAL PRIMARY KEY,
    club_profile_id INTEGER NOT NULL REFERENCES club_profile(id) ON DELETE CASCADE,
    title           VARCHAR(255) NOT NULL,
    description     TEXT,
    -- Either file_path (uploaded file) OR link_url (external URL) must be set
    file_path       VARCHAR(500),
    file_name       VARCHAR(255),
    file_size       BIGINT,
    mime_type       VARCHAR(100),
    link_url        VARCHAR(500),
    -- Optional compliance category (background_check, cpr, code_of_conduct, etc.)
    -- Free-form so admins can add custom slots without schema changes
    slot            VARCHAR(50),
    notes           TEXT,
    is_required     BOOLEAN NOT NULL DEFAULT false,
    expires_at      TIMESTAMPTZ,
    uploaded_by     INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT documents_source_required CHECK (file_path IS NOT NULL OR link_url IS NOT NULL)
);

CREATE INDEX IF NOT EXISTS idx_documents_club ON documents(club_profile_id);
CREATE INDEX IF NOT EXISTS idx_documents_slot ON documents(club_profile_id, slot) WHERE slot IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_documents_expires ON documents(expires_at) WHERE expires_at IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_documents_uploaded_by ON documents(uploaded_by);

-- =====================================================
-- PHASE 3: document_assignments (many-to-many)
-- =====================================================

CREATE TABLE IF NOT EXISTS document_assignments (
    id           SERIAL PRIMARY KEY,
    document_id  INTEGER NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
    target_type  VARCHAR(20) NOT NULL,
    target_id    INTEGER NOT NULL,
    assigned_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
    assigned_at  TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT document_assignments_target_type_check
        CHECK (target_type IN ('club','team','athlete','user')),
    CONSTRAINT document_assignments_unique
        UNIQUE (document_id, target_type, target_id)
);

CREATE INDEX IF NOT EXISTS idx_doc_assignments_target
    ON document_assignments(target_type, target_id);
CREATE INDEX IF NOT EXISTS idx_doc_assignments_doc
    ON document_assignments(document_id);

-- =====================================================
-- PHASE 4: document_acknowledgments
-- =====================================================
-- Matches the schema specified in project_coach_document_compliance.md so the
-- compliance feature can drop on top without rework.

CREATE TABLE IF NOT EXISTS document_acknowledgments (
    id              SERIAL PRIMARY KEY,
    document_id     INTEGER NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
    user_id         INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    acknowledged_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    signature       TEXT,
    ip_address      VARCHAR(45),
    expires_at      TIMESTAMPTZ,

    CONSTRAINT document_acknowledgments_unique UNIQUE (document_id, user_id)
);

CREATE INDEX IF NOT EXISTS idx_doc_acks_user ON document_acknowledgments(user_id);
CREATE INDEX IF NOT EXISTS idx_doc_acks_doc ON document_acknowledgments(document_id);

-- =====================================================
-- PHASE 5: BACKFILL FROM club_documents
-- =====================================================
-- Each existing club_documents row becomes one documents row with a single
-- club-scoped assignment. We preserve the original id by INSERTing it
-- explicitly so any code that ends up referencing old ids keeps working.
--
-- Field mapping:
--   club_documents.name      -> documents.title
--   club_documents.url       -> documents.file_path (if file_type set) else link_url
--   club_documents.link_url  -> documents.link_url (overrides above when present)
--   club_documents.slot      -> documents.slot
--   club_documents.notes     -> documents.notes
--   club_documents.file_type -> documents.mime_type (best effort)

-- file_type set => uploaded file; cd.url is the file path
-- file_type NULL => external link; cd.url is a link, route it to link_url
INSERT INTO documents (
    id, club_profile_id, title, description,
    file_path, file_name, mime_type,
    link_url, slot, notes,
    is_required, uploaded_by, created_at, updated_at
)
SELECT
    cd.id,
    cd.club_profile_id,
    cd.name,
    NULL,  -- description
    CASE WHEN cd.file_type IS NOT NULL THEN cd.url ELSE NULL END,
    NULL,  -- file_name unknown for legacy rows
    cd.file_type,
    CASE WHEN cd.file_type IS NOT NULL
         THEN NULLIF(cd.link_url, '')
         ELSE COALESCE(NULLIF(cd.link_url, ''), cd.url)
    END,
    cd.slot,
    cd.notes,
    false,
    cd.created_by,
    cd.created_at,
    cd.updated_at
FROM club_documents cd
WHERE NOT EXISTS (SELECT 1 FROM documents d WHERE d.id = cd.id);

-- Sync the sequence past any backfilled ids
SELECT setval(
    pg_get_serial_sequence('documents', 'id'),
    COALESCE((SELECT MAX(id) FROM documents), 1),
    true
);

-- One assignment row per backfilled document, scoped to its owner club
INSERT INTO document_assignments (document_id, target_type, target_id, assigned_by, assigned_at)
SELECT
    d.id,
    'club',
    d.club_profile_id,
    d.uploaded_by,
    d.created_at
FROM documents d
WHERE NOT EXISTS (
    SELECT 1 FROM document_assignments da
    WHERE da.document_id = d.id
      AND da.target_type = 'club'
      AND da.target_id = d.club_profile_id
);

-- =====================================================
-- PHASE 6: VERIFICATION (visible in psql output)
-- =====================================================

DO $$
DECLARE
    cd_count INTEGER;
    d_count  INTEGER;
    a_count  INTEGER;
BEGIN
    SELECT COUNT(*) INTO cd_count FROM club_documents;
    SELECT COUNT(*) INTO d_count  FROM documents;
    SELECT COUNT(*) INTO a_count  FROM document_assignments;

    RAISE NOTICE 'Migration 032 verification:';
    RAISE NOTICE '  club_documents rows: %', cd_count;
    RAISE NOTICE '  documents rows: %', d_count;
    RAISE NOTICE '  document_assignments rows: %', a_count;

    IF d_count < cd_count THEN
        RAISE EXCEPTION 'Backfill incomplete: documents (%) < club_documents (%)', d_count, cd_count;
    END IF;
END $$;
