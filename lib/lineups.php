<?php
/**
 * Lineup builder (CKU R67, slice 8.5, migration 096).
 * Spec: docs/lineup-builder-spec-2026-09.md.
 *
 * Every decision api/lineups.php makes lives here, as request handlers that
 * RETURN {status, body, audit} instead of emitting, so LineupTest executes the
 * real 403/404/422 paths against SQLite. The gateway is HTTP only: routing,
 * echo, and the audit row from the `audit` element.
 *
 * ── Who may do what ─────────────────────────────────────────────────────────
 *
 *   READ (staff)  team view standing AND staff: te_team_roster_staff_standing()
 *                 on the team, or te_event_staff_standing() on the game (a club
 *                 admin, or a coach of a team ON the event). Full shape: slots,
 *                 bench order, notes, the roster, attendance, the presets.
 *   READ (crew)   te_team_view_standing() — a guardian of a player on the team.
 *                 403 unless the game's lineup has `published_at` (decision 1);
 *                 then the REDUCED shape only: on-field slots + bench names.
 *                 No notes, no bench order, no roster, no attendance. The
 *                 template is never published.
 *   WRITE         te_event_staff_standing() for a game lineup;
 *                 te_team_roster_staff_standing() for the template. A parent
 *                 never writes.
 *
 * ── Validation (te_lineup_validate) ─────────────────────────────────────────
 * formation belongs to the field size; every slot code is in that formation or
 * BENCH; on-field count ≤ field players; no athlete twice; no field slot twice;
 * every athlete is a roster member with status active / injured / suspended.
 * Injured or suspended on the BENCH is kept and reported as a warning — never
 * silently dropped; on the FIELD it is refused with a sentence.
 *
 * ── Date-only rule ──────────────────────────────────────────────────────────
 * "Last game" compares calendar_events.event_date as a STRING against the
 * target game's date. No strtotime, no DateTime.
 *
 * ── The tables may not exist yet ────────────────────────────────────────────
 * `main` is shared and deploys are by push. te_lineups_tables_present() probes
 * information_schema (SQLite has none — the fallback asks the table) and every
 * handler answers 503 with a sentence until migration 096 is applied.
 */

require_once __DIR__ . '/AthleteScope.php';
require_once __DIR__ . '/event_standing.php';
require_once __DIR__ . '/team_roster_scope.php';
require_once __DIR__ . '/field_size.php';
require_once __DIR__ . '/lineup_formations.php';
require_once __DIR__ . '/guardian_identity.php';

const TE_LINEUP_BENCH = 'BENCH';
const TE_LINEUP_MAX_NOTE = 200;
const TE_LINEUP_MAX_NAME = 120;

/** Roster statuses that may appear on a lineup at all. `inactive` may not. */
const TE_LINEUP_ELIGIBLE_STATUSES = ['active', 'injured', 'suspended'];

/**
 * Bench sort: primary position in this order, then jersey. Mirrors
 * SOCCER_POSITIONS in frontend/src/components/RosterManagement.tsx — the
 * roster vocabulary, not a second list of positions.
 */
const TE_LINEUP_POSITION_ORDER = [
    'Goalkeeper', 'Center Back', 'Left Back', 'Right Back',
    'Defensive Midfielder', 'Central Midfielder', 'Attacking Midfielder',
    'Left Wing', 'Right Wing', 'Striker', 'Forward',
];

// ---------------------------------------------------------------------------
// Probe
// ---------------------------------------------------------------------------

function te_lineups_tables_present(PDO $pdo): bool
{
    static $memo = null;
    $memo ??= new WeakMap();
    if (isset($memo[$pdo])) {
        return $memo[$pdo];
    }
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_name IN ('lineups', 'lineup_slots')"
        );
        $stmt->execute();
        return $memo[$pdo] = ((int) $stmt->fetchColumn() === 2);
    } catch (Throwable $e) {
        try {
            $pdo->query('SELECT 1 FROM lineups LIMIT 1');
            $pdo->query('SELECT 1 FROM lineup_slots LIMIT 1');
            return $memo[$pdo] = true;
        } catch (Throwable $e2) {
            return $memo[$pdo] = false;
        }
    }
}

function te_lineups_unavailable_message(): string
{
    return 'The lineup builder is not switched on yet. The database update for this feature has not been applied — nothing was saved.';
}

function te_lineup_now_sql(PDO $pdo): string
{
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? "datetime('now')" : 'NOW()';
}

