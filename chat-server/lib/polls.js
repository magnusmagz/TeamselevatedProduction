'use strict';

/**
 * Polls in chat. Scope: docs/chat-polls-scope.md
 *
 * A poll is for CHOOSING BETWEEN OPTIONS — "team dinner Friday at 7 or Thursday
 * at 6:30". Deliberately NOT a signup sheet: "who is bringing oranges" is
 * assignment, where each answer is a commitment and the useful view is names
 * against tasks (Maggie, 2026-08-28).
 */

const MIN_OPTIONS = 2;
const MAX_OPTIONS = 10;
const MAX_QUESTION = 300;
const MAX_LABEL = 100;

/**
 * Who may create a poll: coaches and club admins.
 *
 * ⚠️ Its OWN predicate, deliberately. `canModerate()` is admin-only and excludes
 * coaches; `canInitiateConversation()` includes parents. Neither list is right,
 * and reusing one because it is close is the "which predicate got called"
 * mistake CLAUDE.md records four times over.
 */
const POLL_CREATOR_ROLES = ['super_admin', 'owner', 'club_admin', 'admin', 'coach'];

function canCreatePoll(role) {
  return POLL_CREATOR_ROLES.includes(role);
}

/**
 * Validate what the composer sent.
 *
 * Returns `{ ok: true, poll }` or `{ ok: false, error }` — a message a person
 * can act on, not a code.
 */
function validatePoll(input) {
  const question = String((input && input.question) || '').trim();
  if (!question) return { ok: false, error: 'Give the poll a question' };
  if (question.length > MAX_QUESTION) {
    return { ok: false, error: `Keep the question under ${MAX_QUESTION} characters` };
  }

  const rawOptions = Array.isArray(input && input.options) ? input.options : [];
  const options = rawOptions
    .map((o) => String(o == null ? '' : o).trim())
    .filter((o) => o.length > 0);

  if (options.length < MIN_OPTIONS) {
    return { ok: false, error: 'A poll needs at least two options' };
  }
  if (options.length > MAX_OPTIONS) {
    return { ok: false, error: `A poll can have at most ${MAX_OPTIONS} options` };
  }
  if (options.some((o) => o.length > MAX_LABEL)) {
    return { ok: false, error: `Keep each option under ${MAX_LABEL} characters` };
  }

  // Duplicates are a mistake, not a choice: two identical options split the vote
  // and make the result unreadable.
  const seen = new Set(options.map((o) => o.toLowerCase()));
  if (seen.size !== options.length) {
    return { ok: false, error: 'Two options are the same' };
  }

  let closesAt = null;
  if (input && input.closesAt) {
    const when = new Date(input.closesAt);
    if (Number.isNaN(when.getTime())) {
      return { ok: false, error: 'That closing time is not a date' };
    }
    closesAt = when.toISOString();
  }

  return {
    ok: true,
    poll: {
      question,
      options,
      closesAt,
      isAnonymous: Boolean(input && input.isAnonymous),
      resultsBeforeVote: (input && input.resultsBeforeVote) !== false,
      allowMultiple: Boolean(input && input.allowMultiple),
    },
  };
}

/** Closed is a comparison, never a stored status a worker has to maintain. */
function isClosed(poll, now = new Date()) {
  if (!poll || !poll.closesAt) return false;
  return new Date(poll.closesAt).getTime() <= now.getTime();
}

const POLL_FOR_MESSAGES_SQL = `
  SELECT p.id, p.message_id AS "messageId", p.question,
         p.is_anonymous        AS "isAnonymous",
         p.results_before_vote AS "resultsBeforeVote",
         p.allow_multiple      AS "allowMultiple",
         p.closes_at           AS "closesAt",
         p.created_by          AS "createdBy"
    FROM chat_polls p
   WHERE p.message_id = ANY($1::int[])
`;

const OPTIONS_WITH_VOTES_SQL = `
  SELECT o.id, o.poll_id AS "pollId", o.label, o.sort_order AS "sortOrder",
         v.user_id AS "voterId",
         COALESCE(NULLIF(TRIM(u.first_name), ''), 'Someone') AS "voterName"
    FROM chat_poll_options o
    LEFT JOIN chat_poll_votes v ON v.option_id = o.id
    LEFT JOIN users u ON u.id = v.user_id
   WHERE o.poll_id = ANY($1::int[])
   ORDER BY o.sort_order, o.id, v.created_at
`;

/**
 * Build what a client renders, for one viewer.
 *
 * ⚠️ **`voters` is omitted entirely for an anonymous poll** — not blanked, not
 * sent-then-hidden. The only reliable way to keep an anonymous vote anonymous is
 * for the identity never to leave the server. `youVoted` still works, because
 * that is about the viewer's own vote and tells them nothing about anyone else.
 *
 * ⚠️ Ids as STRINGS, matching every other chat payload. The string/number
 * mismatch produced three visible bugs on 2026-08-26.
 */
function buildPollView(poll, optionRows, viewerId, now = new Date()) {
  const closed = isClosed(poll, now);

  const byOption = new Map();
  for (const row of optionRows) {
    if (!byOption.has(row.id)) {
      byOption.set(row.id, {
        id: String(row.id),
        label: row.label,
        votes: 0,
        voters: [],
        youVoted: false,
      });
    }
    const option = byOption.get(row.id);
    if (row.voterId != null) {
      option.votes += 1;
      option.voters.push({ id: String(row.voterId), name: row.voterName });
      if (viewerId != null && String(row.voterId) === String(viewerId)) {
        option.youVoted = true;
      }
    }
  }

  const options = [...byOption.values()];
  const youVoted = options.some((o) => o.youVoted);
  const totalVotes = options.reduce((sum, o) => sum + o.votes, 0);

  // Hidden until you vote, if the creator chose that — and always revealed once
  // the poll closes, since there is nothing left to influence.
  const showResults = poll.resultsBeforeVote || youVoted || closed;

  return {
    id: String(poll.id),
    question: poll.question,
    isAnonymous: poll.isAnonymous,
    allowMultiple: poll.allowMultiple,
    closesAt: poll.closesAt,
    closed,
    youVoted,
    showResults,
    totalVotes: showResults ? totalVotes : null,
    options: options.map((o) => ({
      id: o.id,
      label: o.label,
      youVoted: o.youVoted,
      votes: showResults ? o.votes : null,
      // Never leaves the server for an anonymous poll.
      voters: !poll.isAnonymous && showResults ? o.voters : undefined,
    })),
  };
}

module.exports = {
  POLL_CREATOR_ROLES,
  canCreatePoll,
  validatePoll,
  isClosed,
  buildPollView,
  POLL_FOR_MESSAGES_SQL,
  OPTIONS_WITH_VOTES_SQL,
  MIN_OPTIONS,
  MAX_OPTIONS,
};
