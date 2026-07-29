<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;

/**
 * te_normalize_jersey_size() parses group + size structurally rather than matching
 * label text against a table. That is what makes the unavoidable duplication of
 * label strings (PHP, TypeScript, two applied migrations, 26 seeded form rows)
 * cosmetic instead of load-bearing: a club may retitle its own registration-form
 * option and the answer still resolves.
 *
 * The two refusals below are as important as the acceptances.
 */
class JerseySizeResolverTest extends TestCase
{
    /** @dataProvider resolvableProvider */
    public function testResolves(string $input, string $expected): void
    {
        $this->assertSame($expected, te_normalize_jersey_size($input));
    }

    public static function resolvableProvider(): array
    {
        return [
            // Stored codes round-trip.
            'code'                  => ['YM', 'YM'],
            'code lowercase'        => ['ym', 'YM'],
            'code padded'           => ['  AL  ', 'AL'],
            'code adult 2xl'        => ['A2XL', 'A2XL'],

            // The exact labels seeded onto registration forms.
            'seeded youth label'    => ['Youth Medium (10-12)', 'YM'],
            'seeded adult label'    => ['Adult 2X-Large', 'A2XL'],
            'seeded youth xs'       => ['Youth X-Small (4-5)', 'YXS'],

            // Retitled by a club in the form builder — the case the structural
            // parse exists for.
            'age hint removed'      => ['Youth Medium', 'YM'],
            'abbreviated'           => ['youth med', 'YM'],
            'code-ish label'        => ['YOUTH XS', 'YXS'],
            'spelled out'           => ['Adult Extra Large', 'AXL'],
            'reordered'             => ['Large Youth', 'YL'],
            'slash separator'       => ['Adult/Large', 'AL'],
            'gendered wording'      => ['Mens Large', 'AL'],
            'womens wording'        => ['Womens Small', 'AS'],

            // Vendor spellings from imports and order sheets.
            'vendor xxl'            => ['AXXL', 'A2XL'],
            'vendor xxxl'           => ['AXXXL', 'A3XL'],
            'vendor 2x'             => ['A2X', 'A2XL'],
            'vendor 3x'             => ['A3X', 'A3XL'],
            'vendor adult 2xl'      => ['adult 2xl', 'A2XL'],
        ];
    }

    /** @dataProvider unresolvableProvider */
    public function testRejectsToNull($input): void
    {
        $this->assertNull(te_normalize_jersey_size($input));
    }

    public static function unresolvableProvider(): array
    {
        return [
            // Load-bearing: the athlete form sends '' for an athlete with no size.
            'empty string'      => [''],
            'whitespace only'   => ['   '],
            'null'              => [null],

            // Load-bearing: an unprefixed size is genuinely ambiguous between
            // Youth and Adult, and must never be guessed into one.
            'bare M'            => ['M'],
            'bare MEDIUM'       => ['MEDIUM'],
            'bare Large'        => ['Large'],
            'bare XL'           => ['XL'],

            // Group with no size, or size with no meaning.
            'group only youth'  => ['Youth'],
            'group only adult'  => ['ADULT'],
            'single letter'     => ['Y'],
            'nonsense'          => ['banana'],
            'number'            => ['42'],

            // Youth kits are not made in 2XL/3XL, so these are not real sizes
            // even though both halves parse.
            'youth 2xl'         => ['Youth 2XL'],
            'youth 3xl'         => ['Y3XL'],
        ];
    }

    /** Idempotence: normalizing an already-normalized value changes nothing. */
    public function testIsIdempotent(): void
    {
        foreach (TE_JERSEY_SIZES as $code) {
            $once = te_normalize_jersey_size($code);
            $this->assertSame($code, $once);
            $this->assertSame($once, te_normalize_jersey_size($once));
        }
    }

    /** Never returns anything the CHECK constraint would reject. */
    public function testOutputIsAlwaysStorableOrNull(): void
    {
        $inputs = ['YM', 'garbage', '', 'Youth Medium', 'M', 'A9XL', 'Adult', 'youth small (6-8)', '2XL'];
        foreach ($inputs as $in) {
            $out = te_normalize_jersey_size($in);
            if ($out !== null) {
                $this->assertContains($out, TE_JERSEY_SIZES, "input '$in' produced unstorable '$out'");
            }
        }
    }
}
