<?php
/**
 * National onboarding funnel (GOTR G6).
 *
 *   GET ?org_unit_id=N   per council under N: accounts, invited, accepted,
 *                        signed in, compliant — see lib/onboarding_funnel.php
 *
 * Standing at the unit is the gate: org_admin OR org_viewer (a viewer reads
 * rollups, which is what this is), or super admin. Standing inherits down the
 * tree and never up, so a council admin asking about their division is refused.
 * Nothing here reads the token's roles directly — te_user_org_standing() is the
 * one place that answer is derived.
 */

require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/org_scope.php';
require_once __DIR__ . '/../lib/onboarding_funnel.php';

try {
    $pdo = Database::getInstance()->getConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

$auth = AuthMiddleware::requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$orgUnitId = (int) ($_GET['org_unit_id'] ?? 0);
if ($orgUnitId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'org_unit_id is required']);
    exit;
}

// Authorization before any read. A missing unit and a unit the caller cannot
// see answer the same 403 — the id space must not be enumerable from here.
$standing = te_user_org_standing($pdo, $auth, $orgUnitId);
if ($standing !== 'org_admin' && $standing !== 'org_viewer') {
    http_response_code(403);
    echo json_encode(['error' => 'You do not administer this organization']);
    exit;
}

try {
    $funnel = te_onboarding_funnel($pdo, $orgUnitId);
} catch (Throwable $e) {
    error_log('onboarding-funnel: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Could not build the funnel']);
    exit;
}

echo json_encode(['success' => true, 'standing' => $standing] + $funnel);
