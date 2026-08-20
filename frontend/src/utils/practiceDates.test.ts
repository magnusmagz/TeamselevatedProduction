import { generatePracticeDates } from './practiceDates';
import { parseDateOnly, toDateOnlyString } from './dateFormat';

// These tests are only meaningful in a timezone BEHIND UTC, which is where the
// bug lives. jest.config sets TZ=America/Chicago for the same reason.
const dayOfWeekOf = (iso: string) =>
  new Date(`${iso}T12:00:00`).toLocaleDateString('en-US', { weekday: 'long' }).toLowerCase();

describe('generatePracticeDates', () => {
  it('is running in a timezone that can expose the bug', () => {
    // Guard: if TZ is UTC the assertions below pass even on the broken code.
    expect(new Date('2026-08-25').getDate()).toBe(24);
  });

  // The reported bug: coach picks Tuesday, practices land on Wednesday.
  // Central Kansas United, 5th-6th Purple, 2026-08-19.
  it('puts Tuesday practices on Tuesdays', () => {
    const dates = generatePracticeDates('2026-08-20', '2026-10-21', ['tuesday']);

    expect(dates.length).toBeGreaterThan(0);
    for (const { date } of dates) {
      expect(dayOfWeekOf(date)).toBe('tuesday');
    }
    expect(dates[0].date).toBe('2026-08-25');
  });

  it('every generated date actually falls on the weekday it claims', () => {
    const dates = generatePracticeDates('2026-01-01', '2026-12-31', [
      'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday',
    ]);

    expect(dates).toHaveLength(365);
    for (const { date, dayName } of dates) {
      expect(dayOfWeekOf(date)).toBe(dayName);
    }
  });

  it('includes the boundary days when the range starts or ends on a match', () => {
    // 2026-08-25 and 2026-09-01 are both Tuesdays.
    const dates = generatePracticeDates('2026-08-25', '2026-09-01', ['tuesday']);
    expect(dates.map(d => d.date)).toEqual(['2026-08-25', '2026-09-01']);
  });

  it('handles multiple weekdays and stays in chronological order', () => {
    const dates = generatePracticeDates('2026-08-24', '2026-09-06', ['tuesday', 'thursday']);
    expect(dates.map(d => d.date)).toEqual([
      '2026-08-25', '2026-08-27', '2026-09-01', '2026-09-03',
    ]);
  });

  it('crosses a DST boundary without dropping or duplicating a week', () => {
    // US DST ends 2026-11-01. Tuesdays either side must stay Tuesdays.
    const dates = generatePracticeDates('2026-10-20', '2026-11-17', ['tuesday']);
    expect(dates.map(d => d.date)).toEqual([
      '2026-10-20', '2026-10-27', '2026-11-03', '2026-11-10', '2026-11-17',
    ]);
    for (const { date } of dates) expect(dayOfWeekOf(date)).toBe('tuesday');
  });

  it('returns nothing for an empty selection, a backwards range, or bad input', () => {
    expect(generatePracticeDates('2026-08-20', '2026-10-21', [])).toEqual([]);
    expect(generatePracticeDates('2026-10-21', '2026-08-20', ['tuesday'])).toEqual([]);
    expect(generatePracticeDates('', '2026-10-21', ['tuesday'])).toEqual([]);
    expect(generatePracticeDates('not-a-date', '2026-10-21', ['tuesday'])).toEqual([]);
  });
});

describe('parseDateOnly / toDateOnlyString', () => {
  it('round-trips a date string without shifting the day', () => {
    for (const iso of ['2026-01-01', '2026-08-25', '2026-11-01', '2026-12-31']) {
      expect(toDateOnlyString(parseDateOnly(iso)!)).toBe(iso);
    }
  });

  it('parses to the local calendar day, unlike new Date()', () => {
    expect(parseDateOnly('2026-08-25')!.getDate()).toBe(25);
    expect(parseDateOnly('2026-08-25')!.getDay()).toBe(2); // Tuesday
    // The shape that caused the bug:
    expect(new Date('2026-08-25').getDay()).toBe(1); // reads as Monday locally
  });

  it('rejects input that is not YYYY-MM-DD', () => {
    expect(parseDateOnly('')).toBeNull();
    expect(parseDateOnly('8/25/2026')).toBeNull();
    expect(parseDateOnly('2026-08-25T00:00:00')).toBeNull();
  });
});
