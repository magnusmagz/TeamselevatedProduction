-- Migration: 019_tournament_notification_triggers.sql
-- Description: Wire tournament events to the existing email/SMS comms pipeline.
--              Adds a `tournament_event_kind` slug to email_templates so the
--              TournamentNotificationService can look up the right template per trigger.
--              Seeds 8 platform-scoped templates (one per system trigger).
-- Created: 2026-04-29
-- Phase: 2A — wire-up of existing comms infra to tournament events
--
-- SAFETY:
--   - Additive only: ADD COLUMN IF NOT EXISTS, partial INDEX, INSERT WHERE NOT EXISTS
--   - Existing 109 email_templates rows are NOT modified
--   - Re-run safe: every statement is idempotent
--   - No DROP / RENAME / TRUNCATE anywhere

-- ============================================
-- 1. Schema change — link templates to system events
-- ============================================
-- A nullable slug. NULL for user-created / non-system templates (the existing 109).
-- Populated only for the 8 seed templates this migration adds.
ALTER TABLE email_templates
    ADD COLUMN IF NOT EXISTS tournament_event_kind VARCHAR(60);

-- Partial index — only indexes rows that actually have a kind.
-- Keeps the existing 109 NULL rows out of the index entirely.
CREATE INDEX IF NOT EXISTS idx_email_templates_tournament_kind
    ON email_templates(tournament_event_kind)
    WHERE tournament_event_kind IS NOT NULL;

-- ============================================
-- 2. Seed platform templates — one per trigger
-- ============================================
-- Each INSERT is guarded by a "WHERE NOT EXISTS" so re-running is safe.
-- club_profile_id = NULL + scope = 'platform' means: visible to every club,
-- clubs can clone & customize via the existing email-templates flow.
-- All templates use the existing {{merge_field}} syntax, including new
-- tournament_*, division_*, match_*, registration_* fields added in
-- the same release.

-- 2a. tournament.registration_accepted
INSERT INTO email_templates (
    club_profile_id, name, subject, html_output, body_text,
    category, scope, channel, is_active, tournament_event_kind,
    created_at, updated_at
)
SELECT
    NULL,
    'Tournament — Registration Accepted',
    'You''re in! {{registration_team_name}} accepted into {{tournament_name}}',
    '<p>Hi {{guardian_first_name}},</p>'
    || '<p>Great news — <strong>{{registration_team_name}}</strong> has been accepted into '
    || '<strong>{{tournament_name}}</strong> ({{division_name}}).</p>'
    || '<p><strong>Tournament dates:</strong> {{tournament_start_date}} – {{tournament_end_date}}<br>'
    || '<strong>Location:</strong> {{tournament_location}}</p>'
    || '<p>Next steps: confirm payment if not already received and watch for the schedule '
    || 'when registration closes.</p>'
    || '<p><a href="{{tournament_url}}">View tournament page</a></p>',
    E'Hi {{guardian_first_name}},\n\n'
    || E'Great news — {{registration_team_name}} has been accepted into {{tournament_name}} ({{division_name}}).\n\n'
    || E'Tournament dates: {{tournament_start_date}} – {{tournament_end_date}}\n'
    || E'Location: {{tournament_location}}\n\n'
    || E'Next steps: confirm payment if not already received and watch for the schedule when registration closes.\n\n'
    || E'View tournament page: {{tournament_url}}',
    'tournament',
    'platform',
    'email',
    true,
    'tournament.registration_accepted',
    NOW(), NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM email_templates WHERE tournament_event_kind = 'tournament.registration_accepted'
);

-- 2b. tournament.registration_declined
INSERT INTO email_templates (
    club_profile_id, name, subject, html_output, body_text,
    category, scope, channel, is_active, tournament_event_kind,
    created_at, updated_at
)
SELECT
    NULL,
    'Tournament — Registration Declined',
    'Update on your registration for {{tournament_name}}',
    '<p>Hi {{guardian_first_name}},</p>'
    || '<p>Thank you for registering <strong>{{registration_team_name}}</strong> for '
    || '<strong>{{tournament_name}}</strong>. Unfortunately we''re unable to accept this '
    || 'registration at this time.</p>'
    || '<p>If you''d like to discuss other options or future tournaments, please reach out '
    || 'to the tournament director.</p>',
    E'Hi {{guardian_first_name}},\n\n'
    || E'Thank you for registering {{registration_team_name}} for {{tournament_name}}. '
    || E'Unfortunately we''re unable to accept this registration at this time.\n\n'
    || E'If you''d like to discuss other options or future tournaments, please reach out to the tournament director.',
    'tournament',
    'platform',
    'email',
    true,
    'tournament.registration_declined',
    NOW(), NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM email_templates WHERE tournament_event_kind = 'tournament.registration_declined'
);

