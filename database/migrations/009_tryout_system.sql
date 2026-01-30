-- Migration: 009_tryout_system.sql
-- Description: Create tables for tryout management system with configurable evaluations
-- Created: 2026-01-30

-- ============================================
-- 1. EXTEND REGISTRATIONS TABLE FOR TRYOUT STATUS
-- ============================================

-- Add tryout-specific columns to registrations table
ALTER TABLE registrations ADD COLUMN IF NOT EXISTS tryout_status VARCHAR(30)
    CHECK (tryout_status IN (
        'registered',      -- Initial registration
        'checked_in',      -- Checked in at tryout
        'evaluated',       -- Has received at least one evaluation
        'offered',         -- Received roster offer
        'waitlist',        -- On waitlist
        'not_selected',    -- Not selected for roster
        'accepted',        -- Accepted offer
        'declined',        -- Declined offer
        'rostered'         -- Added to team roster
    ));

ALTER TABLE registrations ADD COLUMN IF NOT EXISTS overall_score DECIMAL(5,2);
ALTER TABLE registrations ADD COLUMN IF NOT EXISTS ranking INTEGER;
ALTER TABLE registrations ADD COLUMN IF NOT EXISTS assigned_team_id INTEGER REFERENCES teams(id);

-- Index for tryout status queries
CREATE INDEX IF NOT EXISTS idx_registrations_tryout_status ON registrations(tryout_status);
CREATE INDEX IF NOT EXISTS idx_registrations_ranking ON registrations(ranking);

