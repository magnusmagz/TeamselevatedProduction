'use strict';

const test = require('node:test');
const assert = require('node:assert');

const {
  buildConversationsQuery,
  ARCHIVE_SQL,
  UNARCHIVE_SQL,
  UNARCHIVE_ON_NEW_MESSAGE_SQL,
  MARK_READ_SQL,
} = require('../lib/archive');

/**
 * Return the first balanced parenthesised group after WHERE, plus where it ends.
 * Used to prove the archive predicate sits OUTSIDE the OR group rather than
 * inside it — a string `.includes()` cannot tell those two apart, and the
 * difference is the whole bug.
 */
function whereOrGroup(sql) {
  const whereIdx = sql.indexOf('WHERE');
  assert.notStrictEqual(whereIdx, -1, 'query has no WHERE clause');
  const openIdx = sql.indexOf('(', whereIdx);
  assert.notStrictEqual(openIdx, -1, 'WHERE clause has no parenthesised group');

  let depth = 0;
  for (let i = openIdx; i < sql.length; i++) {
    if (sql[i] === '(') depth++;
    else if (sql[i] === ')') {
      depth--;
      if (depth === 0) return { group: sql.slice(openIdx, i + 1), rest: sql.slice(i + 1) };
    }
  }
  throw new Error('unbalanced parentheses in WHERE clause');
}

// ─── Conversation list filtering ─────────────────────────────────────────────

test('active list excludes conversations this user archived', () => {
  assert.match(buildConversationsQuery(), /cp\.archived_at IS NULL/);
});

test('archived list selects only conversations this user archived', () => {
  assert.match(buildConversationsQuery({ archived: true }), /cp\.archived_at IS NOT NULL/);
});

test('default is the active list', () => {
  assert.strictEqual(buildConversationsQuery(), buildConversationsQuery({ archived: false }));
});

test('archive filter sits OUTSIDE the OR group, so the team branch cannot re-admit it', () => {
  // The team branch (c.type = 'team' AND c.team_id = ANY(...)) matches regardless
  // of participant state. If the archive predicate were placed inside the OR
  // group, archiving a TEAM chat would appear to do nothing at all.
  for (const archived of [false, true]) {
    const { group, rest } = whereOrGroup(buildConversationsQuery({ archived }));

    assert.match(group, /OR/, 'expected the OR group to hold both access branches');
    assert.match(group, /team_id = ANY/, 'expected the team branch inside the OR group');
    assert.ok(
      !group.includes('archived_at'),
      'archive predicate must NOT be inside the OR group — the team branch would re-admit it'
    );
    assert.match(rest, /AND\s+cp\.archived_at/, 'archive predicate must be ANDed after the OR group');
  }
});

test('archive state is exposed to the client as archivedAt', () => {
  assert.match(buildConversationsQuery(), /cp\.archived_at AS "archivedAt"/);
});

// ─── Writes must be upserts, because team chats have no participant row ──────

test('archive upserts rather than updates', () => {
  // ensureTeamConversation() creates team conversations with NO participant rows;
  // members reach them through the team-id branch. A bare UPDATE would affect zero
  // rows and archiving a team chat would silently fail.
  assert.match(ARCHIVE_SQL, /INSERT INTO conversation_participants/);
  assert.match(ARCHIVE_SQL, /ON CONFLICT \(conversation_id, user_id\)/);
  assert.match(ARCHIVE_SQL, /DO UPDATE SET archived_at/);
});

test('archive conflict path touches only archived_at', () => {
  // Re-archiving must not clobber display_name, role, or read state.
  const doUpdate = ARCHIVE_SQL.slice(ARCHIVE_SQL.indexOf('DO UPDATE'));
  for (const col of ['display_name', 'role', 'last_read_message_id', 'left_at']) {
    assert.ok(!doUpdate.includes(col), `conflict path must not overwrite ${col}`);
  }
});

test('mark-read upserts, so team-chat unread badges actually clear', () => {
  // This was a bare UPDATE against a row that does not exist on team chats.
  assert.match(MARK_READ_SQL, /INSERT INTO conversation_participants/);
  assert.match(MARK_READ_SQL, /ON CONFLICT \(conversation_id, user_id\)/);
});

// ─── Unarchive ───────────────────────────────────────────────────────────────

test('manual unarchive is scoped to the one user who asked', () => {
  assert.match(UNARCHIVE_SQL, /SET archived_at = NULL/);
  assert.match(UNARCHIVE_SQL, /conversation_id = \$1 AND user_id = \$2/);
});

test('a new message unarchives for EVERYONE who had archived it', () => {
  assert.match(UNARCHIVE_ON_NEW_MESSAGE_SQL, /SET archived_at = NULL/);
  assert.ok(
    !/user_id = \$\d/.test(UNARCHIVE_ON_NEW_MESSAGE_SQL),
    'must not be scoped to a single user — a new message restores the thread for all who archived it'
  );
  assert.match(UNARCHIVE_ON_NEW_MESSAGE_SQL, /archived_at IS NOT NULL/);
});

test('unarchive-on-new-message returns the affected users', () => {
  // The caller needs these ids: an archived conversation is absent from those
  // clients' lists, so the ordinary conversationUpdated broadcast is dropped on
  // the floor and the thread would not reappear until a reload.
  assert.match(UNARCHIVE_ON_NEW_MESSAGE_SQL, /RETURNING user_id/);
});

// ─── The thing this feature is NOT ───────────────────────────────────────────

test('nothing here deletes a message or a conversation', () => {
  // Archive is view state. The only removal path in chat is admin moderation,
  // which tombstones and writes audit_log. If a DELETE ever appears in this
  // module, that decision has been reversed by accident.
  for (const sql of [ARCHIVE_SQL, UNARCHIVE_SQL, UNARCHIVE_ON_NEW_MESSAGE_SQL, MARK_READ_SQL]) {
    assert.ok(!/\bDELETE\b/i.test(sql), 'archive must never delete');
  }
  assert.ok(!/\bDELETE\b/i.test(buildConversationsQuery()));
});

test('archive never touches left_at', () => {
  // left_at is the "leave group" verb: its six read-side uses remove you from
  // every other participant's roster. Archive must stay distinct from it.
  assert.ok(!/left_at\s*=/.test(ARCHIVE_SQL));
  assert.ok(!/left_at\s*=/.test(UNARCHIVE_SQL));
  assert.ok(!/left_at\s*=/.test(UNARCHIVE_ON_NEW_MESSAGE_SQL));
});
