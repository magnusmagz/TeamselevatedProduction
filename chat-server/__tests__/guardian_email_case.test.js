'use strict';

/**
 * Parent standing in this app is derived by comparing `guardians.email` against
 * `users.email`. Postgres `=` is case-sensitive and the two columns live in
 * different tables and are independently editable, so one capital letter removes a
 * family's chat entirely — HTTP 200, empty list, indistinguishable from "you have
 * no team chats".
 *
 * Emily Govier (user 235) was that bug on the PHP side. It was fixed there on
 * 2026-08-18 across ten query sites, and this app — a separate Heroku app deployed
 * by `git subtree split --prefix=chat-server` — was missed and stayed broken. There
 * were THREE sites here, and the third (server.js, the participant picker) was
 * nearly missed again.
 *
 * So this is a SCAN, not three assertions about three known constants. Fixing the
 * sites you remembered and missing the one you did not is the entire failure mode,
 * and the next such join will be written by someone who never read this file.
 */

const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '..');

/** Every .js in the app, excluding node_modules and the tests themselves. */
function sourceFiles(dir = ROOT, out = []) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    if (entry.name === 'node_modules' || entry.name === '__tests__' || entry.name.startsWith('.')) continue;
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) sourceFiles(full, out);
    else if (entry.name.endsWith('.js')) out.push(full);
  }
  return out;
}

/**
 * A comparison between two `<alias>.email` columns, with or without LOWER() on
 * either side. Deliberately matches the BROKEN form too — that is what it is for.
 */
const EMAIL_COMPARISON = /(LOWER\s*\(\s*)?\b\w+\.email\b\s*\)?\s*=\s*(LOWER\s*\(\s*)?\b\w+\.email\b/gi;

test('every guardian/user email comparison lowercases BOTH sides', () => {
  const offenders = [];

  for (const file of sourceFiles()) {
    const src = fs.readFileSync(file, 'utf8');
    const lines = src.split('\n');

    lines.forEach((line, i) => {
      for (const match of line.matchAll(EMAIL_COMPARISON)) {
        const [, leftLower, rightLower] = match;
        if (leftLower && rightLower) continue;
        offenders.push(`${path.relative(ROOT, file)}:${i + 1}: ${line.trim()}`);
      }
    });
  }

  assert.deepStrictEqual(
    offenders,
    [],
    'case-sensitive email comparison — a guardian whose stored email differs by ' +
      'one capital letter silently loses all chat access:\n  ' + offenders.join('\n  ')
  );
});

/**
 * The scan above would pass a file that stopped joining guardians altogether, so
 * pin that the three known identity sites still exist and still lowercase.
 *
 * ⚠️ UPDATED for phase 2 of the user_guardians rollout. The comparison is no
 * longer written out at each site: it moved into `lib/guardian_identity.js`, and
 * the three sites now interpolate `GUARDIAN_JOIN_SQL` from there. That is the
 * `user_guardians` link table this comment used to name as the planned end of
 * this class — it has arrived, and the email match survives alongside it as the
 * second half of a UNION, because 194 guardian emails still have no account and
 * dropping the fallback would empty their families' portals.
 *
 * So the assertion changed from "each file lowercases" to "each file resolves
 * through the one place that lowercases". The case-sensitivity scan above is
 * unchanged and still covers the whole app, guardian_identity.js included.
 * Behaviour is proved against a real database in guardian_identity.test.js.
 */
test('the three known guardian-identity sites resolve through the shared join', () => {
  const expected = [
    ['lib/team_scope.js', 'guardian team scope'],
    ['lib/participants.js', 'DM participant allowlist'],
    ['server.js', 'participant picker'],
  ];

  for (const [rel, what] of expected) {
    const src = fs.readFileSync(path.join(ROOT, rel), 'utf8');
    assert.match(
      src,
      /\$\{GUARDIAN_JOIN_SQL\}/,
      `${rel} (${what}) no longer resolves guardians through lib/guardian_identity.js`
    );
  }

  // And that one place still lowercases both sides.
  const resolver = fs.readFileSync(path.join(ROOT, 'lib/guardian_identity.js'), 'utf8');
  assert.match(
    resolver,
    /LOWER\(\$\{g\}\.email\) = LOWER\(\$\{u\}\.email\)/i,
    'lib/guardian_identity.js no longer carries a case-insensitive guardian comparison'
  );
});

/**
 * Stored emails were deliberately not normalised when the PHP side was fixed:
 * normalising the rows fixes the symptom and hides the class, so the next capital
 * letter typed anywhere breaks again at whichever site was missed. Nothing in this
 * app should be writing a lowercased copy back.
 */
test('no site normalises stored guardian emails instead of the comparison', () => {
  for (const file of sourceFiles()) {
    const src = fs.readFileSync(file, 'utf8');
    assert.doesNotMatch(
      src,
      /UPDATE\s+guardians\s+SET\s+email\s*=\s*LOWER/i,
      `${path.relative(ROOT, file)} rewrites stored emails; fix comparisons, not rows`
    );
  }
});
