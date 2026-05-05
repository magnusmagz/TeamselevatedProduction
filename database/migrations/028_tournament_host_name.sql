-- Migration: 028_tournament_host_name.sql
-- Description: Add a free-text host_name to tournaments. Distinct from the
--              platform-internal club_id (the owner): this is the
--              public-facing "Hosted by" display string. Useful when a
--              tournament's host org name differs from the platform club's
--              record (sanctioning bodies, multi-club partnerships,
--              tournament-specific branding).
-- Created: 2026-05-05
-- Phase: Module 1 polish
--
-- SAFETY: additive nullable column. No data changes. Idempotent.

ALTER TABLE tournaments
    ADD COLUMN IF NOT EXISTS host_name VARCHAR(200);
