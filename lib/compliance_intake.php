<?php
/**
 * Credential intake feeds (GOTR G7, migration 098).
 *
 * An LMS posts `{email, requirement_key, completed_on, external_id}` to
 * api/compliance-intake.php?action=lms with a bearer key, and the product
 * records a person_credentials row with source='lms'. This file is everything
 * behind that endpoint that can be tested without a web server: key minting
 * and verification, the per-key rate limit, person and requirement matching,
 * the write, and the unmatched queue an admin works by hand.
 *
 * ⚠️ FIVE RULES
 *
 * 1. THE KEY IS THE ORG UNIT. A key is minted by an org_admin of a unit and
 *    can only touch people who hold a staff role in a club under that unit.
 *    A person the unit does not have is UNKNOWN to that key — a 202 with an
 *    unmatched row, never a 404 that confirms who exists elsewhere.
 * 2. NEVER CREATE A USER. Accounts are made by the G6 onboarding path. An
 *    email nobody has lands in compliance_intake_unmatched for an admin to
 *    match by hand.
 * 3. THE KEY IS STORED HASHED and shown once. Verification is a sha256 of the
 *    presented plaintext compared to the stored hash; there is nothing to leak
 *    from the table.
 * 4. THE WRITE IS te_credential_upsert(), status 'verified', source 'lms'.
 *    Nothing here invents a second write path. Whether an LMS completion
 *    should be auto-accepted (verified) or land as 'submitted' for review is
 *    the plan's open question 4; this ships as verified because that is what
 *    "results arrive from an LMS" means, and it is one word to change.
 * 5. RATE-LIMITED PER KEY, 600/min. Redis when there is one; otherwise a count
 *    of this key's own audit rows in the last minute. Neither failing is a
 *    reason to accept unlimited traffic, and neither is a reason to refuse a
 *    legitimate feed — a broken Redis falls through to the database count.
 */

require_once __DIR__ . '/compliance.php';
require_once __DIR__ . '/org_scope.php';

/** The tables migration 098 creates. Both arrive together or not at all. */
const TE_COMPLIANCE_INTAKE_TABLES = ['compliance_intake_keys', 'compliance_intake_unmatched'];

/** Requests per key per minute. */
const TE_COMPLIANCE_INTAKE_RATE_PER_MINUTE = 600;

/** Plaintext keys start with this so one is recognisable in a config screen. */
const TE_COMPLIANCE_INTAKE_KEY_PREFIX = 'tei_';

/** Why an arrival could not be applied. */
const TE_COMPLIANCE_INTAKE_REASONS = ['no_person', 'no_requirement'];

// ------------------------------------------------------------------ probe ---

/** Are migration 098's tables live? Memoised per PDO like te_compliance_tables_present(). */
function te_compliance_intake_tables_present(PDO $pdo): bool
{
    static $memo = null;
    $memo ??= new WeakMap();
    if (isset($memo[$pdo])) {
        return $memo[$pdo];
    }
    $names = "'" . implode("', '", TE_COMPLIANCE_INTAKE_TABLES) . "'";
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_name IN ($names)");
        $stmt->execute();
        return $memo[$pdo] = ((int) $stmt->fetchColumn() === count(TE_COMPLIANCE_INTAKE_TABLES));
    } catch (Throwable $e) {
        try {
            foreach (TE_COMPLIANCE_INTAKE_TABLES as $table) {
                $pdo->query("SELECT 1 FROM {$table} LIMIT 1");
            }
            return $memo[$pdo] = true;
        } catch (Throwable $e2) {
            return $memo[$pdo] = false;
        }
    }
}

// ------------------------------------------------------------------- keys ---

/** A fresh key: the plaintext (shown once), its hash (stored), its prefix (listed). */
function te_compliance_intake_key_generate(): array
{
    $plain = TE_COMPLIANCE_INTAKE_KEY_PREFIX . bin2hex(random_bytes(20));
    return ['plain' => $plain, 'hash' => te_compliance_intake_key_hash($plain), 'prefix' => substr($plain, 0, 8)];
}

function te_compliance_intake_key_hash(string $plain): string
{
    return hash('sha256', $plain);
}

