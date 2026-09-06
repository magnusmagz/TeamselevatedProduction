<?php
/**
 * Division and national compliance rollups (GOTR G5).
 *
 * A council's compliance page is te_compliance_club_staff() + one
 * te_compliance_status() per person — a PHP loop over one club. A division
 * over 30 councils, or national over 270, cannot afford that loop per request,
 * so the rollup is ONE set-based query over every descendant club. That
 * creates the one hazard this file has to manage:
 *
 * ⚠️ THE SQL RE-STATES te_compliance_status(), AND THE TWO MUST NEVER DRIFT.
 *
 * Every rule that decides a person's standing is written twice — once in PHP
 * for the council screen, once in SQL here for the board report — and a
 * division admin looking at both must see the same coach counted the same
 * way. The rules, and where each lives in lib/compliance.php:
 *
 *   - staff of a club      = user_club_access in (coach, club_admin, volunteer),
 *                            active and not revoked, UNION club_staff_roles
 *                            (te_compliance_club_staff)
 *   - a person's roles     = club_staff_roles rows if any, else the mapped
 *                            user_club_access roles   (te_compliance_staff_roles)
 *   - a club's requirements = its own active rows + every active row on every
 *                            ANCESTOR org unit         (te_compliance_requirements_for_club)
 *   - a requirement applies = it names no roles, or names one the person holds
 *                            (te_person_requirements)
 *   - missing / expired / expiring_30 / compliant     (te_compliance_status)
 *
 * ComplianceRollupTest::testTheRollupAgreesWithThePerPersonPredicateForEverybody
 * runs BOTH against one fixture and fails if they disagree. When you change a
 * rule in lib/compliance.php, that test is what tells you to change it here.
 *
 * The scope CTE is te_org_descendant_club_ids_sql() — a subquery, never a
 * materialised `IN (?,?,…)` list, for the reason at the top of lib/org_scope.php.
 *
 * Date-only rule: `expires_at` is compared as a 'YYYY-MM-DD' string against
 * today and today+30 computed in PHP (UTC). No date arithmetic happens in SQL,
 * so Postgres and SQLite cannot answer differently.
 *
 * Read-only. Nothing in this file writes.
 */

require_once __DIR__ . '/org_scope.php';
require_once __DIR__ . '/compliance.php';

/** How many months the expiry trend looks ahead. */
const TE_COMPLIANCE_TREND_MONTHS = 6;

/**
 * 'YYYY-MM' for this month and the next five, from a 'YYYY-MM-DD' today.
 *
 * Built from the date's own year and month as integers — no DateTime addition,
 * so 31 Jan + 1 month cannot land in March.
 *
 * @return string[]
 */
function te_compliance_rollup_months(string $today): array
{
    $year = (int) substr($today, 0, 4);
    $month = (int) substr($today, 5, 2);
    $out = [];
    for ($i = 0; $i < TE_COMPLIANCE_TREND_MONTHS; $i++) {
        $out[] = sprintf('%04d-%02d', $year, $month);
        $month++;
        if ($month > 12) {
            $month = 1;
            $year++;
        }
    }
    return $out;
}

/**
 * The CTE chain everything in this file starts from: the clubs in scope, who
 * is staff there, what each club demands, and who owes what.
 *
 * With a requirement filter, `club_reqs` is narrowed to that one row, so
 * `owed` becomes "everybody who owes THIS requirement" — the population a
 * per-requirement report should count.
 *
 * @return array{sql: string, params: array}
 */