-- 2c. tournament.registration_waitlisted
INSERT INTO email_templates (
    club_profile_id, name, subject, html_output, body_text,
    category, scope, channel, is_active, tournament_event_kind,
    created_at, updated_at
)
SELECT
    NULL,
    'Tournament — Registration Waitlisted',
    '{{registration_team_name}} is on the waitlist for {{tournament_name}}',
    '<p>Hi {{guardian_first_name}},</p>'
    || '<p>Your registration for <strong>{{registration_team_name}}</strong> in '
    || '<strong>{{tournament_name}}</strong> has been placed on the waitlist for '
    || '{{division_name}}.</p>'
    || '<p>If a spot opens up, you''ll be the first to know — we''ll email immediately '
    || 'with an acceptance deadline. No action needed right now.</p>'
    || '<p><a href="{{tournament_url}}">View tournament page</a></p>',
    E'Hi {{guardian_first_name}},\n\n'
    || E'Your registration for {{registration_team_name}} in {{tournament_name}} has been placed on the waitlist for {{division_name}}.\n\n'
    || E'If a spot opens up, you''ll be the first to know — we''ll email immediately with an acceptance deadline. No action needed right now.\n\n'
    || E'View tournament page: {{tournament_url}}',
    'tournament',
    'platform',
    'email',
    true,
    'tournament.registration_waitlisted',
    NOW(), NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM email_templates WHERE tournament_event_kind = 'tournament.registration_waitlisted'
);

-- 2d. tournament.payment_received
INSERT INTO email_templates (
    club_profile_id, name, subject, html_output, body_text,
    category, scope, channel, is_active, tournament_event_kind,
    created_at, updated_at
)
SELECT
    NULL,
    'Tournament — Payment Received',
    'Payment received — {{registration_team_name}} confirmed for {{tournament_name}}',
    '<p>Hi {{guardian_first_name}},</p>'
    || '<p>We''ve received your payment for <strong>{{registration_team_name}}</strong>''s '
    || 'registration in <strong>{{tournament_name}}</strong>. Your spot is confirmed.</p>'
    || '<p><strong>Tournament dates:</strong> {{tournament_start_date}} – {{tournament_end_date}}<br>'
    || '<strong>Location:</strong> {{tournament_location}}</p>'
    || '<p><a href="{{tournament_url}}">View tournament page</a></p>',
    E'Hi {{guardian_first_name}},\n\n'
    || E'We''ve received your payment for {{registration_team_name}}''s registration in {{tournament_name}}. Your spot is confirmed.\n\n'
    || E'Tournament dates: {{tournament_start_date}} – {{tournament_end_date}}\n'
    || E'Location: {{tournament_location}}\n\n'
    || E'View tournament page: {{tournament_url}}',
    'tournament',
    'platform',
    'email',
    true,
    'tournament.payment_received',
    NOW(), NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM email_templates WHERE tournament_event_kind = 'tournament.payment_received'
);

-- 2e. tournament.schedule_published
INSERT INTO email_templates (
    club_profile_id, name, subject, html_output, body_text,
    category, scope, channel, is_active, tournament_event_kind,
    created_at, updated_at
)
SELECT
    NULL,
    'Tournament — Schedule Published',
    'Schedule is live — {{tournament_name}}',
    '<p>Hi {{guardian_first_name}},</p>'
    || '<p>The schedule for <strong>{{tournament_name}}</strong> is now live. '
    || 'Check your team''s match times, fields, and opponents.</p>'
    || '<p><strong>Tournament dates:</strong> {{tournament_start_date}} – {{tournament_end_date}}<br>'
    || '<strong>Location:</strong> {{tournament_location}}</p>'
    || '<p><a href="{{tournament_url}}">View full schedule</a></p>',
    E'Hi {{guardian_first_name}},\n\n'
    || E'The schedule for {{tournament_name}} is now live. Check your team''s match times, fields, and opponents.\n\n'
    || E'Tournament dates: {{tournament_start_date}} – {{tournament_end_date}}\n'
    || E'Location: {{tournament_location}}\n\n'
    || E'View full schedule: {{tournament_url}}',
    'tournament',
    'platform',
    'email',
    true,
    'tournament.schedule_published',
    NOW(), NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM email_templates WHERE tournament_event_kind = 'tournament.schedule_published'
);

