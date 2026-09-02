<?php
/**
 * Field size, and which fields fit a team (CKU R73, slice 6.3).
 *
 * The club's rule: U8 and under play 4v4, U9/U10 play 7v7, U11/U12 play 9v9,
 * U13 and up play 11v11. That mapping is a RULE, so it lives here in code
 * rather than in a table a club could half-fill; what the database holds is the
 * one fact code cannot derive, `fields.field_size` (migration 088).
 *
 * ⚠️ Two things here are load-bearing and easy to "simplify" away:
 *
 * 1. **An unsized field is never hidden.** `field_size` is NULL for every row
 *    the day 088 is applied, and it stays NULL for any club that never fills it
 *    in. A picker that showed only matching fields would therefore show NOTHING
 *    to every club on day one — the feature would read as an outage. NULL means
 *    "nobody has recorded a size", which is a real answer, distinct from "this
 *    field is the wrong size", and the two are reported as different values of
 *    `size_match` (null vs false) rather than collapsed into one.
 *
 * 2. **Nothing is filtered out server-side, including the mismatches.** The
 *    requirement is that a team is steered to a correctly sized field, not that
 *    it is prevented from booking one. A club knows things this rule does not —
 *    a U13 side training on a 9v9 grid, a facility with one pitch. So
 *    te_fields_for_team() returns the club's whole field list and labels each
 *    row; the UI puts the matches first and warns on the rest. Dropping the
 *    mismatched rows here would silently remove fields a club can currently
 *    book, which is a regression wearing a feature's clothes.
 *
 * Every read of `field_size` tolerates the column being ABSENT. `main` is
 * shared and deploys are by push, so this code reaches production the moment
 * any session pushes, which may be days before migration 088 is applied to Neon
 * by hand. On Postgres a reference to a missing column is 42703 — a hard error
 * that would take the home-field dropdown down for every club, not just hide a
 * new feature. Same shape, and same reason, as lib/program_ordering.php.
 */

/**
 * The sizes migration 088's CHECK constraint allows.
 *
 * ⚠️ Adding one means a new migration AND a new arm in
 * te_field_size_for_age_group() — a size that can be stored but never matched
 * makes a field permanently "wrong size" for every team.
 *
 * There is deliberately no 5v5: the only small-sided format anywhere in this
 * codebase is 4v4 (sport_presets, migration 035, U5–U7 at 4 players on the
 * field), so 4v4 is what U8-and-under maps to here. If a club plays 5v5 that is
 * a rule change, not a typo to absorb.
 */
const TE_FIELD_SIZES = ['4v4', '7v7', '9v9', '11v11'];

// te_normalize_age_group() — the product's one parser for a U-group label.
require_once __DIR__ . '/age_rule.php';

/**
 * The age-group number out of a label, or null if there isn't one.
 *
 * ⚠️ The SPELLING is parsed by `te_normalize_age_group()` in lib/age_rule.php
 * and nowhere else. `teams.age_group` is free text and production holds several
 * shapes ('U12', 'U-12', 'u 12', '12U'); that function is the product's single
 * answer to "how is this label spelled", and a second parser here would be the
 * copied-age-logic mistake CLAUDE.md already records four times over. It
 * deliberately refuses anything that is not a single clean U-group — 'Open',
 * 'Adult', 'U10/U11' — and this file inherits that refusal, which is the right
 * default: an ambiguous label answers "no recommendation" and the picker
 * degrades to the flat list it has always shown.
 *
 * The 4–25 clamp is applied HERE rather than there, on purpose: age_rule.php's
 * normaliser answers a spelling question and te_age_group() owns the clamp on
 * the other path. A birth year cannot reach this anyway — '2012' is not a
 * U-group spelling — but the clamp keeps a nonsense group from producing a
 * confident '11v11'.
 *
 * There is no date arithmetic here at all, so none of the timezone rules that
 * govern DOB handling apply.
 */
function te_age_group_number(?string $ageGroup): ?int
{
    $label = te_normalize_age_group($ageGroup);
    if ($label === null) {
        return null;
    }
    $n = (int) substr($label, 1);
    return ($n >= 4 && $n <= 25) ? $n : null;
}

/** Canonical `U12` label for a readable youth group, or null. */
function te_age_group_label(?string $ageGroup): ?string
{
    $n = te_age_group_number($ageGroup);
    return $n === null ? null : 'U' . $n;
}

/**
 * The field size a team of this age group plays on, or null when the age group
 * is missing or unreadable.
 *
 * NULL is the honest answer for an unknown group, and callers must treat it as
 * "no opinion" rather than as a mismatch: a team with no age_group on file must
 * see its club's fields exactly as it does today.
 */
function te_field_size_for_age_group(?string $ageGroup): ?string
{
    $n = te_age_group_number($ageGroup);
    if ($n === null) {
        return null;
    }
    if ($n <= 8)  { return '4v4'; }
    if ($n <= 10) { return '7v7'; }
    if ($n <= 12) { return '9v9'; }
    return '11v11';
}

/**
 * Is a submitted size one migration 088 will accept?
 *
 * Empty string and null both mean "no size recorded" and normalise to NULL —
 * the facility form submits `field_size: ''` for a field nobody has sized, and
 * writing that straight to SQL would raise 23514 and roll back the whole
 * facility save. Same rule, and the same failure, as jersey_size.
 *
 * An unrecognised non-empty value also becomes NULL rather than throwing: this
 * value rides along with a much larger facility save, and refusing the whole
 * save over one select is worse than recording no size. (Contrast
 * te_classify_jersey_size_submission, which refuses — there the size IS the
 * request.)
 */
