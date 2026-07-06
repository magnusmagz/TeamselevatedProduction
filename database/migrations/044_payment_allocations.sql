-- Migration: 044_payment_allocations.sql
-- Description: Phase 2 of docs/payments-stripe-implementation-plan.md.
--              payment_allocations records how much of each payment transaction
--              was applied to each invoice — one payment can span several
--              invoices, and (Phase 3) several payers can split one invoice.
--              This table is what powers the "who paid what" ledger.
--              Also adds processor_session_id to payment_transactions
--              (Stripe Checkout Session id, for support/reconciliation).

CREATE TABLE IF NOT EXISTS payment_allocations (
    id SERIAL PRIMARY KEY,
    payment_transaction_id INTEGER NOT NULL REFERENCES payment_transactions(id) ON DELETE CASCADE,
    invoice_id INTEGER NOT NULL REFERENCES invoices(id),
    amount DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_payment_allocations_invoice
    ON payment_allocations(invoice_id);
CREATE INDEX IF NOT EXISTS idx_payment_allocations_txn
    ON payment_allocations(payment_transaction_id);

ALTER TABLE payment_transactions ADD COLUMN IF NOT EXISTS processor_session_id VARCHAR(255);

COMMENT ON TABLE payment_allocations IS 'Per-invoice application of payment transactions; source for the who-paid-what ledger';
