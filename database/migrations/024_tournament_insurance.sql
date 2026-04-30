-- Migration: 024_tournament_insurance.sql
-- Description: Add insurance certificate tracking fields to tournaments.
--              Cup orgs affiliated with governing bodies (USYS, US Club, AYSO,
--              state associations) must show valid insurance before play.
--              These fields let the director attach a cert PDF, record the
--              expiry date and policy number/provider, and surface "expires
--              soon" or "expired" warnings on the tournament Overview.
-- Created: 2026-04-30
-- Phase: Module 1 polish (insurance certificate management)
--
-- SAFETY: additive nullable columns. No data changes. Idempotent.

ALTER TABLE tournaments
    ADD COLUMN IF NOT EXISTS insurance_certificate_url VARCHAR(500);

ALTER TABLE tournaments
    ADD COLUMN IF NOT EXISTS insurance_certificate_filename VARCHAR(255);

ALTER TABLE tournaments
    ADD COLUMN IF NOT EXISTS insurance_expiry_date DATE;

ALTER TABLE tournaments
    ADD COLUMN IF NOT EXISTS insurance_policy_number VARCHAR(100);

ALTER TABLE tournaments
    ADD COLUMN IF NOT EXISTS insurance_provider VARCHAR(200);
