-- ========================================
-- Populate Legacy League and Club
-- ========================================
-- Purpose: Create Legacy League and Default Club for existing data
-- Run after: 001_league_hierarchy.sql
-- ========================================

DO $$
DECLARE
    legacy_league_id INTEGER;
    default_club_id INTEGER;
    affected_teams INTEGER;
    affected_athletes INTEGER;
BEGIN
    -- ========================================
    -- STEP 1: Create Legacy League
    -- ========================================

    -- Check if Legacy League already exists
    SELECT id INTO legacy_league_id FROM leagues WHERE name = 'Legacy League';

    -- If not, create it
    IF legacy_league_id IS NULL THEN
        INSERT INTO leagues (name, description, active)
        VALUES ('Legacy League', 'Default league for clubs migrated from single-club system', TRUE)
        RETURNING id INTO legacy_league_id;

        RAISE NOTICE 'Created Legacy League with ID: %', legacy_league_id;
    ELSE
        RAISE NOTICE 'Legacy League already exists with ID: %', legacy_league_id;
    END IF;

    -- ========================================
    -- STEP 2: Create Default Club (if no clubs exist)
    -- ========================================

    -- Check if there are any clubs
    IF (SELECT COUNT(*) FROM club_profile) = 0 THEN
        INSERT INTO club_profile (
            name,
            description,
            league_id,
            primary_color,
            secondary_color
        )
        VALUES (
            'Default Club',
            'Default club created during league hierarchy migration',
            legacy_league_id,
            '#1f2937',
            '#f3f4f6'
        )
        RETURNING id INTO default_club_id;

        RAISE NOTICE 'Created Default Club with ID: %', default_club_id;
    ELSE
        -- If clubs exist, just ensure they all have a league_id
        UPDATE club_profile
        SET league_id = legacy_league_id
        WHERE league_id IS NULL;

        RAISE NOTICE 'Updated % existing clubs with Legacy League ID',
            (SELECT COUNT(*) FROM club_profile WHERE league_id = legacy_league_id);
    END IF;

    -- ========================================
    -- STEP 3: Associate existing teams
    -- ========================================

    -- Update teams that don't have club_id or league_id
    -- Try to link through programs first
    UPDATE teams t
    SET
        club_id = COALESCE(p.club_id, default_club_id),
        league_id = legacy_league_id
    FROM programs p
    WHERE t.program_id = p.id
        AND (t.club_id IS NULL OR t.league_id IS NULL)
        AND p.club_id IS NOT NULL;

    GET DIAGNOSTICS affected_teams = ROW_COUNT;
    RAISE NOTICE 'Updated % teams via program associations', affected_teams;

    -- For teams without program_id or where program has no club_id,
    -- assign to default club if it was created
    IF default_club_id IS NOT NULL THEN
        UPDATE teams
        SET
            club_id = default_club_id,
            league_id = legacy_league_id
        WHERE club_id IS NULL OR league_id IS NULL;

        GET DIAGNOSTICS affected_teams = ROW_COUNT;
        RAISE NOTICE 'Updated % orphaned teams with Default Club', affected_teams;
    ELSE
        -- If default_club_id wasn't created, assign to first available club
        UPDATE teams
        SET
            club_id = (SELECT id FROM club_profile WHERE league_id = legacy_league_id LIMIT 1),
            league_id = legacy_league_id
        WHERE club_id IS NULL OR league_id IS NULL;

        GET DIAGNOSTICS affected_teams = ROW_COUNT;
        RAISE NOTICE 'Updated % orphaned teams with first available club', affected_teams;
    END IF;

    -- ========================================
    -- STEP 4: Associate existing athletes
    -- ========================================

    -- Update athletes to have club_id and league_id
    -- Note: athletes table doesn't have club_profile_id, just needs club_id populated
    IF default_club_id IS NOT NULL THEN
        UPDATE athletes
        SET
            club_id = default_club_id,
            league_id = legacy_league_id
        WHERE club_id IS NULL OR league_id IS NULL;

        GET DIAGNOSTICS affected_athletes = ROW_COUNT;
        RAISE NOTICE 'Updated % athletes with Default Club', affected_athletes;
    ELSE
        -- Assign to first available club
        UPDATE athletes
        SET
            club_id = (SELECT id FROM club_profile WHERE league_id = legacy_league_id LIMIT 1),
            league_id = legacy_league_id
        WHERE club_id IS NULL OR league_id IS NULL;

        GET DIAGNOSTICS affected_athletes = ROW_COUNT;
        RAISE NOTICE 'Updated % athletes with first available club', affected_athletes;
    END IF;

    -- ========================================
    -- STEP 5: Migrate user_roles to user_club_access
    -- ========================================

    -- Check if user_roles table exists
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

        RAISE NOTICE 'Migrated % user roles to user_club_access',
            (SELECT COUNT(*) FROM user_club_access);
    END IF;

    -- ========================================
    -- STEP 6: Print summary
    -- ========================================

    RAISE NOTICE '========================================';
    RAISE NOTICE 'DATA MIGRATION COMPLETE';
    RAISE NOTICE '========================================';
    RAISE NOTICE 'Legacy League ID: %', legacy_league_id;
    RAISE NOTICE 'Leagues: %', (SELECT COUNT(*) FROM leagues);
    RAISE NOTICE 'Clubs: %', (SELECT COUNT(*) FROM club_profile);
    RAISE NOTICE 'Teams with associations: %', (SELECT COUNT(*) FROM teams WHERE club_id IS NOT NULL AND league_id IS NOT NULL);
    RAISE NOTICE 'Athletes with associations: %', (SELECT COUNT(*) FROM athletes WHERE club_id IS NOT NULL AND league_id IS NOT NULL);
    RAISE NOTICE 'User club access entries: %', (SELECT COUNT(*) FROM user_club_access);
    RAISE NOTICE '========================================';

END $$;
