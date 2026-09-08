<?php
/**
 * The field a game is played on — calendar_events.field_id (migration 100).
 *
 * One place answers three questions so no gateway re-derives any of them:
 *
 *  1. Is the column live yet?  te_event_field_available()
 *     `main` is shared and deploys are by push, so this code can reach
 *     production before migration 100 is applied by hand. On Postgres a
 *     reference to a missing column is 42703 — a hard error that would take
 *     the calendar, the referee page and every merge-tag send down, not merely
 *     hide a new feature. Every read below tolerates the column being ABSENT
 *     and degrades to "no field". Same shape as lib/field_size.php.
 *
 *  2. Which SQL do I add to a query?  te_event_field_select() / te_event_field_join()
 *     Every list that shows a game gets `field_id`, `field_name`, `field_size`
 *     from ONE LEFT JOIN, or NULL literals when the column is not there.
 *
 *  3. Is this submitted field_id acceptable?  te_event_field_validate()
 *     A field must exist, be active, and belong to the event's venue. A field
 *     from another venue is a 422, never silently re-pointed — the person
 *     chose a venue and a pitch that is not at it, and only they know which
 *     half is wrong. Size mismatches are NOT refused here: lib/field_size.php's
 *     rule is that a wrong-sized field is offered with a warning, never
 *     blocked, and a write path that blocked it would contradict the picker.
 *
 * Display: the UI composes "Venue · Field" itself (frontend/src/utils/eventWhere.ts)
 * and merge tags compose "Venue, Field" (MergeFieldService). Nothing here
 * writes composed text into `location` — that column stays the free-text
 * fallback it has always been, so clearing the field never leaves a stale
 * "North Park · Field 2" behind in a column nobody re-reads.
 */

/** Test seam: force the answer to the column probe, or pass null to clear. */
function te_event_field_probe_override(?bool $value = null)
{
    static $override = null;
    if (func_num_args() > 0) {
        $override = $value;
    }
    return $override;
}

/**
 * Is calendar_events.field_id live? Memoised per PDO handle.
 *
 * Postgres is asked through information_schema — the authoritative answer,
 * and one that cannot poison an open transaction the way a failed SELECT on a
 * missing column does (a failed statement aborts the whole transaction on
 * Postgres). SQLite has no information_schema, so the test fixtures are probed
 * with a SELECT instead. A failed probe answers false: the degraded path is
 * always the safe one.
 */
function te_event_field_column_present(PDO $pdo): bool
{
    static $memo = null;
    $memo ??= new WeakMap();
    if (isset($memo[$pdo])) {
        return $memo[$pdo];
    }
    try {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
            $stmt = $pdo->prepare(
                "SELECT 1 FROM information_schema.columns
                  WHERE table_name = 'calendar_events' AND column_name = 'field_id'"
            );
            $stmt->execute();
            return $memo[$pdo] = (bool) $stmt->fetchColumn();
        }
        $pdo->query('SELECT field_id FROM calendar_events LIMIT 1');
        return $memo[$pdo] = true;
    } catch (Throwable $e) {
        error_log('te_event_field_column_present: ' . $e->getMessage());
        return $memo[$pdo] = false;
    }
}

/** The probe, honouring a test override. */
function te_event_field_available(PDO $pdo): bool
{
    $override = te_event_field_probe_override();
    if ($override !== null) {
        return $override;
    }
    return te_event_field_column_present($pdo);
}

/**
 * Select-list fragment (leading comma) adding field_id / field_name /
 * field_size to a query over calendar_events aliased `$alias`. Pair with
 * te_event_field_join(). `field_size` rides along only when migration 088's
 * column is live (lib/field_size.php owns that probe).
 */
function te_event_field_select(PDO $pdo, string $alias = 'e', string $fieldAlias = 'ef'): string
{
    if (!te_event_field_available($pdo)) {
        return ', NULL AS field_id, NULL AS field_name, NULL AS field_size';
    }
    require_once __DIR__ . '/field_size.php';
    $size = te_field_size_available($pdo) ? "{$fieldAlias}.field_size" : 'NULL';
    return ", {$alias}.field_id AS field_id, {$fieldAlias}.name AS field_name, {$size} AS field_size";
}

/** The LEFT JOIN that te_event_field_select() reads from, or '' when the column is absent. */
function te_event_field_join(PDO $pdo, string $alias = 'e', string $fieldAlias = 'ef'): string
{
    if (!te_event_field_available($pdo)) {
        return '';
    }
    return " LEFT JOIN fields {$fieldAlias} ON {$fieldAlias}.id = {$alias}.field_id ";
}

/**
 * Validate a submitted field against the event's venue.
 *
 * @param mixed $rawFieldId  '', null, 0 → no field (value null, no error).
 * @param mixed $rawVenueId  the venue the SAME request is saving.
 * @return array{value: ?int, error: ?string}
 */
function te_event_field_validate(PDO $pdo, $rawFieldId, $rawVenueId): array
{
    if ($rawFieldId === null || $rawFieldId === '' || $rawFieldId === 0 || $rawFieldId === '0') {
        return ['value' => null, 'error' => null];
    }
    if (!is_numeric($rawFieldId) || (int) $rawFieldId <= 0) {
        return ['value' => null, 'error' => 'field_id must be a field id'];
    }
    $fieldId = (int) $rawFieldId;
    $venueId = ($rawVenueId === null || $rawVenueId === '') ? null : (int) $rawVenueId;
    if ($venueId === null || $venueId <= 0) {
        return ['value' => null, 'error' => 'A field needs a facility: choose the facility first'];
    }

    $stmt = $pdo->prepare('SELECT id, venue_id, active FROM fields WHERE id = ?');
    $stmt->execute([$fieldId]);
    $field = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$field) {
        return ['value' => null, 'error' => 'That field does not exist'];
    }
    if ((int) $field['venue_id'] !== $venueId) {
        return ['value' => null, 'error' => 'That field is not at the chosen facility'];
    }
    $active = $field['active'];
    $isActive = is_bool($active) ? $active : in_array(strtolower((string) $active), ['1', 't', 'true'], true);
    if (!$isActive) {
        return ['value' => null, 'error' => 'That field is no longer active'];
    }
    return ['value' => $fieldId, 'error' => null];
}

/**
 * "Venue · Field" for server-rendered text (ICS location, emails). Falls back
 * to the venue alone, then the free-text location, then ''. The frontend has
 * its own copy of this rule in utils/eventWhere.ts — keep the two in step.
 */
function te_event_place_label(?string $venueName, ?string $fieldName, ?string $location, string $sep = ' · '): string
{
    $venue = trim((string) $venueName);
    $field = trim((string) $fieldName);
    if ($venue !== '' && $field !== '') {
        return $venue . $sep . $field;
    }
    if ($venue !== '') {
        return $venue;
    }
    if ($field !== '') {
        return $field;
    }
    return trim((string) $location);
}
