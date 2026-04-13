-- 017_data_imports.sql
-- Data importer: tracks bulk CSV imports (athletes + guardians first; facilities/events later)

CREATE TABLE IF NOT EXISTS import_jobs (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id),
    club_profile_id INTEGER NOT NULL REFERENCES club_profile(id),
    team_id INTEGER REFERENCES teams(id) ON DELETE SET NULL,
    entity_type VARCHAR(50) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'queued',
    original_filename VARCHAR(255),
    csv_content TEXT,
    total_rows INTEGER DEFAULT 0,
    processed_rows INTEGER DEFAULT 0,
    created_count INTEGER DEFAULT 0,
    updated_count INTEGER DEFAULT 0,
    skipped_count INTEGER DEFAULT 0,
    error_count INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT NOW(),
    started_at TIMESTAMP,
    finished_at TIMESTAMP,
    CONSTRAINT import_jobs_status_check CHECK (status IN ('queued', 'processing', 'completed', 'failed')),
    CONSTRAINT import_jobs_entity_type_check CHECK (entity_type IN ('athletes'))
);

CREATE INDEX IF NOT EXISTS idx_import_jobs_user ON import_jobs(user_id);
CREATE INDEX IF NOT EXISTS idx_import_jobs_club ON import_jobs(club_profile_id);
CREATE INDEX IF NOT EXISTS idx_import_jobs_status ON import_jobs(status);
CREATE INDEX IF NOT EXISTS idx_import_jobs_created ON import_jobs(created_at DESC);

CREATE TABLE IF NOT EXISTS import_job_errors (
    id SERIAL PRIMARY KEY,
    job_id INTEGER NOT NULL REFERENCES import_jobs(id) ON DELETE CASCADE,
    row_number INTEGER NOT NULL,
    row_json JSONB,
    error_message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_import_job_errors_job ON import_job_errors(job_id, row_number);
