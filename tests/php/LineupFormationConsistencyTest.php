<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/lineup_formations.php';

/**
 * The formation presets exist in two languages (lib/lineup_formations.php and
 * frontend/src/utils/lineupFormations.ts) with no codegen step, so the server
 * that validates a slot code and the screen that draws it can drift. This
 * parses the TS copy and compares it to the PHP constant, slot for slot,
 * coordinate for coordinate — same shape as JerseySizeConsistencyTest.
 */
class LineupFormationConsistencyTest extends TestCase
{
    private const TS_PATH = __DIR__ . '/../../frontend/src/utils/lineupFormations.ts';

    /** size => formation => [[slot, label, x, y], ...] out of the TS source. */
    private function tsFormations(): array
    {
        $src = file_get_contents(self::TS_PATH);
        $this->assertNotFalse($src, 'lineupFormations.ts must be readable');

        preg_match('/export const LINEUP_FORMATIONS[^=]*=\s*\{(.*?)\n\};/s', $src, $block);
        $this->assertNotEmpty($block, 'could not find LINEUP_FORMATIONS in the TS source');

        $out = [];
        $size = null;
        $formation = null;
        foreach (explode("\n", $block[1]) as $line) {
            if (preg_match("/^  '([^']+)': \{/", $line, $m)) {
                $size = $m[1];
                $out[$size] = [];
            } elseif (preg_match("/^    '([^']+)': \[/", $line, $m)) {
                $formation = $m[1];
                $out[$size][$formation] = [];
            } elseif (preg_match("/\{ slot: '([^']+)', label: '([^']+)', x: (\d+), y: (\d+) \}/", $line, $m)) {
                $out[$size][$formation][] = ['slot' => $m[1], 'label' => $m[2], 'x' => (int) $m[3], 'y' => (int) $m[4]];
            }
        }
        return $out;
    }

    public function testTypescriptAndPhpPresetsAreIdentical(): void
    {
        $ts = $this->tsFormations();
        $this->assertNotEmpty($ts);
        $this->assertSame(
            TE_LINEUP_FORMATIONS,
            $ts,
            'lineupFormations.ts and TE_LINEUP_FORMATIONS have drifted — a slot the screen draws would be one the server refuses'
        );
    }

    public function testTheSizesAreTheFieldSizesAndTheCountsMatch(): void
    {
        $this->assertSame(TE_FIELD_SIZES, array_keys(TE_LINEUP_FORMATIONS));
        foreach (TE_LINEUP_FORMATIONS as $size => $presets) {
            $this->assertNotEmpty($presets, "$size has no presets");
            $players = te_lineup_field_players($size);
            foreach ($presets as $name => $slots) {
                $this->assertCount($players, $slots, "$size $name must hold exactly $players slots");
                $codes = array_column($slots, 'slot');
                $this->assertSame($codes, array_unique($codes), "$size $name repeats a slot code");
                $this->assertNotContains('BENCH', $codes);
                $lines = array_sum(array_map('intval', explode('-', $name)));
                $this->assertSame($players - ($size === '4v4' ? 0 : 1), $lines, "$size $name outfield count");
                foreach ($slots as $s) {
                    $this->assertGreaterThanOrEqual(0, $s['x']);
                    $this->assertLessThanOrEqual(100, $s['x']);
                    $this->assertGreaterThanOrEqual(0, $s['y']);
                    $this->assertLessThanOrEqual(100, $s['y']);
                }
            }
        }
    }

    public function testFourVFourHasNoKeeperAndEveryOtherSizeHasOne(): void
    {
        foreach (TE_LINEUP_FORMATIONS as $size => $presets) {
            foreach ($presets as $name => $slots) {
                $hasGk = in_array('GK', array_column($slots, 'slot'), true);
                $this->assertSame($size !== '4v4', $hasGk, "$size $name keeper (decision 4)");
            }
        }
    }

    public function testTheDefaultIsTheFirstPreset(): void
    {
        $this->assertSame('3-3-2', te_lineup_default_formation('9v9'));
        $this->assertSame('4-3-3', te_lineup_default_formation('11v11'));
        $this->assertNull(te_lineup_default_formation('5v5'));
        $this->assertNull(te_lineup_formation_slots('9v9', '4-3-3'));
    }
}
