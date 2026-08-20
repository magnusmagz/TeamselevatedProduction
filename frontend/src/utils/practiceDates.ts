import { parseDateOnly, toDateOnlyString } from './dateFormat';

/** Day-of-week names as the scheduler's checkboxes emit them. */
export const DAY_NAMES = [
  'sunday', 'monday', 'tuesday', 'wednesday',
  'thursday', 'friday', 'saturday',
] as const;

export type DayName = typeof DAY_NAMES[number];

export interface PracticeDate {
  /** "YYYY-MM-DD", the calendar day the practice lands on. */
  date: string;
  /** The selected weekday this date satisfies, e.g. "tuesday". */
  dayName: DayName;
}

/**
 * Expand a date range + set of weekdays into the calendar days to schedule.
 *
 * Extracted from PracticeScheduler so it can be tested under a real timezone.
 * The bug it exists to prevent: the original inline loop asked `getDay()` (LOCAL)
 * and then wrote the date out with `toISOString()` (UTC). Those disagree for the
 * whole evening in the Americas, so a coach who picked Tuesday got practices
 * stored on Wednesday. Central Kansas United hit this on six teams and repaired
 * them by hand before it was reported.
 *
 * Everything here stays on the local calendar day, start and finish.
 */
export function generatePracticeDates(
  startDate: string,
  endDate: string,
  selectedDays: string[]
): PracticeDate[] {
  const start = parseDateOnly(startDate);
  const end = parseDateOnly(endDate);
  if (!start || !end || selectedDays.length === 0) return [];

  const wanted = new Set(selectedDays);
  const out: PracticeDate[] = [];

  for (const cursor = new Date(start); cursor <= end; cursor.setDate(cursor.getDate() + 1)) {
    const dayName = DAY_NAMES[cursor.getDay()];
    if (wanted.has(dayName)) {
      out.push({ date: toDateOnlyString(cursor), dayName });
    }
  }

  return out;
}
