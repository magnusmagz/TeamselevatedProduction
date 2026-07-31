'use strict';

/**
 * Who a user is permitted to start a conversation with.
 *
 * ─── Why this exists ──────────────────────────────────────────────────────────
 * `createConversation` used to take `participantIds` straight from the client,
 * resolve the names out of `users`, and insert them. No club check, no team
 * check, no role check — and `canInitiateConversation` includes `parent`. Any
 * authenticated initiator could therefore open a DM with ANY user id in the
 * system, in any club. Never exploited (no athlete has ever been a conversation
 * participant) but reachable: conversation 18 in prod is a cross-club DM.
 *
 * Same shape as the athlete/guardian gateway bug already recorded in CLAUDE.md —
 * bound what the endpoint ACCEPTS, not what the form happens to send.
 *
 * ─── Product rule ─────────────────────────────────────────────────────────────
 * Coaches cannot DM athletes. DMs are between coaches and the crew (guardians).
 *
 * ─── Why this is an ALLOWLIST and must never become a blocklist ───────────────
 * The tempting implementation is "reject any user id found in athletes.user_id".
 * That would be wrong and would break the feature. Verified against live Neon
 * 2026-07-30:
 *
 *     athletes with user_id ............................... 26
 *     …whose account email is a GUARDIAN's ............... 23
 *     …whose account holds a STAFF role .................. 10  (6 coach, 4 club_admin)
 *     users holding the 'player' role ..................... 0
 *
 * `athletes.user_id` is not a "this account is the child" signal — it mostly
 * points at the parent. Blocklisting it would refuse DMs to 23 guardians and 10
 * coaches, which is exactly the coach↔crew conversation chat exists for.
 *
 * So the set is built from guardians and staff. Athletes are excluded by never
 * being IN it, which stays correct however mis-linked `athletes.user_id` gets.
 */

/**
 * Every user the creator may include as a participant.
 *   $1  int[]  team ids the creator can access
 *   $2  int    the creator's club id (may be NULL — that branch then yields nothing)
 *
 * Deliberately a little WIDER than `getTeamMembersForPicker`, which finds coaches
 * by "has previously participated or sent a message in this team's conversations".
 * That definition cannot see a newly-added coach who has not spoken yet, and a
 * security boundary that is narrower than the UI would block legitimate first
 * conversations. The picker is UX; this is the boundary.
 */
const ALLOWED_PARTICIPANTS_SQL = `
  -- Guardians of athletes on teams the creator can access
  SELECT DISTINCT u.id AS user_id
  FROM users u
  JOIN guardians g ON g.email = u.email
  JOIN athlete_guardians ag ON ag.guardian_id = g.id
  JOIN athletes a ON a.id = ag.athlete_id
  JOIN team_members tm ON tm.athlete_id = a.id
  WHERE tm.team_id = ANY($1::int[])

  UNION

  -- Staff of the creator's own club
  SELECT DISTINCT uca.user_id
  FROM user_club_access uca
  WHERE uca.club_profile_id = $2
    AND uca.active
    AND uca.role IN ('club_admin', 'coach')
`;

/**
 * Requested ids that are not in the allowed set.
 *
 * Both sides are coerced to Number: ids arrive from client JSON (possibly as
 * strings) and from pg (as numbers), and a Set of mixed types silently lets
 * everything through — which would defeat the whole check.
 *
 * The creator is always permitted to be in their own conversation.
 */
function disallowedParticipants(requestedIds, allowedIds, creatorId = null) {
  const allowed = new Set(
    (allowedIds instanceof Set ? [...allowedIds] : allowedIds || []).map(Number)
  );
  if (creatorId !== null && creatorId !== undefined) allowed.add(Number(creatorId));

  const requested = [...new Set((requestedIds || []).map(Number))];
  return requested.filter(id => !Number.isFinite(id) || !allowed.has(id));
}

module.exports = {
  ALLOWED_PARTICIPANTS_SQL,
  disallowedParticipants,
};
