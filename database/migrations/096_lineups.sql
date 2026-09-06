-- 096_lineups.sql
--
-- Lineup builder (CKU R67, slice 8.5). Spec: docs/lineup-builder-spec-2026-09.md.
--
-- A coach sets who starts, where they play and who is on the bench for a game,
-- from a phone, and keeps a template so the next game does not start from
-- nothing. Two tables; every input the builder needs is already recorded
-- (team_members for the roster, calendar_events + calendar_event_teams for the
-- game, event_attendance for who is here).
--
-- ADDITIVE ONLY. Nothing existing is altered. `main` is shared and deploys are
-- by push, so api/lineups.php reaches production before this file is applied
-- by hand. lib/lineups.php probes for the tables and answers 503 with a
-- sentence until then — same shape as lib/referee_feedback.php.
--
-- lineups
--   calendar_event_id  NULL means the team's TEMPLATE lineup ("Default"); a
--                      value means the lineup for that game. ON DELETE CASCADE:
--                      a lineup for a game that no longer exists has nothing to
--                      be about. One template per team is enforced in code, not
--                      by constraint, so a second template can be added later.
--   field_size         Copied at creation from the team's age group
--                      (lib/field_size.php) so a later age-group edit does not
--                      silently re-shape a saved lineup. Same CHECK list as
--                      fields.field_size (migration 088).
--   formation          Validated in code against lib/lineup_formations.php for
--                      the field size; free text here so a new preset needs no
--                      migration.
--   published_at       Decision 1: a coach may publish a game's lineup to the
--                      families (opt-in, per game). NULL = staff only.
--
-- lineup_slots
--   slot               A slot code from the formation preset (GK, D1, M2, …)
--                      or BENCH. Unique per lineup for field slots only.
--   sort_order         Bench order. Staff-only data; the crew view never gets it.
--   note               Short, staff-only ("left foot", "first sub for CB").
--
-- REVERSE:
--   DROP TABLE IF EXISTS lineup_slots;
--   DROP TABLE IF EXISTS lineups;

CREATE TABLE IF NOT EXISTS lineups (
    id                 SERIAL PRIMARY KEY,
    club_id            INTEGER NULL REFERENCES club_profile(id),
    team_id            INTEGER NOT NULL REFERENCES teams(id),
    calendar_event_id  INTEGER NULL REFERENCES calendar_events(id) ON DELETE CASCADE,
    name               TEXT NOT NULL DEFAULT 'Default' CHECK (length(name) BETWEEN 1 AND 120),
    formation          TEXT NOT NULL CHECK (length(formation) BETWEEN 1 AND 20),
    field_size         TEXT NOT NULL CHECK (field_size IN ('4v4', '7v7', '9v9', '11v11')),
    published_at       TIMESTAMPTZ NULL,
    created_by         INTEGER NULL REFERENCES users(id),
    updated_by         INTEGER NULL REFERENCES users(id),
    created_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at         TIMESTAMPTZ NULL
);

-- One lineup per (team, game). The template (NULL event) is outside this index
-- on purpose — see the header.
CREATE UNIQUE INDEX IF NOT EXISTS uq_lineups_team_event
    ON lineups (team_id, calendar_event_id) WHERE calendar_event_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_lineups_team ON lineups (team_id);
CREATE INDEX IF NOT EXISTS idx_lineups_event ON lineups (calendar_event_id);

CREATE TABLE IF NOT EXISTS lineup_slots (
    id          SERIAL PRIMARY KEY,
    lineup_id   INTEGER NOT NULL REFERENCES lineups(id) ON DELETE CASCADE,
    athlete_id  INTEGER NOT NULL REFERENCES athletes(id) ON DELETE CASCADE,
    slot        TEXT NOT NULL CHECK (length(slot) BETWEEN 1 AND 12),
    sort_order  INTEGER NOT NULL DEFAULT 0,
    captain     BOOLEAN NOT NULL DEFAULT FALSE,
    note        TEXT NULL CHECK (note IS NULL OR length(note) <= 200),
    UNIQUE (lineup_id, athlete_id)
);

-- A field slot holds one player; the bench holds many.
CREATE UNIQUE INDEX IF NOT EXISTS uq_lineup_slots_field_slot
    ON lineup_slots (lineup_id, slot) WHERE slot <> 'BENCH';
CREATE INDEX IF NOT EXISTS idx_lineup_slots_lineup ON lineup_slots (lineup_id);