/** The token out of an `Authorization: Bearer …` header, or null for anything else. */
function te_compliance_intake_bearer_from_header(?string $header): ?string
{
    if ($header === null || !preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $m)) {
        return null;
    }
    return $m[1];
}

/**
 * Create a key for an org unit. Returns the plaintext ONCE; nothing stores it.
 *
 * @return array{ok: bool, id?: int, plain?: string, prefix?: string, reason?: string, error?: string}
 */
function te_compliance_intake_key_create(PDO $pdo, int $orgUnitId, string $name, ?int $actorId): array
{
    if (!te_compliance_intake_tables_present($pdo)) {
        return ['ok' => false, 'reason' => 'schema', 'error' => 'Intake tables are not present'];
    }
    if ($orgUnitId <= 0 || !te_org_unit($pdo, $orgUnitId)) {
        return ['ok' => false, 'reason' => 'unit_not_found', 'error' => 'Org unit not found'];
    }
    $name = trim($name);
    if ($name === '') {
        return ['ok' => false, 'reason' => 'name_required', 'error' => 'A key needs a name'];
    }
    $key = te_compliance_intake_key_generate();
    try {
        $pdo->prepare(
            'INSERT INTO compliance_intake_keys (org_unit_id, name, key_hash, key_prefix, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$orgUnitId, mb_substr($name, 0, 120), $key['hash'], $key['prefix'], $actorId ?: null, date('Y-m-d H:i:s')]);
        $id = te_org_last_insert_id($pdo, 'compliance_intake_keys_id_seq');
    } catch (Throwable $e) {
        error_log('te_compliance_intake_key_create: ' . $e->getMessage());
        return ['ok' => false, 'reason' => 'error', 'error' => 'Could not create the key'];
    }
    return ['ok' => true, 'id' => $id, 'plain' => $key['plain'], 'prefix' => $key['prefix']];
}

/** Every key of a unit, revoked ones included, without hashes. */
function te_compliance_intake_keys(PDO $pdo, int $orgUnitId): array
{
    if (!te_compliance_intake_tables_present($pdo) || $orgUnitId <= 0) {
        return [];
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT id, org_unit_id, name, key_prefix, created_by, created_at, last_used_at, revoked_at, revoked_by
               FROM compliance_intake_keys WHERE org_unit_id = ? ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute([$orgUnitId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('te_compliance_intake_keys: ' . $e->getMessage());
        return [];
    }
    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['org_unit_id'] = (int) $row['org_unit_id'];
        $row['active'] = $row['revoked_at'] === null;
    }
    return $rows;
}

/** Revoke a key. The unit must own it — a key id is not a capability. */
function te_compliance_intake_key_revoke(PDO $pdo, int $keyId, int $orgUnitId, ?int $actorId): array
{
    if (!te_compliance_intake_tables_present($pdo)) {
        return ['ok' => false, 'reason' => 'schema'];
    }
    try {
        $stmt = $pdo->prepare('SELECT id, org_unit_id, revoked_at FROM compliance_intake_keys WHERE id = ?');
        $stmt->execute([$keyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int) $row['org_unit_id'] !== $orgUnitId) {
            return ['ok' => false, 'reason' => 'not_found'];
        }
        if ($row['revoked_at'] !== null) {
            return ['ok' => true, 'id' => $keyId, 'already' => true];
        }
        $pdo->prepare('UPDATE compliance_intake_keys SET revoked_at = ?, revoked_by = ? WHERE id = ?')
            ->execute([date('Y-m-d H:i:s'), $actorId ?: null, $keyId]);
    } catch (Throwable $e) {
        error_log('te_compliance_intake_key_revoke: ' . $e->getMessage());
        return ['ok' => false, 'reason' => 'error'];
    }
    return ['ok' => true, 'id' => $keyId, 'already' => false];
}

/**
 * Authenticate a presented key.
 *
 * @return array{ok: bool, key?: array, reason: 'ok'|'missing'|'unknown'|'revoked'|'schema'}
 */
function te_compliance_intake_authenticate(PDO $pdo, ?string $plain): array
{
    $plain = trim((string) $plain);
    if ($plain === '') {
        return ['ok' => false, 'reason' => 'missing'];
    }
    if (!te_compliance_intake_tables_present($pdo)) {
        return ['ok' => false, 'reason' => 'schema'];
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT id, org_unit_id, name, key_prefix, revoked_at FROM compliance_intake_keys WHERE key_hash = ?'
        );
        $stmt->execute([te_compliance_intake_key_hash($plain)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('te_compliance_intake_authenticate: ' . $e->getMessage());
        return ['ok' => false, 'reason' => 'unknown'];
    }
    if (!$row) {
        return ['ok' => false, 'reason' => 'unknown'];
    }
    if ($row['revoked_at'] !== null) {
        return ['ok' => false, 'reason' => 'revoked'];
    }
    return ['ok' => true, 'reason' => 'ok', 'key' => [
        'id' => (int) $row['id'], 'org_unit_id' => (int) $row['org_unit_id'],
        'name' => (string) $row['name'], 'key_prefix' => (string) $row['key_prefix'],
    ]];
}

/** Stamp last_used_at. Best-effort; never fails a request. */
function te_compliance_intake_touch_key(PDO $pdo, int $keyId): void
{
    try {
        $pdo->prepare('UPDATE compliance_intake_keys SET last_used_at = ? WHERE id = ?')
            ->execute([date('Y-m-d H:i:s'), $keyId]);
    } catch (Throwable $e) {
        error_log('te_compliance_intake_touch_key: ' . $e->getMessage());
    }
}

// ------------------------------------------------------------- rate limit ---

/**
 * Has this key exceeded TE_COMPLIANCE_INTAKE_RATE_PER_MINUTE in the current minute?
 *
 * Redis: INCR on a per-key-per-minute counter with a two-minute expiry. The
 * client is anything with incr()/expire() (Predis in production, a stub in the
 * tests). A client that throws is treated as absent and the database count is
 * used instead — never "unlimited".
 *
 * Database: this key's `compliance_intake_received` audit rows in the last 60
 * seconds. The audit row is written for every accepted arrival anyway (every
 * write is audited), so nothing extra is stored to make the fallback work.
 *
 * `$now` is injectable for the tests; it is 'Y-m-d H:i:s' in the server's zone,
 * the same clock AuditLogger's NOW() stamps rows with.
 */
function te_compliance_intake_rate_limited(PDO $pdo, int $keyId, $redis = null, ?string $now = null): bool
{
    $now = $now ?? date('Y-m-d H:i:s');
    $limit = TE_COMPLIANCE_INTAKE_RATE_PER_MINUTE;

    if ($redis !== null) {
        try {
            $minute = substr($now, 0, 16); // 'Y-m-d H:i'
            $bucket = 'te:intake:' . $keyId . ':' . str_replace([' ', ':', '-'], '', $minute);
            $count = (int) $redis->incr($bucket);
            if ($count === 1) {
                $redis->expire($bucket, 120);
            }
            return $count > $limit;
        } catch (Throwable $e) {
            error_log('te_compliance_intake_rate_limited redis, falling back to DB: ' . $e->getMessage());
        }
    }

    try {
        $since = (new DateTimeImmutable($now))->sub(new DateInterval('PT60S'))->format('Y-m-d H:i:s');
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM audit_log
              WHERE action = 'compliance_intake_received'
                AND resource_type = 'compliance_intake_keys'
                AND resource_id = ?
                AND created_at > ?"
        );
        $stmt->execute([$keyId, $since]);
        return (int) $stmt->fetchColumn() >= $limit;
    } catch (Throwable $e) {
        // Fails OPEN for one request, and says so. A feed that is refused
        // because the counter is broken loses real completions; one request
        // over the line loses nothing.
        error_log('te_compliance_intake_rate_limited db, allowing: ' . $e->getMessage());
        return false;
    }
}

// -------------------------------------------------------------- matching ---

/** Every club under an org unit (the unit itself included). */
function te_compliance_intake_clubs_under_unit(PDO $pdo, int $orgUnitId): array
{
    if ($orgUnitId <= 0 || !te_org_tables_present($pdo)) {
        return [];
    }
    $sub = te_org_descendant_club_ids_sql([$orgUnitId]);
    try {
        $stmt = $pdo->prepare('SELECT c.id FROM club_profile c WHERE c.id IN (' . $sub['sql'] . ')');
        $stmt->execute($sub['params']);
        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: [] as $id) {
            if ((int) $id > 0) {
                $ids[] = (int) $id;
            }
        }
        sort($ids);
        return $ids;
    } catch (Throwable $e) {
        error_log('te_compliance_intake_clubs_under_unit: ' . $e->getMessage());
        return [];
    }
}

/**
 * The person an email names, if they hold a STAFF role in a club under the unit.
 *
 * LOWER() on both sides — the one-capital-letter lesson. `users.email` is
 * unique, so at most one account matches; the question is whether that account
 * is staff somewhere this key reaches. Returns the clubs under the unit where
 * they are staff, because the requirement is resolved against those.
 *
 * @return array{user_id: ?int, clubs: int[]}
 */
function te_compliance_intake_find_person(PDO $pdo, string $email, int $orgUnitId): array
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return ['user_id' => null, 'clubs' => []];
    }
    try {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = ?');
        $stmt->execute([$email]);
        $userId = $stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('te_compliance_intake_find_person: ' . $e->getMessage());
        return ['user_id' => null, 'clubs' => []];
    }
    if ($userId === false || $userId === null) {
        return ['user_id' => null, 'clubs' => []];
    }
    $userId = (int) $userId;

    $under = te_compliance_intake_clubs_under_unit($pdo, $orgUnitId);
    $clubs = [];
    foreach (te_compliance_user_club_ids($pdo, $userId) as $clubId) {
        if (in_array($clubId, $under, true) && te_compliance_staff_roles($pdo, $userId, $clubId)) {
            $clubs[] = $clubId;
        }
    }
    return ['user_id' => $clubs ? $userId : null, 'clubs' => $clubs];
}

