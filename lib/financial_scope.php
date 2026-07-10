<?php
/**
 * financial_scope.php — server-side club scoping for financial/reporting endpoints.
 *
 * Tenancy is by club_profile.id. Leagues were merged into clubs, so a `league_id`
 * value IS a club_profile.id. Every other identifier (program, team, athlete,
 * payment, invoice) resolves to its owning club via club_id.
 *
 * Usage:
 *   require_once __DIR__ . '/../lib/financial_scope.php';
 *   $auth = AuthMiddleware::requireAuth();
 *   te_assert_financial_scope($auth, $pdo, ['league' => $league_id, 'program' => $program_id]);
 *
 * Super admins pass everything. Any provided id that resolves to a club the caller
 * cannot access → 403. A non-super-admin who provides NO scoping id is denied (an
 * unscoped financial report would leak across clubs).
 */

require_once __DIR__ . '/AuthMiddleware.php';

/** Resolve a single (type, id) to its owning club_profile id, or null if unknown. */
function te_club_for(PDO $pdo, string $type, $id): ?int {
    if ($id === null || $id === '' || $id === false) return null;

    switch ($type) {
        case 'league': // a league_id is itself a club_profile.id
        case 'club':
            return (int)$id;
        case 'program':
            $stmt = $pdo->prepare('SELECT club_id FROM programs WHERE id = ?');
            break;
        case 'team':
            $stmt = $pdo->prepare('SELECT club_id FROM teams WHERE id = ?');
            break;
        case 'athlete':
            $stmt = $pdo->prepare('SELECT club_id FROM athletes WHERE id = ?');
            break;
        case 'payment':
            $stmt = $pdo->prepare('SELECT a.club_id FROM athlete_payments ap JOIN athletes a ON a.id = ap.athlete_id WHERE ap.id = ?');
            break;
        case 'invoice':
            $stmt = $pdo->prepare('SELECT a.club_id FROM invoices i JOIN athletes a ON a.id = i.athlete_id WHERE i.id = ?');
            break;
        default:
            return null;
    }
    $stmt->execute([$id]);
    $club = $stmt->fetchColumn();
    return $club ? (int)$club : null;
}

/**
 * Assert the caller can access every scoping id in $ids (map of type => id).
 * Exits 403 on any violation. No-op for super admins.
 */
function te_assert_financial_scope(AuthMiddleware $auth, PDO $pdo, array $ids): void {
    if ($auth->isSuperAdmin()) return;

    $checked = 0;
    foreach ($ids as $type => $id) {
        if ($id === null || $id === '' || $id === false) continue;
        $checked++;
        $clubId = te_club_for($pdo, $type, $id);
        if ($clubId === null || !$auth->canAccessClub($clubId)) {
            http_response_code(403);
            echo json_encode(['error' => 'Not authorized for the requested scope']);
            exit;
        }
    }

    if ($checked === 0) {
        http_response_code(403);
        echo json_encode(['error' => 'A league_id or club_id within your access is required']);
        exit;
    }
}

/**
 * Stronger than te_assert_financial_scope: requires an ADMIN-level role
 * (club_admin) for every scoping club — not merely access. Coaches, parents,
 * and volunteers have club access but must NOT reach admin financial tools
 * (revenue, transaction reports, outstanding balances, payment management).
 * Super admins pass. Exits 403 otherwise; a non-super-admin with no scoping id
 * is denied (an unscoped financial report can't be authorized).
 */
function te_assert_financial_admin(AuthMiddleware $auth, PDO $pdo, array $ids): void {
    if ($auth->isSuperAdmin()) return;

    $checked = 0;
    foreach ($ids as $type => $id) {
        if ($id === null || $id === '' || $id === false) continue;
        $checked++;
        $clubId = te_club_for($pdo, $type, $id);
        if ($clubId === null || !$auth->hasRole('club_admin', $clubId, 'club')) {
            http_response_code(403);
            echo json_encode(['error' => 'Admin access required for financial data']);
            exit;
        }
    }

    if ($checked === 0) {
        http_response_code(403);
        echo json_encode(['error' => 'A league_id or club_id within your admin scope is required']);
        exit;
    }
}
