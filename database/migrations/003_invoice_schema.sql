-- Invoice Schema Migration
-- Adds invoicing tables for parent-facing invoice management

-- Invoices table
CREATE TABLE IF NOT EXISTS invoices (
    id SERIAL PRIMARY KEY,
    invoice_number VARCHAR(20) UNIQUE NOT NULL,
    athlete_id INTEGER REFERENCES athletes(id),
    athlete_payment_id INTEGER REFERENCES athlete_payments(id),
    program_id INTEGER REFERENCES programs(id),
    league_id INTEGER REFERENCES leagues(id),

    invoice_date DATE NOT NULL DEFAULT CURRENT_DATE,
    due_date DATE NOT NULL,

    subtotal DECIMAL(10,2) NOT NULL,
    discount_amount DECIMAL(10,2) DEFAULT 0,
    total_amount DECIMAL(10,2) NOT NULL,
    amount_paid DECIMAL(10,2) DEFAULT 0,

    status VARCHAR(20) DEFAULT 'draft',  -- draft, sent, viewed, paid, overdue, cancelled

    memo TEXT,
    pdf_url VARCHAR(500),

    sent_at TIMESTAMP,
    viewed_at TIMESTAMP,
    paid_at TIMESTAMP,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Check constraint for status
ALTER TABLE invoices ADD CONSTRAINT invoices_status_check
    CHECK (status IN ('draft', 'sent', 'viewed', 'paid', 'overdue', 'cancelled'));

-- Invoice line items (for itemized invoices)
CREATE TABLE IF NOT EXISTS invoice_items (
    id SERIAL PRIMARY KEY,
    invoice_id INTEGER REFERENCES invoices(id) ON DELETE CASCADE,
    payment_item_id INTEGER REFERENCES payment_items(id),

    description VARCHAR(255) NOT NULL,
    quantity INTEGER DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL,
    line_total DECIMAL(10,2) NOT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Invoice email tracking
CREATE TABLE IF NOT EXISTS invoice_emails (
    id SERIAL PRIMARY KEY,
    invoice_id INTEGER REFERENCES invoices(id) ON DELETE CASCADE,
    email_type VARCHAR(20) NOT NULL,  -- initial, reminder, receipt
    sent_to VARCHAR(255) NOT NULL,
    subject VARCHAR(255),
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    opened_at TIMESTAMP,
    clicked_at TIMESTAMP
);

-- Check constraint for email type
ALTER TABLE invoice_emails ADD CONSTRAINT invoice_emails_type_check
    CHECK (email_type IN ('initial', 'reminder', 'receipt'));

-- Indexes for performance
CREATE INDEX IF NOT EXISTS idx_invoices_athlete ON invoices(athlete_id);
CREATE INDEX IF NOT EXISTS idx_invoices_status ON invoices(status);
CREATE INDEX IF NOT EXISTS idx_invoices_league ON invoices(league_id);
CREATE INDEX IF NOT EXISTS idx_invoices_due ON invoices(due_date);
CREATE INDEX IF NOT EXISTS idx_invoices_number ON invoices(invoice_number);
CREATE INDEX IF NOT EXISTS idx_invoice_items_invoice ON invoice_items(invoice_id);
CREATE INDEX IF NOT EXISTS idx_invoice_emails_invoice ON invoice_emails(invoice_id);

-- Sequence for invoice numbers (format: INV-YYYYMM-XXXXX)
CREATE SEQUENCE IF NOT EXISTS invoice_number_seq START 1;

-- Function to generate invoice number
CREATE OR REPLACE FUNCTION generate_invoice_number()
RETURNS VARCHAR(20) AS $$
DECLARE
    new_number VARCHAR(20);
BEGIN
    new_number := 'INV-' || TO_CHAR(CURRENT_DATE, 'YYYYMM') || '-' ||
                  LPAD(nextval('invoice_number_seq')::TEXT, 5, '0');
    RETURN new_number;
END;
$$ LANGUAGE plpgsql;

-- Comments
COMMENT ON TABLE invoices IS 'Parent-facing invoices for payment obligations';
COMMENT ON TABLE invoice_items IS 'Line items for itemized invoices';
COMMENT ON TABLE invoice_emails IS 'Email tracking for invoice communications';
COMMENT ON COLUMN invoices.status IS 'Invoice status: draft, sent, viewed, paid, overdue, cancelled';