function te_compliance_rollup_cte_prefix(PDO $pdo, int $orgUnitId, ?int $requirementId = null): array
{
    $scope = te_org_descendant_club_ids_sql([$orgUnitId]);
    $true = te_compliance_true_literal($pdo);
    $staffRoles = "'" . implode("', '", array_keys(TE_COMPLIANCE_ROLE_FALLBACK)) . "'";

    $reqFilter = '';
    $params = $scope['params'];
    if ($requirementId !== null && $requirementId > 0) {
        $reqFilter = ' AND r.id = ?';
        $params[] = $requirementId;
    }

    $sql = 'WITH scope_clubs AS (' . $scope['sql'] . ')'
        // te_compliance_staff_roles: club_staff_roles first, else the mapped
        // user_club_access roles. The NOT EXISTS is the "else".
        . ', person_roles AS ('
        . '   SELECT csr.user_id, csr.club_profile_id AS club_id, LOWER(csr.staff_role) AS staff_role'
        . '     FROM club_staff_roles csr'
        . '    WHERE csr.club_profile_id IN (SELECT id FROM scope_clubs)'
        . '   UNION'
        . '   SELECT uca.user_id, uca.club_profile_id AS club_id, LOWER(uca.role) AS staff_role'
        . '     FROM user_club_access uca'
        . "    WHERE uca.active = $true AND uca.revoked_at IS NULL"
        . "      AND LOWER(uca.role) IN ($staffRoles)"
        . '      AND uca.club_profile_id IN (SELECT id FROM scope_clubs)'
        . '      AND NOT EXISTS (SELECT 1 FROM club_staff_roles c2'
        . '                       WHERE c2.user_id = uca.user_id AND c2.club_profile_id = uca.club_profile_id)'
        . ' )'
        . ', staff AS (SELECT DISTINCT user_id, club_id FROM person_roles)'
        // te_compliance_requirements_for_club: own rows + every ancestor's.
        . ', club_reqs AS ('
        . '   SELECT c.id AS club_id, r.id AS req_id, r.required'
        . '     FROM club_profile c'
        . '     JOIN compliance_requirements r'
        . "       ON r.active = $true"
        . '      AND (r.club_profile_id = c.id'
        . '           OR r.org_unit_id IN (SELECT a.id FROM org_units a'
        . '                                 JOIN org_units o ON o.path LIKE a.path || \'%\''
        . '                                WHERE o.id = c.org_unit_id))'
        . '    WHERE c.id IN (SELECT id FROM scope_clubs)' . $reqFilter
        . ' )'
        // te_person_requirements: no roles named = everyone; else intersect.
        . ', owed AS ('
        . '   SELECT DISTINCT s.user_id, s.club_id, cr.req_id, cr.required'
        . '     FROM staff s'
        . '     JOIN club_reqs cr ON cr.club_id = s.club_id'
        . '    WHERE NOT EXISTS (SELECT 1 FROM compliance_requirement_roles rr WHERE rr.requirement_id = cr.req_id)'
        . '       OR EXISTS (SELECT 1 FROM compliance_requirement_roles rr'
        . '                    JOIN person_roles pr ON pr.staff_role = rr.staff_role'
        . '                                        AND pr.user_id = s.user_id AND pr.club_id = s.club_id'
        . '                   WHERE rr.requirement_id = cr.req_id)'
        . ' )';

    return ['sql' => $sql, 'params' => $params];
}

/**
 * Per-council counts under one org unit, plus the total.
 *
 * Counts are per PERSON, exactly as `club-status` builds its summary: a person
 * with two lapsed certificates is one expired person. `compliant` means every
 * REQUIRED requirement is verified and unexpired (rule 3 in lib/compliance.php);
 * a still-valid certificate inside its 30-day window is compliant AND expiring.
 *
 * A council with nobody on staff is still a row, with zeros and a null
 * `risk_share` — "no staff" and "0% at risk" are different answers.
 *
 * Ordered highest risk first: non-compliant share descending, then the
 * non-compliant count, then name. The sort is in PHP because integer division
 * differs between the two drivers this has to run on.
 *
 * @return array{councils: array<int, array>, total: array}
 */
