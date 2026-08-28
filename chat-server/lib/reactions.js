'use strict';

/**
 * Message reactions.
 *
 * ⚠️ **Reactions have existed on paper since January and never worked.** The
 * table, the server handlers and the client's `addReaction`/`removeReaction`
 * helpers were all shipped — and nothing ever called them. No listener, no UI,
 * and crucially the server never sent a message's existing reactions when a
 * conversation loaded, so even a reaction that was somehow stored would have
 * vanished on refresh. Zero rows across 366 messages, confirmed against
 * production 2026-08-28. Treat the pre-existing code as a sketch, not as
 * working precedent.
 *
 * The missing half was always the read side, which is what this file is.
 */

/**
 * The six, agreed with Maggie 2026-08-28.
 *
 * Acknowledge, warmth, celebrate, well done, funny, surprise. Six fits one row
 * on a phone.
 *
 * ⚠️ **Nothing negative, deliberately.** A thumbs-down or an angry face in a
 * parent group chat creates conflict a message never would, and once it is on
 * screen it gets used. Adding one is a product decision, not a tweak.
 *
 * Mirrored by the CHECK constraint in migration 079 — the set is enforced where
 * it is stored, not merely where it is offered, because a picker is a
 * suggestion. Change both together or the constraint rejects what the UI sends.
 */
const REACTION_EMOJI = ['👍', '❤️', '🎉', '👏', '😂', '😮'];

function isAllowedEmoji(emoji) {
  return REACTION_EMOJI.includes(emoji);
}

/**
 * Every reaction on a set of messages, with the reactor's name.
 *
 * The name is joined here rather than stored on the reaction: a person who
 * changes their name should not leave a trail of the old one under old
 * messages. `users.first_name` alone, because a reaction is a lightweight
 * acknowledgement and a full name reads as heavier than the gesture.
 */
const REACTIONS_FOR_MESSAGES_SQL = `
  SELECT r.message_id   AS "messageId",
         r.emoji        AS emoji,
         r.user_id      AS "userId",
         COALESCE(NULLIF(TRIM(u.first_name), ''), 'Someone') AS "userName"
    FROM chat_reactions r
    LEFT JOIN users u ON u.id = r.user_id
   WHERE r.message_id = ANY($1::int[])
   ORDER BY r.created_at
`;

/**
 * Fold reaction rows onto their messages.
 *
 * Returns, per message id, one entry per emoji with the people who used it:
 *
 *   { "12": [ { emoji: '👍', count: 2, users: [{id, name}, …] } ] }
 *
 * Grouped on the server so every client does not repeat the same fold, and so
 * the shape the UI renders is decided in one place.
 */
function groupReactions(rows) {
  const byMessage = {};

  for (const row of rows || []) {
    // String keys throughout: pg returns integers as numbers and the client
    // holds message ids as STRINGS. Mixing them is the mismatch that produced
    // three separate visible bugs on 2026-08-26 — see sameUser() on the client.
    const messageId = String(row.messageId);

    if (!byMessage[messageId]) byMessage[messageId] = [];

    let entry = byMessage[messageId].find((e) => e.emoji === row.emoji);
    if (!entry) {
      entry = { emoji: row.emoji, count: 0, users: [] };
      byMessage[messageId].push(entry);
    }

    entry.count += 1;
    entry.users.push({ id: String(row.userId), name: row.userName });
  }

  return byMessage;
}

module.exports = {
  REACTION_EMOJI,
  isAllowedEmoji,
  REACTIONS_FOR_MESSAGES_SQL,
  groupReactions,
};
