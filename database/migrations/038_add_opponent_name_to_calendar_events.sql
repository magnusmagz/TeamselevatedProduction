-- Migration 038: Free-text opponent name on calendar events
--
-- Problem: A coach/admin creating a calendar event of type `game` has no place
-- to record who the opponent is. The event form collects name/type/date/times/
-- teams/facility/location/description but nothing for the opposing team, so the
-- opponent only ever lived informally inside the free-text location/description.
--
-- This migration adds a nullable free-text opponent_name column so the events
-- gateway can persist it on the calendar_events row and the calendar UI can show
-- it back (e.g. "vs Springfield FC") for game-type events.
--
-- Additive only (ADD COLUMN IF NOT EXISTS, nullable, no default) — safe for the
-- demo-data prod DB. Non-game events simply leave it NULL.

ALTER TABLE calendar_events ADD COLUMN IF NOT EXISTS opponent_name VARCHAR(255);
