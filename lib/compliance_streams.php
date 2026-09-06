<?php
/**
 * Admin-authored reminder streams (GOTR G7, docs/gotr-hierarchy-plan-2026-09.md §4).
 *
 * A stream is a requirement's own reminder cadence, written by the tier that
 * wants it: an ordered list of steps, each an offset from the expiry date with
 * its own subject and body. Migration 091 modelled the table; migration 093
 * made the default 90/60/30/7 cadence `stream_id IS NULL` in the log; this
 * file is the authoring and the resolution. lib/compliance_reminders.php is
 * the dispatch.
 *
 * ⚠️ THE RESOLUTION RULE — exactly one stream applies to a credential:
 *
 *   1. the CLUB's own active stream for the requirement, else
 *   2. the NEAREST ANCESTOR org unit's active stream (council before division
 *      before national), else
 *   3. the default cadence (TE_COMPLIANCE_REMINDER_THRESHOLDS), which has no
 *      row and logs with stream_id NULL.
 *
 * Steps are NEVER merged across tiers. A council that writes a 14-day step
 * does not add it to national's list; it replaces national's list for its
 * clubs. Merging would make "what will this coach receive" unanswerable from
 * any one screen. Deactivating a stream falls back to the next tier, never to
 * silence: the default cadence exists so that a switched-off stream cannot
 * quietly stop anybody being reminded.
 *
 * ⚠️ MERGE TAGS ARE A CLOSED LIST (TE_COMPLIANCE_STREAM_TAGS). Anything else
 * is refused at SAVE time with the tag named, so the author sees it in the
 * form. At send time a tag that resolves to nothing blocks that send and is
 * reported — a coach is never mailed `{{first_name}}` literally. Same rule as
 * the unresolved-`{{tag}}` guard in EmailSendService, for the same reason.
 *
 * Offsets: `days_before` is days BEFORE expiry. A negative value is days AFTER
 * it (-7 = one week past), which is how "you have lapsed, here is how to
 * renew" is authored. A post-expiry step sends at most once, because the log
 * is keyed on (credential, stream, days_before) and an expired credential's
 * day count only keeps falling.
 *
 * Authoring standing is the TIER's: te_compliance_can_admin_club for a club
 * stream, org_admin standing for an org-unit stream. A stream can only be
 * attached where the requirement actually applies — a club cannot author for
 * a requirement it does not inherit, and an org unit cannot author for one
 * that lives below it.
 */

require_once __DIR__ . '/compliance.php';
require_once __DIR__ . '/org_scope.php';

/** The merge tags a step may use. Anything else is a 422. */
const TE_COMPLIANCE_STREAM_TAGS = [
    'first_name', 'requirement_name', 'expires_on', 'days_left', 'club_name', 'renewal_url',
];

/** Widest offset either side of expiry a step may carry, in days. */
const TE_COMPLIANCE_STREAM_MAX_OFFSET = 730;

/** Longest a stream may be. Twelve steps is a monthly cadence for a year. */
const TE_COMPLIANCE_STREAM_MAX_STEPS = 12;

const TE_COMPLIANCE_STREAM_MAX_SUBJECT = 200;
const TE_COMPLIANCE_STREAM_MAX_BODY = 5000;

// ------------------------------------------------------------- validation ---

/** Every `{{tag}}` in a piece of text, in order, de-duplicated. */
function te_compliance_stream_tags_in(string $text): array
{
    if (!preg_match_all('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', $text, $m)) {
        return [];
    }
    return array_values(array_unique($m[1]));
}

/**
 * Normalise and validate a submitted steps list.
 *
 * Returns the cleaned list ordered largest offset first (the order they fire),
 * or a reason the form can render: no_steps, too_many, bad_offset,
 * duplicate_offset, blank_subject, blank_body, too_long, bad_channel,
 * unknown_tag (with `unknown_tags` naming them).
 *
 * @return array{ok: bool, steps?: array, error?: string, reason?: string, unknown_tags?: string[]}
 */
