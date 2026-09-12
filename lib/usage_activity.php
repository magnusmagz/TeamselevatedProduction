<?php
/**
 * usage_activity — daily / weekly / monthly active users, by club and by role.
 *
 * One definition of "who holds which role in which club" serves both halves of
 * every rate: te_usage_population_sql() is the denominator (everyone who holds
 * the role) and, filtered to one user, the buckets a ping is recorded under
 * (the numerator). A rate whose two sides use different rules is not a rate.
 *
 * Roles are evaluated INDEPENDENTLY, so a coach-parent is in both buckets —
 * never `$isParent = !$isCoach` (CLAUDE.md, dual roles). The sources are the
 * same ones JWT::loadRoleSet and the parent portal use:
 *   - user_club_access rows that are active and unrevoked
 *   - coach derived from team standing (primary_coach_id, or an active
 *     assistant_coach / team_manager membership) on a non-deleted team
 *   - parent derived from the guardian chain (te_guardian_link_sql: a
 *     user_guardians link OR a case-insensitive email match) to a non-deleted
 *     athlete's club
 *   - super_admin from users.system_role, recorded against club 0 (platform)
 *
 * Portable SQL on purpose: UsageActivityTest runs every statement here against
 * SQLite, so no FILTER, no date arithmetic in SQL — windows are computed in PHP
 * and passed as strings.
 */

require_once __DIR__ . '/guardian_identity.php';

const TE_USAGE_PLATFORM_CLUB_ID = 0;
const TE_USAGE_SURFACES = ['staff', 'parent', 'referee', 'backfill'];

/**
 * (user_id, club_id, role) for everyone who holds a role, or for one user when
 * $userParam is given (the caller binds it). Rows are DISTINCT via UNION.
 */
function te_usage_population_sql(?string $userParam = null): string
{
    $link = te_guardian_link_sql('u', 'g');
    $ucaWhere = $userParam ? " AND uca.user_id = {$userParam}" : '';
    $teamWhere = $userParam ? " AND t.primary_coach_id = {$userParam}" : '';
    $tmWhere = $userParam ? " AND tm.user_id = {$userParam}" : '';
    $uWhere = $userParam ? " AND u.id = {$userParam}" : '';
    $saWhere = $userParam ? " AND u.id = {$userParam}" : '';

    return "
        SELECT uca.user_id AS user_id, uca.club_profile_id AS club_id, uca.role AS role
          FROM user_club_access uca
         WHERE uca.active = TRUE AND uca.revoked_at IS NULL AND uca.club_profile_id IS NOT NULL{$ucaWhere}
        UNION
        SELECT t.primary_coach_id, t.club_id, 'coach'
          FROM teams t
         WHERE t.primary_coach_id IS NOT NULL AND t.club_id IS NOT NULL AND t.deleted_at IS NULL{$teamWhere}
        UNION
        SELECT tm.user_id, t.club_id, 'coach'
          FROM team_members tm
          JOIN teams t ON t.id = tm.team_id
         WHERE tm.user_id IS NOT NULL AND tm.role IN ('assistant_coach', 'team_manager')
           AND tm.status = 'active' AND t.club_id IS NOT NULL AND t.deleted_at IS NULL{$tmWhere}
        UNION
        SELECT u.id, a.club_id, 'parent'
          FROM users u
          JOIN guardians g ON {$link}
          JOIN athlete_guardians ag ON ag.guardian_id = g.id
          JOIN athletes a ON a.id = ag.athlete_id AND a.deleted_at IS NULL
         WHERE a.club_id IS NOT NULL{$uWhere}
        UNION
        SELECT u.id, " . TE_USAGE_PLATFORM_CLUB_ID . ", 'super_admin'
          FROM users u
         WHERE u.system_role = 'super_admin'{$saWhere}
    ";
}

/** @return array<int, array{club_id:int, role:string}> the buckets one user is recorded under */
function te_usage_buckets_for_user(PDO $pdo, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }
    // The same placeholder appears five times; PDO with emulation off needs one bind
    // per occurrence, so give each its own name.
    $sql = te_usage_population_sql(':uid');
    $n = 0;
    $sql = preg_replace_callback('/:uid\b/', function () use (&$n) { return ':uid' . (++$n); }, $sql);
    $stmt = $pdo->prepare($sql);
    $params = [];
    for ($i = 1; $i <= $n; $i++) {
        $params[":uid{$i}"] = $userId;
    }
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['club_id' => (int)$r['club_id'], 'role' => (string)$r['role']];
    }
    usort($out, fn($a, $b) => [$a['club_id'], $a['role']] <=> [$b['club_id'], $b['role']]);
    return $out;
}

