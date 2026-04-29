-- Migration: 020_tournament_venue.sql
-- Description: Link tournaments to venues for field selection + add daily scheduling
--              window. Replaces the silently-broken "no fields" path in the
--              schedule generator with a clean venue→fields lookup.
-- Created: 2026-04-29
-- Phase: 2A — demo-readiness sprint, item #1
--
-- SAFETY:
--   - Additive only: ADD COLUMN IF NOT EXISTS, partial INDEX
--   - No DROP / RENAME / TYPE CHANGE
--   - Existing 1-row tournament data preserved; nullable venue_id and
--     defaulted time columns mean existing rows stay valid
--   - Re-run safe (every statement idempotent)
--   - Demo data preserved (RAC Complex venue id=37 already exists with 12 fields)

-- ============================================
-- 1. tournaments.venue_id — link to venues table
-- ============================================
-- Nullable so legacy tournaments without a venue keep working.
-- ON DELETE SET NULL so deleting a venue doesn't cascade-delete tournaments.
ALTER TABLE tournaments
    ADD COLUMN IF NOT EXISTS venue_id INTEGER REFERENCES venues(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_tournaments_venue
    ON tournaments(venue_id)
    WHERE venue_id IS NOT NULL;

-- ============================================
-- 2. Daily scheduling window
-- ============================================
-- Tournament-level policy (not venue-level) — same venue may host an evening
-- adult league one weekend and an 8-AM-start youth tournament the next.
-- Defaults: 8 AM – 8 PM. Existing tournaments inherit the defaults; matches
-- already scheduled outside the window are NOT touched.
ALTER TABLE tournaments
    ADD COLUMN IF NOT EXISTS daily_start_time TIME DEFAULT '08:00:00';
ALTER TABLE tournaments
    ADD COLUMN IF NOT EXISTS daily_end_time TIME DEFAULT '20:00:00';

-- ============================================
-- 3. Seed Spring Classic 2026 to RAC Complex (venue 37, 12 fields)
-- ============================================
-- Only set if currently NULL — won't overwrite a director's later choice.
-- Spring Classic 2026 today says "Memorial Park, Seattle WA" in legacy
-- location_* columns. Those stay (additive rule); reads prefer venue when set.
UPDATE tournaments
SET venue_id = 37,
    updated_at = CURRENT_TIMESTAMP
WHERE id = 1
  AND venue_id IS NULL
  AND EXISTS (SELECT 1 FROM venues WHERE id = 37);

-- ============================================
-- Verification (visible output, not destructive)
-- ============================================
SELECT
    t.id,
    t.name,
    t.venue_id,
    v.name AS venue_name,
    v.city AS venue_city,
    v.state AS venue_state,
    t.daily_start_time,
    t.daily_end_time,
    (SELECT COUNT(*) FROM fields WHERE venue_id = t.venue_id AND active = true) AS available_fields
FROM tournaments t
LEFT JOIN venues v ON t.venue_id = v.id
ORDER BY t.id;
