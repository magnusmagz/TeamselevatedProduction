-- Migration: Merge League and Club Entities
-- Purpose: Eliminate the League layer, making Club the top-level entity
-- Hierarchy changes from: League -> Club -> Team -> Athlete
--                     to: Club -> Team -> Athlete

-- =====================================================
-- PHASE 1: BACKUP TABLES (for rollback capability)
-- =====================================================

-- Backup leagues table
CREATE TABLE IF NOT EXISTS _backup_leagues AS SELECT * FROM leagues;

-- Backup user_league_access table
CREATE TABLE IF NOT EXISTS _backup_user_league_access AS SELECT * FROM user_league_access;

-- Backup coach_league_assignments table (if exists)
DO $$
BEGIN
    IF EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'coach_league_assignments') THEN
        EXECUTE 'CREATE TABLE IF NOT EXISTS _backup_coach_league_assignments AS SELECT * FROM coach_league_assignments';
    END IF;
END $$;

-- Backup league_documents table (if exists)
DO $$
BEGIN
    IF EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'league_documents') THEN
        EXECUTE 'CREATE TABLE IF NOT EXISTS _backup_league_documents AS SELECT * FROM league_documents';
    END IF;
END $$;

-- =====================================================
-- PHASE 2: ADD MISSING COLUMNS TO CLUB_PROFILE
-- =====================================================

-- Add columns that exist on leagues but not on club_profile
ALTER TABLE club_profile ADD COLUMN IF NOT EXISTS website VARCHAR(255);
ALTER TABLE club_profile ADD COLUMN IF NOT EXISTS description TEXT;
ALTER TABLE club_profile ADD COLUMN IF NOT EXISTS contact_phone VARCHAR(20);

-- =====================================================
-- PHASE 3: MIGRATE LEAGUE DATA TO CLUBS
-- =====================================================

