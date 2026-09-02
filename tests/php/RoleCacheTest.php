<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/role_cache.php';

/**
 * The cached role context (G2, `te:ctx:v1:<user_id>`, 300s).
 *
 * Three properties, in order of how much they cost when they break:
 *
 *  1. **Invalidation at every write to `user_club_access`.** A stale role is
 *     stale ACCESS, and SEC-11 exists precisely so a revocation lands on the
 *     next request. The scan at the bottom is the real guard — the bug shape
 *     here is not a wrong function, it is a write site that forgot to call it.
 *  2. **A hit does not touch the database.** Asserted with a loader that fails
 *     the test if it runs, which is the only way to prove it.
 *  3. **Redis being down is not an error.** Every entry point falls through to
 *     the database and the request completes.
 */
class RoleCacheTest extends TestCase
{
    protected function setUp(): void
    {
        te_role_cache_reset();
        putenv('TE_FEATURE_ROLE_CACHE');
        unset($_ENV['TE_FEATURE_ROLE_CACHE']);
        te_role_cache_set_client(new FakeRedisForRoleCache());
    }

    protected function tearDown(): void
    {
        te_role_cache_reset();
        putenv('TE_FEATURE_ROLE_CACHE');
        unset($_ENV['TE_FEATURE_ROLE_CACHE']);
    }

    /** @return FakeRedisForRoleCache */
    private function redis()
    {
        return te_role_cache_client();
    }

    private function roleSet(string $role = 'club_admin', int $clubId = 32): array
    {
        return [
            'system_role' => 'user',
            'roles' => [[
                'role' => $role, 'scope_type' => 'club',
                'scope_id' => $clubId, 'scope_name' => 'Central Kansas United',
            ]],
        ];
    }

    private function failingLoader(): callable
    {
        return function () {
            $this->fail('the database was queried on a cache HIT');
        };
    }

    // ---------------------------------------------------------------- shape

    public function testTheKeyIsVersionedAndTtlIsFiveMinutes(): void
    {
        $this->assertSame('te:ctx:v1:75', te_role_cache_key(75));
        // A string id (the JWT claim's type) and an int id are the SAME person
        // and must not get two entries — one of which invalidation would miss.
        $this->assertSame(te_role_cache_key(75), te_role_cache_key('75'));
        $this->assertSame(300, TE_ROLE_CACHE_TTL);
    }

    // ------------------------------------------------------- hit / miss

    public function testAMissLoadsFromTheDatabaseAndStoresTheAnswer(): void
    {
        $calls = 0;
        $set = te_role_cache_resolve(75, function () use (&$calls) {
            $calls++;
            return $this->roleSet();
        }, $cached);

        $this->assertSame(1, $calls);
        $this->assertFalse($cached);
        $this->assertSame('club_admin', $set['roles'][0]['role']);
        $this->assertArrayHasKey('te:ctx:v1:75', $this->redis()->store);
        $this->assertSame(300, $this->redis()->ttls['te:ctx:v1:75']);
    }

    public function testAHitSkipsTheDatabaseEntirely(): void
    {
        te_role_cache_put(75, $this->roleSet());

        $set = te_role_cache_resolve(75, $this->failingLoader(), $cached);

        $this->assertTrue($cached);
        $this->assertSame('club_admin', $set['roles'][0]['role']);
        // Names survive the round trip: api/my-context.php serves them from here.
        $this->assertSame('Central Kansas United', $set['roles'][0]['scope_name']);
    }

    public function testOneUsersEntryIsNotAnotherUsers(): void
    {
        te_role_cache_put(75, $this->roleSet('club_admin', 32));

        $set = te_role_cache_resolve(76, fn() => $this->roleSet('parent', 51), $cached);

        $this->assertFalse($cached);
        $this->assertSame('parent', $set['roles'][0]['role']);
    }

