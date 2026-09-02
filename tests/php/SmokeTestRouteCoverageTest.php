<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The smoke test must probe every GET route index.php declares.
 *
 * On 2026-09-02 `controllers/TeamController.php` was found with fifteen routes and
 * zero authentication — reachable from the internet since it was written. It
 * survived because no frontend code calls those routes, and `scripts/smoke-test.php`
 * walked the endpoints a screen calls. An open door is, by definition, the one
 * nothing calls: a suite built from the UI's call list can never find one.
 *
 * So the smoke test parses index.php's route table instead of listing paths, and
 * this test is what keeps that true. It fails when a GET route exists in index.php
 * that the smoke test would neither probe nor has explicitly excused as public —
 * whether because someone replaced the sweep with a hand-written list, or because a
 * route was written in a shape the parser cannot see.
 *
 * ⚠️ This test proves COVERAGE, not correctness. It says every route gets probed;
 * whether the probe passes is the smoke test's job, and that only runs against a
 * deployed environment. A green run here says nothing about whether a door is shut.
 */
class SmokeTestRouteCoverageTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private static string $smokeSrc;

    public static function setUpBeforeClass(): void
    {
        // Loads the parser, smoke_route_to_path() and the allowlist, and stops at the
        // guard before anything that needs a database or the network.
        if (!defined('TE_SMOKE_TEST_LIB_ONLY')) {
            define('TE_SMOKE_TEST_LIB_ONLY', true);
        }
        require_once self::ROOT . '/scripts/smoke-test.php';

        self::$smokeSrc = (string) file_get_contents(self::ROOT . '/scripts/smoke-test.php');

        // JWT::verify() logs and refuses without one; the value is irrelevant here,
        // the point is that a junk signature fails against a secret that exists.
        putenv('JWT_SECRET=smoke-route-coverage-test-secret');
        putenv('JWT_ALGORITHM=HS256');
    }

    /**
     * A deliberately DIFFERENT parse of index.php: line by line, inside the GET
     * block, rather than the smoke test's single regex over the whole block. Two
     * readings of the same table disagreeing is the signal — one of them has stopped
     * seeing a route.
     */
    private function routesInIndexPhp(): array
    {
        $lines = file(self::ROOT . '/index.php', FILE_IGNORE_NEW_LINES);
        $this->assertNotEmpty($lines, 'index.php is unreadable');

        $inGet = false;
        $routes = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (preg_match('/^[\'"](GET|POST|PUT|DELETE|PATCH)[\'"]\s*=>\s*\[/', $trimmed, $m)) {
                $inGet = $m[1] === 'GET';
                continue;
            }
            if ($inGet && preg_match('/^\],?$/', $trimmed)) {
                $inGet = false;
                continue;
            }
            if ($inGet && preg_match('/^[\'"](\S+?)[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/', $trimmed, $m)) {
                $routes[$m[1]] = $m[2];
            }
        }

        return $routes;
    }

    public function testTheSmokeTestParserSeesEveryRouteIndexPhpDeclares(): void
    {
        $mine = $this->routesInIndexPhp();
        $theirs = smoke_index_get_routes(self::ROOT . '/index.php');

        $this->assertNotEmpty($mine, 'no GET routes found in index.php — has the table moved?');
        $this->assertSame(
            array_keys($mine),
            array_keys($theirs),
            "smoke_index_get_routes() and this test read index.php's GET table differently. "
            . 'A route one of them cannot see is a route nobody probes.'
        );
    }

    /** '(\d+)' is the only pattern the table uses; a probe path must contain no regex. */
    public function testEveryRouteBecomesAConcreteProbePath(): void
    {
        foreach (array_keys(smoke_index_get_routes(self::ROOT . '/index.php')) as $route) {
            $path = smoke_route_to_path($route);
            $this->assertStringStartsWith('/', $path, "{$route} did not produce a path");
            $this->assertDoesNotMatchRegularExpression(
                '/[()\\\\\[\]+*?]/',
                $path,
                "{$route} still contains regex after substitution — smoke_route_to_path() needs to "
                . 'learn the new pattern, or the smoke test requests a URL that matches nothing.'
            );
        }
    }

    /**
     * The point of the whole file. Every GET route is probed by the sweep, or named
     * in SMOKE_PUBLIC_GET_ROUTES with a reason someone wrote down.
     */
    public function testEveryGetRouteIsProbedOrExplicitlyPublic(): void
    {
        $routes = smoke_index_get_routes(self::ROOT . '/index.php');
        $sweeps = $this->smokeTestSweepsTheRouteTable();

        $unprobed = [];
        foreach ($routes as $route => $handler) {
            if (array_key_exists($route, SMOKE_PUBLIC_GET_ROUTES)) {
                continue;
            }
            if ($sweeps) {
                continue;
            }
            // No sweep: fall back to demanding the literal path in the file.
            if (!str_contains(self::$smokeSrc, smoke_route_to_path($route))) {
                $unprobed[] = "{$route} ({$handler})";
            }
        }

        $this->assertSame([], $unprobed,
            "scripts/smoke-test.php probes neither of these, and they are not in "
            . "SMOKE_PUBLIC_GET_ROUTES:\n  " . implode("\n  ", $unprobed)
            . "\nindex.php performs no authentication, so an unprobed route is an "
            . 'unwatched door.');
    }

    /**
     * The sweep itself: a loop over smoke_index_get_routes() that skips the allowlist
     * and asserts 401 on everything else. If this shape goes away the test above stops
     * trusting it and demands literal paths instead.
     */
    private function smokeTestSweepsTheRouteTable(): bool
    {
        return (bool) preg_match(
            '/smoke_index_get_routes\s*\(.*?foreach\s*\(\s*\$indexRoutes.*?SMOKE_PUBLIC_GET_ROUTES.*?check\(.*?,\s*401\s*\)/s',
            self::$smokeSrc
        );
    }

    /**
     * An allowlist entry is a claim about a specific route. A stale one (route renamed
     * or removed) is worse than none: it reads as "this was considered" when nothing
     * matches it any more.
     */
    public function testEveryPublicAllowlistEntryNamesALiveRouteAndCarriesAReason(): void
    {
        $routes = smoke_index_get_routes(self::ROOT . '/index.php');

        // Empty is the expected state as of 2026-09-02 — every route in the table is a
        // staff read. The assertion is that whatever IS there is honest, not that
        // something is.
        $this->assertIsArray(SMOKE_PUBLIC_GET_ROUTES);

        foreach (SMOKE_PUBLIC_GET_ROUTES as $route => $reason) {
            $this->assertArrayHasKey($route, $routes,
                "SMOKE_PUBLIC_GET_ROUTES excuses '{$route}', which index.php no longer declares.");
            $this->assertGreaterThan(15, strlen(trim((string) $reason)),
                "'{$route}' is allowlisted as public with no real reason. The entry is a claim that "
                . 'the handler was read and answers anonymously on purpose — write down why.');
        }
    }

    /**
     * The endpoints reached directly rather than through index.php have no route table
     * to sweep, so they are listed by hand in the smoke test — which means they can be
     * dropped by hand too. These seven are the ones closed on 2026-09-02; each was open
     * in production, and none of them is reachable from the route table.
     */
    public function testTheEndpointsClosedOn20260902AreStillProbed(): void
    {
        foreach ([
            'registration/registrations-api.php',
            'registration/tryouts-api.php?path=registrations',
            'api/event-attendance.php?action=get',
            'api/rsvp-webhook.php?action=status',
            'api/invitations-gateway.php?action=list',
            'api/user-profile.php',
            'api/coach-notes.php?action=list',
        ] as $needle) {
            $this->assertStringContainsString($needle, self::$smokeSrc,
                "scripts/smoke-test.php no longer probes {$needle}, which was found unauthenticated "
                . 'in production on 2026-09-02.');
        }
    }

    /**
     * A missing Authorization header proves nothing about an endpoint that
     * authenticated with JWT::decode() — those refused an absent token correctly the
     * whole time and accepted any hand-built one. The forged probe is the assertion
     * that matters, and it must keep the shape ForgedTokenAuthGateTest uses.
     */
    public function testTheForgedTokenProbeIsBuiltLikeTheUnitTestsAndIsUsed(): void
    {
        $token = smoke_forged_token(999999);
        $parts = explode('.', $token);
        $this->assertCount(3, $parts, 'a JWT has three segments');

        $decode = fn(string $s) => json_decode(base64_decode(strtr($s, '-_', '+/')), true);
        $this->assertSame('HS256', $decode($parts[0])['alg'] ?? null);
        $this->assertSame('999999', $decode($parts[1])['user_id'] ?? null,
            'user_id must be a STRING — lib/JWT.php mints it as one, and the forgery has to look '
            . 'like the real thing.');
        $this->assertStringNotContainsString('.', $parts[2]);
        $this->assertFalse(\JWT::verify($token), 'the forged token must not verify');

        $this->assertMatchesRegularExpression(
            '/foreach\s*\(\s*\[.*?\]\s*as\s*\$p\s*\)\s*\{\s*check\([^)]*forged token/s',
            self::$smokeSrc,
            'the forged token is built but never sent — the probe is the point, not the builder.'
        );
    }

    /**
     * The public tryout session list is the one door that must stay OPEN.
     * PublicTryoutRegistration.tsx reads it for families with no account, so a 401
     * there is a broken sign-up page. Asserting 200 is how a security sweep that goes
     * one file too far gets caught.
     */
    public function testThePublicTryoutSessionListIsAssertedToStayReachable(): void
    {
        $this->assertMatchesRegularExpression(
            '/tryouts-api\.php\?path=sessions[^\n]*\n?[^\n]*,\s*200\s*\)/',
            self::$smokeSrc,
            'the smoke test must assert 200 (not a refusal) for path=sessions with no token.'
        );
    }

    /**
     * The smoke test is only worth running against production, and that is only safe
     * while it cannot change anything. The closed-door sweep added GET probes; it must
     * not have added a method that writes.
     */
    public function testTheClosedDoorSweepIsReadOnly(): void
    {
        $this->assertSame(0, preg_match('/CURLOPT_CUSTOMREQUEST/', self::$smokeSrc),
            'no PUT/DELETE/PATCH in a read-only smoke test');
        // CURLOPT_POST appears once, in postRead(), which reads club-parents.
        $this->assertSame(1, preg_match_all('/CURLOPT_POST\b/', self::$smokeSrc),
            'the only POST in this file is postRead(); a second one needs justifying.');
    }
}
