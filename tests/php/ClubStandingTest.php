<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use AuthMiddleware;

/**
 * Club membership is not club staff, and club staff is not club admin.
 *
 * `canAccessClub()` returns true for ANY role scoped to the club, `parent`
 * included. `handleClubParents` gated on it, so a parent who POSTed their own
 * club_id received every guardian in the club: name, email, mobile phone, portal
 * status and their children's names. Verified against production with a real
 * parent token — club 32 exposed 196 guardians to 13 parent accounts, club 51
 * exposed 148 to 2.
 *
 * Same shape as `userCanAccessAthlete` vs `staffCanManageAthlete`: a membership
 * predicate standing in for a staff one.
 */
class ClubStandingTest extends TestCase
{
    private function withRole(string $role, int $clubId = 51): AuthMiddleware
    {
        return AuthMiddleware::fromContext([
            'user_id' => 70,
            'email' => 'someone@example.com',
            'roles' => [['role' => $role, 'scope_type' => 'club', 'scope_id' => $clubId]],
        ]);
    }

    private function superAdmin(): AuthMiddleware
    {
        return AuthMiddleware::fromContext([
            'user_id' => 1, 'email' => 'super@platform.test',
            'system_role' => 'super_admin', 'roles' => [],
        ]);
    }

    /** THE REGRESSION. A parent holds a club role and must still be refused. */
    public function testParentIsNotClubStaffOrAdmin(): void
    {
        $parent = $this->withRole('parent');

        $this->assertTrue($parent->canAccessClub(51), 'a parent does hold a club role');
        $this->assertFalse(te_is_club_staff($parent, 51));
        $this->assertFalse(te_is_club_admin($parent, 51));
    }

    /** Volunteer and player are club roles too, and equally not staff. */
    public function testOtherNonStaffClubRolesAreAlsoRefused(): void
    {
        foreach (['volunteer', 'player', 'treasurer'] as $role) {
            $auth = $this->withRole($role);
            $this->assertTrue($auth->canAccessClub(51), "$role holds a club role");
            $this->assertFalse(te_is_club_staff($auth, 51), "$role must not be staff");
            $this->assertFalse(te_is_club_admin($auth, 51), "$role must not be admin");
        }
    }

    public function testCoachIsStaffButNotAdmin(): void
    {
        $coach = $this->withRole('coach');

        $this->assertTrue(te_is_club_staff($coach, 51));
        $this->assertFalse(te_is_club_admin($coach, 51), 'the crew roster is club-wide; a coach is team-scoped');
    }

    public function testClubAdminIsBoth(): void
    {
        $admin = $this->withRole('club_admin');

        $this->assertTrue(te_is_club_staff($admin, 51));
        $this->assertTrue(te_is_club_admin($admin, 51));
    }

    public function testSuperAdminIsBoth(): void
    {
        $this->assertTrue(te_is_club_staff($this->superAdmin(), 51));
        $this->assertTrue(te_is_club_admin($this->superAdmin(), 51));
    }

    /** Standing is per club — an admin of one club is nothing in another. */
    public function testStandingDoesNotCrossClubs(): void
    {
        $admin = $this->withRole('club_admin', 51);

        $this->assertTrue(te_is_club_admin($admin, 51));
        $this->assertFalse(te_is_club_admin($admin, 32));
        $this->assertFalse(te_is_club_staff($admin, 32));
    }

    /**
     * Source guard. The bug was never the predicate, it was which one the handler
     * called — so assert no live call to canAccessClub survives in the gateway.
     * Prose mentioning it is fine; a call is not.
     */
    public function testAuthGatewayNoLongerGatesOnMereClubMembership(): void
    {
        $src = file_get_contents(__DIR__ . '/../../api/auth-gateway.php');

        $this->assertDoesNotMatchRegularExpression(
            '/\$auth\s*->\s*canAccessClub\s*\(/',
            $src,
            'canAccessClub is club membership; a parent satisfies it'
        );
        $this->assertStringContainsString('te_is_club_admin($auth, $clubId)', $src);
        $this->assertStringContainsString('te_is_club_staff($auth, $clubId)', $src);
    }

    /** The crew roster specifically must require ADMIN, not merely staff. */
    public function testCrewRosterRequiresAdminNotStaff(): void
    {
        $src = file_get_contents(__DIR__ . '/../../api/auth-gateway.php');
        $at = strpos($src, 'function handleClubParents');
        $this->assertNotFalse($at);

        $handler = substr($src, $at, 1200);
        $this->assertStringContainsString('te_is_club_admin(', $handler);
        $this->assertStringNotContainsString('te_is_club_staff(', $handler);
    }
}
