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
    // Tolerate the common vendor spellings of the 2XL/3XL codes so a CSV import or
    // a hand-edited payload does not silently drop the size.
    $aliases = ['AXXL' => 'A2XL', 'AXXXL' => 'A3XL', 'A2X' => 'A2XL', 'A3X' => 'A3XL'];
    $v = $aliases[$v] ?? $v;

    return in_array($v, TE_JERSEY_SIZES, true) ? $v : null;
}
