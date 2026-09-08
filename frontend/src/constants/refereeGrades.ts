/**
 * Referee grades (Referees directory, 2026-09-08).
 *
 * ONE ordered scale, mirrored from lib/referees.php (TE_REFEREE_GRADE_RANK) and
 * pinned together by tests/php/RefereeGradesConsistencyTest.php. The US Soccer
 * grades are the four named ones; the legacy numeric grades 9 (lowest) … 1
 * (highest) map onto that scale for comparison — 9–7 ≈ Grassroots, 6–5 ≈
 * Regional, 4–3 ≈ National, 2–1 ≈ Professional. A blank or "Other" grade has
 * no rank and never qualifies for a game that sets a minimum.
 *
 * The server decides who qualifies; this file only renders selects and, on the
 * assignment picker, the "below the minimum" warning.
 */

export interface RefereeGradeOption {
  value: string;
  label: string;
  group: 'current' | 'legacy';
  rank: number;
  /** US Soccer assistant-only grade: qualifies for assistant / fourth at its level, never center. */
  assistantOnly?: boolean;
}

export const REFEREE_GRADE_OPTIONS: RefereeGradeOption[] = [
  { value: 'Grassroots', label: 'Grassroots', group: 'current', rank: 1 },
  { value: 'Regional', label: 'Regional', group: 'current', rank: 2 },
  { value: 'National', label: 'National', group: 'current', rank: 3 },
  { value: 'Professional', label: 'Professional', group: 'current', rank: 4 },
  { value: 'Regional Assistant Referee', label: 'Regional Assistant Referee', group: 'current', rank: 2, assistantOnly: true },
  { value: 'National Assistant Referee', label: 'National Assistant Referee', group: 'current', rank: 3, assistantOnly: true },
  { value: 'Grade 9', label: 'Grade 9', group: 'legacy', rank: 1 },
  { value: 'Grade 8', label: 'Grade 8', group: 'legacy', rank: 1 },
  { value: 'Grade 7', label: 'Grade 7', group: 'legacy', rank: 1 },
  { value: 'Grade 6', label: 'Grade 6', group: 'legacy', rank: 2 },
  { value: 'Grade 5', label: 'Grade 5', group: 'legacy', rank: 2 },
  { value: 'Grade 4', label: 'Grade 4', group: 'legacy', rank: 3 },
  { value: 'Grade 3', label: 'Grade 3', group: 'legacy', rank: 3 },
  { value: 'Grade 2', label: 'Grade 2', group: 'legacy', rank: 4 },
  { value: 'Grade 1', label: 'Grade 1', group: 'legacy', rank: 4 },
];

/** The four values a game may set as its minimum (plus "Any" = no minimum). */
export const GAME_MIN_GRADES = ['Grassroots', 'Regional', 'National', 'Professional'];

/** The sentinel the Add/Edit modal uses for a free-text grade. */
export const OTHER_GRADE = '__other__';

export function refereeGradeRank(grade: string | null | undefined): number | null {
  const k = (grade ?? '').trim().toLowerCase();
  if (!k) return null;
  const key = /^[1-9]$/.test(k) ? `grade ${k}` : k;
  const hit = REFEREE_GRADE_OPTIONS.find((o) => o.value.toLowerCase() === key);
  return hit ? hit.rank : null;
}

/** Mirrors te_referee_grade_meets(): no minimum → true; a minimum with an unranked grade → false. */
export function refereeGradeMeets(grade: string | null | undefined, minimum: string | null | undefined): boolean {
  const min = refereeGradeRank(minimum);
  if (min === null) return true;
  const have = refereeGradeRank(grade);
  return have !== null && have >= min;
}

export function isAssistantOnlyGrade(grade: string | null | undefined): boolean {
  const k = (grade ?? '').trim().toLowerCase();
  return REFEREE_GRADE_OPTIONS.some((o) => o.assistantOnly && o.value.toLowerCase() === k);
}

/** Mirrors te_referee_grade_qualifies(): the minimum, plus "an AR grade never takes center". */
export function refereeGradeQualifies(grade: string | null | undefined, minimum: string | null | undefined, role: string): boolean {
  if (role === 'center' && isAssistantOnlyGrade(grade)) return false;
  return refereeGradeMeets(grade, minimum);
}

/** Is a stored grade one of the listed options (else it is "Other" free text)? */
export function isListedGrade(grade: string | null | undefined): boolean {
  const k = (grade ?? '').trim().toLowerCase();
  return REFEREE_GRADE_OPTIONS.some((o) => o.value.toLowerCase() === k);
}
