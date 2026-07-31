'use strict';

/**
 * Flag-gated admin read.
 *
 * A club admin may open a conversation they are not part of ONLY because an open
 * report exists on it. There is no browse-any-conversation surface: the admin's
 * entry point is the review queue, and the report is the authorisation.
 *
 * ─── Reading is granted, sending is not ───────────────────────────────────────
 * This is deliberately NOT folded into `isConversationParticipant`. That
 * predicate also gates `sendMessage`, so widening it would let an admin post
 * into a DM between two other people — reaching in to read a reported thread and
 * being able to talk in it are different powers, and only the first was asked
 * for. `isConversationParticipant` stays strict; this runs alongside it on the
 * read path only.
 *
 * ─── Every such open is logged ────────────────────────────────────────────────
 * Once admins can read reported conversations, READING becomes the sensitive
 * action rather than removing. Under the "no expectation of privacy" stance the
 * log is not a privacy control — it is how the club shows it exercised oversight,
 * and how an admin shows they opened a thread because of a report rather than out
 * of curiosity.
 */

/**
 * The open report that would authorise reading this conversation, if any.
 * Oldest first: if several are open, the one that has been waiting is the one
 * the admin is answering.
 */
const OPEN_REPORT_FOR_CONVERSATION_SQL = `
  SELECT r.id, r.club_id AS "clubId"
  FROM chat_message_reports r
  WHERE r.conversation_id = $1 AND r.status = 'open'
  ORDER BY r.created_at ASC
  LIMIT 1
`;

/**
 * Record the open. ($1 user, $2 conversation, $3 report, $4 club)
 *
 * report_id is NOT NULL by design — an entry here without the report that
 * justified it would be an unexplained read, which is the thing this table
 * exists to make impossible.
 */
const LOG_ACCESS_SQL = `
  INSERT INTO chat_access_log (user_id, conversation_id, report_id, club_id)
  VALUES ($1, $2, $3, $4)
`;

/**
 * May this moderator open this conversation on the strength of that report?
 *
 * Platform roles cross clubs for support; a club admin is confined to their own.
 * Pure so the decision is testable without a database.
 */
function moderatorMayOpen({ role, actorClubId, reportClubId, isPlatform }) {
  if (!role) return false;
  if (isPlatform) return true;
  if (actorClubId === null || actorClubId === undefined) return false;
  if (reportClubId === null || reportClubId === undefined) return false;
  return Number(actorClubId) === Number(reportClubId);
}

module.exports = {
  OPEN_REPORT_FOR_CONVERSATION_SQL,
  LOG_ACCESS_SQL,
  moderatorMayOpen,
};
