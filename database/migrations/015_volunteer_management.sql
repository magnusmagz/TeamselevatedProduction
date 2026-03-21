-- Migration 015: Volunteer Management System
-- Date: 2026-03-21
-- Description: Extends team_volunteers table, creates volunteer_signups table

-- ============================================
-- 1. Add columns to team_volunteers
-- ============================================

ALTER TABLE team_volunteers
    ADD COLUMN IF NOT EXISTS status VARCHAR(20) NOT NULL DEFAULT 'active',
    ADD COLUMN IF NOT EXISTS self_signup BOOLEAN NOT NULL DEFAULT false;

-- Add check constraint for status values
ALTER TABLE team_volunteers
    DROP CONSTRAINT IF EXISTS chk_volunteer_status;
ALTER TABLE team_volunteers
    ADD CONSTRAINT chk_volunteer_status
    CHECK (status IN ('active', 'inactive', 'pending_approval'));

-- Drop old unique constraint (team_id, user_id, volunteer_role) and replace
-- with simpler one-person-per-team constraint
ALTER TABLE team_volunteers
    DROP CONSTRAINT IF EXISTS unique_team_user_role;
ALTER TABLE team_volunteers
    ADD CONSTRAINT unique_team_user UNIQUE (team_id, user_id);

-- ============================================
-- 2. Create volunteer_signups table
-- ============================================

CREATE TABLE IF NOT EXISTS volunteer_signups (
    id SERIAL PRIMARY KEY,
    team_id INT NOT NULL REFERENCES teams(id) ON DELETE CASCADE,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    requested_at TIMESTAMP NOT NULL DEFAULT NOW(),
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    reviewed_by INT REFERENCES users(id),
    reviewed_at TIMESTAMP,
    notes TEXT,
    CONSTRAINT chk_signup_status CHECK (status IN ('pending', 'approved', 'rejected'))
);

-- Prevent duplicate pending signups for same team+user
CREATE UNIQUE INDEX IF NOT EXISTS idx_volunteer_signups_pending
    ON volunteer_signups (team_id, user_id) WHERE status = 'pending';

-- ============================================
-- 3. Indexes
-- ============================================

CREATE INDEX IF NOT EXISTS idx_volunteer_signups_team ON volunteer_signups(team_id);
CREATE INDEX IF NOT EXISTS idx_volunteer_signups_user ON volunteer_signups(user_id);
CREATE INDEX IF NOT EXISTS idx_volunteer_signups_status ON volunteer_signups(status);

CREATE INDEX IF NOT EXISTS idx_team_volunteers_team ON team_volunteers(team_id);
CREATE INDEX IF NOT EXISTS idx_team_volunteers_user ON team_volunteers(user_id);
CREATE INDEX IF NOT EXISTS idx_team_volunteers_status ON team_volunteers(status);
CREATE INDEX IF NOT EXISTS idx_team_volunteers_bg_status ON team_volunteers(background_check_status);
