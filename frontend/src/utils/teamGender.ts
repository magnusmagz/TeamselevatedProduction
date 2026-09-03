/**
 * teams.gender — the stored values and what they are called on screen.
 *
 * The column is CHECK-constrained to exactly 'Male' | 'Female' | 'Mixed'
 * (default 'Mixed'), so those are the only values that may ever be submitted;
 * anything else fails the whole team save with SQLSTATE 23514. Tournament
 * divisions use boys / girls / coed for the same idea, which is why the labels
 * below read that way while the values stay on the constraint's vocabulary.
 *
 * PHP counterpart: lib/team_gender.php. TeamGenderConsistencyTest pins the two
 * lists to each other and to the constraint.
 */

export type TeamGender = 'Male' | 'Female' | 'Mixed';

export const TEAM_GENDER_VALUES: TeamGender[] = ['Male', 'Female', 'Mixed'];

export const TEAM_GENDER_OPTIONS: { value: TeamGender; label: string }[] = [
  { value: 'Male', label: 'Boys' },
  { value: 'Female', label: 'Girls' },
  { value: 'Mixed', label: 'Coed' },
];

const LABELS: Record<string, string> = {
  male: 'Boys',
  female: 'Girls',
  mixed: 'Coed',
};

/**
 * Display label for a stored gender. An unrecognised value is returned as it
 * was stored rather than blanked or guessed — a blank cell reads as "no
 * answer", which is a different fact from "a value we do not have a word for".
 */
export function teamGenderLabel(value?: string | null): string {
  if (!value) return '';
  return LABELS[value.trim().toLowerCase()] || value;
}
