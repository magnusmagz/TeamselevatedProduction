-- 051_athlete_medical.sql
--
-- Create the athlete health profile table that legacy/medical-gateway.php has
-- always written to and which has never existed. Until now every save of medical
-- info on the athlete form raised 42P01, was swallowed, and the UI reported
-- success — so no athlete health data has ever been stored.
--
-- COPPA-COMPLIANCE.md (Feb 2026) explicitly noted athlete_medical was missing and
-- excluded it from the health-data work. This migration brings it under that same
-- regime: encrypted free-text PHI, is_encrypted flag, FK cascade for erasure, and
-- a retention policy row.
--
-- Column set matches the gateway's INSERT exactly, so the existing read/write
-- code works unchanged.
--
-- NOTE ON OVERLAP: allergies / medications / medical_conditions are free text here
-- while structured `allergies` and `medications` tables also exist. Kept for now so
-- the broken form works today; the structured tables are the better long-term home
-- and these three should migrate there once data is actually flowing.
--
-- Safe to re-run.

CREATE TABLE IF NOT EXISTS athlete_medical (
    id                        SERIAL PRIMARY KEY,
    athlete_id                INTEGER NOT NULL,

    -- Free-text PHI. TEXT (never VARCHAR(n)) because AES-256-GCM ciphertext is
    -- substantially longer than its plaintext — a length cap here would silently
    -- truncate ciphertext into something undecryptable.
    allergies                 TEXT,
    medical_conditions        TEXT,
    medications               TEXT,
    special_instructions      TEXT,
    concussion_history        TEXT,
    physician_name            TEXT,
    physician_phone           TEXT,
    physician_address         TEXT,
    insurance_provider        TEXT,
    insurance_policy_number   TEXT,
    insurance_group_number    TEXT,
    blood_type                TEXT,
    inhaler_location          TEXT,
    epipen_location           TEXT,

    -- Deliberately NOT encrypted: the application reasons about these to build
    -- medical alerts (severe allergy, EpiPen, asthma, expired physical, concussion
    -- protocol). Encrypting them would break alerting or leak ciphertext into an
    -- alert message.
    allergy_severity          VARCHAR(32),
    has_asthma                BOOLEAN NOT NULL DEFAULT FALSE,
    has_epipen                BOOLEAN NOT NULL DEFAULT FALSE,
    emergency_treatment_consent BOOLEAN NOT NULL DEFAULT TRUE,
    last_physical_date        DATE,
    physical_expiry_date      DATE,
    last_concussion_date      DATE,
    return_to_play_date       DATE,
    height_inches             NUMERIC(5,2),
    weight_lbs                NUMERIC(6,2),

    -- Mirrors medical_records / medications / allergies so the documented
    -- plaintext-fallback read path works consistently across health tables.
    is_encrypted              BOOLEAN NOT NULL DEFAULT FALSE,

    created_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- One health profile per athlete; the gateway upserts on athlete_id.
    CONSTRAINT athlete_medical_athlete_id_key UNIQUE (athlete_id),

    -- ON DELETE CASCADE so the athlete_profiles retention cascade described in
    -- DATA-RETENTION-SETUP.md reaches this table. Without it, deleting an athlete
    -- would orphan their health record — the exact opposite of right-to-erasure.
    CONSTRAINT athlete_medical_athlete_id_fkey
        FOREIGN KEY (athlete_id) REFERENCES athletes (id) ON DELETE CASCADE
);

-- Bring the table under the documented retention regime rather than leaving it as
-- the one health table nothing ever purges. auto_delete stays FALSE to match the
-- other seeded policies (flag for review, don't silently destroy).
INSERT INTO data_retention_policy (data_type, retention_days, description, auto_delete, created_at, updated_at)
SELECT 'athlete_medical', 2555,
       'Athlete health profiles (allergies, conditions, medications, physician, insurance). Cascades with athlete deletion.',
       FALSE, NOW(), NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM data_retention_policy WHERE data_type = 'athlete_medical'
);

COMMENT ON TABLE athlete_medical IS
    'Athlete health profile, one row per athlete. Free-text PHI columns are encrypted at the application layer (lib/Encryption.php, AES-256-GCM); see is_encrypted.';
