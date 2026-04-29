-- Migration: 022_tournament_faq.sql
-- Description: Add tournaments.faq_markdown — admin-editable markdown that
--              renders on the public tournament page as an "Info" tab.
--              Cup directors get the same parking/check-in/weather/food
--              questions every event; centralizing answers self-serves parents
--              and reduces help-desk volume.
-- Created: 2026-04-29
-- Phase: 2A — demo-readiness (#4 public mobile polish, FAQ piece)
--
-- SAFETY: additive nullable column. No data changes. Idempotent via IF NOT EXISTS.

ALTER TABLE tournaments
    ADD COLUMN IF NOT EXISTS faq_markdown TEXT;
