-- 085_program_staff.sql
--
-- Coaches assigned to a PROGRAM, not a team (CKU R66, slice 8.1).
--
-- Camps, clinics and drop-ins have registrants and no roster: `team_members` is
-- empty for them, so every existing coach scope — getCoachTeamIds(), the
-- calendar's team joins, the recipient typeahead — resolves to nothing for the
-- person actually running the session. This table is the missing link, and it is
-- the ONLY thing that grants program reach: nothing infers program standing from
-- a team, a club role, or a registration.
--
-- Migration 085 (7.1 discount codes was punted, freeing the number)
-- worktree. Claim the next number by listing database/migrations/ in EVERY
-- checkout before creating one.
--
-- ADDITIVE ONLY. One new table; nothing existing is altered or dropped. Code
-- that reaches production before this is applied degrades rather than 500s —
-- lib/program_scope.php probes for the table and answers "no programs" when it
-- is absent, which is the same shape as lib/program_ordering.php's column probe
-- and for the same reason: `main` is shared and deploys are by push, so this SQL
-- runs days after the code that reads it.
--
--   role         'coach' | 'assistant' | 'manager'. Mirrors the vocabulary of
--                team_members.role rather than inventing a second one. It is
--                recorded for display and future per-role gating; every read
--                today treats the three identically.
--   assigned_by  users(id) of the club admin who made the assignment. NULL is
--                honest for a row created outside a request path (by hand in
--                psql, which this team does regularly).
--   UNIQUE(program_id, user_id)  one row per person per program. A re-assign is
--                an upsert, not a second row, so removing staff cannot leave a
--                stale duplicate still granting reach.
--
-- REVERSE:
--   DROP TABLE IF EXISTS program_staff;

CREATE TABLE IF NOT EXISTS program_staff (
    id          SERIAL PRIMARY KEY,
    program_id  INTEGER NOT NULL REFERENCES programs(id) ON DELETE CASCADE,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role        VARCHAR(20) NOT NULL DEFAULT 'coach'
                CHECK (role IN ('coach', 'assistant', 'manager')),
    assigned_by INTEGER NULL REFERENCES users(id),
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (program_id, user_id)
);

-- "Which programs does this user staff" runs on every calendar read and every
-- recipient search for a non-admin, so it is the index that matters.
CREATE INDEX IF NOT EXISTS idx_program_staff_user ON program_staff (user_id);
CREATE INDEX IF NOT EXISTS idx_program_staff_program ON program_staff (program_id);
