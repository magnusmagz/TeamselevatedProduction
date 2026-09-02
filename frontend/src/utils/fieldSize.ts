/**
 * Field size and age-group fit — the frontend half of CKU R73, slice 6.3.
 *
 * The rule: U8 and under play 4v4, U9/U10 play 7v7, U11/U12 play 9v9, U13 and
 * up play 11v11. It is mirrored from `lib/field_size.php`
 * (`te_field_size_for_age_group`) and `FieldSizeConsistency` in
 * `fieldSize.test.ts` locks the two lists together, so a size added on one side
 * without the other fails the build.
 *
 * ⚠️ The server is what decides fit. `size_match` arrives on each row from
 * `legacy/fields-gateway.php?action=for-team`; nothing here recomputes it. The
 * mapping below exists only to LABEL the group ("fits U12 (9v9)") when the
 * server has told us the recommended size, and as the fallback for a page that
 * knows a team's age group before it has the field list.
 *
 * ⚠️ Three states, not two. `size_match` is `true` (fits), `false` (a size is
 * recorded and it is the wrong one) or `null` (no size recorded, or the team
 * has no readable age group). NULL IS NOT FALSE: every field is unsized the day
 * migration 088 applies, and a picker that treated "unknown" as "wrong" would
 * bury every field a club owns under a warning.
 *
 * Nothing here blocks a selection. A club knows things this rule does not — a
 * U13 side training on a 9v9 grid, a facility with one pitch — so a mismatch is
 * a sentence on screen, never a disabled option.
 */

export const FIELD_SIZES = ['4v4', '7v7', '9v9', '11v11'] as const;
export type FieldSize = (typeof FIELD_SIZES)[number];

export interface TeamField {
  id: number;
  name: string;
  venue_id: number;
  venue_name: string;
  display_name: string;
  field_type?: string | null;
  surface_type?: string | null;
  field_size: FieldSize | string | null;
  /** true = fits, false = wrong size, null = no opinion. See the header. */
  size_match: boolean | null;
}

export interface TeamFieldsResponse {
  team_id: number;
  age_group: string | null;
  age_group_label: string | null;
  recommended_size: FieldSize | string | null;
  sizing_available: boolean;
  fields: TeamField[];
}

/**
 * The age-group number out of a label, or null if there isn't one.
 *
 * `teams.age_group` is free text and production holds several shapes — `U12`,
 * `U-12`, `u 12`, `12U`. The accepted spellings here are EXACTLY the ones
 * `te_normalize_age_group()` in `lib/age_rule.php` accepts, and
 * `fieldSize.test.ts` reads that file to keep the pair honest.
 *
 * Anything that is not a single clean U-group answers null: `Open`, `Adult`,
 * `U10/U11` (genuinely ambiguous — resolving it to one half would be a guess),
 * a bare `12`, and a birth year. Null means "no recommendation", and the picker
 * then renders the flat list it always did, which is the right thing to do with
 * a label nobody can read.
 */
export function ageGroupNumber(label?: string | null): number | null {
  if (!label) return null;
  const compact = label.trim().toUpperCase().replace(/[\s_]+/g, '');
  if (!compact) return null;

  const m = /^U(?:NDER)?-?(\d{1,2})$/.exec(compact) ?? /^(\d{1,2})-?U$/.exec(compact);
  if (!m) return null;

  const n = parseInt(m[1], 10);
  return n >= 4 && n <= 25 ? n : null;
}

/** Canonical `U12` label for a readable youth group, or null. */
export function ageGroupLabel(label?: string | null): string | null {
  const n = ageGroupNumber(label);
  return n === null ? null : `U${n}`;
}

/** The size a team of this age group plays on, or null when it cannot be read. */
export function fieldSizeForAgeGroup(label?: string | null): FieldSize | null {
  const n = ageGroupNumber(label);
  if (n === null) return null;
  if (n <= 8) return '4v4';
  if (n <= 10) return '7v7';
  if (n <= 12) return '9v9';
  return '11v11';
}

/**
 * "fits U12 (9v9)" — the hint shown beside the recommended group, or null when
 * there is nothing to recommend (no age group on the team, or the column is not
 * live yet). A null hint is the signal to render the picker exactly as it was
 * before this feature: one flat list, no groups, no warnings.
 */
export function fitHint(res?: TeamFieldsResponse | null): string | null {
  if (!res || !res.sizing_available) return null;
  const size = res.recommended_size;
  const group = res.age_group_label ?? ageGroupLabel(res.age_group);
  if (!size || !group) return null;
  return `fits ${group} (${size})`;
}

export interface GroupedFields {
  /** Fields whose recorded size matches the team's age group. */
  fits: TeamField[];
  /** Fields with no size on file. Listed normally — unknown is not wrong. */
  unsized: TeamField[];
  /** Fields of a different size. Offered, with a warning, never hidden. */
  other: TeamField[];
  /**
   * False when there is nothing to group by — no recommendation, or no field in
   * the club has a size recorded. The picker then renders one flat list, which
   * is exactly today's behaviour and the correct state before migration 088 is
   * applied or before a club fills any sizes in.
   */
  grouped: boolean;
}

/**
 * Split a team's field list into the three buckets the pickers render.
 *
 * Order within each bucket is the server's (venue, then field name), and the
 * server already returns fits first — so a caller that ignores the grouping
 * still leads with the right answer.
 */
export function groupFieldsByFit(res?: TeamFieldsResponse | null): GroupedFields {
  const fields = res?.fields ?? [];
  const fits = fields.filter((f) => f.size_match === true);
  const other = fields.filter((f) => f.size_match === false);
  const unsized = fields.filter((f) => f.size_match !== true && f.size_match !== false);

  // Grouping only helps when the server actually had an opinion about
  // something. Otherwise every field lands in `unsized` and the headers would
  // be noise on a list that has not changed.
  const grouped = Boolean(res?.sizing_available && res?.recommended_size && (fits.length > 0 || other.length > 0));

  return { fits, unsized, other, grouped };
}

/**
 * The sentence shown when the selected field is the wrong size, or null when
 * there is nothing to warn about.
 *
 * Deliberately states both facts — what the field is and what the team usually
 * plays — because the person reading it is often the one who knows why the
 * exception is fine.
 */
export function mismatchWarning(res: TeamFieldsResponse | null | undefined, fieldId: number | null): string | null {
  if (!res || !res.sizing_available || !res.recommended_size || !fieldId) return null;
  const field = res.fields.find((f) => f.id === fieldId);
  if (!field || field.size_match !== false) return null;
  const group = res.age_group_label ?? ageGroupLabel(res.age_group) ?? 'this team';
  return `${field.name} is ${field.field_size}. ${group} normally plays ${res.recommended_size}. You can still schedule here.`;
}
