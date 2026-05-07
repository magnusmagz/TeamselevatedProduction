-- Migration: 034_waitlist_auto_cascade.sql
-- Description: Wire automatic waitlist cascade.
--
--              When a team withdraws (or an accepted spot otherwise opens),
--              the next-in-line waitlisted team gets emailed an offer with
--              an acceptance deadline. Decline / no-response cascades to
--              the next team. The existing `status='waitlisted'` keeps its
--              meaning; the new columns layer offer-state on top so the
--              workflow is auditable.
--
--              Also seeds three platform-default email templates that the
--              cascade triggers (offer / accepted / expired).
--
-- Created: 2026-05-07
-- Phase: Module 2 — registration polish (waitlist cascade, all phases)
--
-- SAFETY: additive nullable columns + seed inserts that no-op on conflict.
-- No destructive changes. Idempotent.

-- 1. Columns on tournament_registrations
ALTER TABLE tournament_registrations
    ADD COLUMN IF NOT EXISTS waitlist_position INTEGER;

ALTER TABLE tournament_registrations
    ADD COLUMN IF NOT EXISTS waitlist_offered_at TIMESTAMPTZ;

ALTER TABLE tournament_registrations
    ADD COLUMN IF NOT EXISTS waitlist_offer_expires_at TIMESTAMPTZ;

ALTER TABLE tournament_registrations
    ADD COLUMN IF NOT EXISTS waitlist_offer_state VARCHAR(20) NOT NULL DEFAULT 'none';

ALTER TABLE tournament_registrations
    ADD COLUMN IF NOT EXISTS waitlist_offer_token VARCHAR(64);

-- Index for fast "find next eligible row" lookups during cascade.
CREATE INDEX IF NOT EXISTS idx_tournament_registrations_waitlist_queue
    ON tournament_registrations (division_id, waitlist_position)
    WHERE status = 'waitlisted';

-- Lookup index for token-based response endpoint (public, no auth).
CREATE INDEX IF NOT EXISTS idx_tournament_registrations_waitlist_token
    ON tournament_registrations (waitlist_offer_token)
    WHERE waitlist_offer_token IS NOT NULL;

-- 2. Backfill waitlist_position for existing waitlisted rows, ordered by
--    created_at within each division so the implicit FIFO becomes explicit.
WITH ranked AS (
    SELECT id,
           ROW_NUMBER() OVER (PARTITION BY division_id ORDER BY created_at, id) AS rn
    FROM tournament_registrations
    WHERE status = 'waitlisted'
      AND waitlist_position IS NULL
)
UPDATE tournament_registrations tr
SET waitlist_position = ranked.rn
FROM ranked
WHERE tr.id = ranked.id;

-- 3. Seed platform-default email templates for the three cascade events.
--    `cloned_from = NULL` + `scope = 'platform'` matches the pattern from
--    migration 019. Inserts are idempotent via NOT EXISTS guards on
--    tournament_event_kind + scope.

INSERT INTO email_templates (name, subject, html_output, body_text, channel, scope, tournament_event_kind, is_active, club_profile_id, created_at, updated_at)
SELECT
    'Tournament — Waitlist Offer',
    'A spot is available — {{tournament_name}} ({{division_name}})',
    '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;color:#1a1a1a;max-width:560px;margin:0 auto;padding:24px">'
    || '<h2 style="color:#1a56db">A spot is available</h2>'
    || '<p>Hi {{recipient_first_name}},</p>'
    || '<p>A spot has opened up in <strong>{{tournament_name}}</strong> for the <strong>{{division_name}}</strong> division. '
    || 'Your team {{team_name}} is next on the waitlist.</p>'
    || '<p style="background:#fef3c7;border-left:4px solid #f59e0b;padding:12px;margin:16px 0">'
    || '<strong>Respond by {{offer_expires_at}}.</strong> If we don''t hear back by then, the spot goes to the next team on the waitlist.'
    || '</p>'
    || '<p style="text-align:center;margin:24px 0">'
    || '<a href="{{accept_url}}" style="background:#16a34a;color:#fff;padding:12px 24px;text-decoration:none;border-radius:6px;font-weight:600;margin-right:8px">Accept Spot</a>'
    || '<a href="{{decline_url}}" style="background:#fff;color:#dc2626;padding:12px 24px;text-decoration:none;border-radius:6px;font-weight:600;border:1px solid #dc2626">Decline</a>'
    || '</p>'
    || '<p style="color:#6b7280;font-size:13px">Tournament: {{tournament_name}} · {{tournament_start_date}} – {{tournament_end_date}}<br>'
    || 'Division: {{division_name}} ({{division_age_group}} {{division_gender}})</p>'
    || '<p style="color:#9ca3af;font-size:12px;margin-top:24px;border-top:1px solid #e5e7eb;padding-top:12px">'
    || 'Sent because your team is on the waitlist for {{tournament_name}}. {{unsubscribe_link}}</p>'
    || '</body></html>',
    'A spot has opened up in ' || E'\n'
    || '{{tournament_name}} for the {{division_name}} division. Your team {{team_name}} is next on the waitlist.' || E'\n\n'
    || 'Respond by {{offer_expires_at}}. If we do not hear back by then, the spot goes to the next team.' || E'\n\n'
    || 'Accept: {{accept_url}}' || E'\n'
    || 'Decline: {{decline_url}}' || E'\n\n'
    || 'Tournament: {{tournament_name}} ({{tournament_start_date}} – {{tournament_end_date}})' || E'\n'
    || 'Division: {{division_name}}',
    'email', 'platform', 'tournament.waitlist_offer', true, NULL, NOW(), NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM email_templates
    WHERE tournament_event_kind = 'tournament.waitlist_offer' AND scope = 'platform'
);

