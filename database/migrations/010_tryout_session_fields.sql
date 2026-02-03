-- Migration: 010_tryout_session_fields.sql
-- Description: Add rain date, age group, and gender fields to tryout sessions
-- Created: 2026-02-03

-- ============================================
-- ADD NEW COLUMNS TO TRYOUT_SESSIONS
-- ============================================

-- Rain date flag - marks sessions as backup/rain dates
ALTER TABLE tryout_sessions ADD COLUMN IF NOT EXISTS is_rain_date BOOLEAN DEFAULT FALSE;

-- Age group for the session (e.g., "U9", "U10", "U11-U12")
ALTER TABLE tryout_sessions ADD COLUMN IF NOT EXISTS age_group VARCHAR(30);

-- Gender for the session (e.g., "Boys", "Girls", "Coed")
ALTER TABLE tryout_sessions ADD COLUMN IF NOT EXISTS gender VARCHAR(20);

-- ============================================
-- INDEXES
-- ============================================
CREATE INDEX IF NOT EXISTS idx_tryout_sessions_age_group ON tryout_sessions(age_group);
CREATE INDEX IF NOT EXISTS idx_tryout_sessions_gender ON tryout_sessions(gender);
CREATE INDEX IF NOT EXISTS idx_tryout_sessions_rain_date ON tryout_sessions(is_rain_date);

-- ============================================
-- COMMENTS
-- ============================================
COMMENT ON COLUMN tryout_sessions.is_rain_date IS 'True if this is a backup/rain date session';
COMMENT ON COLUMN tryout_sessions.age_group IS 'Age group for this session (e.g., U9, U10-U12)';
COMMENT ON COLUMN tryout_sessions.gender IS 'Gender restriction for this session (Boys, Girls, Coed)';
