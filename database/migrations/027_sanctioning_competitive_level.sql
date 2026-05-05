-- Migration: 027_sanctioning_competitive_level.sql
-- Description: Two related additions that close out the Module 1 division
--              config user story:
--
--              1. Sanctioning & affiliation on tournaments — governing body
--                 (USYS / US Club / AYSO / AAU / state assoc / unaffiliated),
--                 sanction number, and optional state association. Cup orgs
--                 look for these as a trust signal on the public microsite.
--                 They also feed the per-body age-eligibility rules engine
--                 (added in a subsequent slice).
--
--              2. Competitive level on tournament_divisions — Premier / Gold /
--                 Silver / Bronze / Recreational / Custom. Free-form so a
--                 director can use any league's level naming.
--
-- Created: 2026-05-05
-- Phase: Module 1 polish — finishes Division & Age Group Config user story
--
-- SAFETY: additive nullable columns on existing tables. No data changes.
-- Idempotent.

ALTER TABLE tournaments
    ADD COLUMN IF NOT EXISTS governing_body VARCHAR(32);

ALTER TABLE tournaments
    ADD COLUMN IF NOT EXISTS sanction_number VARCHAR(100);

ALTER TABLE tournaments
    ADD COLUMN IF NOT EXISTS state_association VARCHAR(100);

ALTER TABLE tournament_divisions
    ADD COLUMN IF NOT EXISTS competitive_level VARCHAR(32);
