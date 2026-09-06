import fs from 'fs';
import path from 'path';

/**
 * No screen in this frontend may carry or display a shared default password
 * (GOTR G6, 2026-09-06). CoachManagement.tsx hardcoded `password123` in its
 * form default and told the admin so on screen; a kickoff blast then emailed
 * it in plaintext to eleven people. Coaches now get a single-use invite link
 * and no password at creation.
 *
 * A scan, not a unit test, because the literal lived in six places in one file
 * and "fixed one, missed three" is this repo's recurring failure.
 */

function walk(dir: string, out: string[] = []): string[] {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      walk(full, out);
    } else if (/\.(tsx?|jsx?)$/.test(entry.name) && !/\.test\.(tsx?|jsx?)$/.test(entry.name)) {
      out.push(full);
    }
  }
  return out;
}

test('no source file mentions the shared default password', () => {
  const root = path.join(__dirname);
  const offenders = walk(root)
    .filter((f) => fs.readFileSync(f, 'utf8').includes('password123'))
    .map((f) => path.relative(root, f));
  expect(offenders).toEqual([]);
});

test('the coach form no longer sends a password at creation', () => {
  const src = fs.readFileSync(path.join(__dirname, 'components', 'CoachManagement.tsx'), 'utf8');
  expect(src).not.toMatch(/password:\s*'/);
  expect(src).not.toMatch(/Default password/i);
  expect(src).toMatch(/invitation/i);
});
