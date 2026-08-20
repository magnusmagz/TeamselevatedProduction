-- 072: user_guardians — record identity instead of deriving it
--
-- There is no row anywhere saying "this account belongs to this guardian". The
-- relationship is re-derived on every request by string-comparing users.email against
-- guardians.email, two independently-editable columns in two different tables. Seven
-- incidents have come out of that absence; the most recent (2026-08-18) was a parent
-- whose guardian row differed from her login by one capital letter, leaving her with a
-- valid parent role and an empty portal.
--
-- Full plan, measurements and the risks this migration itself introduces:
--   docs/user-guardians-identity-plan.md
--
-- THIS MIGRATION IS ADDITIVE AND CHANGES NO BEHAVIOUR. Nothing reads the table yet.
-- Phase 2 introduces one resolver that reads it UNION the existing email match, which
-- is strictly wider than today and so cannot cost anyone access. Reversible by
-- DROP TABLE.

CREATE TABLE IF NOT EXISTS user_guardians (
    id           SERIAL PRIMARY KEY,

    user_id      INTEGER NOT NULL REFERENCES users(id)     ON DELETE CASCADE,

    -- ⚠️ A guardians(id). NOT the same thing as consent_records.guardian_id, which is
    -- an FK to users(id) — verified against the live constraint
    -- (consent_records_guardian_id_fkey -> users). Joining those two columns because
    -- they share a name would attribute consent to the wrong person. The name matches
    -- athlete_guardians.guardian_id, which is the majority meaning; consent_records is
    -- the outlier.
    guardian_id  INTEGER NOT NULL REFERENCES guardians(id) ON DELETE CASCADE,

    -- How this row came to exist. Stored, never derived: when the first wrong link
    -- appears it will be a family seeing another family's child, and the only question
    -- that matters then is how it got here. A backfilled match and an admin's
    -- deliberate action must stay distinguishable forever.
    --   backfill_email | invite_accept | admin_link | registration
    source       VARCHAR(30) NOT NULL,

    --   exact     — the account matched exactly one guardian, no judgement involved
    --   household — a shared address, confirmed by a human
    --   manual    — an admin connected them directly
    confidence   VARCHAR(10) NOT NULL,

    linked_by    INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Constrains the PAIR, not the guardian. One guardian may legitimately hold two
    -- accounts — Allix Boyce had exactly that, an invited @yahoo one and a
    -- self-created @gmail one — and today one of the two is simply broken. Both
    -- linking is the better answer; it should be visible to staff, not silently
    -- deduplicated here.
    UNIQUE (user_id, guardian_id)
);

-- UNIQUE already indexes (user_id, guardian_id), covering the hot path
-- account -> their guardians. The REVERSE lookup gets nothing from it, and it is not
-- rare: portal_status.php and handleClubParents ask "which account belongs to this
-- guardian" once per row of the Crew page.
CREATE INDEX IF NOT EXISTS user_guardians_guardian_idx ON user_guardians (guardian_id);


-- ─────────────────────────────────────────────────────────────────────────────
-- Audit. Migration 070's trigger covers athlete_guardians ONLY; this table needs its
-- own. Same reasoning: attaching an adult to a child is the most consequential row in
-- the schema, and the one change we could not explain (2026-07-31) left no trace.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE OR REPLACE FUNCTION te_audit_user_guardians() RETURNS TRIGGER AS $$
DECLARE
    actor   INTEGER;
    payload JSONB;
BEGIN
    BEGIN
        actor := NULLIF(current_setting('app.user_id', true), '')::INTEGER;
    EXCEPTION WHEN OTHERS THEN
        actor := NULL;
    END;

    IF actor IS NOT NULL AND NOT EXISTS (SELECT 1 FROM users WHERE id = actor) THEN
        actor := NULL;
    END IF;

    IF TG_OP = 'DELETE' THEN
        payload := jsonb_build_object('op','delete','user_id',OLD.user_id,
                    'guardian_id',OLD.guardian_id,'source',OLD.source,
                    'confidence',OLD.confidence);
        INSERT INTO audit_log (user_id, action, resource_type, resource_id, details, created_at)
        VALUES (actor, 'user_guardian_unlinked', 'user_guardians', OLD.id, payload, NOW());
        RETURN OLD;
    END IF;

    IF TG_OP = 'INSERT' THEN
        payload := jsonb_build_object('op','insert','user_id',NEW.user_id,
                    'guardian_id',NEW.guardian_id,'source',NEW.source,
                    'confidence',NEW.confidence);
        INSERT INTO audit_log (user_id, action, resource_type, resource_id, details, created_at)
        VALUES (actor, 'user_guardian_linked', 'user_guardians', NEW.id, payload, NOW());
        RETURN NEW;
    END IF;

    payload := jsonb_build_object('op','update',
                'user_id',NEW.user_id,'old_user_id',OLD.user_id,
                'guardian_id',NEW.guardian_id,'old_guardian_id',OLD.guardian_id,
                'reassigned',(OLD.user_id IS DISTINCT FROM NEW.user_id
                           OR OLD.guardian_id IS DISTINCT FROM NEW.guardian_id),
                'source',NEW.source,'confidence',NEW.confidence);
    INSERT INTO audit_log (user_id, action, resource_type, resource_id, details, created_at)
    VALUES (actor, 'user_guardian_changed', 'user_guardians', NEW.id, payload, NOW());
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS user_guardians_audit ON user_guardians;

CREATE TRIGGER user_guardians_audit
    AFTER INSERT OR UPDATE OR DELETE ON user_guardians
    FOR EACH ROW EXECUTE FUNCTION te_audit_user_guardians();

-- ─────────────────────────────────────────────────────────────────────────────
-- NO BACKFILL IN THIS FILE, deliberately.
--
-- The backfill is scripts/backfill-user-guardians.php: dry-run by default, prints a
-- report, and refuses the ambiguous cases rather than guessing. Six accounts share an
-- address and one of them (eli@teamselevated.com) is a staff address sitting on four
-- unrelated children — no automated rule separates that from a genuine household
-- without eventually guessing wrong about a child. Those six are held for a human.
--
-- Putting it here instead would make it a single unreviewable statement that runs
-- itself, and the wrong row it wrote would be a child-data disclosure.
-- ─────────────────────────────────────────────────────────────────────────────
