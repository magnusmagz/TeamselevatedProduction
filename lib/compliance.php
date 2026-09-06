<?php
/**
 * Compliance: requirements, inheritance, and one person's standing against them
 * (migration 091, GOTR G3).
 *
 * The product's existing answer to "may this person be on the field" is
 * `team_volunteers.background_check_status`, stored per (team, user), with
 * "any cleared row wins" and no expiry anywhere. A coach cleared on a team they
 * left is cleared for life. This file is the person-level replacement, and it is
 * generic because the paperwork is not predictable: an admin defines the
 * requirement, we record completion and compute expiry.
 *
 * ⚠️ FOUR RULES THAT ARE NOT OPTIONAL
 *
 * 1. EVERY FUNCTION TOLERATES THE TABLES BEING ABSENT. `main` is shared and
 *    deploys are by push, so this code reaches production the moment any
 *    session pushes — days before migration 091 is applied to Neon by hand. On
 *    Postgres a SELECT against a missing table is 42P01, a hard error that would
 *    take down whatever called it (including the volunteer gate, which is a live
 *    child-safety control) rather than hiding a feature nobody is using yet. The
 *    probe is one query per connection and a failed probe answers "absent",
 *    because the degraded path is always the safe one.
 *
 * 2. REQUIREMENTS INHERIT DOWN, AND THE INHERITANCE IS A PATH PREFIX. A club's
 *    requirements are its own rows plus every row on every ANCESTOR org unit of
 *    the club's unit — national's baseline, the division's additions, the
 *    council's own. Never a sibling council's, never a descendant's. A club with
 *    no org unit (every non-GOTR club today) gets only its own, which is why
 *    turning this on cannot change anything for CKU.
 *
 * 3. ONLY A `required` ROW DECIDES COMPLIANCE. An optional requirement still
 *    tracks, still expires, still counts in the missing/expiring/expired
 *    numbers — it just cannot make somebody non-compliant. Collapsing the two
 *    would mean a council could not track a nice-to-have certificate without
 *    locking people out over it.
 *
 * 4. EXPIRY IS COMPUTED AT WRITE TIME AND STORED. `expires_at = completed_at +
 *    validity_days`. Stored rather than derived on read so a single person can
 *    carry a certificate with its own printed expiry without editing the
 *    requirement for everybody. But a READ still re-checks the date against
 *    today — see te_compliance_status — because the nightly sweep may not have
 *    run yet and a screen must never report a lapsed certificate as verified.
 *
 * ⚠️ Date-only values here follow the same rule as frontend/src/utils/dateFormat.ts:
 * `completed_at` and `expires_at` are DATE columns holding a calendar day. They
 * are compared as 'YYYY-MM-DD' strings and arithmetic on them is done in UTC, so
 * no timezone can move a certificate to the previous day.
 */

require_once __DIR__ . '/org_scope.php';

/** The tables migration 091 creates. All six arrive together or not at all. */
const TE_COMPLIANCE_TABLES = [
    'compliance_requirements',
    'compliance_requirement_roles',
    'club_staff_roles',
    'person_credentials',
    'compliance_reminder_streams',
    'compliance_reminder_log',
];

/** Reporting categories. `custom` is always available; nothing branches on the rest. */
const TE_COMPLIANCE_KINDS = ['background_check', 'cpr_first_aid', 'training', 'document', 'custom'];

/** What counts as proof. */
const TE_COMPLIANCE_PROOFS = ['document', 'attested_date', 'external_link'];

/** A credential's state. */
const TE_COMPLIANCE_STATUSES = ['missing', 'submitted', 'verified', 'rejected', 'expired'];

/** Where the record came from. */
const TE_COMPLIANCE_SOURCES = ['portal', 'admin', 'import', 'lms', 'email'];

/**
 * The role vocabulary a requirement can name.
 *
 * The four GOTR words plus the two user_club_access roles a person is derived
 * into when nobody has given them a club_staff_roles row yet. `parent`,
 * `player` and `treasurer` are deliberately absent: none of them is staff on
 * the field, and a requirement that could name them would be a way to demand
 * paperwork from families.
 */