/** 'Concussion Protocol (2026)' -> 'concussion_protocol_2026'. */
function te_compliance_intake_requirement_slug(string $name): string
{
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?? '';
    return trim($slug, '_');
}

/**
 * Resolve a requirement key against the requirements the person's clubs
 * inherit. The key is a numeric requirement id or the slug of its name.
 *
 * @param int[] $clubIds
 */
function te_compliance_intake_resolve_requirement(PDO $pdo, string $key, array $clubIds): ?array
{
    $key = trim($key);
    if ($key === '') {
        return null;
    }
    $wantId = ctype_digit($key) ? (int) $key : 0;
    $wantSlug = te_compliance_intake_requirement_slug($key);

    foreach ($clubIds as $clubId) {
        foreach (te_compliance_requirements_for_club($pdo, (int) $clubId) as $req) {
            if (($wantId > 0 && (int) $req['id'] === $wantId)
                || ($wantSlug !== '' && te_compliance_intake_requirement_slug((string) $req['name']) === $wantSlug)) {
                return $req;
            }
        }
    }
    return null;
}

/**
 * Validate the feed body. email, requirement_key and completed_on are
 * required; completed_on is 'YYYY-MM-DD' and not in the future; external_id
 * is optional free text.
 *
 * @return array{ok: bool, payload?: array, error?: string}
 */