    /**
     * The cache holds the SCOPE-INDEPENDENT half only. Two requests from the
     * same user can ask for different active clubs, and caching one of those
     * answers would hand it to the other.
     */
    public function testTheCachedShapeCarriesNoActiveContext(): void
    {
        te_role_cache_put(75, [
            'system_role' => 'user',
            'roles' => $this->roleSet()['roles'],
            'active_context' => ['role' => 'club_admin', 'scope_id' => 32],
            'org_id' => 32,
        ]);

        $stored = json_decode($this->redis()->store['te:ctx:v1:75'], true);

        $this->assertSame(['system_role', 'roles'], array_keys($stored));
    }

    // --------------------------------------------------------- invalidation

    public function testInvalidationOnAGrantMakesTheNextReadHitTheDatabase(): void
    {
        te_role_cache_put(75, $this->roleSet('parent', 51));

        te_role_cache_invalidate(75);

        $set = te_role_cache_resolve(75, fn() => $this->roleSet('coach', 51), $cached);
        $this->assertFalse($cached);
        $this->assertSame('coach', $set['roles'][0]['role']);
    }

    public function testInvalidationOnARevocationDropsTheRoleImmediately(): void
    {
        te_role_cache_put(75, $this->roleSet('club_admin', 32));

        te_role_cache_invalidate(75);

        $set = te_role_cache_resolve(75, fn() => ['system_role' => 'user', 'roles' => []], $cached);
        $this->assertFalse($cached);
        $this->assertSame([], $set['roles']);
    }

    /**
     * Invalidation runs even with the switch OFF. Otherwise flipping the switch
     * off and back on would resurrect an entry written before a revocation.
     */
    public function testInvalidationRunsEvenWhenTheSwitchIsOff(): void
    {
        te_role_cache_put(75, $this->roleSet());
        putenv('TE_FEATURE_ROLE_CACHE=off');

        te_role_cache_invalidate(75);

        $this->assertArrayNotHasKey('te:ctx:v1:75', $this->redis()->store);
    }

    public function testInvalidatingNobodyIsANoOp(): void
    {
        te_role_cache_put(75, $this->roleSet());

        te_role_cache_invalidate(null);
        te_role_cache_invalidate('');

        $this->assertArrayHasKey('te:ctx:v1:75', $this->redis()->store);
    }

    // ------------------------------------------------------------- failure

    public function testRedisBeingDownFallsThroughToTheDatabase(): void
    {
        te_role_cache_set_client(new BrokenRedisForRoleCache());

        $set = te_role_cache_resolve(75, fn() => $this->roleSet(), $cached);

        $this->assertFalse($cached);
        $this->assertSame('club_admin', $set['roles'][0]['role']);
    }

    public function testRedisBeingDownDoesNotBreakInvalidationEither(): void
    {
        te_role_cache_set_client(new BrokenRedisForRoleCache());

        te_role_cache_invalidate(75);

        $this->addToAssertionCount(1); // reaching here without a throw is the assertion
    }

    public function testNoRedisAtAllIsAPermanentMiss(): void
    {
        te_role_cache_set_client(null);
        putenv('REDIS_URL');
        unset($_ENV['REDIS_URL']);

        $this->assertNull(te_role_cache_get(75));
        te_role_cache_put(75, $this->roleSet());
        $this->assertNull(te_role_cache_get(75));
    }

    /**
     * A stored value we cannot read back is a MISS, never a half-answer.
     * Serving ['roles' => null] would empty someone's access rather than
     * refresh it.
     */
    public function testAnUnreadableEntryIsAMissNotAnEmptyRoleSet(): void
    {
        foreach (['not json', '{}', '{"roles":null}', '"a string"', ''] as $junk) {
            $this->redis()->store['te:ctx:v1:75'] = $junk;
            $this->assertNull(te_role_cache_get(75), "junk value [$junk] must read as a miss");
        }
    }

    // --------------------------------------------------------- the switch

