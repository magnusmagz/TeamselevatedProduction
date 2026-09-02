<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use AuthMiddleware;

/**
 * Attendance and RSVP rows are staff data — lib/event_standing.php (2026-09-02).
 *
 * CKU reported parents seeing other families' RSVPs (roadmap R81). The gateway the
 * sheet blamed was already scoped; the real surfaces were:
 *   - api/event-attendance.php: requireAuth() and then nothing — $auth was never read.
 *     "Take Attendance" on the staff calendar (which a parent can reach: ProtectedRoute
 *     is authentication only) listed every athlete on the event's teams with their
 *     status, and `save` let the same caller rewrite it.
 *   - api/rsvp-webhook.php?action=status: no auth at all. Name, EMAIL and RSVP status
 *     of every attendee for any event id.
 *
 * Functional against SQLite for the predicate, parse-based for the call sites — the
 * predicate is shared, so the bug can only ever be which site forgot to call it.
 */
class EventStandingTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        require_once __DIR__ . '/../../lib/event_standing.php';
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("CREATE TABLE calendar_events (id INTEGER PRIMARY KEY, club_id INTEGER)");
        $this->pdo->exec("CREATE TABLE calendar_event_teams (event_id INTEGER, team_id INTEGER)");
        $this->pdo->exec("CREATE TABLE teams (id INTEGER PRIMARY KEY, club_id INTEGER, primary_coach_id INTEGER, deleted_at TEXT)");
        $this->pdo->exec("CREATE TABLE team_members (id INTEGER PRIMARY KEY, team_id INTEGER, user_id INTEGER, role TEXT, status TEXT)");
        // Event 100 in club 51, for teams 7 and 8. Team 9 is elsewhere.
        $this->pdo->exec("INSERT INTO calendar_events VALUES (100, 51), (200, NULL)");
        $this->pdo->exec("INSERT INTO calendar_event_teams VALUES (100, 7), (100, 8)");
        $this->pdo->exec("INSERT INTO teams VALUES (7, 51, 300, NULL), (8, 51, NULL, NULL), (9, 51, 301, NULL)");
        $this->pdo->exec("INSERT INTO team_members VALUES (1, 8, 302, 'assistant_coach', 'active')");
    }

    private function auth(int $userId, array $roles, bool $super = false): AuthMiddleware
    {
        return AuthMiddleware::fromContext([
            'user_id' => $userId,
            'system_role' => $super ? 'super_admin' : 'user',
            'roles' => array_map(fn($r) => ['role' => $r[0], 'scope_id' => $r[1], 'scope_type' => 'club'], $roles),
        ]);
    }

    public function testMissingEventIsNullNotFalse(): void
    {
        $this->assertNull(te_event_staff_standing($this->pdo, $this->auth(1, []), 999));
    }

    public function testSuperAdmin(): void
    {
        $this->assertTrue(te_event_staff_standing($this->pdo, $this->auth(1, [], true), 100));
    }

    public function testClubAdminOfTheEventsClub(): void
    {
        $this->assertTrue(te_event_staff_standing($this->pdo, $this->auth(1, [['club_admin', 51]]), 100));
    }

    public function testClubAdminOfAnotherClubHasNoStanding(): void
    {
        $this->assertFalse(te_event_staff_standing($this->pdo, $this->auth(1, [['club_admin', 32]]), 100));
    }

    public function testPrimaryCoachOfATeamOnTheEvent(): void
    {
        $this->assertTrue(te_event_staff_standing($this->pdo, $this->auth(300, [['coach', 51]]), 100));
    }

    public function testAssistantCoachOfATeamOnTheEvent(): void
    {
        $this->assertTrue(te_event_staff_standing($this->pdo, $this->auth(302, [['coach', 51]]), 100));
    }

    public function testCoachOfAnUnrelatedTeamInTheSameClubHasNoStanding(): void
    {
        // Team 9 is in club 51 but not on the event. Access is not a subscription.
        $this->assertFalse(te_event_staff_standing($this->pdo, $this->auth(301, [['coach', 51]]), 100));
    }

    public function testAParentHasNoStanding(): void
    {
        $this->assertFalse(te_event_staff_standing($this->pdo, $this->auth(400, [['parent', 51]]), 100));
    }

    public function testAnEventWithNoClubStillAdmitsItsCoachesAndNobodyElse(): void
    {
        $this->pdo->exec("INSERT INTO calendar_event_teams VALUES (200, 7)");
        $this->assertTrue(te_event_staff_standing($this->pdo, $this->auth(300, [['coach', 51]]), 200));
        $this->assertFalse(te_event_staff_standing($this->pdo, $this->auth(1, [['club_admin', 51]]), 200));
    }

    // ---- call sites ------------------------------------------------------------

    private static function code(string $rel): string
    {
        $out = '';
        foreach (token_get_all(file_get_contents(__DIR__ . '/../../' . $rel)) as $t) {
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

    private static function caseBody(string $code, string $case): string
    {
        $start = strpos($code, "case '$case':");
        self::assertNotFalse($start, "case '$case' not found");
        $end = strpos($code, "\n        case '", $start + 5) ?: strlen($code);
        return substr($code, $start, $end - $start);
    }

    public function testAttendanceGetAndSaveGateOnEventStanding(): void
    {
        $code = self::code('api/event-attendance.php');
        foreach (['get', 'save'] as $case) {
            $body = self::caseBody($code, $case);
            $gate = strpos($body, 'te_require_event_staff(');
            $this->assertNotFalse($gate, "case '$case' must call te_require_event_staff");
            $query = strpos($body, '$pdo->prepare(') ?: strpos($body, '$attendanceService->');
            $this->assertLessThan($query, $gate, "case '$case' must authorize before it queries");
        }
    }

    public function testAttendanceSaveAttributesToTheTokenNotTheBody(): void
    {
        $body = self::caseBody(self::code('api/event-attendance.php'), 'save');
        $this->assertStringNotContainsString("\$data['marked_by']", $body);
        $this->assertStringContainsString('$auth->getUserId()', $body);
    }

    public function testAthleteHistoryUsesTheAthleteReadPredicate(): void
    {
        $body = self::caseBody(self::code('api/event-attendance.php'), 'athlete-history');
        $this->assertStringContainsString('AthleteScope::userCanAccessAthlete(', $body);
    }

    public function testRsvpStatusIsAuthenticatedAndRespondIsNot(): void
    {
        $code = self::code('api/rsvp-webhook.php');
        $status = strpos($code, "\$action === 'status'");
        $this->assertNotFalse($status);
        $statusBranch = substr($code, $status, strpos($code, '} else {', $status) - $status);
        $this->assertStringContainsString('AuthMiddleware::requireAuth()', $statusBranch);
        $this->assertStringContainsString('te_require_event_staff(', $statusBranch);

        $respond = strpos($code, "\$action === 'respond'");
        $respondBranch = substr($code, $respond, $status - $respond);
        $this->assertStringNotContainsString('requireAuth', $respondBranch,
            'respond is keyed on a signed token and must stay reachable from an email link');
    }
}
