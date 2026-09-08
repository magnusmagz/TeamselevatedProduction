-- 099_referees.sql
--
-- Referees: a club-level directory under People, a `referee` role so a referee
-- can hold an account, and game assignments that feed the referee's own page
-- (Maggie, 2026-09-08).
--
-- ONE PERSON, MANY CLUBS. Referees work with different clubs, so the PERSON is
-- the `users` row (users.email is UNIQUE) and each club keeps its OWN `referees`
-- row for that person — its grade, certification and notes about them.
-- `referees.user_id` links the club's row to the account; a referee therefore
-- holds the `referee` role in several clubs and /referee lists games across all
-- of them. Creating a referee whose email already has an account links to it
-- rather than making a second one (lib/referees.php, te_referee_create).
--
-- WHY NOT tournament_referees. That table is the tournament module's: a row
-- there is an assignment to a tournament match, made by a tournament director,
-- and it carries no user. Left alone; this is a different thing.
--
-- referee_feedback.referee_id (migration 095 left referee_name free text)
-- lets the coach's feedback modal pick a directory row; the admin summary
-- then groups by id when present, by name otherwise. Free text still works.
--
-- game_referees: who is refereeing which GAME (a calendar_events row with
-- type = 'game'). role is the position on the day. Assigned by whoever can
-- edit the game — te_event_staff_standing(): club admin of the event's club
-- or a coach of a team on it — or CLAIMED by a referee from /referee
-- (self_assigned = TRUE): an upcoming game in a club they referee for whose
-- role is not yet filled. A referee may release only their own claimed row;
-- staff may unassign any row. An upcoming game with no `center` referee is
-- "needs ref" on every staff game surface (referee_status on the events list).
--
-- ⚠️ ONE NON-ADDITIVE STEP, APPROVED (Maggie, 2026-09-08 — recorded next to the
-- other approved exceptions in CLAUDE.md): user_club_access.role is
-- CHECK-constrained to six values and `referee` must be a seventh. The DO block
-- below finds the constraint by DEFINITION (the one CHECK on user_club_access
-- whose text mentions club_admin), refuses to run unless exactly one matches,
-- and re-creates it under its existing name. It cannot silently add a second
-- constraint: if the name it expects is wrong, it raises and the transaction
-- rolls back. The name Postgres will have given it is user_club_access_role_check.
--
-- Everything else is additive: two new tables, three columns
-- (referee_feedback.referee_id, calendar_events.min_referee_grade,
-- calendar_events.allow_referee_self_assign).
--
-- calendar_events.min_referee_grade: the lowest grade a referee needs to take
-- the game (NULL = any). The ordered scale and the legacy 9–1 mapping live in
-- lib/referees.php (TE_REFEREE_GRADE_RANK), mirrored in
-- frontend/src/constants/refereeGrades.ts. open-games and claim enforce it;
-- staff may place someone below it and the row records grade_override.
--
-- `main` is shared and deploys are by push, so api/referees.php reaches
-- production before this is applied by hand. lib/referees.php probes for the
-- table and answers 503 with a sentence until it is there.
--
-- REVERSE:
--   DROP TABLE IF EXISTS game_referees;
--   ALTER TABLE referee_feedback DROP COLUMN IF EXISTS referee_id;
--   ALTER TABLE calendar_events DROP COLUMN IF EXISTS min_referee_grade;
--   ALTER TABLE calendar_events DROP COLUMN IF EXISTS allow_referee_self_assign;
--   DROP TABLE IF EXISTS referees;
--   ALTER TABLE user_club_access DROP CONSTRAINT user_club_access_role_check;
--   ALTER TABLE user_club_access ADD CONSTRAINT user_club_access_role_check
--     CHECK (role IN ('club_admin','coach','parent','player','volunteer','treasurer'));
--   (Delete any user_club_access rows with role = 'referee' FIRST or the
--    six-value CHECK cannot be re-added.)

CREATE TABLE IF NOT EXISTS referees (
    id                  SERIAL PRIMARY KEY,
    club_id             INTEGER NOT NULL REFERENCES club_profile(id) ON DELETE CASCADE,
    user_id             INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    first_name          TEXT NOT NULL,
    last_name           TEXT NOT NULL,
    email               TEXT NULL,
    phone               TEXT NULL,          -- E.164, via te_normalize_sms_phone
    grade               TEXT NULL,          -- official grade (Grassroots … National, legacy 9–1), free text allowed
    certification_level TEXT NULL,          -- any extra credential
    notes               TEXT NULL,
    active              BOOLEAN NOT NULL DEFAULT TRUE,
    archived_at         TIMESTAMP NULL,
    created_by          INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMP NOT NULL DEFAULT NOW()
);

