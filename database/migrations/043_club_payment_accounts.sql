-- Migration: 043_club_payment_accounts.sql
-- Description: Stripe Connect account per club (Phase 1 of
--              docs/payments-stripe-implementation-plan.md). Each club onboards as a
--              Stripe Express connected account; live checkout is refused until
--              charges_enabled is true.

CREATE TABLE IF NOT EXISTS club_payment_accounts (
    id SERIAL PRIMARY KEY,
    club_id INTEGER NOT NULL REFERENCES club_profile(id) ON DELETE CASCADE,

    -- Stripe identifiers
    stripe_account_id VARCHAR(255) UNIQUE NOT NULL,

    -- Onboarding lifecycle (mirrors Stripe account state, updated by
    -- api/webhooks/stripe-connect.php on account.updated)
    onboarding_status VARCHAR(30) DEFAULT 'pending', -- 'pending', 'in_progress', 'complete', 'restricted'
    charges_enabled BOOLEAN DEFAULT false,
    payouts_enabled BOOLEAN DEFAULT false,
    details_submitted BOOLEAN DEFAULT false,
    requirements JSONB, -- Stripe's outstanding-requirements blob, for support/debugging

    created_by INTEGER REFERENCES users(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- One Stripe account per club
    CONSTRAINT uq_club_payment_account UNIQUE (club_id)
);

CREATE INDEX IF NOT EXISTS idx_club_payment_accounts_stripe
    ON club_payment_accounts(stripe_account_id);

COMMENT ON TABLE club_payment_accounts IS 'Stripe Connect (Express) account state per club; source of truth for whether a club may accept live payments';

-- Webhook idempotency ledger, shared by all Stripe webhook receivers
-- (api/webhooks/stripe-connect.php now, api/webhooks/stripe.php in Phase 2).
-- INSERT ... ON CONFLICT DO NOTHING on event_id is the dedup gate: rowCount 0
-- means the event was already handled.
CREATE TABLE IF NOT EXISTS stripe_webhook_events (
    id SERIAL PRIMARY KEY,
    event_id VARCHAR(255) UNIQUE NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_stripe_webhook_events_type
    ON stripe_webhook_events(event_type, received_at);
