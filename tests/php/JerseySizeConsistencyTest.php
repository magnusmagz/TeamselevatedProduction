<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The jersey-size list exists in two languages and in two applied migrations.
 * This test covers the copies that can still drift.
 *
 * WHAT CANNOT BE DEDUPLICATED, AND WHY
 *  - lib/jersey_size.php (PHP)  vs  frontend/src/utils/jerseySize.ts (TypeScript):
 *    different runtimes, no codegen step in this project. Locked together here.
 *  - migrations 054 (CHECK constraint) and 055 (seeded form options): already
 *    applied to production. An applied migration is history and must not be
 *    edited, so its copy is permanent by design — asserted, not removed.
 *  - the 26 program_form_fields rows migration 055 seeded: that is data, and a
 *    club can legitimately retitle its own form labels.
 *
 * Because that last case is real, te_normalize_jersey_size() parses group + size
 * structurally instead of matching label text (see JerseySizeResolverTest). Label
 * drift is therefore cosmetic. This test keeps it from even being cosmetic.
 */
class JerseySizeConsistencyTest extends TestCase
{
    private const TS_PATH = __DIR__ . '/../../frontend/src/utils/jerseySize.ts';
    private const MIGRATION_054 = __DIR__ . '/../../database/migrations/054_athlete_jersey_size.sql';
    private const MIGRATION_055 = __DIR__ . '/../../database/migrations/055_registration_jersey_size_field.sql';

    /** Parse the value/label/group triples out of the TS source. */
    private function tsSizes(): array
    {
        $src = file_get_contents(self::TS_PATH);
        $this->assertNotFalse($src, 'jerseySize.ts must be readable');

        preg_match_all(
            "/\{\s*value:\s*'([^']+)',\s*label:\s*'([^']+)',\s*group:\s*'([^']+)'\s*\}/",
            $src,
            $m,
            PREG_SET_ORDER
        );
        $this->assertNotEmpty($m, 'could not parse SIZES out of jerseySize.ts');

        $out = [];
        foreach ($m as $row) {
            // Mirrors the `${group} ${label}` derivation in the TS module.
            $out[$row[1]] = $row[3] . ' ' . $row[2];
        }
        return $out;
    }

    public function testTypescriptAndPhpAgreeOnCodesAndLabels(): void
    {
        $this->assertSame(
            TE_JERSEY_SIZE_LABELS,
            $this->tsSizes(),
            'jerseySize.ts and TE_JERSEY_SIZE_LABELS have drifted — the registration '
            . 'form and the athlete form would offer different options'
        );
    }

    public function testPhpCodeListIsDerivedFromTheLabelMap(): void
    {
        // Not a tautology: it fails if someone re-introduces a hand-written code
        // list, which is how a code without a label (or the reverse) creeps in.
        $this->assertSame(array_keys(TE_JERSEY_SIZE_LABELS), TE_JERSEY_SIZES);
        $this->assertCount(12, TE_JERSEY_SIZES);
    }

    /** Migration 054's CHECK constraint must accept exactly the codes we store. */
    public function testMigration054ConstraintMatchesTheCodeList(): void
    {
        $sql = file_get_contents(self::MIGRATION_054);
        $this->assertNotFalse($sql, 'migration 054 must be readable');

        // The IN (...) list inside the CHECK constraint.
        preg_match("/CHECK\s*\(.*?IN\s*\((.*?)\)/s", $sql, $m);
        $this->assertNotEmpty($m, 'could not find the CHECK constraint IN list in migration 054');

        preg_match_all("/'([A-Z0-9]+)'/", $m[1], $codes);
        sort($codes[1]);
        $expected = TE_JERSEY_SIZES;
        sort($expected);

        $this->assertSame($expected, $codes[1], 'migration 054 CHECK list differs from TE_JERSEY_SIZES');
    }

    /** Migration 055's seeded option labels must match what the app now seeds. */
    public function testMigration055OptionsMatchTheLabelList(): void
    {
        $sql = file_get_contents(self::MIGRATION_055);
        $this->assertNotFalse($sql, 'migration 055 must be readable');

        preg_match('/\'(\[\"Youth.*?\])\'/s', $sql, $m);
        $this->assertNotEmpty($m, 'could not find the options JSON literal in migration 055');

        $this->assertSame(
            te_jersey_size_options(),
            json_decode($m[1], true),
            'migration 055 option labels have drifted from TE_JERSEY_SIZE_LABELS'
        );
    }

    /**
     * Whatever a form offers, the backend must be able to store it. This is the
     * property that actually matters — if it holds, label drift cannot cause a
     * parent's selection to vanish.
     */
    public function testEveryLabelResolvesBackToItsOwnCode(): void
    {
        foreach (TE_JERSEY_SIZE_LABELS as $code => $label) {
            $this->assertSame($code, te_normalize_jersey_size($label), "label '$label'");
        }
        foreach ($this->tsSizes() as $code => $fullLabel) {
            $this->assertSame($code, te_normalize_jersey_size($fullLabel), "ts label '$fullLabel'");
        }
    }
}
