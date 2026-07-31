'use strict';

/**
 * Audit writer for the chat server.
 *
 * CLAUDE.md says to write `audit_log` through `lib/AuditLogger.php` and never a
 * raw INSERT. The chat server is Node and cannot require a PHP class, so this
 * mirrors that class's statement exactly — same columns, same order, same JSON
 * `details`. `AuditShapeTest` pins the two together by parsing both files; if the
 * PHP class gains a column and this does not, that test fails.
 *
 * ─── One deliberate difference from AuditLogger ───────────────────────────────
 * AuditLogger swallows its own failures, because "auditing must never break the
 * operation it records" — right for a guardian confirming consent, where the
 * user action must not fail on an audit outage.
 *
 * Moderation removal is the opposite case. The audit entry IS the accountability
 * for an admin reaching into someone else's conversation, so `logInTransaction`
 * throws and the caller rolls the removal back. A removal nobody can reconstruct
 * is indistinguishable from an admin quietly deleting evidence — the same
 * reasoning already applied to retention purges in scripts/retention-check.php.
 */

const AUDIT_INSERT_SQL = `
  INSERT INTO audit_log
    (user_id, action, resource_type, resource_id, ip_address, user_agent, details, created_at)
  VALUES ($1, $2, $3, $4, $5, $6, $7, NOW())
`;

/**
 * Write an audit row on the supplied client. Throws on failure — call it inside
 * the same transaction as the operation it records.
 *
 * @param {object} client  a pg client already inside a transaction
 */
async function logInTransaction(client, {
  userId = null,
  action,
  resourceType = null,
  resourceId = null,
  ipAddress = null,
  userAgent = null,
  details = null,
}) {
  if (!action) throw new Error('audit: action is required');
  await client.query(AUDIT_INSERT_SQL, [
    userId,
    action,
    resourceType,
    resourceId,
    ipAddress,
    userAgent,
    details ? JSON.stringify(details) : null,
  ]);
}

/**
 * The client address behind Heroku's router, for the audit row.
 * `x-forwarded-for` is a comma-separated chain; the first entry is the client.
 */
function socketIp(socket) {
  const fwd = socket?.handshake?.headers?.['x-forwarded-for'];
  if (typeof fwd === 'string' && fwd.length > 0) return fwd.split(',')[0].trim();
  return socket?.handshake?.address || null;
}

function socketUserAgent(socket) {
  return socket?.handshake?.headers?.['user-agent'] || null;
}

module.exports = { AUDIT_INSERT_SQL, logInTransaction, socketIp, socketUserAgent };
