<?php
/**
 * The national onboarding funnel (GOTR G6): per council under an org unit,
 * how far its coaches have got.
 *
 *   accounts   coach role rows (user_club_access, active, unrevoked) in the club
 *   invited    …of those, with any `<email>:coach_invite` token — a re-send is
 *              still one person
 *   accepted   …with a coach-invite token whose used_at is set. This is the only
 *              fact that means "accepted": a password_hash is not (it used to be
 *              `password123`), and neither is an email having been opened.
 *   signed_in  the portal_status evidence order — an audit `login_success` row
 *              (resource_type 'user' OR 'users', both exist in prod) or
 *              users.last_login_at. Fifteen live users have the second and not
 *              the first.
 *   compliant  te_compliance_status()'s rollup, reused rather than re-derived.
 *              NULL when the council has no requirements at all — "not
 *              applicable" and "zero compliant" are different answers, and a
 *              zero here would read as an emergency.
 *
 * Each count is ONE set-based query over te_org_descendant_club_ids_sql(), so a
 * division over 30 councils costs the same five statements as a council over
 * one. Compliance is the exception on purpose: the rule lives in
 * te_compliance_status() and takes one person at a time, and the alternative —
 * a second SQL implementation of "required, verified, unexpired, applies to
 * your role" — would be exactly the drift CLAUDE.md keeps recording. The loop
 * only runs for councils that have requirements, and is capped so a report
 * cannot time out; the cap is reported, never silent.
 *
 * Every function tolerates migration 090 being absent (`available: false`),
 * like the rest of lib/org_scope.php.
 */

require_once __DIR__ . '/org_scope.php';
require_once __DIR__ . '/compliance.php';

/** People evaluated for compliance per request, across all councils. */
const TE_ONBOARDING_COMPLIANCE_CAP = 2000;

/**
 * @return array{
 *   available: bool, org_unit: ?array, councils: array, totals: array,
 *   compliance_capped: bool
 * }
 */
function te_onboarding_funnel(PDO $pdo, int $orgUnitId, ?string $today = null): array
{
    $empty = ['available' => false, 'org_unit' => null, 'councils' => [], 'totals' => te_onboarding_zero(),
              'compliance_capped' => false];

    if (!te_org_tables_present($pdo) || $orgUnitId <= 0) {
        return $empty;
    }
    $unit = te_org_unit($pdo, $orgUnitId);
    if (!$unit) {
        return $empty;
    }

    $scope = te_org_descendant_club_ids_sql([$orgUnitId]);
    $true = te_compliance_true_literal($pdo);

    // The councils themselves, in tree order.
    $stmt = $pdo->prepare(
        "SELECT c.id AS club_id, c.name AS club_name, o.id AS org_unit_id, o.name AS org_unit_name,
                o.external_code AS council_code, o.path
           FROM club_profile c
           JOIN org_units o ON o.id = c.org_unit_id
          WHERE c.id IN ({$scope['sql']})
          ORDER BY o.path, c.name, c.id"
    );
    $stmt->execute($scope['params']);
    $councils = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $councils[(int) $row['club_id']] = [
            'club_id'       => (int) $row['club_id'],
            'club_name'     => (string) $row['club_name'],
            'org_unit_id'   => (int) $row['org_unit_id'],
            'org_unit_name' => (string) $row['org_unit_name'],
            'council_code'  => $row['council_code'] !== null ? (string) $row['council_code'] : null,
        ] + te_onboarding_zero();
    }

    // The coach population, once: everything below is a predicate on it.
    $population = "FROM user_club_access uca
                   JOIN users u ON u.id = uca.user_id
                  WHERE uca.role = 'coach'
                    AND uca.active = {$true}
                    AND uca.revoked_at IS NULL
                    AND uca.club_profile_id IN ({$scope['sql']})";
    $inviteKey = "LOWER(TRIM(u.email)) || ':coach_invite'";

    $metrics = [
        'accounts'  => '',
        'invited'   => "AND EXISTS (SELECT 1 FROM magic_link_tokens t WHERE t.email = {$inviteKey})",
        'accepted'  => "AND EXISTS (SELECT 1 FROM magic_link_tokens t WHERE t.email = {$inviteKey} AND t.used_at IS NOT NULL)",
        'signed_in' => "AND (u.last_login_at IS NOT NULL OR EXISTS (
                            SELECT 1 FROM audit_log al
                             WHERE al.action = 'login_success'
                               AND al.resource_type IN ('user', 'users')
                               AND al.resource_id = u.id))",
    ];

    foreach ($metrics as $name => $predicate) {
        $stmt = $pdo->prepare(
            "SELECT uca.club_profile_id AS club_id, COUNT(DISTINCT uca.user_id) AS n
             {$population} {$predicate}
             GROUP BY uca.club_profile_id"
        );
        $stmt->execute($scope['params']);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $clubId = (int) $row['club_id'];
            if (isset($councils[$clubId])) {
                $councils[$clubId][$name] = (int) $row['n'];
            }
        }
    }

    // Compliance: reuse the one rule, only where there is something to be compliant with.
    $capped = false;
    $evaluated = 0;
    if (te_compliance_tables_present($pdo)) {
        $stmt = $pdo->prepare(
            "SELECT uca.club_profile_id AS club_id, uca.user_id {$population} ORDER BY uca.club_profile_id, uca.user_id"
        );
        $stmt->execute($scope['params']);
        $people = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $people[(int) $row['club_id']][] = (int) $row['user_id'];
        }

        foreach ($councils as $clubId => &$council) {
            if (!te_compliance_requirements_for_club($pdo, $clubId)) {
                continue; // stays null: nothing is required here
            }
            $council['compliant'] = 0;
            foreach ($people[$clubId] ?? [] as $userId) {
                if ($evaluated >= TE_ONBOARDING_COMPLIANCE_CAP) {
                    $capped = true;
                    break 2;
                }
                $evaluated++;
                $status = te_compliance_status($pdo, $userId, $clubId, $today);
                if (!empty($status['rollup']['compliant'])) {
                    $council['compliant']++;
                }
            }
        }
        unset($council);
    }

    $totals = te_onboarding_zero();
    $anyCompliance = false;
    foreach ($councils as $council) {
        foreach (['accounts', 'invited', 'accepted', 'signed_in'] as $k) {
            $totals[$k] += $council[$k];
        }
        if ($council['compliant'] !== null) {
            $anyCompliance = true;
            $totals['compliant'] = (int) $totals['compliant'] + $council['compliant'];
        }
    }
    if (!$anyCompliance) {
        $totals['compliant'] = null;
    }

    return [
        'available' => true,
        'org_unit'  => [
            'id' => (int) $unit['id'], 'name' => (string) $unit['name'], 'type' => (string) $unit['type'],
            'external_code' => $unit['external_code'] ?? null,
        ],
        'councils'  => array_values($councils),
        'totals'    => $totals,
        'compliance_capped' => $capped,
    ];
}

/** @return array{accounts:int, invited:int, accepted:int, signed_in:int, compliant:?int} */
function te_onboarding_zero(): array
{
    return ['accounts' => 0, 'invited' => 0, 'accepted' => 0, 'signed_in' => 0, 'compliant' => null];
}
