<?php
/**
 * Cached role context — `te:ctx:v1:<user_id>` in Redis, 300s.
 *
 * `AuthMiddleware::requireAuth()` re-derives every role from the database on
 * EVERY request (SEC-11), which is what makes a revocation take effect on the
 * next request instead of at token expiry. That property is worth keeping, but
 * the cost grows with the platform: two queries per request, one of which used
 * to scan every team that exists. A national GOTR admin over 270 councils pays
 * it on every page.
 *
 * So the derivation is cached per user for five minutes and invalidated by the
 * writes that can change it. The cache holds ONLY the scope-independent half —
 * `system_role` and the `roles` array. The active context is recomputed per
 * request from the token's requested scope, because two requests from the same
 * user can legitimately ask for different clubs and caching one of those
 * answers would hand it to the other.
 *
 * Rules that are load-bearing:
 *
 *  - **Redis unavailable is never a failed request.** Every function here
 *    swallows its own errors and answers "no cache"; the caller falls through
 *    to the database. A cache that can 500 a login is worse than no cache.
 *  - **Every write to `user_club_access` calls te_role_cache_invalidate().**
 *    Five minutes of a stale ROLE is five minutes of stale ACCESS, and the
 *    revocation paths (`super-admin-gateway`, `club-users-gateway`) are exactly
 *    the ones where that matters. RoleCacheTest scans for a call at each write
 *    site — the bug class this repo keeps producing is "fixed one, missed
 *    three".
 *  - **The key is versioned (`v1`).** The cached shape is a serialised context;
 *    changing that shape means bumping the version, not reasoning about what is
 *    already in Redis when the deploy lands.
 *  - **Switch: `TE_FEATURE_ROLE_CACHE`.** Unset means ON per feature_flags
 *    semantics; it ships set to `off` and Maggie flips it.
 */

require_once __DIR__ . '/feature_flags.php';
require_once __DIR__ . '/../config/env.php';

/** Key prefix. Bump the version when the cached array's shape changes. */
const TE_ROLE_CACHE_PREFIX = 'te:ctx:v1:';

/** Seconds a derived context may be served without touching the database. */
const TE_ROLE_CACHE_TTL = 300;

function te_role_cache_key($userId): string
{
    return TE_ROLE_CACHE_PREFIX . (string)$userId;
}

function te_role_cache_enabled(): bool
{
    return te_feature_enabled('ROLE_CACHE');
}

/**
 * Test seam: hand in any object exposing get($k) / setex($k,$ttl,$v) / del($k).
 * Passing null clears the override and re-enables discovery.
 */
function te_role_cache_set_client($client): void
{
    $GLOBALS['__te_role_cache_client'] = $client;
    $GLOBALS['__te_role_cache_resolved'] = ($client !== null);
}

/** Test seam: forget the memoised client (and any negative memo). */
function te_role_cache_reset(): void
{
    unset($GLOBALS['__te_role_cache_client'], $GLOBALS['__te_role_cache_resolved']);
}

/**
 * The Redis client, or null when there isn't one.
 *
 * Memoised INCLUDING the negative answer: with no REDIS_URL (every unit test,
 * and any misconfigured dyno) this must not re-attempt a connection on each
 * call. Reuses RedisQueue's connection so a request holds one Redis socket, not
 * two.
 */
function te_role_cache_client()
{
    if (!empty($GLOBALS['__te_role_cache_resolved'])) {
        return $GLOBALS['__te_role_cache_client'];
    }
    $GLOBALS['__te_role_cache_resolved'] = true;
    $GLOBALS['__te_role_cache_client'] = null;

    if (!Env::get('REDIS_URL')) {
        return null;
    }
    try {
        require_once __DIR__ . '/RedisQueue.php';
        $GLOBALS['__te_role_cache_client'] = RedisQueue::getInstance()->getClient();
    } catch (Throwable $e) {
        error_log('role cache: no Redis client, falling through to the database: ' . $e->getMessage());
        $GLOBALS['__te_role_cache_client'] = null;
    }
    return $GLOBALS['__te_role_cache_client'];
}

/**
 * The cached role set for a user, or null on a miss / disabled / no Redis.
 *
 * @return array{system_role:string,roles:array}|null
 */
function te_role_cache_get($userId): ?array
{
    if (!te_role_cache_enabled()) {
        return null;
    }
    $client = te_role_cache_client();
    if (!$client) {
        return null;
    }
    try {
        $raw = $client->get(te_role_cache_key($userId));
    } catch (Throwable $e) {
        error_log('role cache read failed, using the database: ' . $e->getMessage());
        return null;
    }
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    // A shape we do not recognise is a miss, not a half-answer: serving
    // ['roles' => null] would empty someone's access rather than refresh it.
    if (!is_array($decoded) || !isset($decoded['roles']) || !is_array($decoded['roles'])) {
        return null;
    }
    return [
        'system_role' => (string)($decoded['system_role'] ?? 'user'),
        'roles' => $decoded['roles'],
    ];
}

/** Store a freshly derived role set. Failures are silent by design. */
function te_role_cache_put($userId, array $roleSet): void
{
    if (!te_role_cache_enabled()) {
        return;
    }
    $client = te_role_cache_client();
    if (!$client) {
        return;
    }
    try {
        $client->setex(
            te_role_cache_key($userId),
            TE_ROLE_CACHE_TTL,
            json_encode([
                'system_role' => $roleSet['system_role'] ?? 'user',
                'roles' => $roleSet['roles'] ?? [],
            ])
        );
    } catch (Throwable $e) {
        error_log('role cache write failed: ' . $e->getMessage());
    }
}

/**
 * Drop a user's cached context. Call this from EVERY write to
 * `user_club_access` — grant, role change and revoke alike.
 *
 * Deliberately runs even when the switch is off, so flipping the switch off and
 * back on cannot resurrect an entry written before a revocation.
 */
function te_role_cache_invalidate($userId): void
{
    if ($userId === null || $userId === '') {
        return;
    }
    $client = te_role_cache_client();
    if (!$client) {
        return;
    }
    try {
        $client->del(te_role_cache_key($userId));
    } catch (Throwable $e) {
        error_log('role cache invalidation failed for user ' . $userId . ': ' . $e->getMessage());
    }
}

/**
 * Read-through: the cached role set, or $load()'s answer stored for next time.
 *
 * This is the whole cache policy in one place, and the request path calls
 * nothing else — which is what makes "a hit does not touch the database"
 * testable: pass a loader that fails the test if it runs.
 *
 * $load is a CLOSURE, not a value, so a hit never opens a database connection
 * at all. Anything it throws propagates: a real database failure must still
 * reach AuthMiddleware's catch, which falls back to the token's own roles.
 *
 * @param callable():array $load
 * @param bool|null $cameFromCache Out param, diagnostics only.
 */
function te_role_cache_resolve($userId, callable $load, &$cameFromCache = null): array
{
    $cached = te_role_cache_get($userId);
    if ($cached !== null) {
        $cameFromCache = true;
        return $cached;
    }
    $cameFromCache = false;
    $roleSet = $load();
    te_role_cache_put($userId, $roleSet);
    return $roleSet;
}
