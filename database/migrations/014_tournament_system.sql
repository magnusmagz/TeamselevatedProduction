-- Migration: 014_tournament_system.sql
-- Description: Create tables for tournament management system
-- Created: 2026-03-21
-- Phase: 1 (MVP) — core tournament, divisions, groups, registrations, matches, standings
-- Phase 2 tables (referees, match events) included but can be deferred

-- ============================================
-- 1. TOURNAMENTS TABLE
-- ============================================
-- Top-level tournament entity, scoped to a club
CREATE TABLE IF NOT EXISTS tournaments (
    id SERIAL PRIMARY KEY,
    club_id INTEGER NOT NULL REFERENCES club_profile(id) ON DELETE CASCADE,
    name VARCHAR(200) NOT NULL,
    description TEXT,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    location_name VARCHAR(200),
    location_address VARCHAR(500),
    location_city VARCHAR(100),
    location_state VARCHAR(50),
    location_zip VARCHAR(20),
    location_coordinates JSONB,              -- {lat, lng} for map integration
    registration_open_date TIMESTAMP,
    registration_close_date TIMESTAMP,
    status VARCHAR(30) NOT NULL DEFAULT 'draft'
        CHECK (status IN (
            'draft',                          -- Being set up
            'registration_open',              -- Accepting team registrations
            'registration_closed',            -- Registration window ended
            'scheduling',                     -- Building schedule/brackets
            'in_progress',                    -- Games underway
            'weather_delay',                  -- Paused for weather
            'completed',                      -- All matches finished
            'cancelled'                       -- Cancelled
        )),
    entry_fee_cents INTEGER DEFAULT 0,        -- Store in cents to avoid float issues
    max_teams_per_division INTEGER,
    rules_document_url TEXT,
    contact_name VARCHAR(200),
    contact_email VARCHAR(200),
    contact_phone VARCHAR(30),
    sport VARCHAR(30) NOT NULL DEFAULT 'soccer', -- 'soccer', 'basketball', 'baseball', etc.
    public_url_slug VARCHAR(100),             -- For shareable public page: /tournaments/{slug}
    season_id INTEGER REFERENCES seasons(id) ON DELETE SET NULL,
    created_by INTEGER NOT NULL REFERENCES users(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_tournaments_club ON tournaments(club_id);
CREATE INDEX IF NOT EXISTS idx_tournaments_status ON tournaments(status);
CREATE INDEX IF NOT EXISTS idx_tournaments_dates ON tournaments(start_date, end_date);
CREATE UNIQUE INDEX IF NOT EXISTS idx_tournaments_slug ON tournaments(club_id, public_url_slug);

-- ============================================
-- 2. TOURNAMENT DIVISIONS TABLE
-- ============================================
-- Divisions within a tournament (e.g. U12 Boys, U14 Girls)
-- Each division has its own format, rules, and schedule
CREATE TABLE IF NOT EXISTS tournament_divisions (
    id SERIAL PRIMARY KEY,
    tournament_id INTEGER NOT NULL REFERENCES tournaments(id) ON DELETE CASCADE,
    name VARCHAR(100) NOT NULL,               -- "U12 Boys Gold", "U14 Girls Silver"
    age_group VARCHAR(10) NOT NULL,           -- "U8", "U10", "U12", "U14", "U16", "U19"
    gender VARCHAR(10) NOT NULL DEFAULT 'coed'
        CHECK (gender IN ('boys', 'girls', 'coed')),
    format VARCHAR(30) NOT NULL DEFAULT 'group_knockout'
        CHECK (format IN (
            'round_robin',                    -- Pure round robin, no knockout
            'group_knockout',                 -- Group stage + single elim bracket
            'single_elimination',             -- Straight knockout
            'double_elimination'              -- Two-loss elimination
        )),
    game_duration_minutes INTEGER NOT NULL DEFAULT 50,
    half_duration_minutes INTEGER NOT NULL DEFAULT 25,
    max_roster_size INTEGER DEFAULT 18,
    min_roster_size INTEGER DEFAULT 7,
    max_teams INTEGER,
    teams_per_group INTEGER DEFAULT 4,        -- For group_knockout format
    teams_advancing_per_group INTEGER DEFAULT 2, -- How many advance from each group
    goal_differential_cap INTEGER,            -- e.g. 4 = 7-0 win counts as 4-0 for tiebreakers
    tiebreaker_rules JSONB NOT NULL DEFAULT '[
        "points",
        "head_to_head",
        "goal_difference",
        "goals_for",
        "goals_against",
        "wins",
        "coin_flip"
    ]'::jsonb,
    points_for_win INTEGER NOT NULL DEFAULT 3,
    points_for_draw INTEGER NOT NULL DEFAULT 1,
    points_for_loss INTEGER NOT NULL DEFAULT 0,
    max_players_on_field INTEGER,             -- e.g. 4 (4v4), 7 (7v7), 9 (9v9), 11 (11v11)
    sport_rule_notes JSONB,                  -- ["No heading", "Build-out line", "Size 4 ball"]
    overtime_rules JSONB,                     -- {has_extra_time, extra_time_minutes, has_penalties, knockout_only}
    sort_order INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_tournament_divisions_tournament ON tournament_divisions(tournament_id);
CREATE INDEX IF NOT EXISTS idx_tournament_divisions_age ON tournament_divisions(age_group, gender);

-- ============================================
-- 3. TOURNAMENT GROUPS TABLE
-- ============================================
-- Pools/groups within a division for round-robin play
CREATE TABLE IF NOT EXISTS tournament_groups (
    id SERIAL PRIMARY KEY,
    division_id INTEGER NOT NULL REFERENCES tournament_divisions(id) ON DELETE CASCADE,
    name VARCHAR(50) NOT NULL,                -- "Group A", "Pool 1"
    sort_order INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_tournament_groups_division ON tournament_groups(division_id);

-- ============================================
-- 4. TOURNAMENT REGISTRATIONS TABLE
-- ============================================
-- Links existing CRM teams to tournament divisions
-- Reuses teams table — no duplicate team data
CREATE TABLE IF NOT EXISTS tournament_registrations (
    id SERIAL PRIMARY KEY,
    tournament_id INTEGER NOT NULL REFERENCES tournaments(id) ON DELETE CASCADE,
    division_id INTEGER NOT NULL REFERENCES tournament_divisions(id) ON DELETE CASCADE,
    team_id INTEGER NOT NULL REFERENCES teams(id),
    team_name_override VARCHAR(200),          -- For guest teams not in CRM (display name)
    club_name_override VARCHAR(200),          -- Guest team's club name
    registered_by INTEGER NOT NULL REFERENCES users(id),
    status VARCHAR(30) NOT NULL DEFAULT 'pending'
        CHECK (status IN (
            'pending',                        -- Awaiting review
            'accepted',                       -- Approved to participate
            'rejected',                       -- Declined
            'waitlisted',                     -- On waitlist
            'withdrawn'                       -- Team withdrew
        )),
    payment_status VARCHAR(30) NOT NULL DEFAULT 'unpaid'
        CHECK (payment_status IN ('unpaid', 'paid', 'refunded', 'waived')),
    payment_amount_cents INTEGER,
    payment_reference VARCHAR(100),           -- External payment ref / check number
    payment_item_id INTEGER,                  -- FK to payment_items if using integrated payments
    seed INTEGER,                             -- Seeding position (nullable = unseeded)
    group_id INTEGER REFERENCES tournament_groups(id) ON DELETE SET NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(tournament_id, team_id)            -- A team can only register once per tournament
);

CREATE INDEX IF NOT EXISTS idx_tournament_reg_tournament ON tournament_registrations(tournament_id);
CREATE INDEX IF NOT EXISTS idx_tournament_reg_division ON tournament_registrations(division_id);
CREATE INDEX IF NOT EXISTS idx_tournament_reg_team ON tournament_registrations(team_id);
CREATE INDEX IF NOT EXISTS idx_tournament_reg_group ON tournament_registrations(group_id);
CREATE INDEX IF NOT EXISTS idx_tournament_reg_status ON tournament_registrations(status);

-- ============================================
-- 5. TOURNAMENT MATCHES TABLE
-- ============================================
-- Individual matches — supports both group stage and knockout rounds
-- References existing fields table for venue/field assignment
CREATE TABLE IF NOT EXISTS tournament_matches (
    id SERIAL PRIMARY KEY,
    division_id INTEGER NOT NULL REFERENCES tournament_divisions(id) ON DELETE CASCADE,
    group_id INTEGER REFERENCES tournament_groups(id) ON DELETE SET NULL,  -- NULL for knockout matches
    round VARCHAR(50) NOT NULL,               -- "Group Stage", "Round of 16", "Quarterfinal", "Semifinal", "3rd Place", "Final"
    match_number INTEGER NOT NULL,            -- Sequential match number for the tournament
    home_registration_id INTEGER REFERENCES tournament_registrations(id) ON DELETE SET NULL,
    away_registration_id INTEGER REFERENCES tournament_registrations(id) ON DELETE SET NULL,
    home_placeholder VARCHAR(100),            -- "Winner Match 5", "1st Group A" (before teams known)
    away_placeholder VARCHAR(100),
    -- Bracket progression: which match result feeds into this match
    home_source_match_id INTEGER REFERENCES tournament_matches(id) ON DELETE SET NULL,
    away_source_match_id INTEGER REFERENCES tournament_matches(id) ON DELETE SET NULL,
    home_source_type VARCHAR(20)              -- 'winner', 'loser', 'group_position'
        CHECK (home_source_type IN ('winner', 'loser', 'group_position')),
    away_source_type VARCHAR(20)
        CHECK (away_source_type IN ('winner', 'loser', 'group_position')),
    -- Scheduling — reuses existing fields table
    field_id INTEGER REFERENCES fields(id) ON DELETE SET NULL,
    scheduled_time TIMESTAMP,
    scheduled_end_time TIMESTAMP,
    actual_start_time TIMESTAMP,
    -- Status and scoring
    status VARCHAR(30) NOT NULL DEFAULT 'scheduled'
        CHECK (status IN (
            'scheduled',
            'in_progress',
            'completed',
            'cancelled',
            'postponed',
            'forfeit_home',                   -- Away team wins by forfeit
            'forfeit_away'                    -- Home team wins by forfeit
        )),
    home_score INTEGER,
    away_score INTEGER,
    home_penalty_score INTEGER,               -- For knockout PKs
    away_penalty_score INTEGER,
    winner_registration_id INTEGER REFERENCES tournament_registrations(id) ON DELETE SET NULL,
    -- Event integration: optionally create an events record for calendar
    event_id INTEGER REFERENCES events(id) ON DELETE SET NULL,
    notes TEXT,
    scored_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    scored_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_tournament_matches_division ON tournament_matches(division_id);
CREATE INDEX IF NOT EXISTS idx_tournament_matches_group ON tournament_matches(group_id);
CREATE INDEX IF NOT EXISTS idx_tournament_matches_home ON tournament_matches(home_registration_id);
CREATE INDEX IF NOT EXISTS idx_tournament_matches_away ON tournament_matches(away_registration_id);
CREATE INDEX IF NOT EXISTS idx_tournament_matches_field_time ON tournament_matches(field_id, scheduled_time);
CREATE INDEX IF NOT EXISTS idx_tournament_matches_status ON tournament_matches(status);
CREATE INDEX IF NOT EXISTS idx_tournament_matches_event ON tournament_matches(event_id);
CREATE INDEX IF NOT EXISTS idx_tournament_matches_number ON tournament_matches(division_id, match_number);

-- ============================================
-- 6. TOURNAMENT STANDINGS TABLE
-- ============================================
-- Auto-calculated group standings, updated after each score entry
CREATE TABLE IF NOT EXISTS tournament_standings (
    id SERIAL PRIMARY KEY,
    group_id INTEGER NOT NULL REFERENCES tournament_groups(id) ON DELETE CASCADE,
    registration_id INTEGER NOT NULL REFERENCES tournament_registrations(id) ON DELETE CASCADE,
    played INTEGER NOT NULL DEFAULT 0,
    won INTEGER NOT NULL DEFAULT 0,
    drawn INTEGER NOT NULL DEFAULT 0,
    lost INTEGER NOT NULL DEFAULT 0,
    goals_for INTEGER NOT NULL DEFAULT 0,
    goals_against INTEGER NOT NULL DEFAULT 0,
    goal_difference INTEGER NOT NULL DEFAULT 0,   -- Computed: goals_for - goals_against
    points INTEGER NOT NULL DEFAULT 0,            -- Computed: (won * pts_for_win) + (drawn * pts_for_draw)
    position INTEGER,                             -- Rank in group, updated after recalculation
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(group_id, registration_id)
);

CREATE INDEX IF NOT EXISTS idx_tournament_standings_group ON tournament_standings(group_id, position);

-- ============================================
-- 7. TOURNAMENT MATCH EVENTS TABLE (Phase 2)
-- ============================================
-- Detailed per-match events: goals, cards, substitutions
CREATE TABLE IF NOT EXISTS tournament_match_events (
    id SERIAL PRIMARY KEY,
    match_id INTEGER NOT NULL REFERENCES tournament_matches(id) ON DELETE CASCADE,
    registration_id INTEGER NOT NULL REFERENCES tournament_registrations(id),  -- Which team
    event_type VARCHAR(30) NOT NULL
        CHECK (event_type IN (
            'goal',
            'own_goal',
            'penalty_goal',
            'missed_penalty',
            'yellow_card',
            'red_card',
            'second_yellow',
            'substitution_in',
            'substitution_out',
            'injury'
        )),
    minute INTEGER,                           -- Game minute (0-90+)
    athlete_id INTEGER REFERENCES athletes(id) ON DELETE SET NULL,  -- Links to existing athletes table
    details JSONB,                            -- Flexible: {assist_by, penalty_scored, replacement_athlete_id, ...}
    created_by INTEGER REFERENCES users(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_tournament_match_events_match ON tournament_match_events(match_id);
CREATE INDEX IF NOT EXISTS idx_tournament_match_events_athlete ON tournament_match_events(athlete_id);
CREATE INDEX IF NOT EXISTS idx_tournament_match_events_type ON tournament_match_events(event_type);

-- ============================================
-- 8. TOURNAMENT REFEREES TABLE (Phase 2)
-- ============================================
-- Referee pool for a tournament
CREATE TABLE IF NOT EXISTS tournament_referees (
    id SERIAL PRIMARY KEY,
    tournament_id INTEGER NOT NULL REFERENCES tournaments(id) ON DELETE CASCADE,
    name VARCHAR(200) NOT NULL,
    email VARCHAR(200),
    phone VARCHAR(30),
    certification_level VARCHAR(50),          -- "Grade 8", "Regional", "National", etc.
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_tournament_referees_tournament ON tournament_referees(tournament_id);

-- ============================================
-- 9. TOURNAMENT MATCH REFEREES TABLE (Phase 2)
-- ============================================
-- Referee assignments to specific matches
CREATE TABLE IF NOT EXISTS tournament_match_referees (
    id SERIAL PRIMARY KEY,
    match_id INTEGER NOT NULL REFERENCES tournament_matches(id) ON DELETE CASCADE,
    referee_id INTEGER NOT NULL REFERENCES tournament_referees(id) ON DELETE CASCADE,
    role VARCHAR(30) NOT NULL DEFAULT 'center'
        CHECK (role IN ('center', 'assistant_1', 'assistant_2', 'fourth_official')),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(match_id, referee_id)
);

CREATE INDEX IF NOT EXISTS idx_tournament_match_refs_match ON tournament_match_referees(match_id);
CREATE INDEX IF NOT EXISTS idx_tournament_match_refs_ref ON tournament_match_referees(referee_id);

-- ============================================
-- 10. EXTEND EXISTING FIELDS TABLE
-- ============================================
-- Add tournament-useful columns to the existing fields table
ALTER TABLE fields ADD COLUMN IF NOT EXISTS surface_type VARCHAR(30);       -- grass, turf, indoor
ALTER TABLE fields ADD COLUMN IF NOT EXISTS supports_lighting BOOLEAN DEFAULT false;
ALTER TABLE fields ADD COLUMN IF NOT EXISTS location_notes TEXT;            -- Parking, directions

-- ============================================
-- 11. SPORT PRESETS TABLE
-- ============================================
-- Age-group rule presets per sport (soccer first, extensible to other sports)
CREATE TABLE IF NOT EXISTS sport_presets (
    id SERIAL PRIMARY KEY,
    sport VARCHAR(30) NOT NULL,
    age_group VARCHAR(10) NOT NULL,
    game_duration_minutes INTEGER NOT NULL,
    half_duration_minutes INTEGER NOT NULL,
    max_roster_size INTEGER NOT NULL,
    min_roster_size INTEGER NOT NULL,
    max_players_on_field INTEGER NOT NULL,
    rule_notes JSONB,
    UNIQUE(sport, age_group)
);

-- Seed soccer presets (US Youth Soccer standards)
INSERT INTO sport_presets (sport, age_group, game_duration_minutes, half_duration_minutes, max_roster_size, min_roster_size, max_players_on_field, rule_notes) VALUES
    ('soccer', 'U8',  40, 20, 12, 7,  4,  '["No heading", "No offside", "Build-out line", "Size 3 ball"]'::jsonb),
    ('soccer', 'U10', 50, 25, 14, 7,  7,  '["No heading", "Build-out line", "Size 4 ball"]'::jsonb),
    ('soccer', 'U12', 60, 30, 18, 11, 9,  '["No heading (U11 and under)", "Size 4 ball"]'::jsonb),
    ('soccer', 'U14', 70, 35, 22, 11, 11, '["Size 5 ball"]'::jsonb),
    ('soccer', 'U16', 70, 35, 22, 11, 11, '["Size 5 ball"]'::jsonb),
    ('soccer', 'U19', 90, 45, 22, 11, 11, '["Size 5 ball"]'::jsonb)
ON CONFLICT (sport, age_group) DO NOTHING;

-- ============================================
-- 12. TOURNAMENT MATCH CHECKINS TABLE
-- ============================================
-- Per-match player check-in for roster verification
CREATE TABLE IF NOT EXISTS tournament_match_checkins (
    id SERIAL PRIMARY KEY,
    match_id INTEGER NOT NULL REFERENCES tournament_matches(id) ON DELETE CASCADE,
    registration_id INTEGER NOT NULL REFERENCES tournament_registrations(id) ON DELETE CASCADE,
    athlete_id INTEGER NOT NULL REFERENCES athletes(id) ON DELETE CASCADE,
    checked_in BOOLEAN NOT NULL DEFAULT false,
    checked_in_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    checked_in_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(match_id, registration_id, athlete_id)
);

CREATE INDEX IF NOT EXISTS idx_match_checkins_match ON tournament_match_checkins(match_id);
CREATE INDEX IF NOT EXISTS idx_match_checkins_registration ON tournament_match_checkins(registration_id);

-- ============================================
-- 13. EXTEND ATHLETES TABLE
-- ============================================
-- Add photo URL for player cards
ALTER TABLE athletes ADD COLUMN IF NOT EXISTS photo_url VARCHAR(500);

-- ============================================
-- 14. ADD TOURNAMENT COLUMN TO COMMUNICATION LOG
-- ============================================
-- So tournament-related communications are linkable
-- (communication_log table created in 013_email_sms_system migration)
ALTER TABLE communication_log ADD COLUMN IF NOT EXISTS tournament_id INTEGER REFERENCES tournaments(id) ON DELETE SET NULL;