/**
 * Record that a user was active on a day. Idempotent: the row's last_seen_at
 * moves, nothing else. Returns the buckets written (empty for an account that
 * holds no role anywhere — a fact worth seeing, not an error).
 *
 * @return array<int, array{club_id:int, role:string}>
 */
function te_usage_record(PDO $pdo, int $userId, string $activityDate, string $surface = 'staff', ?string $nowTs = null): array
{
    if (!in_array($surface, TE_USAGE_SURFACES, true)) {
        $surface = 'staff';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $activityDate)) {
        throw new InvalidArgumentException('activity_date must be YYYY-MM-DD');
    }
    $now = $nowTs ?? gmdate('Y-m-d H:i:s');
    $buckets = te_usage_buckets_for_user($pdo, $userId);
    if (!$buckets) {
        return [];
    }
    $stmt = $pdo->prepare("
        INSERT INTO user_activity_daily (user_id, club_id, role, activity_date, surface, first_seen_at, last_seen_at)
        VALUES (:user_id, :club_id, :role, :activity_date, :surface, :first_seen, :last_seen)
        ON CONFLICT (user_id, club_id, role, activity_date)
        DO UPDATE SET last_seen_at = EXCLUDED.last_seen_at
    ");
    foreach ($buckets as $b) {
        $stmt->execute([
            ':user_id' => $userId,
            ':club_id' => $b['club_id'],
            ':role' => $b['role'],
            ':activity_date' => $activityDate,
            ':surface' => $surface,
            ':first_seen' => $now,
            ':last_seen' => $now,
        ]);
    }
    return $buckets;
}

/**
 * The client's local calendar day is the honest DAU day, but the client is the
 * client: accept it only within a day of the server's UTC date, else use ours.
 */
function te_usage_resolve_activity_date(?string $clientDate, ?string $serverToday = null): string
{
    $today = $serverToday ?? gmdate('Y-m-d');
    if (!$clientDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $clientDate)) {
        return $today;
    }
    $delta = abs((int)((strtotime($clientDate . ' UTC') - strtotime($today . ' UTC')) / 86400));
    return $delta <= 1 ? $clientDate : $today;
}

/** @return array<string, int> "club_id|role" => holders */
function te_usage_holders(PDO $pdo): array
{
    $rows = $pdo->query("SELECT club_id, role, COUNT(DISTINCT user_id) AS n FROM (" . te_usage_population_sql() . ") p GROUP BY club_id, role")
                ->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $out[(int)$r['club_id'] . '|' . $r['role']] = (int)$r['n'];
    }
    return $out;
}

/** @return array<string, int> "club_id|*" => distinct people holding any role in the club */
function te_usage_holders_by_club(PDO $pdo): array
{
    $rows = $pdo->query("SELECT club_id, COUNT(DISTINCT user_id) AS n FROM (" . te_usage_population_sql() . ") p GROUP BY club_id")
                ->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $out[(int)$r['club_id']] = (int)$r['n'];
    }
    return $out;
}

/**
 * DAU / WAU / MAU as of a day, per club × role, plus a per-club "any role" line
 * so one person with two roles is counted once for the club. Rates are against
 * the current holders of the role (the population is evaluated now; history is
 * not re-bucketed).
 *
 * @return array{as_of:string, rows:array<int, array>, clubs:array<int, array>}
 */
