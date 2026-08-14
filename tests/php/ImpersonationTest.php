<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/impersonation.php';

/**
 * Impersonation claim handling.
 *
 * The bugs this pins are all "the claim quietly went missing or quietly grew":
 * a re-minted token that dropped `imp` would be a permanent unmarked login as
 * the target, and one that reset the expiry would never end.
 */
class ImpersonationTest extends TestCase
{
    private function claims($now)
    {
        return te_impersonation_claims(7, 'admin@example.com', 'Ada Admin', $now);
    }

    public function testTokenExpiryMatchesImpersonationExpiry()
    {
        $now = 1700000000;
        $claims = $this->claims($now);

        // The JWT's own exp must be the impersonation's exp, not JWT::generate()'s
        // 24h default — otherwise an abandoned session stays usable all day.
        $this->assertSame($now + TE_IMPERSONATION_TTL, $claims['exp']);
        $this->assertSame($claims['imp']['exp'], $claims['exp']);
        $this->assertSame(3600, TE_IMPERSONATION_TTL);
    }

    public function testClaimRecordsTheAdminBehindTheSession()
    {
        $claims = $this->claims(1700000000);

        $this->assertSame(7, $claims['imp']['by']);
        $this->assertSame('admin@example.com', $claims['imp']['by_email']);
        $this->assertSame('Ada Admin', $claims['imp']['by_name']);
        $this->assertSame(1700000000, $claims['imp']['started_at']);
    }

    public function testReadsClaimFromAnObjectPayload()
    {
        $now = 1700000000;
        $payload = json_decode(json_encode($this->claims($now)));

        $imp = te_read_impersonation($payload, $now + 10);

        $this->assertNotNull($imp);
        $this->assertSame(7, $imp['by']);
    }

    public function testOrdinarySessionHasNoImpersonation()
    {
        $this->assertNull(te_read_impersonation((object) ['user_id' => '3'], 1700000000));
        $this->assertNull(te_read_impersonation(null, 1700000000));
    }

    public function testExpiredClaimReadsAsNoImpersonation()
    {
        $now = 1700000000;
        $payload = json_decode(json_encode($this->claims($now)));

        $this->assertNull(te_read_impersonation($payload, $now + TE_IMPERSONATION_TTL));
        $this->assertNull(te_read_impersonation($payload, $now + TE_IMPERSONATION_TTL + 1));
    }

    public function testMalformedClaimIsNotAnImpersonation()
    {
        $now = 1700000000;

        // Missing `by`: unattributable, so it is not a session anyone is
        // accountable for.
        $this->assertNull(te_read_impersonation((object) ['imp' => ['exp' => $now + 60]], $now));
        // Missing `exp`: would never end.
        $this->assertNull(te_read_impersonation((object) ['imp' => ['by' => 7]], $now));
    }

    public function testCarryPreservesClaimAndOriginalExpiryOnReMint()
    {
        $now = 1700000000;
        $old = json_decode(json_encode($this->claims($now)));

        // Half an hour later the session re-mints (verify-session runs on every
        // page load). The window must not restart.
        $carried = te_carry_impersonation(['system_role' => 'user'], $old, $now + 1800);

        $this->assertSame(7, $carried['imp']['by']);
        $this->assertSame($now + TE_IMPERSONATION_TTL, $carried['exp']);
        $this->assertSame('user', $carried['system_role']);
    }

    public function testCarryLeavesAnOrdinarySessionAlone()
    {
        $claims = ['system_role' => 'super_admin'];

        $carried = te_carry_impersonation($claims, (object) ['user_id' => '3'], 1700000000);

        $this->assertSame($claims, $carried);
        $this->assertArrayNotHasKey('imp', $carried);
        // No exp is injected: JWT::generate()'s 24h default must still apply to
        // a normal login.
        $this->assertArrayNotHasKey('exp', $carried);
    }

    public function testCarryDropsAnExpiredClaimRatherThanRenewingIt()
    {
        $now = 1700000000;
        $old = json_decode(json_encode($this->claims($now)));

        $carried = te_carry_impersonation([], $old, $now + TE_IMPERSONATION_TTL + 1);

        $this->assertArrayNotHasKey('imp', $carried);
    }

    public function testSuperAdminCannotBeImpersonated()
    {
        $target = ['id' => 9, 'system_role' => 'super_admin'];

        $this->assertSame('cannot_impersonate_super_admin', te_impersonation_refusal($target, 7));
    }

    public function testSelfAndMissingUserAreRefused()
    {
        $this->assertSame('cannot_impersonate_self', te_impersonation_refusal(['id' => 7, 'system_role' => 'user'], 7));
        $this->assertSame('user_not_found', te_impersonation_refusal(false, 7));
        $this->assertSame('user_not_found', te_impersonation_refusal(null, 7));
    }

    public function testOrdinaryUserIsAllowed()
    {
        $this->assertNull(te_impersonation_refusal(['id' => 9, 'system_role' => 'user'], 7));
        // A missing system_role defaults to 'user' — absent is not privileged.
        $this->assertNull(te_impersonation_refusal(['id' => 9], 7));
    }

    /**
     * The two re-minting endpoints must both carry the claim. Asserted by
     * parsing, because the bug is an omission at a call site, not a defect in
     * te_carry_impersonation() itself.
     */
    public function testAuthGatewayCarriesImpersonationOnEveryReMint()
    {
        $src = file_get_contents(__DIR__ . '/../../api/auth-gateway.php');

        foreach (['handleVerifySession', 'handleSwitchContext'] as $fn) {
            $start = strpos($src, "function $fn(");
            $this->assertNotFalse($start, "$fn not found");
            $body = substr($src, $start, 4000);
            $this->assertStringContainsString(
                'te_carry_impersonation',
                $body,
                "$fn re-mints a token without carrying the impersonation claim — " .
                'that silently converts an impersonation into a permanent login as the target'
            );
        }
    }

    /** Stopping must not live behind the super-admin gate — see auth-gateway.php. */
    public function testStopImpersonationIsReachableFromAnImpersonatedSession()
    {
        $auth = file_get_contents(__DIR__ . '/../../api/auth-gateway.php');
        $superAdmin = file_get_contents(__DIR__ . '/../../api/super-admin-gateway.php');

        $this->assertStringContainsString("case 'stop-impersonation':", $auth);
        $this->assertStringNotContainsString('stop-impersonation', $superAdmin);
    }
}
