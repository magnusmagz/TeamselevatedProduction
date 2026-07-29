<?php
/**
 * Canonical jersey sizes, server side.
 *
 * The athletes.jersey_size CHECK constraint (migration 054) accepts exactly these
 * codes. Anything else reaching an INSERT/UPDATE raises 23514, which the gateways'
 * try/catch would turn into a healthy-looking response that saved nothing — the
 * silent-failure pattern SchemaConformanceTest exists to prevent. So every write
 * path normalizes through te_normalize_jersey_size() first and never trusts the
 * value the frontend sent.
 *
 * ON THE DUPLICATED LABEL STRINGS
 * The same 12 human labels necessarily appear in more than one place: here, in
 * frontend/src/utils/jerseySize.ts (different language), and frozen into applied
 * migrations 054/055 and the 26 program_form_fields rows they seeded (data, not
 * code — and an applied migration must never be edited).
 *
 * Rather than pretend that can be deduplicated, te_normalize_jersey_size() is
 * deliberately written NOT to depend on exact label text: it parses group + size
 * structurally. So 'Youth Medium (10-12)', 'Youth Medium', 'youth med', and 'YM'
 * all resolve to 'YM'. A club retitling the option in the form builder, or a label
 * here drifting from the seeded rows, is then a cosmetic difference rather than
 * silent data loss. JerseySizeConsistencyTest keeps the two code lists aligned so
 * the forms still *look* the same across surfaces.
 */

/**
 * Code => the human label families actually see.
 *
 * The public registration form renders every `select` generically: the option
 * label IS the submitted value (see renderField in PublicRegistrationForm.tsx), so
 * registrations arrive carrying 'Youth Medium (10-12)' rather than 'YM'. Showing a
 * parent a bare 'YM' would be jargon, so the label is what goes on the form.
 *
 * Age hints are on the youth sizes only. Adult fit varies too much by brand and
 * cut for a number here to be anything but misleading.
 */
const TE_JERSEY_SIZE_LABELS = [
    'YXS'  => 'Youth X-Small (4-5)',
    'YS'   => 'Youth Small (6-8)',
    'YM'   => 'Youth Medium (10-12)',
    'YL'   => 'Youth Large (14-16)',
    'YXL'  => 'Youth X-Large (18-20)',
    'AXS'  => 'Adult X-Small',
    'AS'   => 'Adult Small',
    'AM'   => 'Adult Medium',
    'AL'   => 'Adult Large',
    'AXL'  => 'Adult X-Large',
    'A2XL' => 'Adult 2X-Large',
    'A3XL' => 'Adult 3X-Large',
];

/**
 * The 12 storable codes, matching migration 054's CHECK constraint.
 *
 * Derived from the label map rather than listed again, so a code can never exist
 * without a label (or vice versa). define() because a const expression cannot call
 * array_keys().
 */
if (!defined('TE_JERSEY_SIZES')) {
    define('TE_JERSEY_SIZES', array_keys(TE_JERSEY_SIZE_LABELS));
}

/** The option list to seed into a registration form's jersey-size select. */
function te_jersey_size_options(): array
{
    return array_values(TE_JERSEY_SIZE_LABELS);
}

/**
 * Group markers. Order matters: the whole-word forms are tried before the bare
 * Y/A prefix so 'ADULT SMALL' is not read as group 'A' + size 'DULT SMALL'.
 */
const TE_JERSEY_GROUP_WORDS = ['Y' => ['YOUTH', 'JUNIOR', 'JR'], 'A' => ['ADULT', 'SENIOR', 'SR', 'MENS', 'WOMENS']];

/** Size tokens, after separators are stripped ('X-LARGE' arrives as 'XLARGE'). */
const TE_JERSEY_SIZE_TOKENS = [
    'XXXL' => '3XL', '3XL' => '3XL', '3X' => '3XL', '3XLARGE' => '3XL', 'TRIPLEXLARGE' => '3XL',
    'XXL'  => '2XL', '2XL' => '2XL', '2X' => '2XL', '2XLARGE' => '2XL', 'DOUBLEXLARGE' => '2XL',
    'XL'   => 'XL',  'XLARGE' => 'XL', 'EXTRALARGE' => 'XL',
    'XS'   => 'XS',  'XSMALL' => 'XS', 'EXTRASMALL' => 'XS',
    'S'    => 'S',   'SMALL' => 'S',
    'M'    => 'M',   'MED' => 'M', 'MEDIUM' => 'M',
    'L'    => 'L',   'LARGE' => 'L',
];

/**
 * Coerce a submitted jersey size to a storable code, or NULL.
 *
 * Accepts the stored codes ('YM'), the form labels ('Youth Medium (10-12)'),
 * retitled labels ('Youth Medium'), and common vendor spellings ('AXXL'), because
 * the value can arrive from the athlete form, a public registration form whose
 * option text a club may have edited, or an import.
 *
 * Returns NULL for empty, absent or unresolvable input rather than throwing: a bad
 * size is not worth failing an otherwise valid athlete save over, and NULL is the
 * honest representation of "we do not know this athlete's size".
 *
 * Two NULL cases are load-bearing rather than defensive:
 *
 *  - '' => NULL. The athlete form submits every field it manages, so an athlete
 *    with no size on file sends jersey_size:''. That must land as NULL, not as an
 *    empty string that fails the CHECK and takes the whole save down with it.
 *
 *  - An unprefixed size ('M', 'Large') => NULL. Youth Medium and Adult Medium are
 *    very different garments, so a size with no group is genuinely ambiguous and
 *    must not be guessed into one or the other. Refusing it is the point of the
 *    Y/A prefix existing at all.
 */
function te_normalize_jersey_size($value): ?string
{
    if ($value === null) {
        return null;
    }

    $v = strtoupper(trim((string) $value));
    if ($v === '') {
        return null;
    }

    // Drop age hints like '(10-12)' and normalize separators to single spaces.
    $v = preg_replace('/\([^)]*\)/', ' ', $v);
    $v = trim(preg_replace('/[\s._\/]+/', ' ', $v));

    // Resolve the group (youth vs adult) and strip its marker off.
    $group = null;
    foreach (TE_JERSEY_GROUP_WORDS as $letter => $words) {
        foreach ($words as $word) {
            if (preg_match('/\b' . $word . '\b/', $v)) {
                $group = $letter;
                $v = preg_replace('/\b' . $word . '\b/', ' ', $v);
                break 2;
            }
        }
    }
    if ($group === null && preg_match('/^([YA])[\sA-Z0-9-]/', $v, $m)) {
        $group = $m[1];
        $v = substr($v, 1);
    }
    if ($group === null) {
        return null; // ambiguous or unrecognizable — see the doc block
    }

    // 'X-LARGE' / 'X LARGE' -> 'XLARGE' so one token table covers every spelling.
    $v = preg_replace('/[\s-]+/', '', $v);

    $size = TE_JERSEY_SIZE_TOKENS[$v] ?? null;
    if ($size === null) {
        return null;
    }

    // Final gate: youth has no 2XL/3XL, so 'Y2XL' correctly falls out here.
    $code = $group . $size;

    return in_array($code, TE_JERSEY_SIZES, true) ? $code : null;
}
