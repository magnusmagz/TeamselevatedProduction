-- 075: Reporter role + page trail on support tickets.
--
-- Two questions the team was asking in Slack by hand on nearly every ticket:
-- "what are they, an admin or a parent?" and "where were they before this?".
-- Both are knowable at submit time, so neither should cost a round trip to the
-- reporter. See SCOPE-Support-Tickets.md.

-- Comma-joined display summary, e.g. "club_admin, coach" or
-- "parent (via guardian record)". Resolved SERVER-SIDE from the token, never
-- from the request body — a role in a request body is a claim, not a fact.
--
-- TEXT rather than an array or an FK: this is a support artifact describing what
-- the reporter was at the moment they filed, and it must stay readable after the
-- role is changed or revoked. Its only consumers are a human reading Slack and
-- the occasional "how many tickets came from parents" LIKE query.
ALTER TABLE support_tickets ADD COLUMN IF NOT EXISTS reporter_roles TEXT;

-- Up to 5 pages the reporter visited BEFORE the one they filed from, oldest
-- first: [{"path": "/teams/12", "at": "2026-08-26T14:03:11Z"}, ...].
--
-- Query strings are redacted on the client and again here — /reset-password and
-- /verify-magic-link carry live tokens in theirs, and a support trail is read by
-- more people than a session ever should be.
ALTER TABLE support_tickets ADD COLUMN IF NOT EXISTS page_trail JSONB;
