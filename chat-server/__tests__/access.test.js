'use strict';

const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');

const {
  OPEN_REPORT_FOR_CONVERSATION_SQL,
  LOG_ACCESS_SQL,
  moderatorMayOpen,
} = require('../lib/access');

const serverSrc = fs.readFileSync(path.join(__dirname, '..', 'server.js'), 'utf8');
const joinHandler = (() => {
  const start = serverSrc.indexOf("socket.on('joinConversation'");
  return serverSrc.slice(start, serverSrc.indexOf("socket.on('createConversation'"));
})();
const migration = fs.readFileSync(
  path.join(__dirname, '..', '..', 'database', 'migrations', '062_chat_access_log.sql'),
  'utf8'
);

// ─── The gate ────────────────────────────────────────────────────────────────

test('access opens only on an OPEN report', () => {
  assert.match(OPEN_REPORT_FOR_CONVERSATION_SQL, /r\.status = 'open'/);
  assert.match(OPEN_REPORT_FOR_CONVERSATION_SQL, /r\.conversation_id = \$1/);
});

test('the oldest open report is the one that authorises', () => {
  // If several are open, the one that has been waiting is what the admin is
  // answering — and it is the one whose age the queue is flagging.
  assert.match(OPEN_REPORT_FOR_CONVERSATION_SQL, /ORDER BY r\.created_at ASC/);
});

test('a club admin may only open a report in their own club', () => {
  assert.strictEqual(
    moderatorMayOpen({ role: 'club_admin', actorClubId: 51, reportClubId: 51, isPlatform: false }),
    true
  );
  assert.strictEqual(
    moderatorMayOpen({ role: 'club_admin', actorClubId: 51, reportClubId: 32, isPlatform: false }),
    false
  );
});

test('platform roles cross clubs, club admins do not', () => {
  assert.strictEqual(
    moderatorMayOpen({ role: 'super_admin', actorClubId: null, reportClubId: 32, isPlatform: true }),
    true
  );
});

test('a missing club on either side refuses rather than defaulting open', () => {
  // Legacy conversations can carry a NULL club. Unknown must not mean allowed.
  assert.strictEqual(
    moderatorMayOpen({ role: 'club_admin', actorClubId: null, reportClubId: 32, isPlatform: false }),
    false
  );
  assert.strictEqual(
    moderatorMayOpen({ role: 'club_admin', actorClubId: 51, reportClubId: null, isPlatform: false }),
    false
  );
});

test('club ids are compared numerically', () => {
  // A JWT may carry a string where the DB gives a number; === would refuse a
  // legitimate admin, and that failure would look like a permissions bug.
  assert.strictEqual(
    moderatorMayOpen({ role: 'club_admin', actorClubId: '51', reportClubId: 51, isPlatform: false }),
    true
  );
});

test('a role of nothing opens nothing', () => {
  assert.strictEqual(moderatorMayOpen({ role: null, isPlatform: false }), false);
});

// ─── Reading is granted; sending is not ──────────────────────────────────────

test('only moderators reach the flag-gated branch', () => {
  assert.match(joinHandler, /canModerate\(userInfo\.role\)/);
});

test('sendMessage still uses the STRICT participant predicate', () => {
  // The whole point of keeping this out of isConversationParticipant: an admin
  // who reaches into a reported DM must not be able to talk in it.
  const sendHandler = serverSrc.slice(
    serverSrc.indexOf("socket.on('sendMessage'"),
    serverSrc.indexOf("socket.on('getTeamMembers'")
  );
  assert.match(sendHandler, /isConversationParticipant\(/);
  assert.ok(!/OPEN_REPORT_FOR_CONVERSATION_SQL/.test(sendHandler),
    'sendMessage must not gain flag-gated access');
  assert.ok(!/moderatorMayOpen/.test(sendHandler));
});

test('the strict predicate itself was not widened', () => {
  const pred = serverSrc.slice(
    serverSrc.indexOf('async function isConversationParticipant'),
    serverSrc.indexOf('async function getConversationRoom') > -1
      ? serverSrc.indexOf('async function getConversationRoom')
      : serverSrc.indexOf('async function isConversationParticipant') + 2000
  );
  assert.ok(!/chat_message_reports/.test(pred),
    'isConversationParticipant must stay report-free — it gates sends too');
});

// ─── Every flag-gated open is logged ─────────────────────────────────────────

test('the open is logged before any of the conversation is served', () => {
  const logAt = joinHandler.indexOf('LOG_ACCESS_SQL');
  const joinAt = joinHandler.indexOf('socket.join(room)');
  assert.ok(logAt !== -1, 'a flag-gated open must be logged');
  assert.ok(joinAt !== -1);
  assert.ok(logAt < joinAt, 'log the read before serving it');
});

test('a failed log refuses the read', () => {
  // A read with no log entry is exactly what this table exists to prevent.
  const after = joinHandler.slice(joinHandler.indexOf('Error writing chat access log'));
  assert.match(after.slice(0, 250), /socket\.emit\('error'/);
  assert.match(after.slice(0, 250), /return;/);
});

test('ordinary participation is NOT logged', () => {
  // An admin reading a team chat they already belong to is participation, not
  // oversight. Logging it would bury the entries that matter.
  assert.match(joinHandler, /if \(viaReportId !== null\)/);
});

test('the log records which report justified the read', () => {
  assert.match(LOG_ACCESS_SQL, /INSERT INTO chat_access_log/);
  assert.match(LOG_ACCESS_SQL, /\(user_id, conversation_id, report_id, club_id\)/);
  assert.match(migration, /report_id INTEGER NOT NULL REFERENCES chat_message_reports/,
    'report_id must be NOT NULL — an entry without one is an unexplained read');
});

test('the access log is append-only in this module', () => {
  assert.ok(!/UPDATE chat_access_log/i.test(serverSrc));
  assert.ok(!/DELETE\s+FROM chat_access_log/i.test(serverSrc));
});

test('the log can answer both post-incident questions', () => {
  // "What has this admin looked at" and "who has looked at this conversation".
  assert.match(migration, /idx_chat_access_log_user/);
  assert.match(migration, /idx_chat_access_log_conversation/);
});
