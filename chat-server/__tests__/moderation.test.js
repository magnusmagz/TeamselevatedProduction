'use strict';

const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');

const {
  TOMBSTONE_TEXT,
  MODERATOR_ROLES,
  canModerate,
  isPlatformRole,
  buildMessageHistoryQuery,
  MESSAGE_SCOPE_SQL,
  REMOVE_MESSAGE_SQL,
} = require('../lib/moderation');
const { AUDIT_INSERT_SQL } = require('../lib/audit');
const { MARK_READ_SQL } = require('../lib/archive');

const serverSrc = fs.readFileSync(path.join(__dirname, '..', 'server.js'), 'utf8');
const removeHandler = (() => {
  const start = serverSrc.indexOf("socket.on('removeMessage'");
  return serverSrc.slice(start, serverSrc.indexOf("socket.on('archiveConversation'"));
})();

// ─── Who may remove ──────────────────────────────────────────────────────────

test('coaches cannot remove messages', () => {
  // The capability deliberately withheld: a coach must not be able to scrub
  // their own words, and a coach is the person you least want able to.
  assert.strictEqual(canModerate('coach'), false);
  assert.strictEqual(canModerate('parent'), false);
  assert.strictEqual(canModerate('player'), false);
  assert.strictEqual(canModerate('volunteer'), false);
});

test('club admins and platform roles may remove', () => {
  for (const role of ['club_admin', 'admin', 'owner', 'super_admin']) {
    assert.strictEqual(canModerate(role), true, `${role} should moderate`);
  }
  assert.deepStrictEqual([...MODERATOR_ROLES].sort(), ['admin', 'club_admin', 'owner', 'super_admin']);
});

test('only platform roles cross club boundaries', () => {
  assert.strictEqual(isPlatformRole('super_admin'), true);
  assert.strictEqual(isPlatformRole('owner'), true);
  assert.strictEqual(isPlatformRole('club_admin'), false);
});

test('the handler enforces the role and the club', () => {
  assert.match(removeHandler, /canModerate\(userInfo\.role\)/);
  assert.match(removeHandler, /isPlatformRole\(userInfo\.role\)/);
  assert.match(removeHandler, /Number\(actorClub\) !== Number\(msg\.clubId\)/,
    'club comparison must be numeric — a string/number mismatch would silently pass');
});

// ─── Soft, never hard ────────────────────────────────────────────────────────

test('removal is an UPDATE, never a DELETE', () => {
  // Matches `DELETE FROM`, not the word "delete" — the prose in this area says
  // "soft delete" a lot, and a bare word match flags the comments.
  assert.match(REMOVE_MESSAGE_SQL, /^\s*UPDATE chat_messages/);
  assert.ok(!/DELETE\s+FROM/i.test(REMOVE_MESSAGE_SQL));
  assert.ok(!/DELETE\s+FROM/i.test(removeHandler), 'the handler must never hard-delete');
  assert.ok(!/TRUNCATE/i.test(removeHandler));
});

test('removal records who and why', () => {
  assert.match(REMOVE_MESSAGE_SQL, /deleted_at = NOW\(\)/);
  assert.match(REMOVE_MESSAGE_SQL, /deleted_by = \$2/);
  assert.match(REMOVE_MESSAGE_SQL, /removal_reason = \$3/);
});

test('re-removing does not rewrite the original actor', () => {
  // Idempotency guard: without it a second admin overwrites who removed it and
  // when, destroying the accountability the audit trail depends on.
  assert.match(REMOVE_MESSAGE_SQL, /WHERE id = \$1 AND deleted_at IS NULL/);
});

test('the message text is never sent to clients once removed', () => {
  const q = buildMessageHistoryQuery();
  assert.match(q, /CASE WHEN deleted_at IS NULL THEN message_text ELSE NULL END AS text/,
    'text must be nulled in SQL, so no later refactor can serialise it');
});

