<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/age_rule.php';
require_once __DIR__ . '/../../services/AgeEligibilityService.php';

/**
 * The age rule, PHP half.
 *
 * DECISION (Maggie, 2026-09-02): the age matrix runs 1 Aug – 31 Jul. Before
 * this, the frontend rolled the season year on 1 Aug and
 * AgeEligibilityService used the tournament start_date's calendar year with no
 * roll, so the two halves of the product disagreed by a whole year for five
 * months of every season. CLAUDE.md recorded that as an open rules decision.
 *
 * ⚠️ The interesting part of this file is the FIXTURE, not the assertions.
 * `tests/fixtures/age-rule-cases.json` is read by this test and by
 * `frontend/src/utils/ageGroup.fixture.test.ts`. Two implementations agreeing
 * with each other is worth nothing if each is checked against its own numbers;
 * they are checked against the same numbers.
 */
class AgeRuleTest extends TestCase
{
    /** @return array<int, array<string, mixed>> */
    private function cases(): array
    {
        $path = __DIR__ . '/../fixtures/age-rule-cases.json';
        $this->assertFileExists($path);
        $cases = json_decode(file_get_contents($path), true);

        $this->assertIsArray($cases);
        $this->assertNotEmpty($cases, 'The shared fixture must not be empty.');

        return $cases;
    }

    public function testEveryFixtureCaseAgrees(): void
    {
        foreach ($this->cases() as $i => $case) {
            $where = sprintf(
                'case %d (dob %s, on %s): %s',
                $i,
                $case['dob'],
                $case['on_date'],
                $case['note'] ?? ''
            );

            $this->assertSame($case['season_year'], te_season_year($case['on_date']), "season year, $where");
            $this->assertSame($case['age_group'], te_age_group($case['dob'], $case['on_date']), "age group, $where");
            $this->assertSame($case['age_years'], te_age_in_years($case['dob'], $case['on_date']), "age in years, $where");
        }
    }

    /**
     * The fixture is only a guard while it actually covers the boundaries. A
     * case list that quietly loses 1 Aug still passes every assertion above.
     */
    public function testTheFixtureCoversTheBoundariesItExistsFor(): void
    {
        $onDates = array_column($this->cases(), 'on_date');
        $dobs    = array_column($this->cases(), 'dob');

        foreach (['2026-07-31', '2026-08-01', '2026-01-01', '2025-12-31'] as $needed) {
            $this->assertContains($needed, $onDates, "The fixture must evaluate a case on $needed.");
        }
        $this->assertContains('2016-02-29', $dobs, 'The fixture must carry a leap-day DOB.');

        foreach (['-01-01', '-04-01', '-07-01', '-10-01'] as $suffix) {
            $matches = array_filter($dobs, fn($d) => str_ends_with($d, $suffix));
            $this->assertNotEmpty($matches, "The fixture must carry a DOB on a quarter boundary ($suffix).");
        }
    }

    /**
     * The boundary itself, stated once in the form the decision was written in.
     */
    public function testTheSeasonRunsAugustToJuly(): void
    {
        $this->assertSame(2026, te_season_year('2026-01-01'));
        $this->assertSame(2026, te_season_year('2026-07-31'), '31 Jul is the LAST day of season 2026.');
        $this->assertSame(2027, te_season_year('2026-08-01'), '1 Aug is the FIRST day of season 2027.');
        $this->assertSame(2027, te_season_year('2026-12-31'));
    }

    /**
     * A timestamp is read by its date prefix; anything unrecognised is null,
     * never a guess. `strtotime()` would happily answer for 'tomorrow'.
     */
    public function testUnreadableValuesAreNullRatherThanGuesses(): void
    {
        $this->assertSame(2027, te_season_year('2026-08-01 00:00:00'));
        $this->assertSame(2027, te_season_year('2026-08-01T13:45:00Z'));

        foreach ([null, '', 'null', 'undefined', 'tomorrow', '08/01/2026', '2026-13-01'] as $junk) {
            $this->assertNull(te_season_year($junk), var_export($junk, true) . ' must not resolve to a season year.');
        }
        $this->assertNull(te_age_group(null, '2026-08-01'));
        $this->assertNull(te_age_group('2014-06-15', null));
        $this->assertNull(te_age_in_years('', '2026-08-01'));
    }

    /**
     * ⚠️ The whole reason this file parses strings. In a US timezone a
     * date-only value parsed as UTC midnight reads back as the PREVIOUS day,
     * which moves 1 Jan into the prior birth year and 1 Aug across the season
     * boundary. Run under a zone where that would show.
     */
    public function testTheAnswersDoNotMoveWithTheTimezone(): void
    {
        $original = date_default_timezone_get();
        try {
            foreach (['UTC', 'America/Chicago', 'Pacific/Auckland'] as $zone) {
                date_default_timezone_set($zone);
                $this->assertSame(2027, te_season_year('2026-08-01'), "season boundary in $zone");
                $this->assertSame('U11', te_age_group('2015-01-01', '2026-07-31'), "1 Jan DOB in $zone");
                $this->assertSame(11, te_age_in_years('2015-01-01', '2026-07-31'), "1 Jan age in $zone");
            }
        } finally {
            date_default_timezone_set($original);
        }
    }

