import { formatDateOnly } from './dateFormat';

describe('formatDateOnly', () => {
  // Regression: a Postgres date "2026-11-07" must render as Nov 7 — NOT Nov 6,
  // which is what `new Date("2026-11-07").toLocaleDateString()` produces in the
  // Americas (UTC-midnight parse rendered in local time). Nov 7 2026 is a Saturday.
  it('keeps the stored calendar day (no timezone shift)', () => {
    expect(formatDateOnly('2026-11-07', { weekday: 'short', month: 'short', day: 'numeric' }))
      .toBe('Sat, Nov 7');
    expect(formatDateOnly('2026-11-07', { day: 'numeric' })).toBe('7');
    expect(formatDateOnly('2026-11-07', { month: 'short' })).toBe('Nov');
  });

  it('the rendered day-of-month always equals the input day', () => {
    for (const iso of ['2026-01-01', '2026-06-17', '2026-07-02', '2026-12-31']) {
      const expectedDay = String(parseInt(iso.split('-')[2], 10));
      expect(formatDateOnly(iso, { day: 'numeric' })).toBe(expectedDay);
    }
  });

  it('defaults to a readable "Mon D, YYYY" and handles empty input', () => {
    expect(formatDateOnly('2026-07-02')).toBe('Jul 2, 2026');
    expect(formatDateOnly('')).toBe('');
    expect(formatDateOnly(null)).toBe('');
    expect(formatDateOnly(undefined)).toBe('');
  });
});
