/**
 * Timestamp formatting on the notification health screen.
 *
 * ⚠️ Postgres returns an offset of `+00`, which is NOT valid ISO 8601 — that
 * requires `+00:00`. `new Date('2026-08-28T03:59:26+00')` is Invalid Date, so
 * the formatter fell through and printed the raw timestamp. An admin saw
 * "nothing has been notified since 2026-08-28 03:59:26+00" on 2026-08-28.
 *
 * The function is duplicated here rather than exported, because it is a private
 * display detail of the component; what matters is that the PARSING rule is
 * pinned. If this drifts from the component the test is worthless — so keep them
 * together.
 */
function ago(iso: string | null, now: number): string {
  if (!iso) return 'never';

  let normalised = iso.trim().replace(' ', 'T');
  normalised = normalised.replace(/([+-])(\d{2})$/, '$1$2:00');
  if (!/([+-]\d{2}:\d{2}|Z)$/.test(normalised)) normalised += 'Z';

  const then = new Date(normalised).getTime();
  if (Number.isNaN(then)) return iso;

  const mins = Math.max(0, Math.floor((now - then) / 60000));
  if (mins < 1) return 'just now';
  if (mins < 60) return `${mins}m ago`;
  if (mins < 1440) return `${Math.floor(mins / 60)}h ago`;
  return `${Math.floor(mins / 1440)}d ago`;
}

const NOW = Date.parse('2026-08-28T06:00:00Z');

describe('notification health timestamps', () => {
  /** The exact production string that failed. */
  it('parses the +00 offset Postgres actually returns', () => {
    expect(ago('2026-08-28 03:59:26+00', NOW)).toBe('2h ago');
  });

  it('parses fractional seconds with a bare offset', () => {
    expect(ago('2026-08-28 03:54:18.96879+00', NOW)).toBe('2h ago');
  });

  it('parses a proper ISO offset', () => {
    expect(ago('2026-08-28T03:59:26+00:00', NOW)).toBe('2h ago');
  });

  it('parses a naive timestamp as UTC', () => {
    expect(ago('2026-08-28 03:59:26', NOW)).toBe('2h ago');
  });

  it('parses a non-zero offset correctly', () => {
    // 01:00 at -05:00 is 06:00 UTC — the same instant as NOW.
    expect(ago('2026-08-28 01:00:00-05', NOW)).toBe('just now');
  });

  it('scales from minutes to days', () => {
    expect(ago('2026-08-28 05:30:00+00', NOW)).toBe('30m ago');
    expect(ago('2026-08-26 06:00:00+00', NOW)).toBe('2d ago');
  });

  it('says never rather than guessing when there is nothing', () => {
    expect(ago(null, NOW)).toBe('never');
  });

  /** Garbage still shows something rather than crashing the panel. */
  it('falls back to the raw value it cannot parse', () => {
    expect(ago('not a date', NOW)).toBe('not a date');
  });
});
