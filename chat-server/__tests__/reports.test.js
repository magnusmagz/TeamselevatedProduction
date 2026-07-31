'use strict';

const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');

const {
  REPORT_REASONS,
  severityForReason,
  isValidReason,
  FILE_USER_REPORT_SQL,
  REPORT_SCOPE_SQL,
  OPEN_REPORT_COUNT_SQL,
} = require('../lib/reports');

const serverSrc = fs.readFileSync(path.join(__dirname, '..', 'server.js'), 'utf8');
const reportHandler = (() => {
  const start = serverSrc.indexOf("socket.on('reportMessage'");
  return serverSrc.slice(start, serverSrc.indexOf("socket.on('removeMessage'"));
})();
const migration = fs.readFileSync(
  path.join(__dirname, '..', '..', 'database', 'migrations', '061_chat_message_reports.sql'),
  'utf8'
);

// ─── Reasons and severity ────────────────────────────────────────────────────

test('reasons are a closed set', () => {
  // Free text goes in `note`. A closed set is what lets the queue be sorted and
  // what gives the compliance summary something to aggregate.
  assert.ok(REPORT_REASONS.includes('safety_concern'));
  assert.ok(REPORT_REASONS.includes('personal_information'));
  assert.strictEqual(isValidReason('safety_concern'), true);
  assert.strictEqual(isValidReason('not_a_reason'), false);
  assert.strictEqual(isValidReason(undefined), false);
});

test('safety and harassment reports are high severity', () => {
  assert.strictEqual(severityForReason('safety_concern'), 'high');
  assert.strictEqual(severityForReason('harassment'), 'high');
  assert.strictEqual(severityForReason('spam'), 'medium');
  assert.strictEqual(severityForReason('other'), 'medium');
});

test('an unknown reason cannot smuggle in a severity', () => {
  assert.strictEqual(severityForReason('anything_else'), 'medium');
});

// ─── Who may report, and what they may report on ─────────────────────────────

test('reporting requires a message and a valid reason', () => {
  assert.match(reportHandler, /isValidReason\(reason\)/);
});

test('you can only report a message you can already read', () => {
  // Otherwise reporting becomes a probe for the existence of messages in other
  // clubs — an enumeration oracle dressed as a safety feature.
  assert.match(reportHandler, /isConversationParticipant\(/);
  const accessAt = reportHandler.indexOf('isConversationParticipant');
  const insertAt = reportHandler.indexOf('FILE_USER_REPORT_SQL');
  assert.ok(accessAt !== -1 && insertAt !== -1 && accessAt < insertAt,
    'access must be checked before the report is filed');
});

test('the reporter is never told whether the message was already flagged', () => {
  // Same response for a fresh report and a duplicate. Otherwise the reporter
  // learns that someone else reported it, or that an admin dismissed it.
  assert.match(reportHandler, /socket\.emit\('messageReported'/);
  assert.ok(!/already|duplicate|dismissed/i.test(reportHandler.split("emit('messageReported'")[1] || ''));
});

test('the reporter note is length-capped before storage', () => {
  assert.match(reportHandler, /\.slice\(0, 2000\)/);
});

// ─── Deduplication ───────────────────────────────────────────────────────────

test('a duplicate report is swallowed rather than erroring', () => {
  assert.match(FILE_USER_REPORT_SQL, /ON CONFLICT DO NOTHING/);
});

test('dedupe uses PARTIAL unique indexes, split by source', () => {
  // A single combined UNIQUE would dedupe neither: reported_by is NULL for auto
  // flags and rule is NULL for human reports, and Postgres treats NULLs as
  // distinct in a unique constraint.
  assert.match(migration, /CREATE UNIQUE INDEX[\s\S]*?WHERE source = 'user'/);
  assert.match(migration, /CREATE UNIQUE INDEX[\s\S]*?WHERE source = 'auto'/);
});

test('user dedupe is per reporter, auto dedupe is per rule', () => {
  assert.match(migration, /\(message_id, reported_by\) WHERE source = 'user'/);
  assert.match(migration, /\(message_id, rule\) WHERE source = 'auto'/);
});

// ─── The table as an authorisation record ────────────────────────────────────

test('human reports and auto flags share one table', () => {
  // One admin inbox, not two.
  assert.match(migration, /source VARCHAR\(10\) NOT NULL DEFAULT 'user'/);
  assert.match(migration, /CHECK \(source IN \('user', 'auto'\)\)/);
});

test('there is an index for "is this conversation open for review"', () => {
  // M3 asks this on every admin conversation open; it is the flag-gate.
  assert.match(migration, /idx_chat_reports_conversation_open[\s\S]*?WHERE status = 'open'/);
});

test('club_id is denormalised onto the report', () => {
  // conversations.club_id is NULL for pre-conversations legacy messages, so a
  // queue that scoped by joining through conversations would silently drop them.
  assert.match(migration, /club_id INTEGER REFERENCES club_profile\(id\)/);
  assert.match(OPEN_REPORT_COUNT_SQL, /WHERE club_id = \$1 AND status = 'open'/);
});

test('reports cascade when their message is finally purged', () => {
  // Retention hard-deletes removed messages after 90 days. A report pointing at
  // a message nobody can read is not evidence of anything.
  assert.match(migration, /message_id INTEGER NOT NULL REFERENCES chat_messages\(id\) ON DELETE CASCADE/);
});

test('status and severity are constrained, not free text', () => {
  assert.match(migration, /CHECK \(status IN \('open', 'actioned', 'dismissed'\)\)/);
  assert.match(migration, /CHECK \(severity IN \('low', 'medium', 'high'\)\)/);
});

test('reporting never deletes anything', () => {
  assert.ok(!/DELETE\s+FROM/i.test(FILE_USER_REPORT_SQL));
  assert.ok(!/DELETE\s+FROM/i.test(reportHandler));
  assert.ok(!/DROP/i.test(migration.replace(/--.*$/gm, '')));
});

test('the scope query exposes what the queue needs to route a report', () => {
  assert.match(REPORT_SCOPE_SQL, /c\.club_id AS "clubId"/);
  assert.match(REPORT_SCOPE_SQL, /c\.type AS "conversationType"/);
});
