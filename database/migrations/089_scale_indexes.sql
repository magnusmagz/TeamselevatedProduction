-- 089_scale_indexes.sql
--
-- The five indexes every scope predicate in this codebase already depends on
-- and none of which exist (GOTR G2, docs/gotr-hierarchy-plan-2026-09.md §5).
--
-- `team_members` and `athlete_guardians` carry NO indexes at all today beyond
-- their primary keys, and `teams.primary_coach_id` has none either. Every
-- answer to "which athletes may this person see" walks all three:
--
--   lib/AthleteScope.php          coachTeamIdsForUser / staffManageableAthleteIds
--   lib/coach_scope.php           getCoachTeamIds — the one source of team scope
--   lib/guardian_identity.php     te_athlete_ids_for_user — the guardian chain
--   lib/AuthMiddleware.php        the per-request role derivation
--   chat-server/lib/team_scope.js the same joins again, from Node
--
-- At CKU scale a sequential scan is invisible. At the ~30,000 coaches and
-- volunteers GOTR is bringing, every one of those is a full-table scan on every
-- request, including the role derivation that `requireAuth()` runs before any
-- handler is reached.
--
-- ADDITIVE ONLY. Indexes create no rows, change no results, and are safe to
-- drop. Nothing in the application reads a catalogue to decide whether these
-- exist, so code that ships before this runs is merely slower, never broken.
--
-- ⚠️ OPERATOR NOTE — CONCURRENTLY.
--   These are written as plain CREATE INDEX because scripts/apply-migration.php
--   wraps the file in ONE transaction and `CREATE INDEX CONCURRENTLY` cannot run
--   inside a transaction block (Postgres 25001). A plain CREATE INDEX takes a
--   SHARE lock on the table: reads continue, writes BLOCK for the duration.
--   On the current row counts (team_members and athlete_guardians are both in
--   the hundreds) that is milliseconds and this file can be applied as-is.
--   Once either table is past roughly 30,000 rows, do NOT apply this through
--   apply-migration.php — run the five statements by hand as
--   `CREATE INDEX CONCURRENTLY IF NOT EXISTS ...`, one per session, outside any
--   transaction, and check `pg_index.indisvalid` afterwards (a failed
--   CONCURRENTLY build leaves an INVALID index that must be dropped and
--   rebuilt). That is a decision about live write traffic, so it belongs to the
--   operator and not to this file.
--
-- Column order is the lookup order, not alphabetical:
--   team_members (team_id, user_id)   — "is this user staff on this team", the
--                                       assistant_coach/team_manager half of
--                                       every coach predicate. team_id leads
--                                       because the roster read (all members of
--                                       one team) uses the same index.
--   team_members (athlete_id)         — "which teams is this athlete on", the
--                                       athlete→club derivation and the
--                                       guardian chain's last hop.
--   athlete_guardians (athlete_id, guardian_id)
--                                     — the crew of one athlete, and the
--                                       covering pair for the link lookups.
--   athlete_guardians (guardian_id)   — the reverse direction: the athletes of
--                                       one guardian. te_athlete_ids_for_user()
--                                       queries this way round and the composite
--                                       above cannot serve it (guardian_id is
--                                       not the leading column).
--   teams (primary_coach_id)          — the head-coach half of every coach
--                                       predicate, which today scans `teams`.
--
-- REVERSE:
--   DROP INDEX IF EXISTS idx_team_members_team_user;
--   DROP INDEX IF EXISTS idx_team_members_athlete;
--   DROP INDEX IF EXISTS idx_athlete_guardians_athlete_guardian;
--   DROP INDEX IF EXISTS idx_athlete_guardians_guardian;
--   DROP INDEX IF EXISTS idx_teams_primary_coach;

CREATE INDEX IF NOT EXISTS idx_team_members_team_user
    ON team_members (team_id, user_id);

CREATE INDEX IF NOT EXISTS idx_team_members_athlete
    ON team_members (athlete_id);

CREATE INDEX IF NOT EXISTS idx_athlete_guardians_athlete_guardian
    ON athlete_guardians (athlete_id, guardian_id);

CREATE INDEX IF NOT EXISTS idx_athlete_guardians_guardian
    ON athlete_guardians (guardian_id);

CREATE INDEX IF NOT EXISTS idx_teams_primary_coach
    ON teams (primary_coach_id);