function te_compliance_stream_validate_steps($steps): array
{
    if (!is_array($steps) || !$steps) {
        return ['ok' => false, 'reason' => 'no_steps', 'error' => 'A stream needs at least one step'];
    }
    if (count($steps) > TE_COMPLIANCE_STREAM_MAX_STEPS) {
        return ['ok' => false, 'reason' => 'too_many', 'error' => 'A stream may have at most ' . TE_COMPLIANCE_STREAM_MAX_STEPS . ' steps'];
    }

    $clean = [];
    $seen = [];
    $unknown = [];
    foreach (array_values($steps) as $i => $step) {
        $n = $i + 1;
        if (!is_array($step)) {
            return ['ok' => false, 'reason' => 'bad_step', 'error' => "Step $n is not an object"];
        }
        $raw = $step['days_before'] ?? null;
        if ($raw === null || $raw === '' || !is_numeric($raw) || (string) (int) $raw !== (string) trim((string) $raw)) {
            return ['ok' => false, 'reason' => 'bad_offset', 'error' => "Step $n needs a whole number of days"];
        }
        $days = (int) $raw;
        if (abs($days) > TE_COMPLIANCE_STREAM_MAX_OFFSET) {
            return ['ok' => false, 'reason' => 'bad_offset', 'error' => "Step $n is more than " . TE_COMPLIANCE_STREAM_MAX_OFFSET . ' days from expiry'];
        }
        if (isset($seen[$days])) {
            return ['ok' => false, 'reason' => 'duplicate_offset', 'error' => "Two steps are both $days days from expiry"];
        }
        $seen[$days] = true;

        $subject = trim((string) ($step['subject'] ?? ''));
        $body = trim((string) ($step['body'] ?? ''));
        if ($subject === '') {
            return ['ok' => false, 'reason' => 'blank_subject', 'error' => "Step $n has no subject"];
        }
        if ($body === '') {
            return ['ok' => false, 'reason' => 'blank_body', 'error' => "Step $n has no body"];
        }
        if (mb_strlen($subject) > TE_COMPLIANCE_STREAM_MAX_SUBJECT || mb_strlen($body) > TE_COMPLIANCE_STREAM_MAX_BODY) {
            return ['ok' => false, 'reason' => 'too_long', 'error' => "Step $n is too long"];
        }
        $channel = strtolower(trim((string) ($step['channel'] ?? 'email')));
        if ($channel !== 'email') {
            return ['ok' => false, 'reason' => 'bad_channel', 'error' => "Step $n: only email is supported"];
        }

        foreach (te_compliance_stream_tags_in($subject . ' ' . $body) as $tag) {
            if (!in_array($tag, TE_COMPLIANCE_STREAM_TAGS, true) && !in_array($tag, $unknown, true)) {
                $unknown[] = $tag;
            }
        }

        $clean[] = ['days_before' => $days, 'subject' => $subject, 'body' => $body, 'channel' => 'email'];
    }

    if ($unknown) {
        return [
            'ok' => false, 'reason' => 'unknown_tag', 'unknown_tags' => $unknown,
            'error' => 'Unknown merge tag' . (count($unknown) > 1 ? 's' : '') . ': '
                . implode(', ', array_map(static fn (string $t): string => '{{' . $t . '}}', $unknown))
                . '. Allowed: ' . implode(', ', array_map(static fn (string $t): string => '{{' . $t . '}}', TE_COMPLIANCE_STREAM_TAGS)),
        ];
    }

    usort($clean, static fn (array $a, array $b): int => $b['days_before'] <=> $a['days_before']);
    return ['ok' => true, 'steps' => $clean];
}

/**
 * Fill the tags in one piece of text. A value that is null or '' is
 * UNRESOLVED and left in place, and named in `missing` so the caller can
 * refuse to send. Zero is a value ("0 days left"), not a gap.
 *
 * @return array{text: string, missing: string[]}
 */
