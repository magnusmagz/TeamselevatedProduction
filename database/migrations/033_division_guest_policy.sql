-- Migration 033: Guest player policy on tournament divisions
--
-- Existing player rules (age eligibility, sanctioning/competitive level, roster
-- size cap) already cover guests because guests are just rows on the same roster
-- table. Two knobs are guest-specific:
--   1. max_guest_players — how many is_guest=true rows are allowed per registration
--   2. guest_must_be_same_club — guests must come from the registering team's club

ALTER TABLE tournament_divisions
    ADD COLUMN IF NOT EXISTS max_guest_players INTEGER,
    ADD COLUMN IF NOT EXISTS guest_must_be_same_club BOOLEAN NOT NULL DEFAULT false;

COMMENT ON COLUMN tournament_divisions.max_guest_players IS
    'Maximum is_guest=true players per registration. NULL = no cap.';

COMMENT ON COLUMN tournament_divisions.guest_must_be_same_club IS
    'When true, guest athletes must be on a team in the same club as the registering team.';
