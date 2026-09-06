/**
 * The referee-feedback category tags (CKU R68, slice 8.6).
 *
 * ONE list, in two languages. The PHP copy is TE_REFEREE_FEEDBACK_CATEGORIES in
 * lib/referee_feedback.php, which is what the server accepts on write; this is
 * what the modal offers and the admin page renders. There is no codegen step in
 * this project, so tests/php/RefereeFeedbackCategoriesTest.php parses this file
 * and fails the build if the two drift — a tag offered here that the server
 * refuses is a 422 on every submit that picks it.
 *
 * Order matters: the server stores categories in this order regardless of the
 * order they were ticked, so the export column is stable.
 */
export const REFEREE_FEEDBACK_CATEGORIES: ReadonlyArray<{ value: string; label: string; hint: string }> = [
  { value: 'control', label: 'Game control', hint: 'Kept the match under control' },
  { value: 'consistency', label: 'Consistency', hint: 'Same call both ways, all game' },
  { value: 'communication', label: 'Communication', hint: 'Explained decisions to players and coaches' },
  { value: 'safety', label: 'Player safety', hint: 'Managed dangerous play' },
  { value: 'punctuality', label: 'Punctuality', hint: 'On time and ready' },
];

export const REFEREE_FEEDBACK_CATEGORY_VALUES: ReadonlyArray<string> = REFEREE_FEEDBACK_CATEGORIES.map((c) => c.value);

export function refereeCategoryLabel(value: string): string {
  return REFEREE_FEEDBACK_CATEGORIES.find((c) => c.value === value)?.label ?? value;
}
