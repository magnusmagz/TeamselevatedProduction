-- 053_users_tos_acceptance.sql
--
-- Record Terms of Service acceptance at signup.
--
-- COPPA-COMPLIANCE.md describes SignUp.tsx's ToS checkbox as "an active checkbox
-- [that] blocks submission until accepted". It does — in the browser. The
-- frontend has always POSTed `tos_accepted`, and no backend file has ever
-- referenced it, so the acceptance was discarded and there is no record that any
-- user agreed to anything.
--
-- consent_records cannot hold this: its athlete_id is NOT NULL and signup has no
-- athlete. Acceptance of the Terms is a property of the account, so it lives on
-- users.
--
-- Existing rows keep NULL — we genuinely do not know whether they accepted, and
-- back-dating a legal acceptance would be worse than admitting the gap.
--
-- Safe to re-run.

ALTER TABLE users ADD COLUMN IF NOT EXISTS tos_accepted_at TIMESTAMP;
ALTER TABLE users ADD COLUMN IF NOT EXISTS tos_version     VARCHAR(20);

COMMENT ON COLUMN users.tos_accepted_at IS 'When the user accepted the Terms of Service. NULL means unknown/never recorded (predates 2026-07-29).';
COMMENT ON COLUMN users.tos_version     IS 'Terms version accepted, so a future revision can require re-acceptance.';
