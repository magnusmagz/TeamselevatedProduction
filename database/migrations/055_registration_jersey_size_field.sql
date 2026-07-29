-- 055_registration_jersey_size_field.sql
--
-- Add an optional "Jersey Size" question to every EXISTING athlete registration
-- form. New programs get it from the default field sets in
-- registration/programs-api.php and registration/tryouts-api.php, but those only
-- run at program-create time — without this backfill the field would be invisible
-- on all 26 live forms and the feature would appear to do nothing.
--
-- OPTIONAL (required = 0) on purpose: a family that does not know their kid's size
-- should leave it blank. A blank reads as "still need to ask"; a forced guess reads
-- as real data and gets a wrong-sized kit ordered.
--
-- OPTIONS ARE HUMAN LABELS, NOT CODES ('Youth Medium (10-12)', not 'YM'). The public
-- form renders every select generically — the option label IS the submitted value
-- (renderField in PublicRegistrationForm.tsx). registrations-api.php resolves the
-- label back to a code with te_normalize_jersey_size() before it touches
-- athletes.jersey_size. Showing a parent a bare 'YM' would be jargon.
-- Keep this list identical to TE_JERSEY_SIZE_LABELS in lib/jersey_size.php.
--
-- Targets only forms that actually describe an athlete (those having an
-- athlete_first field). Coach/adult programs — 3 of the 29 live forms — are
-- deliberately skipped; they have no athlete to size.
--
-- The section and display_order are derived PER PROGRAM rather than hardcoded:
-- live data has 25 programs using section 'athlete_info' and 1 using
-- 'athlete_information', and the form fetch is ORDER BY section, display_order.
-- Hardcoding either would strand the field in a section of its own on that
-- one program.
--
-- Idempotent: the NOT EXISTS guard means re-running adds nothing.

INSERT INTO program_form_fields (
    program_id, field_name, field_label, field_type,
    required, options, section, display_order, created_at
)
SELECT
    src.program_id,
    'jersey_size',
    'Jersey Size',
    'select',
    -- program_form_fields.required is BOOLEAN in Postgres. The PHP writers pass
    -- 1/0 and PDO coerces them, but raw SQL has to say false or the insert fails
    -- with 42804.
    false,
    '["Youth X-Small (4-5)","Youth Small (6-8)","Youth Medium (10-12)","Youth Large (14-16)","Youth X-Large (18-20)","Adult X-Small","Adult Small","Adult Medium","Adult Large","Adult X-Large","Adult 2X-Large","Adult 3X-Large"]',
    src.athlete_section,
    src.next_order,
    NOW()
FROM (
    SELECT
        f.program_id,
        -- Whatever this program calls its athlete section.
        MIN(f.section) FILTER (WHERE f.field_name = 'athlete_first') AS athlete_section,
        -- Last within that section, so it lands after the existing athlete
        -- questions instead of splitting them.
        MAX(f.display_order) + 1 AS next_order
    FROM program_form_fields f
    GROUP BY f.program_id
    HAVING COUNT(*) FILTER (WHERE f.field_name = 'athlete_first') > 0
) src
WHERE NOT EXISTS (
    SELECT 1 FROM program_form_fields existing
    WHERE existing.program_id = src.program_id
      AND existing.field_name = 'jersey_size'
);
