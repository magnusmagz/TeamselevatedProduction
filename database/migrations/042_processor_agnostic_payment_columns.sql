-- Migration: 042_processor_agnostic_payment_columns.sql
-- Description: Additive processor-agnostic columns on payment_transactions ahead of the
--              Stripe integration (see docs/payments-stripe-implementation-plan.md).
--              The maverick_* columns from 001 are FROZEN/DEPRECATED — only demo-mode
--              data was ever written to them and no live Maverick integration exists.
--              New code records the processor name plus its IDs in these columns instead.

ALTER TABLE payment_transactions ADD COLUMN IF NOT EXISTS processor VARCHAR(20); -- 'stub', 'stripe'
ALTER TABLE payment_transactions ADD COLUMN IF NOT EXISTS processor_transaction_id VARCHAR(255);
ALTER TABLE payment_transactions ADD COLUMN IF NOT EXISTS processor_charge_id VARCHAR(255);
ALTER TABLE payment_transactions ADD COLUMN IF NOT EXISTS processor_customer_id VARCHAR(255);
ALTER TABLE payment_transactions ADD COLUMN IF NOT EXISTS processor_payment_method_id VARCHAR(255);

-- One processor never reuses a transaction id; uniqueness is per (processor, id).
CREATE UNIQUE INDEX IF NOT EXISTS uq_payment_txn_processor_txn
    ON payment_transactions(processor, processor_transaction_id)
    WHERE processor_transaction_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_payment_txn_processor_charge
    ON payment_transactions(processor_charge_id);

COMMENT ON COLUMN payment_transactions.processor IS 'Payment processor that handled this transaction: stub (demo) or stripe';
COMMENT ON COLUMN payment_transactions.maverick_transaction_id IS 'DEPRECATED: demo-era column, frozen. Use processor + processor_transaction_id.';
