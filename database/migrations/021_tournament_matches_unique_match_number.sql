-- Migration: 021_tournament_matches_unique_match_number.sql
-- Description: Add UNIQUE (division_id, match_number) on tournament_matches.
--              Prevents BracketGenerator (and any other path) from silently
--              creating duplicate match_numbers within a division. Two duplicate
--              Final rows (#10 in U6 Coed, #22 in U14 State Cup) were observed
--              2026-04-29 in Spring Classic 2026 — root cause unclear, but the
--              constraint makes recurrence impossible: a duplicate insert will
--              throw and the transaction will roll back instead of accumulating
--              orphan rows.
-- Created: 2026-04-29
-- Phase: 2A — known-bug fixes
--
-- SAFETY:
--   - Additive (constraint addition is non-destructive to existing rows)
--   - Pre-flight check 2026-04-29 confirmed zero (division_id, match_number) dupes
--   - Re-run safe via the IF NOT EXISTS check

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.table_constraints
        WHERE table_name = 'tournament_matches'
          AND constraint_name = 'tournament_matches_division_match_number_unique'
    ) THEN
        ALTER TABLE tournament_matches
            ADD CONSTRAINT tournament_matches_division_match_number_unique
            UNIQUE (division_id, match_number);
    END IF;
END $$;

-- Verification: list constraints on tournament_matches
SELECT constraint_name, constraint_type
FROM information_schema.table_constraints
WHERE table_name = 'tournament_matches'
  AND constraint_type = 'UNIQUE'
ORDER BY constraint_name;
