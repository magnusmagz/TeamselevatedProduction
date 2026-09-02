import {
  deriveDocumentStatus,
  daysUntilExpiry,
  formatDocumentDate,
  EXPIRING_SOON_DAYS,
} from './documentStatus';

/**
 * The suite runs pinned to America/Chicago (jest.globalSetup.js), which is what
 * makes the formatting assertions here mean anything: in UTC the whole
 * off-by-one class passes silently.
 *
 * `documents.expires_at` is TIMESTAMPTZ — an instant, not a calendar day — so
 * the comparisons deliberately use instant arithmetic. Display is the half that
 * was wrong: three components each did
 * `new Date(expires_at).toLocaleDateString()`, which renders in the viewer's
 * zone, so a value entered through the Document Center's date picker (submitted
 * as `YYYY-MM-DD`, stored as midnight UTC) showed a day early across the
 * Americas.
 */

const NOW = Date.parse('2026-09-02T12:00:00Z');
const DAY = 24 * 60 * 60 * 1000;

describe('deriveDocumentStatus', () => {
  it('treats a document with no expiry as valid', () => {
    expect(deriveDocumentStatus(null, NOW)).toBe('valid');
    expect(deriveDocumentStatus(undefined, NOW)).toBe('valid');
    expect(deriveDocumentStatus('', NOW)).toBe('valid');
  });

  it('flags a past expiry as expired', () => {
    expect(deriveDocumentStatus(new Date(NOW - DAY).toISOString(), NOW)).toBe('expired');
  });

  it('flags anything inside the window as expiring_soon', () => {
    expect(deriveDocumentStatus(new Date(NOW + DAY).toISOString(), NOW)).toBe('expiring_soon');
    expect(
      deriveDocumentStatus(new Date(NOW + (EXPIRING_SOON_DAYS - 1) * DAY).toISOString(), NOW)
    ).toBe('expiring_soon');
  });

  it('leaves anything beyond the window valid', () => {
    expect(
      deriveDocumentStatus(new Date(NOW + (EXPIRING_SOON_DAYS + 1) * DAY).toISOString(), NOW)
    ).toBe('valid');
  });

  /**
   * An unreadable date must not render a red "Expired" badge. Every copy of the
   * old helper produced NaN here, and `NaN < now` is false — so they happened to
   * answer 'valid' by accident. This makes it deliberate.
   */
  it('treats an unparseable date as valid rather than expired', () => {
    expect(deriveDocumentStatus('not-a-date', NOW)).toBe('valid');
  });
});

describe('daysUntilExpiry', () => {
  it('counts forward and backward across now', () => {
    expect(daysUntilExpiry(new Date(NOW + 7 * DAY).toISOString(), NOW)).toBe(7);
    expect(daysUntilExpiry(new Date(NOW - 3 * DAY).toISOString(), NOW)).toBe(-3);
  });

  it('returns 0 rather than NaN for an unreadable value', () => {
    expect(daysUntilExpiry('not-a-date', NOW)).toBe(0);
  });
});

describe('formatDocumentDate', () => {
  /**
   * THE DISPLAY BUG. In Chicago, `new Date('2026-09-30').toLocaleDateString()`
   * is "9/29/2026" — the day before the one stored.
   */
  it('keeps a midnight-UTC expiry on its stored calendar day', () => {
    expect(formatDocumentDate('2026-09-30T00:00:00Z')).toBe('Sep 30, 2026');
    expect(formatDocumentDate('2026-01-01T00:00:00Z')).toBe('Jan 1, 2026');
  });

  it('returns an empty string for a missing or unreadable value, never "Invalid Date"', () => {
    expect(formatDocumentDate(null)).toBe('');
    expect(formatDocumentDate(undefined)).toBe('');
    expect(formatDocumentDate('not-a-date')).toBe('');
  });
});
