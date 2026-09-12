-- 102_invoice_scholarship.sql
--
-- A scholarship awarded ON an invoice (Maggie, decided 2026-09-12; plan in
-- docs/scholarship-on-invoice-plan-2026-09.md).
--
-- A club admin or treasurer reduces what one family owes on one athlete's
-- invoice. The amount is its OWN column — invoices.discount_amount is the
-- sibling discount and the two are reported separately — and the family-facing
-- label is separate from the internal reason, which never leaves the staff side.
--
--   total_amount = subtotal - discount_amount - scholarship_amount
--
-- is maintained by lib/scholarship.php (te_scholarship_apply / te_scholarship_revoke),
-- the only writers. scholarship_id is a nullable link to the pre-existing
-- `scholarships` fund table (migration 001, no writers yet) so budget tracking
-- can attach later without another migration. Additive only.

ALTER TABLE invoices ADD COLUMN IF NOT EXISTS scholarship_amount NUMERIC(10,2) NOT NULL DEFAULT 0;
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS scholarship_label VARCHAR(80);
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS scholarship_reason TEXT;
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS scholarship_id INTEGER REFERENCES scholarships(id);
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS scholarship_awarded_by INTEGER REFERENCES users(id);
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS scholarship_awarded_at TIMESTAMP;

-- The treasurer summary sums awards by date within a club; the club is reached
-- through the athlete, so the awarded_at index is what the range scan needs.
CREATE INDEX IF NOT EXISTS idx_invoices_scholarship_awarded_at
    ON invoices (scholarship_awarded_at) WHERE scholarship_awarded_at IS NOT NULL;

COMMENT ON COLUMN invoices.scholarship_amount IS 'Staff-awarded reduction; total_amount = subtotal - discount_amount - scholarship_amount';
COMMENT ON COLUMN invoices.scholarship_label IS 'What the family sees on the invoice (default "Scholarship")';
COMMENT ON COLUMN invoices.scholarship_reason IS 'Internal. Financial admins only; never emailed';
