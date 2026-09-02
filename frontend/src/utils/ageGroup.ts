/**
 * Age-group helpers — the single source of truth for the frontend.
 *
 * Uses the calendar-year / birth-year rule (modern USYS / US Club / AYSO standard,
 * and what the backend AgeEligibilityService enforces): the age-group number is
 * `seasonYear − birthYear`. Grouping is by birth YEAR, not birth month — everyone
 * born in the same calendar year is the same U-group.
 *
 * Fixes the Jan-1 drift: the birth year is read from the date STRING, not via
 * `new Date(dob).getFullYear()`, which parses a date-only value as UTC midnight and,
 * rendered in a US timezone, rolls back to Dec 31 of the prior year — bumping Jan-1
 * births into the wrong group.
 *
 * Replaces the per-component copies of this logic (was duplicated across
 * AthleteManagement, AthleteForm, RosterManagement, TeamList, PlayerCard, etc.).
 */

/** Calendar year of a date-only DOB ("YYYY-MM-DD"), with no timezone shift. */
export function birthYearOf(dob?: string | null): number | null {
  if (!dob || dob === 'null' || dob === 'undefined') return null;
  const m = /^(\d{4})-\d{2}-\d{2}/.exec(dob);
  if (m) return parseInt(m[1], 10);
  const d = new Date(dob);
  return isNaN(d.getTime()) ? null : d.getUTCFullYear();
}

/**
 * The season year used for age grouping. The soccer seasonal year runs Aug–Jul;
 * the age group uses the ending calendar year (so Aug–Dec → next year).
 */
export function currentSeasonYear(now: Date = new Date()): number {
  return now.getMonth() >= 7 ? now.getFullYear() + 1 : now.getFullYear();
}

/** Age-group label ("U12") for a DOB, or null if the DOB is missing/invalid. */
export function ageGroup(dob?: string | null, now: Date = new Date()): string | null {
  const by = birthYearOf(dob);
  if (by === null) return null;
  const n = currentSeasonYear(now) - by;
  if (n < 4 || n > 25) return null;
  return `U${n}`;
}

/** Current age in whole years from a DOB (timezone-safe). */
export function ageInYears(dob?: string | null, now: Date = new Date()): number | null {
  const by = birthYearOf(dob);
  if (by === null) return null;
  const m = /^\d{4}-(\d{2})-(\d{2})/.exec(dob ?? '');
  const bMonth = m ? parseInt(m[1], 10) - 1 : 0;
  const bDay = m ? parseInt(m[2], 10) : 1;
  let age = now.getFullYear() - by;
  if (now.getMonth() < bMonth || (now.getMonth() === bMonth && now.getDate() < bDay)) age--;
  return age;
}

/**
 * Calendar month (1–12) of a date-only DOB, with no timezone shift.
 * Same approach as `birthYearOf` — read it off the string, never
 * `new Date(dob).getMonth()`, which answers in local time about a value
 * parsed as UTC midnight and so reports the prior month on the 1st.
 */
function birthMonthOf(dob?: string | null): number | null {
  if (birthYearOf(dob) === null) return null;
  const m = /^\d{4}-(\d{2})-\d{2}/.exec(dob as string);
  if (m) {
    const month = parseInt(m[1], 10);
    return month >= 1 && month <= 12 ? month : null;
  }
  const d = new Date(dob as string);
  return isNaN(d.getTime()) ? null : d.getUTCMonth() + 1;
}

/**
 * Birth quarter ("Q1"–"Q4") for a DOB, or null if the DOB is missing/invalid.
 * Jan–Mar → Q1, Apr–Jun → Q2, Jul–Sep → Q3, Oct–Dec → Q4.
 */
export function ageQuarter(dob?: string | null): string | null {
  const month = birthMonthOf(dob);
  if (month === null) return null;
  return `Q${Math.floor((month - 1) / 3) + 1}`;
}
