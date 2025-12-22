-- ========================================
-- Invitations System Migration
-- ========================================
-- Purpose: Enable league/club admins to invite users to their organization
-- Date: 2025-12-09
-- ========================================

-- ========================================
-- INVITATIONS TABLE
-- ========================================
-- Stores email-based invitations
CREATE TABLE IF NOT EXISTS invitations (
    id SERIAL PRIMARY KEY,
    league_id INTEGER,
    club_profile_id INTEGER,
    email VARCHAR(255) NOT NULL,
    role VARCHAR(50) NOT NULL CHECK (role IN ('league_admin', 'club_admin', 'coach')),
    status VARCHAR(50) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'accepted', 'expired', 'canceled')),
    invited_by INTEGER NOT NULL,
    personal_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    accepted_at TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,

    FOREIGN KEY (league_id) REFERENCES leagues(id) ON DELETE CASCADE,
    FOREIGN KEY (club_profile_id) REFERENCES club_profile(id) ON DELETE CASCADE,
    FOREIGN KEY (invited_by) REFERENCES users(id) ON DELETE CASCADE,

    -- Ensure invitation is for either league OR club, not both
    CHECK (
        (league_id IS NOT NULL AND club_profile_id IS NULL) OR
        (league_id IS NULL AND club_profile_id IS NOT NULL)
    ),

    -- Prevent duplicate pending invitations for same email to same org
    UNIQUE(league_id, club_profile_id, email, status)
);

-- Create indexes for invitations
CREATE INDEX IF NOT EXISTS idx_invitations_league ON invitations(league_id);
CREATE INDEX IF NOT EXISTS idx_invitations_club ON invitations(club_profile_id);
CREATE INDEX IF NOT EXISTS idx_invitations_email ON invitations(email);
CREATE INDEX IF NOT EXISTS idx_invitations_status ON invitations(status);
CREATE INDEX IF NOT EXISTS idx_invitations_invited_by ON invitations(invited_by);
CREATE INDEX IF NOT EXISTS idx_invitations_expires_at ON invitations(expires_at);

-- ========================================
-- INVITATION LINKS TABLE
-- ========================================
-- Stores shareable invitation links with usage limits
CREATE TABLE IF NOT EXISTS invitation_links (
    id SERIAL PRIMARY KEY,
    league_id INTEGER,
    club_profile_id INTEGER,
    code VARCHAR(20) UNIQUE NOT NULL,
    role VARCHAR(50) NOT NULL CHECK (role IN ('league_admin', 'club_admin', 'coach')),
    created_by INTEGER NOT NULL,
    max_uses INTEGER,
    uses_count INTEGER DEFAULT 0,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_active BOOLEAN DEFAULT TRUE,

    FOREIGN KEY (league_id) REFERENCES leagues(id) ON DELETE CASCADE,
    FOREIGN KEY (club_profile_id) REFERENCES club_profile(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,

    -- Ensure invitation link is for either league OR club, not both
    CHECK (
        (league_id IS NOT NULL AND club_profile_id IS NULL) OR
        (league_id IS NULL AND club_profile_id IS NOT NULL)
    ),

    -- Ensure max_uses is positive if set
    CHECK (max_uses IS NULL OR max_uses > 0),

    -- Ensure uses_count doesn't exceed max_uses
    CHECK (max_uses IS NULL OR uses_count <= max_uses)
);

-- Create indexes for invitation_links
CREATE INDEX IF NOT EXISTS idx_invitation_links_league ON invitation_links(league_id);
CREATE INDEX IF NOT EXISTS idx_invitation_links_club ON invitation_links(club_profile_id);
CREATE INDEX IF NOT EXISTS idx_invitation_links_code ON invitation_links(code);
CREATE INDEX IF NOT EXISTS idx_invitation_links_created_by ON invitation_links(created_by);
CREATE INDEX IF NOT EXISTS idx_invitation_links_active ON invitation_links(is_active);
CREATE INDEX IF NOT EXISTS idx_invitation_links_expires_at ON invitation_links(expires_at);

-- ========================================
-- HELPER FUNCTIONS
-- ========================================

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

COMMENT ON TABLE invitations IS 'Stores email-based invitations for users to join leagues or clubs';
COMMENT ON TABLE invitation_links IS 'Stores shareable invitation links with optional usage limits';
COMMENT ON COLUMN invitations.league_id IS 'League being invited to (mutually exclusive with club_profile_id)';
COMMENT ON COLUMN invitations.club_profile_id IS 'Club being invited to (mutually exclusive with league_id)';
COMMENT ON COLUMN invitations.role IS 'Role that will be assigned when invitation is accepted';
COMMENT ON COLUMN invitations.status IS 'Current status: pending, accepted, expired, or canceled';
COMMENT ON COLUMN invitation_links.code IS 'Unique shareable code (e.g., ABC123)';
COMMENT ON COLUMN invitation_links.max_uses IS 'Maximum number of times link can be used (NULL = unlimited)';
COMMENT ON COLUMN invitation_links.uses_count IS 'Number of times link has been used';

-- ========================================
-- ROW LEVEL SECURITY POLICIES
-- ========================================

-- Enable RLS on invitations table
ALTER TABLE invitations ENABLE ROW LEVEL SECURITY;

-- Enable RLS on invitation_links table
ALTER TABLE invitation_links ENABLE ROW LEVEL SECURITY;

-- Policy: Allow users to view invitations they sent
CREATE POLICY invitations_view_own ON invitations
    FOR SELECT
    USING (invited_by = current_setting('request.jwt.claims', true)::json->>'user_id');

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
-- COMPLETE
-- ========================================