-- 2f. tournament.match_rescheduled
INSERT INTO email_templates (
    club_profile_id, name, subject, html_output, body_text,
    category, scope, channel, is_active, tournament_event_kind,
    created_at, updated_at
)
SELECT
    NULL,
    'Tournament — Match Rescheduled',
    'Schedule change: {{match_home_team}} vs {{match_away_team}}',
    '<p>Hi {{guardian_first_name}},</p>'
    || '<p>Heads up — a match in <strong>{{tournament_name}}</strong> has been rescheduled:</p>'
    || '<p><strong>{{match_home_team}}</strong> vs <strong>{{match_away_team}}</strong> '
    || '({{match_round}})<br>'
    || '<strong>New kickoff:</strong> {{match_kickoff}}<br>'
    || '<strong>Field:</strong> {{match_field_name}}</p>'
    || '<p><a href="{{tournament_url}}">View full schedule</a></p>',
    E'Hi {{guardian_first_name}},\n\n'
    || E'Heads up — a match in {{tournament_name}} has been rescheduled:\n\n'
    || E'{{match_home_team}} vs {{match_away_team}} ({{match_round}})\n'
    || E'New kickoff: {{match_kickoff}}\n'
    || E'Field: {{match_field_name}}\n\n'
    || E'View full schedule: {{tournament_url}}',
    'tournament',
    'platform',
    'email',
    true,
    'tournament.match_rescheduled',
    NOW(), NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM email_templates WHERE tournament_event_kind = 'tournament.match_rescheduled'
);

-- 2g. tournament.score_posted
-- This template's body works for both email AND sms (kept short for SMS character limit).
-- The service decides which channel(s) to fire based on recipient preference.
INSERT INTO email_templates (
    club_profile_id, name, subject, html_output, body_text,
    category, scope, channel, is_active, tournament_event_kind,
    created_at, updated_at
)
SELECT
    NULL,
    'Tournament — Score Posted',
    'Final: {{match_home_team}} {{match_home_score}} – {{match_away_score}} {{match_away_team}}',
    '<p>Final score from <strong>{{tournament_name}}</strong>:</p>'
    || '<p style="font-size:18px"><strong>{{match_home_team}}</strong> {{match_home_score}} '
    || '— {{match_away_score}} <strong>{{match_away_team}}</strong></p>'
    || '<p>{{match_round}} • {{match_field_name}}</p>'
    || '<p><a href="{{tournament_url}}">View standings</a></p>',
    E'Final ({{tournament_name}}): {{match_home_team}} {{match_home_score}}–{{match_away_score}} {{match_away_team}}. Standings: {{tournament_url}}',
    'tournament',
    'platform',
    'email',
    true,
    'tournament.score_posted',
    NOW(), NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM email_templates WHERE tournament_event_kind = 'tournament.score_posted'
);

-- 2h. tournament.weather_delay
INSERT INTO email_templates (
    club_profile_id, name, subject, html_output, body_text,
    category, scope, channel, is_active, tournament_event_kind,
    created_at, updated_at
)
SELECT
    NULL,
    'Tournament — Weather Delay',
    'Weather delay — {{tournament_name}}',
    '<p>Hi everyone,</p>'
    || '<p><strong>{{tournament_name}}</strong> is currently in a weather delay. '
    || 'Please stay clear of the fields and watch for the next update.</p>'
    || '<p><a href="{{tournament_url}}">Live updates</a></p>',
    E'{{tournament_name}}: WEATHER DELAY in effect. Stay clear of fields. Updates: {{tournament_url}}',
    'tournament',
    'platform',
    'email',
    true,
    'tournament.weather_delay',
    NOW(), NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM email_templates WHERE tournament_event_kind = 'tournament.weather_delay'
);

-- ============================================
-- Verification (visible in psql output, not destructive)
-- ============================================
-- Expect: 8 rows
SELECT tournament_event_kind, name, scope, channel, is_active
FROM email_templates
WHERE tournament_event_kind IS NOT NULL
ORDER BY tournament_event_kind;
