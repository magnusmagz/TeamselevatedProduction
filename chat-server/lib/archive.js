'use strict';

/**
 * Conversation archive — per-user view state, NOT deletion.
 *
 * Archiving hides a conversation from one user's list. Nothing is removed, no
 * other participant is affected, and a new message brings it back. There is no
 * user-facing delete in chat; the only removal path is admin moderation, which
 * tombstones and writes audit_log.
 *
 * ─── Why every write here is an UPSERT ────────────────────────────────────────
 * ensureTeamConversation() creates a team conversation with NO participant rows.
 * Team members reach it through the team-id branch of getUserConversations:
 *
 *     OR (c.type = 'team' AND c.team_id = ANY($2::int[]))
 *
 * So for a team chat the row that per-user state lives on usually does not exist
 * yet. A bare UPDATE silently affects zero rows and the archive appears to work
 * until the list reloads. This is not hypothetical — it is exactly why markRead
 * never cleared unread badges on team chats.
 *
 * These are exported as SQL rather than inlined so they can be asserted on
 * without standing up socket.io and Postgres. See __tests__/archive.test.js.
 */

/**
 * The conversation-list query, for either the active list or the archived list.
 *
 * `cp` is LEFT JOINed on this user, so `cp.archived_at IS NULL` correctly reads
 * as "I have not archived this" even when no participant row exists at all.
 *
 * The archive predicate MUST sit outside the OR group. Inside it, the team
 * branch re-admits an archived team conversation and archiving a team chat
 * appears to do nothing.
 */
function buildConversationsQuery({ archived = false } = {}) {
  const archiveFilter = archived ? 'cp.archived_at IS NOT NULL' : 'cp.archived_at IS NULL';

  return `
    SELECT DISTINCT
      c.id,
      c.type,
      c.team_id AS "teamId",
      t.name AS "teamName",
      c.last_message_at AS "lastMessageAt",
      c.last_message_preview AS "lastMessagePreview",
      c.created_at AS "createdAt",
      cp.archived_at AS "archivedAt",
      COALESCE(c.last_message_at, c.created_at) AS "sortTime"
    FROM conversations c
    LEFT JOIN teams t ON t.id = c.team_id
    LEFT JOIN conversation_participants cp ON cp.conversation_id = c.id AND cp.user_id = $1
    WHERE (
      (cp.user_id IS NOT NULL AND cp.left_at IS NULL)
      OR
      (c.type = 'team' AND c.team_id = ANY($2::int[]))
    )
    AND ${archiveFilter}
    ORDER BY "sortTime" DESC
  `;
}

/** Archive for one user. Upsert — see the note above. ($1 conv, $2 user, $3 display name) */
const ARCHIVE_SQL = `
  INSERT INTO conversation_participants
    (conversation_id, user_id, role, display_name, archived_at)
  VALUES ($1, $2, 'member', $3, NOW())
  ON CONFLICT (conversation_id, user_id)
  DO UPDATE SET archived_at = EXCLUDED.archived_at
`;

/**
 * Unarchive for one user. A plain UPDATE is correct here: to have archived it you
 * must already have a row. ($1 conv, $2 user)
 */
const UNARCHIVE_SQL = `
  UPDATE conversation_participants
  SET archived_at = NULL
  WHERE conversation_id = $1 AND user_id = $2
`;

/**
 * A new message un-archives the conversation for EVERYONE who had archived it —
 * Messenger's behaviour, and the thing that makes archive safe to offer: nothing
 * is ever permanently hidden.
 *
 * RETURNING user_id matters. An archived conversation is absent from those users'
 * client-side lists, so the existing `conversationUpdated` broadcast maps over a
 * list that does not contain it and silently does nothing. The caller needs the
 * ids to push the conversation back. ($1 conv)
 */
const UNARCHIVE_ON_NEW_MESSAGE_SQL = `
  UPDATE conversation_participants
  SET archived_at = NULL
  WHERE conversation_id = $1 AND archived_at IS NOT NULL
  RETURNING user_id
`;

/**
 * Mark-read, upserted for the same team-chat reason as archive.
 *
 * This was previously a bare UPDATE, which is a no-op on team conversations
 * because the participant row does not exist — team-chat unread badges never
 * cleared. Fixed here rather than left inconsistent alongside the archive upsert.
 * ($1 conv, $2 user, $3 display name)
 *
 * The watermark deliberately does NOT filter `deleted_at IS NULL`. A removed
 * message is still something the user has seen — it renders as a tombstone — so
 * excluding it would stop the watermark short of the newest message and leave the
 * unread badge stuck forever on any conversation whose latest message was
 * moderated away. Unread COUNTS still exclude removed messages; only this
 * high-water mark includes them.
 */
const MARK_READ_SQL = `
  INSERT INTO conversation_participants
    (conversation_id, user_id, role, display_name, last_read_at, last_read_message_id)
  VALUES ($1, $2, 'member', $3, NOW(),
    (SELECT MAX(id) FROM chat_messages WHERE conversation_id = $1))
  ON CONFLICT (conversation_id, user_id)
  DO UPDATE SET last_read_at = NOW(),
                last_read_message_id = EXCLUDED.last_read_message_id
`;

module.exports = {
  buildConversationsQuery,
  ARCHIVE_SQL,
  UNARCHIVE_SQL,
  UNARCHIVE_ON_NEW_MESSAGE_SQL,
  MARK_READ_SQL,
};
