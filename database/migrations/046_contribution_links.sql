-- Migration: 046_contribution_links.sql
-- Description: Phase 4 of docs/payments-stripe-implementation-plan.md — the
--              "sphere of influence" contribution link. A link is INVOICE-BOUND
--              (payments toward a fee owed to the club, never crowdfunding):
--              capped at the invoice's remaining balance, auto-closed at goal,
--              funds settle to the club's connected account like any payment.

CREATE TABLE IF NOT EXISTS contribution_links (
    id SERIAL PRIMARY KEY,
    token VARCHAR(64) UNIQUE NOT NULL,          -- 128-bit hex, the only public identifier
    invoice_id INTEGER NOT NULL REFERENCES invoices(id),
    created_by_user_id INTEGER REFERENCES users(id),

    display_name VARCHAR(120) NOT NULL,         -- e.g. "Jamie — Summer Camp 2026" (first name only)
    message TEXT,                               -- optional note from the family

    status VARCHAR(20) DEFAULT 'active',        -- 'active', 'completed', 'closed', 'expired'
    expires_at TIMESTAMP,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_contribution_links_invoice ON contribution_links(invoice_id);
CREATE INDEX IF NOT EXISTS idx_contribution_links_status ON contribution_links(status);

CREATE TABLE IF NOT EXISTS invoice_contributions (
    id SERIAL PRIMARY KEY,
    contribution_link_id INTEGER NOT NULL REFERENCES contribution_links(id),
    payment_transaction_id INTEGER REFERENCES payment_transactions(id),

    contributor_name VARCHAR(200),
    contributor_email VARCHAR(255),
    is_anonymous BOOLEAN DEFAULT false,
    comment TEXT,                               -- visible to the family only, not public

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_invoice_contributions_link ON invoice_contributions(contribution_link_id);

COMMENT ON TABLE contribution_links IS 'Shareable pay-toward-an-invoice links; club is merchant of record, capped at remaining balance';
