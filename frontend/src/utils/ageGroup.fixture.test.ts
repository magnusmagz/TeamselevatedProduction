import { ageGroup, ageInYears, currentSeasonYear } from './ageGroup';

/**
 * The age rule, TypeScript half — checked against the SAME data file as the PHP
 * half (`tests/php/AgeRuleTest.php` reads `tests/fixtures/age-rule-cases.json`
 * too).
 *
 * DECISION (Maggie, 2026-09-02): the age matrix runs 1 Aug – 31 Jul. This file
 * already rolled the season year on 1 Aug; `services/AgeEligibilityService.php`
 * used the tournament start_date's calendar year with no roll, so the two
 * halves of the product disagreed by a whole year for five months of every
 * season. CLAUDE.md carried that as an open rules decision. The PHP moved to
 * match this file, and the fixture is what stops either side drifting again.
 *
 * ⚠️ The fixture is read at RUNTIME with `fs`, not `import`ed. A JSON import
 * from outside `src/` fails CRA's module scope in the production build even
 * though jest resolves it happily — the failure would be a broken deploy, not a
 * red test. Same reason `sameUser.test.ts` requires its files.
 */

interface AgeRuleCase {
  note?: string;
  dob: string;
  on_date: string;
  season_year: number;
  age_group: string | null;
  age_years: number | null;
}

/**
 * ⚠️ `new Date('2026-08-01')` is UTC midnight, which in America/Chicago — the
 * zone this suite is pinned to — is 31 Jul in local time, the wrong side of the
 * season boundary. The fixture's `on_date` is a date-only string and must be
 * built from its parts as a LOCAL date, exactly as `parseDateOnly` does. Every
 * assertion below is a no-op against a `now` that is a day out.
 */
function localDateOnly(value: string): Date {
  const [year, month, day] = value.split('-').map((part) => parseInt(part, 10));
  return new Date(year, month - 1, day);
}

function loadCases(): AgeRuleCase[] {
  const fs = require('fs');
  const path = require('path');
  const fixture = path.join(__dirname, '..', '..', '..', 'tests', 'fixtures', 'age-rule-cases.json');
  return JSON.parse(fs.readFileSync(fixture, 'utf8'));
}

describe('the shared age-rule fixture', () => {
  const cases = loadCases();

  it('is not empty', () => {
    expect(cases.length).toBeGreaterThan(0);
  });

  it.each(cases.map((c) => [`${c.dob} on ${c.on_date} — ${c.note ?? ''}`, c] as const))(
    '%s',
    (_label, testCase) => {
      const now = localDateOnly(testCase.on_date);

      expect(currentSeasonYear(now)).toBe(testCase.season_year);
      expect(ageGroup(testCase.dob, now)).toBe(testCase.age_group);
      expect(ageInYears(testCase.dob, now)).toBe(testCase.age_years);
    }
  );

  /**
   * The guard on the guard. A fixture that quietly loses 1 Aug still passes
   * every case above while covering nothing that matters.
   */
  it('covers both sides of the season boundary, New Year and a leap day', () => {
    const onDates = cases.map((c) => c.on_date);
    const dobs = cases.map((c) => c.dob);

    expect(onDates).toContain('2026-07-31');
    expect(onDates).toContain('2026-08-01');
    expect(onDates).toContain('2026-01-01');
    expect(dobs).toContain('2016-02-29');

    for (const suffix of ['-01-01', '-04-01', '-07-01', '-10-01']) {
      expect(dobs.some((d) => d.endsWith(suffix))).toBe(true);
    }
  });

  /**
   * The boundary stated once, in the form the decision was written in — so a
   * reader does not have to infer it from the case list.
   */
  it('rolls the season year on 1 August', () => {
    expect(currentSeasonYear(localDateOnly('2026-07-31'))).toBe(2026);
    expect(currentSeasonYear(localDateOnly('2026-08-01'))).toBe(2027);
    expect(currentSeasonYear(localDateOnly('2026-12-31'))).toBe(2027);
    expect(currentSeasonYear(localDateOnly('2027-01-01'))).toBe(2027);
  });
});
