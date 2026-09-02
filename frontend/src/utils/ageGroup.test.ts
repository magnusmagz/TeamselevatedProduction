import { ageGroup, birthYearOf, currentSeasonYear, ageInYears, ageQuarter } from './ageGroup';

describe('ageGroup (calendar-year / birth-year)', () => {
  // 2025-26 season → season year 2026. Local constructor = timezone-independent.
  const now = new Date(2026, 2, 15); // Mar 15, 2026

  it('reads birth year from the string with no timezone shift (the Jan-1 bug)', () => {
    expect(birthYearOf('2014-01-01')).toBe(2014); // the bug returned 2013 in the Americas
    expect(birthYearOf('2014-12-31')).toBe(2014);
    expect(birthYearOf('2014-06-15T00:00:00Z')).toBe(2014);
    expect(birthYearOf('')).toBeNull();
    expect(birthYearOf(null)).toBeNull();
    expect(birthYearOf('null')).toBeNull();
  });

  it('groups purely by birth year (a Jan-1 birth is NOT bumped a group)', () => {
    expect(ageGroup('2014-01-01', now)).toBe('U12'); // 2026 - 2014 — not U13
    expect(ageGroup('2014-12-31', now)).toBe('U12'); // same year → same group — not U11
    expect(ageGroup('2013-06-15', now)).toBe('U13');
    expect(ageGroup('', now)).toBeNull();
  });

  it('season year uses the Aug–Jul ending year', () => {
    expect(currentSeasonYear(new Date(2025, 8, 15))).toBe(2026); // September
    expect(currentSeasonYear(new Date(2026, 2, 15))).toBe(2026); // March
    expect(currentSeasonYear(new Date(2026, 7, 1))).toBe(2027);  // Aug 1 rolls to next
  });

  it('ageInYears is timezone-safe on Jan 1', () => {
    expect(ageInYears('2014-01-01', new Date(2026, 2, 15))).toBe(12);
    expect(ageInYears('2014-06-01', new Date(2026, 2, 15))).toBe(11); // birthday not yet reached
  });

  it('ageInYears does not age a child up a day early (the day-before-birthday case)', () => {
    // Born Sep 3 2010; on Sep 2 2026 the birthday has NOT happened yet.
    // The old per-component calcAge parsed the DOB as UTC midnight, which in
    // the Americas renders as Sep 2 — so it returned 16 a day early.
    expect(ageInYears('2010-09-03', new Date(2026, 8, 2))).toBe(15);
    expect(ageInYears('2010-09-03', new Date(2026, 8, 3))).toBe(16); // on the day
    expect(ageInYears('', new Date(2026, 8, 2))).toBeNull();
    expect(ageInYears(null, new Date(2026, 8, 2))).toBeNull();
  });

  it('ageQuarter reads the month from the string, so every quarter-boundary first is right', () => {
    // All four were wrong before: `new Date('2015-01-01').getMonth()` is
    // December of the PRIOR year in any US timezone, and the same shift moves
    // Apr 1 -> Mar, Jul 1 -> Jun, Oct 1 -> Sep.
    expect(ageQuarter('2015-01-01')).toBe('Q1');
    expect(ageQuarter('2015-04-01')).toBe('Q2');
    expect(ageQuarter('2015-07-01')).toBe('Q3');
    expect(ageQuarter('2015-10-01')).toBe('Q4');
  });

  it('ageQuarter maps the rest of each quarter (control cases)', () => {
    expect(ageQuarter('2015-02-15')).toBe('Q1'); // mid-quarter control
    expect(ageQuarter('2015-03-31')).toBe('Q1');
    expect(ageQuarter('2015-06-30')).toBe('Q2');
    expect(ageQuarter('2015-09-30')).toBe('Q3');
    expect(ageQuarter('2015-12-31')).toBe('Q4');
  });

  it('ageQuarter returns null for a missing or unreadable DOB', () => {
    expect(ageQuarter('')).toBeNull();
    expect(ageQuarter(null)).toBeNull();
    expect(ageQuarter(undefined)).toBeNull();
    expect(ageQuarter('null')).toBeNull();
    expect(ageQuarter('not-a-date')).toBeNull();
  });
});
