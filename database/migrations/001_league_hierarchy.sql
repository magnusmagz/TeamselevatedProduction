-- ========================================
-- League Hierarchy Migration
-- ========================================
-- Purpose: Transform single-club system into multi-tenant league hierarchy
-- Structure: League → Clubs → Teams → Athletes
-- Date: 2025-12-03
-- ========================================

-- ========================================
-- PHASE 1: CREATE NEW TABLES
-- ========================================

-- Leagues table (top-level organization)
CREATE TABLE IF NOT EXISTS leagues (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    website VARCHAR(255),
    contact_email VARCHAR(255),
    contact_phone VARCHAR(20),
    logo_url VARCHAR(500),
    address TEXT,
    city VARCHAR(100),
    state VARCHAR(50),
    zip_code VARCHAR(20),
    active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(name)
);

-- Create index for active leagues
CREATE INDEX IF NOT EXISTS idx_leagues_active ON leagues(active);

-- User league access (defines user roles at league level)
CREATE TABLE IF NOT EXISTS user_league_access (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL,
    league_id INTEGER NOT NULL,
    role VARCHAR(50) NOT NULL CHECK (role IN ('league_admin', 'coach')),
    granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    granted_by INTEGER,
    revoked_at TIMESTAMP,
    revoked_by INTEGER,
    active BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (league_id) REFERENCES leagues(id) ON DELETE CASCADE,
    FOREIGN KEY (granted_by) REFERENCES users(id),
    FOREIGN KEY (revoked_by) REFERENCES users(id),
    UNIQUE(user_id, league_id, role)
);

-- Create indexes for user_league_access
CREATE INDEX IF NOT EXISTS idx_user_league_access_user ON user_league_access(user_id);
CREATE INDEX IF NOT EXISTS idx_user_league_access_league ON user_league_access(league_id);
CREATE INDEX IF NOT EXISTS idx_user_league_access_active ON user_league_access(active);

-- User club access (defines user roles at club level)
CREATE TABLE IF NOT EXISTS user_club_access (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL,
    club_profile_id INTEGER NOT NULL,
    role VARCHAR(50) NOT NULL CHECK (role IN ('club_admin', 'coach', 'parent', 'player')),
    granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    granted_by INTEGER,
    revoked_at TIMESTAMP,
    revoked_by INTEGER,
    active BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (club_profile_id) REFERENCES club_profile(id) ON DELETE CASCADE,
    FOREIGN KEY (granted_by) REFERENCES users(id),
    FOREIGN KEY (revoked_by) REFERENCES users(id),
    UNIQUE(user_id, club_profile_id, role)
);

-- Create indexes for user_club_access
CREATE INDEX IF NOT EXISTS idx_user_club_access_user ON user_club_access(user_id);
CREATE INDEX IF NOT EXISTS idx_user_club_access_club ON user_club_access(club_profile_id);
CREATE INDEX IF NOT EXISTS idx_user_club_access_active ON user_club_access(active);

-- Coach league assignments (allows coaches to float between clubs in a league)
CREATE TABLE IF NOT EXISTS coach_league_assignments (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL,
    league_id INTEGER NOT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    assigned_by INTEGER,
    active BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (league_id) REFERENCES leagues(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by) REFERENCES users(id),
    UNIQUE(user_id, league_id)
);

-- Create indexes for coach_league_assignments
CREATE INDEX IF NOT EXISTS idx_coach_league_assignments_user ON coach_league_assignments(user_id);
CREATE INDEX IF NOT EXISTS idx_coach_league_assignments_league ON coach_league_assignments(league_id);
CREATE INDEX IF NOT EXISTS idx_coach_league_assignments_active ON coach_league_assignments(active);

-- ========================================
-- PHASE 2: ADD COLUMNS TO EXISTING TABLES
-- ========================================

-- Add system_role to users table (super_admin vs regular user)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'users' AND column_name = 'system_role'
    ) THEN
        ALTER TABLE users ADD COLUMN system_role VARCHAR(50) DEFAULT 'user'
            CHECK (system_role IN ('super_admin', 'user'));
    END IF;
END $$;

-- Add league_id to club_profile (clubs belong to leagues)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'club_profile' AND column_name = 'league_id'
    ) THEN
        ALTER TABLE club_profile ADD COLUMN league_id INTEGER;
        -- Don't add foreign key constraint yet - will do after data migration
    END IF;
END $$;

-- Add club_id and league_id to teams
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'teams' AND column_name = 'club_id'
    ) THEN
        ALTER TABLE teams ADD COLUMN club_id INTEGER;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'teams' AND column_name = 'league_id'
    ) THEN
        ALTER TABLE teams ADD COLUMN league_id INTEGER;
    END IF;
END $$;

-- Add club_id and league_id to athletes (if athletes table exists)
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.tables
        WHERE table_name = 'athletes'
    ) THEN
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_name = 'athletes' AND column_name = 'club_id'
        ) THEN
            ALTER TABLE athletes ADD COLUMN club_id INTEGER;
        END IF;

        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_name = 'athletes' AND column_name = 'league_id'
        ) THEN
            ALTER TABLE athletes ADD COLUMN league_id INTEGER;
        END IF;
    END IF;
