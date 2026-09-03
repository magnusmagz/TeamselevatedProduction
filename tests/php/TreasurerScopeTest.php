<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use AuthMiddleware;

/**
 * Treasurer is the MONEY-ONLY role (decided 2026-09-03, kept rather than retired).
 *
 * A club treasurer is usually a volunteer parent. Making them a club admin would
 * also hand them every child's medical record and every family's contact details,
 * which is why the role exists. So it must hold on both sides:
 *
 *   - every financial endpoint admits it (te_is_financial_admin, and through it
 *     te_assert_financial_admin, plus api/payment-reports.php), and
 *   - no athlete-, crew- or compliance-facing predicate mentions it.
 *
 * The second half is a SCAN, because "just let staff see it" is the one-line
 * change that would silently widen a treasurer into minors' data.
 */
class TreasurerScopeTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** Files whose predicates decide who reaches athletes, crew, rosters, documents, events. */
    private const MUST_NOT_MENTION_TREASURER = [
        'lib/club_standing.php',
        'lib/AthleteScope.php',
        'lib/coach_scope.php',
        'lib/team_roster_scope.php',
        'lib/document_scope.php',
        'lib/event_standing.php',
        'lib/guardian_link_writer.php',
    ];

    private function withRole(string $role, int $clubId = 51): AuthMiddleware
    {
        return AuthMiddleware::fromContext([
            'user_id' => 70,
            'email' => 'treasurer@example.com',
            'roles' => [['role' => $role, 'scope_type' => 'club', 'scope_id' => $clubId]],
        ]);
    }

    public function testTreasurerIsAFinancialAdminOfTheirClubOnly(): void
    {
        require_once self::ROOT . '/lib/financial_scope.php';
        $t = $this->withRole('treasurer', 51);
        $this->assertTrue(te_is_financial_admin($t, 51));
        $this->assertFalse(te_is_financial_admin($t, 32), 'a treasurer of one club is nobody in another');
    }

    public function testClubAdminIsStillAFinancialAdminAndOthersAreNot(): void
    {
        require_once self::ROOT . '/lib/financial_scope.php';
        $this->assertTrue(te_is_financial_admin($this->withRole('club_admin'), 51));
        foreach (['coach', 'parent', 'volunteer', 'player'] as $role) {
            $this->assertFalse(te_is_financial_admin($this->withRole($role), 51), "$role must not reach money");
        }
    }

    /** The other half: money standing is not athlete standing. */
    public function testTreasurerIsNotClubStaff(): void
    {
        require_once self::ROOT . '/lib/club_standing.php';
        $t = $this->withRole('treasurer');
        $this->assertFalse(te_is_club_staff($t, 51));
        $this->assertFalse(te_is_club_admin($t, 51));
    }

    public function testAthleteFacingPredicatesNeverMentionTreasurer(): void
    {
        foreach (self::MUST_NOT_MENTION_TREASURER as $rel) {
            $path = self::ROOT . '/' . $rel;
            $this->assertFileExists($path);
            $src = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', file_get_contents($path));
            $this->assertStringNotContainsStringIgnoringCase(
                'treasurer', $src,
                "$rel mentions treasurer outside a comment. Money standing must not become athlete standing; "
                . 'admit the role in lib/financial_scope.php only.'
            );
        }
    }

    public function testEveryFinancialAdminGateGoesThroughTheOnePredicate(): void
    {
        $src = file_get_contents(self::ROOT . '/lib/financial_scope.php');
        $this->assertMatchesRegularExpression(
            '/function te_assert_financial_admin\b.*?te_is_financial_admin\(\$auth, \$clubId\)/s', $src,
            'te_assert_financial_admin must delegate to te_is_financial_admin');
        $reports = file_get_contents(self::ROOT . '/api/payment-reports.php');
        $this->assertStringContainsString('te_is_financial_admin($auth, $clubId)', $reports);
        $this->assertStringNotContainsString("role = 'treasurer'", $reports,
            'payment-reports must not re-derive treasurer standing with its own query');
    }

    public function testTreasurerIsInvitableAndNotACompliancePaperworkRole(): void
    {
        $inv = file_get_contents(self::ROOT . '/api/invitations-gateway.php');
        preg_match('/const\s+TE_INVITABLE_ROLES\s*=\s*\[([^\]]*)\]/', $inv, $m);
        $this->assertNotEmpty($m);
        $this->assertStringContainsString("'treasurer'", $m[1], 'an admin must be able to grant it without a DB edit');

        $comp = file_get_contents(self::ROOT . '/lib/compliance.php');
        preg_match('/const\s+TE_COMPLIANCE_STAFF_ROLES\s*=\s*\[([^\]]*)\]/', $comp, $c);
        $this->assertNotEmpty($c);
        $this->assertStringNotContainsString("'treasurer'", $c[1], 'a requirement must not be able to demand paperwork of a treasurer');
    }
}
