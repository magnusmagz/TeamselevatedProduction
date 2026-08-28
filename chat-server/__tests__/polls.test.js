'use strict';

const test = require('node:test');
const assert = require('node:assert');
const {
  canCreatePoll, validatePoll, isClosed, buildPollView, POLL_CREATOR_ROLES,
} = require('../lib/polls');

/**
 * Polls. Scope and decisions: docs/chat-polls-scope.md
 *
 * A poll is for CHOOSING BETWEEN OPTIONS — "team dinner Friday at 7 or Thursday
 * at 6:30". Deliberately not a signup sheet.
 */

// ── who ──────────────────────────────────────────────────────────────────────

test('coaches and club admins can create a poll; families cannot', () => {
  assert.strictEqual(canCreatePoll('coach'), true);
  assert.strictEqual(canCreatePoll('club_admin'), true);
  assert.strictEqual(canCreatePoll('super_admin'), true);

  assert.strictEqual(canCreatePoll('parent'), false);
  assert.strictEqual(canCreatePoll('player'), false);
  assert.strictEqual(canCreatePoll(undefined), false);
});

/**
 * ⚠️ Its own list, deliberately. canModerate() excludes coaches and
 * canInitiateConversation() includes parents — reusing either because it is
 * close is the "which predicate got called" mistake CLAUDE.md records four
 * times over.
 */
test('the creator list is neither the moderator list nor the conversation list', () => {
  const { canModerate } = require('../lib/moderation');

  assert.strictEqual(canModerate('coach'), false, 'moderation excludes coaches');
  assert.strictEqual(canCreatePoll('coach'), true, 'polls include them');
  assert.ok(!POLL_CREATOR_ROLES.includes('parent'), 'and parents are not in ours');
});

// ── what ─────────────────────────────────────────────────────────────────────

test('a poll needs a question and at least two options', () => {
  assert.strictEqual(validatePoll({ question: '', options: ['a', 'b'] }).ok, false);
  assert.strictEqual(validatePoll({ question: 'When?', options: ['a'] }).ok, false);
  assert.strictEqual(validatePoll({ question: 'When?', options: [] }).ok, false);
  assert.strictEqual(validatePoll({ question: 'When?', options: ['Fri 7', 'Thu 6:30'] }).ok, true);
});

test('blank options are dropped rather than counted', () => {
  const result = validatePoll({ question: 'When?', options: ['Fri', '  ', 'Thu', ''] });
  assert.strictEqual(result.ok, true);
  assert.deepStrictEqual(result.poll.options, ['Fri', 'Thu']);
});

/** Two identical options split the vote and make the result unreadable. */
test('duplicate options are refused', () => {
  const result = validatePoll({ question: 'When?', options: ['Friday', 'friday'] });
  assert.strictEqual(result.ok, false);
  assert.match(result.error, /same/i);
});

test('a nonsense closing time is refused, and none is fine', () => {
  assert.strictEqual(validatePoll({ question: 'q', options: ['a', 'b'], closesAt: 'soon' }).ok, false);
  assert.strictEqual(validatePoll({ question: 'q', options: ['a', 'b'] }).poll.closesAt, null);
});

test('results are shown before voting unless the creator says otherwise', () => {
  assert.strictEqual(validatePoll({ question: 'q', options: ['a', 'b'] }).poll.resultsBeforeVote, true);
  assert.strictEqual(
    validatePoll({ question: 'q', options: ['a', 'b'], resultsBeforeVote: false }).poll.resultsBeforeVote,
    false
  );
});

// ── closing ──────────────────────────────────────────────────────────────────

test('closed is a comparison, not a stored status', () => {
  const now = new Date('2026-08-28T12:00:00Z');
  assert.strictEqual(isClosed({ closesAt: null }, now), false);
  assert.strictEqual(isClosed({ closesAt: '2026-08-28T13:00:00Z' }, now), false);
  assert.strictEqual(isClosed({ closesAt: '2026-08-28T11:59:59Z' }, now), true);
});

// ── what a viewer sees ───────────────────────────────────────────────────────

const poll = (over = {}) => ({
  id: 5, question: 'Team dinner?', isAnonymous: false,
  resultsBeforeVote: true, allowMultiple: false, closesAt: null, ...over,
});

