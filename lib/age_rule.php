<?php
/**
 * The age rule — one seasonal calendar for the whole product.
 *
 * DECISION (Maggie, 2026-09-02): "The age matrix runs from August 1 to July 31,
 * replacing the previous January 1 to December 31 calendar birth-year mandate."
 * One rule everywhere; there is deliberately no per-club setting.
 *
 * This file is the PHP half. `frontend/src/utils/ageGroup.ts` is the TypeScript
 * half and is the REFERENCE — it shipped first and its answers are what the
 * staff app already renders, so where the two could disagree the PHP moved.
 * The pair is pinned to a shared data file, `tests/fixtures/age-rule-cases.json`,
 * exercised by `tests/php/AgeRuleTest.php` and
 * `frontend/src/utils/ageGroup.fixture.test.ts`. Change one side and the other
 * suite fails on the same data.
 *
 * ⚠️ EVERY function here reads year / month / day off the date STRING and never
 * builds a `DateTime`, calls `strtotime()`, or otherwise lets a timezone near a
 * date-only value. This is the same rule CLAUDE.md states for the frontend, and
 * it is not theoretical here either: `date('Y', strtotime('2026-01-01'))` is
 * fine, but the moment anything in the chain normalises to UTC, a Jan-1 birthday
 * reads as Dec 31 of the prior year and the athlete lands in the wrong U-group.
 * The season boundary makes it worse, not better — Aug 1 is now a boundary too,
 * so a one-day shift moves a player a whole season year.
 *
 * A value that cannot be read returns null rather than a guess. Callers decide
 * what an unknown age means for them; this file never invents one.
 */

if (!function_exists('te_date_parts')) {
    /**
     * [year, month, day] from a date-only or timestamp string, or null.
     *
     * Accepts 'YYYY-MM-DD' and anything that begins with it ('2026-08-01
     * 00:00:00', '2026-08-01T00:00:00Z'), because that is what Postgres hands
     * back for `date` and `timestamp` columns. Anything else is refused — a
     * format we do not recognise is not a date to guess at.
     */
    function te_date_parts(?string $value): ?array
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || $value === 'null' || $value === 'undefined') {
            return null;
        }
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m)) {
            return null;
        }

        $year  = (int) $m[1];
        $month = (int) $m[2];
        $day   = (int) $m[3];

        if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return null;
        }

        return [$year, $month, $day];
    }
}

if (!function_exists('te_season_year')) {
    /**
     * The season year that CONTAINS $onDate.
     *
     *   Aug 1 – Dec 31  ->  calendar year + 1   (2026-08-01 -> 2027)
     *   Jan 1 – Jul 31  ->  calendar year       (2026-07-31 -> 2026)
     *
     * The season is named for the year it ENDS in, which is what makes
     * "U12 in 2027" mean one thing on both sides of New Year's Day.
     *
     * Mirrors `currentSeasonYear()` in frontend/src/utils/ageGroup.ts, whose
     * test is `now.getMonth() >= 7` — month 7 is August, zero-based.
     *
     * @return int|null null when the date cannot be read.
     */
    function te_season_year(?string $onDate): ?int
    {
        $parts = te_date_parts($onDate);
        if ($parts === null) {
            return null;
        }
        [$year, $month] = $parts;

        return $month >= 8 ? $year + 1 : $year;
    }
}

if (!function_exists('te_age_group')) {
    /**
     * The U-label ("U12") for a DOB as of $onDate, or null.
     *
     * Grouping is by birth YEAR against the season year, not by birthday:
     * `seasonYear - birthYear`. Everyone born in the same calendar year is in
     * the same group for the season, which is what "the age matrix" means.
     *
     * Returns null outside 4–25 — mirrors the TS clamp, which exists so a junk
     * DOB (1900, or a typo'd 2099) renders as "no age group" rather than as a
     * confident "U126".
     */
    function te_age_group(?string $dob, ?string $onDate): ?string
    {
        $birth = te_date_parts($dob);
        $seasonYear = te_season_year($onDate);
        if ($birth === null || $seasonYear === null) {
            return null;
        }

        $n = $seasonYear - $birth[0];
        if ($n < 4 || $n > 25) {
            return null;
        }

        return 'U' . $n;
    }
}

if (!function_exists('te_age_in_years')) {
    /**
     * Age in whole years on $onDate — an ordinary birthday calculation, NOT the
     * season-year rule.
     *
     * Deliberately separate from te_age_group(): "how old is this child today"
     * and "which group do they play in this season" are different questions and
     * conflating them is what put quarter-boundary births in the wrong bucket
     * before ageGroup.ts consolidated the copies.
     *
     * @return int|null null when either date cannot be read.
     */
    function te_age_in_years(?string $dob, ?string $onDate): ?int
    {
        $birth = te_date_parts($dob);
        $on    = te_date_parts($onDate);
        if ($birth === null || $on === null) {
            return null;
        }

        [$by, $bm, $bd] = $birth;
        [$oy, $om, $od] = $on;

        $age = $oy - $by;
        if ($om < $bm || ($om === $bm && $od < $bd)) {
            $age--;
        }

        return $age;
    }
}

if (!function_exists('te_normalize_age_group')) {
    /**
     * One canonical spelling for a U-group label: "U12".
     *
     * ⚠️ `teams.age_group` is free text and production holds several shapes —
     * 'U12', 'U-12', 'u 12', '12U'. Comparing an athlete's derived 'U12' against
     * a stored '12U' with `===` matches nothing and silently empties a coach's
     * list, which is indistinguishable from "no registrants in your group". So
     * BOTH sides of every comparison go through this function, and there is
     * exactly one of it.
     *
     * Anything that is not a single U-group returns null: 'Open', 'Adult',
     * 'U10/U11' (a genuinely ambiguous value we must not silently resolve to
     * one of its halves), and the empty string.
     *
     * No 4–25 clamp here on purpose — this answers "how is this label spelled",
     * not "is this a plausible youth age". te_age_group() owns the clamp.
     */
    function te_normalize_age_group(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $s = strtoupper(trim($raw));
        if ($s === '') {
            return null;
        }
        // Collapse internal whitespace and separators used as spacing only.
        $s = preg_replace('/[\s_]+/', '', $s);

        // "U12", "U-12", "UNDER12"
        if (preg_match('/^U(?:NDER)?-?(\d{1,2})$/', $s, $m)) {
            $n = (int) $m[1];
            return $n > 0 ? 'U' . $n : null;
        }
        // "12U", "12-U"
        if (preg_match('/^(\d{1,2})-?U$/', $s, $m)) {
            $n = (int) $m[1];
            return $n > 0 ? 'U' . $n : null;
        }

        return null;
    }
}
