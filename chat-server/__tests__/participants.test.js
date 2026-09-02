'use strict';

const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');

const { ALLOWED_PARTICIPANTS_SQL, disallowedParticipants } = require('../lib/participants');
const { GUARDIAN_JOIN_SQL } = require('../lib/guardian_identity');

const serverSrc = fs.readFileSync(path.join(__dirname, '..', 'server.js'), 'utf8');

// ─── The allowlist shape ─────────────────────────────────────────────────────

test('allowed set is built from guardians and club staff', () => {
  assert.match(ALLOWED_PARTICIPANTS_SQL, /JOIN guardians g ON/);
  assert.match(ALLOWED_PARTICIPANTS_SQL, /FROM user_club_access uca/);
  assert.match(ALLOWED_PARTICIPANTS_SQL, /UNION/);
});

/**
 * The guardian half of the allowlist resolves identity through
 * lib/guardian_identity.js — user_guardians links UNION the email match — so a
 * parent whose login address differs from their guardian record is reachable by
 * DM. Previously they were not, and it read as "that parent does not exist"
 * rather than as a refusal.
 */
test('the guardian branch uses the shared resolver, both branches', () => {
  assert.strictEqual(
    ALLOWED_PARTICIPANTS_SQL.includes(GUARDIAN_JOIN_SQL),
    true,
    'the DM boundary must use the shared guardian join verbatim'
  );
  assert.match(ALLOWED_PARTICIPANTS_SQL, /EXISTS \(SELECT 1 FROM user_guardians ug/);
  assert.match(ALLOWED_PARTICIPANTS_SQL, /LOWER\(g\.email\) = LOWER\(u\.email\)/);
});

test('staff branch is limited to club_admin and coach, and to the active row', () => {
  assert.match(ALLOWED_PARTICIPANTS_SQL, /uca\.role IN \('club_admin', 'coach'\)/);
  assert.match(ALLOWED_PARTICIPANTS_SQL, /uca\.active/);
  // 'player' must never be a way into the set.
  assert.ok(!/'player'/.test(ALLOWED_PARTICIPANTS_SQL));
});

test('guardian branch is scoped to the passed team ids', () => {
  assert.match(ALLOWED_PARTICIPANTS_SQL, /tm\.team_id = ANY\(\$1::int\[\]\)/);
});

test('staff branch is scoped to the club', () => {
  assert.match(ALLOWED_PARTICIPANTS_SQL, /uca\.club_profile_id = \$2/);
});

/**
 * The regression this whole module exists to prevent.
 *
 * Of 26 populated athletes.user_id values in prod, 23 point at an account whose
 * email is a GUARDIAN's and 10 at accounts holding staff roles. Subtracting that
 * column would refuse DMs to 23 guardians and 10 coaches — the exact conversation
 * chat is for. Athletes stay out by never being IN the set.
 */
test('the allowlist NEVER blocklists athletes.user_id', () => {
  // `athletes` IS referenced, legitimately — the guardian chain walks
  // guardians → athlete_guardians → athletes → team_members to find whose
  // parents are on which team. What must never appear is athletes.USER_ID, the
  // column that would turn this into a blocklist.
  assert.ok(
    !/\ba\.user_id\b/.test(ALLOWED_PARTICIPANTS_SQL) &&
      !/athletes\.user_id/i.test(ALLOWED_PARTICIPANTS_SQL),
    'allowlist must never read athletes.user_id — 23 of 26 point at a guardian'
  );

  for (const forbidden of ['EXCEPT', 'NOT IN', 'NOT EXISTS']) {
    assert.ok(
      !ALLOWED_PARTICIPANTS_SQL.toUpperCase().includes(forbidden),
      `allowlist must not subtract anyone (${forbidden}) — build the set, don't filter it`
    );
  }
});

// ─── The diff ────────────────────────────────────────────────────────────────

test('participants outside the allowed set are refused', () => {
  assert.deepStrictEqual(disallowedParticipants([1, 2, 999], [1, 2, 3]), [999]);
});

test('an allowed set fully covering the request refuses nobody', () => {
  assert.deepStrictEqual(disallowedParticipants([1, 2], [1, 2, 3]), []);
});

test('the creator may always be in their own conversation', () => {
  assert.deepStrictEqual(disallowedParticipants([7], [1, 2], 7), []);
});

test('string ids from client JSON are compared as numbers', () => {
  // pg returns numbers, JSON may carry strings. A mixed-type Set silently lets
  // everything through, which would defeat the entire check.
  assert.deepStrictEqual(disallowedParticipants(['1', '2'], [1, 2]), []);
  assert.deepStrictEqual(disallowedParticipants(['999'], [1, 2]), [999]);
});

test('non-numeric ids are refused rather than coerced through', () => {
  assert.deepStrictEqual(disallowedParticipants(['abc'], [1, 2]), [NaN]);
  assert.strictEqual(disallowedParticipants([null], [1, 2]).length, 1);
});

test('an empty allowed set refuses everyone', () => {
  // A coach with no teams and no club can message nobody. Correct.
  assert.deepStrictEqual(disallowedParticipants([1, 2], []), [1, 2]);
});

test('duplicate requested ids collapse', () => {
  assert.deepStrictEqual(disallowedParticipants([9, 9, 9], [1]), [9]);
});

// ─── Wiring: the check must actually run, and must fail closed ───────────────

test('createConversation calls the allowlist before inserting participants', () => {
  const handler = serverSrc.slice(serverSrc.indexOf("socket.on('createConversation'"));
  const checkAt = handler.indexOf('disallowedParticipants');
  const insertAt = handler.indexOf('INSERT INTO conversation_participants');

  assert.ok(checkAt !== -1, 'createConversation must consult the allowlist');
  assert.ok(insertAt !== -1);
  assert.ok(checkAt < insertAt, 'the allowlist must be checked BEFORE participants are inserted');
});

test('a failure to compute the allowlist refuses the conversation', () => {
  // Fail closed. If we cannot tell who the creator may talk to, "everyone" is
  // precisely the assumption that made this exploitable.
  const handler = serverSrc.slice(serverSrc.indexOf("socket.on('createConversation'"));
  const catchIdx = handler.indexOf('Error resolving allowed participants');
  assert.ok(catchIdx !== -1, 'allowlist resolution needs its own catch');

  const afterCatch = handler.slice(catchIdx, catchIdx + 400);
  assert.match(afterCatch, /socket\.emit\('error'/, 'must surface an error');
  assert.match(afterCatch, /return;/, 'must return rather than fall through to the insert');
});

test('the conversation list and the allowlist share one definition of team access', () => {
  // If these drifted, a user could be offered someone they are then refused, or
  // be allowed to message someone their list never shows.
  assert.match(serverSrc, /async function getAccessibleTeamIds/);
  const calls = serverSrc.match(/getAccessibleTeamIds\(/g) || [];
  assert.ok(calls.length >= 3, 'expected the definition plus both call sites');
});
