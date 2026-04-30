-- Migration: 023_match_referee_report.sql
-- Description: Add referee-report fields to tournament_matches so the Match
--              Center modal's "Referee Report" tab can persist field
--              conditions, incident reports, and a match-card photo URL.
--              Goals, yellow/red cards, etc. are persisted in the existing
--              tournament_match_events table (Phase 2 schema, currently
--              UI-less — Match Center will be its first consumer).
-- Created: 2026-04-30
-- Phase: 2A — Match Center upgrade
--
-- SAFETY: additive nullable columns. No data changes. Idempotent.

ALTER TABLE tournament_matches
    ADD COLUMN IF NOT EXISTS field_conditions TEXT;

ALTER TABLE tournament_matches
    ADD COLUMN IF NOT EXISTS incident_report TEXT;

ALTER TABLE tournament_matches
    ADD COLUMN IF NOT EXISTS match_card_photo_url VARCHAR(500);