INSERT INTO email_templates (name, subject, html_output, body_text, channel, scope, tournament_event_kind, is_active, club_profile_id, created_at, updated_at)
SELECT
    'Tournament — Waitlist Spot Confirmed',
    'You''re in — {{tournament_name}} ({{division_name}})',
    '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;color:#1a1a1a;max-width:560px;margin:0 auto;padding:24px">'
    || '<h2 style="color:#16a34a">You''re in!</h2>'
    || '<p>Hi {{recipient_first_name}},</p>'
    || '<p>Great news — <strong>{{team_name}}</strong> is now confirmed for <strong>{{tournament_name}}</strong> ({{division_name}}).</p>'
    || '<p>What happens next:</p>'
    || '<ul>'
    || '<li>Watch your email for the schedule when matches are published</li>'
    || '<li>Make sure your roster and required documents are submitted before the deadline</li>'
    || '<li>Pay any outstanding entry fee if you haven''t already</li>'
    || '</ul>'
    || '<p style="color:#6b7280;font-size:13px">Tournament: {{tournament_name}} · {{tournament_start_date}} – {{tournament_end_date}}<br>'
    || 'Venue: {{venue_name}}</p>'
    || '<p style="color:#9ca3af;font-size:12px;margin-top:24px;border-top:1px solid #e5e7eb;padding-top:12px">{{unsubscribe_link}}</p>'
    || '</body></html>',
    'You are confirmed for {{tournament_name}} ({{division_name}}).' || E'\n\n'
    || 'Watch for the schedule email when matches are published, and make sure your roster + documents are in.' || E'\n\n'
    || 'Tournament: {{tournament_name}} ({{tournament_start_date}} – {{tournament_end_date}})' || E'\n'
    || 'Venue: {{venue_name}}',
    'email', 'platform', 'tournament.waitlist_accepted', true, NULL, NOW(), NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM email_templates
    WHERE tournament_event_kind = 'tournament.waitlist_accepted' AND scope = 'platform'
);

INSERT INTO email_templates (name, subject, html_output, body_text, channel, scope, tournament_event_kind, is_active, club_profile_id, created_at, updated_at)
SELECT
    'Tournament — Waitlist Offer Expired',
    'Waitlist offer expired — {{tournament_name}}',
    '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;color:#1a1a1a;max-width:560px;margin:0 auto;padding:24px">'
    || '<h2 style="color:#6b7280">Offer expired</h2>'
    || '<p>Hi {{recipient_first_name}},</p>'
    || '<p>The waitlist offer for <strong>{{team_name}}</strong> at <strong>{{tournament_name}}</strong> ({{division_name}}) expired without a response, so the spot has been offered to the next team on the waitlist.</p>'
    || '<p>If a spot opens up again, we''ll reach out — your team stays on the waitlist.</p>'
    || '<p style="color:#9ca3af;font-size:12px;margin-top:24px;border-top:1px solid #e5e7eb;padding-top:12px">{{unsubscribe_link}}</p>'
    || '</body></html>',
    'The waitlist offer for {{team_name}} at {{tournament_name}} ({{division_name}}) expired without a response. The spot has been offered to the next team. Your team stays on the waitlist.',
    'email', 'platform', 'tournament.waitlist_offer_expired', true, NULL, NOW(), NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM email_templates
    WHERE tournament_event_kind = 'tournament.waitlist_offer_expired' AND scope = 'platform'
);
