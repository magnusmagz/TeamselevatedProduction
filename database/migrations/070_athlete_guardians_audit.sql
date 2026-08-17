-- 070: Audit every change to athlete_guardians
--
-- Attaching an adult to a child, or detaching one, is the single most consequential
-- row in this schema: it decides who may read a minor's record, who may collect them,
-- and whose click counts as parental consent. It was the ONLY sensitive mutation in
-- the codebase writing no audit trail at all. The only guardian-related actions in
-- audit_log were profile_guardian_synced (6 rows) and profile_guardian_sync_no_match
-- (1), both from lib/guardian_sync.php.
--
-- Found 2026-08-17. consent_records 23/24 recorded Jaia Hanks (user 241) as consenting
-- for Sebastian Luna (athlete 435), whose guardian is Eva Estrada. The guard that
-- allows that write, AthleteScope::isGuardianOfAthlete, is an exact email match and had
-- shipped two days earlier — so it ran and PASSED, meaning a guardians row carrying
-- jaiahanks@icloud.com really was linked to athlete 435 at 22:50 on 2026-07-31. That
-- link is gone and nothing anywhere records its removal. Sebastian was left reading as
-- consented on the strength of a stranger's click, with his actual parent never
-- prompted, and the removal is permanently unknowable.
--
-- WHY A TRIGGER AND NOT AuditLogger CALLS
--
-- The house rule is that audit_log is written through lib/AuditLogger.php, never a raw
-- INSERT, and this migration is a deliberate exception with a narrow reason.
--
-- There are 16 mutation sites across 6 files (registrations-api, athletes-gateway,
-- guardian-gateway, athletes.php, AthleteController, AthleteImportStrategy). Wiring
-- each one is precisely the "fixed one file, missed the other" pattern that produced
-- the FIELD() bug and the phantom columns — and it would still miss the case that
-- actually happened here, because a link removed by hand in psql goes through no PHP
-- at all. This team fixes production data by hand regularly (see CHANGELOG); an
-- application-layer trail cannot see any of it.
--
-- The trigger sees every writer, forever, including ones not yet written.
--
-- ATTRIBUTION IS BEST-EFFORT AND THAT IS HONEST
--
-- A trigger cannot know the logged-in user. It reads app.user_id, which
-- te_db_set_actor() in lib/db_actor.php sets per request. When it is unset the row
-- records user_id NULL — which is not a gap so much as a fact worth having: it means
-- the change did NOT come through an instrumented request path. A NULL here is the
-- signature of exactly the event we could not explain.

CREATE OR REPLACE FUNCTION te_audit_athlete_guardians() RETURNS TRIGGER AS $$
DECLARE
    actor   INTEGER;
    payload JSONB;
BEGIN
    -- current_setting(..., true) returns NULL rather than raising when unset, which is
    -- what keeps this trigger from breaking every insert on an uninstrumented path.
    BEGIN
        actor := NULLIF(current_setting('app.user_id', true), '')::INTEGER;
    EXCEPTION WHEN OTHERS THEN
        actor := NULL;
    END;

    -- The FK is to users(id); a stale or bogus setting must not fail the write.
    IF actor IS NOT NULL AND NOT EXISTS (SELECT 1 FROM users WHERE id = actor) THEN
        actor := NULL;
    END IF;

    IF TG_OP = 'DELETE' THEN
        payload := jsonb_build_object(
            'op', 'delete',
            'athlete_id', OLD.athlete_id,
            'guardian_id', OLD.guardian_id,
            'relationship', OLD.relationship,
            'is_primary', OLD.is_primary
        );
        INSERT INTO audit_log (user_id, action, resource_type, resource_id, details, created_at)
        VALUES (actor, 'guardian_link_removed', 'athlete_guardians', OLD.id, payload, NOW());
        RETURN OLD;
    END IF;

    IF TG_OP = 'INSERT' THEN
        payload := jsonb_build_object(
            'op', 'insert',
            'athlete_id', NEW.athlete_id,
            'guardian_id', NEW.guardian_id,
            'relationship', NEW.relationship,
            'is_primary', NEW.is_primary
        );
        INSERT INTO audit_log (user_id, action, resource_type, resource_id, details, created_at)
        VALUES (actor, 'guardian_link_added', 'athlete_guardians', NEW.id, payload, NOW());
        RETURN NEW;
    END IF;

    -- UPDATE. Re-pointing a link at a different athlete or guardian is a re-parenting
    -- and is recorded as such; flag-only edits (is_primary, can_pickup) are recorded
    -- too but are a different kind of event.
    payload := jsonb_build_object(
        'op', 'update',
        'athlete_id', NEW.athlete_id,
        'guardian_id', NEW.guardian_id,
        'old_athlete_id', OLD.athlete_id,
        'old_guardian_id', OLD.guardian_id,
        'reassigned', (OLD.athlete_id IS DISTINCT FROM NEW.athlete_id
                    OR OLD.guardian_id IS DISTINCT FROM NEW.guardian_id),
        'old_relationship', OLD.relationship,
        'relationship', NEW.relationship,
        'old_is_primary', OLD.is_primary,
        'is_primary', NEW.is_primary
    );
    INSERT INTO audit_log (user_id, action, resource_type, resource_id, details, created_at)
    VALUES (actor, 'guardian_link_changed', 'athlete_guardians', NEW.id, payload, NOW());
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS athlete_guardians_audit ON athlete_guardians;

CREATE TRIGGER athlete_guardians_audit
    AFTER INSERT OR UPDATE OR DELETE ON athlete_guardians
    FOR EACH ROW EXECUTE FUNCTION te_audit_athlete_guardians();

-- ─────────────────────────────────────────────────────────────────────────────
-- NO BACKFILL, and none is possible.
--
-- The information this trigger records was never stored anywhere, so there is nothing
-- to reconstruct. The 197 existing links predate it and will show no origin. That is a
-- statement of fact, not a defect to paper over with invented created_at guesses.
--
-- AFTER, not BEFORE: the audit records what actually happened. A BEFORE trigger writes
-- the row even when a later constraint aborts the statement.
-- ─────────────────────────────────────────────────────────────────────────────
