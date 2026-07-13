-- Migration: 041_codify_payment_schema_drift.sql
-- Description: Codify payment_items columns that exist in production Neon but were
--              never captured in a migration (queried by api/payment-items.php,
--              api/sibling-discount.php, api/invoices.php, registration/registrations-api.php),
--              and extend the invoices status CHECK to include 'partial', which
--              services/PaymentService.php already writes for partially paid invoices.
-- All statements are guarded so this is a no-op on databases that already have them.

-- ============================================
-- 1. PAYMENT ITEMS — drift columns
-- ============================================
ALTER TABLE payment_items ADD COLUMN IF NOT EXISTS accounting_code VARCHAR(50);
ALTER TABLE payment_items ADD COLUMN IF NOT EXISTS sibling_discount_enabled BOOLEAN DEFAULT false;
ALTER TABLE payment_items ADD COLUMN IF NOT EXISTS sibling_discount_type VARCHAR(20); -- 'percentage', 'fixed'
ALTER TABLE payment_items ADD COLUMN IF NOT EXISTS sibling_discount_value DECIMAL(10,2);

-- ============================================
-- 2. INVOICES — allow 'partial' status
-- ============================================
ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_status_check;
ALTER TABLE invoices ADD CONSTRAINT invoices_status_check
    CHECK (status IN ('draft', 'sent', 'viewed', 'partial', 'paid', 'overdue', 'cancelled'));

-- ============================================
-- COMMENTS
-- ============================================
COMMENT ON COLUMN payment_items.accounting_code IS 'Club-side accounting/GL code shown on invoices';
COMMENT ON COLUMN payment_items.sibling_discount_type IS 'percentage or fixed; applied by registration + sibling-discount APIs';
