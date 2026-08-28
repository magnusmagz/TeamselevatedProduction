'use strict';

const test = require('node:test');
const assert = require('node:assert');
const {
  REACTION_EMOJI, isAllowedEmoji, groupReactions,
} = require('../lib/reactions');

/**
 * Message reactions.
 *
 * ⚠️ These existed on paper since January and never worked. The table, the
 * server handlers and the client helpers all shipped, and nothing ever called
 * them — no listener, no UI, and the server never sent a message's existing
 * reactions when a conversation loaded, so even a stored reaction would have
 * vanished on refresh. Zero rows across 366 messages in production. Finished
 * 2026-08-28.
 */

test('the set is the six agreed, and nothing negative', () => {
  assert.deepStrictEqual(REACTION_EMOJI, ['👍', '❤️', '🎉', '👏', '😂', '😮']);

  // A thumbs-down or an angry face in a parent group chat creates conflict a
  // message never would. Adding one is a product decision, not a tweak.
  for (const negative of ['👎', '😡', '🤬', '💩']) {
    assert.strictEqual(isAllowedEmoji(negative), false, `${negative} must not be available`);
  }
});

test('anything outside the set is refused', () => {
  assert.strictEqual(isAllowedEmoji('🦄'), false);
  assert.strictEqual(isAllowedEmoji(''), false);
  assert.strictEqual(isAllowedEmoji(null), false);
  assert.strictEqual(isAllowedEmoji(undefined), false);
  assert.strictEqual(isAllowedEmoji('👍'), true);
});

test('reactions group per message and per emoji, carrying who', () => {
  const grouped = groupReactions([
    { messageId: 12, emoji: '👍', userId: 74, userName: 'Lisa' },
    { messageId: 12, emoji: '👍', userId: 96, userName: 'David' },
    { messageId: 12, emoji: '🎉', userId: 74, userName: 'Lisa' },
    { messageId: 13, emoji: '❤️', userId: 96, userName: 'David' },
  ]);

  assert.strictEqual(grouped['12'].length, 2);

  const thumbs = grouped['12'].find((r) => r.emoji === '👍');
  assert.strictEqual(thumbs.count, 2);
  assert.deepStrictEqual(thumbs.users.map((u) => u.name), ['Lisa', 'David']);

  assert.strictEqual(grouped['13'][0].emoji, '❤️');
});

/**
 * ⚠️ Ids are STRINGS throughout. pg returns integers as numbers and the client
 * holds message ids as strings; mixing them is the mismatch that produced three
 * separate visible bugs on 2026-08-26.
 */
test('ids come back as strings so the client can match them', () => {
  const grouped = groupReactions([
    { messageId: 12, emoji: '👍', userId: 74, userName: 'Lisa' },
  ]);

  assert.ok(Object.prototype.hasOwnProperty.call(grouped, '12'), 'keyed by string id');
  assert.strictEqual(typeof grouped['12'][0].users[0].id, 'string');
});

test('a message with no reactions simply has no entry', () => {
  assert.deepStrictEqual(groupReactions([]), {});
  assert.deepStrictEqual(groupReactions(null), {});
});

test('a reactor with no name still shows as someone', () => {
  const grouped = groupReactions([
    { messageId: 12, emoji: '👍', userId: 74, userName: 'Someone' },
  ]);
  assert.strictEqual(grouped['12'][0].users[0].name, 'Someone');
});

/**
 * The server, the client and the migration each hold this list, and the
 * database is the enforcement — a picker is only a suggestion. A set that
 * drifts produces reactions the server silently refuses.
 */
test('the client and the migration carry the same set', () => {
  const fs = require('fs');
  const path = require('path');
  const root = path.join(__dirname, '..', '..');

  const client = fs.readFileSync(
    path.join(root, 'frontend/src/components/chat/reactionEmoji.ts'), 'utf8');
  const migration = fs.readFileSync(
    path.join(root, 'database/migrations/079_chat_reaction_emoji_set.sql'), 'utf8');

  for (const emoji of REACTION_EMOJI) {
    assert.ok(client.includes(emoji), `client is missing ${emoji}`);
    assert.ok(migration.includes(emoji), `migration is missing ${emoji}`);
  }
});
