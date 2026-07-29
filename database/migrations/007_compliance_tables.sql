-- 007_compliance_tables.sql
--
-- BACK-FILLED 2026-07-29. These tables have existed in production since the
-- Feb 2026 COPPA work (COPPA-COMPLIANCE.md), but the migration file itself was
-- lost along with lib/Encryption.php, lib/AuditLogger.php and api/consent.php.
-- Recorded here so the schema is reproducible from the repo rather than only
-- from the live database.
--
-- Written to match what is actually in Neon today, verified against
-- information_schema — not from the original file, which no longer exists.
--
-- Safe to re-run.

CREATE TABLE IF NOT EXISTS consent_records (
    id                  SERIAL PRIMARY KEY,
    guardian_id         INTEGER NOT NULL REFERENCES users (id),
    athlete_id          INTEGER NOT NULL REFERENCES athletes (id),
    consent_type        VARCHAR(50) NOT NULL,
    consent_given       BOOLEAN NOT NULL DEFAULT FALSE,
    consented_at        TIMESTAMP,
    ip_address          VARCHAR(45),
    user_agent          TEXT,
    consent_version     VARCHAR(20),
    confirmation_token  VARCHAR(128),
    email_sent_at       TIMESTAMP,
    email_confirmed_at  TIMESTAMP,
    revoked_at          TIMESTAMP,
    CONSTRAINT consent_type_check CHECK (consent_type IN
        ('data_collection', 'medical_data', 'emergency_treatment', 'tos_privacy'))
);

CREATE TABLE IF NOT EXISTS audit_log (
    id            SERIAL PRIMARY KEY,
    user_id       INTEGER,
    action        VARCHAR(100) NOT NULL,
    resource_type VARCHAR(100),
    resource_id   INTEGER,
    ip_address    VARCHAR(45),
    user_agent    TEXT,
    details       JSONB,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS data_retention_policy (
    id             SERIAL PRIMARY KEY,
    data_type      VARCHAR(100) NOT NULL UNIQUE,
    retention_days INTEGER NOT NULL,
    description    TEXT,
    auto_delete    BOOLEAN NOT NULL DEFAULT FALSE,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Plaintext-fallback flag for the application-level encryption in lib/Encryption.php.
ALTER TABLE medical_records ADD COLUMN IF NOT EXISTS is_encrypted BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE medications     ADD COLUMN IF NOT EXISTS is_encrypted BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE allergies       ADD COLUMN IF NOT EXISTS is_encrypted BOOLEAN NOT NULL DEFAULT FALSE;