const TE_COMPLIANCE_STAFF_ROLES = [
    'head_coach', 'junior_coach', 'team_helper', 'volunteer', 'coach', 'club_admin',
];

/**
 * user_club_access.role -> the staff role it means, when club_staff_roles has
 * nothing for this person. Any role not in this map is not staff and gets no
 * requirements at all.
 */
const TE_COMPLIANCE_ROLE_FALLBACK = [
    'coach'      => 'coach',
    'club_admin' => 'club_admin',
    'volunteer'  => 'volunteer',
];

// ------------------------------------------------------------------ probe ---

/**
 * Are the migration-091 tables live?
 *
 * Memoised per PDO instance via WeakMap rather than per process, for the same
 * reason as te_org_tables_present(): the test suite builds one connection with
 * the tables and one without, and PHP reuses object ids, so an id-keyed cache
 * would let the first connection's answer decide the second's.
 */
function te_compliance_tables_present(PDO $pdo): bool
{
    static $memo = null;
    $memo ??= new WeakMap();
    if (isset($memo[$pdo])) {
        return $memo[$pdo];
    }

    $names = "'" . implode("', '", TE_COMPLIANCE_TABLES) . "'";
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_name IN ($names)"
        );
        $stmt->execute();
        return $memo[$pdo] = ((int) $stmt->fetchColumn() === count(TE_COMPLIANCE_TABLES));
    } catch (Throwable $e) {
        // SQLite has no information_schema, so that throws and each table is
        // asked directly — safe there precisely because SQLite has no
        // transaction to poison with a failed statement.
        try {
            foreach (TE_COMPLIANCE_TABLES as $table) {
                $pdo->query("SELECT 1 FROM {$table} LIMIT 1");
            }
            return $memo[$pdo] = true;
        } catch (Throwable $e2) {
            return $memo[$pdo] = false;
        }
    }
}

/** Today, as the same 'YYYY-MM-DD' string shape the DATE columns hold. */
function te_compliance_today(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
}

/**
 * completed_at + validity_days, as a date-only string.
 *
 * Built in UTC and formatted straight back out, so the only arithmetic is
 * calendar-day addition. DateInterval adds days by the calendar, not by 86,400
 * seconds, so a DST boundary inside the window cannot move the answer.
 */
function te_compliance_expiry_from(?string $completedAt, ?int $validityDays): ?string
{
    if ($completedAt === null || $completedAt === '' || $validityDays === null || $validityDays <= 0) {
        return null;
    }
    $day = substr(trim($completedAt), 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
        return null;
    }
    try {
        $date = new DateTimeImmutable($day, new DateTimeZone('UTC'));
    } catch (Throwable $e) {
        return null;
    }
    return $date->add(new DateInterval('P' . $validityDays . 'D'))->format('Y-m-d');
}

/** Whole days from today to $date; negative when it has passed. Null for no date. */
function te_compliance_days_to(?string $date, ?string $today = null): ?int
{
    if ($date === null || $date === '') {
        return null;
    }
    $day = substr(trim($date), 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
        return null;
    }
    $today = $today ?? te_compliance_today();
    try {
        $utc = new DateTimeZone('UTC');
        $a = new DateTimeImmutable($today, $utc);
        $b = new DateTimeImmutable($day, $utc);
    } catch (Throwable $e) {
        return null;
    }
    return (int) $a->diff($b)->format('%r%a');
}

// ----------------------------------------------------------- requirements ---

/**
 * Every requirement that applies to a club: its OWN rows, UNION every row on
 * every ancestor org unit of the club's unit (the unit itself included).
 *
 * The ancestor set is derived IN SQL from the club's org_unit_id — a row `a` is
 * an ancestor of the club's unit `o` when `o.path LIKE a.path || '%'`, which is
 * true for `o` itself and for every unit above it and false for every sibling
 * and every descendant. Deriving it in the statement rather than reading paths
 * into PHP first means a re-parent between building the query and running it
 * cannot leave the caller reading a tree that no longer exists.
 *
 * A club with no org unit — which is every non-GOTR club, forever — gets only
 * its own rows. That is the whole reason switching this on cannot change
 * anything for an existing club.
 *
 * Active rows only. Deactivating a requirement must stop it demanding anything
 * of anybody immediately, without deleting the credentials already recorded
 * against it.
 *
 * @return array<int, array> requirement rows, each with a `roles` string[]
 */