function te_compliance_rollup(PDO $pdo, int $orgUnitId, ?string $today = null, ?int $requirementId = null): array
{
    $empty = ['staff_total' => 0, 'compliant' => 0, 'expiring_30' => 0, 'expired' => 0, 'missing' => 0];
    if ($orgUnitId <= 0 || !te_org_tables_present($pdo) || !te_compliance_tables_present($pdo)) {
        return ['councils' => [], 'total' => $empty];
    }

    $today = $today ?? te_compliance_today();
    $in30 = (new DateTimeImmutable($today, new DateTimeZone('UTC')))->add(new DateInterval('P30D'))->format('Y-m-d');
    $true = te_compliance_true_literal($pdo);

    $prefix = te_compliance_rollup_cte_prefix($pdo, $orgUnitId, $requirementId);
    $filtered = $requirementId !== null && $requirementId > 0;

    // te_compliance_status: a stored 'verified' past its date is 'expired'
    // whether or not the sweep has run; within 30 days it is 'expiring' (still
    // verified for the purpose of compliance).
    $graded = ', graded AS ('
        . '   SELECT o.user_id, o.club_id, o.req_id, o.required,'
        . '          CASE'
        . '            WHEN pc.id IS NULL THEN \'missing\''
        . '            WHEN LOWER(pc.status) = \'verified\' AND pc.expires_at IS NOT NULL AND pc.expires_at < ? THEN \'expired\''
        . '            WHEN LOWER(pc.status) = \'verified\' AND pc.expires_at IS NOT NULL AND pc.expires_at <= ? THEN \'expiring\''
        . '            ELSE LOWER(pc.status)'
        . '          END AS state'
        . '     FROM owed o'
        . '     LEFT JOIN person_credentials pc ON pc.user_id = o.user_id AND pc.requirement_id = o.req_id'
        . ' )';

    // Unfiltered: every staff member counts, owing anything or not (a person
    // with no requirements is compliant, as club-status says). Filtered: only
    // the people who owe the requirement asked about.
    $population = $filtered
        ? ' SELECT g.user_id, g.club_id FROM graded g'
        : ' SELECT s.user_id, s.club_id FROM staff s';

    $perPerson = ', per_person AS ('
        . '   SELECT p.user_id, p.club_id,'
        . '          COALESCE(SUM(CASE WHEN g.state = \'missing\' THEN 1 ELSE 0 END), 0) AS missing,'
        . '          COALESCE(SUM(CASE WHEN g.state = \'expired\' THEN 1 ELSE 0 END), 0) AS expired,'
        . '          COALESCE(SUM(CASE WHEN g.state = \'expiring\' THEN 1 ELSE 0 END), 0) AS expiring,'
        . "          COALESCE(SUM(CASE WHEN g.required = $true AND g.state NOT IN ('verified', 'expiring') THEN 1 ELSE 0 END), 0) AS required_failing"
        . '     FROM (SELECT DISTINCT user_id, club_id FROM (' . $population . ') pop) p'
        . '     LEFT JOIN graded g ON g.user_id = p.user_id AND g.club_id = p.club_id'
        . '    GROUP BY p.user_id, p.club_id'
        . ' )';

    $sql = $prefix['sql'] . $graded . $perPerson
        . ' SELECT c.id AS club_id, c.name AS club_name, c.org_unit_id,'
        . '        o.name AS org_unit_name, o.type AS org_unit_type, o.path AS org_unit_path,'
        . '        COUNT(p.user_id) AS staff_total,'
        . '        COALESCE(SUM(CASE WHEN p.required_failing = 0 THEN 1 ELSE 0 END), 0) AS compliant,'
        . '        COALESCE(SUM(CASE WHEN p.expiring > 0 THEN 1 ELSE 0 END), 0) AS expiring_30,'
        . '        COALESCE(SUM(CASE WHEN p.expired > 0 THEN 1 ELSE 0 END), 0) AS expired,'
        . '        COALESCE(SUM(CASE WHEN p.missing > 0 THEN 1 ELSE 0 END), 0) AS missing'
        . '   FROM club_profile c'
        . '   JOIN org_units o ON o.id = c.org_unit_id'
        . '   LEFT JOIN per_person p ON p.club_id = c.id'
        . '  WHERE c.id IN (SELECT id FROM scope_clubs)'
        . '  GROUP BY c.id, c.name, c.org_unit_id, o.name, o.type, o.path';

    $params = array_merge($prefix['params'], [$today, $in30]);

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('te_compliance_rollup: ' . $e->getMessage());
        return ['councils' => [], 'total' => $empty];
    }

    $total = $empty;
    $councils = [];
    foreach ($rows as $row) {
        $staff = (int) $row['staff_total'];
        $compliant = (int) $row['compliant'];
        $council = [
            'club_id'       => (int) $row['club_id'],
            'club_name'     => (string) $row['club_name'],
            'org_unit_id'   => (int) $row['org_unit_id'],
            'org_unit_name' => (string) $row['org_unit_name'],
            'org_unit_type' => (string) $row['org_unit_type'],
            'org_unit_path' => (string) $row['org_unit_path'],
            'staff_total'   => $staff,
            'compliant'     => $compliant,
            'expiring_30'   => (int) $row['expiring_30'],
            'expired'       => (int) $row['expired'],
            'missing'       => (int) $row['missing'],
            'non_compliant' => $staff - $compliant,
            'risk_share'    => $staff > 0 ? round(($staff - $compliant) / $staff, 4) : null,
        ];
        $councils[] = $council;
        foreach (array_keys($empty) as $key) {
            $total[$key] += $council[$key];
        }
    }

    usort($councils, static function (array $a, array $b): int {
        // Highest risk first. A council with no staff sorts last.
        $ra = $a['risk_share'] ?? -1;
        $rb = $b['risk_share'] ?? -1;
        if ($ra !== $rb) {
            return $rb <=> $ra;
        }
        if ($a['non_compliant'] !== $b['non_compliant']) {
            return $b['non_compliant'] <=> $a['non_compliant'];
        }
        return strcasecmp($a['club_name'], $b['club_name']) ?: ($a['club_id'] <=> $b['club_id']);
    });

    return ['councils' => $councils, 'total' => $total];
}

