<?php

require_once __DIR__ . '/../lib/age_rule.php';

/**
 * AgeEligibilityService
 *
 * Decides whether an athlete with a given date_of_birth is eligible for a
 * given division (e.g., U10) under the rules of the tournament's governing
 * body. Modern USYS / US Club / AYSO have all converged on a birth-year
 * format: for U-N during season Y, the player must be born in year (Y - N)
 * or later.
 *
 * ⚠️ The SEASON YEAR is `te_season_year()` (lib/age_rule.php), not the
 * calendar year of start_date. DECISION (Maggie, 2026-09-02): "The age matrix
 * runs from August 1 to July 31, replacing the previous January 1 to
 * December 31 calendar birth-year mandate." So a tournament starting
 * 2026-08-15 is season 2027 and its U10 cutoff is birth year 2017 — under the
 * old code it was season 2026 and 2016, one year looser, and it disagreed
 * with the U-group the staff app had been showing for that same athlete since
 * frontend/src/utils/ageGroup.ts shipped. The two halves now share
 * tests/fixtures/age-rule-cases.json.
 *
 * Birth-year cutoff math (the modern standard):
 *   max_birth_year = season_year - age_number
 *   eligible iff date_of_birth >= January 1 of (max_birth_year)
 *
 * Special cases handled here:
 *   - Age groups like "U6", "U8", ..., "U19" are parsed for their integer.
 *   - Mixed forms ("U10/U11", "Open") return null (unknown rule), which the
 *     caller treats as "no warning".
 *   - 'unaffiliated' or NULL governing_body uses the modern birth-year rule
 *     as a sensible default.
 *   - 'aaau' / state associations also default to the same rule unless they
 *     override here in the future. Adding a body-specific branch is a
 *     one-liner.
 *
 * Returns a small struct so callers (the roster-add endpoint, the roster
 * modal) can render a clear "warning, not block" message.
 */
class AgeEligibilityService {

    /**
     * @param string|null $ageGroup  e.g. "U10", "U13"
     * @param string|null $dob       ISO date 'YYYY-MM-DD'
     * @param string|null $seasonStart  ISO date — usually tournament.start_date
     * @param string|null $governingBody — 'usys' | 'us_club' | 'ayso' | 'aau' | 'state' | 'unaffiliated' | null
     *
     * @return array {
     *   eligible: bool,
     *   reason: string|null,
     *   max_birth_year: int|null,
     *   age_at_season_end: int|null,
     * }
     */
    public function check(?string $ageGroup, ?string $dob, ?string $seasonStart, ?string $governingBody): array {
        $defaultOk = ['eligible' => true, 'reason' => null, 'max_birth_year' => null, 'age_at_season_end' => null];

        if (!$ageGroup || !$dob || !$seasonStart) {
            return $defaultOk;
        }

        // Parse the age number out of "U10" / "u-12" / "U 14" etc.
        if (!preg_match('/^\s*[uU]\s*[-]?\s*(\d{1,2})\s*$/', $ageGroup, $m)) {
            return $defaultOk;
        }
        $ageNumber = (int)$m[1];
        if ($ageNumber < 4 || $ageNumber > 25) {
            return $defaultOk;
        }

        // Aug 1 – Jul 31. Never strtotime()/date() on a date-only value: a
        // start_date of 2026-08-01 parsed as UTC and read back locally is
        // 2026-07-31, which is the wrong side of the boundary and therefore a
        // whole season year out.
        $seasonYear = te_season_year($seasonStart);
        if ($seasonYear === null) return $defaultOk;

        // Birth-year rule (modern standard, used by USYS, US Club, and
        // current-era AYSO). max_birth_year is the EARLIEST acceptable
        // birth year — players born BEFORE Jan 1 of this year are too old.
        $maxBirthYear = $seasonYear - $ageNumber;

        $dobParts = te_date_parts($dob);
        if ($dobParts === null) return $defaultOk;
        $dobYear = $dobParts[0];

        // The birth-year age, i.e. the U-number this player falls in for the
        // season — deliberately NOT te_age_in_years(), which answers the
        // different question "how old are they today".
        $ageAtSeasonEnd = $seasonYear - $dobYear;

        if ($dobYear < $maxBirthYear) {
            $bodyLabel = $this->bodyLabel($governingBody);
            return [
                'eligible'           => false,
                'reason'             => "Player born $dobYear is over the U$ageNumber cutoff ($bodyLabel: birth year $maxBirthYear or later)",
                'max_birth_year'     => $maxBirthYear,
                'age_at_season_end'  => $ageAtSeasonEnd,
            ];
        }

        return [
            'eligible'           => true,
            'reason'             => null,
            'max_birth_year'     => $maxBirthYear,
            'age_at_season_end'  => $ageAtSeasonEnd,
        ];
    }

    private function bodyLabel(?string $body): string {
        switch (strtolower((string)$body)) {
            case 'usys':         return 'USYS';
            case 'us_club':      return 'US Club';
            case 'ayso':         return 'AYSO';
            case 'aau':          return 'AAU';
            case 'state':        return 'state association';
            case 'unaffiliated': return 'standard rule';
            default:             return 'standard rule';
        }
    }
}
