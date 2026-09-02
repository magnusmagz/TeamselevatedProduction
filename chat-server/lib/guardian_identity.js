'use strict';

/**
 * Which guardian rows belong to an account — the ONE answer, for this app.
 *
 * ─── Why this file exists ─────────────────────────────────────────────────────
 * Parent standing was derived by string-comparing `users.email` against
 * `guardians.email`: two independently-editable columns in two different tables.
 * Every failure it produced looked like a product statement rather than an error
 * — an empty chat list, "no athletes are registered to you" — so each one
 * survived until a family spoke up. Emily Govier's one capital letter. Allix
 * Boyce's @yahoo account against her @gmail guardian row.
 *
 * Migration 072 records the relationship as a row (`user_guardians`). The rule is
 * now: recorded links UNION the old email match, which is STRICTLY WIDER than
 * what it replaces. Nobody reachable today stops being reachable; families whose
 * two addresses have drifted apart start being reachable. Phase 2 cannot cost
 * anyone access, and that is the whole reason it is shaped as a union.
 *
 * ─── This is a PORT, not a second definition ──────────────────────────────────
 * `lib/guardian_identity.php` is the original; `te_guardian_link_sql()` is the
 * function this mirrors. The chat server is a separate Heroku app deployed by
 * `git subtree split --prefix=chat-server`, so it cannot require the PHP — the
 * same reason `COACH_TEAM_IDS_SQL` in team_scope.js is a port of
 * `lib/coach_scope.php`. Two implementations of one rule is the known cost. The
 * two must be changed together, and `lib/chat_notification_scope.php` — which
 * decides who gets EMAILED about a chat — is a third mirror of the same rule. If
 * these three disagree, a parent is mailed a link to a 403.
 *
 * ⚠️ Do NOT narrow this to `user_guardians` alone before phase 3 writes links at
 * their source (invite accept, registration, admin connect tool). 194 guardian
 * emails have no account yet; dropping the fallback first would land every
 * newly-accepted family in an empty chat — the exact bug this project exists to
 * end, reintroduced by its own rollout.
 *
 * ⚠️ `user_guardians.guardian_id` is a `guardians(id)`, like
 * `athlete_guardians.guardian_id`. `consent_records.guardian_id` is a `users(id)`
 * — the outlier. Never join those two because they share a name.
 *
 * ─── Two things in the predicate that are load-bearing ────────────────────────
 * **LOWER() on BOTH sides.** Postgres `=` is case-sensitive. This app was missed
 * when the PHP side was fixed on 2026-08-18 and stayed broken two extra days.
 * Stored emails are deliberately NOT normalised — the comparison was wrong, so
 * the comparison changed. Guarded by __tests__/guardian_email_case.test.js.
 *
 * **The email branch is guarded on the USER's address being non-blank.**
 * `guardians.email` is NOT NULL and 24 production rows hold `''`. In SQL `'' = ''`
 * is true, so an account with no address would otherwise collapse into every one
 * of those 24 unrelated families at once. The guard cannot narrow anything for a
 * real account: `users.email` is UNIQUE and a login requires it.
 */

/** Aliases are interpolated, so they must be identifiers and never caller input. */
const SAFE_ALIAS = /^[A-Za-z_][A-Za-z0-9_]*$/;

/**
 * SQL predicate linking a `users` row to a `guardians` row, for queries that
 * already have both in scope. No placeholders — safe to embed at any position in
 * a query without disturbing its `$n` numbering.
 *
 * Returned as a fragment rather than a list of ids so a call site that was one
 * statement stays one statement: turning a join into "fetch ids, then query"
 * changes row multiplication and locking behaviour for no benefit.
 *
 * @param {string} userAlias     alias of the `users` table in the caller's query
 * @param {string} guardianAlias alias of the `guardians` table in the caller's query
 * @returns {string}
 */
function guardianLinkSql(userAlias = 'u', guardianAlias = 'g') {
  for (const alias of [userAlias, guardianAlias]) {
    if (!SAFE_ALIAS.test(alias)) {
      throw new Error(`guardianLinkSql: unsafe table alias ${JSON.stringify(alias)}`);
    }
  }

  const u = userAlias;
  const g = guardianAlias;

  return (
    `(EXISTS (SELECT 1 FROM user_guardians ug` +
    ` WHERE ug.user_id = ${u}.id AND ug.guardian_id = ${g}.id)` +
    ` OR (TRIM(${u}.email) <> '' AND LOWER(${g}.email) = LOWER(${u}.email)))`
  );
}

/** The predicate at the aliases every call site in this app actually uses. */
const GUARDIAN_LINK_SQL = guardianLinkSql('u', 'g');

/**
 * The whole join clause, so a call site substitutes one line rather than
 * reassembling `JOIN guardians g ON …` around the predicate itself.
 *
 * Every guardian-identity site in this app uses THIS constant. The rule appears
 * verbatim in exactly one file, which is what
 * __tests__/guardian_identity.test.js scans for.
 */
const GUARDIAN_JOIN_SQL = `JOIN guardians g ON ${GUARDIAN_LINK_SQL}`;

module.exports = {
  guardianLinkSql,
  GUARDIAN_LINK_SQL,
  GUARDIAN_JOIN_SQL,
};