const rows = [
  { id: 1, pollId: 5, label: 'Friday 7', sortOrder: 0, voterId: 74, voterName: 'Lisa' },
  { id: 1, pollId: 5, label: 'Friday 7', sortOrder: 0, voterId: 96, voterName: 'David' },
  { id: 2, pollId: 5, label: 'Thursday 6:30', sortOrder: 1, voterId: null, voterName: null },
];

test('counts votes and knows which one is yours', () => {
  const view = buildPollView(poll(), rows, 96);

  assert.strictEqual(view.totalVotes, 2);
  assert.strictEqual(view.options[0].votes, 2);
  assert.strictEqual(view.options[0].youVoted, true);
  assert.strictEqual(view.options[1].votes, 0);
  assert.strictEqual(view.youVoted, true);
});

/**
 * ⚠️ THE ONE THAT MATTERS. An anonymous poll must not send voter identities to
 * anyone — not blanked, not sent-and-hidden. The only reliable way to keep a
 * vote anonymous is for the identity never to leave the server.
 */
test('an anonymous poll never sends who voted', () => {
  const view = buildPollView(poll({ isAnonymous: true }), rows, 96);

  for (const option of view.options) {
    assert.strictEqual(option.voters, undefined, 'voters must not be present at all');
  }
  // Their own vote still resolves — that tells them nothing about anyone else.
  assert.strictEqual(view.options[0].youVoted, true);
  assert.strictEqual(view.totalVotes, 2);
});

test('a named poll says who voted', () => {
  const view = buildPollView(poll(), rows, 96);
  assert.deepStrictEqual(view.options[0].voters.map((v) => v.name), ['Lisa', 'David']);
});

test('results stay hidden until you vote when the creator chose that', () => {
  const hidden = buildPollView(poll({ resultsBeforeVote: false }), rows, 999);
  assert.strictEqual(hidden.showResults, false);
  assert.strictEqual(hidden.totalVotes, null);
  assert.strictEqual(hidden.options[0].votes, null);

  const voted = buildPollView(poll({ resultsBeforeVote: false }), rows, 96);
  assert.strictEqual(voted.showResults, true);
  assert.strictEqual(voted.options[0].votes, 2);
});

/** Once it is closed there is nothing left to influence. */
test('closing reveals results to everyone', () => {
  const view = buildPollView(
    poll({ resultsBeforeVote: false, closesAt: '2020-01-01T00:00:00Z' }), rows, 999
  );
  assert.strictEqual(view.closed, true);
  assert.strictEqual(view.showResults, true);
  assert.strictEqual(view.totalVotes, 2);
});

/**
 * Ids as strings, matching every other chat payload. The string/number mismatch
 * produced three visible bugs on 2026-08-26.
 */
test('ids come back as strings', () => {
  const view = buildPollView(poll(), rows, 96);
  assert.strictEqual(typeof view.id, 'string');
  assert.strictEqual(typeof view.options[0].id, 'string');
  assert.strictEqual(typeof view.options[0].voters[0].id, 'string');
});

test('a viewer who has not voted is not mistaken for one who has', () => {
  const view = buildPollView(poll(), rows, 12345);
  assert.strictEqual(view.youVoted, false);
  assert.ok(view.options.every((o) => o.youVoted === false));
});

// ── picking more than one ────────────────────────────────────────────────────

test('a multiple-choice poll is carried through from the composer', () => {
  const single = validatePoll({ question: 'q', options: ['a', 'b'] });
  assert.strictEqual(single.poll.allowMultiple, false, 'single by default');

  const multi = validatePoll({ question: 'q', options: ['a', 'b'], allowMultiple: true });
  assert.strictEqual(multi.poll.allowMultiple, true);
});

test('the view tells the client which kind it is', () => {
  const rows = [
    { id: 1, pollId: 5, label: 'Friday', sortOrder: 0, voterId: 74, voterName: 'Lisa' },
    { id: 2, pollId: 5, label: 'Thursday', sortOrder: 1, voterId: 74, voterName: 'Lisa' },
  ];
  const view = buildPollView({
    id: 5, question: 'Which nights suit?', isAnonymous: false,
    resultsBeforeVote: true, allowMultiple: true, closesAt: null,
  }, rows, 74);

  assert.strictEqual(view.allowMultiple, true);
  // One person, two picks. The total counts VOTES rather than people, which is
  // why the UI says "pick as many as you like" beside it.
  assert.strictEqual(view.totalVotes, 2);
  assert.ok(view.options.every((o) => o.youVoted));
});
