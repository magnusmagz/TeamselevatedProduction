<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;

/**
 * users.role carries a CHECK (admin/coach/parent/athlete/player/user/super_admin)
 * that predates the club-access roles. The first referee invite wrote 'referee'
 * into it and 500'd (2026-09-08). The INSERT must go through the legacy mapper.
 */
class CoachInviteLegacyRoleTest extends TestCase
{
    public function testUnknownClubRolesMapToUserAndKnownOnesPassThrough(): void
    {
        require_once __DIR__ . '/../../lib/coach_invite.php';
        $this->assertSame('user', te_coach_invite_legacy_user_role('referee'));
        $this->assertSame('user', te_coach_invite_legacy_user_role('treasurer'));
        $this->assertSame('user', te_coach_invite_legacy_user_role('volunteer'));
        $this->assertSame('user', te_coach_invite_legacy_user_role('club_admin'));
        $this->assertSame('coach', te_coach_invite_legacy_user_role('coach'));
        $this->assertSame('parent', te_coach_invite_legacy_user_role('parent'));
    }

    public function testTheUsersInsertUsesTheMapper(): void
    {
        $src = file_get_contents(__DIR__ . '/../../lib/coach_invite.php');
        $i = strpos($src, 'INSERT INTO users');
        $this->assertNotFalse($i);
        $window = substr($src, $i, 600);
        $this->assertStringContainsString('te_coach_invite_legacy_user_role($role)', $window,
            'the users.role value must pass through the legacy mapper or a new club role 500s on users_role_check');
    }
}
