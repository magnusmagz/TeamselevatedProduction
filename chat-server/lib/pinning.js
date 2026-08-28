'use strict';

/**
 * Pinning a message to the top of a conversation.
 *
 * ONE PIN PER CONVERSATION. Pinning a second replaces the first — enforced by a
 * partial unique index in migration 081, not by this code. Several pins become a
 * second inbox nobody prunes, and a stale pin is far more visible when there is
 * only ever one: "the pinned message" is either current or obviously wrong,
 * where "one of four" is neither.
 */

/**
 * Who may pin: coaches and club admins.
 *
 * ⚠️ Its OWN predicate, and that is the point. `canModerate()` is
 * ['super_admin','owner','club_admin','admin'] and **excludes coaches** — using
 * it here would mean a coach could not pin their own team's practice details,
 * which is the main thing pinning is for. Same list as canCreatePoll today, and
 * deliberately not the same FUNCTION: they answer different questions and will
 * drift apart the first time one of them changes.
 */
const PINNER_ROLES = ['super_admin', 'owner', 'club_admin', 'admin', 'coach'];

function canPinMessage(role) {
  return PINNER_ROLES.includes(role);
}

/**
 * Clear any existing pin in a conversation.
 *
 * Run before setting a new one. Without it the partial unique index rejects the
 * second pin, which would surface as "pinning is broken" rather than as
 * "replacing the pin", and the index exists to make the invariant true rather
 * than to be tripped over.
 */
const UNPIN_CONVERSATION_SQL = `
  UPDATE chat_messages
     SET pinned_at = NULL, pinned_by = NULL
   WHERE conversation_id = $1 AND pinned_at IS NOT NULL
`;

const PIN_MESSAGE_SQL = `
  UPDATE chat_messages
     SET pinned_at = NOW(), pinned_by = $2
   WHERE id = $1
     AND deleted_at IS NULL
  RETURNING id, conversation_id, message_text, sender_name, pinned_at
`;

const UNPIN_MESSAGE_SQL = `
  UPDATE chat_messages
     SET pinned_at = NULL, pinned_by = NULL
   WHERE id = $1
  RETURNING id, conversation_id
`;

/**
 * The pinned message in a conversation, if there is one.
 *
 * Excludes a removed message: moderation nulls the text and leaves a tombstone,
 * and a banner reading "Message removed by an administrator" pinned to the top
 * of a conversation is worse than no banner. The pin is not cleared in the
 * database — if the removal is ever reversed the pin is still what someone
 * intended — it simply does not render.
 */
const PINNED_FOR_CONVERSATION_SQL = `
  SELECT m.id, m.message_text AS text, m.sender_name AS sender,
         m.pinned_at AS "pinnedAt",
         COALESCE(NULLIF(TRIM(u.first_name), ''), 'Someone') AS "pinnedBy"
    FROM chat_messages m
    LEFT JOIN users u ON u.id = m.pinned_by
   WHERE m.conversation_id = $1
     AND m.pinned_at IS NOT NULL
     AND m.deleted_at IS NULL
   LIMIT 1
`;

/** Shape a pinned row for the client. Ids as strings, like every chat payload. */
function buildPinnedView(row) {
  if (!row) return null;
  return {
    messageId: String(row.id),
    text: row.text,
    sender: row.sender,
    pinnedBy: row.pinnedBy,
    pinnedAt: row.pinnedAt,
  };
}

module.exports = {
  PINNER_ROLES,
  canPinMessage,
  UNPIN_CONVERSATION_SQL,
  PIN_MESSAGE_SQL,
  UNPIN_MESSAGE_SQL,
  PINNED_FOR_CONVERSATION_SQL,
  buildPinnedView,
};
