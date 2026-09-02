import fs from 'fs';
import path from 'path';
import {
  FIELD_SIZES,
  ageGroupNumber,
  ageGroupLabel,
  fieldSizeForAgeGroup,
  fitHint,
  groupFieldsByFit,
  mismatchWarning,
  TeamField,
  TeamFieldsResponse,
} from './fieldSize';

const field = (id: number, name: string, size: string | null, match: boolean | null): TeamField => ({
  id,
  name,
  venue_id: 1,
  venue_name: 'Ashford Park',
  display_name: `Ashford Park - ${name}`,
  field_size: size,
  size_match: match,
});

const u12Response = (fields: TeamField[]): TeamFieldsResponse => ({
  team_id: 10,
  age_group: 'U12',
  age_group_label: 'U12',
  recommended_size: '9v9',
  sizing_available: true,
  fields,
});

describe('the age-group to field-size mapping', () => {
  it('maps every band', () => {
    expect(fieldSizeForAgeGroup('U6')).toBe('4v4');
    expect(fieldSizeForAgeGroup('U8')).toBe('4v4');
    expect(fieldSizeForAgeGroup('U9')).toBe('7v7');
    expect(fieldSizeForAgeGroup('U10')).toBe('7v7');
    expect(fieldSizeForAgeGroup('U11')).toBe('9v9');
    expect(fieldSizeForAgeGroup('U12')).toBe('9v9');
    expect(fieldSizeForAgeGroup('U13')).toBe('11v11');
    expect(fieldSizeForAgeGroup('U19')).toBe('11v11');
  });

  it('reads the live spellings of a free-text age group', () => {
    ['U12', 'u12', 'U-12', 'U 12', 'Under12', '12U', '12u', '12-U'].forEach((spelling) => {
      expect(ageGroupNumber(spelling)).toBe(12);
      expect(fieldSizeForAgeGroup(spelling)).toBe('9v9');
    });
    expect(ageGroupLabel('12U')).toBe('U12');
  });

  /**
   * The refusals are shared with te_normalize_age_group() in lib/age_rule.php,
   * which is the product's one parser for this label. An ambiguous value must
   * not silently resolve to one half of itself.
   */
  it('refuses a birth year, a compound label and an ambiguous one', () => {
    expect(ageGroupNumber('2012')).toBeNull();
    expect(ageGroupNumber('2012 Boys')).toBeNull();
    expect(ageGroupNumber('U10/U11')).toBeNull();
    expect(ageGroupNumber('U12 Boys')).toBeNull();
    expect(ageGroupNumber('12')).toBeNull();
    expect(fieldSizeForAgeGroup('Recreational')).toBeNull();
    expect(fieldSizeForAgeGroup(null)).toBeNull();
  });

  /**
   * The PHP is what decides fit at runtime; this list only labels the group.
   * If they disagree the label lies, so lock them together.
   */
  it('matches lib/field_size.php', () => {
    const php = fs.readFileSync(path.join(__dirname, '../../../lib/field_size.php'), 'utf8');
    // The PHP delegates its spelling parser to lib/age_rule.php; this file
    // mirrors the same accepted shapes rather than inventing a third.
    expect(php).toContain('te_normalize_age_group(');
    const declared = /const TE_FIELD_SIZES = \[([^\]]+)\]/.exec(php);
    expect(declared).not.toBeNull();
    const phpSizes = (declared as RegExpExecArray)[1]
      .split(',')
      .map((s) => s.trim().replace(/^'|'$/g, ''))
      .filter(Boolean);
    expect(phpSizes).toEqual([...FIELD_SIZES]);

    expect(php).toContain("if ($n <= 8)  { return '4v4'; }");
    expect(php).toContain("if ($n <= 10) { return '7v7'; }");
    expect(php).toContain("if ($n <= 12) { return '9v9'; }");
    expect(php).toContain("return '11v11';");
  });
});

