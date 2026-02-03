-- Migration: 011_tryout_session_name.sql
-- Description: Add name field to tryout sessions
-- Created: 2026-02-03

-- Add name column to tryout_sessions
ALTER TABLE tryout_sessions ADD COLUMN IF NOT EXISTS name VARCHAR(100);

-- Comment
COMMENT ON COLUMN tryout_sessions.name IS 'Display name for the session (e.g., U9 Girls, U12-U14 Boys)';