    /**
     * `teams.age_group` is free text. Both sides of every comparison go through
     * one function, or a U12 coach matches nothing and it looks like an empty
     * tryout rather than a broken filter.
     */
    public function testAgeGroupLabelsNormalise(): void
    {
        foreach (['U12', 'u12', 'U-12', 'u 12', '12U', '12-U', ' U12 ', 'Under 12', 'UNDER-12'] as $spelling) {
            $this->assertSame('U12', te_normalize_age_group($spelling), "'$spelling' must normalise to U12.");
        }

        foreach ([null, '', 'Open', 'Adult', 'U10/U11', 'Recreational', 'U'] as $notAGroup) {
            $this->assertNull(
                te_normalize_age_group($notAGroup),
                var_export($notAGroup, true) . ' is not a single age group and must not resolve to one.'
            );
        }
    }

    // ------------------------------------------------------------------
    // AgeEligibilityService — the year derivation, and nothing else.
    // ------------------------------------------------------------------

    /**
     * The behaviour change. A tournament starting 15 Aug 2026 is season 2027,
     * so its U10 cutoff is birth year 2017. The old code said 2016 and let a
     * player in who the staff app was already labelling U11.
     */
    public function testATournamentStartingInAugustUsesTheNextSeasonYear(): void
    {
        $svc = new AgeEligibilityService();

        $check = $svc->check('U10', '2016-03-04', '2026-08-15', 'usys');
        $this->assertSame(2017, $check['max_birth_year']);
        $this->assertFalse($check['eligible'], 'Born 2016 is over the U10 cutoff for season 2027.');
        $this->assertSame(11, $check['age_at_season_end']);

        $inRange = $svc->check('U10', '2017-03-04', '2026-08-15', 'usys');
        $this->assertTrue($inRange['eligible']);
        $this->assertSame(2017, $inRange['max_birth_year']);
    }

    /**
     * The day before the boundary is unchanged from the old rule, which is what
     * makes this a narrow change rather than a re-grading of every tournament.
     */
    public function testATournamentStartingBeforeAugustIsUnchanged(): void
    {
        $svc = new AgeEligibilityService();

        $check = $svc->check('U10', '2016-03-04', '2026-07-31', 'usys');
        $this->assertSame(2016, $check['max_birth_year']);
        $this->assertTrue($check['eligible']);
    }

    /**
     * A start_date of 1 Jan must not be dragged back to 31 Dec of the prior
     * year by a timezone. That would be a season year out on the busiest
     * possible date.
     */
    public function testTheStartDateIsReadWithoutATimezone(): void
    {
        $svc = new AgeEligibilityService();
        $original = date_default_timezone_get();
        try {
            foreach (['UTC', 'America/Chicago', 'Pacific/Auckland'] as $zone) {
                date_default_timezone_set($zone);
                $this->assertSame(2016, $svc->check('U10', '2010-06-01', '2026-01-01', null)['max_birth_year'], $zone);
                $this->assertSame(2017, $svc->check('U10', '2010-06-01', '2026-08-01', null)['max_birth_year'], $zone);
            }
        } finally {
            date_default_timezone_set($original);
        }
    }

    /**
     * The per-body rule-set structure is untouched — only the year derivation
     * changed. `state` and the rest still label their own message.
     */
    public function testGoverningBodyLabellingSurvives(): void
    {
        $svc = new AgeEligibilityService();

        $this->assertStringContainsString(
            'state association',
            $svc->check('U10', '2010-06-01', '2026-08-15', 'state')['reason']
        );
        $this->assertStringContainsString(
            'US Club',
            $svc->check('U10', '2010-06-01', '2026-08-15', 'us_club')['reason']
        );
        $this->assertStringContainsString(
            'standard rule',
            $svc->check('U10', '2010-06-01', '2026-08-15', null)['reason']
        );
    }

    /**
     * Unknown inputs stay "no warning", not "ineligible". A tournament with a
     * mixed division must not start refusing players.
     */
    public function testUnknownRulesStillWarnAboutNothing(): void
    {
        $svc = new AgeEligibilityService();

        foreach ([['U10/U11', '2010-06-01'], ['Open', '2010-06-01'], ['U10', 'not-a-date']] as [$group, $dob]) {
            $check = $svc->check($group, $dob, '2026-08-15', 'usys');
            $this->assertTrue($check['eligible']);
            $this->assertNull($check['reason']);
        }
    }
}
