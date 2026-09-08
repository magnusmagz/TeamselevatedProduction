-- 100_calendar_event_field.sql
--
-- A game is played on a FIELD, not merely at a venue (Maggie, 2026-09-08).
-- calendar_events already records `venue_id` and a free-text `location`; this
-- adds the one fact code cannot derive — which of the venue's pitches — so the
-- calendar, the team schedule, the lineup sheet, the parent portal and the
-- referee's page can all say "North Park · Field 2", and the game form can
-- steer a U10 side to a 7v7 grid (lib/field_size.php, migration 088).
--
-- Additive only. NULL means "no field chosen", which every existing row is.
-- The FK is ON DELETE SET NULL: fields are hard-deleted when a venue is
-- re-saved (legacy/venues-gateway.php PUT deletes and re-inserts them), and a
-- game must survive its pitch being renamed out from under it — losing the
-- field is right, losing the game is not.
--
-- The application validates that the field belongs to the event's venue
-- (lib/event_field.php, te_event_field_validate); the database does not,
-- because `fields.venue_id` is not part of a unique key it could reference.
--
-- REVERSE:
--   DROP INDEX IF EXISTS idx_calendar_events_field_id;
--   ALTER TABLE calendar_events DROP COLUMN IF EXISTS field_id;

ALTER TABLE calendar_events
    ADD COLUMN IF NOT EXISTS field_id INTEGER NULL REFERENCES fields(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_calendar_events_field_id ON calendar_events (field_id);

COMMENT ON COLUMN calendar_events.field_id IS
    'The field (pitch) under venue_id this event is played on; NULL = none chosen. Must belong to venue_id (enforced in lib/event_field.php).';
