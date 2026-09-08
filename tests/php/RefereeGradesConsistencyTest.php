<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/referees.php';

/**
 * The referee grade scale is defined twice — lib/referees.php for open-games,
 * claim and the staff override, frontend/src/constants/refereeGrades.ts for the
 * selects and the picker warning — and the two must be ONE scale. Same shape
 * as JerseySizeConsistencyTest and TeamGenderConsistencyTest: the TS file is
 * parsed, not trusted.
 */
class RefereeGradesConsistencyTest extends TestCase
{
    private const TS = __DIR__ . '/../../frontend/src/constants/refereeGrades.ts';

    /** @return array<string,int> value => rank, from the TS option list */
    private function tsRanks(): array
    {
        $src = file_get_contents(self::TS);
        $this->assertNotFalse($src);
        preg_match_all("/\{\s*value:\s*'([^']+)',\s*label:\s*'[^']+',\s*group:\s*'(?:current|legacy)',\s*rank:\s*(\d)(,\s*assistantOnly:\s*true)?\s*\}/", $src, $m, PREG_SET_ORDER);
        $this->assertNotEmpty($m, 'could not parse REFEREE_GRADE_OPTIONS');
        $out = [];
        foreach ($m as $row) {
            $out[$row[1]] = (int) $row[2];
        }
        return $out;
    }

    public function testTheOptionListsMatch(): void
    {
        $this->assertSame(TE_REFEREE_GRADES, array_keys($this->tsRanks()), 'same values, same order, in both files');
    }

    public function testEveryOptionHasTheSameRankOnBothSides(): void
    {
        foreach ($this->tsRanks() as $value => $rank) {
            $this->assertSame($rank, te_referee_grade_rank($value), "rank of {$value} differs between PHP and TS");
        }
    }

    /** The assistant-only flag is on the same two grades on both sides. */
    public function testAssistantOnlyGradesMatch(): void
    {
        $src = file_get_contents(self::TS);
        preg_match_all("/value:\s*'([^']+)'[^}]*assistantOnly:\s*true/", $src, $m);
        $this->assertSame(TE_REFEREE_ASSISTANT_ONLY_GRADES, array_map('strtolower', $m[1]));
        foreach ($m[1] as $g) {
            $this->assertTrue(te_referee_grade_is_assistant_only($g));
        }
        $this->assertFalse(te_referee_grade_is_assistant_only('National'));
    }

    public function testTheGameMinimumsAreTheFourNamedGrades(): void
    {
        $src = file_get_contents(self::TS);
        preg_match("/GAME_MIN_GRADES\s*=\s*\[([^\]]*)\]/", $src, $m);
        $this->assertNotEmpty($m);
        preg_match_all("/'([^']+)'/", $m[1], $vals);
        $this->assertSame(TE_GAME_MIN_GRADES, $vals[1]);
    }

    public function testTheLegacyMappingIsTheDocumentedOne(): void
    {
        // 9–7 ≈ Grassroots, 6–5 ≈ Regional, 4–3 ≈ National, 2–1 ≈ Professional
        $expected = ['Grade 9' => 1, 'Grade 8' => 1, 'Grade 7' => 1, 'Grade 6' => 2, 'Grade 5' => 2,
                     'Grade 4' => 3, 'Grade 3' => 3, 'Grade 2' => 4, 'Grade 1' => 4];
        foreach ($expected as $g => $rank) {
            $this->assertSame($rank, te_referee_grade_rank($g), $g);
        }
        $this->assertNull(te_referee_grade_rank('Other'));
        $this->assertNull(te_referee_grade_rank(''));
        $this->assertNull(te_referee_grade_rank(null));
    }
}
