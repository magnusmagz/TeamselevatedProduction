<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;

/**
 * lib/feature_flags.php — kill switches read from config vars (Phase 2, 2026-09-02).
 *
 * The semantics that matter: UNSET means ON (a switch exists to turn a shipped feature
 * off, not to keep it dark), and a caller that skips work because a switch is off must
 * say so rather than report success. The scan below lists every Phase 2 send path and
 * fails if one stops consulting a switch — the bug class this repo keeps producing is
 * "fixed one, missed three".
 */
class FeatureFlagsTest extends TestCase
{
    /**
     * Phase 2 send paths → the switch each must consult. Extend as slices land.
     *
     * A value may be a LIST when one file grew a second independent send: a
     * kill switch is per feature, not per file, so folding two sends onto one
     * name would mean turning off tryout offers to stop coach invites.
     */
    private const GATED = [
        'api/invoices.php' => 'TRANSACTIONAL_EMAIL',
        'registration/registrations-api.php' => 'REGISTRATION_CONFIRMATION',
        // Slice 2.2 — send-offers answered "Offers sent successfully" with no
        // send of any kind anywhere in the handler.
        // Slice 8.2 — the coach-invite email is a SEPARATE switch from the
        // offer email in the same file. They fail differently and a club may
        // want one dark and the other live.
        'registration/tryouts-api.php' => ['TRYOUT_OFFER_EMAIL', 'TRYOUT_COACH_INVITE_EMAIL'],
        // Slice 2.1a — the three payment endpoints that logged
        // "DEMO: Would send ..." and answered success. PaymentEmailStubsTest
        // checks the stronger property (the switch is consulted BEFORE each send
        // site, in the same case block); this list is the roll call.
        'api/payment-receipt.php' => 'TRANSACTIONAL_EMAIL',
        'api/payment-failures.php' => 'TRANSACTIONAL_EMAIL',
        'api/payment-reminders.php' => 'TRANSACTIONAL_EMAIL',
        // Slices 2.3/2.4 — scheduled broadcasts were stored as status='scheduled'
        // and never dispatched. The switch stops the worker tick AND the endpoint
        // that accepts a schedule, so flipping it off cannot leave campaigns
        // accumulating for a dispatcher that is not running.
        'lib/broadcast_dispatcher.php' => 'SCHEDULED_DISPATCH',
        'api/communications-gateway.php' => 'SCHEDULED_DISPATCH',
        // GOTR G4 — compliance. Two switches, and both are load-bearing:
        // COMPLIANCE is the whole feature (gateway, export, screens), while
        // COMPLIANCE_REMINDERS is the mailing sweep alone, so a council can have
        // the screens live with nothing being sent to 30,000 coaches. The
        // dispatcher checks them itself as well as the worker tick, because a
        // switch is per feature and not per caller.
        'lib/compliance_reminders.php' => ['COMPLIANCE', 'COMPLIANCE_REMINDERS'],
        'workers/queue-worker.php' => ['COMPLIANCE', 'COMPLIANCE_REMINDERS'],
        'api/compliance-gateway.php' => 'COMPLIANCE',
        'api/compliance-export.php' => 'COMPLIANCE',
    ];

    protected function setUp(): void
    {
        require_once __DIR__ . '/../../lib/feature_flags.php';
        putenv('TE_FEATURE_UNIT_PROBE');
        unset($_ENV['TE_FEATURE_UNIT_PROBE']);
    }

    private function set(?string $v): void
    {
        if ($v === null) {
            putenv('TE_FEATURE_UNIT_PROBE');
            unset($_ENV['TE_FEATURE_UNIT_PROBE']);
        } else {
            putenv("TE_FEATURE_UNIT_PROBE=$v");
            $_ENV['TE_FEATURE_UNIT_PROBE'] = $v;
        }
    }

    public function testUnsetIsOn(): void
    {
        $this->set(null);
        $this->assertTrue(te_feature_enabled('UNIT_PROBE'));
    }

    /** @dataProvider offValues */
    public function testOffValues(string $v): void
    {
        $this->set($v);
        $this->assertFalse(te_feature_enabled('UNIT_PROBE'), "'$v' must read as OFF");
    }

    public static function offValues(): array
    {
        return [['off'], ['OFF'], ['0'], ['false'], ['False'], ['no'], [' off ']];
    }

    /** @dataProvider onValues */
    public function testOnValues(string $v): void
    {
        $this->set($v);
        $this->assertTrue(te_feature_enabled('UNIT_PROBE'), "'$v' must read as ON");
    }

    public static function onValues(): array
    {
        return [['on'], ['1'], ['true'], ['yes'], ['anything'], ['']];
    }

    public function testTheNameMustBeUpperSnake(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        te_feature_enabled('transactional email');
    }

    public function testTheDisabledResponseNeverClaimsSuccess(): void
    {
        $r = te_feature_disabled_response('UNIT_PROBE');
        $this->assertFalse($r['success']);
        $this->assertFalse($r['sent']);
        $this->assertSame('UNIT_PROBE', $r['feature_disabled']);
    }

    public function testEveryGatedSendPathConsultsItsSwitch(): void
    {
        foreach (self::GATED as $rel => $flags) {
            $src = file_get_contents(__DIR__ . '/../../' . $rel);
            foreach ((array) $flags as $flag) {
                $this->assertStringContainsString("te_feature_enabled('$flag')", $src,
                    "$rel must check te_feature_enabled('$flag') before sending");
            }
        }
        $this->assertTrue(true);
    }
}
