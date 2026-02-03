-- Migration: 012_tryout_what_to_bring.sql
-- Description: Add what_to_bring field to programs for tryout checklists
-- Created: 2026-02-03

-- Add what_to_bring column (JSON array of checklist items)
ALTER TABLE programs ADD COLUMN IF NOT EXISTS what_to_bring JSONB;

-- Comment
COMMENT ON COLUMN programs.what_to_bring IS 'JSON array of items athletes should bring (e.g., ["Cleats", "Water bottle"])';
