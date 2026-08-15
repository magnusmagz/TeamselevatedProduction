-- 067: Athletes have no login identity.
--
-- Decided 2026-08-15. te_create_athlete() used to find-or-create a `player` users
-- row from the email on the athlete form — and for a youth athlete that email is
-- the PARENT's. Because users.email is unique, the child's row then OWNED that
-- address and the parent had no account of their own, so signing in with a magic
-- link logged the parent in AS THEIR CHILD, into a row with no club roles, which
-- routes to the staff app. That is the "parents are seeing the coach's portal"
-- report from Central Kansas United.
--
-- The code no longer creates these rows (lib/athlete_writes.php). This clears the
-- ones already in production.
--
-- THREE GROUPS, and the distinction is load-bearing — "linked from
-- athletes.user_id" does NOT mean "is an athlete's account":
--
--   A. 21 genuine athlete accounts — role='player', no club roles, no password,
--      never signed in, and ZERO inbound references across all 67 FKs to users.id.
--      Deleted.
--
--   B. 2 accounts belonging to REAL PEOPLE that were merely mis-linked:
--        #69  Elias Ulvi  — role=parent, 2 club roles, primary coach of 2 teams,
--                           referenced by 15 tables (audit_log, communication_log,
--                           email_templates, tournaments, …)
--        #305 Vance Johnson — role=coach, 1 club role, primary coach of a team
--      These are UNLINKED ONLY. Deleting them would take a coach's identity and
--      years of audit trail with it. This is exactly the mis-linking CLAUDE.md
--      already records: athletes.user_id mostly points at a guardian, never at
--      the child.
--
--   C. #309 "Bonnie Ziegler" — the account Jess Ziegler actually signed into on
--      2026-08-14. Unlinked here; its disposition is handled separately because
--      it carries consent_records. Not deleted by this migration.
--
-- Reversible: _backup_athlete_accounts_2026_08_15 holds every deleted users row
-- plus every (athlete_id, user_id) link that was cleared.

BEGIN;

CREATE TABLE IF NOT EXISTS _backup_athlete_accounts_2026_08_15 AS
SELECT u.*, a.id AS linked_athlete_id, now() AS backed_up_at
FROM users u
JOIN athletes a ON a.user_id = u.id;

-- A + B + C: the link itself is wrong in every case. An athlete has no account.
UPDATE athletes SET user_id = NULL WHERE user_id IS NOT NULL;

-- A only. Every predicate here is a safety rail, not a filter for convenience:
-- role, no club standing, no credential, never signed in.
DELETE FROM users u
WHERE u.role = 'player'
  AND u.id <> 309
  AND (u.password_hash IS NULL OR u.password_hash = '')
  AND u.last_login_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM user_club_access c WHERE c.user_id = u.id)
  AND EXISTS (SELECT 1 FROM _backup_athlete_accounts_2026_08_15 b WHERE b.id = u.id);

COMMIT;

-- Second pass, same day: 12 further role='player' accounts that were NOT linked
-- from athletes.user_id at all — the @student.com seed set from 2026-02-17. No
-- password, never signed in, zero inbound references. Backed up to
-- _backup_player_accounts_unlinked_2026_08_15.
--
-- Afterwards exactly ONE role='player' row remains: #309, held back deliberately.

DELETE FROM users u
WHERE u.role = 'player'
  AND u.id <> 309
  AND (u.password_hash IS NULL OR u.password_hash = '')
  AND u.last_login_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM user_club_access c WHERE c.user_id = u.id);
