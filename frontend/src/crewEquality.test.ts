import fs from 'fs';
import path from 'path';

/**
 * THERE IS NO PRIMARY GUARDIAN. Crew members are equal.
 *
 * Product rule, reaffirmed by Maggie 2026-09-02. `athlete_guardians.is_primary`
 * stays in Neon (the schema is additive-only) but it is legacy: nothing writes
 * it, nothing reads it, and no surface shows it.
 *
 * ⚠️ This is a SCAN, not a component test, and that is deliberate. The concept
 * was spread across four components — AthleteForm's radio group, GuardianManagement's
 * checkbox and badge, AthleteProfileEnhanced's "Primary Contact" panel and two
 * PRIMARY chips, and AthleteManagement's "Primary Crew" column. Fixing one and
 * missing three is the failure mode this repo keeps repeating
 * (`ParentPortalChildScopeTest`, `MysqlOnlySqlTest`, `sameUser.test.ts` all exist
 * for it). A test that renders one component proves nothing about the other
 * three.
 *
 * The strings are guardian-specific on purpose. `primary_position`,
 * `primary_coach`, `primary_phone` (the emergency_contacts column), Primary Color
 * and brand-primary are all real, unrelated names — flagging them would produce a
 * checker that cries wolf, and a checker that cries wolf gets deleted.
 */

const SRC = path.join(__dirname);

/**
 * Files allowed to name the concept, and why. Both assert its ABSENCE; a test
 * that cannot say the word cannot pin the rule.
 */
const ALLOWED = new Set([
  'crewEquality.test.ts',
  'components/AthleteForm.crew.test.tsx',
  'components/AthleteManagement.crew.test.tsx',
]);

/** Guardian-primary spellings. Each one shipped in a component at some point. */
const BANNED: Array<[RegExp, string]> = [
  [/is_primary_contact/, 'the API-facing spelling of athlete_guardians.is_primary'],
  [/primary_guardian_(name|email|phone)/, 'a legacy athlete-list key — read `guardians` instead'],
  [/(get|set)PrimaryGuardian/, 'elects one crew member'],
  [/withOnePrimary|isPrimaryFlag/, 'normalises a crew list around a primary'],
  [/Primary Crew/, 'a heading or column label that ranks crew members'],
  [/Primary Contact/, 'a badge or panel that ranks crew members'],
];

function walk(dir: string, out: string[] = []): string[] {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      walk(full, out);
    } else if (/\.(ts|tsx)$/.test(entry.name)) {
      out.push(full);
    }
  }
  return out;
}

/**
 * Comments explaining the removal mention these strings on purpose and are not
 * findings. Block comments, `//` lines and JSDoc continuations all go.
 */
function stripComments(source: string): string {
  return source
    // Blank a block comment out line by line rather than collapsing it, so the
    // line numbers in a finding still match the file someone opens.
    .replace(/\/\*[\s\S]*?\*\//g, (block) => block.replace(/[^\n]/g, ' '))
    .split('\n')
    .map((line) => {
      const t = line.trimStart();
      return t.startsWith('//') || t.startsWith('*') ? '' : line;
    })
    .join('\n');
}

describe('crew members are equal — no primary guardian anywhere in the UI', () => {
  it('has no guardian-primary control, badge, key or helper in frontend/src', () => {
    const findings: string[] = [];

    for (const file of walk(SRC)) {
      const rel = path.relative(SRC, file);
      if (ALLOWED.has(rel)) continue;

      const lines = stripComments(fs.readFileSync(file, 'utf8')).split('\n');
      lines.forEach((line, i) => {
        for (const [pattern, why] of BANNED) {
          if (pattern.test(line)) {
            findings.push(`${rel}:${i + 1} — ${why}\n      ${line.trim()}`);
          }
        }
      });
    }

    expect(findings).toEqual([]);
  });
});
