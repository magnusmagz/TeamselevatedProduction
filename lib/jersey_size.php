<?php
/**
 * Canonical jersey sizes, server side.
 *
 * The athletes.jersey_size CHECK constraint (migration 054) accepts exactly this
 * list. Anything else reaching an INSERT/UPDATE raises 23514, which the gateways'
 * try/catch would turn into a healthy-looking response that saved nothing — the
 * silent-failure pattern SchemaConformanceTest exists to prevent. So every write
 * path normalizes through te_normalize_jersey_size() first and never trusts the
 * value the frontend sent.
 *
 * Keep in sync with frontend/src/utils/jerseySize.ts.
 */

const TE_JERSEY_SIZES = [
    'YXS', 'YS', 'YM', 'YL', 'YXL',
    'AXS', 'AS', 'AM', 'AL', 'AXL', 'A2XL', 'A3XL',
];

/**
 * Code => the human label families actually see.
 *
 * The public registration form renders every `select` field generically: the
 * option label IS the submitted value (see renderField in
 * PublicRegistrationForm.tsx). So registrations arrive carrying 'Youth Medium
 * (10-12)', not 'YM', and te_normalize_jersey_size() has to resolve both. Showing
 * a parent a bare 'YM' would be jargon, so the label is what goes on the form and
 * this map is what turns it back into a storable code.
 *
 * These strings are also the `options` seeded into program_form_fields, so
 * changing one here without re-seeding breaks resolution for existing programs.
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

/** The option list to seed into a registration form's jersey-size select. */
function te_jersey_size_options(): array
{
    return array_values(TE_JERSEY_SIZE_LABELS);
}

/**
 * Coerce a submitted jersey size to a storable value.
 *
 * Returns NULL for empty/absent/unrecognized input rather than throwing: a bad
 * size is not worth failing an otherwise valid athlete save over, and NULL is the
 * honest representation of "we do not know this athlete's size".
 *
 * Note the '' => NULL mapping is load-bearing, not defensive. The athlete form
 * always sends every field it manages, so an athlete with no size on file submits
 * jersey_size:'' — which must land as NULL, not as an empty string that fails the
 * CHECK and takes the whole save down with it.
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

    // Human label from a registration form, e.g. 'Youth Medium (10-12)'. Matched
    // both with and without the age hint, so a club that retitles the option to
    // plain 'Youth Medium' in the form builder still resolves.
    foreach (TE_JERSEY_SIZE_LABELS as $code => $label) {
        $upper = strtoupper($label);
        if ($v === $upper || $v === trim(preg_replace('/\s*\([^)]*\)/', '', $upper))) {
            return $code;
        }
    }

    // Tolerate the common vendor spellings of the 2XL/3XL codes so a CSV import or
    // a hand-edited payload does not silently drop the size.
    $aliases = ['AXXL' => 'A2XL', 'AXXXL' => 'A3XL', 'A2X' => 'A2XL', 'A3X' => 'A3XL'];
    $v = $aliases[$v] ?? $v;

    return in_array($v, TE_JERSEY_SIZES, true) ? $v : null;
}
