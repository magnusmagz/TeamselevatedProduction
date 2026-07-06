-- 041_athletes_user_id.sql
-- Decouple athletes.id from users.id.
--
-- Historically legacy/athletes-gateway.php created a `users` row for an athlete and
-- REUSED that user's id as the athlete primary key ("use it as the athlete id").
-- Athlete ids therefore came from two overlapping spaces — the athletes sequence AND
-- users.id — which collided (SQLSTATE 23505 duplicate key on athletes_pkey). This adds
-- an explicit link column so athletes.id is ALWAYS sequence-generated and the optional
-- user linkage lives in athletes.user_id instead of being overloaded onto the PK.
--
-- Safe/additive: nullable column, no default, backfills only the 2 historically-coupled
-- rows. Apply BEFORE deploying the matching legacy/athletes-gateway.php change.

ALTER TABLE athletes ADD COLUMN IF NOT EXISTS user_id INTEGER;

-- Backfill rows that were coupled the old way (athlete.id == a player user's id).
UPDATE athletes a
   SET user_id = a.id
 WHERE user_id IS NULL
   AND EXISTS (SELECT 1 FROM users u WHERE u.id = a.id AND u.role = 'player');

CREATE INDEX IF NOT EXISTS idx_athletes_user_id ON athletes(user_id);
