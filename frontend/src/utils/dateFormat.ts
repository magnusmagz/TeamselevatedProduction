/**
 * Format a date-only value on its own calendar day, regardless of the viewer's
 * timezone.
 *
 * Postgres `date` columns (program start/end, tryout session dates) serialize as
 * "YYYY-MM-DD". `new Date("2026-11-07")` parses that as UTC midnight, so a
 * local-timezone formatter shifts it back a day in the Americas — a program set
 * for Nov 7 shows as "Nov 6". Formatting in UTC keeps it on the stored day.
 *
 * Use this for values that represent a calendar date with no time-of-day. Do NOT
 * use it for real timestamps (created_at, etc.) where the local time matters.
 */
export function formatDateOnly(
  dateString: string | null | undefined,
  options: Intl.DateTimeFormatOptions = { year: 'numeric', month: 'short', day: 'numeric' }
): string {
  if (!dateString) return '';
  const d = new Date(dateString);
  if (isNaN(d.getTime())) return '';
  return d.toLocaleDateString('en-US', { ...options, timeZone: 'UTC' });
}

/**
 * Parse a "YYYY-MM-DD" date-only string into a Date sitting at LOCAL midnight.
 *
 * `new Date("2026-08-25")` lands on UTC midnight, which in the Americas is the
 * evening of the 24th — so `.getDay()` on it answers for the wrong calendar day.
 * Building from components keeps the Date on the day the string names.
 *
 * Use this whenever you need to ask a date-only value a calendar question
 * (day-of-week, iterate day by day). For DISPLAY, use `formatDateOnly`.
 */
export function parseDateOnly(dateString: string): Date | null {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(dateString ?? '');
  if (!m) return null;
  const d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
  return isNaN(d.getTime()) ? null : d;
}

/**
 * Format a Date back to "YYYY-MM-DD" using its LOCAL calendar day.
 *
 * The counterpart to `parseDateOnly`. `toISOString().split('T')[0]` reads the
 * UTC day instead, which is a different day for most of the evening in the
 * Americas — pairing it with a local `getDay()` is what put Tuesday practices
 * on Wednesday (see PracticeScheduler).
 */
export function toDateOnlyString(date: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}
