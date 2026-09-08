<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use AuthMiddleware;
use PDO;

/**
 * A referee is NOT club staff (Maggie, 2026-09-08).
 *
 * The `referee` role exists so a referee can hold an account and reach their
 * own games and contact card at /referee. It must admit them to nothing
 * else: no athlete, no crew, no roster, no document, no event attendance, no
 * compliance paperwork, no money. Same shape as TreasurerScopeTest — the
 * predicates are asserted AND the files that decide athlete-facing standing
 * are scanned for the word, because "just let staff see it" is the one-line
 * change that would silently widen a referee into minors' data.
 */
class RefereeScopeTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** Files whose predicates decide who reaches athletes, crew, rosters, documents, events, money. */
    private const MUST_NOT_MENTION_REFEREE = [
        'lib/club_standing.php',
        'lib/AthleteScope.php',
        'lib/coach_scope.php',
        'lib/team_roster_scope.php',
        'lib/document_scope.php',
        'lib/event_standing.php',
        'lib/guardian_link_writer.php',
        'lib/financial_scope.php',
    ];

    private function withRole(string $role, int $clubId = 51): AuthMiddleware
    {
        return AuthMiddleware::fromContext([
            'user_id' => 70,
            'email' => 'ref@example.com',
            'roles' => [['role' => $role, 'scope_type' => 'club', 'scope_id' => $clubId]],
        ]);
    }

    public function testRefereeIsNotClubStaffOrAdmin(): void
    {
        require_once self::ROOT . '/lib/club_standing.php';
        $r = $this->withRole('referee');
        $this->assertFalse(te_is_club_staff($r, 51));
        $this->assertFalse(te_is_club_admin($r, 51));
    }

    public function testRefereeIsNotAFinancialAdmin(): void
    {
        require_once self::ROOT . '/lib/financial_scope.php';
        $this->assertFalse(te_is_financial_admin($this->withRole('referee'), 51));
    }

    public function testRefereeHasNoStandingOnAnEvent(): void
    {
        require_once self::ROOT . '/lib/event_standing.php';
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE calendar_events (id INTEGER PRIMARY KEY, club_id INTEGER);
                    CREATE TABLE calendar_event_teams (id INTEGER PRIMARY KEY, event_id INTEGER, team_id INTEGER);
                    CREATE TABLE teams (id INTEGER PRIMARY KEY, club_id INTEGER, primary_coach_id INTEGER, deleted_at TEXT);
                    CREATE TABLE team_members (id INTEGER PRIMARY KEY, team_id INTEGER, user_id INTEGER, role TEXT, status TEXT);
                    INSERT INTO calendar_events (id, club_id) VALUES (500, 51);");
        $this->assertFalse(te_event_staff_standing($pdo, $this->withRole('referee'), 500));
    }

    public function testRefereeCannotManageOrReadAnAthlete(): void
    {
        require_once self::ROOT . '/lib/AthleteScope.php';
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE athletes (id INTEGER PRIMARY KEY, club_id INTEGER, deleted_at TEXT);
                    CREATE TABLE teams (id INTEGER PRIMARY KEY, club_id INTEGER, primary_coach_id INTEGER, deleted_at TEXT);
                    CREATE TABLE team_members (id INTEGER PRIMARY KEY, team_id INTEGER, user_id INTEGER, athlete_id INTEGER, role TEXT, status TEXT);
                    CREATE TABLE guardians (id INTEGER PRIMARY KEY, email TEXT);
                    CREATE TABLE athlete_guardians (id INTEGER PRIMARY KEY, athlete_id INTEGER, guardian_id INTEGER);
                    CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT);
                    CREATE TABLE user_guardians (id INTEGER PRIMARY KEY, user_id INTEGER, guardian_id INTEGER);
                    INSERT INTO athletes (id, club_id) VALUES (9, 51);
                    INSERT INTO users (id, email) VALUES (70, 'ref@example.com');");
        $r = $this->withRole('referee');
        $this->assertFalse(\AthleteScope::staffCanManageAthlete($pdo, $r, 9));
        $this->assertFalse(\AthleteScope::userCanAccessAthlete($pdo, $r, 9));
    }

    public function testAthleteFacingPredicatesNeverMentionReferee(): void
    {
        foreach (self::MUST_NOT_MENTION_REFEREE as $rel) {
            $path = self::ROOT . '/' . $rel;
            $this->assertFileExists($path);
            $src = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', file_get_contents($path));
            $this->assertStringNotContainsStringIgnoringCase(
                'referee', $src,
                "$rel mentions referee outside a comment. A referee reaches their own games only; "
                . 'admit the role in lib/referees.php and nowhere else.'
            );
        }
    }

    public function testRefereeIsInvitableManageableOnUsersAndNotACompliancePaperworkRole(): void
    {
        $inv = file_get_contents(self::ROOT . '/api/invitations-gateway.php');
        preg_match('/const\s+TE_INVITABLE_ROLES\s*=\s*\[([^\]]*)\]/', $inv, $m);
        $this->assertNotEmpty($m);
        $this->assertStringContainsString("'referee'", $m[1], 'an admin must be able to grant it without a DB edit');

        $ci = file_get_contents(self::ROOT . '/lib/coach_invite.php');
        preg_match('/const\s+TE_STAFF_INVITE_ROLES\s*=\s*\[([^\]]*)\]/', $ci, $s);
        $this->assertNotEmpty($s);
        $this->assertStringContainsString("'referee'", $s[1], 'Invite / Resend / Send login link / Set password on Club Settings → Users');

        $comp = file_get_contents(self::ROOT . '/lib/compliance.php');
        preg_match('/const\s+TE_COMPLIANCE_STAFF_ROLES\s*=\s*\[([^\]]*)\]/', $comp, $c);
        $this->assertNotEmpty($c);
        $this->assertStringNotContainsString("'referee'", $c[1], 'a requirement must not be able to demand paperwork of a referee');
        preg_match('/const\s+TE_COMPLIANCE_ROLE_FALLBACK\s*=\s*\[([^\]]*)\]/', $comp, $f);
        $this->assertStringNotContainsString("'referee'", $f[1] ?? '');
    }

    /** The one approved edit to lib/JWT.php: a single word in the precedence ORDER BY. */
    public function testTheJwtEditIsTheOneWordInThePrecedenceLadder(): void
    {
        $src = file_get_contents(self::ROOT . '/lib/JWT.php');
        $this->assertSame(1, preg_match_all("/WHEN 'referee'\s+THEN 5/", $src), 'referee ranks after volunteer (4) and before parent (6)');
        $this->assertSame(1, preg_match_all("/WHEN 'parent'\s+THEN 6/", $src));
        $this->assertSame(1, preg_match_all("/WHEN 'player'\s+THEN 7/", $src));
        $this->assertStringNotContainsString('referee', file_get_contents(self::ROOT . '/lib/AuthMiddleware.php'));
        $this->assertStringNotContainsString('referee', file_get_contents(self::ROOT . '/api/auth-gateway.php'));
    }

    /** The gateway gates with the standing predicates, never with membership. */
    public function testTheRefereesGatewayUsesTheRightPredicates(): void
    {
        $src = file_get_contents(self::ROOT . '/api/referees.php');
        $this->assertStringContainsString('AuthMiddleware::requireAuth()', $src);
        $this->assertStringContainsString('te_is_club_admin(', $src);
        $this->assertStringContainsString('te_is_club_staff(', $src);
        $this->assertStringContainsString('te_event_staff_standing(', $src);
        $this->assertStringNotContainsString('canAccessClub(', $src, 'membership is not staff');
        $this->assertStringNotContainsString('JWT::decode(', $src);
        $this->assertDoesNotMatchRegularExpression('/DELETE\s+FROM\s+referees\b/i', $src . file_get_contents(self::ROOT . '/lib/referees.php'), 'never hard-deleted');
        $this->assertDoesNotMatchRegularExpression('/INSERT\s+INTO\s+audit_log/i', $src);
        $this->assertStringContainsString("date('Y-m-d')", $src, 'today is a date-only string');
    }
}
