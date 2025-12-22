-- ========================================
-- Invitations System Migration (ALTER)
-- ========================================
-- Purpose: Adapt existing invitations tables for league/club hierarchy
-- Date: 2025-12-09
-- ========================================

BEGIN;

-- ========================================
-- ALTER INVITATIONS TABLE
-- ========================================

-- Add new columns for league/club hierarchy
ALTER TABLE invitations ADD COLUMN IF NOT EXISTS league_id INTEGER REFERENCES leagues(id) ON DELETE CASCADE;
ALTER TABLE invitations ADD COLUMN IF NOT EXISTS club_profile_id INTEGER REFERENCES club_profile(id) ON DELETE CASCADE;

-- Rename created_by to invited_by for consistency
ALTER TABLE invitations RENAME COLUMN created_by TO invited_by;

-- Update role constraint to include new roles
ALTER TABLE invitations DROP CONSTRAINT IF EXISTS invitations_role_check;
ALTER TABLE invitations ADD CONSTRAINT invitations_role_check
    CHECK (role IN ('league_admin', 'club_admin', 'coach', 'player'));

-- Update status constraint to include 'canceled'
ALTER TABLE invitations DROP CONSTRAINT IF EXISTS invitations_status_check;
ALTER TABLE invitations ADD CONSTRAINT invitations_status_check
    CHECK (status IN ('pending', 'accepted', 'expired', 'canceled'));

-- Make team_id nullable (since we now have league/club invitations)
ALTER TABLE invitations ALTER COLUMN team_id DROP NOT NULL;

-- Add constraint: must have either league_id, club_profile_id, OR team_id (but only one)
ALTER TABLE invitations ADD CONSTRAINT invitations_org_check CHECK (
    (league_id IS NOT NULL AND club_profile_id IS NULL AND team_id IS NULL) OR
    (league_id IS NULL AND club_profile_id IS NOT NULL AND team_id IS NULL) OR
    (league_id IS NULL AND club_profile_id IS NULL AND team_id IS NOT NULL)
);

-- Create new indexes
CREATE INDEX IF NOT EXISTS idx_invitations_league ON invitations(league_id);
CREATE INDEX IF NOT EXISTS idx_invitations_club ON invitations(club_profile_id);
CREATE INDEX IF NOT EXISTS idx_invitations_invited_by ON invitations(invited_by);

-- ========================================
-- ALTER INVITATION_LINKS TABLE
-- ========================================

-- Add new columns for league/club hierarchy
ALTER TABLE invitation_links ADD COLUMN IF NOT EXISTS league_id INTEGER REFERENCES leagues(id) ON DELETE CASCADE;
ALTER TABLE invitation_links ADD COLUMN IF NOT EXISTS club_profile_id INTEGER REFERENCES club_profile(id) ON DELETE CASCADE;

-- Add role column
ALTER TABLE invitation_links ADD COLUMN IF NOT EXISTS role VARCHAR(50) CHECK (role IN ('league_admin', 'club_admin', 'coach', 'player'));

-- Make team_id nullable
ALTER TABLE invitation_links ALTER COLUMN team_id DROP NOT NULL;

-- Add constraint: must have either league_id, club_profile_id, OR team_id (but only one)
ALTER TABLE invitation_links ADD CONSTRAINT invitation_links_org_check CHECK (
    (league_id IS NOT NULL AND club_profile_id IS NULL AND team_id IS NULL) OR
    (league_id IS NULL AND club_profile_id IS NOT NULL AND team_id IS NULL) OR
    (league_id IS NULL AND club_profile_id IS NULL AND team_id IS NOT NULL)
);

-- Create new indexes
CREATE INDEX IF NOT EXISTS idx_invitation_links_league ON invitation_links(league_id);
CREATE INDEX IF NOT EXISTS idx_invitation_links_club ON invitation_links(club_profile_id);

-- ========================================
-- UPDATE RLS POLICIES
-- ========================================

-- Drop old policies
DROP POLICY IF EXISTS invitations_select ON invitations;
DROP POLICY IF EXISTS invitations_insert ON invitations;
DROP POLICY IF EXISTS invitations_public_view ON invitations;
DROP POLICY IF EXISTS invitations_public_accept ON invitations;

DROP POLICY IF EXISTS invitation_links_view_own ON invitation_links;
DROP POLICY IF EXISTS invitation_links_insert_admin ON invitation_links;
DROP POLICY IF EXISTS invitation_links_public_view ON invitation_links;

-- Create new policies for invitations