function te_compliance_stream_render(string $text, array $values): array
{
    $missing = [];
    $out = preg_replace_callback('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', function (array $m) use ($values, &$missing): string {
        $tag = $m[1];
        $value = $values[$tag] ?? null;
        if ($value === null || $value === '') {
            if (!in_array($tag, $missing, true)) {
                $missing[] = $tag;
            }
            return $m[0];
        }
        return (string) $value;
    }, $text);
    return ['text' => (string) $out, 'missing' => $missing];
}

// ------------------------------------------------------------------ rows ---

/** Decode a stored row: ints, a real boolean, and steps as an array. */
function te_compliance_stream_normalize(array $row): array
{
    $steps = $row['steps'] ?? '[]';
    if (is_string($steps)) {
        $steps = json_decode($steps, true);
    }
    $clean = [];
    foreach (is_array($steps) ? $steps : [] as $step) {
        if (!is_array($step)) {
            continue;
        }
        $clean[] = [
            'days_before' => (int) ($step['days_before'] ?? 0),
            'subject'     => (string) ($step['subject'] ?? ''),
            'body'        => (string) ($step['body'] ?? $step['body_markdown'] ?? ''),
            'channel'     => (string) ($step['channel'] ?? 'email'),
        ];
    }
    usort($clean, static fn (array $a, array $b): int => $b['days_before'] <=> $a['days_before']);

    return [
        'id'              => (int) $row['id'],
        'requirement_id'  => (int) $row['requirement_id'],
        'org_unit_id'     => ($row['org_unit_id'] === null || $row['org_unit_id'] === '') ? null : (int) $row['org_unit_id'],
        'club_profile_id' => ($row['club_profile_id'] === null || $row['club_profile_id'] === '') ? null : (int) $row['club_profile_id'],
        'active'          => te_compliance_bool($row['active'] ?? false),
        'steps'           => $clean,
        'created_by'      => isset($row['created_by']) && $row['created_by'] !== null ? (int) $row['created_by'] : null,
        'created_at'      => $row['created_at'] ?? null,
        'updated_at'      => $row['updated_at'] ?? null,
    ];
}