-- Copy league attributes to clubs (only if club doesn't have the value)
UPDATE club_profile cp
SET
    website = COALESCE(cp.website, l.website),
    description = COALESCE(cp.description, l.description),
    logo_url = COALESCE(cp.logo_url, l.logo_url),
    email = COALESCE(cp.email, l.contact_email),
    contact_phone = COALESCE(cp.contact_phone, l.contact_phone),
    address = COALESCE(cp.address, l.address),
    city = COALESCE(cp.city, l.city),
    state = COALESCE(cp.state, l.state),
    zip_code = COALESCE(cp.zip_code, l.zip_code)
FROM leagues l
WHERE cp.league_id = l.id;

-- =====================================================
-- PHASE 4: CREATE CLUB_DOCUMENTS TABLE
-- =====================================================

CREATE TABLE IF NOT EXISTS club_documents (
    id SERIAL PRIMARY KEY,
    club_profile_id INTEGER NOT NULL,
    name VARCHAR(255) NOT NULL,
    url VARCHAR(500) NOT NULL,
    created_by INTEGER,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (club_profile_id) REFERENCES club_profile(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Create indexes for club_documents
CREATE INDEX IF NOT EXISTS idx_club_documents_club ON club_documents(club_profile_id);
CREATE INDEX IF NOT EXISTS idx_club_documents_created_by ON club_documents(created_by);

-- =====================================================
-- PHASE 5: MIGRATE LEAGUE_DOCUMENTS TO CLUB_DOCUMENTS
-- =====================================================

-- Only run if league_documents exists
DO $$
BEGIN
    IF EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'league_documents') THEN
        INSERT INTO club_documents (club_profile_id, name, url, created_by, created_at, updated_at)
        SELECT cp.id, ld.name, ld.url, ld.created_by, ld.created_at, ld.updated_at
        FROM league_documents ld
        JOIN club_profile cp ON cp.league_id = ld.league_id;
    END IF;
END $$;

-- =====================================================
-- PHASE 6: MIGRATE USER_LEAGUE_ACCESS TO USER_CLUB_ACCESS
-- =====================================================

-- Convert league_admin to club_admin, keep coach as coach
INSERT INTO user_club_access (user_id, club_profile_id, role, granted_at, granted_by, active)
SELECT
    ula.user_id,
    cp.id,
    CASE WHEN ula.role = 'league_admin' THEN 'club_admin' ELSE ula.role END,
    ula.granted_at,
    ula.granted_by,
    ula.active
FROM user_league_access ula
JOIN club_profile cp ON cp.league_id = ula.league_id
WHERE ula.active = TRUE
ON CONFLICT (user_id, club_profile_id, role) DO NOTHING;

-- =====================================================
-- PHASE 7: UPDATE INVITATIONS TABLE (if exists)
-- =====================================================

-- Migrate league invitations to club invitations
DO $$
BEGIN
    IF EXISTS (SELECT FROM information_schema.columns WHERE table_name = 'invitations' AND column_name = 'league_id') THEN
        -- Update invitations that have league_id to use the club_profile_id instead
        UPDATE invitations i
        SET club_profile_id = cp.id
        FROM club_profile cp
        WHERE i.league_id = cp.league_id
        AND i.club_profile_id IS NULL;

        -- Change role from league_admin to club_admin in invitations
        UPDATE invitations
        SET role = 'club_admin'
        WHERE role = 'league_admin';
    END IF;
END $$;

-- =====================================================
-- PHASE 8: DROP FOREIGN KEY CONSTRAINTS
-- =====================================================

-- Drop constraints referencing leagues table
ALTER TABLE club_profile DROP CONSTRAINT IF EXISTS club_profile_league_id_fkey;
ALTER TABLE teams DROP CONSTRAINT IF EXISTS teams_league_id_fkey;
ALTER TABLE athletes DROP CONSTRAINT IF EXISTS athletes_league_id_fkey;
ALTER TABLE programs DROP CONSTRAINT IF EXISTS programs_league_id_fkey;
ALTER TABLE invitations DROP CONSTRAINT IF EXISTS invitations_league_id_fkey;

-- =====================================================
-- PHASE 9: DROP LEAGUE_ID COLUMNS
-- =====================================================

ALTER TABLE club_profile DROP COLUMN IF EXISTS league_id;
ALTER TABLE teams DROP COLUMN IF EXISTS league_id;
ALTER TABLE athletes DROP COLUMN IF EXISTS league_id;
ALTER TABLE programs DROP COLUMN IF EXISTS league_id;
ALTER TABLE invitations DROP COLUMN IF EXISTS league_id;

-- =====================================================
-- PHASE 10: DROP INDEXES REFERENCING LEAGUE
-- =====================================================

DROP INDEX IF EXISTS idx_club_profile_league;
DROP INDEX IF EXISTS idx_teams_league;
DROP INDEX IF EXISTS idx_teams_club_league;
DROP INDEX IF EXISTS idx_athletes_league;
DROP INDEX IF EXISTS idx_athletes_club_league;
DROP INDEX IF EXISTS idx_programs_league;
DROP INDEX IF EXISTS idx_user_league_access_user;
DROP INDEX IF EXISTS idx_user_league_access_league;
DROP INDEX IF EXISTS idx_user_league_access_active;
DROP INDEX IF EXISTS idx_coach_league_assignments_user;
DROP INDEX IF EXISTS idx_coach_league_assignments_league;
DROP INDEX IF EXISTS idx_coach_league_assignments_active;

-- =====================================================
-- PHASE 11: DROP LEAGUE-RELATED TABLES
-- =====================================================

DROP TABLE IF EXISTS coach_league_assignments CASCADE;
DROP TABLE IF EXISTS league_documents CASCADE;
DROP TABLE IF EXISTS user_league_access CASCADE;
DROP TABLE IF EXISTS leagues CASCADE;

-- =====================================================
-- PHASE 12: UPDATE CHECK CONSTRAINTS
-- =====================================================

-- Update user_club_access role constraint if needed
-- The role constraint should already include 'club_admin', 'coach', 'parent', 'player'
-- No changes needed as we're converting league_admin to club_admin

-- =====================================================
-- VERIFICATION QUERIES (run these to verify migration)
-- =====================================================

-- Verify no league tables remain
-- SELECT table_name FROM information_schema.tables WHERE table_name LIKE '%league%';

-- Verify user_club_access has migrated users
-- SELECT COUNT(*) FROM user_club_access WHERE role = 'club_admin';

-- Verify club_documents has migrated documents
-- SELECT COUNT(*) FROM club_documents;

-- =====================================================
-- ROLLBACK SCRIPT (if needed, run separately)
-- =====================================================
/*
-- To rollback, run these commands:

-- Restore leagues table
CREATE TABLE leagues AS SELECT * FROM _backup_leagues;

-- Restore user_league_access
CREATE TABLE user_league_access AS SELECT * FROM _backup_user_league_access;

-- Restore coach_league_assignments
CREATE TABLE coach_league_assignments AS SELECT * FROM _backup_coach_league_assignments;

-- Restore league_documents
CREATE TABLE league_documents AS SELECT * FROM _backup_league_documents;

-- Re-add league_id columns
ALTER TABLE club_profile ADD COLUMN league_id INTEGER REFERENCES leagues(id);
ALTER TABLE teams ADD COLUMN league_id INTEGER REFERENCES leagues(id);
ALTER TABLE athletes ADD COLUMN league_id INTEGER REFERENCES leagues(id);
ALTER TABLE programs ADD COLUMN league_id INTEGER REFERENCES leagues(id);

-- Restore league_id values from backup... (would need additional logic)
*/