function te_compliance_intake_validate_payload(array $body, ?string $today = null): array
{
    $email = trim((string) ($body['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'email is required and must be an email address'];
    }
    $key = trim((string) ($body['requirement_key'] ?? ''));
    if ($key === '' || mb_strlen($key) > 120) {
        return ['ok' => false, 'error' => 'requirement_key is required (a requirement id or the slug of its name)'];
    }
    $completed = te_compliance_date_or_null($body['completed_on'] ?? null);
    if ($completed === null) {
        return ['ok' => false, 'error' => 'completed_on is required as YYYY-MM-DD'];
    }
    $today = $today ?? te_compliance_today();
    if ($completed > $today) {
        return ['ok' => false, 'error' => 'completed_on cannot be in the future'];
    }
    $external = isset($body['external_id']) ? trim((string) $body['external_id']) : '';
    return ['ok' => true, 'payload' => [
        'email' => $email, 'requirement_key' => $key, 'completed_on' => $completed,
        'external_id' => $external === '' ? null : mb_substr($external, 0, 255),
    ]];
}

/**
 * Apply one arrival.
 *
 * @param array $key from te_compliance_intake_authenticate()
 * @return array{ok: bool, matched?: bool, user_id?: int, credential_id?: int,
 *               unmatched_id?: int, reason?: string, error?: string}
 */
function te_compliance_intake_receive(PDO $pdo, array $key, array $body, ?string $today = null): array
{
    if (!te_compliance_intake_tables_present($pdo) || !te_compliance_tables_present($pdo)) {
        return ['ok' => false, 'reason' => 'schema', 'error' => 'Intake tables are not present'];
    }
    $validated = te_compliance_intake_validate_payload($body, $today);
    if (!$validated['ok']) {
        return ['ok' => false, 'reason' => 'invalid', 'error' => $validated['error']];
    }
    $payload = $validated['payload'];
    $orgUnitId = (int) ($key['org_unit_id'] ?? 0);
    $keyId = (int) ($key['id'] ?? 0);

    $person = te_compliance_intake_find_person($pdo, $payload['email'], $orgUnitId);
    if ($person['user_id'] === null) {
        $id = te_compliance_intake_record_unmatched($pdo, $orgUnitId, $keyId, $payload, 'no_person', $body);
        return ['ok' => true, 'matched' => false, 'reason' => 'no_person', 'unmatched_id' => $id];
    }

    $requirement = te_compliance_intake_resolve_requirement($pdo, $payload['requirement_key'], $person['clubs']);
    if ($requirement === null) {
        $id = te_compliance_intake_record_unmatched($pdo, $orgUnitId, $keyId, $payload, 'no_requirement', $body);
        return ['ok' => true, 'matched' => false, 'reason' => 'no_requirement', 'unmatched_id' => $id];
    }

    $written = te_compliance_intake_write_credential($pdo, $person['user_id'], (int) $requirement['id'], $payload);
    if (!$written['ok']) {
        return ['ok' => false, 'reason' => $written['reason'] ?? 'error', 'error' => 'Could not record the credential'];
    }
    return ['ok' => true, 'matched' => true, 'user_id' => $person['user_id'], 'credential_id' => $written['id'],
            'requirement_id' => (int) $requirement['id'], 'expires_at' => $written['expires_at'] ?? null];
}

/** The one write. status 'verified', source 'lms' — rule 4 in the header. */
function te_compliance_intake_write_credential(PDO $pdo, int $userId, int $requirementId, array $payload): array
{
    $note = 'LMS completion' . ($payload['external_id'] ? ' ' . $payload['external_id'] : '')
        . ' received ' . date('Y-m-d');
    return te_credential_upsert($pdo, [
        'user_id'        => $userId,
        'requirement_id' => $requirementId,
        'status'         => 'verified',
        'completed_at'   => $payload['completed_on'],
        'source'         => 'lms',
        'notes'          => $note,
    ], null);
}

function te_compliance_intake_record_unmatched(PDO $pdo, int $orgUnitId, int $keyId, array $payload, string $reason, array $raw): int
{
    try {
        $pdo->prepare(
            'INSERT INTO compliance_intake_unmatched
                (org_unit_id, key_id, email, requirement_key, completed_on, external_id, reason, payload, received_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $orgUnitId, $keyId > 0 ? $keyId : null, $payload['email'], $payload['requirement_key'],
            $payload['completed_on'], $payload['external_id'], $reason,
            json_encode($raw, JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s'),
        ]);
        return te_org_last_insert_id($pdo, 'compliance_intake_unmatched_id_seq');
    } catch (Throwable $e) {
        error_log('te_compliance_intake_record_unmatched: ' . $e->getMessage());
        return 0;
    }
}

/** Open (unmatched) arrivals for a unit, newest first. */
function te_compliance_intake_unmatched(PDO $pdo, int $orgUnitId, int $limit = 200): array
{
    if (!te_compliance_intake_tables_present($pdo) || $orgUnitId <= 0) {
        return [];
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT id, org_unit_id, key_id, email, requirement_key, completed_on, external_id, reason, received_at
               FROM compliance_intake_unmatched
              WHERE org_unit_id = ? AND matched_at IS NULL
              ORDER BY received_at DESC, id DESC
              LIMIT ' . max(1, (int) $limit)
        );
        $stmt->execute([$orgUnitId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('te_compliance_intake_unmatched: ' . $e->getMessage());
        return [];
    }
    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['org_unit_id'] = (int) $row['org_unit_id'];
        $row['key_id'] = $row['key_id'] === null ? null : (int) $row['key_id'];
    }
    return $rows;
}

/**
 * An admin matches an open arrival to a person by hand.
 *
 * The person must be staff under the unit (the same predicate the feed uses);
 * the requirement is the arrival's own key unless the arrival was
 * `no_requirement`, in which case the admin names one. Writes the credential
 * with source='lms' — it is still the LMS's result, just routed by a human —
 * and closes the row.
 *
 * @return array{ok: bool, credential_id?: int, reason?: string, error?: string}
 */
function te_compliance_intake_match(PDO $pdo, int $orgUnitId, int $unmatchedId, int $userId, ?int $actorId, ?int $requirementId = null): array
{
    if (!te_compliance_intake_tables_present($pdo)) {
        return ['ok' => false, 'reason' => 'schema', 'error' => 'Intake tables are not present'];
    }
    try {
        $stmt = $pdo->prepare('SELECT * FROM compliance_intake_unmatched WHERE id = ?');
        $stmt->execute([$unmatchedId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('te_compliance_intake_match select: ' . $e->getMessage());
        return ['ok' => false, 'reason' => 'error', 'error' => 'Could not read the arrival'];
    }
    if (!$row || (int) $row['org_unit_id'] !== $orgUnitId) {
        return ['ok' => false, 'reason' => 'not_found', 'error' => 'Arrival not found'];
    }
    if ($row['matched_at'] !== null) {
        return ['ok' => false, 'reason' => 'already_matched', 'error' => 'That arrival has already been matched'];
    }

    // Staff under the unit — resolved from the DB, never from the body.
    $under = te_compliance_intake_clubs_under_unit($pdo, $orgUnitId);
    $clubs = [];
    foreach (te_compliance_user_club_ids($pdo, $userId) as $clubId) {
        if (in_array($clubId, $under, true) && te_compliance_staff_roles($pdo, $userId, $clubId)) {
            $clubs[] = $clubId;
        }
    }
    if (!$clubs) {
        return ['ok' => false, 'reason' => 'person_not_under_unit', 'error' => 'That person does not hold a staff role under this organization'];
    }

    $requirement = $requirementId !== null && $requirementId > 0
        ? te_compliance_intake_resolve_requirement($pdo, (string) $requirementId, $clubs)
        : te_compliance_intake_resolve_requirement($pdo, (string) $row['requirement_key'], $clubs);
    if ($requirement === null) {
        return ['ok' => false, 'reason' => 'no_requirement', 'error' => 'Choose which requirement this completion is for'];
    }

    $completed = te_compliance_date_or_null($row['completed_on'] ?? null);
    if ($completed === null) {
        return ['ok' => false, 'reason' => 'bad_date', 'error' => 'The arrival carries no completion date'];
    }

    $written = te_compliance_intake_write_credential($pdo, $userId, (int) $requirement['id'], [
        'completed_on' => $completed, 'external_id' => $row['external_id'],
    ]);
    if (!$written['ok']) {
        return ['ok' => false, 'reason' => $written['reason'] ?? 'error', 'error' => 'Could not record the credential'];
    }

    try {
        $pdo->prepare(
            'UPDATE compliance_intake_unmatched
                SET matched_user_id = ?, credential_id = ?, matched_by = ?, matched_at = ?
              WHERE id = ?'
        )->execute([$userId, $written['id'], $actorId ?: null, date('Y-m-d H:i:s'), $unmatchedId]);
    } catch (Throwable $e) {
        error_log('te_compliance_intake_match close: ' . $e->getMessage());
    }
    return ['ok' => true, 'credential_id' => (int) $written['id'], 'requirement_id' => (int) $requirement['id'], 'user_id' => $userId];
}
