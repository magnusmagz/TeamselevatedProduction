-- Migration: 026_tournament_rosters.sql
-- Description: Tournament-specific roster per team registration. The card
--              picker (Match Center) used to read from team_members, which
--              meant a guest player couldn't be carded and a player who
--              changed jersey numbers for the tournament had to update the
--              regular team roster. With this table, each (team, tournament)
--              has its own roster snapshot — director can import from the
--              team's regular roster with one click, add/remove individuals,
--              set tournament-specific jersey numbers, and add free-text
--              guest players who don't have athlete records.
-- Created: 2026-05-04
-- Phase: Module 3 polish (player rostering — minimum viable)
--
-- SAFETY: new table, no changes to existing tables. Idempotent.

CREATE TABLE IF NOT EXISTS tournament_registration_players (
    id              SERIAL PRIMARY KEY,
    registration_id INTEGER NOT NULL REFERENCES tournament_registrations(id) ON DELETE CASCADE,
    -- athlete_id is NULL for guest players who don't have an athlete record
    -- in the host club's database (e.g., player borrowed from a visiting
    -- club). When set, the player's name resolves from the athletes table
    -- so a name change on the athlete propagates everywhere.
    athlete_id      INTEGER REFERENCES athletes(id) ON DELETE SET NULL,
    -- Required when athlete_id is null. Otherwise optional / display-only
    -- snapshot — useful when an athlete is later renamed or deleted.
    player_name     VARCHAR(200),
    -- Tournament-specific jersey number; may differ from the team's regular
    -- jersey assignment.
    jersey_number   INTEGER,
    position        VARCHAR(50),
    is_guest        BOOLEAN NOT NULL DEFAULT false,
    notes           TEXT,
    created_by      INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Same athlete can't be on the same tournament roster twice. Postgres
-- treats NULL athlete_ids as distinct, so two guests with NULL athletes
-- don't trip this constraint (they're different people anyway).
CREATE UNIQUE INDEX IF NOT EXISTS idx_tournament_registration_players_unique
    ON tournament_registration_players(registration_id, athlete_id)
    WHERE athlete_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_tournament_registration_players_registration
    ON tournament_registration_players(registration_id);
