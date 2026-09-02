'use strict';

const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');

const {
  expandsToWholeClub,
  COACH_TEAM_IDS_SQL,
  GUARDIAN_TEAM_IDS_SQL,
  CLUB_TEAM_IDS_SQL,
  mergeTeamIds,
} = require('../lib/team_scope');
const { GUARDIAN_JOIN_SQL } = require('../lib/guardian_identity');

const serverSrc = fs.readFileSync(path.join(__dirname, '..', 'server.js'), 'utf8');

/** Body of a top-level function in server.js, up to the next top-level one. */
function fnBody(name, endMarker) {
  const start = serverSrc.indexOf(name);
  assert.notStrictEqual(start, -1, `${name} not found in server.js`);
  const end = serverSrc.indexOf(endMarker, start);
  assert.notStrictEqual(end, -1, `end marker for ${name} not found`);
  return serverSrc.slice(start, end);
}

// ─── The predicate ────────────────────────────────────────────────────────────

test('club-wide visibility is for club staff only', () => {
  for (const role of ['super_admin', 'owner', 'club_admin', 'admin']) {
    assert.strictEqual(expandsToWholeClub(role), true, `${role} should see the club`);
  }
});

/**
 * THE BUG. `canInitiateConversation` contains 'coach' and 'parent', and using it
 * to decide club-wide team visibility gave all 14 CKU coaches all 16 teams.
 */
test('coach and parent never expand to the whole club', () => {
  assert.strictEqual(expandsToWholeClub('coach'), false);
  assert.strictEqual(expandsToWholeClub('parent'), false);
  assert.strictEqual(expandsToWholeClub('player'), false);
  assert.strictEqual(expandsToWholeClub('member'), false);
  assert.strictEqual(expandsToWholeClub(undefined), false);
  assert.strictEqual(expandsToWholeClub(''), false);
});

// ─── The coach scope query ────────────────────────────────────────────────────

test('coach teams match lib/coach_scope.php: primary coach or active staff membership', () => {
  assert.match(COACH_TEAM_IDS_SQL, /t\.primary_coach_id = \$1/);
  assert.match(COACH_TEAM_IDS_SQL, /tm\.user_id = \$1/);
  assert.match(COACH_TEAM_IDS_SQL, /'assistant_coach'/);
  assert.match(COACH_TEAM_IDS_SQL, /'team_manager'/);
  assert.match(COACH_TEAM_IDS_SQL, /tm\.status = 'active'/);
});

/**
 * A `player` membership must never confer coach scope — that is the whole
 * distinction between being on a team and staffing it.
 */
test('coach scope does not admit player memberships', () => {
  assert.doesNotMatch(COACH_TEAM_IDS_SQL, /'player'/);
});

test('guardian scope walks the athlete chain, not team_members.user_id', () => {
  assert.match(GUARDIAN_TEAM_IDS_SQL, /JOIN guardians g ON/);
  assert.match(GUARDIAN_TEAM_IDS_SQL, /athlete_guardians/);
  assert.match(GUARDIAN_TEAM_IDS_SQL, /tm\.athlete_id = a\.id/);
});

/**
 * Identity is resolved by lib/guardian_identity.js, not by an inlined email
 * comparison: recorded user_guardians links UNION the case-insensitive email
 * match. Asserting the SHARED constant rather than the literal SQL is the point —
 * it is what keeps this query, the DM allowlist, the participant picker and
 * lib/chat_notification_scope.php on one answer. Behaviour is proved against a
 * real database in __tests__/guardian_identity.test.js.
 */
