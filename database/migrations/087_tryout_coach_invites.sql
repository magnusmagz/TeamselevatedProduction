-- 087_tryout_coach_invites.sql
--
-- "Coach invited player" (CKU R86, slice 8.2).
--
-- During tryouts a coach marks a registrant they want on their team. The family
-- is told with the EXISTING team-invitation email (lib/Email.php's
-- sendTeamInvitation) carrying registration instructions, and the club director
-- gets one place to see who each coach has claimed and what happened next.
--
-- 086 belongs to the athlete-evaluations slice running in the same worktree.
-- Claim the next number by listing database/migrations/ in EVERY checkout —
-- the main one and each worktree — before creating one.
--
-- ADDITIVE ONLY. One new table; nothing existing is altered or dropped. Code
-- that reaches production before this is applied degrades rather than 500s:
-- lib/tryout_coach_invite.php probes for the table and the three tryouts-api
-- paths answer 503 with a sentence, the same shape as lib/program_scope.php
-- and lib/program_ordering.php, and for the same reason — `main` is shared and
-- deploys are by push, so this SQL runs days after the code that reads it.
--
--   registration_id  registrations(id). The tryout registrant, not the athlete:
--                    a family can register for two programs and a coach's claim
--                    is about ONE tryout. CASCADE, because an invite to a
--                    registration that no longer exists is not a fact about
--                    anything.
--   team_id          teams(id), NULLABLE. A coach may want a player before the
--                    team they will land on is decided. ON DELETE SET NULL
--                    rather than CASCADE: deleting a team must not erase the
--                    record that a coach made a selection.
--   invited_by       users(id) of the coach. NOT NULL — the whole point of this
--                    table is attributing the selection to a person, so an
--                    unattributed row would be worse than no row. Only ever
--                    written from the token, never from the request body.
--   email_sent_at    NULL until an address actually accepted the mail. It is
--                    the evidence half: the row records that a coach chose
--                    someone, this column records that the family was told, and
--                    the two are separate facts because the second one fails on
--                    its own. Nothing may report "sent" from the row's mere
--                    existence.
--   status           The COACH-SELECTION state, not the athlete's. 'registered'
--                    is in the constraint for completeness, but the API never
--                    writes it and must not start: whether the athlete has since
--                    been rostered is COMPUTED at read time from tryout_offers
--                    and team_members. A stored copy is a second source that
--                    drifts the first time someone is rostered by hand in psql,
--                    which this team does regularly.
--   UNIQUE(registration_id, invited_by)
--                    One row per coach per registrant, so pressing the button
--                    twice is an upsert rather than a second claim — and so two
--                    DIFFERENT coaches wanting the same player is representable,
--                    which is the situation a director most needs to see.
--
-- REVERSE:
--   DROP TABLE IF EXISTS tryout_coach_invites;

CREATE TABLE IF NOT EXISTS tryout_coach_invites (
    id              SERIAL PRIMARY KEY,
    registration_id INTEGER NOT NULL REFERENCES registrations(id) ON DELETE CASCADE,
    team_id         INTEGER NULL REFERENCES teams(id) ON DELETE SET NULL,
    invited_by      INTEGER NOT NULL REFERENCES users(id),
    invited_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    email_sent_at   TIMESTAMPTZ NULL,
    status          VARCHAR(20) NOT NULL DEFAULT 'invited'
                    CHECK (status IN ('invited', 'registered', 'declined', 'withdrawn')),
    notes           TEXT NULL,
    UNIQUE (registration_id, invited_by)
);

-- The director's view lists a whole program's invites, which reaches this table
-- through registrations; the button on every athlete row asks the other way.
CREATE INDEX IF NOT EXISTS idx_tryout_coach_invites_registration
    ON tryout_coach_invites (registration_id);
CREATE INDEX IF NOT EXISTS idx_tryout_coach_invites_invited_by
    ON tryout_coach_invites (invited_by);