-- One row per address per club. Case-insensitive; a referee with no email is
-- allowed and is not constrained.
CREATE UNIQUE INDEX IF NOT EXISTS referees_club_lower_email_uniq
    ON referees (club_id, lower(email)) WHERE email IS NOT NULL;
CREATE INDEX IF NOT EXISTS referees_club_id_idx ON referees (club_id);
CREATE INDEX IF NOT EXISTS referees_user_id_idx ON referees (user_id) WHERE user_id IS NOT NULL;

COMMENT ON TABLE referees IS
    'Club-level referee directory (People → Referees). One row per club per person; user_id links to the account.';

ALTER TABLE referee_feedback
    ADD COLUMN IF NOT EXISTS referee_id INTEGER NULL REFERENCES referees(id) ON DELETE SET NULL;
CREATE INDEX IF NOT EXISTS referee_feedback_referee_id_idx ON referee_feedback (referee_id) WHERE referee_id IS NOT NULL;

ALTER TABLE calendar_events
    ADD COLUMN IF NOT EXISTS min_referee_grade TEXT NULL;
COMMENT ON COLUMN calendar_events.min_referee_grade IS
    'Lowest referee grade that may take this game (Grassroots/Regional/National/Professional); NULL = any.';

-- Self-assignment is the norm (Maggie, 2026-09-08): default TRUE, a game can be closed.
ALTER TABLE calendar_events
    ADD COLUMN IF NOT EXISTS allow_referee_self_assign BOOLEAN NOT NULL DEFAULT TRUE;
COMMENT ON COLUMN calendar_events.allow_referee_self_assign IS
    'May a referee claim this game from /referee? Staff assignment is unaffected.';

CREATE TABLE IF NOT EXISTS game_referees (
    id                  SERIAL PRIMARY KEY,
    calendar_event_id   INTEGER NOT NULL REFERENCES calendar_events(id) ON DELETE CASCADE,
    referee_id          INTEGER NOT NULL REFERENCES referees(id) ON DELETE CASCADE,
    role                TEXT NOT NULL DEFAULT 'referee'
                        CHECK (role IN ('referee', 'center', 'assistant', 'fourth')),
    assigned_by         INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    assigned_at         TIMESTAMP NOT NULL DEFAULT NOW(),
    self_assigned       BOOLEAN NOT NULL DEFAULT FALSE,   -- claimed by the referee from /referee, not placed by staff
    grade_override      BOOLEAN NOT NULL DEFAULT FALSE,   -- staff placed a referee below the game's minimum grade, knowingly
    conflict_override   BOOLEAN NOT NULL DEFAULT FALSE,   -- staff placed a referee who is on an overlapping game that day, knowingly
    UNIQUE (calendar_event_id, referee_id)
);
CREATE INDEX IF NOT EXISTS game_referees_referee_id_idx ON game_referees (referee_id);

COMMENT ON TABLE game_referees IS
    'Referee assignments to games (calendar_events.type = game). Feeds the referee''s /referee page.';

-- The approved non-additive step: `referee` joins the user_club_access.role CHECK.
DO $$
DECLARE
    matched   INTEGER;
    the_name  TEXT;
BEGIN
    SELECT count(*), min(conname)
      INTO matched, the_name
      FROM pg_constraint
     WHERE conrelid = 'user_club_access'::regclass
       AND contype = 'c'
       AND pg_get_constraintdef(oid) ILIKE '%club_admin%';

    IF matched <> 1 THEN
        RAISE EXCEPTION
            '099_referees: expected exactly one CHECK constraint on user_club_access mentioning club_admin, found %. Nothing changed.',
            matched;
    END IF;

    RAISE NOTICE '099_referees: re-creating user_club_access CHECK constraint % with referee added', the_name;

    EXECUTE format('ALTER TABLE user_club_access DROP CONSTRAINT %I', the_name);
    EXECUTE format(
        'ALTER TABLE user_club_access ADD CONSTRAINT %I CHECK (role IN (%L, %L, %L, %L, %L, %L, %L))',
        the_name, 'club_admin', 'coach', 'parent', 'player', 'volunteer', 'treasurer', 'referee'
    );
END $$;