function te_compliance_requirements_for_club(PDO $pdo, int $clubId): array
{
    if (!te_compliance_tables_present($pdo) || $clubId <= 0) {
        return [];
    }

    $where = ['r.club_profile_id = ?'];
    $params = [$clubId];

    // The org tree is a separate migration (090) and may be absent even when
    // 091 is present. Without it there is no inheritance, only the club's own
    // rows — which is the correct answer for a club with no tier above it.
    if (te_org_tables_present($pdo)) {
        $where[] = 'r.org_unit_id IN ('
            . 'SELECT a.id FROM org_units a'
            . ' JOIN org_units o ON o.path LIKE a.path || \'%\''
            . ' JOIN club_profile c ON c.org_unit_id = o.id'
            . ' WHERE c.id = ?'
            . ')';
        $params[] = $clubId;
    }

    $sql = 'SELECT r.* FROM compliance_requirements r'
        . ' WHERE r.active = ' . te_compliance_true_literal($pdo)
        . ' AND (' . implode(' OR ', $where) . ')'
        . ' ORDER BY r.sort_order, r.name, r.id';

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('te_compliance_requirements_for_club: ' . $e->getMessage());
        return [];
    }

    return te_compliance_decorate_requirements($pdo, $rows);
}

/**
 * `TRUE` on Postgres, `1` on SQLite.
 *
 * SQLite accepts the keyword TRUE from 3.23 but stores booleans as integers, and
 * a fixture that inserts 1 then compares against TRUE matches nothing on older
 * builds. Asking the driver is cheaper than being wrong on one of the two.
 */
function te_compliance_true_literal(PDO $pdo): string
{
    try {
        return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '1' : 'TRUE';
    } catch (Throwable $e) {
        return 'TRUE';
    }
}

/** Attach each requirement's role set and normalise its scalars. */
function te_compliance_decorate_requirements(PDO $pdo, array $rows): array
{
    if (!$rows) {
        return [];
    }

    $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
    $roles = [];
    try {
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            "SELECT requirement_id, staff_role FROM compliance_requirement_roles
              WHERE requirement_id IN ($placeholders) ORDER BY staff_role"
        );
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $roles[(int) $row['requirement_id']][] = (string) $row['staff_role'];
        }
    } catch (Throwable $e) {
        error_log('te_compliance_decorate_requirements: ' . $e->getMessage());
    }

    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['org_unit_id'] = $row['org_unit_id'] === null ? null : (int) $row['org_unit_id'];
        $row['club_profile_id'] = $row['club_profile_id'] === null ? null : (int) $row['club_profile_id'];
        $row['validity_days'] = ($row['validity_days'] === null || $row['validity_days'] === '')
            ? null : (int) $row['validity_days'];
        $row['required'] = te_compliance_bool($row['required'] ?? true);
        $row['active'] = te_compliance_bool($row['active'] ?? true);
        $row['sort_order'] = (int) ($row['sort_order'] ?? 0);
        // No rows in compliance_requirement_roles means "every staff role".
        $row['roles'] = $roles[$row['id']] ?? [];
    }
    return $rows;
}

/** Postgres hands back 't'/'f', SQLite 1/0, a form posts 'true'/'false'. */
function te_compliance_bool($value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if ($value === null) {
        return false;
    }
    return !in_array(strtolower(trim((string) $value)), ['', '0', 'f', 'false', 'no', 'off'], true);
}

// ------------------------------------------------------------ staff roles ---