function te_lineup_bool_sql(PDO $pdo, bool $v): string
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        return $v ? '1' : '0';
    }
    return $v ? 'TRUE' : 'FALSE';
}

function te_lineup_result(int $status, array $body, ?array $audit = null): array
{
    return ['status' => $status, 'body' => $body, 'audit' => $audit];
}

function te_lineup_fail(int $status, string $error, array $extra = []): array
{
    return te_lineup_result($status, array_merge(['success' => false, 'error' => $error], $extra));
}

// ---------------------------------------------------------------------------
// The team, the event, the roster
// ---------------------------------------------------------------------------

/** @return array{id:int, club_id:?int, name:string, age_group:?string, field_size:?string}|null */
function te_lineup_team(PDO $pdo, int $teamId): ?array
{
    $stmt = $pdo->prepare('SELECT id, club_id, name, age_group FROM teams WHERE id = ? AND deleted_at IS NULL');
    $stmt->execute([$teamId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    return [
        'id'         => (int) $row['id'],
        'club_id'    => $row['club_id'] === null ? null : (int) $row['club_id'],
        'name'       => (string) $row['name'],
        'age_group'  => $row['age_group'],
        'field_size' => te_field_size_for_age_group($row['age_group']),
    ];
}

/**
 * The field size a lineup is created with: an explicit valid request, else the
 * age-group rule, else 11v11. Never an error — a team with no readable age
 * group still gets a builder; the screen offers the size picker in that case.
 */
function te_lineup_resolve_field_size(?array $team, $requested): string
{
    $explicit = te_normalize_field_size($requested);
    if ($explicit !== null) {
        return $explicit;
    }
    return $team['field_size'] ?? '11v11';
}

/** @return array{id:int, club_id:?int, name:string, type:string, event_date:string, start_time:?string, opponent_name:?string, team_ids:int[]}|null */
function te_lineup_event(PDO $pdo, int $eventId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, club_id, name, type, event_date, start_time, opponent_name, status
           FROM calendar_events WHERE id = ?'
    );
    $stmt->execute([$eventId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $teams = $pdo->prepare('SELECT team_id FROM calendar_event_teams WHERE event_id = ? ORDER BY team_id');
    $teams->execute([$eventId]);
    return [
        'id'            => (int) $row['id'],
        'club_id'       => $row['club_id'] === null ? null : (int) $row['club_id'],
        'name'          => (string) $row['name'],
        'type'          => (string) $row['type'],
        'event_date'    => substr((string) $row['event_date'], 0, 10),
        'start_time'    => $row['start_time'],
        'opponent_name' => $row['opponent_name'],
        'status'        => $row['status'],
        'team_ids'      => array_map('intval', $teams->fetchAll(PDO::FETCH_COLUMN)),
    ];
}

function te_lineup_event_public(array $event): array
{
    return [
        'id'            => $event['id'],
        'name'          => $event['name'],
        'type'          => $event['type'],
        'event_date'    => $event['event_date'],
        'start_time'    => $event['start_time'],
        'opponent_name' => $event['opponent_name'],
        'status'        => $event['status'],
    ];
}

/**
 * The players who may appear on this team's lineup: athlete members with an
 * eligible status, sorted by primary position (roster order) then jersey.
 *
 * @return array<int, array{athlete_id:int, first_name:string, last_name:string, name:string, jersey_number:?int, primary_position:?string, status:string}>
 */
function te_lineup_roster(PDO $pdo, int $teamId): array
{
    $marks = implode(',', array_fill(0, count(TE_LINEUP_ELIGIBLE_STATUSES), '?'));
    $stmt = $pdo->prepare("
        SELECT tm.athlete_id, tm.jersey_number, tm.primary_position, tm.status,
               a.first_name, a.last_name
          FROM team_members tm
          JOIN athletes a ON a.id = tm.athlete_id
         WHERE tm.team_id = ?
           AND tm.athlete_id IS NOT NULL
           AND tm.status IN ($marks)
           AND tm.leave_date IS NULL
           AND a.deleted_at IS NULL
    ");
    $stmt->execute(array_merge([$teamId], TE_LINEUP_ELIGIBLE_STATUSES));
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rows[] = [
            'athlete_id'       => (int) $r['athlete_id'],
            'first_name'       => (string) $r['first_name'],
            'last_name'        => (string) $r['last_name'],
            'name'             => trim($r['first_name'] . ' ' . $r['last_name']),
            'jersey_number'    => $r['jersey_number'] === null ? null : (int) $r['jersey_number'],
            'primary_position' => $r['primary_position'],
            'status'           => (string) $r['status'],
        ];
    }
    $rank = static function (?string $pos): int {
        $i = array_search((string) $pos, TE_LINEUP_POSITION_ORDER, true);
        return $i === false ? count(TE_LINEUP_POSITION_ORDER) : $i;
    };
    usort($rows, static function ($a, $b) use ($rank) {
        return [$rank($a['primary_position']), $a['jersey_number'] ?? PHP_INT_MAX, $a['last_name'], $a['first_name']]
            <=> [$rank($b['primary_position']), $b['jersey_number'] ?? PHP_INT_MAX, $b['last_name'], $b['first_name']];
    });
    return $rows;
}

/** athlete_id => present / absent / late / excused, for one event. */
function te_lineup_attendance(PDO $pdo, int $eventId): array
{
    $stmt = $pdo->prepare('SELECT athlete_id, status FROM event_attendance WHERE event_id = ?');
    $stmt->execute([$eventId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int) $r['athlete_id']] = (string) $r['status'];
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Standing
// ---------------------------------------------------------------------------

/** May this caller EDIT this lineup? Game: event standing. Template: team staff. */
function te_lineup_can_edit(PDO $pdo, AuthMiddleware $auth, int $teamId, ?int $eventId): bool
{
    if ($eventId !== null && te_event_staff_standing($pdo, $auth, $eventId) === true) {
        return true;
    }
    return te_team_roster_staff_standing($pdo, $auth, $teamId) === TE_TEAM_ROSTER_OK;
}

/**
 * Resolve team + event (must be a game this team is on) for a request.
 * Returns [team, event, failure] — failure is a te_lineup_fail() result or null.
 */
function te_lineup_resolve(PDO $pdo, int $teamId, ?int $eventId): array
{
    $team = $teamId > 0 ? te_lineup_team($pdo, $teamId) : null;
    if ($team === null) {
        return [null, null, te_lineup_fail(404, 'Team not found')];
    }
    $event = null;
    if ($eventId !== null) {
        $event = $eventId > 0 ? te_lineup_event($pdo, $eventId) : null;
        if ($event === null || !in_array($team['id'], $event['team_ids'], true)) {
            return [$team, null, te_lineup_fail(404, 'Game not found for this team')];
        }
    }
    return [$team, $event, null];
}

// ---------------------------------------------------------------------------
// Rows
// ---------------------------------------------------------------------------

function te_lineup_row_shape(PDO $pdo, array $row): array
{
    $stmt = $pdo->prepare('SELECT athlete_id, slot, sort_order, captain, note FROM lineup_slots WHERE lineup_id = ? ORDER BY id');
    $stmt->execute([(int) $row['id']]);
    $order = [];
    foreach ((te_lineup_formation_slots((string) $row['field_size'], (string) $row['formation']) ?? []) as $i => $s) {
        $order[$s['slot']] = $i;
    }
    $slots = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $slots[] = [
            'athlete_id' => (int) $s['athlete_id'],
            'slot'       => (string) $s['slot'],
            'sort_order' => (int) $s['sort_order'],
            'captain'    => (bool) $s['captain'],
            'note'       => $s['note'] === null || $s['note'] === '' ? null : (string) $s['note'],
        ];
    }
    usort($slots, static function ($a, $b) use ($order) {
        $ra = $a['slot'] === TE_LINEUP_BENCH ? [1, $a['sort_order'], $a['athlete_id']] : [0, $order[$a['slot']] ?? 999, 0];
        $rb = $b['slot'] === TE_LINEUP_BENCH ? [1, $b['sort_order'], $b['athlete_id']] : [0, $order[$b['slot']] ?? 999, 0];
        return $ra <=> $rb;
    });
    return [
        'id'                => (int) $row['id'],
        'club_id'           => $row['club_id'] === null ? null : (int) $row['club_id'],
        'team_id'           => (int) $row['team_id'],
        'calendar_event_id' => $row['calendar_event_id'] === null ? null : (int) $row['calendar_event_id'],
        'is_template'       => $row['calendar_event_id'] === null,
        'name'              => (string) $row['name'],
        'formation'         => (string) $row['formation'],
        'field_size'        => (string) $row['field_size'],
        'published_at'      => $row['published_at'],
        'updated_at'        => $row['updated_at'] ?? null,
        'slots'             => $slots,
    ];
}

/** The lineup for (team, game), or the team's template when $eventId is null. */
function te_lineup_find(PDO $pdo, int $teamId, ?int $eventId): ?array
{
    if ($eventId === null) {
        $stmt = $pdo->prepare('SELECT * FROM lineups WHERE team_id = ? AND calendar_event_id IS NULL ORDER BY id LIMIT 1');
        $stmt->execute([$teamId]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM lineups WHERE team_id = ? AND calendar_event_id = ? LIMIT 1');
        $stmt->execute([$teamId, $eventId]);
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? te_lineup_row_shape($pdo, $row) : null;
}

/**
 * The most recent game before $beforeDate (string compare) on which this team
 * has a saved lineup, excluding $excludeEventId. Null when there is none.
 */
function te_lineup_last_game_event_id(PDO $pdo, int $teamId, ?string $beforeDate, ?int $excludeEventId): ?int
{
    $sql = "
        SELECT ce.id
          FROM lineups l
          JOIN calendar_events ce ON ce.id = l.calendar_event_id
         WHERE l.team_id = ?
           AND l.calendar_event_id IS NOT NULL
           AND ce.type = 'game'";
    $params = [$teamId];
    if ($beforeDate !== null && $beforeDate !== '') {
        $sql .= ' AND ce.event_date <= ?';
        $params[] = $beforeDate;
    }
    if ($excludeEventId !== null) {
        $sql .= ' AND ce.id <> ?';
        $params[] = $excludeEventId;
    }
    $sql .= ' ORDER BY ce.event_date DESC, ce.start_time DESC, ce.id DESC LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $id = $stmt->fetchColumn();
    return $id === false || $id === null ? null : (int) $id;
}

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

/**
 * @param array $body   {formation?, slots: [{athlete_id, slot, sort_order?, captain?, note?}]}
 * @param array $roster te_lineup_roster() rows
 * @return array{error:?string, formation:?string, slots:array, warnings:string[]}
 */
function te_lineup_validate(array $body, string $fieldSize, array $roster): array
{
    $fail = fn(string $m) => ['error' => $m, 'formation' => null, 'slots' => [], 'warnings' => []];

    $formation = trim((string) ($body['formation'] ?? ''));
    if ($formation === '') {
        $formation = te_lineup_default_formation($fieldSize) ?? '';
    }
    $preset = te_lineup_formation_slots($fieldSize, $formation);
    if ($preset === null) {
        return $fail("$formation is not a $fieldSize formation");
    }
    $codes = array_column($preset, 'slot');
    $fieldPlayers = te_lineup_field_players($fieldSize) ?? count($codes);

    $raw = $body['slots'] ?? [];
    if (!is_array($raw) || (count($raw) > 0 && array_keys($raw) !== range(0, count($raw) - 1))) {
        return $fail('slots must be a list');
    }

    $byAthlete = [];
    foreach ($roster as $r) {
        $byAthlete[$r['athlete_id']] = $r;
    }

    // Count first: "too many on the field" is the sentence a coach can act on;
    // "GK is not a slot in 2-2" would hide it behind the first wrong code.
    $onField = 0;
    foreach ($raw as $s) {
        if (is_array($s) && strtoupper(trim((string) ($s['slot'] ?? ''))) !== TE_LINEUP_BENCH) {
            $onField++;
        }
    }
    if ($onField > $fieldPlayers) {
        return $fail("$fieldSize allows $fieldPlayers players on the field");
    }

    $seenAthlete = [];
    $seenSlot = [];
    $slots = [];
    $warnings = [];
    foreach ($raw as $i => $s) {
        if (!is_array($s)) {
            return $fail("slot $i is malformed");
        }
        $athleteId = (int) ($s['athlete_id'] ?? 0);
        if ($athleteId <= 0) {
            return $fail("slot $i has no athlete_id");
        }
        $slot = strtoupper(trim((string) ($s['slot'] ?? '')));
        if ($slot === '') {
            return $fail("slot $i has no slot code");
        }
        $isBench = $slot === TE_LINEUP_BENCH;
        if (!$isBench && !in_array($slot, $codes, true)) {
            return $fail("$slot is not a slot in $formation");
        }
        $player = $byAthlete[$athleteId] ?? null;
        if ($player === null) {
            return $fail("Athlete $athleteId is not on this roster");
        }
        if (isset($seenAthlete[$athleteId])) {
            return $fail("{$player['name']} is placed more than once");
        }
        $seenAthlete[$athleteId] = true;
        if (!$isBench) {
            if (isset($seenSlot[$slot])) {
                return $fail("$slot is used twice");
            }
            $seenSlot[$slot] = true;
        }
        if ($player['status'] !== 'active') {
            if ($isBench) {
                $warnings[] = "{$player['name']} is marked {$player['status']} and is on the bench";
            } else {
                return $fail("{$player['name']} is marked {$player['status']} — move them to the bench or update the roster");
            }
        }
        $note = $s['note'] ?? null;
        $note = $note === null ? null : trim((string) $note);
        if ($note !== null && mb_strlen($note) > TE_LINEUP_MAX_NOTE) {
            return $fail('A note must be ' . TE_LINEUP_MAX_NOTE . ' characters or fewer');
        }
        $slots[] = [
            'athlete_id' => $athleteId,
            'slot'       => $slot,
            'sort_order' => (int) ($s['sort_order'] ?? 0),
            'captain'    => filter_var($s['captain'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'note'       => $note === '' ? null : $note,
        ];
    }

    return ['error' => null, 'formation' => $formation, 'slots' => $slots, 'warnings' => $warnings];
}

// ---------------------------------------------------------------------------
// Handlers — {status, body, audit}
// ---------------------------------------------------------------------------

/** GET: the full shape for staff, the reduced shape for a guardian of a player. */
function te_lineup_get(PDO $pdo, AuthMiddleware $auth, int $teamId, ?int $eventId): array
{
    [$team, $event, $failure] = te_lineup_resolve($pdo, $teamId, $eventId);
    if ($failure !== null) {
        return $failure;
    }

    $canEdit = te_lineup_can_edit($pdo, $auth, $team['id'], $eventId);
    if (!$canEdit && te_team_view_standing($pdo, $auth, $team['id']) !== TE_TEAM_ROSTER_OK) {
        return te_lineup_fail(403, 'Only staff of this team, or a family on it, can see its lineup');
    }
    if (!te_lineups_tables_present($pdo)) {
        return te_lineup_fail(503, te_lineups_unavailable_message(), ['available' => false]);
    }

    $lineup = te_lineup_find($pdo, $team['id'], $eventId);
    $template = $eventId === null ? $lineup : te_lineup_find($pdo, $team['id'], null);
    if ($lineup === null && $eventId !== null && $template !== null) {
        $lineup = $template;
    }
    $isTemplate = $lineup !== null && $lineup['is_template'];

    if (!$canEdit) {
        // A family: the game's own lineup, published. Never the template.
        if ($event === null || $lineup === null || $isTemplate || $lineup['published_at'] === null) {
            return te_lineup_fail(403, 'This lineup has not been published to families');
        }
        return te_lineup_result(200, [
            'success'        => true,
            'available'      => true,
            'can_edit'       => false,
            'team'           => ['id' => $team['id'], 'name' => $team['name']],
            'event'          => te_lineup_event_public($event),
            'lineup'         => te_lineup_crew_view($lineup, te_lineup_roster($pdo, $team['id'])),
            'my_athlete_ids' => te_athlete_ids_for_user($pdo, (int) $auth->getUserId()),
        ]);
    }

    $fieldSize = $lineup['field_size'] ?? te_lineup_resolve_field_size($team, null);
    $lastGame = null;
    if ($event !== null) {
        $lastId = te_lineup_last_game_event_id($pdo, $team['id'], $event['event_date'], $event['id']);
        if ($lastId !== null) {
            $last = te_lineup_event($pdo, $lastId);
            $lastGame = $last ? te_lineup_event_public($last) : null;
        }
    }

    return te_lineup_result(200, [
        'success'      => true,
        'available'    => true,
        'can_edit'     => true,
        'team'         => [
            'id'         => $team['id'],
            'name'       => $team['name'],
            'age_group'  => $team['age_group'],
            'field_size' => $fieldSize,
            'field_size_from_age_group' => $team['field_size'] !== null,
        ],
        'event'        => $event === null ? null : te_lineup_event_public($event),
        'lineup'       => $lineup,
        'is_template'  => $isTemplate,
        'has_template' => $template !== null,
        'last_game'    => $lastGame,
        'formations'   => te_lineup_formations_for($fieldSize),
        'roster'       => te_lineup_roster($pdo, $team['id']),
        'attendance'   => $event === null ? [] : te_lineup_attendance($pdo, $event['id']),
    ]);
}

/**
 * The shape a family sees: on-field slots with names, and bench NAMES in name
 * order. No notes, no sort_order, nothing about who is not on the sheet.
 */
function te_lineup_crew_view(array $lineup, array $roster): array
{
    $byId = [];
    foreach ($roster as $r) {
        $byId[$r['athlete_id']] = $r;
    }
    $slots = [];
    $bench = [];
    foreach ($lineup['slots'] as $s) {
        $p = $byId[$s['athlete_id']] ?? null;
        $entry = [
            'athlete_id'    => $s['athlete_id'],
            'name'          => $p['name'] ?? 'Player',
            'jersey_number' => $p['jersey_number'] ?? null,
            'captain'       => $s['captain'],
        ];
        if ($s['slot'] === TE_LINEUP_BENCH) {
            $entry['last_name'] = $p['last_name'] ?? '';
            $entry['first_name'] = $p['first_name'] ?? '';
            $bench[] = $entry;
        } else {
            $entry['slot'] = $s['slot'];
            $slots[] = $entry;
        }
    }
    usort($bench, fn($a, $b) => [$a['last_name'], $a['first_name']] <=> [$b['last_name'], $b['first_name']]);
    $bench = array_map(function ($b) {
        unset($b['last_name'], $b['first_name']);
        return $b;
    }, $bench);
    return [
        'formation'    => $lineup['formation'],
        'field_size'   => $lineup['field_size'],
        'published_at' => $lineup['published_at'],
        'slots'        => $slots,
        'bench'        => $bench,
    ];
}

/** Shared write gate for save / copy-from / publish. Null means proceed. */
function te_lineup_write_gate(PDO $pdo, AuthMiddleware $auth, int $teamId, ?int $eventId): array
{
    [$team, $event, $failure] = te_lineup_resolve($pdo, $teamId, $eventId);
    if ($failure !== null) {
        return [null, null, $failure];
    }
    if ($event !== null && $event['type'] !== 'game') {
        return [$team, $event, te_lineup_fail(422, 'A lineup is for a game — this event is a ' . $event['type'])];
    }
    if (!te_lineup_can_edit($pdo, $auth, $team['id'], $eventId)) {
        return [$team, $event, te_lineup_fail(403, 'Only a coach of this team, or a club admin, can set its lineup')];
    }
    if (!te_lineups_tables_present($pdo)) {
        return [$team, $event, te_lineup_fail(503, te_lineups_unavailable_message(), ['available' => false])];
    }
    return [$team, $event, null];
}

/** POST save: full slot replace in one transaction. */
function te_lineup_save(PDO $pdo, AuthMiddleware $auth, int $teamId, ?int $eventId, array $body, int $userId, array $auditExtra = []): array
{
    [$team, $event, $failure] = te_lineup_write_gate($pdo, $auth, $teamId, $eventId);
    if ($failure !== null) {
        return $failure;
    }

    $existing = te_lineup_find($pdo, $team['id'], $eventId);
    $fieldSize = te_normalize_field_size($body['field_size'] ?? null)
        ?? ($existing['field_size'] ?? te_lineup_resolve_field_size($team, null));

    $roster = te_lineup_roster($pdo, $team['id']);
    $v = te_lineup_validate($body, $fieldSize, $roster);
    if ($v['error'] !== null) {
        return te_lineup_fail(422, $v['error']);
    }

    $name = trim((string) ($body['name'] ?? ''));
    if ($name === '') {
        $name = $existing['name'] ?? ($event === null ? 'Default' : ($event['opponent_name'] ? 'vs ' . $event['opponent_name'] : $event['name']));
    }
    $name = mb_substr($name, 0, TE_LINEUP_MAX_NAME);

    $now = te_lineup_now_sql($pdo);
    try {
        $pdo->beginTransaction();
        if ($existing === null) {
            $stmt = $pdo->prepare("
                INSERT INTO lineups (club_id, team_id, calendar_event_id, name, formation, field_size, created_by, updated_by, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, $now, $now)
            ");
            $stmt->execute([$team['club_id'], $team['id'], $eventId, $name, $v['formation'], $fieldSize, $userId, $userId]);
            $lineupId = (int) $pdo->lastInsertId();
        } else {
            $lineupId = $existing['id'];
            $stmt = $pdo->prepare("
                UPDATE lineups SET name = ?, formation = ?, field_size = ?, updated_by = ?, updated_at = $now WHERE id = ?
            ");
            $stmt->execute([$name, $v['formation'], $fieldSize, $userId, $lineupId]);
            $pdo->prepare('DELETE FROM lineup_slots WHERE lineup_id = ?')->execute([$lineupId]);
        }
        $ins = $pdo->prepare('INSERT INTO lineup_slots (lineup_id, athlete_id, slot, sort_order, captain, note) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($v['slots'] as $s) {
            $ins->execute([$lineupId, $s['athlete_id'], $s['slot'], $s['sort_order'], $s['captain'] ? 1 : 0, $s['note']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('lineups: save failed: ' . $e->getMessage());
        return te_lineup_fail(500, 'Could not save the lineup');
    }

    $lineup = te_lineup_find($pdo, $team['id'], $eventId);
    return te_lineup_result(200, [
        'success'   => true,
        'available' => true,
        'lineup'    => $lineup,
        'warnings'  => $v['warnings'],
    ], [
        'action'        => 'lineup_saved',
        'resource_type' => 'lineup',
        'resource_id'   => $lineupId,
        'details'       => array_merge([
            'team_id'   => $team['id'],
            'event_id'  => $eventId,
            'formation' => $v['formation'],
            'field_size' => $fieldSize,
            'on_field'  => count(array_filter($v['slots'], fn($s) => $s['slot'] !== TE_LINEUP_BENCH)),
            'bench'     => count(array_filter($v['slots'], fn($s) => $s['slot'] === TE_LINEUP_BENCH)),
            'created'   => $existing === null,
        ], $auditExtra),
    ]);
}

/**
 * POST copy-from: `template`, `last`, or a source event id of THIS team's.
 * Players no longer on the roster are left out and named in `warnings`;
 * injured/suspended players who were on the field are moved to the bench.
 */
function te_lineup_copy_from(PDO $pdo, AuthMiddleware $auth, int $teamId, ?int $eventId, $source, int $userId): array
{
    if ($eventId === null) {
        return te_lineup_fail(422, 'Say which game to copy into');
    }
    [$team, $event, $failure] = te_lineup_write_gate($pdo, $auth, $teamId, $eventId);
    if ($failure !== null) {
        return $failure;
    }

    $source = trim((string) $source);
    $from = null;
    $sourceEvent = null;
    if ($source === 'template' || $source === '') {
        $from = te_lineup_find($pdo, $team['id'], null);
        if ($from === null) {
            return te_lineup_fail(404, 'This team has no default lineup yet — save one with "Save as default"');
        }
        $label = 'template';
    } else {
        if ($source === 'last') {
            $sourceId = te_lineup_last_game_event_id($pdo, $team['id'], $event['event_date'], $event['id']);
            if ($sourceId === null) {
                return te_lineup_fail(404, 'No earlier game has a saved lineup');
            }
            $label = 'last';
        } else {
            $sourceId = (int) $source;
            if ($sourceId <= 0 || $sourceId === $event['id']) {
                return te_lineup_fail(422, 'source must be template, last, or a game id');
            }
            $label = 'event';
        }
        $from = te_lineup_find($pdo, $team['id'], $sourceId);
        if ($from === null) {
            return te_lineup_fail(404, 'That game has no saved lineup for this team');
        }
        $sourceEvent = te_lineup_event($pdo, $sourceId);
    }

    $roster = te_lineup_roster($pdo, $team['id']);
    $byId = [];
    foreach ($roster as $r) {
        $byId[$r['athlete_id']] = $r;
    }
    $slots = [];
    $warnings = [];
    foreach ($from['slots'] as $s) {
        $p = $byId[$s['athlete_id']] ?? null;
        if ($p === null) {
            $warnings[] = "Athlete {$s['athlete_id']} is no longer on the roster and was left out";
            continue;
        }
        if ($p['status'] !== 'active' && $s['slot'] !== TE_LINEUP_BENCH) {
            $warnings[] = "{$p['name']} is marked {$p['status']} and was moved to the bench";
            $s['slot'] = TE_LINEUP_BENCH;
        }
        $slots[] = $s;
    }

    $body = ['formation' => $from['formation'], 'field_size' => $from['field_size'], 'slots' => $slots];
    $r = te_lineup_save($pdo, $auth, $team['id'], $event['id'], $body, $userId, [
        'copied_from'     => $label,
        'source_event_id' => $sourceEvent['id'] ?? null,
    ]);
    if ($r['status'] === 200) {
        $r['body']['warnings'] = array_merge($warnings, $r['body']['warnings']);
        $r['body']['copied_from'] = $label;
        $r['body']['copied_from_event'] = $sourceEvent ? te_lineup_event_public($sourceEvent) : null;
    }
    return $r;
}

/** POST publish / unpublish: sets or clears published_at on a GAME lineup. */
function te_lineup_publish(PDO $pdo, AuthMiddleware $auth, int $teamId, ?int $eventId, bool $publish, int $userId): array
{
    if ($eventId === null) {
        return te_lineup_fail(422, 'Only a game lineup can be published — the default is staff-only');
    }
    [$team, $event, $failure] = te_lineup_write_gate($pdo, $auth, $teamId, $eventId);
    if ($failure !== null) {
        return $failure;
    }
    $lineup = te_lineup_find($pdo, $team['id'], $eventId);
    if ($lineup === null) {
        return te_lineup_fail(404, 'Save the lineup before publishing it');
    }
    $now = te_lineup_now_sql($pdo);
    $stmt = $pdo->prepare($publish
        ? "UPDATE lineups SET published_at = $now, updated_by = ?, updated_at = $now WHERE id = ?"
        : "UPDATE lineups SET published_at = NULL, updated_by = ?, updated_at = $now WHERE id = ?");
    $stmt->execute([$userId, $lineup['id']]);

    $lineup = te_lineup_find($pdo, $team['id'], $eventId);
    return te_lineup_result(200, ['success' => true, 'available' => true, 'lineup' => $lineup], [
        'action'        => $publish ? 'lineup_published' : 'lineup_unpublished',
        'resource_type' => 'lineup',
        'resource_id'   => $lineup['id'],
        'details'       => ['team_id' => $team['id'], 'event_id' => $eventId],
    ]);
}

/** GET games: this team's games with lineup status. Staff of the team only. */
function te_lineup_games(PDO $pdo, AuthMiddleware $auth, int $teamId): array
{
    $team = $teamId > 0 ? te_lineup_team($pdo, $teamId) : null;
    if ($team === null) {
        return te_lineup_fail(404, 'Team not found');
    }
    if (te_team_roster_staff_standing($pdo, $auth, $team['id']) !== TE_TEAM_ROSTER_OK) {
        return te_lineup_fail(403, 'Only staff of this team can see its lineups');
    }
    $available = te_lineups_tables_present($pdo);
    if ($available) {
        $stmt = $pdo->prepare("
            SELECT ce.id, ce.name, ce.event_date, ce.start_time, ce.opponent_name, ce.status,
                   l.id AS lineup_id, l.published_at
              FROM calendar_events ce
              JOIN calendar_event_teams cet ON cet.event_id = ce.id
              LEFT JOIN lineups l ON l.team_id = cet.team_id AND l.calendar_event_id = ce.id
             WHERE cet.team_id = ? AND ce.type = 'game'
             ORDER BY ce.event_date, ce.start_time, ce.id
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT ce.id, ce.name, ce.event_date, ce.start_time, ce.opponent_name, ce.status,
                   NULL AS lineup_id, NULL AS published_at
              FROM calendar_events ce
              JOIN calendar_event_teams cet ON cet.event_id = ce.id
             WHERE cet.team_id = ? AND ce.type = 'game'
             ORDER BY ce.event_date, ce.start_time, ce.id
        ");
    }
    $stmt->execute([$team['id']]);
    $games = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $games[] = [
            'id'            => (int) $r['id'],
            'name'          => (string) $r['name'],
            'event_date'    => substr((string) $r['event_date'], 0, 10),
            'start_time'    => $r['start_time'],
            'opponent_name' => $r['opponent_name'],
            'status'        => $r['status'],
            'has_lineup'    => $r['lineup_id'] !== null,
            'published'     => $r['published_at'] !== null,
        ];
    }
    return te_lineup_result(200, [
        'success'   => true,
        'available' => $available,
        'team'      => ['id' => $team['id'], 'name' => $team['name']],
        'games'     => $games,
    ]);
}