END $$;

-- Add league_id to programs and make club_id nullable
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.tables
        WHERE table_name = 'programs'
    ) THEN
        -- Make club_id nullable
        ALTER TABLE programs ALTER COLUMN club_id DROP NOT NULL;

        -- Add league_id
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_name = 'programs' AND column_name = 'league_id'
        ) THEN
            ALTER TABLE programs ADD COLUMN league_id INTEGER;
        END IF;
    END IF;
END $$;

-- ========================================
-- PHASE 3: DATA MIGRATION
-- ========================================

-- Create "Legacy League" for existing clubs
DO $$
DECLARE
    legacy_league_id INTEGER;
BEGIN
    -- Check if Legacy League already exists
    SELECT id INTO legacy_league_id FROM leagues WHERE name = 'Legacy League';

    -- If not, create it
    IF legacy_league_id IS NULL THEN
        INSERT INTO leagues (name, description, active)
        VALUES ('Legacy League', 'Default league for existing clubs migrated from single-club system', TRUE)
        RETURNING id INTO legacy_league_id;

        RAISE NOTICE 'Created Legacy League with ID: %', legacy_league_id;
    ELSE
        RAISE NOTICE 'Legacy League already exists with ID: %', legacy_league_id;
    END IF;

    -- Assign all existing clubs without league_id to the legacy league
    UPDATE club_profile
    SET league_id = legacy_league_id
    WHERE league_id IS NULL;

    RAISE NOTICE 'Assigned % clubs to Legacy League', (SELECT COUNT(*) FROM club_profile WHERE league_id = legacy_league_id);

    -- Update teams with club_id and league_id based on program associations
    -- If teams have program_id, link them to the club through programs
    UPDATE teams t
    SET
        club_id = p.club_id,
        league_id = cp.league_id
    FROM programs p
    INNER JOIN club_profile cp ON p.club_id = cp.id
    WHERE t.program_id = p.id
        AND t.club_id IS NULL
        AND p.club_id IS NOT NULL;

    RAISE NOTICE 'Updated % teams with club and league associations', (SELECT COUNT(*) FROM teams WHERE club_id IS NOT NULL);

    -- For teams without program_id or where program has no club_id,
    -- assign to the first available club in legacy league (or create a default club if none exists)
    UPDATE teams t
    SET
        club_id = (SELECT id FROM club_profile WHERE league_id = legacy_league_id LIMIT 1),
        league_id = legacy_league_id
    WHERE t.club_id IS NULL;

    -- Update athletes if the table exists
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'athletes') THEN
        -- Update athletes based on their club_profile_id
        UPDATE athletes a
        SET
            club_id = a.club_profile_id,
            league_id = cp.league_id
        FROM club_profile cp
        WHERE a.club_profile_id = cp.id
            AND a.club_id IS NULL;

        RAISE NOTICE 'Updated % athletes with club and league associations', (SELECT COUNT(*) FROM athletes WHERE club_id IS NOT NULL);
    END IF;

    -- Migrate user_roles to user_club_access
    -- This assumes user_roles table exists with club_profile_id
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'user_roles') THEN
        INSERT INTO user_club_access (user_id, club_profile_id, role, granted_at, active)
        SELECT DISTINCT
            ur.user_id,
            ur.club_profile_id,
            ur.role,
            ur.created_at,
            TRUE
        FROM user_roles ur
        WHERE ur.club_profile_id IS NOT NULL
        ON CONFLICT (user_id, club_profile_id, role) DO NOTHING;

        RAISE NOTICE 'Migrated % user roles to user_club_access', (SELECT COUNT(*) FROM user_club_access);
    END IF;
END $$;

-- ========================================
-- PHASE 4: ADD CONSTRAINTS
-- ========================================

-- Add foreign key constraints after data migration
DO $$
BEGIN
    -- Add foreign key from club_profile to leagues
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE constraint_name = 'club_profile_league_id_fkey'
    ) THEN
        ALTER TABLE club_profile
        ADD CONSTRAINT club_profile_league_id_fkey
        FOREIGN KEY (league_id) REFERENCES leagues(id);
    END IF;

    -- Add foreign key from teams to club_profile
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE constraint_name = 'teams_club_id_fkey'
    ) THEN
        ALTER TABLE teams
        ADD CONSTRAINT teams_club_id_fkey
        FOREIGN KEY (club_id) REFERENCES club_profile(id);
    END IF;

    -- Add foreign key from teams to leagues
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE constraint_name = 'teams_league_id_fkey'
    ) THEN
        ALTER TABLE teams
        ADD CONSTRAINT teams_league_id_fkey
        FOREIGN KEY (league_id) REFERENCES leagues(id);
    END IF;

    -- Add foreign key constraints for athletes if table exists
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'athletes') THEN
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.table_constraints
            WHERE constraint_name = 'athletes_club_id_fkey'
        ) THEN
            ALTER TABLE athletes
            ADD CONSTRAINT athletes_club_id_fkey
            FOREIGN KEY (club_id) REFERENCES club_profile(id);
        END IF;

        IF NOT EXISTS (
            SELECT 1 FROM information_schema.table_constraints
            WHERE constraint_name = 'athletes_league_id_fkey'
        ) THEN
            ALTER TABLE athletes
            ADD CONSTRAINT athletes_league_id_fkey
            FOREIGN KEY (league_id) REFERENCES leagues(id);
        END IF;
    END IF;

    -- Add foreign key from programs to leagues if table exists
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'programs') THEN
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.table_constraints
            WHERE constraint_name = 'programs_league_id_fkey'
        ) THEN
            ALTER TABLE programs
            ADD CONSTRAINT programs_league_id_fkey
            FOREIGN KEY (league_id) REFERENCES leagues(id);
        END IF;
    END IF;
