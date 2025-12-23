-- ============================================
-- PAYMENT SYSTEM DATABASE SCHEMA
-- Teams Elevated - Revenue Collection
-- ============================================
-- Creates all tables for payment processing
-- ============================================

BEGIN;

-- ============================================
-- 1. PAYMENT PLANS
-- ============================================
CREATE TABLE IF NOT EXISTS payment_plans (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,

    -- Plan structure
    total_installments INTEGER NOT NULL,
    frequency VARCHAR(20) NOT NULL, -- 'weekly', 'biweekly', 'monthly'
    down_payment_percentage DECIMAL(5,2) DEFAULT 0.00, -- 0-100

    -- Scoping (which items can use this plan)
    league_id INTEGER REFERENCES leagues(id),
    club_id INTEGER REFERENCES club_profile(id),

    -- Configuration
    auto_pay_required BOOLEAN DEFAULT true,
    late_fee_amount DECIMAL(10,2) DEFAULT 0.00,
    grace_period_days INTEGER DEFAULT 3,

    active BOOLEAN DEFAULT true,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_payment_plans_league ON payment_plans(league_id);
CREATE INDEX idx_payment_plans_club ON payment_plans(club_id);

-- ============================================
-- 2. PAYMENT ITEMS
-- ============================================
CREATE TABLE IF NOT EXISTS payment_items (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    item_type VARCHAR(50) NOT NULL, -- 'registration', 'dues', 'uniform', 'tournament', 'donation', 'merchandise', 'late_fee'
    base_price DECIMAL(10,2) NOT NULL,

    -- Scoping
    league_id INTEGER REFERENCES leagues(id),
    club_id INTEGER REFERENCES club_profile(id),
    team_id INTEGER REFERENCES teams(id),
    program_id INTEGER REFERENCES programs(id),

    -- Configuration
    is_recurring BOOLEAN DEFAULT false,
    recurring_frequency VARCHAR(20), -- 'monthly', 'quarterly', 'annual'
    is_required BOOLEAN DEFAULT true,
    allow_payment_plan BOOLEAN DEFAULT false,

    -- Availability
    available_from TIMESTAMP,
    available_to TIMESTAMP,
    max_quantity INTEGER,

    -- Maverick Payments integration
    maverick_product_id VARCHAR(255),
    maverick_price_id VARCHAR(255),

    active BOOLEAN DEFAULT true,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_payment_items_league ON payment_items(league_id);
CREATE INDEX idx_payment_items_club ON payment_items(club_id);
CREATE INDEX idx_payment_items_program ON payment_items(program_id);
CREATE INDEX idx_payment_items_type ON payment_items(item_type);

-- ============================================
-- 3. SCHOLARSHIPS
-- ============================================
CREATE TABLE IF NOT EXISTS scholarships (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,

    -- Scoping
    league_id INTEGER REFERENCES leagues(id),
    club_id INTEGER REFERENCES club_profile(id),

    -- Fund details
    total_budget DECIMAL(10,2),
    remaining_budget DECIMAL(10,2),

    -- Award structure
    award_type VARCHAR(20) NOT NULL, -- 'percentage', 'fixed_amount', 'full'
    award_percentage DECIMAL(5,2), -- if percentage
    award_amount DECIMAL(10,2), -- if fixed
    max_awards INTEGER,
    awards_given INTEGER DEFAULT 0,

    -- Eligibility
    eligibility_criteria TEXT,
    requires_application BOOLEAN DEFAULT true,
    application_deadline TIMESTAMP,

    -- Season/Program
    season_id INTEGER,
    program_id INTEGER REFERENCES programs(id),

    active BOOLEAN DEFAULT true,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_scholarships_league ON scholarships(league_id);
CREATE INDEX idx_scholarships_club ON scholarships(club_id);
CREATE INDEX idx_scholarships_program ON scholarships(program_id);

-- ============================================
-- 4. SCHOLARSHIP APPLICATIONS
-- ============================================
CREATE TABLE IF NOT EXISTS scholarship_applications (
    id SERIAL PRIMARY KEY,
    scholarship_id INTEGER REFERENCES scholarships(id) ON DELETE CASCADE,

    -- Applicant
    user_id INTEGER REFERENCES users(id),
    athlete_id INTEGER REFERENCES athletes(id),

    -- Application details
    application_data JSONB, -- flexible field for form responses
    financial_need_statement TEXT,
    supporting_documents JSONB, -- array of file URLs

    -- Review
    status VARCHAR(20) DEFAULT 'pending', -- 'pending', 'under_review', 'approved', 'denied'
    reviewed_by INTEGER REFERENCES users(id),
    reviewed_at TIMESTAMP,
    review_notes TEXT,

    -- Award
    approved_amount DECIMAL(10,2),
    approved_percentage DECIMAL(5,2),

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_scholarship_apps_scholarship ON scholarship_applications(scholarship_id);
CREATE INDEX idx_scholarship_apps_athlete ON scholarship_applications(athlete_id);
CREATE INDEX idx_scholarship_apps_status ON scholarship_applications(status);

-- ============================================
-- 5. DISCOUNT CODES
-- ============================================
CREATE TABLE IF NOT EXISTS discount_codes (
    id SERIAL PRIMARY KEY,
    code VARCHAR(50) UNIQUE NOT NULL,
    description TEXT,

    -- Discount structure
    discount_type VARCHAR(20) NOT NULL, -- 'percentage', 'fixed_amount'
    discount_value DECIMAL(10,2) NOT NULL,
    max_discount_amount DECIMAL(10,2), -- cap for percentage discounts

    -- Usage limits
    max_uses INTEGER,
    times_used INTEGER DEFAULT 0,
    max_uses_per_user INTEGER DEFAULT 1,

    -- Scoping
    league_id INTEGER REFERENCES leagues(id),
    club_id INTEGER REFERENCES club_profile(id),
    applicable_item_types TEXT[], -- which payment_item types it applies to

    -- Validity
    valid_from TIMESTAMP,
    valid_to TIMESTAMP,

    active BOOLEAN DEFAULT true,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_discount_codes_code ON discount_codes(code);
CREATE INDEX idx_discount_codes_league ON discount_codes(league_id);

-- ============================================
-- 6. ATHLETE PAYMENTS
-- ============================================
CREATE TABLE IF NOT EXISTS athlete_payments (
    id SERIAL PRIMARY KEY,
    athlete_id INTEGER REFERENCES athletes(id) ON DELETE CASCADE,
    payment_item_id INTEGER REFERENCES payment_items(id),
    program_id INTEGER REFERENCES programs(id), -- Direct program tracking for reporting

    -- Amount details
    base_amount DECIMAL(10,2) NOT NULL,
    discount_amount DECIMAL(10,2) DEFAULT 0.00,
    scholarship_amount DECIMAL(10,2) DEFAULT 0.00,
    final_amount DECIMAL(10,2) NOT NULL, -- base - discount - scholarship

    -- Payment plan
    payment_plan_id INTEGER REFERENCES payment_plans(id),

    -- References
    scholarship_application_id INTEGER REFERENCES scholarship_applications(id),
    discount_code_id INTEGER REFERENCES discount_codes(id),

    -- Status
    status VARCHAR(20) DEFAULT 'pending', -- 'pending', 'partial', 'paid', 'refunded', 'waived'
    amount_paid DECIMAL(10,2) DEFAULT 0.00,
    amount_remaining DECIMAL(10,2),

    -- Dates
    due_date TIMESTAMP,
    paid_at TIMESTAMP,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_athlete_payments_athlete ON athlete_payments(athlete_id);
CREATE INDEX idx_athlete_payments_program ON athlete_payments(program_id);
CREATE INDEX idx_athlete_payments_status ON athlete_payments(status);
CREATE INDEX idx_athlete_payments_item ON athlete_payments(payment_item_id);

-- ============================================
-- 7. PAYMENT TRANSACTIONS
-- ============================================
CREATE TABLE IF NOT EXISTS payment_transactions (
    id SERIAL PRIMARY KEY,
    athlete_payment_id INTEGER REFERENCES athlete_payments(id),

    -- Payment details
    amount DECIMAL(10,2) NOT NULL,
    payment_method VARCHAR(50), -- 'credit_card', 'debit_card', 'ach', 'cash', 'check', 'waived'

    -- Maverick Payments integration
    maverick_transaction_id VARCHAR(255) UNIQUE,
    maverick_charge_id VARCHAR(255),
    maverick_customer_id VARCHAR(255),
    maverick_payment_method_id VARCHAR(255),

    -- Transaction info
    status VARCHAR(20) DEFAULT 'pending', -- 'pending', 'processing', 'succeeded', 'failed', 'refunded', 'canceled'
    failure_reason TEXT,
    receipt_url TEXT,

    -- Metadata
    payment_type VARCHAR(20), -- 'full', 'installment', 'partial'
    installment_number INTEGER, -- if part of payment plan
    paid_by_user_id INTEGER REFERENCES users(id),

    -- Refunds
    refund_amount DECIMAL(10,2) DEFAULT 0.00,
    refund_reason TEXT,
    refunded_at TIMESTAMP,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_payment_txn_athlete_payment ON payment_transactions(athlete_payment_id);
CREATE INDEX idx_payment_txn_status ON payment_transactions(status);
CREATE INDEX idx_payment_txn_maverick ON payment_transactions(maverick_transaction_id);

-- ============================================
-- 8. PAYMENT INSTALLMENTS
-- ============================================
CREATE TABLE IF NOT EXISTS payment_installments (
    id SERIAL PRIMARY KEY,
    athlete_payment_id INTEGER REFERENCES athlete_payments(id) ON DELETE CASCADE,
    payment_plan_id INTEGER REFERENCES payment_plans(id),

    -- Installment details
    installment_number INTEGER NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    due_date TIMESTAMP NOT NULL,

    -- Status
    status VARCHAR(20) DEFAULT 'scheduled', -- 'scheduled', 'processing', 'paid', 'failed', 'late', 'waived'
    paid_amount DECIMAL(10,2) DEFAULT 0.00,
    paid_at TIMESTAMP,

    -- Late fees
    late_fee_assessed DECIMAL(10,2) DEFAULT 0.00,
    late_fee_paid DECIMAL(10,2) DEFAULT 0.00,

    -- Auto-pay
    auto_pay_enabled BOOLEAN DEFAULT false,
    auto_pay_attempts INTEGER DEFAULT 0,
    next_retry_at TIMESTAMP,

    -- Transaction reference
    transaction_id INTEGER REFERENCES payment_transactions(id),

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_installments_athlete_payment ON payment_installments(athlete_payment_id);
CREATE INDEX idx_installments_due_date ON payment_installments(due_date);
CREATE INDEX idx_installments_status ON payment_installments(status);

-- ============================================
-- 9. FINANCIAL REPORTS (cached)
-- ============================================
CREATE TABLE IF NOT EXISTS financial_reports (
    id SERIAL PRIMARY KEY,

    -- Report scope
    report_type VARCHAR(50) NOT NULL, -- 'revenue_summary', 'outstanding_payments', 'scholarship_usage', 'payment_plan_status'
    league_id INTEGER REFERENCES leagues(id),
    club_id INTEGER REFERENCES club_profile(id),
    team_id INTEGER REFERENCES teams(id),

    -- Time period
    period_start TIMESTAMP NOT NULL,
    period_end TIMESTAMP NOT NULL,

    -- Report data (JSON for flexibility)
    report_data JSONB NOT NULL,

    -- Metadata
    generated_by INTEGER REFERENCES users(id),
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_financial_reports_type ON financial_reports(report_type);
CREATE INDEX idx_financial_reports_league ON financial_reports(league_id);
CREATE INDEX idx_financial_reports_period ON financial_reports(period_start, period_end);

-- ============================================
-- 10. ADD MAVERICK CUSTOMER ID TO USERS
-- ============================================
-- Add column to users table for storing Maverick customer ID
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'users' AND column_name = 'maverick_customer_id'
    ) THEN
        ALTER TABLE users ADD COLUMN maverick_customer_id VARCHAR(255);
        CREATE INDEX idx_users_maverick_customer ON users(maverick_customer_id);
    END IF;
END $$;

COMMIT;

-- ============================================
-- VERIFICATION
-- ============================================
SELECT 'Payment schema created successfully!' as status;

-- Show created tables
SELECT table_name
FROM information_schema.tables
WHERE table_schema = 'public'
  AND table_name IN (
    'payment_plans',
    'payment_items',
    'scholarships',
    'scholarship_applications',
    'discount_codes',
    'athlete_payments',
    'payment_transactions',
    'payment_installments',
    'financial_reports'
  )
ORDER BY table_name;