// ─── The tombstone, and the seven deleted_at sites ───────────────────────────

test('history RETURNS removed messages rather than filtering them', () => {
  for (const team of [false, true]) {
    const q = buildMessageHistoryQuery({ team });
    assert.ok(!/deleted_at IS NULL/.test(q.replace(/CASE WHEN deleted_at IS NULL[^,]*/g, '')),
      'history must not filter removed messages out — they render as tombstones');
    assert.match(q, /\(deleted_at IS NOT NULL\) AS removed/);
  }
});

test('the tombstone does not blame the sender', () => {
  assert.match(TOMBSTONE_TEXT, /administrator/i);
  assert.ok(!/unsent|deleted a message/i.test(TOMBSTONE_TEXT));
});

test('markRead INCLUDES removed messages in the watermark', () => {
  // Otherwise removing the newest message leaves that conversation's unread
  // badge stuck forever, because the watermark can never reach the latest id.
  const maxSubquery = MARK_READ_SQL.match(/SELECT MAX\(id\) FROM chat_messages[^)]*/)[0];
  assert.ok(!/deleted_at/.test(maxSubquery),
    'the read watermark must not exclude removed messages');
});

test('unread counts and previews still EXCLUDE removed messages', () => {
  // Only the watermark includes them. A tombstone must not drive a badge or a
  // conversation-list preview.
  const unread = serverSrc.match(/SELECT COUNT\(\*\) as count FROM chat_messages[\s\S]{0,200}/);
  assert.ok(unread && /deleted_at IS NULL/.test(unread[0]), 'unread count must exclude removed');

  const preview = serverSrc.match(/SELECT sender_name, created_at FROM chat_messages[\s\S]{0,200}/);
  assert.ok(preview && /deleted_at IS NULL/.test(preview[0]), 'preview must exclude removed');
});

// ─── Audit ───────────────────────────────────────────────────────────────────

test('the audit row is written inside the removal transaction', () => {
  const begin = removeHandler.indexOf("'BEGIN'");
  const audit = removeHandler.indexOf('logInTransaction');
  const commit = removeHandler.indexOf("'COMMIT'");
  assert.ok(begin !== -1 && audit !== -1 && commit !== -1);
  assert.ok(begin < audit && audit < commit,
    'a removal that cannot be audited must roll back, not proceed');
});

test('the audit entry does NOT carry the message text', () => {
  // audit_log is retained 2555 days against the message's own 90. Copying the
  // text there would defeat every removal motivated by privacy rather than safety.
  const details = removeHandler.slice(removeHandler.indexOf('details: {'));
  assert.ok(!/message_text|\btext\b/.test(details.slice(0, 300)),
    'audit details must not include the removed text');
});

test('the audit statement matches the PHP AuditLogger column list', () => {
  // CLAUDE.md: write audit_log through lib/AuditLogger.php, never a raw INSERT.
  // Node cannot require the PHP class, so the shapes are pinned together here.
  const php = fs.readFileSync(path.join(__dirname, '..', '..', 'lib', 'AuditLogger.php'), 'utf8');
  const cols = s => (s.match(/\(user_id[^)]*\)/) || [''])[0].replace(/\s+/g, ' ').trim();
  assert.strictEqual(cols(AUDIT_INSERT_SQL), cols(php),
    'chat-server audit INSERT has drifted from lib/AuditLogger.php');
});

// ─── Scoping ─────────────────────────────────────────────────────────────────

test('a message resolves to its conversation club for scoping', () => {
  assert.match(MESSAGE_SCOPE_SQL, /c\.club_id AS "clubId"/);
  assert.match(MESSAGE_SCOPE_SQL, /m\.deleted_at AS "deletedAt"/);
});

test('everyone in the room is told, not just the admin', () => {
  assert.match(removeHandler, /io\.to\(getConversationRoom\(msg\.conversationId\)\)\.emit\('messageRemoved'/);
});
