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
    /** Phase 2 send paths → the switch each must consult. Extend as slices land. */
    private const GATED = [
        // 'api/invoices.php' => 'TRANSACTIONAL_EMAIL',
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
        foreach (self::GATED as $rel => $flag) {
            $src = file_get_contents(__DIR__ . '/../../' . $rel);
            $this->assertStringContainsString("te_feature_enabled('$flag')", $src,
                "$rel must check te_feature_enabled('$flag') before sending");
        }
        $this->assertTrue(true);
    }
}
