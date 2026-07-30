-- 056: Clear seeded/default passwords off auto-created player accounts.
--
-- WHY
-- `te_create_athlete()` (lib/athlete_writes.php) used to create the athlete's linked
-- users row with `password_hash('defaultpass')` — a constant literal in the source.
-- Two consequences, both live in production until 2026-07-30:
--
--   1. SECURITY. Every athlete created with an email address got a real, loginable
--      account. `handlePasswordLogin` in api/auth-gateway.php does a plain
--      password_verify with no additional gate, so anyone who knew an email in the
--      club could sign in as that user. Because the email on a youth athlete's form
--      is the PARENT's, 14 of these credentials sat on real adults' addresses in
--      Central Kansas United (club 51) alone. A further 12 seeded @student.com demo
--      accounts used the password 'password'. 31 accounts total, verified by running
--      password_verify against the live hashes.
--
--   2. CORRECTNESS. The crew / parent-portal status endpoints (handleClubParents and
--      handleParentPortalStatus) define "active" as "a users row for this email has a
--      password_hash", joined on email alone. The child's auto-created account
--      therefore made the parent display as having accepted a portal invite that was
--      never sent — which is how this was found.
--
-- WHAT
-- Setting password_hash to NULL does not delete or orphan anything: athletes.user_id
-- still points at the row, and the account falls back to the magic-link path it was
-- always meant to use (every affected row already carries auth_provider='magic_link').
-- handlePasswordLogin returns "No password set for this account — please use magic
-- link to login, or set a password via password reset", which is the correct state for
-- an account no human ever chose a password for.
--
-- The `last_login_at IS NULL` guard is what makes this safe to run anywhere: it cannot
-- touch a player who set a real password and actually used it. All 31 affected rows in
-- prod had last_login_at NULL — nobody has ever signed in with one of these.
--
-- The generating bug is fixed in lib/athlete_writes.php (the INSERT no longer writes
-- password_hash at all), so this backfill is one-time, not recurring.

UPDATE users
   SET password_hash = NULL
 WHERE role = 'player'
   AND password_hash IS NOT NULL
   AND password_hash <> ''
   AND last_login_at IS NULL;
