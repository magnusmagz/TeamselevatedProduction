-- 097_users_password_set_by_admin.sql
--
-- When a club admin sets a temporary password for a coach (api/coach-access.php
-- ?action=set-temporary-password), record WHEN. The staff dashboard shows a
-- dismissible one-line banner to a user whose password was admin-set and who
-- has not changed it since; the profile's own password-change path clears it.
--
-- This is a nudge, not a forced-change flag. The product decision (2026-09-06)
-- is that an admin-set password is accepted as-is and the auth gateway is not
-- involved — nothing reads this column to allow or refuse anything.
--
-- ADDITIVE ONLY. One nullable column on `users`; nothing existing is altered.
-- Code probes information_schema for it and degrades to "no banner, nothing
-- written" until this is applied (lib/coach_access.php).
--
-- REVERSE:
--   ALTER TABLE users DROP COLUMN IF EXISTS password_set_by_admin_at;
--   (Safe at any time. The banner simply stops appearing.)

ALTER TABLE users ADD COLUMN IF NOT EXISTS password_set_by_admin_at TIMESTAMP NULL;

COMMENT ON COLUMN users.password_set_by_admin_at IS
    'Set when a club admin assigned a temporary password (audit: password_set_by_admin); cleared when the user changes it themselves.';
