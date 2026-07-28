// Athlete grade_level is stored as an integer: -1 = Pre-K, 0 = Kindergarten,
// 1-12 = grade number. These helpers give consistent human labels (Pre-K, K,
// 1st … 12th) everywhere, so the UI never shows a bare "0" for kindergarten.

export const GRADE_LEVELS: number[] = [-1, 0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12];

function ordinal(n: number): string {
  const s = ['th', 'st', 'nd', 'rd'];
  const v = n % 100;
  return `${n}${s[(v - 20) % 10] || s[v] || s[0]}`;
}

/** Human label for a stored grade_level. Empty string when there is no grade. */
export function formatGrade(g: number | string | null | undefined): string {
  if (g === null || g === undefined || g === '') return '';
  const n = typeof g === 'number' ? g : parseInt(g, 10);
  if (Number.isNaN(n)) return '';
  if (n <= -1) return 'Pre-K';
  if (n === 0) return 'K';
  return ordinal(n);
}

/** Options for a grade <select>. value is the stored integer, label is human. */
export const GRADE_OPTIONS: { value: number; label: string }[] =
  GRADE_LEVELS.map((v) => ({ value: v, label: formatGrade(v) }));
