-- Migration 037: Event attendance tracking (per-athlete, per-event)
--
-- Problem: api/event-attendance.php reads from and upserts into a table named
-- `event_attendance`, and the frontend AttendanceModal (opened via the calendar's
-- "Take Attendance" button) drives that endpoint. But the table was never created
-- by any migration — it only existed implicitly in code. As a result every save
-- (INSERT ... ON CONFLICT) and every summary query (COUNT FILTER) failed with
-- "relation event_attendance does not exist", so attendance silently never worked.
--
-- This migration creates the table to match what the endpoint expects, and uses
-- a 4-value status (present / absent / late / excused) per the product spec.
-- The upsert key is (event_id, athlete_id), matching the endpoint's ON CONFLICT.
--
-- Additive only (CREATE TABLE IF NOT EXISTS) — safe for the demo-data prod DB.

CREATE TABLE IF NOT EXISTS event_attendance (
    id          SERIAL PRIMARY KEY,
    event_id    INTEGER NOT NULL REFERENCES calendar_events(id) ON DELETE CASCADE,
    athlete_id  INTEGER NOT NULL REFERENCES athletes(id) ON DELETE CASCADE,
    status      VARCHAR(20) NOT NULL DEFAULT 'present'
                CHECK (status IN ('present', 'absent', 'late', 'excused')),
    notes       TEXT,
    marked_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
    marked_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_event_attendance UNIQUE (event_id, athlete_id)
);

CREATE INDEX IF NOT EXISTS idx_event_attendance_event ON event_attendance(event_id);
CREATE INDEX IF NOT EXISTS idx_event_attendance_athlete ON event_attendance(athlete_id);
