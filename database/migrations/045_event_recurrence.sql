-- 045_event_recurrence.sql
-- Recurring calendar events. Occurrences are MATERIALIZED at creation time
-- (one calendar_events row each) so RSVP, invites, attendance, and iCal all
-- keep working per-event with no scheduler. These columns tie a series
-- together so it can be displayed and bulk-deleted ("this and future").
-- Additive + nullable: safe to apply before the code deploy.

ALTER TABLE calendar_events ADD COLUMN IF NOT EXISTS recurrence_group_id VARCHAR(36);
ALTER TABLE calendar_events ADD COLUMN IF NOT EXISTS recurrence_rule VARCHAR(160);

CREATE INDEX IF NOT EXISTS idx_calendar_events_recurrence_group
    ON calendar_events(recurrence_group_id)
    WHERE recurrence_group_id IS NOT NULL;
