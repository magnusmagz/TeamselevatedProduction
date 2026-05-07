-- 035_expand_sport_presets.sql
-- Adds U5–U20 + Adult soccer presets so the DivisionForm age-group
-- dropdown can be data-driven off sport_presets instead of a hardcoded
-- frontend array.
--
-- Existing rows for U8, U10, U12, U14, U16, U19 are preserved (ON
-- CONFLICT DO NOTHING). Game/half durations and roster sizes follow
-- USYS Player Development Initiatives guidance and match the cadence
-- of the rows already in place.
--
-- Idempotent: safe to re-run.

INSERT INTO sport_presets
  (sport, age_group, game_duration_minutes, half_duration_minutes, max_roster_size, min_roster_size, max_players_on_field, rule_notes)
VALUES
  -- 4v4 small-sided, no goalkeepers
  ('soccer', 'U5',    24, 12,  8, 4,  4, '["No goalkeepers", "No heading", "Size 3 ball"]'::jsonb),
  ('soccer', 'U6',    24, 12,  8, 4,  4, '["No goalkeepers", "No heading", "Size 3 ball"]'::jsonb),
  ('soccer', 'U7',    24, 12,  8, 4,  4, '["No goalkeepers", "No heading", "Size 3 ball"]'::jsonb),
  -- 7v7 transition
  ('soccer', 'U9',    50, 25, 14, 7,  7, '["No heading", "Build-out line", "Size 4 ball"]'::jsonb),
  -- 9v9 transition
  ('soccer', 'U11',   60, 30, 18, 9,  9, '["No heading (U11 and under)", "Size 4 ball"]'::jsonb),
  -- 11v11
  ('soccer', 'U13',   70, 35, 22, 11, 11, '["Size 5 ball"]'::jsonb),
  ('soccer', 'U15',   70, 35, 22, 11, 11, '["Size 5 ball"]'::jsonb),
  ('soccer', 'U17',   80, 40, 22, 11, 11, '["Size 5 ball"]'::jsonb),
  ('soccer', 'U18',   90, 45, 22, 11, 11, '["Size 5 ball"]'::jsonb),
  ('soccer', 'U20',   90, 45, 22, 11, 11, '["Size 5 ball"]'::jsonb),
  ('soccer', 'Adult', 90, 45, 22, 11, 11, '["Size 5 ball"]'::jsonb)
ON CONFLICT (sport, age_group) DO NOTHING;
