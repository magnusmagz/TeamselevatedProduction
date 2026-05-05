<?php
/**
 * AgeEligibilityService
 *
 * Decides whether an athlete with a given date_of_birth is eligible for a
 * given division (e.g., U10) under the rules of the tournament's governing
 * body. Modern USYS / US Club / AYSO have all converged on a calendar-year
 * birth-year format: for U-N during season Y, the player must be born in
 * year (Y - N) or later. The "season year" for a tournament is the year
 * of its start_date.
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

        $seasonTs = strtotime($seasonStart);
        if ($seasonTs === false) return $defaultOk;
        $seasonYear = (int)date('Y', $seasonTs);

        // Birth-year rule (modern standard, used by USYS, US Club, and
        // current-era AYSO). max_birth_year is the EARLIEST acceptable
        // birth year — players born BEFORE Jan 1 of this year are too old.
        $maxBirthYear = $seasonYear - $ageNumber;

        $dobTs = strtotime($dob);
        if ($dobTs === false) return $defaultOk;
        $dobYear = (int)date('Y', $dobTs);

        $ageAtSeasonEnd = $seasonYear - $dobYear; // approx; off by a few months — sufficient for warning copy

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
