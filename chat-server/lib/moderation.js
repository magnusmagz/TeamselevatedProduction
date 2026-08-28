'use strict';

/**
 * Admin moderation removal.
 *
 * The only way a chat message is ever removed. There is no user-facing delete and
 * no sender unsend — a coach cannot scrub their own words, which is the whole
 * point. Removal is a SOFT delete: the row survives with `deleted_at` set,
 * participants see a tombstone instead of a gap, and the text stays readable in
 * the database for the 90-day reversal window before `chat_messages_removed` in
 * lib/retention_plans.php hard-deletes it.
 *
 * Not time-boxed, deliberately. Messenger's 10-minute unsend window exists for
 * sender remorse; moderation acts on a complaint that arrives hours or days later.
 */

/** Shown in place of removed text. Never attribute it to the sender — they did not do it. */
const TOMBSTONE_TEXT = 'Message removed by an administrator';

/** Who may remove. Explicitly NOT coach, and explicitly not the sender. */
const MODERATOR_ROLES = ['super_admin', 'owner', 'club_admin', 'admin'];

function canModerate(role) {
  return MODERATOR_ROLES.includes(role);
}

/** Platform roles reach any club; a club admin is confined to their own. */
function isPlatformRole(role) {
  return role === 'super_admin' || role === 'owner';
}

/**
 * Message history, INCLUDING removed messages as tombstones.
 *
 * This is the one read path that must not filter `deleted_at IS NULL`. A removed
 * message that simply vanishes leaves participants unsure whether they imagined
 * it; the tombstone is what makes moderation visible rather than furtive.
 *
 * `message_text` is nulled in SQL rather than in JS — the removed text must never
 * reach a client, and doing it in the query means no later refactor can
 * accidentally serialise it.
 *
 * Every OTHER read path keeps excluding removed messages: unread counts and
 * last-message previews should not be driven by a tombstone.
 */
function buildMessageHistoryQuery({ team = false } = {}) {
  const columns = `
      id,
      CASE WHEN deleted_at IS NULL THEN message_text ELSE NULL END AS text,
      (deleted_at IS NOT NULL) AS removed,
      sender_name AS sender, sender_id AS "senderId", sender_role AS role,
      message_type AS "messageType",
      created_at AS timestamp,
      TO_CHAR(created_at, 'HH24:MI') AS time`;

  if (team) {
    return `
      SELECT ${columns}, $1::int AS "conversationId"
      FROM chat_messages
      WHERE conversation_id = $1
      UNION ALL
      SELECT ${columns}, $1::int AS "conversationId"
      FROM chat_messages
      WHERE scope_type = 'team' AND scope_id = $2 AND conversation_id IS NULL
      ORDER BY timestamp DESC
      LIMIT $3
    `;
  }

  return `
    SELECT ${columns}, conversation_id AS "conversationId"
    FROM chat_messages
    WHERE conversation_id = $1
    ORDER BY created_at DESC
    LIMIT $2
  `;
}

/**
 * Resolve a message to its conversation and club, so a club admin cannot reach
 * into another club's conversation. Also returns whether it is already removed —
 * re-removing must not overwrite the original actor or timestamp.
 */
const MESSAGE_SCOPE_SQL = `
  SELECT m.id, m.conversation_id AS "conversationId", m.sender_id AS "senderId",
         m.deleted_at AS "deletedAt", c.club_id AS "clubId"
  FROM chat_messages m
  LEFT JOIN conversations c ON c.id = m.conversation_id
  WHERE m.id = $1
`;

/**
 * Soft-delete. `AND deleted_at IS NULL` makes it idempotent: a second removal
 * affects zero rows rather than rewriting who removed it and when.
 *   $1 message id, $2 actor user id, $3 reason
 */
const REMOVE_MESSAGE_SQL = `
  UPDATE chat_messages
  SET deleted_at = NOW(), deleted_by = $2, removal_reason = $3
  WHERE id = $1 AND deleted_at IS NULL
  RETURNING id, conversation_id AS "conversationId", sender_id AS "senderId"
`;

module.exports = {
  TOMBSTONE_TEXT,
  MODERATOR_ROLES,
  canModerate,
  isPlatformRole,
  buildMessageHistoryQuery,
  MESSAGE_SCOPE_SQL,
  REMOVE_MESSAGE_SQL,
};