-- ============================================
-- 2. TRYOUT SESSIONS TABLE
-- ============================================
-- Stores individual tryout dates/times (a program can have multiple sessions)
CREATE TABLE IF NOT EXISTS tryout_sessions (
    id SERIAL PRIMARY KEY,
    program_id INTEGER NOT NULL REFERENCES programs(id) ON DELETE CASCADE,
    session_date DATE NOT NULL,
    start_time TIME,
    end_time TIME,
    location VARCHAR(255),
    venue_id INTEGER REFERENCES venues(id),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Indexes for tryout_sessions
CREATE INDEX IF NOT EXISTS idx_tryout_sessions_program ON tryout_sessions(program_id);
CREATE INDEX IF NOT EXISTS idx_tryout_sessions_date ON tryout_sessions(session_date);

-- ============================================
-- 3. EVALUATION CRITERIA TABLE
-- ============================================
-- Admin-configurable criteria for evaluating athletes at tryouts
CREATE TABLE IF NOT EXISTS tryout_evaluation_criteria (
    id SERIAL PRIMARY KEY,
    program_id INTEGER NOT NULL REFERENCES programs(id) ON DELETE CASCADE,
    name VARCHAR(100) NOT NULL,           -- e.g., "Technical Skills"
    description TEXT,                      -- e.g., "Ball control, passing, shooting"
    max_score INTEGER DEFAULT 5,           -- 1-5 scale by default
    weight DECIMAL(3,2) DEFAULT 1.00,      -- For weighted averages
    display_order INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Indexes for tryout_evaluation_criteria
CREATE INDEX IF NOT EXISTS idx_tryout_criteria_program ON tryout_evaluation_criteria(program_id);
CREATE INDEX IF NOT EXISTS idx_tryout_criteria_order ON tryout_evaluation_criteria(program_id, display_order);

-- ============================================
-- 4. COACH EVALUATIONS TABLE
-- ============================================
-- Stores individual coach evaluations (one per coach per athlete)
CREATE TABLE IF NOT EXISTS tryout_evaluations (
    id SERIAL PRIMARY KEY,
    registration_id INTEGER NOT NULL REFERENCES registrations(id) ON DELETE CASCADE,
    evaluator_id INTEGER NOT NULL REFERENCES users(id),
    session_id INTEGER REFERENCES tryout_sessions(id),
    scores JSONB NOT NULL,                 -- {"criteria_id": score, ...}
    overall_score DECIMAL(5,2),            -- Calculated weighted average for this evaluation
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(registration_id, evaluator_id)  -- One evaluation per coach per athlete
);

-- Indexes for tryout_evaluations
CREATE INDEX IF NOT EXISTS idx_tryout_evaluations_registration ON tryout_evaluations(registration_id);
CREATE INDEX IF NOT EXISTS idx_tryout_evaluations_evaluator ON tryout_evaluations(evaluator_id);
CREATE INDEX IF NOT EXISTS idx_tryout_evaluations_session ON tryout_evaluations(session_id);
CREATE INDEX IF NOT EXISTS idx_tryout_evaluations_scores ON tryout_evaluations USING GIN (scores);

-- ============================================
-- 5. OFFERS TABLE
-- ============================================
-- Tracks offers sent to athletes and their responses
CREATE TABLE IF NOT EXISTS tryout_offers (
    id SERIAL PRIMARY KEY,
    registration_id INTEGER NOT NULL REFERENCES registrations(id) ON DELETE CASCADE,
    offer_type VARCHAR(20) NOT NULL CHECK (offer_type IN ('roster', 'waitlist', 'not_selected')),
    team_id INTEGER REFERENCES teams(id),
    response VARCHAR(20) CHECK (response IN ('accepted', 'declined')),
    notes TEXT,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    responded_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Indexes for tryout_offers
CREATE INDEX IF NOT EXISTS idx_tryout_offers_registration ON tryout_offers(registration_id);
CREATE INDEX IF NOT EXISTS idx_tryout_offers_team ON tryout_offers(team_id);
CREATE INDEX IF NOT EXISTS idx_tryout_offers_type ON tryout_offers(offer_type);
CREATE INDEX IF NOT EXISTS idx_tryout_offers_response ON tryout_offers(response);

-- ============================================
-- 6. TRIGGERS FOR UPDATED_AT
-- ============================================

-- Trigger function for updating updated_at timestamp
CREATE OR REPLACE FUNCTION update_tryout_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Apply triggers to all tryout tables
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trigger_tryout_sessions_updated_at') THEN
        CREATE TRIGGER trigger_tryout_sessions_updated_at
            BEFORE UPDATE ON tryout_sessions
            FOR EACH ROW
            EXECUTE FUNCTION update_tryout_updated_at();
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trigger_tryout_criteria_updated_at') THEN
        CREATE TRIGGER trigger_tryout_criteria_updated_at
            BEFORE UPDATE ON tryout_evaluation_criteria
            FOR EACH ROW
            EXECUTE FUNCTION update_tryout_updated_at();
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trigger_tryout_evaluations_updated_at') THEN
        CREATE TRIGGER trigger_tryout_evaluations_updated_at
            BEFORE UPDATE ON tryout_evaluations
            FOR EACH ROW
            EXECUTE FUNCTION update_tryout_updated_at();
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trigger_tryout_offers_updated_at') THEN
        CREATE TRIGGER trigger_tryout_offers_updated_at
            BEFORE UPDATE ON tryout_offers
            FOR EACH ROW
            EXECUTE FUNCTION update_tryout_updated_at();
    END IF;
END $$;

-- ============================================
-- 7. FUNCTION TO CALCULATE OVERALL SCORE
-- ============================================
-- Calculates weighted average score for an evaluation
CREATE OR REPLACE FUNCTION calculate_evaluation_score(
    p_scores JSONB,
    p_program_id INTEGER
) RETURNS DECIMAL(5,2) AS $$
DECLARE
    v_weighted_sum DECIMAL(10,4) := 0;
    v_total_weight DECIMAL(10,4) := 0;
    v_criteria RECORD;
    v_score INTEGER;
BEGIN
    FOR v_criteria IN
        SELECT id, weight, max_score
        FROM tryout_evaluation_criteria
        WHERE program_id = p_program_id
    LOOP
        v_score := (p_scores->>v_criteria.id::text)::INTEGER;
        IF v_score IS NOT NULL THEN
            -- Normalize score to 0-100 scale, then apply weight
            v_weighted_sum := v_weighted_sum + ((v_score::DECIMAL / v_criteria.max_score) * 100 * v_criteria.weight);
            v_total_weight := v_total_weight + v_criteria.weight;
        END IF;
    END LOOP;

    IF v_total_weight > 0 THEN
        RETURN ROUND(v_weighted_sum / v_total_weight, 2);
    END IF;

    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

-- ============================================
-- 8. TRIGGER TO UPDATE REGISTRATION SCORES
-- ============================================
-- Automatically updates registration overall_score when evaluations change
CREATE OR REPLACE FUNCTION update_registration_score()
RETURNS TRIGGER AS $$
DECLARE
    v_avg_score DECIMAL(5,2);
    v_registration_id INTEGER;
BEGIN
    -- Get the registration_id based on operation type
    IF TG_OP = 'DELETE' THEN
        v_registration_id := OLD.registration_id;
    ELSE
        v_registration_id := NEW.registration_id;
    END IF;

    -- Calculate average overall_score across all evaluators
    SELECT AVG(overall_score)
    INTO v_avg_score
    FROM tryout_evaluations
    WHERE registration_id = v_registration_id
    AND overall_score IS NOT NULL;

    -- Update registration
    UPDATE registrations
    SET overall_score = v_avg_score,
        tryout_status = CASE
            WHEN tryout_status = 'checked_in' THEN 'evaluated'
            ELSE tryout_status
        END
    WHERE id = v_registration_id;

    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trigger_update_registration_score') THEN
        CREATE TRIGGER trigger_update_registration_score
            AFTER INSERT OR UPDATE OR DELETE ON tryout_evaluations
            FOR EACH ROW
            EXECUTE FUNCTION update_registration_score();
    END IF;
END $$;

-- ============================================
-- COMMENTS
-- ============================================
COMMENT ON TABLE tryout_sessions IS 'Individual tryout dates/times for a program';
COMMENT ON TABLE tryout_evaluation_criteria IS 'Admin-configurable criteria for evaluating athletes';
COMMENT ON TABLE tryout_evaluations IS 'Individual coach evaluations with scores per criteria';
COMMENT ON TABLE tryout_offers IS 'Offers sent to athletes (roster, waitlist, not_selected)';

COMMENT ON COLUMN tryout_evaluations.scores IS 'JSON object mapping criteria_id to score value';
COMMENT ON COLUMN tryout_evaluations.overall_score IS 'Weighted average score for this single evaluation';
COMMENT ON COLUMN registrations.overall_score IS 'Average score across all evaluators';
COMMENT ON COLUMN registrations.tryout_status IS 'Current status in the tryout workflow';
COMMENT ON COLUMN registrations.assigned_team_id IS 'Team athlete is assigned to after acceptance';