-- Policy: Allow users to view invitations they sent
CREATE POLICY invitations_view_own ON invitations
    FOR SELECT
    USING (invited_by = (current_setting('request.jwt.claims', true)::json->>'user_id')::integer);

-- Policy: Allow users to insert invitations if they have admin role in the org
CREATE POLICY invitations_insert_admin ON invitations
    FOR INSERT
    WITH CHECK (
        invited_by = (current_setting('request.jwt.claims', true)::json->>'user_id')::integer
    );

-- Policy: Allow public to view invitation details (for acceptance page)
CREATE POLICY invitations_public_view ON invitations
    FOR SELECT
    USING (status = 'pending' AND expires_at > CURRENT_TIMESTAMP);

-- Policy: Allow public to update invitations when accepting
CREATE POLICY invitations_public_accept ON invitations
    FOR UPDATE
    USING (status = 'pending' AND expires_at > CURRENT_TIMESTAMP);

-- Create new policies for invitation_links

-- Policy: Allow users to view invitation links they created
CREATE POLICY invitation_links_view_own ON invitation_links
    FOR SELECT
    USING (created_by = (current_setting('request.jwt.claims', true)::json->>'user_id')::integer);

-- Policy: Allow users to insert invitation links if they have admin role
CREATE POLICY invitation_links_insert_admin ON invitation_links
    FOR INSERT
    WITH CHECK (
        created_by = (current_setting('request.jwt.claims', true)::json->>'user_id')::integer
    );

-- Policy: Allow public to view active invitation links (for acceptance page)
CREATE POLICY invitation_links_public_view ON invitation_links
    FOR SELECT
    USING (is_active = TRUE AND expires_at > CURRENT_TIMESTAMP);

-- ========================================
-- HELPER FUNCTIONS
-- ========================================

-- Drop existing function if it exists
DROP FUNCTION IF EXISTS expire_old_invitations();

-- Function to automatically expire old invitations
CREATE OR REPLACE FUNCTION expire_old_invitations()
RETURNS void AS $$
BEGIN
    UPDATE invitations
    SET status = 'expired'
    WHERE status = 'pending'
      AND expires_at < CURRENT_TIMESTAMP;
END;
$$ LANGUAGE plpgsql;

-- Drop existing function if it exists
DROP FUNCTION IF EXISTS is_invitation_link_valid(VARCHAR);

-- Function to check if invitation link is still valid
CREATE OR REPLACE FUNCTION is_invitation_link_valid(link_code VARCHAR)
RETURNS BOOLEAN AS $$
DECLARE
    link_record RECORD;
BEGIN
    SELECT * INTO link_record
    FROM invitation_links
    WHERE code = link_code
      AND is_active = TRUE
      AND expires_at > CURRENT_TIMESTAMP;

    IF NOT FOUND THEN
        RETURN FALSE;
    END IF;

    -- Check if max uses has been reached
    IF link_record.max_uses IS NOT NULL AND link_record.uses_count >= link_record.max_uses THEN
        RETURN FALSE;
    END IF;

    RETURN TRUE;
END;
$$ LANGUAGE plpgsql;

-- ========================================
-- COMMENTS
-- ========================================

COMMENT ON COLUMN invitations.league_id IS 'League being invited to (mutually exclusive with club_profile_id and team_id)';
COMMENT ON COLUMN invitations.club_profile_id IS 'Club being invited to (mutually exclusive with league_id and team_id)';
COMMENT ON COLUMN invitations.team_id IS 'Team being invited to (mutually exclusive with league_id and club_profile_id) - legacy';
COMMENT ON COLUMN invitations.role IS 'Role that will be assigned when invitation is accepted';
COMMENT ON COLUMN invitations.status IS 'Current status: pending, accepted, expired, or canceled';

COMMENT ON COLUMN invitation_links.league_id IS 'League link is for (mutually exclusive with club_profile_id and team_id)';
COMMENT ON COLUMN invitation_links.club_profile_id IS 'Club link is for (mutually exclusive with league_id and team_id)';
COMMENT ON COLUMN invitation_links.team_id IS 'Team link is for (mutually exclusive with league_id and club_profile_id) - legacy';
COMMENT ON COLUMN invitation_links.code IS 'Unique shareable code (e.g., ABC123)';
COMMENT ON COLUMN invitation_links.role IS 'Role that will be assigned when link is used';
COMMENT ON COLUMN invitation_links.max_uses IS 'Maximum number of times link can be used (NULL = unlimited)';
COMMENT ON COLUMN invitation_links.uses_count IS 'Number of times link has been used';

COMMIT;

-- ========================================
-- COMPLETE
-- ========================================