function te_compliance_stream_get(PDO $pdo, int $id): ?array
{
    if ($id <= 0 || !te_compliance_tables_present($pdo)) {
        return null;
    }
    try {
        $stmt = $pdo->prepare('SELECT * FROM compliance_reminder_streams WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('te_compliance_stream_get: ' . $e->getMessage());
        return null;
    }
    return $row ? te_compliance_stream_normalize($row) : null;
}

/** The one row for a requirement at a tier, active or not. */
function te_compliance_stream_at_tier(PDO $pdo, int $requirementId, int $clubId, int $orgUnitId): ?array
{
    if (!te_compliance_tables_present($pdo)) {
        return null;
    }
    try {
        if ($clubId > 0) {
            $stmt = $pdo->prepare('SELECT * FROM compliance_reminder_streams WHERE requirement_id = ? AND club_profile_id = ? ORDER BY id LIMIT 1');
            $stmt->execute([$requirementId, $clubId]);
        } else {
            $stmt = $pdo->prepare('SELECT * FROM compliance_reminder_streams WHERE requirement_id = ? AND org_unit_id = ? ORDER BY id LIMIT 1');
            $stmt->execute([$requirementId, $orgUnitId]);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('te_compliance_stream_at_tier: ' . $e->getMessage());
        return null;
    }
    return $row ? te_compliance_stream_normalize($row) : null;
}

/**
 * Does the requirement apply at this tier — i.e. may a stream for it live here?
 *
 * Club tier: the club inherits it (its own row or an ancestor's). Org tier: the
 * requirement is owned by the unit or one of its ancestors. A requirement on a
 * council cannot get a stream at the division: the division's clubs outside
 * that council never see the requirement, so the stream would be unreachable
 * from where it was written.
 */
function te_compliance_stream_tier_applies(PDO $pdo, int $requirementId, int $clubId, int $orgUnitId): bool
{
    if ($clubId > 0) {
        foreach (te_compliance_requirements_for_club($pdo, $clubId) as $req) {
            if ((int) $req['id'] === $requirementId) {
                return true;
            }
        }
        return false;
    }
    if ($orgUnitId <= 0 || !te_org_tables_present($pdo)) {
        return false;
    }
    try {
        $stmt = $pdo->prepare('SELECT org_unit_id FROM compliance_requirements WHERE id = ?');
        $stmt->execute([$requirementId]);
        $owner = $stmt->fetchColumn();
        if ($owner === false || $owner === null || $owner === '') {
            return false;
        }
        foreach (te_compliance_stream_ancestor_units($pdo, $orgUnitId) as $unit) {
            if ($unit['id'] === (int) $owner) {
                return true;
            }
        }
    } catch (Throwable $e) {
        error_log('te_compliance_stream_tier_applies: ' . $e->getMessage());
    }
    return false;
}

/**
 * The unit itself and every ancestor, deepest first.
 *
 * @return array<int, array{id:int, depth:int, type:string, name:string}>
 */
function te_compliance_stream_ancestor_units(PDO $pdo, int $orgUnitId): array
{
    if ($orgUnitId <= 0 || !te_org_tables_present($pdo)) {
        return [];
    }
    try {
        $stmt = $pdo->prepare(
            "SELECT a.id, a.depth, a.type, a.name
               FROM org_units a
               JOIN org_units o ON o.path LIKE a.path || '%'
              WHERE o.id = ?
              ORDER BY a.depth DESC"
        );
        $stmt->execute([$orgUnitId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('te_compliance_stream_ancestor_units: ' . $e->getMessage());
        return [];
    }
    return array_map(static fn (array $r): array => [
        'id' => (int) $r['id'], 'depth' => (int) $r['depth'], 'type' => (string) $r['type'], 'name' => (string) $r['name'],
    ], $rows);
}

// ------------------------------------------------------------- authoring ---

/**
 * May this person attach a stream at this tier? Exactly one of club / org unit.
 *
 * The same predicates the requirement builder uses: te_compliance_can_admin_club
 * for a club (club admin, or org_admin over the club's unit) and org_admin
 * standing for a unit. Nothing weaker — a coach is team-scoped and a stream
 * mails every staff member in the club.
 */
function te_compliance_stream_can_author(PDO $pdo, $auth, int $clubId, int $orgUnitId): bool
{
    if (($clubId > 0) === ($orgUnitId > 0)) {
        return false;
    }
    if ($clubId > 0) {
        return te_compliance_can_admin_club($pdo, $auth, $clubId);
    }
    return te_user_org_standing($pdo, $auth, $orgUnitId) === 'org_admin';
}

/**
 * Create or update a stream.
 *
 * On update the tier and requirement come from the STORED row, never the body —
 * the same rule as requirement-save, so a stream cannot be re-homed onto a
 * tier the caller administers and then edited. A create at a tier that already
 * has a row for the requirement updates that row: two rows at one tier would
 * make the resolution rule ambiguous.
 *
 * Authorization is the caller's (te_compliance_stream_can_author); this
 * function validates the shape and the tier.
 *
 * @param array $data id?, requirement_id, club_profile_id | org_unit_id, steps, active?
 * @return array{ok: bool, id?: int, reason?: string, error?: string, unknown_tags?: string[]}
 */
function te_compliance_stream_save(PDO $pdo, array $data, ?int $actorId = null): array
{
    if (!te_compliance_tables_present($pdo)) {
        return ['ok' => false, 'reason' => 'schema', 'error' => 'Compliance tables are not present'];
    }

    $id = (int) ($data['id'] ?? 0);
    $existing = $id > 0 ? te_compliance_stream_get($pdo, $id) : null;
    if ($id > 0 && !$existing) {
        return ['ok' => false, 'reason' => 'not_found', 'error' => 'Stream not found'];
    }

    if ($existing) {
        $requirementId = $existing['requirement_id'];
        $clubId = (int) ($existing['club_profile_id'] ?? 0);
        $orgUnitId = (int) ($existing['org_unit_id'] ?? 0);
    } else {
        $requirementId = (int) ($data['requirement_id'] ?? 0);
        $clubId = (int) ($data['club_profile_id'] ?? 0);
        $orgUnitId = (int) ($data['org_unit_id'] ?? 0);
        if ($requirementId <= 0) {
            return ['ok' => false, 'reason' => 'requirement_required', 'error' => 'requirement_id is required'];
        }
        if (($clubId > 0) === ($orgUnitId > 0)) {
            return ['ok' => false, 'reason' => 'one_tier', 'error' => 'A stream belongs to exactly one of a club or an org unit'];
        }
        if (!te_compliance_stream_tier_applies($pdo, $requirementId, $clubId, $orgUnitId)) {
            return ['ok' => false, 'reason' => 'requirement_not_at_tier', 'error' => 'That requirement does not apply at this tier'];
        }
        $same = te_compliance_stream_at_tier($pdo, $requirementId, $clubId, $orgUnitId);
        if ($same) {
            $existing = $same;
            $id = $same['id'];
        }
    }

    $validated = array_key_exists('steps', $data) || !$existing
        ? te_compliance_stream_validate_steps($data['steps'] ?? [])
        : ['ok' => true, 'steps' => $existing['steps']];
    if (!$validated['ok']) {
        return ['ok' => false] + $validated;
    }

    $active = array_key_exists('active', $data)
        ? te_compliance_bool($data['active'])
        : ($existing['active'] ?? false);
    $now = date('Y-m-d H:i:s');
    $json = json_encode($validated['steps'], JSON_UNESCAPED_UNICODE);

    try {
        if ($existing) {
            $pdo->prepare('UPDATE compliance_reminder_streams SET steps = ?, active = ?, updated_at = ? WHERE id = ?')
                ->execute([$json, $active ? 1 : 0, $now, $id]);
            return ['ok' => true, 'id' => $id, 'created' => false];
        }
        $pdo->prepare(
            'INSERT INTO compliance_reminder_streams
                (requirement_id, org_unit_id, club_profile_id, active, steps, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $requirementId, $orgUnitId > 0 ? $orgUnitId : null, $clubId > 0 ? $clubId : null,
            $active ? 1 : 0, $json, $actorId ?: null, $now, $now,
        ]);
        return ['ok' => true, 'id' => te_org_last_insert_id($pdo, 'compliance_reminder_streams_id_seq'), 'created' => true];
    } catch (Throwable $e) {
        error_log('te_compliance_stream_save: ' . $e->getMessage());
        return ['ok' => false, 'reason' => 'error', 'error' => 'Could not save the stream'];
    }
}

/** Switch a stream on or off. Off falls back to the next tier (see the header). */
function te_compliance_stream_set_active(PDO $pdo, int $id, bool $active): array
{
    $existing = te_compliance_stream_get($pdo, $id);
    if (!$existing) {
        return ['ok' => false, 'reason' => 'not_found'];
    }
    try {
        $pdo->prepare('UPDATE compliance_reminder_streams SET active = ?, updated_at = ? WHERE id = ?')
            ->execute([$active ? 1 : 0, date('Y-m-d H:i:s'), $id]);
    } catch (Throwable $e) {
        error_log('te_compliance_stream_set_active: ' . $e->getMessage());
        return ['ok' => false, 'reason' => 'error'];
    }
    return ['ok' => true, 'id' => $id, 'active' => $active];
}

// ------------------------------------------------------------ resolution ---

/**
 * The stream that applies to (requirement, club), or null for the default.
 *
 * The club's own active row, else the nearest ancestor unit's. The org rows are
 * fetched with their unit's depth so "nearest" is one comparison, and the
 * ancestor set is derived in SQL from the club's org_unit_id the same way
 * te_compliance_requirements_for_club does it.
 *
 * The returned row carries `tier` ('club' | 'org_unit') and, for an org row,
 * `tier_unit` (id, type, name) so the caller can say where it came from.
 */
function te_compliance_stream_resolve(PDO $pdo, int $requirementId, int $clubId): ?array
{
    if ($requirementId <= 0 || $clubId <= 0 || !te_compliance_tables_present($pdo)) {
        return null;
    }

    $true = te_compliance_true_literal($pdo);
    try {
        $stmt = $pdo->prepare(
            "SELECT * FROM compliance_reminder_streams
              WHERE requirement_id = ? AND club_profile_id = ? AND active = $true
              ORDER BY id LIMIT 1"
        );
        $stmt->execute([$requirementId, $clubId]);
        $own = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($own) {
            return te_compliance_stream_normalize($own) + ['tier' => 'club', 'tier_unit' => null];
        }

        if (!te_org_tables_present($pdo)) {
            return null;
        }
        $stmt = $pdo->prepare(
            "SELECT s.*, a.depth AS unit_depth, a.type AS unit_type, a.name AS unit_name
               FROM compliance_reminder_streams s
               JOIN org_units a ON a.id = s.org_unit_id
               JOIN org_units o ON o.path LIKE a.path || '%'
               JOIN club_profile c ON c.org_unit_id = o.id
              WHERE s.requirement_id = ? AND s.active = $true AND c.id = ?
              ORDER BY a.depth DESC, s.id
              LIMIT 1"
        );
        $stmt->execute([$requirementId, $clubId]);
        $inherited = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('te_compliance_stream_resolve: ' . $e->getMessage());
        return null;
    }
    if (!$inherited) {
        return null;
    }
    return te_compliance_stream_normalize($inherited) + [
        'tier'      => 'org_unit',
        'tier_unit' => [
            'id' => (int) $inherited['org_unit_id'], 'type' => (string) $inherited['unit_type'],
            'name' => (string) $inherited['unit_name'], 'depth' => (int) $inherited['unit_depth'],
        ],
    ];
}

/**
 * What the panel renders: which stream applies here and the club's own row
 * (active or not) so it can be edited or switched back on.
 *
 * @return array{applies: 'own'|'inherited'|'default', stream: ?array, own: ?array,
 *               inherited_from: ?array, default_thresholds: int[], tags: string[]}
 */
function te_compliance_stream_describe(PDO $pdo, int $requirementId, int $clubId): array
{
    $own = te_compliance_stream_at_tier($pdo, $requirementId, $clubId, 0);
    $applies = te_compliance_stream_resolve($pdo, $requirementId, $clubId);

    $mode = 'default';
    $from = null;
    if ($applies && $applies['tier'] === 'club') {
        $mode = 'own';
    } elseif ($applies) {
        $mode = 'inherited';
        $from = $applies['tier_unit'];
    }

    return [
        'applies'            => $mode,
        'stream'             => $applies,
        'own'                => $own,
        'inherited_from'     => $from,
        'default_thresholds' => defined('TE_COMPLIANCE_REMINDER_THRESHOLDS') ? TE_COMPLIANCE_REMINDER_THRESHOLDS : [90, 60, 30, 7],
        'tags'               => TE_COMPLIANCE_STREAM_TAGS,
    ];
}

/** The same shape for an org-unit tier: the unit's own row and what its clubs would inherit above it. */
function te_compliance_stream_describe_unit(PDO $pdo, int $requirementId, int $orgUnitId): array
{
    $own = te_compliance_stream_at_tier($pdo, $requirementId, 0, $orgUnitId);
    $mode = 'default';
    $applies = null;
    $from = null;

    if ($own && $own['active']) {
        $mode = 'own';
        $applies = $own + ['tier' => 'org_unit', 'tier_unit' => null];
    } else {
        // The nearest ancestor ABOVE this unit with an active row.
        foreach (te_compliance_stream_ancestor_units($pdo, $orgUnitId) as $unit) {
            if ($unit['id'] === $orgUnitId) {
                continue;
            }
            $row = te_compliance_stream_at_tier($pdo, $requirementId, 0, $unit['id']);
            if ($row && $row['active']) {
                $mode = 'inherited';
                $from = $unit;
                $applies = $row + ['tier' => 'org_unit', 'tier_unit' => $unit];
                break;
            }
        }
    }

    return [
        'applies'            => $mode,
        'stream'             => $applies,
        'own'                => $own,
        'inherited_from'     => $from,
        'default_thresholds' => defined('TE_COMPLIANCE_REMINDER_THRESHOLDS') ? TE_COMPLIANCE_REMINDER_THRESHOLDS : [90, 60, 30, 7],
        'tags'               => TE_COMPLIANCE_STREAM_TAGS,
    ];
}

// -------------------------------------------------------------- dispatch ---

/**
 * Which step, if any, is owed right now.
 *
 * A step is eligible when the day count has reached it (days_to_expiry <=
 * days_before — true for a post-expiry step once the credential is that far
 * past). Of the eligible steps the SMALLEST offset wins, same as the default
 * cadence's rule 4: a credential first seen 20 days out gets the 30-day step,
 * not the 90-day one, and not three emails. Anything already in `$sent`
 * (days_before => true) is skipped, and once a smaller step has gone the
 * larger ones are never revisited because the day count only falls.
 *
 * ⚠️ One step per tick per credential, on purpose. If a credential is eligible
 * for a step it has not had AND a smaller one, only the smaller goes — the
 * larger was for a moment that has passed.
 */
function te_compliance_stream_step_due(array $steps, ?int $daysToExpiry, array $sent): ?array
{
    if ($daysToExpiry === null) {
        return null;
    }
    $best = null;
    foreach ($steps as $step) {
        $offset = (int) ($step['days_before'] ?? 0);
        if ($daysToExpiry > $offset) {
            continue; // not reached yet
        }
        // A pre-expiry step is only ever sent BEFORE expiry. A credential
        // first seen three days past its date must not get "expires in 14
        // days" — that sentence is false, and the post-expiry step (if the
        // stream has one) is the one written for this moment.
        if ($daysToExpiry < 0 && $offset >= 0) {
            continue;
        }
        if ($best === null || $offset < $best['days_before']) {
            $best = $step;
            $best['days_before'] = $offset;
        }
    }
    if ($best === null || isset($sent[$best['days_before']])) {
        return null;
    }
    return $best;
}

/** The widest offsets across every ACTIVE stream, for the candidate scan. [max, min] or null. */
function te_compliance_stream_offset_bounds(PDO $pdo): ?array
{
    if (!te_compliance_tables_present($pdo)) {
        return null;
    }
    $true = te_compliance_true_literal($pdo);
    try {
        $stmt = $pdo->query("SELECT steps FROM compliance_reminder_streams WHERE active = $true");
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
    } catch (Throwable $e) {
        error_log('te_compliance_stream_offset_bounds: ' . $e->getMessage());
        return null;
    }
    $max = null;
    $min = null;
    foreach ($rows as $json) {
        foreach (te_compliance_stream_normalize(['id' => 0, 'requirement_id' => 0, 'org_unit_id' => null, 'club_profile_id' => null, 'steps' => $json])['steps'] as $step) {
            $d = $step['days_before'];
            $max = $max === null ? $d : max($max, $d);
            $min = $min === null ? $d : min($min, $d);
        }
    }
    return $max === null ? null : [$max, $min];
}
