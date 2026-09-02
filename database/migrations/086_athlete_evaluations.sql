-- 086_athlete_evaluations.sql
--
-- Mid-year (in-season) athlete evaluations and Individual Development Plans
-- (CKU R76 + R77, slice 8.4).
--
-- WHY NOT REUSE tryout_evaluations
-- `tryout_evaluations.registration_id` is the row's identity: an evaluation there
-- is a fact about somebody's application to a program, and it disappears from
-- view the moment the tryout is over. A mid-year evaluation is a fact about an
-- ATHLETE, made repeatedly across seasons by whoever coaches them at the time,
-- and its whole purpose is to still be there next year. Hanging it off a
-- registration would mean an athlete who was rostered without trying out (most
-- returning players) could never be evaluated at all, and the year-over-year
-- graph would silently lose a season each time a club skipped tryouts.
--
-- WHY CRITERIA ARE FREE TEXT HERE AND A FOREIGN KEY THERE
-- `tryout_evaluation_criteria` belongs to a PROGRAM and clubs edit it between
-- seasons — renaming "Tactical Awareness", dropping a criterion, changing a
-- max_score from 5 to 10. `tryout_evaluations.scores` is a JSON object keyed by
-- criterion ID, so every one of those edits silently rewrites the meaning of
-- evaluations already recorded, and deleting a criterion orphans the key.
-- That is tolerable for a tryout, which is read within days of being written.
-- It is not tolerable for a record whose entire point is comparison across
-- years. So the club's criteria are COPIED here at evaluation time — the name,
-- and also the max_score and weight the score was given under. A row therefore
-- carries everything needed to read it back correctly, and no later edit to the
-- club's tryout criteria can change what a past evaluation meant.
--
-- ADDITIVE ONLY. Two new tables; nothing existing is altered or dropped.
-- `main` is shared and deploys are by push, so api/athlete-evaluations.php will
-- reach production before this file is applied to Neon by hand. On Postgres a
-- SELECT against a missing table is 42P01 — a hard error that would take the
-- athlete profile down for everyone rather than merely hiding a new feature —
-- so lib/athlete_evaluations.php probes for both tables and degrades: reads
-- answer `available: false`, writes answer 503 with a sentence. Same shape as
-- lib/program_scope.php's probe, and for the same reason.
--
--   team_id        NULL on purpose. The evaluation is about the athlete, and an
--                  athlete can be evaluated before they are rostered, between
--                  teams, or by a club admin with no team in mind. Recording it
--                  when it IS known lets the profile say which team the coach
--                  was speaking about. ON DELETE SET NULL: deleting a team must
--                  not delete a child's development history.
--   evaluator_id   users(id), NOT NULL. Someone said this; who, is the point.
--                  No ON DELETE — an evaluation must not vanish because a coach
--                  left the club.
--   evaluated_at   DATE, not a timestamp. This is the day the coach is speaking
--                  about, entered by hand and often backdated to the session it
--                  describes. A date-only value must be read and written in the
--                  same timezone — see the dateFormat.ts rule in CLAUDE.md.
--   season_label   Free text ('2026 Fall', '2026-27'). The x-axis of the
--                  year-over-year graph. Deliberately NOT derived from
--                  evaluated_at: a club's season boundaries are the club's
--                  business, and the frontend already disagrees with
--                  AgeEligibilityService about when a year rolls over.
--   overall_score  The weighted 0-100 roll-up, computed server-side by the same
--                  formula as calculateOverallScore() in tryouts-api.php and
--                  FROZEN here. It is not recomputed on read, because the
--                  weights it used may no longer exist.
--   idp_goals      JSONB array of {goal, target_date, created_at}. 3-5 short
--                  free-text goals. JSONB rather than a third table because
--                  nothing queries inside a goal — they are written and read as
--                  one list, always in the context of their evaluation.
--
-- REVERSE:
--   DROP TABLE IF EXISTS athlete_evaluation_scores;
--   DROP TABLE IF EXISTS athlete_evaluations;

CREATE TABLE IF NOT EXISTS athlete_evaluations (
    id            SERIAL PRIMARY KEY,
    athlete_id    INTEGER NOT NULL REFERENCES athletes(id) ON DELETE CASCADE,
    team_id       INTEGER NULL REFERENCES teams(id) ON DELETE SET NULL,
    evaluator_id  INTEGER NOT NULL REFERENCES users(id),
    evaluated_at  DATE NOT NULL DEFAULT CURRENT_DATE,
    season_label  TEXT NOT NULL,
    overall_score NUMERIC(5,2) NULL,
    notes         TEXT NULL,
    idp_goals     JSONB NULL,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at    TIMESTAMPTZ NULL
);

-- The panel's only read is "this athlete's evaluations, newest first", and the
-- graph groups the same rows by season. One index serves both.
CREATE INDEX IF NOT EXISTS idx_athlete_evaluations_athlete
    ON athlete_evaluations (athlete_id, evaluated_at DESC);
CREATE INDEX IF NOT EXISTS idx_athlete_evaluations_evaluator
    ON athlete_evaluations (evaluator_id);

CREATE TABLE IF NOT EXISTS athlete_evaluation_scores (
    id             SERIAL PRIMARY KEY,
    evaluation_id  INTEGER NOT NULL REFERENCES athlete_evaluations(id) ON DELETE CASCADE,
    criterion_name TEXT NOT NULL,
    score          NUMERIC(5,2) NULL,
    -- Copied from the club's criteria at evaluation time, alongside the name.
    -- Without them a stored 4 is unreadable: out of 5 or out of 10 is the
    -- difference between a strong season and a weak one, and the criterion the
    -- score was given under may since have been edited or deleted.
    max_score      NUMERIC(5,2) NULL,
    weight         NUMERIC(5,2) NULL,
    comment        TEXT NULL,
    display_order  INTEGER NOT NULL DEFAULT 0,
    UNIQUE (evaluation_id, criterion_name)
);

CREATE INDEX IF NOT EXISTS idx_athlete_evaluation_scores_evaluation
    ON athlete_evaluation_scores (evaluation_id);
