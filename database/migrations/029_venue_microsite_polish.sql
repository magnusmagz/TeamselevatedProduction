-- Migration: 029_venue_microsite_polish.sql
-- Description: Bundled additions for the Module 11 facility/field close-out
--              (slices 1 + 2 + 3 of the venue polish plan).
--
--              1. Gate code on venues — common at gated complexes; surfaces on
--                 the public microsite so visiting teams can get in.
--              2. Latitude / longitude on venues — proper map pin instead of
--                 the address-string fallback (which mis-routes on ambiguous
--                 names like "Soccer Park, [city]").
--              3. Medical coordinator + medical station notes on venues —
--                 so cup orgs and parents know who's on-site for medical
--                 issues and where the medical tent / first-aid station sits.
--
-- Created: 2026-05-05
-- Phase: Module 11 polish (slices 1–3)
--
-- SAFETY: additive nullable columns only. No data changes. Idempotent.

ALTER TABLE venues
    ADD COLUMN IF NOT EXISTS gate_code VARCHAR(50);

ALTER TABLE venues
    ADD COLUMN IF NOT EXISTS latitude  NUMERIC(10, 7);

ALTER TABLE venues
    ADD COLUMN IF NOT EXISTS longitude NUMERIC(10, 7);

ALTER TABLE venues
    ADD COLUMN IF NOT EXISTS medical_coordinator_name  VARCHAR(200);

ALTER TABLE venues
    ADD COLUMN IF NOT EXISTS medical_coordinator_phone VARCHAR(50);

ALTER TABLE venues
    ADD COLUMN IF NOT EXISTS medical_coordinator_email VARCHAR(200);

ALTER TABLE venues
    ADD COLUMN IF NOT EXISTS medical_station_notes TEXT;