/**
 * Per council, how many still-valid credentials expire in each of the next
 * six months.
 *
 * Only credentials that are `verified` today and owed by someone who is
 * currently staff — a certificate belonging to somebody who left the council
 * is not a renewal the council has to chase. Already-lapsed certificates are
 * in the rollup's `expired`, not here.
 *
 * The month key is SUBSTR(CAST(expires_at AS TEXT), 1, 7): on Postgres a DATE
 * casts to ISO text under the default DateStyle; on SQLite the column already
 * is that text. Every month in the window is present for every council, zero
 * or not, so a bar row renders the same width everywhere.
 *
 * @return array{months: string[], councils: array<int, array{club_id:int, club_name:string, by_month:int[]}>}
 */
function te_compliance_rollup_trend(PDO $pdo, int $orgUnitId, ?string $today = null): array
{
    $today = $today ?? te_compliance_today();
    $months = te_compliance_rollup_months($today);
    if ($orgUnitId <= 0 || !te_org_tables_present($pdo) || !te_compliance_tables_present($pdo)) {
        return ['months' => $months, 'councils' => []];
    }

    // Last day of the sixth month, as a date-only string.
    $lastMonth = end($months);
    $end = (new DateTimeImmutable($lastMonth . '-01', new DateTimeZone('UTC')))
        ->add(new DateInterval('P1M'))->sub(new DateInterval('P1D'))->format('Y-m-d');

    $prefix = te_compliance_rollup_cte_prefix($pdo, $orgUnitId);
    $sql = $prefix['sql']
        . ' SELECT c.id AS club_id, c.name AS club_name,'
        . '        SUBSTR(CAST(pc.expires_at AS TEXT), 1, 7) AS month, COUNT(*) AS n'
        . '   FROM owed o'
        . '   JOIN person_credentials pc ON pc.user_id = o.user_id AND pc.requirement_id = o.req_id'
        . '   JOIN club_profile c ON c.id = o.club_id'
        . "  WHERE LOWER(pc.status) = 'verified'"
        . '    AND pc.expires_at IS NOT NULL AND pc.expires_at >= ? AND pc.expires_at <= ?'
        . '  GROUP BY c.id, c.name, SUBSTR(CAST(pc.expires_at AS TEXT), 1, 7)';

    $byClub = [];
    try {
        // Every council in scope gets a row, expiries or not.
        $clubs = $pdo->prepare('SELECT c.id, c.name FROM club_profile c WHERE c.id IN ('
            . te_org_descendant_club_ids_sql([$orgUnitId])['sql'] . ') ORDER BY c.name');
        $clubs->execute([$orgUnitId]);
        foreach ($clubs->fetchAll(PDO::FETCH_ASSOC) ?: [] as $club) {
            $byClub[(int) $club['id']] = [
                'club_id'   => (int) $club['id'],
                'club_name' => (string) $club['name'],
                'by_month'  => array_fill(0, count($months), 0),
            ];
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($prefix['params'], [$today, $end]));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $index = array_search((string) $row['month'], $months, true);
            $clubId = (int) $row['club_id'];
            if ($index === false || !isset($byClub[$clubId])) {
                continue;
            }
            $byClub[$clubId]['by_month'][$index] = (int) $row['n'];
        }
    } catch (Throwable $e) {
        error_log('te_compliance_rollup_trend: ' . $e->getMessage());
        return ['months' => $months, 'councils' => []];
    }

    return ['months' => $months, 'councils' => array_values($byClub)];
}