    public function testTheSwitchOffMeansEveryReadIsAMiss(): void
    {
        te_role_cache_put(75, $this->roleSet());
        putenv('TE_FEATURE_ROLE_CACHE=off');

        $this->assertNull(te_role_cache_get(75));
        te_role_cache_resolve(75, fn() => $this->roleSet('coach'), $cached);
        $this->assertFalse($cached);
    }

    /** Unset is ON, per lib/feature_flags.php semantics. */
    public function testUnsetMeansOn(): void
    {
        $this->assertTrue(te_role_cache_enabled());
    }

    // ------------------------------------------------------------ the scan

    /**
     * EVERY write to `user_club_access` must invalidate. This is a scan and not
     * a unit test because the failure mode is an omission at a call site: on
     * 2026-08-17 the same shape ("fixed one, missed three") cost four files.
     *
     * If you add a write site, add the te_role_cache_invalidate() call — do not
     * add the file to an exclusion list.
     */
    public function testEveryUserClubAccessWriteSiteInvalidatesTheCache(): void
    {
        $root = realpath(__DIR__ . '/../../');
        $skipDirs = ['/vendor/', '/tests/', '/node_modules/', '/frontend/', '/database/', '/chat-server/', '/.claude/', '/src/'];

        $found = 0;
        $offenders = [];

        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = str_replace($root, '', $file->getPathname());
            foreach ($skipDirs as $skip) {
                if (strpos($path, $skip) === 0 || strpos($path, $skip) !== false) {
                    continue 2;
                }
            }
            $src = file_get_contents($file->getPathname());
            // SQL literals only. Matching English ("update user club access…")
            // is what made an earlier scan in this repo cry wolf and get deleted.
            if (!preg_match_all('/\b(INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+user_club_access\b/i', $src, $m)) {
                continue;
            }
            $writes = count($m[0]);
            $found += $writes;
            $invalidations = substr_count($src, 'te_role_cache_invalidate(');
            if ($invalidations < $writes) {
                $offenders[] = sprintf('%s: %d write(s), %d invalidation(s)', $path, $writes, $invalidations);
            }
        }

        $this->assertGreaterThanOrEqual(11, $found, 'the scan found fewer write sites than exist — the pattern stopped matching');
        $this->assertSame([], $offenders, "these write user_club_access without dropping the cached role context:\n" . implode("\n", $offenders));
    }

    /** The request path must go through the read-through helper, not re-derive it. */
    public function testRefreshRolesFromDbUsesTheCache(): void
    {
        $src = file_get_contents(__DIR__ . '/../../lib/AuthMiddleware.php');
        $start = strpos($src, 'private function refreshRolesFromDb(');
        $this->assertNotFalse($start);
        $body = substr($src, $start, 2500);

        $this->assertStringContainsString('te_role_cache_resolve', $body);
        // A closure, so a HIT opens no database connection.
        $this->assertMatchesRegularExpression(
            '/te_role_cache_resolve\(\s*\$userId,\s*(\/\/[^\n]*\n\s*)*function \(\) use \(\$userId\)/',
            $body,
            'the loader must be a closure — passing an already-connected handle means a cache hit still pays for the connection'
        );
    }
}

/** Minimal Predis stand-in: the three commands lib/role_cache.php uses. */
class FakeRedisForRoleCache
{
    public array $store = [];
    public array $ttls = [];

    public function get($key)
    {
        return $this->store[$key] ?? null;
    }

    public function setex($key, $ttl, $value)
    {
        $this->store[$key] = $value;
        $this->ttls[$key] = $ttl;
    }

    public function del($key)
    {
        unset($this->store[$key], $this->ttls[$key]);
    }
}

/** Every command throws, the way an unreachable Redis behaves under Predis. */
class BrokenRedisForRoleCache
{
    public function get($key)
    {
        throw new RuntimeException('Connection refused [tcp://redis:6379]');
    }

    public function setex($key, $ttl, $value)
    {
        throw new RuntimeException('Connection refused [tcp://redis:6379]');
    }

    public function del($key)
    {
        throw new RuntimeException('Connection refused [tcp://redis:6379]');
    }
}
