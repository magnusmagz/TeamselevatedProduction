<?php
/**
 * Where a requirement came from, in words a council admin can act on
 * (GOTR G4, docs/gotr-hierarchy-plan-2026-09.md §4).
 *
 * te_compliance_requirements_for_club() returns the INHERITED set: the club's own
 * rows plus every row on every ancestor org unit. On screen those are not
 * interchangeable — a council admin may edit their own and may not edit
 * national's, and the difference between "we require this" and "our national
 * office requires this" is the first thing they need to know before adding a
 * fourth copy of a rule that already exists two tiers up.
 *
 * ⚠️ `editable` here is a LABEL, not a permission. The gate is
 * te_compliance_can_admin_club() and te_user_org_standing() inside
 * api/compliance-gateway.php, which check standing over the row's actual owner.
 * A division admin looking at one of their councils gets `editable => false` on
 * a national row and `true` on the council's own — correct in both cases — but
 * what stops them saving a national row is the gateway, not this flag. Never
 * gate a write on it. Same rule as everywhere else in this codebase: the absence
 * of a UI is not an access control.
 */

require_once __DIR__ . '/compliance.php';
require_once __DIR__ . '/org_scope.php';

/**
 * Decorate requirement rows with `origin`.
 *
 *   origin => ['scope' => 'national'|'division'|'council'|'club',
 *              'name'  => 'Girls on the Run',
 *              'label' => 'National',
 *              'editable' => bool]
 *
 * One query for every org unit named across the whole set, not one per row: a
 * council inheriting five tiers' worth of rules would otherwise issue a query
 * per requirement per page load.
 *
 * A row whose owner cannot be resolved — the org tree is absent (migration 090
 * not applied), or the unit has been deleted underneath us — is labelled
 * "Inherited" rather than silently attributed to this club. Attributing an
 * unknown owner to the viewer is how an admin ends up believing they can delete
 * somebody else's rule.
 *
 * @param array $requirements rows from te_compliance_requirements_for_club()
 * @param int   $clubId       the club being viewed; its own rows are `club`
 */
function te_compliance_decorate_origins(PDO $pdo, array $requirements, int $clubId): array
{
    if (!$requirements) {
        return [];
    }

    $unitIds = [];
    foreach ($requirements as $req) {
        $id = (int) ($req['org_unit_id'] ?? 0);
        if ($id > 0) {
            $unitIds[$id] = true;
        }
    }

    $units = te_compliance_org_units($pdo, array_keys($unitIds));

    foreach ($requirements as &$req) {
        $req['origin'] = te_compliance_origin_for($req, $clubId, $units);
    }
    return $requirements;
}

/**
 * The origin of one row.
 *
 * @param array<int, array{type:string,name:string}> $units id => unit
 */
function te_compliance_origin_for(array $requirement, int $clubId, array $units): array
{
    $ownerClub = (int) ($requirement['club_profile_id'] ?? 0);
    $ownerUnit = (int) ($requirement['org_unit_id'] ?? 0);

    if ($ownerClub > 0) {
        return [
            'scope'    => 'club',
            'name'     => null,
            // "This club" rather than the club's name: the page is already
            // inside that club and repeating its name in every row is noise.
            'label'    => 'This club',
            'editable' => $ownerClub === $clubId,
        ];
    }

    $unit = $units[$ownerUnit] ?? null;
    if ($unit === null) {
        return ['scope' => 'inherited', 'name' => null, 'label' => 'Inherited', 'editable' => false];
    }

    $labels = ['national' => 'National', 'division' => 'Division', 'council' => 'Council'];
    $type = strtolower(trim((string) $unit['type']));

    return [
        'scope'    => $type,
        'name'     => (string) $unit['name'],
        'label'    => ($labels[$type] ?? ucfirst($type)) . ' — ' . $unit['name'],
        // Never editable from a club page. An org row is edited from the tier
        // that owns it, by somebody with org_admin standing there.
        'editable' => false,
    ];
}

/**
 * Type and name for a set of org unit ids.
 *
 * @param int[] $ids
 * @return array<int, array{type:string,name:string}>
 */
function te_compliance_org_units(PDO $pdo, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
    if (!$ids || !te_org_tables_present($pdo)) {
        return [];
    }

    try {
        $marks = implode(', ', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT id, type, name FROM org_units WHERE id IN ($marks)");
        $stmt->execute($ids);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('te_compliance_org_units: ' . $e->getMessage());
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $out[(int) $row['id']] = ['type' => (string) $row['type'], 'name' => (string) $row['name']];
    }
    return $out;
}
