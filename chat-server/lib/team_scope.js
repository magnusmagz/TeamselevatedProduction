'use strict';

/**
 * Which teams a user may see, join, and message in.
 *
 * ─── Why this exists ──────────────────────────────────────────────────────────
 * `getAccessibleTeamIds` used to union in EVERY team in the club whenever
 * `canInitiateConversation(role)` was true — and that list contains `coach`. So
 * every coach in a club got every team's conversation in their list, could join
 * and read any of them, and could enumerate any team's members. Reported on
 * Central Kansas United (club 51), where 14 coaches each saw all 16 teams.
 *
 * ─── The trap: the JWT has no team scope ──────────────────────────────────────
 * The obvious fix is "drop the club-wide branch for coaches and use their real
 * teams". But the old `getCoachTeamIds(payload)` filtered `payload.roles` for
 * `scope_type === 'team'`, and **our tokens never mint one** —
 * `JWT::buildOrganizationalContext()` in the PHP backend emits every role as
 * `scope_type: 'club'`. That function therefore returned `[]` for every user who
 * ever used chat. The club-wide branch was not supplementing a coach's team
 * list; it WAS the list. Removing it alone would have left every coach with
 * nothing.
 *
 * So the coach's real teams have to be read from the database, which is what
 * `COACH_TEAM_IDS_SQL` is for. Nothing here may go back to trusting the token
 * for team scope until the token actually carries it.
 *
 * ─── Deliberately NOT club-filtered ───────────────────────────────────────────
 * `lib/coach_scope.php` takes a club id because those gateways act inside one
 * club's context. This does not: the question here is only "is this your team",
 * and a coach who coaches in two clubs should see both teams' chats regardless
 * of which club their active context happens to point at. Adding a club filter
 * would silently drop a conversation when they switch context.
 *
 * ─── No `deleted_at` filter, on purpose ───────────────────────────────────────
 * Matches `lib/coach_scope.php`, which documents the same choice, and matches
 * the club-wide query this replaces (`SELECT id FROM teams WHERE club_id = $1`).
 * A soft-deleted team still resolves, so archived history stays reachable to the
 * people who were in it. Tightening it is a separate change with its own
 * blast radius.
 */

/**
 * Roles that legitimately see every team in their club.
 *
 * `coach` and `parent` are absent, and that absence is the entire fix — both are
 * scoped to specific teams and get them from the queries below. `parent` was
 * never actually reached (the caller returns early for parents), but leaving it
 * in this list would arm the bug again the moment that early return moved.
 *
 * Pure, so the decision is testable without a database.
 */
function expandsToWholeClub(role) {
  return ['super_admin', 'owner', 'club_admin', 'admin'].includes(role);
}

/**
 * Teams this user actually coaches. ($1 = user id)
 *
 * Mirrors `getCoachTeamIds()` in `lib/coach_scope.php` — primary coach of the
 * team, or an active assistant_coach / team_manager membership. CLAUDE.md is
 * explicit that coach team scoping lives in that function "and nowhere else";
 * this is a port for a separate Node service, not a second definition, and the
 * two must be changed together.
 */
const COACH_TEAM_IDS_SQL = `
  SELECT DISTINCT t.id
  FROM teams t
  LEFT JOIN team_members tm
    ON tm.team_id = t.id
   AND tm.user_id = $1
   AND tm.role IN ('assistant_coach', 'team_manager')
   AND tm.status = 'active'
  WHERE t.primary_coach_id = $1 OR tm.id IS NOT NULL
`;

/**
 * Teams this user reaches as a guardian. ($1 = user id)
 *
 * Identical to the parent lookup the caller already used. It is applied to
 * COACHES TOO, which is the point: `getUserRole()` collapses a user to a single
 * role and prefers `coach` over `parent`, so a coach who is also a parent never
 * took the parent branch. Six CKU coaches are in exactly that position, and
 * scoping them to coached teams alone would have taken away their own child's
 * team chat — a regression disguised as a security fix.
 *
 * Joins guardians on email, which is the identity-by-email workaround recorded
 * in CLAUDE.md. It is what the rest of the product does today; the fix is the
 * `user_guardians` link table on the backlog, not a different join here.
 *
 * LOWER() on BOTH sides is load-bearing. Postgres `=` is case-sensitive, and the
 * two columns are independently editable, so one capital letter severs a family:
 * Emily Govier's guardian row read `Emilygovier0@gmail.com` against a login of
 * `emilygovier0@gmail.com` and her portal was empty. The PHP side was fixed on
 * 2026-08-18 (migration 071); this app was missed and stayed broken for two more
 * days. Four accounts are in that state today, three with children on teams.
 * Stored emails are deliberately NOT normalised — the comparison was wrong, so
 * the comparison changed. Guarded by __tests__/guardian_email_case.test.js.
 */
const GUARDIAN_TEAM_IDS_SQL = `
  SELECT DISTINCT tm.team_id AS id
  FROM users u
  JOIN guardians g ON LOWER(g.email) = LOWER(u.email)
  JOIN athlete_guardians ag ON ag.guardian_id = g.id
  JOIN athletes a ON a.id = ag.athlete_id
  JOIN team_members tm ON tm.athlete_id = a.id
  WHERE u.id = $1
`;

/** Every team in a club. ($1 = club id) — admins only, see expandsToWholeClub. */
const CLUB_TEAM_IDS_SQL = `SELECT id FROM teams WHERE club_id = $1`;

/**
 * Merge id lists into one de-duplicated array of team ids.
 *
 * Coerced to Number because pg returns integers, JWTs carry strings, and a Set
 * of mixed types silently keeps duplicates — the same trap `disallowedParticipants`
 * documents in participants.js.
 *
 * The test is `Number.isInteger(n) && n > 0`, not `Number.isFinite(n)`. Finite
 * lets `null` through as **0** (`Number(null) === 0`), so a NULL team_id from a
 * join would enter the accessible-ids list as team 0 — a value that matches
 * nothing today, but a made-up id inside a permission set is not something to
 * leave to luck. Team ids are positive serials; anything else is a bug upstream
 * and is dropped here rather than propagated.
 */
function mergeTeamIds(...lists) {
  const out = new Set();
  for (const list of lists) {
    for (const id of list || []) {
      const n = Number(id);
      if (Number.isInteger(n) && n > 0) out.add(n);
    }
  }
  return [...out];
}

module.exports = {
  expandsToWholeClub,
  COACH_TEAM_IDS_SQL,
  GUARDIAN_TEAM_IDS_SQL,
  CLUB_TEAM_IDS_SQL,
  mergeTeamIds,
};
