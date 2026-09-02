<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Three list reads answered 200 with no token on 2026-09-02, found by the smoke test's
 * new route walk: GET /api/athletes (329 athletes across every club, with the primary
 * guardian's email and mobile), GET /api/coach/players/search (20 athletes with DOB per
 * query) and GET /api/seasons.
 *
 * Two of them are reachable by DIRECTORY SHADOWING, not the route table: .htaccess only
 * falls through to index.php when the path is neither a file nor a directory, and
 * api/athletes/ and api/seasons/ are directories, so Apache serves their index.php —
 * which calls the controller method directly. Gating the controller covers both routes.
 *
 * Parse-based: the scope helpers are shared and correct; the bug was that these three
 * methods never called them.
 */
class DirectoryShadowedListScopeTest extends TestCase
{
    private function method(string $rel, string $name): string
    {
        $src = file_get_contents(__DIR__ . '/../../' . $rel);
        $code = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $code .= $t[1];
            } else {
                $code .= $t;
            }
        }
        $start = strpos($code, "function $name(");
        $this->assertNotFalse($start, "$name not found in $rel");
        $next = preg_match('/\n    (public|private|protected) function \w+\(/', $code, $m, PREG_OFFSET_CAPTURE, $start + 10)
            ? $m[0][1] : strlen($code);
        return substr($code, $start, $next - $start);
    }

    public function testAthleteListAuthenticatesAndScopesBeforeQuerying(): void
    {
        $body = $this->method('controllers/AthleteController.php', 'getAthletes');
        $auth = strpos($body, '$this->resolveAuth()');
        $scope = strpos($body, 'AthleteScope::accessibleAthleteFilter(');
        $query = strpos($body, '$this->db->prepare(');
        $this->assertNotFalse($auth);
        $this->assertNotFalse($scope);
        $this->assertLessThan($query, $auth);
        $this->assertLessThan($query, $scope);
        $this->assertStringContainsString("{\$scope['sql']}", $body, 'the scope fragment must reach the SQL');
        $this->assertStringContainsString("execute(\$scope['params'])", $body);
    }

    public function testPlayerSearchAuthenticatesAndUsesTheStaffScope(): void
    {
        $body = $this->method('controllers/CoachController.php', 'searchPlayers');
        $this->assertStringContainsString('AuthMiddleware::requireAuth()', $body);
        $this->assertStringContainsString('AthleteScope::staffManageableAthleteIds(', $body,
            'a roster search is staff work; the read predicate would hand a parent their own child here');
        $this->assertStringContainsString("empty(\$allowed)", $body, 'an empty scope returns [] and never builds IN ()');

        $model = $this->method('models/Coach.php', 'searchAvailablePlayers');
        $this->assertStringContainsString('?array $allowedAthleteIds = null', $model);
        $this->assertStringContainsString('a.id IN (', $model);
    }

    public function testSeasonListRequiresStaffStanding(): void
    {
        $body = $this->method('controllers/SeasonController.php', 'getSeasons');
        $auth = strpos($body, 'AuthMiddleware::requireAuth()');
        $query = strpos($body, '$this->db->prepare(');
        $this->assertNotFalse($auth);
        $this->assertLessThan($query, $auth);
        $this->assertStringContainsString("hasRole('club_admin')", $body);
        $this->assertStringContainsString("hasRole('coach')", $body);
    }
}
