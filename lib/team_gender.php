<?php
/**
 * teams.gender — the ONE place a submitted value becomes a stored one.
 *
 * The column carries a CHECK constraint allowing exactly 'Male', 'Female' and
 * 'Mixed' (default 'Mixed'). Tournament divisions use a different vocabulary
 * for the same idea — boys / girls / coed — so anything that copies a division
 * gender onto a team, or takes one from an import or an API caller, has to be
 * translated here first. Writing 'boys' straight into the column raises
 * SQLSTATE 23514 and rolls back the whole team save, which is how the jersey
 * size bug presented (see CLAUDE.md).
 *
 * The stored values are what the constraint allows; what a user READS is the
 * youth-sport wording, which lives in frontend/src/utils/teamGender.ts.
 * TeamGenderConsistencyTest keeps the two lists together.
 */

const TE_TEAM_GENDERS = ['Male', 'Female', 'Mixed'];

/**
 * Resolve a submitted gender to a storable value.
 *
 * Returns null when nothing usable was submitted — absent, blank, or a word
 * this does not recognise. Null means "the caller said nothing about gender",
 * so a create can fall back to the column default and an update can keep what
 * the team already has. It never guesses, because guessing 'Mixed' for an
 * unreadable value would silently relabel a girls team.
 */
function te_normalize_team_gender($value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $v = strtolower(trim($value));
    if ($v === '') {
        return null;
    }

    // Tolerant on the way in: division vocabulary (boys/girls/coed), the stored
    // vocabulary, and the plain words an importer or a spreadsheet produces.
    switch ($v) {
        case 'male':
        case 'boy':
        case 'boys':
        case 'm':
            return 'Male';
        case 'female':
        case 'girl':
        case 'girls':
        case 'f':
            return 'Female';
        case 'mixed':
        case 'coed':
        case 'co-ed':
        case 'co ed':
        case 'both':
            return 'Mixed';
    }

    return null;
}
