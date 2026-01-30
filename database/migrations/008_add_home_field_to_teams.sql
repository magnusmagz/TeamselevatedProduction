-- Add home_field_id column to teams table
ALTER TABLE teams ADD COLUMN IF NOT EXISTS home_field_id INTEGER REFERENCES fields(id);
