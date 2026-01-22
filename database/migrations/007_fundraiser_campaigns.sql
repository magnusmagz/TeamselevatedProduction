-- Migration: 007_fundraiser_campaigns.sql
-- Description: Create tables for fundraiser campaigns with goal-based donations
-- Created: 2026-01-22

-- ============================================
-- 1. FUNDRAISER CAMPAIGNS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS fundraiser_campaigns (
    id SERIAL PRIMARY KEY,
    club_id INTEGER REFERENCES club_profile(id) ON DELETE CASCADE,

    -- Campaign details
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(100) NOT NULL,              -- URL-friendly: "new-court-floors"
    description TEXT,
    image_url TEXT,                          -- Campaign hero image

    -- Goal & progress (denormalized for performance)
    goal_amount DECIMAL(10,2) NOT NULL,
    amount_raised DECIMAL(10,2) DEFAULT 0,
    donor_count INTEGER DEFAULT 0,

    -- Timing
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,

    -- Settings
    show_donor_names BOOLEAN DEFAULT true,
    show_donor_amounts BOOLEAN DEFAULT true,
    show_progress BOOLEAN DEFAULT true,
    allow_comments BOOLEAN DEFAULT true,

    -- Status: draft, active, ended, cancelled
    status VARCHAR(20) DEFAULT 'draft',

    -- Audit
    created_by INTEGER REFERENCES users(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP,
    deleted_by INTEGER REFERENCES users(id),

    -- Unique slug per club
    CONSTRAINT uq_campaign_club_slug UNIQUE(club_id, slug)
);

-- Indexes for campaigns
CREATE INDEX idx_campaigns_club_id ON fundraiser_campaigns(club_id);
CREATE INDEX idx_campaigns_status ON fundraiser_campaigns(status);
CREATE INDEX idx_campaigns_slug ON fundraiser_campaigns(slug);
CREATE INDEX idx_campaigns_dates ON fundraiser_campaigns(start_date, end_date);
CREATE INDEX idx_campaigns_deleted_at ON fundraiser_campaigns(deleted_at);

-- ============================================
-- 2. CAMPAIGN DONATIONS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS campaign_donations (
    id SERIAL PRIMARY KEY,
    campaign_id INTEGER REFERENCES fundraiser_campaigns(id) ON DELETE CASCADE,

    -- Donor info (guest checkout - no account required)
    donor_name VARCHAR(200) NOT NULL,
    donor_email VARCHAR(255) NOT NULL,
    donor_phone VARCHAR(20),
    is_anonymous BOOLEAN DEFAULT false,

    -- Payment
    amount DECIMAL(10,2) NOT NULL,
    comment TEXT,                            -- Public message

    -- Transaction (Maverick integration)
    maverick_transaction_id VARCHAR(255),
    maverick_charge_id VARCHAR(255),
    status VARCHAR(20) DEFAULT 'pending',    -- pending, succeeded, failed, refunded

    -- Receipt
    receipt_sent_at TIMESTAMP,

    -- Audit
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP,
    deleted_by INTEGER REFERENCES users(id)
);

-- Indexes for donations
CREATE INDEX idx_donations_campaign_id ON campaign_donations(campaign_id);
CREATE INDEX idx_donations_status ON campaign_donations(status);
CREATE INDEX idx_donations_donor_email ON campaign_donations(donor_email);
CREATE INDEX idx_donations_created_at ON campaign_donations(created_at);
CREATE INDEX idx_donations_deleted_at ON campaign_donations(deleted_at);

-- ============================================
-- 3. CAMPAIGN UPDATES TABLE (admin posts)
-- ============================================
CREATE TABLE IF NOT EXISTS campaign_updates (
    id SERIAL PRIMARY KEY,
    campaign_id INTEGER REFERENCES fundraiser_campaigns(id) ON DELETE CASCADE,
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    created_by INTEGER REFERENCES users(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP,
    deleted_by INTEGER REFERENCES users(id)
);

-- Indexes for updates
CREATE INDEX idx_updates_campaign_id ON campaign_updates(campaign_id);
CREATE INDEX idx_updates_created_at ON campaign_updates(created_at);
CREATE INDEX idx_updates_deleted_at ON campaign_updates(deleted_at);

-- ============================================
-- 4. TRIGGER FOR UPDATED_AT
-- ============================================
CREATE OR REPLACE FUNCTION update_fundraiser_campaign_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_fundraiser_campaign_updated_at
    BEFORE UPDATE ON fundraiser_campaigns
    FOR EACH ROW
    EXECUTE FUNCTION update_fundraiser_campaign_updated_at();

-- ============================================
-- 5. FUNCTION TO UPDATE CAMPAIGN TOTALS
-- ============================================
CREATE OR REPLACE FUNCTION update_campaign_totals()
RETURNS TRIGGER AS $$
BEGIN
    -- Update when donation status changes to succeeded
    IF TG_OP = 'INSERT' OR TG_OP = 'UPDATE' THEN
        UPDATE fundraiser_campaigns
        SET amount_raised = (
                SELECT COALESCE(SUM(amount), 0)
                FROM campaign_donations
                WHERE campaign_id = NEW.campaign_id
                AND status = 'succeeded'
                AND deleted_at IS NULL
            ),
            donor_count = (
                SELECT COUNT(DISTINCT donor_email)
                FROM campaign_donations
                WHERE campaign_id = NEW.campaign_id
                AND status = 'succeeded'
                AND deleted_at IS NULL
            )
        WHERE id = NEW.campaign_id;
        RETURN NEW;
    END IF;

    -- Handle deletes
    IF TG_OP = 'DELETE' THEN
        UPDATE fundraiser_campaigns
        SET amount_raised = (
                SELECT COALESCE(SUM(amount), 0)
                FROM campaign_donations
                WHERE campaign_id = OLD.campaign_id
                AND status = 'succeeded'
                AND deleted_at IS NULL
            ),
            donor_count = (
                SELECT COUNT(DISTINCT donor_email)
                FROM campaign_donations
                WHERE campaign_id = OLD.campaign_id
                AND status = 'succeeded'
                AND deleted_at IS NULL
            )
        WHERE id = OLD.campaign_id;
        RETURN OLD;
    END IF;

    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_update_campaign_totals
    AFTER INSERT OR UPDATE OR DELETE ON campaign_donations
    FOR EACH ROW
    EXECUTE FUNCTION update_campaign_totals();

-- ============================================
-- COMMENTS
-- ============================================
COMMENT ON TABLE fundraiser_campaigns IS 'Goal-based fundraiser campaigns with progress tracking';
COMMENT ON TABLE campaign_donations IS 'Individual donations to fundraiser campaigns (guest checkout)';
COMMENT ON TABLE campaign_updates IS 'Admin-posted updates for campaign supporters';
COMMENT ON COLUMN fundraiser_campaigns.slug IS 'URL-friendly identifier unique per club';
COMMENT ON COLUMN fundraiser_campaigns.amount_raised IS 'Denormalized total, auto-updated by trigger';
COMMENT ON COLUMN fundraiser_campaigns.donor_count IS 'Denormalized count of unique donors, auto-updated';
COMMENT ON COLUMN campaign_donations.is_anonymous IS 'If true, donor name hidden on public donor wall';