/**
 * What this person is CALLED in this club, for the purpose of deciding their
 * paperwork.
 *
 * `club_staff_roles` first — that table exists precisely because "junior coach"
 * and "head coach" are the same `coach` to every permission check in the
 * product and must stay that way, so the GOTR vocabulary cannot live in
 * user_club_access.
 *
 * When it has nothing, the person is derived from their user_club_access roles.
 * ⚠️ Every role is returned, never just the most privileged one — the same rule
 * as te_support_reporter_roles(). A coach who is also a club admin may owe
 * paperwork for either, and collapsing to one is how a requirement silently
 * stops applying to the population it was written for.
 *
 * A person with no staff role in the club gets an empty list and therefore no
 * requirements. That is not the same as "compliant"; te_compliance_status says
 * so by returning an empty requirement list, and a caller that renders an empty
 * list as a green tick is the bug, not this function.
 *
 * @return string[] a subset of TE_COMPLIANCE_STAFF_ROLES
 */
function te_compliance_staff_roles(PDO $pdo, int $userId, int $clubId): array
{
    if ($userId <= 0 || $clubId <= 0) {
        return [];
    }

    $roles = [];
    if (te_compliance_tables_present($pdo)) {
        try {
            $stmt = $pdo->prepare(
                'SELECT staff_role FROM club_staff_roles WHERE user_id = ? AND club_profile_id = ?'
            );
            $stmt->execute([$userId, $clubId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: [] as $role) {
                $role = strtolower(trim((string) $role));
                if ($role !== '' && !in_array($role, $roles, true)) {
                    $roles[] = $role;
                }
            }
        } catch (Throwable $e) {
            error_log('te_compliance_staff_roles: ' . $e->getMessage());
        }
    }
    if ($roles) {
        return $roles;
    }

    // Fallback. `revoked_at IS NULL` as well as `active` — those two columns can
    // disagree and when they do the revocation is the newer fact (lib/JWT.php
    // minted a revoked role for a year by reading only one of them).
    try {
        $stmt = $pdo->prepare(
            'SELECT role FROM user_club_access
              WHERE user_id = ? AND club_profile_id = ?
                AND active = ' . te_compliance_true_literal($pdo) . ' AND revoked_at IS NULL'
        );
        $stmt->execute([$userId, $clubId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: [] as $role) {
            $mapped = TE_COMPLIANCE_ROLE_FALLBACK[strtolower(trim((string) $role))] ?? null;
            if ($mapped !== null && !in_array($mapped, $roles, true)) {
                $roles[] = $mapped;
            }
        }
    } catch (Throwable $e) {
        error_log('te_compliance_staff_roles fallback: ' . $e->getMessage());
    }

    return $roles;
}

/**
 * The requirements one person owes in one club: the club's inherited set,
 * filtered by that person's staff roles.
 *
 * A requirement naming no roles applies to everyone with any staff role. A
 * requirement naming roles applies when the person holds at least one of them.
 *
 * @return array<int, array>
 */
function te_person_requirements(PDO $pdo, int $userId, int $clubId): array
{
    $roles = te_compliance_staff_roles($pdo, $userId, $clubId);
    if (!$roles) {
        return [];
    }

    $out = [];
    foreach (te_compliance_requirements_for_club($pdo, $clubId) as $req) {
        if (!$req['roles'] || array_intersect($req['roles'], $roles)) {
            $out[] = $req;
        }
    }
    return $out;
}

// ------------------------------------------------------------ credentials ---

/**
 * Create or update one person's record against one requirement.
 *
 * A SELECT-then-INSERT/UPDATE inside a transaction rather than `ON CONFLICT`:
 * the same code has to run on Postgres in production and SQLite in the test
 * suite, and the previous value is needed anyway to decide what an omitted key
 * means.
 *
 * ⚠️ Only submitted keys are written. A partial save cannot blank a field it
 * never sent — the same rule as legacy/guardian-gateway.php, and it matters more
 * here because `submit` (the person) and `review` (the admin) write different
 * halves of the same row minutes apart.
 *
 * `expires_at` is computed from completed_at + the requirement's validity_days
 * UNLESS the caller passes one explicitly, which is how a certificate carrying
 * its own printed expiry is recorded without editing the requirement for
 * everybody. Passing an explicit null with a completed_at still computes —
 * "no expiry" is expressed by a requirement with no validity_days, not by an
 * override, because an override that means two things cannot be read back.
 *
 * @param array $data user_id, requirement_id, and any of status, completed_at,
 *                    expires_at, document_id, source, notes, rejection_reason,
 *                    verified_by, submitted_at
 * @return array{ok: bool, id?: int, expires_at?: ?string, created?: bool, reason?: string}
 */
function te_credential_upsert(PDO $pdo, array $data, ?int $actorId = null): array
{
    if (!te_compliance_tables_present($pdo)) {
        return ['ok' => false, 'reason' => 'schema'];
    }

    $userId = (int) ($data['user_id'] ?? 0);
    $requirementId = (int) ($data['requirement_id'] ?? 0);
    if ($userId <= 0 || $requirementId <= 0) {
        return ['ok' => false, 'reason' => 'user_and_requirement_required'];
    }

    $status = strtolower(trim((string) ($data['status'] ?? 'verified')));
    if (!in_array($status, TE_COMPLIANCE_STATUSES, true)) {
        return ['ok' => false, 'reason' => 'bad_status'];
    }
    $source = strtolower(trim((string) ($data['source'] ?? 'admin')));
    if (!in_array($source, TE_COMPLIANCE_SOURCES, true)) {
        return ['ok' => false, 'reason' => 'bad_source'];
    }

    try {
        $stmt = $pdo->prepare('SELECT id, validity_days FROM compliance_requirements WHERE id = ?');
        $stmt->execute([$requirementId]);
        $requirement = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('te_credential_upsert requirement: ' . $e->getMessage());
        return ['ok' => false, 'reason' => 'error'];
    }
    if (!$requirement) {
        return ['ok' => false, 'reason' => 'requirement_not_found'];
    }

    $completedAt = te_compliance_date_or_null($data['completed_at'] ?? null);
    if (array_key_exists('completed_at', $data) && $data['completed_at'] !== null
        && $data['completed_at'] !== '' && $completedAt === null) {
        return ['ok' => false, 'reason' => 'bad_completed_at'];
    }

    $validity = ($requirement['validity_days'] === null || $requirement['validity_days'] === '')
        ? null : (int) $requirement['validity_days'];

    $override = te_compliance_date_or_null($data['expires_at'] ?? null);
    $expiresAt = $override ?? te_compliance_expiry_from($completedAt, $validity);

    try {
        $stmt = $pdo->prepare('SELECT * FROM person_credentials WHERE user_id = ? AND requirement_id = ?');
        $stmt->execute([$userId, $requirementId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        error_log('te_credential_upsert select: ' . $e->getMessage());
        return ['ok' => false, 'reason' => 'error'];
    }

    // An omitted completed_at on an update keeps the stored one, and the expiry
    // is then recomputed from it — so changing a requirement's validity and
    // re-saving a person moves their expiry, which is what an admin means.
    if ($completedAt === null && $existing && !array_key_exists('completed_at', $data)) {
        $completedAt = te_compliance_date_or_null($existing['completed_at'] ?? null);
        $expiresAt = $override ?? te_compliance_expiry_from($completedAt, $validity);
    }

    $now = date('Y-m-d H:i:s');
    $fields = [
        'status'           => $status,
        'completed_at'     => $completedAt,
        'expires_at'       => $expiresAt,
        'source'           => $source,
        'document_id'      => isset($data['document_id']) && (int) $data['document_id'] > 0
                              ? (int) $data['document_id'] : ($existing['document_id'] ?? null),
        'notes'            => array_key_exists('notes', $data)
                              ? (string) $data['notes'] : ($existing['notes'] ?? null),
        'rejection_reason' => $status === 'rejected'
                              ? (string) ($data['rejection_reason'] ?? '')
                              : null,
        'submitted_at'     => $status === 'submitted' ? $now : ($existing['submitted_at'] ?? null),
        'verified_by'      => $status === 'verified'
                              ? ($actorId ?: ($data['verified_by'] ?? null))
                              : ($status === 'rejected' ? ($actorId ?: null) : ($existing['verified_by'] ?? null)),
        'verified_at'      => in_array($status, ['verified', 'rejected'], true)
                              ? $now : ($existing['verified_at'] ?? null),
        'updated_at'       => $now,
    ];

    try {
        if ($existing) {
            $set = implode(', ', array_map(static fn (string $k): string => "$k = ?", array_keys($fields)));
            $stmt = $pdo->prepare("UPDATE person_credentials SET $set WHERE id = ?");
            $stmt->execute(array_merge(array_values($fields), [(int) $existing['id']]));

            // A renewal is a NEW CYCLE for the reminders. The log is keyed on
            // the credential row, which is one row per person per requirement
            // forever — so without this, the 60-day step sent before the 2024
            // certificate lapsed would count as already sent for the 2026 one
            // and the person would never be reminded again. Clearing on a
            // changed expiry is the only thing that makes a second cycle
            // possible; an unchanged expiry (an admin editing notes) keeps the
            // history, so an edit cannot cause a resend.
            $before = te_compliance_date_or_null($existing['expires_at'] ?? null);
            if ($before !== $expiresAt) {
                try {
                    $pdo->prepare('DELETE FROM compliance_reminder_log WHERE credential_id = ?')
                        ->execute([(int) $existing['id']]);
                } catch (Throwable $e) {
                    error_log('te_credential_upsert reminder-log reset: ' . $e->getMessage());
                }
            }
            return ['ok' => true, 'id' => (int) $existing['id'], 'expires_at' => $expiresAt, 'created' => false];
        }

        $fields['user_id'] = $userId;
        $fields['requirement_id'] = $requirementId;
        $cols = implode(', ', array_keys($fields));
        $marks = implode(', ', array_fill(0, count($fields), '?'));
        $stmt = $pdo->prepare("INSERT INTO person_credentials ($cols) VALUES ($marks)");
        $stmt->execute(array_values($fields));
        $id = te_org_last_insert_id($pdo, 'person_credentials_id_seq');
        return ['ok' => true, 'id' => $id, 'expires_at' => $expiresAt, 'created' => true];
    } catch (Throwable $e) {
        error_log('te_credential_upsert write: ' . $e->getMessage());
        return ['ok' => false, 'reason' => 'error'];
    }
}

/** 'YYYY-MM-DD' or null. Anything else — including '' — is null. */
function te_compliance_date_or_null($value): ?string
{
    if ($value === null) {
        return null;
    }
    $day = substr(trim((string) $value), 0, 10);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) ? $day : null;
}

// ---------------------------------------------------------------- status ---

/**
 * One person's standing in one club: a row per requirement plus a rollup.
 *
 * ⚠️ A stored `verified` whose expires_at has passed is REPORTED as `expired`
 * here, whether or not the nightly sweep has run. The sweep is a convenience
 * for reporting queries; a screen that waited for it would tell a coach they
 * were cleared on the morning their certificate lapsed.
 *
 * The rollup counts missing / expiring_30 / expired across EVERY requirement,
 * so an optional certificate about to lapse still shows up somewhere. Only
 * `required` rows decide `compliant` — see rule 3 at the top of this file.
 *
 * `compliant` is true only when every required requirement is `verified` and
 * unexpired. `submitted` is deliberately not compliant: the whole point of the
 * review step is that somebody looked at the proof.
 *
 * @return array{requirements: array, rollup: array{compliant: bool, missing: int,
 *               expiring_30: int, expired: int, required_total: int, total: int}}
 */
function te_compliance_status(PDO $pdo, int $userId, int $clubId, ?string $today = null): array
{
    $today = $today ?? te_compliance_today();
    $requirements = te_person_requirements($pdo, $userId, $clubId);

    $credentials = [];
    if ($requirements && te_compliance_tables_present($pdo)) {
        try {
            $ids = array_map(static fn (array $r): int => $r['id'], $requirements);
            $marks = implode(', ', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare(
                "SELECT * FROM person_credentials WHERE user_id = ? AND requirement_id IN ($marks)"
            );
            $stmt->execute(array_merge([$userId], $ids));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $credentials[(int) $row['requirement_id']] = $row;
            }
        } catch (Throwable $e) {
            error_log('te_compliance_status: ' . $e->getMessage());
        }
    }

    $rows = [];
    $rollup = [
        'compliant' => true, 'missing' => 0, 'expiring_30' => 0, 'expired' => 0,
        'required_total' => 0, 'total' => count($requirements),
    ];

    foreach ($requirements as $req) {
        $credential = $credentials[$req['id']] ?? null;
        $status = $credential ? strtolower(trim((string) $credential['status'])) : 'missing';
        $completedAt = te_compliance_date_or_null($credential['completed_at'] ?? null);
        $expiresAt = te_compliance_date_or_null($credential['expires_at'] ?? null);
        $daysToExpiry = te_compliance_days_to($expiresAt, $today);

        if ($status === 'verified' && $daysToExpiry !== null && $daysToExpiry < 0) {
            $status = 'expired';
        }

        if ($status === 'missing') {
            $rollup['missing']++;
        } elseif ($status === 'expired') {
            $rollup['expired']++;
        } elseif ($status === 'verified' && $daysToExpiry !== null && $daysToExpiry <= 30) {
            $rollup['expiring_30']++;
        }

        if ($req['required']) {
            $rollup['required_total']++;
            if ($status !== 'verified') {
                $rollup['compliant'] = false;
            }
        }

        $rows[] = [
            'requirement'    => $req,
            'status'         => $status,
            'completed_at'   => $completedAt,
            'expires_at'     => $expiresAt,
            'days_to_expiry' => $daysToExpiry,
            'credential_id'  => $credential ? (int) $credential['id'] : null,
            'document_id'    => isset($credential['document_id']) && $credential['document_id'] !== null
                                ? (int) $credential['document_id'] : null,
            'rejection_reason' => $credential['rejection_reason'] ?? null,
            'source'         => $credential['source'] ?? null,
        ];
    }

    return ['requirements' => $rows, 'rollup' => $rollup];
}

// ----------------------------------------------------------------- sweep ---

/**
 * Move every `verified` credential whose expires_at has passed to `expired`.
 *
 * IDEMPOTENT by construction: the WHERE clause selects only rows that are still
 * `verified`, so a second run in the same minute updates nothing. That is the
 * property that lets this be a tick in the already-running queue worker rather
 * than a scheduler process nobody can afford to run twice.
 *
 * It does not send anything and it does not delete anything. Reads already
 * re-check the date (te_compliance_status), so a sweep that fails to run makes
 * reporting queries stale, never a screen wrong.
 *
 * Audited with the count, and only when something changed — a nightly row
 * saying "0" every night is how the one that says 400 gets scrolled past.
 *
 * @return array{ok: bool, expired: int, reason?: string}
 */
function te_compliance_expire_sweep(PDO $pdo, ?int $actorId = null): array
{
    if (!te_compliance_tables_present($pdo)) {
        return ['ok' => false, 'expired' => 0, 'reason' => 'schema'];
    }

    try {
        $stmt = $pdo->prepare(
            "UPDATE person_credentials
                SET status = 'expired', updated_at = ?
              WHERE status = 'verified'
                AND expires_at IS NOT NULL
                AND expires_at < ?"
        );
        $stmt->execute([date('Y-m-d H:i:s'), te_compliance_today()]);
        $count = $stmt->rowCount();
    } catch (Throwable $e) {
        error_log('te_compliance_expire_sweep: ' . $e->getMessage());
        return ['ok' => false, 'expired' => 0, 'reason' => 'error'];
    }

    if ($count > 0 && class_exists('AuditLogger')) {
        AuditLogger::log($pdo, $actorId, 'compliance_credentials_expired', 'person_credentials', null, [
            'count' => $count,
            'as_of' => te_compliance_today(),
        ]);
    }

    return ['ok' => true, 'expired' => $count];
}

// ------------------------------------------------------------- standing ---

/**
 * May this person administer compliance for this club?
 *
 * Club admin of the club, OR org_admin over the org unit the club hangs from —
 * a division admin manages their councils' requirements, which is the entire
 * point of the tier. `te_is_club_staff` is deliberately NOT accepted: a coach is
 * team-scoped and this is club-wide staff data about other people's background
 * checks.
 *
 * An `org_viewer` is not an admin here. They read rollups (G5) and write
 * nothing.
 */
function te_compliance_can_admin_club(PDO $pdo, $auth, int $clubId): bool
{
    require_once __DIR__ . '/club_standing.php';
    if ($clubId <= 0) {
        return false;
    }
    if (te_is_club_admin($auth, $clubId)) {
        return true;
    }

    $orgUnitId = te_compliance_club_org_unit_id($pdo, $clubId);
    if ($orgUnitId === null) {
        return false;
    }
    return te_user_org_standing($pdo, $auth, $orgUnitId) === 'org_admin';
}

/** The org unit a club hangs from, or null (every non-GOTR club). */
function te_compliance_club_org_unit_id(PDO $pdo, int $clubId): ?int
{
    if (!te_org_tables_present($pdo) || $clubId <= 0) {
        return null;
    }
    try {
        $stmt = $pdo->prepare('SELECT org_unit_id FROM club_profile WHERE id = ?');
        $stmt->execute([$clubId]);
        $value = $stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('te_compliance_club_org_unit_id: ' . $e->getMessage());
        return null;
    }
    return ($value === false || $value === null || $value === '') ? null : (int) $value;
}

/**
 * Every club this person holds an active role in — the clubs their compliance
 * is measured in.
 *
 * @return int[]
 */
function te_compliance_user_club_ids(PDO $pdo, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }
    $ids = [];
    try {
        $stmt = $pdo->prepare(
            'SELECT DISTINCT club_profile_id FROM user_club_access
              WHERE user_id = ? AND active = ' . te_compliance_true_literal($pdo) . ' AND revoked_at IS NULL'
        );
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: [] as $id) {
            if ((int) $id > 0) {
                $ids[] = (int) $id;
            }
        }
    } catch (Throwable $e) {
        error_log('te_compliance_user_club_ids: ' . $e->getMessage());
        return [];
    }

    if (te_compliance_tables_present($pdo)) {
        try {
            $stmt = $pdo->prepare('SELECT DISTINCT club_profile_id FROM club_staff_roles WHERE user_id = ?');
            $stmt->execute([$userId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: [] as $id) {
                if ((int) $id > 0 && !in_array((int) $id, $ids, true)) {
                    $ids[] = (int) $id;
                }
            }
        } catch (Throwable $e) {
            error_log('te_compliance_user_club_ids staff: ' . $e->getMessage());
        }
    }

    sort($ids);
    return $ids;
}

/**
 * Every staff member of a club, for the club-status roll call.
 *
 * The union of club_staff_roles and the staff roles of user_club_access, so a
 * club that has not adopted the GOTR vocabulary still gets a full list. Parents
 * and players are not staff and are not here.
 *
 * @return array<int, array{user_id:int, first_name:?string, last_name:?string, email:?string}>
 */
function te_compliance_club_staff(PDO $pdo, int $clubId): array
{
    if ($clubId <= 0) {
        return [];
    }

    $roles = "'" . implode("', '", array_keys(TE_COMPLIANCE_ROLE_FALLBACK)) . "'";
    $sql = "SELECT DISTINCT u.id AS user_id, u.first_name, u.last_name, u.email
              FROM users u
              JOIN user_club_access uca ON uca.user_id = u.id
             WHERE uca.club_profile_id = ?
               AND uca.active = " . te_compliance_true_literal($pdo) . "
               AND uca.revoked_at IS NULL
               AND LOWER(uca.role) IN ($roles)";
    $params = [$clubId];

    if (te_compliance_tables_present($pdo)) {
        $sql .= " UNION
            SELECT DISTINCT u.id AS user_id, u.first_name, u.last_name, u.email
              FROM users u
              JOIN club_staff_roles csr ON csr.user_id = u.id
             WHERE csr.club_profile_id = ?";
        $params[] = $clubId;
    }

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('te_compliance_club_staff: ' . $e->getMessage());
        return [];
    }

    foreach ($rows as &$row) {
        $row['user_id'] = (int) $row['user_id'];
    }
    usort($rows, static fn (array $a, array $b): int =>
        [strtolower((string) $a['last_name']), strtolower((string) $a['first_name'])]
        <=> [strtolower((string) $b['last_name']), strtolower((string) $b['first_name'])]);
    return $rows;
}
