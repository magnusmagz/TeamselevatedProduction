'use strict';

/**
 * Reporting a chat message.
 *
 * A report is two things at once: a work item for the club admin queue, and the
 * authorisation record that lets that admin read the conversation (M3). Admin
 * read is flag-gated — an open report is the reason a thread can be opened, so
 * this table is a permission grant, not just a to-do list.
 *
 * Human reports and automated flags share one table and one queue so admins have
 * a single inbox. `source` is the only thing that differs.
 */

const VALID_SEVERITIES = ['low', 'medium', 'high'];

/**
 * Reasons a person can pick. Free text goes in `note`; this stays a closed set so
 * the queue can be sorted and counted, and so a compliance summary has something
 * to aggregate.
 */
const REPORT_REASONS = [
  'inappropriate',
  'harassment',
  'safety_concern',
  'personal_information',
  'spam',
  'other',
];

/** Safety concerns jump the queue; everything else is routine. */
function severityForReason(reason) {
  return reason === 'safety_concern' || reason === 'harassment' ? 'high' : 'medium';
}

function isValidReason(reason) {
  return REPORT_REASONS.includes(reason);
}

/**
 * File a report.
 *
 * ON CONFLICT DO NOTHING against the partial unique index: one person reporting
 * the same message twice is not two problems, and a re-report must not resurrect
 * an item an admin already dismissed. Returns no row when it was a duplicate,
 * which the caller reports back as success — the reporter should not be told
 * whether someone else already flagged it.
 *
 *   $1 message id, $2 conversation id, $3 club id, $4 reporter, $5 reason,
 *   $6 note, $7 severity
 */
const FILE_USER_REPORT_SQL = `
  INSERT INTO chat_message_reports
    (message_id, conversation_id, club_id, source, reported_by, rule, severity, note, status)
  VALUES ($1, $2, $3, 'user', $4, $5, $7, $6, 'open')
  ON CONFLICT DO NOTHING
  RETURNING id, severity
`;

/**
 * Where a reported message lives, and whether the reporter can see it at all.
 * Reporting is not a way to probe for the existence of messages in other clubs.
 */
const REPORT_SCOPE_SQL = `
  SELECT m.id, m.conversation_id AS "conversationId", m.sender_id AS "senderId",
         m.deleted_at AS "deletedAt", c.club_id AS "clubId", c.type AS "conversationType",
         c.team_id AS "teamId"
  FROM chat_messages m
  LEFT JOIN conversations c ON c.id = m.conversation_id
  WHERE m.id = $1
`;

/** Open-report count for a club, for the queue badge. */
const OPEN_REPORT_COUNT_SQL = `
  SELECT count(*)::int AS open_count
  FROM chat_message_reports
  WHERE club_id = $1 AND status = 'open'
`;

module.exports = {
  VALID_SEVERITIES,
  REPORT_REASONS,
  severityForReason,
  isValidReason,
  FILE_USER_REPORT_SQL,
  REPORT_SCOPE_SQL,
  OPEN_REPORT_COUNT_SQL,
};
