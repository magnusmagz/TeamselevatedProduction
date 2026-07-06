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
