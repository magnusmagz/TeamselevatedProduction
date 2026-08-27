'use strict';

const test = require('node:test');
const assert = require('node:assert');

/**
 * The unread badge never moved until a page refresh.
 *
 * `conversationUpdated` is the ONLY event a client receives for a conversation
 * it does not have open — and the loop that decides who gets it compared
 * `info.userId` (a STRING, off the JWT, because lib/JWT.php mints the claim as
 * `(string)$userId`) against a Set built from pg (NUMBERS, because node-postgres
 * parses int4 to a JavaScript number).
 *
 * `Set{74}.has("74")` is false. So for a DIRECT conversation the event reached
 * nobody, and only the `type === 'team'` fallback kept team chats working. That
 * is why the parent portal looked healthy while the staff app looked broken:
 * parents live in team chats, staff were testing a DM.
 *
 * Reported 2026-08-26. Third instance of this same string/number class in one
 * day, alongside duplicated messages and the client-side badge increment.
 */

test('a numeric participant id matches a string JWT id', () => {
  const fromPostgres = [74, 96];
  const fromJwt = '74';

  const broken = new Set(fromPostgres);
  assert.strictEqual(broken.has(fromJwt), false, 'the bug: this is why nobody was notified');

  const fixed = new Set(fromPostgres.map(String));
  assert.strictEqual(fixed.has(String(fromJwt)), true, 'the fix');
});

test('a non-participant still does not match', () => {
  const fixed = new Set([74, 96].map(String));
  assert.strictEqual(fixed.has(String('123')), false);
});

test('normalising does not collapse different people', () => {
  const fixed = new Set([7, 74].map(String));
  assert.strictEqual(fixed.has('7'), true);
  assert.strictEqual(fixed.has('74'), true);
  assert.strictEqual(fixed.has('747'), false);
});

/**
 * The server file itself must not go back to raw comparisons. The bug was never
 * in the idea, it was in three separate call sites — same shape as sameUser() on
 * the client.
 */
test('server.js compares participant ids as strings everywhere', () => {
  const fs = require('fs');
  const path = require('path');
  const src = fs.readFileSync(path.join(__dirname, '..', 'server.js'), 'utf8');

  const raw = src
    .split('\n')
    .filter((l) => /\.has\(info\.userId\)|info\.userId === (?!String)/.test(l))
    .filter((l) => !l.trim().startsWith('//') && !l.trim().startsWith('*'));

  assert.deepStrictEqual(raw, [], 'compare ids with String() on both sides');
});
