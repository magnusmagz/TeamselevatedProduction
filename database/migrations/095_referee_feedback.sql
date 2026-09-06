-- 095_referee_feedback.sql
--
-- Referee feedback from the coaches portal (CKU R68, slice 8.6).
--
-- A coach records what they thought of the referee(s) of a game they coached;
-- a club admin reviews every row for the club and exports it. The club uses
-- this when it talks to its assignor, which is why the rows carry the game,
-- the team and the coach rather than an anonymous score.
--
-- WHY NOT tournament_referees / tournament_match_referees
-- Those tables belong to the tournament module: a referee row there is an
-- assignment to a tournament match, created by the tournament director. A
-- league game on the club calendar has no referee record anywhere — the
-- assignor is a separate organisation with its own system — so there is
-- nothing to reference. referee_name is therefore free text, entered by the
-- coach. A referee REGISTRY (so "J. Whistle" and "John Whistle" are one
-- person) is a product decision that has not been made; the per-name summary
-- on the admin page groups on the trimmed string, and that is all it claims.
--
-- WHY calendar_events, NOT a games table
-- There is no games table. A game is a calendar_events row with type = 'game'
-- (see the Database section of CLAUDE.md); its teams are calendar_event_teams.
-- team_id here is the coach's OWN team on that event — a game between two
-- club teams has two coaches with two opinions, and the row says which side
-- each was on.
--
-- ADDITIVE ONLY. One new table; nothing existing is altered. `main` is shared
-- and deploys are by push, so api/referee-feedback.php reaches production
-- before this file is applied by hand. lib/referee_feedback.php probes for the
-- table and answers 503 with a sentence until then — same shape as
-- lib/tryout_coach_invite.php and lib/athlete_evaluations.php.
--
--   club_id            Denormalised from the event so the admin list and the
--                      export are one indexed predicate, not a join through
--                      calendar_events for every filter.
--   calendar_event_id  ON DELETE CASCADE: feedback about a game that no longer
--                      exists has nothing to be about.
--   team_id            No ON DELETE — teams are soft-deleted in this product,
--                      and the feedback stays readable after a team is retired.
--   submitted_by       users(id). Who said it is the point; no ON DELETE.
--   referee_name       Free text, trimmed on write. See above.
--   rating             1 (poor) to 5 (excellent).
--   categories         JSONB array of tags from the ONE list in
--                      lib/referee_feedback.php (mirrored in
--                      frontend/src/constants/refereeFeedbackCategories.ts and
--                      pinned by RefereeFeedbackCategoriesTest). JSONB rather
--                      than text[] so a PDO string parameter binds without a
--                      cast, the way athlete_evaluations.idp_goals already does.
--   incident           TRUE flags the row for admin attention. It is a filter
--                      on the admin page, not a workflow — there is no
--                      "resolved" state, on purpose, until a club asks for one.
--   UNIQUE             One row per (game, coach, referee). A second submission
--                      from the same coach about the same referee is an edit,
--                      and the gateway routes it to update.
--
-- REVERSE:
--   DROP TABLE IF EXISTS referee_feedback;

CREATE TABLE IF NOT EXISTS referee_feedback (
    id                 SERIAL PRIMARY KEY,
    club_id            INTEGER NOT NULL REFERENCES club_profile(id),
    calendar_event_id  INTEGER NOT NULL REFERENCES calendar_events(id) ON DELETE CASCADE,
    team_id            INTEGER NOT NULL REFERENCES teams(id),
    submitted_by       INTEGER NOT NULL REFERENCES users(id),
    referee_name       TEXT NOT NULL CHECK (length(referee_name) BETWEEN 1 AND 120),
    rating             SMALLINT NOT NULL CHECK (rating BETWEEN 1 AND 5),
    categories         JSONB NOT NULL DEFAULT '[]'::jsonb,
    comments           TEXT NULL,
    incident           BOOLEAN NOT NULL DEFAULT FALSE,
    created_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at         TIMESTAMPTZ NULL,
    UNIQUE (calendar_event_id, submitted_by, referee_name)
);

-- The admin list: one club, filtered by date/team/incident/name, newest game
-- first. The date lives on calendar_events, so the index serves the club
-- predicate and the join does the rest.
CREATE INDEX IF NOT EXISTS idx_referee_feedback_club
    ON referee_feedback (club_id, incident);
-- The modal: "my rows on this event".
CREATE INDEX IF NOT EXISTS idx_referee_feedback_event_author
    ON referee_feedback (calendar_event_id, submitted_by);
CREATE INDEX IF NOT EXISTS idx_referee_feedback_author
    ON referee_feedback (submitted_by);
