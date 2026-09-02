<?php
/**
 * The caller's full organizational context — every club role, with names.
 *
 * WHY THIS EXISTS
 * With TE_FEATURE_SLIM_TOKEN on, the JWT carries roles WITHOUT `scope_name` and
 * caps the array at JWT::TOKEN_ROLE_CAP entries, because a GOTR national admin
 * over 270 councils otherwise mints a ~40 KB token that exceeds the router's
 * header limit — they cannot log in at all. The names and the tail of the list
 * are display data, so they move out of the credential and into this endpoint,
 * which the frontend calls once per session.
 *
 * WHY NOT auth-gateway.php
 * That file is on the do-not-modify list, and this is a read of something
 * AuthMiddleware has already resolved. Adding an action there would also mean
 * another mint site to keep the impersonation claim alive at; this endpoint
 * mints nothing.
 *
 * WHAT IT IS NOT
 * Not a grant, and not authoritative for anything. `requireAuth()` re-derives
 * the roles from the database (through the G2 Redis cache) on this request like
 * every other, so what comes back is the same list the server would enforce.
 * The frontend uses it to LABEL and to populate the club picker; every actual
 * permission decision still happens server-side.
 *
 * Impersonation: the token IS the target (lib/impersonation.php), so this
 * returns the TARGET's roles during an impersonation, which is correct — that
 * is what the session is authorized as. `impersonation` is echoed back purely
 * so the banner has the same facts here as it does from verify-session.
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();

require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/impersonation.php';
require_once __DIR__ . '/../lib/role_cache.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'GET only']);
    exit;
}

$auth = AuthMiddleware::requireAuth();

$roles = [];
foreach ((array)$auth->getRoles() as $role) {
    $role = (array)$role;
    $roles[] = [
        'role' => $role['role'] ?? null,
        'scope_type' => $role['scope_type'] ?? 'club',
        'scope_id' => isset($role['scope_id']) ? (int)$role['scope_id'] : null,
        // Present here even when the token dropped it — that is the point.
        'scope_name' => $role['scope_name'] ?? null,
    ];
}

$active = $auth->getActiveContext();
if ($active !== null) {
    $active = (array)$active;
    $active['scope_id'] = isset($active['scope_id']) ? (int)$active['scope_id'] : null;
}

echo json_encode([
    'success' => true,
    // A STRING, matching the JWT claim. See the id-type rule in CLAUDE.md: the
    // client compares these with sameUser(), never with ===.
    'user_id' => (string)$auth->getUserId(),
    'system_role' => $auth->getSystemRole(),
    'roles' => $roles,
    'active_context' => $active,
    'impersonation' => te_read_impersonation($auth->getPayload()),
    // Diagnostics. `cached` says whether this answer came from Redis rather
    // than the database, so a context that looks stale is explainable instead
    // of mysterious. Nothing authorizes on it.
    'cached' => $auth->contextCameFromCache(),
    'role_cache_ttl' => TE_ROLE_CACHE_TTL,
]);
