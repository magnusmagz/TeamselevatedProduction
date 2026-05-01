-- Migration: 025_division_scoring_system.sql
-- Description: Add scoring_system to tournament_divisions so a director can
--              switch a division between standard 3-1-0 and the 10-point
--              cup-tournament system (6 win / 3 tie / 0 loss + 1 per goal
--              capped at 3 + 1 for a shutout). The standings calculator
--              branches on this column. Existing rows default to 'standard'
--              so no behavior changes for current divisions.
-- Created: 2026-05-01
-- Phase: Module 1 polish (scoring system + tiebreakers)
--
-- SAFETY: additive nullable column with default. No data changes. Idempotent.

ALTER TABLE tournament_divisions
    ADD COLUMN IF NOT EXISTS scoring_system VARCHAR(32) NOT NULL DEFAULT 'standard';