test('guardian identity comes from the shared resolver, both branches', () => {
  assert.strictEqual(
    GUARDIAN_TEAM_IDS_SQL.includes(GUARDIAN_JOIN_SQL),
    true,
    'team scope must use the shared guardian join verbatim'
  );
  assert.match(GUARDIAN_TEAM_IDS_SQL, /EXISTS \(SELECT 1 FROM user_guardians ug/);
  assert.match(GUARDIAN_TEAM_IDS_SQL, /LOWER\(g\.email\) = LOWER\(u\.email\)/);
});

test('club query is parameterised by club, not open', () => {
  assert.match(CLUB_TEAM_IDS_SQL, /WHERE club_id = \$1/);
});

// ─── Merging ──────────────────────────────────────────────────────────────────

test('team ids de-duplicate across mixed types', () => {
  // pg returns numbers, JSON carries strings; a Set of mixed types keeps both
  // and the same team appears twice in the list.
  assert.deepStrictEqual(mergeTeamIds([1, 2], ['2', 3]).sort(), [1, 2, 3]);
});

test('merging tolerates empty and missing lists', () => {
  assert.deepStrictEqual(mergeTeamIds(), []);
  assert.deepStrictEqual(mergeTeamIds(null, undefined, []), []);
});

/**
 * `Number(null)` is 0, so a finite-check lets NULL into the accessible list as
 * team 0. Caught by this test on the first run.
 */
test('unparseable ids are dropped, not coerced to zero', () => {
  assert.deepStrictEqual(mergeTeamIds([1, 'abc', null, undefined, '', 0, -3, 1.5]), [1]);
});

// ─── Regression guards on server.js ───────────────────────────────────────────

/**
 * The fix is an absence, so it has to be asserted as one: if anybody reaches for
 * canInitiateConversation here again, every coach gets the whole club back.
 */
test('getAccessibleTeamIds does not gate club-wide access on canInitiateConversation', () => {
  const body = fnBody('async function getAccessibleTeamIds', 'async function getUserConversations');
  assert.match(body, /expandsToWholeClub\(role\)/);
  const code = body.replace(/\/\/.*$/gm, '');   // strip comments; they mention it by name
  assert.doesNotMatch(code, /canInitiateConversation/);
});

test('a coach who is also a parent keeps both sets of teams', () => {
  const body = fnBody('async function getAccessibleTeamIds', 'async function getUserConversations');
  assert.match(body, /getCoachTeamIds\(userId\)/);
  assert.match(body, /getParentTeamIds\(userId\)/);
  assert.match(body, /mergeTeamIds/);
});

/**
 * getCoachTeamIds used to read scope_type === 'team' off the JWT. No token has
 * ever carried one, so it always returned [] — which is why the club-wide branch
 * was silently doing all the work. Going back to the token re-creates that.
 */
test('coach teams come from the database, not the token', () => {
  const body = fnBody('async function getCoachTeamIds', 'Ensure a team conversation exists');
  assert.match(body, /COACH_TEAM_IDS_SQL/);
  assert.doesNotMatch(body, /scope_type/);
  assert.doesNotMatch(body, /payload/);
});

/**
 * The listing bug and the join bug were the same mistake written twice. One
 * scope function now serves both, so a future fix cannot land on the list and
 * miss the door.
 */
test('team conversation access delegates to the one scope function', () => {
  const body = fnBody('async function isConversationParticipant', '\n/**');
  assert.match(body, /getAccessibleTeamIds\(userId, role, payload\)/);
  const code = body.replace(/\/\/.*$/gm, '');
  assert.doesNotMatch(code, /canInitiateConversation/);
  assert.doesNotMatch(code, /SELECT club_id FROM teams/);
});

// ─── A participant row is state, not a grant ──────────────────────────────────

/**
 * ARCHIVE_SQL and MARK_READ_SQL upsert, so OPENING a team chat leaves a
 * permanent participant row. If access consults that row, scoping a coach down
 * exempts exactly the people who already browsed other teams — 10 such rows were
 * live when this was found. Both the list and the join must ignore it for team
 * conversations.
 */
test('the conversation list gives team chats to team scope only', () => {
  const { buildConversationsQuery } = require('../lib/archive');
  const sql = buildConversationsQuery();
  assert.match(
    sql,
    /c\.type <> 'team' AND cp\.user_id IS NOT NULL/,
    'the participant branch must exclude team conversations, or a stale row re-admits one'
  );
});

test('joining a team conversation does not consult the participant row', () => {
  const body = fnBody('async function isConversationParticipant', '\n/**');
  const teamBranch = body.slice(body.indexOf('const teamId'));
  assert.doesNotMatch(
    teamBranch,
    /conversation_participants/,
    'team access must come from scope alone'
  );
  // …while DMs and groups still rely on it entirely.
  assert.match(body, /SELECT 1 FROM conversation_participants/);
});

/**
 * A team conversation belongs to the TEAM's club. Passing the viewer's club in
 * was safe only while every team list was built by club; once coaches are scoped
 * to teams they actually coach, it stamps the wrong club on the conversation —
 * and moderation and reporting are club-scoped, so that is a real association,
 * not a label. Two live teams have club_id NULL, which is how this surfaced.
 */
test('a team conversation takes its club from the team, not the viewer', () => {
  const body = fnBody('async function ensureTeamConversation', '\n/**');
  assert.match(body, /SELECT club_id FROM teams WHERE id = \$1/);
  assert.doesNotMatch(body, /clubId/);
});

/**
 * teamId arrives from the client. Role alone let a coach pull any team's roster,
 * in any club.
 */
test('getTeamMembers checks the requested team, not just the role', () => {
  const body = fnBody("socket.on('getTeamMembers'", "socket.on('typing'");
  assert.match(body, /getAccessibleTeamIds/);
  assert.match(body, /includes\(Number\(teamId\)\)/);
});
