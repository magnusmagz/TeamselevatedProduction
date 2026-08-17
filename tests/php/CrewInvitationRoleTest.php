<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Crew can be invited through the invitations gateway (2026-08-17).
 *
 * The role dropdown offered Coach and Club Admin only. Crew were reachable solely
 * through the per-athlete invite on the Crew page, so a club with an existing guardian
 * roster had no way to hand out one link.
 *
 * `parent` works here without any new linking code, and the reason matters: parent
 * standing is derived from `guardians.email = users.email`, so granting the
 * user_club_access row is the ONLY missing piece for a guardian who already exists.
 * CLAUDE.md records the flip side — "ParentInvite creates the role row, so invited
 * families are fine; anyone who self-signs-up is not."
 *
 * The same email dependency is the failure mode: accept on a different address and the
 * portal is empty. `linked_athletes` on the accept response reports that, which is what
 * the club-admin connect flow will hang off next.
 */
class CrewInvitationRoleTest extends TestCase
{
    private const GATEWAY = __DIR__ . '/../../api/invitations-gateway.php';
    private const FORM = __DIR__ . '/../../frontend/src/components/InviteUsersForm.tsx';

    private function gateway(): string
    {
        return file_get_contents(self::GATEWAY);
    }

    public function testParentIsInvitable(): void
    {
        $this->assertMatchesRegularExpression(
            '/const\s+TE_INVITABLE_ROLES\s*=\s*\[[^\]]*\'parent\'/',
            $this->gateway(),
            'Crew are invited through this gateway; parent must be whitelisted.'
        );
    }

    /**
     * A whitelist that lists every CHECK value is not a whitelist. Nothing invites
     * into these, and `player` in particular should never be invitable — migration 067
     * removed 33 athlete accounts precisely because a child holding a login is the bug.
     */
    public function testUnusedRolesAreNotInvitable(): void
    {
        preg_match('/const\s+TE_INVITABLE_ROLES\s*=\s*\[([^\]]*)\]/', $this->gateway(), $m);
        $this->assertNotEmpty($m, 'TE_INVITABLE_ROLES not found');

        foreach (['player', 'volunteer', 'treasurer'] as $role) {
            $this->assertStringNotContainsString(
                "'{$role}'",
                $m[1],
                "{$role} is not invited through this form; do not widen the whitelist to it."
            );
        }
    }

    /**
     * Both creation paths must validate. There was no whitelist at all: an unsupported
     * role reached user_club_access's CHECK constraint at ACCEPT time — after the
     * invitation had gone out — so the club heard about it from a family who was stuck.
     */
    public function testBothCreationPathsRejectAnUnsupportedRole(): void
    {
        $src = $this->gateway();

        foreach (['handleSendInvitations', 'handleCreateLink'] as $fn) {
            $start = strpos($src, "function {$fn}(");
            $this->assertNotFalse($start, "{$fn} not found");
            $body = substr($src, $start, 2000);

            $this->assertStringContainsString(
                'TE_INVITABLE_ROLES',
                $body,
                "{$fn} must validate the role before writing an invitation."
            );
        }
    }

    /**
     * The accept response must report whether a Crew member actually reached their
     * family, because zero is invisible until they open an empty portal.
     */
    public function testAcceptReportsWhetherCrewReachedTheirFamily(): void
    {
        $src = $this->gateway();

        $this->assertStringContainsString('te_linked_athlete_count', $src);
        $this->assertStringContainsString("'linked_athletes'", $src);
        $this->assertMatchesRegularExpression(
            '/LOWER\(g\.email\)\s*=\s*LOWER\(:email\)/',
            $src,
            'Match guardian email case-insensitively — users sign up with any casing.'
        );
    }

    /**
     * The UI says Crew, never Parents. The stored value stays `parent` because that is
     * the user_club_access CHECK value.
     */
    public function testTheFormOffersCrewInBothSelectors(): void
    {
        $form = file_get_contents(self::FORM);

        $this->assertSame(
            2,
            substr_count($form, '<option value="parent">Crew</option>'),
            'Both the email-invite and shareable-link role selectors must offer Crew. '
            . 'They are separate <select> blocks and adding one is easy to miss.'
        );

        $this->assertStringNotContainsString(
            '<option value="parent">Parent</option>',
            $form,
            'This product calls guardians Crew everywhere in the UI.'
        );
    }
}
