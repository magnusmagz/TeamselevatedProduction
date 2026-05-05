-- Migration: 031_team_branding_colors.sql
-- Description: Add 3-color team branding (primary / secondary / accent) so the
--              BrandingTab UI on the team form actually has columns to land
--              in. Today the form submits primary_color / secondary_color /
--              accent_color but the schema only has a single team_color, so
--              every save was destroying the previously-stored branding.
--
--              The legacy team_color column stays in place as a backwards-
--              compatible mirror of primary_color (anything else still
--              reading team_color keeps working).
-- Created: 2026-05-05
-- Phase: Module — team branding bug fix
--
-- SAFETY: additive nullable columns. No data changes. Idempotent.

ALTER TABLE teams
    ADD COLUMN IF NOT EXISTS primary_color   VARCHAR(20);

ALTER TABLE teams
    ADD COLUMN IF NOT EXISTS secondary_color VARCHAR(20);

ALTER TABLE teams
    ADD COLUMN IF NOT EXISTS accent_color    VARCHAR(20);

-- Backfill: copy team_color into primary_color so existing teams don't
-- look colorless after the form loads them. Idempotent — only fills NULL.
UPDATE teams
SET primary_color = team_color
WHERE primary_color IS NULL
  AND team_color IS NOT NULL
  AND team_color <> '';
