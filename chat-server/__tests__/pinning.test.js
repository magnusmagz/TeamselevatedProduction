'use strict';

const test = require('node:test');
const assert = require('node:assert');
const { canPinMessage, PINNER_ROLES, buildPinnedView } = require('../lib/pinning');
const { canModerate } = require('../lib/moderation');

/**
 * Pinning. One pin per conversation, enforced by a partial unique index rather
 * than by the handler.
 */

test('coaches and club admins can pin; families cannot', () => {
  assert.strictEqual(canPinMessage('coach'), true);
  assert.strictEqual(canPinMessage('club_admin'), true);
  assert.strictEqual(canPinMessage('super_admin'), true);

  assert.strictEqual(canPinMessage('parent'), false);
  assert.strictEqual(canPinMessage('player'), false);
  assert.strictEqual(canPinMessage(undefined), false);
});

/**
 * ⚠️ The whole reason this predicate exists separately. canModerate() excludes
 * coaches — using it here would mean a coach could not pin their own team's
 * practice details, which is the main thing pinning is for.
 */
test('pinning is deliberately wider than moderation', () => {
  assert.strictEqual(canModerate('coach'), false, 'moderation excludes coaches');
  assert.strictEqual(canPinMessage('coach'), true, 'pinning includes them');
  assert.ok(!PINNER_ROLES.includes('parent'), 'but not families');
});

test('a pinned message is shaped for the client with string ids', () => {
  const view = buildPinnedView({
    id: 366, text: 'Practice moved to 5pm', sender: 'Cora Coach',
    pinnedBy: 'Cora', pinnedAt: '2026-08-28T12:00:00Z',
  });

  assert.strictEqual(view.messageId, '366');
  assert.strictEqual(typeof view.messageId, 'string');
  assert.strictEqual(view.text, 'Practice moved to 5pm');
  assert.strictEqual(view.pinnedBy, 'Cora');
});

test('nothing pinned is null, not an empty object', () => {
  assert.strictEqual(buildPinnedView(undefined), null);
  assert.strictEqual(buildPinnedView(null), null);
});

/**
 * A removed message must not stay pinned to the top of a conversation —
 * moderation nulls the text, so the banner would read "Message removed by an
 * administrator", which is worse than no banner. The query filters it; the pin
 * is deliberately left in the database in case the removal is reversed.
 */
test('the pinned query excludes removed messages', () => {
  const { PINNED_FOR_CONVERSATION_SQL } = require('../lib/pinning');
  assert.match(PINNED_FOR_CONVERSATION_SQL, /deleted_at IS NULL/);
});

/**
 * One pin per conversation. The handler clears the old one first so replacing
 * reads as a replace rather than tripping the index — but the index is what
 * makes it true.
 */
test('the migration enforces one pin per conversation', () => {
  const fs = require('fs');
  const path = require('path');
  const sql = fs.readFileSync(
    path.join(__dirname, '..', '..', 'database/migrations/081_chat_pinned_messages.sql'), 'utf8');

  assert.match(sql, /CREATE UNIQUE INDEX/i);
  assert.match(sql, /WHERE pinned_at IS NOT NULL/i);
});
