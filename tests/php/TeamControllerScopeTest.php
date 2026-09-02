<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;

/**
 * controllers/TeamController.php — 15 methods, zero authentication (until 2026-09-02).
 *
 * index.php performs no authentication, and this controller never called
 * requireAuth()/resolveAuth()/hasRole() anywhere. Verified with no token against
 * production on 2026-08-31:
 *
 *     GET /api/teams            -> 200, 20 teams across clubs 32, 50, 51
 *     GET /api/teams/74/roster  -> 200, real coaches with email addresses
 *
 * and POST /api/teams/{id}/volunteers accepted `background_check_status` from the
 * request body, defaulting it to 'pending' — so direct assignment recorded a status
 * and never blocked on it (roadmap R4). No frontend code calls these routes, which is
 * why it survived; the absence of a UI is not an access control.
 *
 * Parse-based: the predicates (te_is_club_staff / te_is_club_admin /
 * te_background_check_status) are shared with gateways that already use them
 * correctly. The bug was that none of them were called here.
 */
class TeamControllerScopeTest extends TestCase
{
    private const CONTROLLER = __DIR__ . '/../../controllers/TeamController.php';
    private const MODEL = __DIR__ . '/../../models/Team.php';

    private function code(string $file): string
    {
        $out = '';
        foreach (token_get_all(file_get_contents($file)) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out .= $t[1];
            } else {
                $out .= $t;
            }
        }
        return $out;
    }

    private function method(string $file, string $name): string
    {
        $code = $this->code($file);
        $start = strpos($code, "function $name(");
        $this->assertNotFalse($start, "$name not found in $file");
        $next = preg_match('/\n    (public|private|protected) function \w+\(/', $code, $m, PREG_OFFSET_CAPTURE, $start + 10)
            ? $m[0][1] : strlen($code);
        return substr($code, $start, $next - $start);
    }

    public function testEveryRequestAuthenticatesInTheConstructor(): void
    {
        $ctor = $this->method(self::CONTROLLER, '__construct');
        $this->assertStringContainsString('AuthMiddleware::requireAuth()', $ctor,
            'index.php performs no authentication; the constructor must');
    }

    /** @dataProvider readers */
    public function testReadersGateOnTeamStaffStanding(string $method): void
    {
        $this->assertStringContainsString('$this->requireTeamStaff(', $this->method(self::CONTROLLER, $method));
    }

    public static function readers(): array
    {
        return [['show'], ['roster'], ['auditLog']];
    }

    /** @dataProvider writers */
    public function testWritersGateOnTeamAdminStanding(string $method): void
    {
        $body = $this->method(self::CONTROLLER, $method);
        $this->assertStringContainsString('$this->requireTeamAdmin(', $body);
        $this->assertStringNotContainsString('requireTeamStaff(', $body,
            "$method is a write; a coach must not pass it");
    }

    public static function writers(): array
    {
        return [['update'], ['delete'], ['assignCoach'], ['removeCoach'], ['assignVolunteer'], ['bulkAction']];
    }

    public function testCreateGatesOnTheClubInTheBodyAndWritesIt(): void
    {
        $body = $this->method(self::CONTROLLER, 'create');
        $this->assertStringContainsString("\$this->requireClubAdmin((int)\$data['club_id'])", $body);
        $model = $this->method(self::MODEL, 'createTeam');
        $this->assertStringContainsString('club_id', $model,
            'createTeam left club_id NULL, so the team was invisible to every club-scoped screen');
    }

    public function testIndexIsScopedToAccessibleClubs(): void
    {
        $body = $this->method(self::CONTROLLER, 'index');
        $this->assertStringContainsString('getAccessibleClubIds()', $body);
        $this->assertStringContainsString("'club_ids' =>", $body);

        $model = $this->method(self::MODEL, 'getTeams');
        $this->assertStringContainsString('AND 1=0', $model,
            'an EMPTY club list must yield no rows — IN () is a syntax error and "no filter" is the opposite answer');
    }

    public function testBulkActionAuthorizesEveryTeamBeforeTouchingAny(): void
    {
        $body = $this->method(self::CONTROLLER, 'bulkAction');
        $gate = strpos($body, 'requireTeamAdmin(');
        $act = strpos($body, 'performBulkAction(');
        $this->assertLessThan($act, $gate);
    }

    /** @dataProvider lookups */
    public function testLookupsRequireStaffStanding(string $method): void
    {
        $this->assertStringContainsString('$this->requireAnyStaff()', $this->method(self::CONTROLLER, $method));
    }

    public static function lookups(): array
    {
        return [['availableCoaches'], ['seasons'], ['fields']];
    }

    public function testVolunteerAssignmentReadsTheBackgroundCheckAndNeverTheBody(): void
    {
        $ctrl = $this->method(self::CONTROLLER, 'assignVolunteer');
        $this->assertStringContainsString('te_background_check_status(', $ctrl);
        $this->assertStringContainsString("!== 'cleared'", $ctrl);
        $this->assertStringContainsString('http_response_code(403)', $ctrl);
        $gate = strpos($ctrl, "!== 'cleared'");
        $write = strpos($ctrl, '->assignVolunteer(');
        $this->assertLessThan($write, $gate, 'the gate must run before the INSERT');

        $model = $this->method(self::MODEL, 'assignVolunteer');
        $this->assertStringNotContainsString("\$data['background_check_status']", $model,
            'the status must be looked up, never accepted from the request');
        $this->assertStringNotContainsString('$_SESSION', $model,
            'assigned_by came from $_SESSION[user_id] ?? 1 on a stateless API, i.e. always 1');
    }

    public function testTheGatewayAndTheControllerShareOnePredicate(): void
    {
        $gateway = $this->code(__DIR__ . '/../../api/volunteer-gateway.php');
        $this->assertStringContainsString('te_background_check_status(', $gateway);
        $this->assertStringNotContainsString("FROM team_volunteers\n        WHERE user_id = ? AND background_check_status = 'cleared'", $gateway,
            'the query body belongs in lib/background_check.php, not re-inlined in the gateway');
    }

    public function testNoAuthGateReadsTheClubPredicateThatAdmitsParents(): void
    {
        $this->assertStringNotContainsString('canAccessClub(', $this->code(self::CONTROLLER));
    }
}