function te_normalize_field_size($raw): ?string
{
    if ($raw === null) {
        return null;
    }
    $value = trim((string) $raw);
    if ($value === '') {
        return null;
    }
    // Tolerate `7V7`, `7 v 7`, `7vs7`.
    $compact = strtolower(preg_replace('/\s+/', '', $value));
    $compact = preg_replace('/vs/', 'v', $compact);
    foreach (TE_FIELD_SIZES as $size) {
        if ($compact === strtolower($size)) {
            return $size;
        }
    }
    return null;
}

/**
 * Is `fields.field_size` live?
 *
 * One information_schema query per request, memoised. A failed probe answers
 * false — the degraded path is always the safe one.
 */
function te_field_size_column_present(PDO $pdo): bool
{
    static $present = null;
    if ($present !== null) {
        return $present;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM information_schema.columns
              WHERE table_name = 'fields' AND column_name = 'field_size'"
        );
        $stmt->execute();
        $present = (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('te_field_size_column_present: ' . $e->getMessage());
        $present = false;
    }

    return $present;
}

/**
 * Test seam: force the answer to the column probe, or pass null to clear.
 *
 * Kept deliberately explicit rather than reaching into a static, so a test that
 * exercises the column-absent path says so in one line and a production caller
 * cannot reach it by accident.
 */
function te_field_size_probe_override(?bool $value = null)
{
    static $override = null;
    if (func_num_args() > 0) {
        $override = $value;
    }
    return $override;
}

/** The probe, honouring a test override. */
function te_field_size_available(PDO $pdo): bool
{
    $override = te_field_size_probe_override();
    if ($override !== null) {
        return $override;
    }
    return te_field_size_column_present($pdo);
}

/**
 * How one field's size compares to what a team needs.
 *
 * @return bool|null true = fits, false = a size is recorded and it is not this
 *                   one, null = no opinion (field unsized, or the team has no
 *                   readable age group). null is NOT false; see the header.
 */
function te_field_size_match(?string $fieldSize, ?string $wantedSize): ?bool
{
    if ($wantedSize === null || $fieldSize === null || $fieldSize === '') {
        return null;
    }
    return $fieldSize === $wantedSize;
}

/**
 * Every field in a team's club, labelled with whether it fits that team.
 *
 * Scoped by the team's OWN club (`venues.club_id`), not by the caller's
 * accessible clubs: the question is "which fields can this team play on", and a
 * super admin asking it must get the same answer a club admin does. Callers
 * gate on team view access before calling.
 *
 * Rows are ordered fits-first, then unsized, then the wrong sizes — so a caller
 * that renders the list flat still puts the right answer at the top.
 *
 * @return array{
 *   team_id:int, age_group:?string, age_group_label:?string,
 *   recommended_size:?string, sizing_available:bool, fields:array
 * }
 */
function te_fields_for_team(PDO $pdo, int $teamId): array
{
    $stmt = $pdo->prepare('SELECT id, club_id, age_group FROM teams WHERE id = ?');
    $stmt->execute([$teamId]);
    $team = $stmt->fetch(PDO::FETCH_ASSOC);

    $ageGroup = $team['age_group'] ?? null;
    $wanted   = te_field_size_for_age_group($ageGroup);
    $hasSize  = te_field_size_available($pdo);

    $result = [
        'team_id'          => $teamId,
        'age_group'        => $ageGroup,
        'age_group_label'  => te_age_group_label($ageGroup),
        'recommended_size' => $hasSize ? $wanted : null,
        'sizing_available' => $hasSize,
        'fields'           => [],
    ];

    if (!$team) {
        return $result;
    }

    // Two live teams have a NULL club_id. `v.club_id = NULL` matches nothing in
    // SQL, so the query would answer an empty list — correct, but only by
    // accident. Answer it deliberately instead.
    if ($team['club_id'] === null) {
        return $result;
    }

    $sizeSelect = $hasSize ? 'f.field_size' : 'NULL';
    $stmt = $pdo->prepare("
        SELECT f.id,
               f.name,
               f.venue_id,
               v.name AS venue_name,
               f.field_type,
               f.surface_type,
               $sizeSelect AS field_size
        FROM fields f
        JOIN venues v ON f.venue_id = v.id
        WHERE v.club_id = ?
          AND f.active = true
        ORDER BY v.name, f.name
    ");
    $stmt->execute([(int) $team['club_id']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $fields = [];
    foreach ($rows as $row) {
        $size  = isset($row['field_size']) && $row['field_size'] !== '' ? (string) $row['field_size'] : null;
        $match = te_field_size_match($size, $hasSize ? $wanted : null);
        $fields[] = [
            'id'           => (int) $row['id'],
            'name'         => $row['name'],
            'venue_id'     => (int) $row['venue_id'],
            'venue_name'   => $row['venue_name'],
            'display_name' => $row['venue_name'] . ' - ' . $row['name'],
            'field_type'   => $row['field_type'] ?? null,
            'surface_type' => $row['surface_type'] ?? null,
            'field_size'   => $size,
            'size_match'   => $match,
        ];
    }

    // Fits first, then unsized, then the wrong sizes. Stable within each group
    // because usort is fed an already venue/field-ordered list and ties keep
    // their relative order via the index tiebreak.
    $rank = static function ($match): int {
        if ($match === true)  { return 0; }
        if ($match === null)  { return 1; }
        return 2;
    };
    $indexed = [];
    foreach ($fields as $i => $f) {
        $indexed[] = [$rank($f['size_match']), $i, $f];
    }
    usort($indexed, static function ($a, $b) {
        return $a[0] <=> $b[0] ?: $a[1] <=> $b[1];
    });
    $result['fields'] = array_column($indexed, 2);

    return $result;
}
