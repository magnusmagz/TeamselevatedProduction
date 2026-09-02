import { formatDateOnly } from './dateFormat';

/**
 * The one answer to "is this document still good", and the one way to print a
 * document date.
 *
 * `deriveStatus` and a `formatDate` were copy-pasted into three components —
 * the parent portal's DocumentsPage, the staff DocumentManager and the
 * ExpirationDashboard — each calling `new Date(...)` directly and each free to
 * drift from the others. They already had: two of them called a value expiring
 * within 30 days "expiring soon", the third bucketed by 7/14/30 with its own
 * `daysUntil`, and only one of them handled a null.
 *
 * `documents.expires_at` is TIMESTAMPTZ, so it names an INSTANT, not a calendar
 * day. Comparing it against `Date.now()` is therefore correct and is what these
 * functions do — this is not the PracticeScheduler date-only bug.
 *
 * DISPLAY is the half that was wrong. `new Date(x).toLocaleDateString()` renders
 * in the viewer's zone, so a document whose expiry was entered through the date
 * picker in ClubDocumentCenter (which submits `YYYY-MM-DD`, stored as midnight
 * UTC) showed as the PREVIOUS day everywhere west of Greenwich — the same
 * off-by-one that put Tuesday practices on Wednesday. Formatting goes through
 * `formatDateOnly`, the shared formatter that keeps a value on its stored day.
 */

export type DocumentStatus = 'valid' | 'expiring_soon' | 'expired';

/** A document inside this many days of expiry is flagged, not yet expired. */
export const EXPIRING_SOON_DAYS = 30;

const MS_PER_DAY = 24 * 60 * 60 * 1000;

/**
 * Whole days from now until expiry. Negative once the document has expired,
 * so a caller can render "3 days ago" from the absolute value.
 *
 * `now` is injectable so tests can pin the clock rather than build dates
 * relative to whenever the suite happens to run.
 */
export function daysUntilExpiry(expiresAt: string, now: number = Date.now()): number {
  const exp = new Date(expiresAt).getTime();
  if (isNaN(exp)) return 0;
  return Math.ceil((exp - now) / MS_PER_DAY);
}

/**
 * A document with no expiry date is `valid` — it never expires, which is not
 * the same as "unknown". An unparseable date is also treated as `valid` rather
 * than `expired`: refusing to render a scary red badge over a value we could
 * not read is the safer failure.
 */
export function deriveDocumentStatus(
  expiresAt?: string | null,
  now: number = Date.now()
): DocumentStatus {
  if (!expiresAt) return 'valid';
  const exp = new Date(expiresAt).getTime();
  if (isNaN(exp)) return 'valid';
  if (exp < now) return 'expired';
  if (exp - now < EXPIRING_SOON_DAYS * MS_PER_DAY) return 'expiring_soon';
  return 'valid';
}

/**
 * Print a document timestamp on the calendar day it was stored on. Returns an
 * empty string for a null or unparseable value — never "Invalid Date".
 */
export function formatDocumentDate(value?: string | null): string {
  return formatDateOnly(value);
}