function te_usage_summary(PDO $pdo, string $asOf): array
{
    $dayStart = $asOf;
    $weekStart = te_usage_days_before($asOf, 6);
    $monthStart = te_usage_days_before($asOf, 29);

    $stmt = $pdo->prepare("
        SELECT club_id, role,
               COUNT(DISTINCT CASE WHEN activity_date = :d1 THEN user_id END) AS dau,
               COUNT(DISTINCT CASE WHEN activity_date >= :w1 THEN user_id END) AS wau,
               COUNT(DISTINCT user_id) AS mau
          FROM user_activity_daily
         WHERE activity_date >= :m1 AND activity_date <= :d2
         GROUP BY club_id, role
    ");
    $stmt->execute([':d1' => $dayStart, ':w1' => $weekStart, ':m1' => $monthStart, ':d2' => $asOf]);
    $byRole = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT club_id,
               COUNT(DISTINCT CASE WHEN activity_date = :d1 THEN user_id END) AS dau,
               COUNT(DISTINCT CASE WHEN activity_date >= :w1 THEN user_id END) AS wau,
               COUNT(DISTINCT user_id) AS mau
          FROM user_activity_daily
         WHERE activity_date >= :m1 AND activity_date <= :d2
         GROUP BY club_id
    ");
    $stmt->execute([':d1' => $dayStart, ':w1' => $weekStart, ':m1' => $monthStart, ':d2' => $asOf]);
    $byClub = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $holders = te_usage_holders($pdo);
    $clubHolders = te_usage_holders_by_club($pdo);

    // Every (club, role) with holders appears even with zero activity — a row of
    // zeros is the finding; an absent row reads as "not tracked".
    $rows = [];
    foreach ($holders as $key => $n) {
        [$clubId, $role] = explode('|', $key, 2);
        $rows[$key] = ['club_id' => (int)$clubId, 'role' => $role, 'holders' => $n, 'dau' => 0, 'wau' => 0, 'mau' => 0];
    }
    foreach ($byRole as $r) {
        $key = (int)$r['club_id'] . '|' . $r['role'];
        if (!isset($rows[$key])) {
            $rows[$key] = ['club_id' => (int)$r['club_id'], 'role' => $r['role'], 'holders' => 0, 'dau' => 0, 'wau' => 0, 'mau' => 0];
        }
        $rows[$key]['dau'] = (int)$r['dau'];
        $rows[$key]['wau'] = (int)$r['wau'];
        $rows[$key]['mau'] = (int)$r['mau'];
    }
    $clubs = [];
    foreach ($clubHolders as $clubId => $n) {
        $clubs[$clubId] = ['club_id' => $clubId, 'holders' => $n, 'dau' => 0, 'wau' => 0, 'mau' => 0];
    }
    foreach ($byClub as $r) {
        $clubId = (int)$r['club_id'];
        if (!isset($clubs[$clubId])) {
            $clubs[$clubId] = ['club_id' => $clubId, 'holders' => 0, 'dau' => 0, 'wau' => 0, 'mau' => 0];
        }
        $clubs[$clubId]['dau'] = (int)$r['dau'];
        $clubs[$clubId]['wau'] = (int)$r['wau'];
        $clubs[$clubId]['mau'] = (int)$r['mau'];
    }
    $rows = array_values($rows);
    usort($rows, fn($a, $b) => [$a['club_id'], $a['role']] <=> [$b['club_id'], $b['role']]);
    $clubs = array_values($clubs);
    usort($clubs, fn($a, $b) => $a['club_id'] <=> $b['club_id']);

    return ['as_of' => $asOf, 'week_start' => $weekStart, 'month_start' => $monthStart, 'rows' => $rows, 'clubs' => $clubs];
}

/**
 * Weekly active users per club × role for the last $weeks 7-day windows ending
 * on $asOf (the newest window is the partial current week when $asOf is today).
 * Aggregated in PHP from the daily rows: portable, and the volume is small.
 *
 * @return array{weeks:array<int,array{start:string,end:string}>, series:array<string, array<int,int>>}
 *   series key is "club_id|role" ("club_id|*" for any role), values align with weeks.
 */
function te_usage_weekly_trend(PDO $pdo, string $asOf, int $weeks = 12): array
{
    $weeks = max(1, min(52, $weeks));
    $windows = [];
    for ($i = $weeks - 1; $i >= 0; $i--) {
        $end = te_usage_days_before($asOf, $i * 7);
        $windows[] = ['start' => te_usage_days_before($end, 6), 'end' => $end];
    }
    $from = $windows[0]['start'];
    $stmt = $pdo->prepare("SELECT user_id, club_id, role, activity_date FROM user_activity_daily WHERE activity_date >= :f AND activity_date <= :t");
    $stmt->execute([':f' => $from, ':t' => $asOf]);
    $seen = []; // key => windowIndex => user_id => true
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        foreach ($windows as $i => $w) {
            if ($r['activity_date'] >= $w['start'] && $r['activity_date'] <= $w['end']) {
                $seen[(int)$r['club_id'] . '|' . $r['role']][$i][(int)$r['user_id']] = true;
                $seen[(int)$r['club_id'] . '|*'][$i][(int)$r['user_id']] = true;
                break;
            }
        }
    }
    $series = [];
    foreach ($seen as $key => $byWindow) {
        $series[$key] = [];
        foreach ($windows as $i => $_) {
            $series[$key][$i] = isset($byWindow[$i]) ? count($byWindow[$i]) : 0;
        }
    }
    ksort($series);
    return ['weeks' => $windows, 'series' => $series];
}

function te_usage_days_before(string $date, int $days): string
{
    return gmdate('Y-m-d', strtotime($date . ' UTC') - $days * 86400);
}

/** Does the 103 table exist? Lets the endpoints answer honestly before it is applied. */
function te_usage_table_present(PDO $pdo): bool
{
    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            // PRAGMA rather than sqlite_master: QueriedTablesExistTest scans FROM/JOIN.
            $r = $pdo->query("PRAGMA table_info(user_activity_daily)")->fetchAll();
            return count($r) > 0;
        }
        $r = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_name = 'user_activity_daily'")->fetch();
        return (bool)$r;
    } catch (Throwable $e) {
        return false;
    }
}