describe('the grouped field picker', () => {
  it('puts fits first, unsized next, and the wrong sizes last', () => {
    const res = u12Response([
      field(1, 'Pitch 1', '9v9', true),
      field(2, 'North', null, null),
      field(3, 'Pitch 2', '11v11', false),
    ]);
    const grouped = groupFieldsByFit(res);
    expect(grouped.grouped).toBe(true);
    expect(grouped.fits.map((f) => f.id)).toEqual([1]);
    expect(grouped.unsized.map((f) => f.id)).toEqual([2]);
    expect(grouped.other.map((f) => f.id)).toEqual([3]);
  });

  it('labels the recommended group with the age group and the size', () => {
    expect(fitHint(u12Response([]))).toBe('fits U12 (9v9)');
  });

  /**
   * An unsized field is listed normally, never hidden. Every field is unsized
   * the day migration 088 applies, so hiding them would empty the picker for
   * every club at once.
   */
  it('never drops an unsized field', () => {
    const res = u12Response([field(1, 'Pitch 1', '9v9', true), field(2, 'North', null, null)]);
    const grouped = groupFieldsByFit(res);
    expect([...grouped.fits, ...grouped.unsized, ...grouped.other]).toHaveLength(2);
  });

  /** A mismatch is offered with a warning — the club may know better. */
  it('never drops a mismatched field', () => {
    const res = u12Response([field(3, 'Pitch 2', '11v11', false)]);
    expect(groupFieldsByFit(res).other.map((f) => f.id)).toEqual([3]);
  });

  it('warns about the selected field without blocking it', () => {
    const res = u12Response([field(3, 'Pitch 2', '11v11', false)]);
    const warning = mismatchWarning(res, 3);
    expect(warning).toContain('Pitch 2 is 11v11');
    expect(warning).toContain('U12 normally plays 9v9');
    expect(warning).toContain('You can still schedule here');
  });

  it('says nothing about a field that fits or has no size on file', () => {
    const res = u12Response([field(1, 'Pitch 1', '9v9', true), field(2, 'North', null, null)]);
    expect(mismatchWarning(res, 1)).toBeNull();
    expect(mismatchWarning(res, 2)).toBeNull();
  });

  /**
   * Before migration 088, and for a club that has recorded no sizes, the picker
   * must look exactly as it did — one flat list, no headings, no warnings.
   * Grouping an unchanged list is noise; warning about it is wrong.
   */
  it('does not group when nothing has a size recorded', () => {
    const res = u12Response([field(1, 'Pitch 1', null, null), field(2, 'North', null, null)]);
    expect(groupFieldsByFit(res).grouped).toBe(false);
    expect(mismatchWarning(res, 1)).toBeNull();
  });

  it('does not group when the column is not live yet', () => {
    const res: TeamFieldsResponse = {
      team_id: 10,
      age_group: 'U12',
      age_group_label: 'U12',
      recommended_size: null,
      sizing_available: false,
      fields: [field(1, 'Pitch 1', null, null)],
    };
    expect(fitHint(res)).toBeNull();
    expect(groupFieldsByFit(res).grouped).toBe(false);
    expect(mismatchWarning(res, 1)).toBeNull();
  });

  /** A team with no readable age group gets no verdicts and no grouping. */
  it('does not group when the team has no age group', () => {
    const res: TeamFieldsResponse = {
      team_id: 12,
      age_group: null,
      age_group_label: null,
      recommended_size: null,
      sizing_available: true,
      fields: [field(1, 'Pitch 1', '9v9', null)],
    };
    expect(fitHint(res)).toBeNull();
    expect(groupFieldsByFit(res).grouped).toBe(false);
  });

  /** A request that never answered must not break the picker. */
  it('tolerates a null response', () => {
    expect(fitHint(null)).toBeNull();
    expect(groupFieldsByFit(null)).toEqual({ fits: [], unsized: [], other: [], grouped: false });
    expect(mismatchWarning(null, 1)).toBeNull();
  });
});