/**
 * Is this club under this org unit? 'in', 'out', or 'missing' (no such club).
 *
 * Three answers, not a boolean, because the endpoint has to say 404 for a
 * club that does not exist and 403 for one that exists outside the caller's
 * tree — and a 404 for the second would let a caller probe which club ids
 * exist by watching which answer they get. The endpoint chooses; this only
 * reports.
 */
function te_compliance_rollup_club_scope(PDO $pdo, int $orgUnitId, int $clubId): string
{
    if ($clubId <= 0) {
        return 'missing';
    }
    try {
        $stmt = $pdo->prepare('SELECT id FROM club_profile WHERE id = ?');
        $stmt->execute([$clubId]);
        if ($stmt->fetchColumn() === false) {
            return 'missing';
        }
        if ($orgUnitId <= 0 || !te_org_tables_present($pdo)) {
            return 'out';
        }
        $scope = te_org_descendant_club_ids_sql([$orgUnitId]);
        $stmt = $pdo->prepare('SELECT 1 FROM club_profile c WHERE c.id = ? AND c.id IN (' . $scope['sql'] . ')');
        $stmt->execute(array_merge([$clubId], $scope['params']));
        return $stmt->fetchColumn() === false ? 'out' : 'in';
    } catch (Throwable $e) {
        error_log('te_compliance_rollup_club_scope: ' . $e->getMessage());
        return 'out';
    }
}

/**
 * The org units at and beneath one unit, in tree order, so the frontend can
 * nest councils under their division without a second round trip.
 *
 * @return array<int, array>
 */
function te_compliance_rollup_units(PDO $pdo, int $orgUnitId): array
{
    $target = te_org_unit($pdo, $orgUnitId);
    if (!$target || (string) $target['path'] === '') {
        return [];
    }
    $prefix = (string) $target['path'];
    return array_values(array_filter(
        te_org_unit_tree($pdo),
        static fn (array $u): bool => strpos((string) $u['path'], $prefix) === 0
    ));
}
