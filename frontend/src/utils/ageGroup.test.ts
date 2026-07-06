import { ageGroup, birthYearOf, currentSeasonYear, ageInYears } from './ageGroup';

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
});