END $$;

-- ========================================
-- PHASE 5: ADD NOT NULL CONSTRAINTS (after validation)
-- ========================================
-- These will be uncommented after validating data migration is complete
-- and all records have proper league/club associations

-- ALTER TABLE club_profile ALTER COLUMN league_id SET NOT NULL;
-- ALTER TABLE teams ALTER COLUMN club_id SET NOT NULL;
-- ALTER TABLE teams ALTER COLUMN league_id SET NOT NULL;

-- ========================================
-- PHASE 6: CREATE INDEXES FOR PERFORMANCE
-- ========================================

CREATE INDEX IF NOT EXISTS idx_club_profile_league ON club_profile(league_id);
CREATE INDEX IF NOT EXISTS idx_teams_club ON teams(club_id);
CREATE INDEX IF NOT EXISTS idx_teams_league ON teams(league_id);
CREATE INDEX IF NOT EXISTS idx_teams_club_league ON teams(club_id, league_id);

-- Add indexes for athletes if table exists
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'athletes') THEN
        CREATE INDEX IF NOT EXISTS idx_athletes_club ON athletes(club_id);
        CREATE INDEX IF NOT EXISTS idx_athletes_league ON athletes(league_id);
        CREATE INDEX IF NOT EXISTS idx_athletes_club_league ON athletes(club_id, league_id);
    END IF;
END $$;

-- Add indexes for programs if table exists
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'programs') THEN
        CREATE INDEX IF NOT EXISTS idx_programs_league ON programs(league_id);
    END IF;
END $$;

-- ========================================
-- MIGRATION COMPLETE
-- ========================================

-- Print summary
DO $$
DECLARE
    league_count INTEGER;
    club_count INTEGER;
    team_count INTEGER;
    athlete_count INTEGER;
    user_league_count INTEGER;
    user_club_count INTEGER;
BEGIN
    SELECT COUNT(*) INTO league_count FROM leagues;
    SELECT COUNT(*) INTO club_count FROM club_profile;
    SELECT COUNT(*) INTO team_count FROM teams WHERE league_id IS NOT NULL;
    SELECT COUNT(*) INTO user_league_count FROM user_league_access;
    SELECT COUNT(*) INTO user_club_count FROM user_club_access;

    -- Check if athletes table exists
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'athletes') THEN
        SELECT COUNT(*) INTO athlete_count FROM athletes WHERE league_id IS NOT NULL;
    ELSE
        athlete_count := 0;
    END IF;

    RAISE NOTICE '========================================';
    RAISE NOTICE 'LEAGUE HIERARCHY MIGRATION COMPLETE';
    RAISE NOTICE '========================================';
    RAISE NOTICE 'Leagues created: %', league_count;
    RAISE NOTICE 'Clubs migrated: %', club_count;
    RAISE NOTICE 'Teams linked: %', team_count;
    RAISE NOTICE 'Athletes linked: %', athlete_count;
    RAISE NOTICE 'User league access entries: %', user_league_count;
    RAISE NOTICE 'User club access entries: %', user_club_count;
    RAISE NOTICE '========================================';
END $$;

-- ========================================
-- ROLLBACK INSTRUCTIONS
-- ========================================
-- If you need to rollback this migration, run the following:
--
-- DROP TABLE IF EXISTS coach_league_assignments CASCADE;
-- DROP TABLE IF EXISTS user_club_access CASCADE;
-- DROP TABLE IF EXISTS user_league_access CASCADE;
-- ALTER TABLE club_profile DROP COLUMN IF EXISTS league_id CASCADE;
-- ALTER TABLE teams DROP COLUMN IF EXISTS club_id CASCADE;
-- ALTER TABLE teams DROP COLUMN IF EXISTS league_id CASCADE;
-- ALTER TABLE athletes DROP COLUMN IF EXISTS club_id CASCADE;
-- ALTER TABLE athletes DROP COLUMN IF EXISTS league_id CASCADE;
-- ALTER TABLE programs DROP COLUMN IF EXISTS league_id CASCADE;
-- ALTER TABLE users DROP COLUMN IF EXISTS system_role;
-- DROP TABLE IF EXISTS leagues CASCADE;
