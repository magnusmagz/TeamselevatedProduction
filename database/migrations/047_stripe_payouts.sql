-- Migration: 047_stripe_payouts.sql
-- Description: Payout ledger for treasurer reporting (Phase 6, pulled forward).
--              Rows are written by the payout.paid / payout.failed webhook
--              events from each club's connected account — "what actually hit
--              the club's bank, and when".

CREATE TABLE IF NOT EXISTS stripe_payouts (
    id SERIAL PRIMARY KEY,
    stripe_payout_id VARCHAR(255) UNIQUE NOT NULL,
    stripe_account_id VARCHAR(255) NOT NULL,
    club_id INTEGER REFERENCES club_profile(id),

    amount DECIMAL(10,2) NOT NULL,
    currency VARCHAR(10) DEFAULT 'usd',
    status VARCHAR(30) NOT NULL,           -- 'paid', 'failed', 'in_transit', ...
    arrival_date DATE,
    failure_message TEXT,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_stripe_payouts_club ON stripe_payouts(club_id, arrival_date);
CREATE INDEX IF NOT EXISTS idx_stripe_payouts_account ON stripe_payouts(stripe_account_id);
