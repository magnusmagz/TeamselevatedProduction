'use strict';

/**
 * Guardian identity: `user_guardians` links UNION the case-insensitive email match.
 *
 * ─── What is being protected ──────────────────────────────────────────────────
 * Parent standing used to be a string comparison between `users.email` and
 * `guardians.email` — two independently-editable columns in two tables. Every
 * failure it produced was silent and looked like a product statement: an empty
 * chat list, not an error. Allix Boyce signed in on @gmail while her guardian row
 * said @yahoo, and she simply had no team chats.
 *
 * Migration 072 makes the relationship a row. The union is STRICTLY WIDER than
 * the email match, so this can only give access back, never take it away — which
 * is exactly why it is safe to ship before phase 3 writes links at their source.
 *
 * ─── Why these tests execute real SQL ─────────────────────────────────────────
 * The rest of this suite asserts on SQL strings, which is the right tool for
 * "does this query still filter on status = 'active'". It is the wrong tool here:
 * the claim is that a linked guardian with a DIFFERENT email actually resolves,
 * and a regex over the query text cannot tell you that. So these run against
 * node:sqlite with a fixture shaped like the live tables.
 *
 * The dialect differences are narrow and handled in `run()`: Postgres `$1`
 * becomes `?`. Everything the predicate uses — EXISTS, TRIM, LOWER — is identical
 * in both engines, which is what makes the substitution honest rather than a
 * different query wearing the same name.
 */

const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');

const {
  guardianLinkSql,
  GUARDIAN_LINK_SQL,
  GUARDIAN_JOIN_SQL,
} = require('../lib/guardian_identity');
const { GUARDIAN_TEAM_IDS_SQL } = require('../lib/team_scope');

const ROOT = path.join(__dirname, '..');

/**
 * node:sqlite landed in Node 22.5 and package.json still declares engines >=18,
 * so skip rather than crash the suite on an older runtime. The scans below do not
 * need it and always run.
 */
let DatabaseSync = null;
try {
  ({ DatabaseSync } = require('node:sqlite'));
} catch {
  /* older Node — behavioural tests skip, scans still run */
}
const needsSqlite = { skip: DatabaseSync ? false : 'node:sqlite unavailable (needs Node >= 22.5)' };

/**
 * Team 10. Three families, each reaching it a different way:
 *   user 2  — guardian 500, addresses agree           (the ordinary case)
 *   user 4  — guardian 501, addresses DIFFER, linked  (what migration 072 adds)
 *   user 5  — blank address, guardian 502 also blank  (must NOT match)
 * user 6 is linked to nobody and is on no team: the negative control.
 */
function fixture() {
  const db = new DatabaseSync(':memory:');
  db.exec(`
    CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, first_name TEXT, last_name TEXT);
    CREATE TABLE guardians (id INTEGER PRIMARY KEY, email TEXT, first_name TEXT, last_name TEXT);
    CREATE TABLE athletes (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT);
    CREATE TABLE athlete_guardians (id INTEGER PRIMARY KEY, athlete_id INTEGER, guardian_id INTEGER);
    CREATE TABLE team_members (
      id INTEGER PRIMARY KEY, team_id INTEGER, user_id INTEGER, athlete_id INTEGER,
      role TEXT, status TEXT
    );
    CREATE TABLE user_guardians (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      user_id INTEGER NOT NULL, guardian_id INTEGER NOT NULL,
      source TEXT, confidence TEXT, linked_by INTEGER, created_at TEXT,
      UNIQUE (user_id, guardian_id)
    );

    INSERT INTO users (id, email, first_name, last_name) VALUES
      (2, 'parent@example.com',      'Pat',   'Parent'),
      (4, 'new-address@example.com', 'Robin', 'Moved'),
      (5, '',                        'Blank', 'Account'),
      (6, 'stranger@example.com',    'Sam',   'Stranger');

    INSERT INTO guardians (id, email, first_name, last_name) VALUES
      (500, 'parent@example.com',     'Pat',     'Parent'),
      (501, 'old-address@example.com','Robin',   'Moved'),
      (502, '',                       'Someone', 'Else');

    INSERT INTO athletes (id, first_name, last_name) VALUES
      (100, 'Sam', 'Smith'), (101, 'Rae', 'Moved'), (102, 'Not', 'Related');

    INSERT INTO athlete_guardians (id, athlete_id, guardian_id) VALUES
      (900, 100, 500), (901, 101, 501), (902, 102, 502);

    INSERT INTO team_members (id, team_id, user_id, athlete_id, role, status) VALUES
      (700, 10, NULL, 100, 'player', 'active'),
      (701, 10, NULL, 101, 'player', 'active'),
      (702, 10, NULL, 102, 'player', 'active');

    INSERT INTO user_guardians (user_id, guardian_id, source) VALUES (4, 501, 'admin');
  `);
  return db;
}

/** Run app SQL against the fixture. Only the placeholder dialect is rewritten. */
function run(db, sql, params = []) {
  return db.prepare(sql.replace(/\$(\d+)/g, '?')).all(...params);
}

/** Guardian ids this account resolves to, using the shared predicate verbatim. */
function guardianIdsFor(db, userId) {
  const rows = run(
    db,
    `SELECT g.id AS id FROM users u ${GUARDIAN_JOIN_SQL} WHERE u.id = $1 ORDER BY g.id`,
    [userId]
  );
  return rows.map(r => r.id);
}

// ─── The rule ─────────────────────────────────────────────────────────────────

/**
 * THE POINT OF PHASE 2. Before migration 072 this user resolved to nothing at
 * all, and the symptom was an empty chat list rather than an error.
 */
test('a linked guardian resolves even though the two emails differ', needsSqlite, () => {
  const db = fixture();

  assert.deepStrictEqual(
    guardianIdsFor(db, 4),
    [501],
    'a user_guardians row must confer standing on its own, with no email agreement'
  );

  assert.deepStrictEqual(
    run(db, GUARDIAN_TEAM_IDS_SQL, [4]).map(r => r.id),
    [10],
    'and that has to carry all the way through to the team scope the chat list uses'
  );
});

/**
 * The union must not become a replacement. 194 guardian emails have no account
 * yet, so deleting the email branch before phase 3 writes links at their source
 * would empty every one of those families' portals — the bug this project exists
 * to end, reintroduced by its own rollout.
 */
test('the email match still resolves on its own, with no link row', needsSqlite, () => {
  const db = fixture();

  assert.deepStrictEqual(guardianIdsFor(db, 2), [500]);
  assert.deepStrictEqual(
    run(db, GUARDIAN_TEAM_IDS_SQL, [2]).map(r => r.id),
    [10],
    'unlinked families must keep the access they have today'
  );
});

/** Postgres `=` is case-sensitive; one capital letter severed Emily Govier's family. */
test('the email match is case-insensitive on both sides', needsSqlite, () => {
  const db = fixture();
  db.exec("UPDATE guardians SET email = 'Parent@Example.COM' WHERE id = 500");

  assert.deepStrictEqual(
    guardianIdsFor(db, 2),
    [500],
    'LOWER() on both sides, or a capital letter empties a family'
  );
});

/**
 * `guardians.email` is NOT NULL and 24 production rows hold `''`. In SQL
 * `'' = ''` is TRUE, so without the TRIM guard an account with no address becomes
 * a guardian of every one of those unrelated families at once — and in this app
 * that means their team chats.
 */
test('a blank email does not match a blank guardian', needsSqlite, () => {
  const db = fixture();

  assert.deepStrictEqual(
    guardianIdsFor(db, 5),
    [],
    'blank must not equal blank; the guard is on the USER address being non-empty'
  );
  assert.deepStrictEqual(
    run(db, GUARDIAN_TEAM_IDS_SQL, [5]).map(r => r.id),
    [],
    'and a blank-email account must reach no team chat through the guardian chain'
  );
});

test('an account that is neither linked nor email-matched resolves to nothing', needsSqlite, () => {
  const db = fixture();
  assert.deepStrictEqual(guardianIdsFor(db, 6), []);
});

/** The union is a union: a user linked AND email-matched yields each row once. */
test('link and email match together do not duplicate a guardian', needsSqlite, () => {
  const db = fixture();
  db.exec("INSERT INTO user_guardians (user_id, guardian_id, source) VALUES (2, 500, 'admin')");

  assert.deepStrictEqual(
    guardianIdsFor(db, 2),
    [500],
    'the predicate is one OR inside one join, so a row cannot be admitted twice'
  );
});

// ─── The scan ─────────────────────────────────────────────────────────────────

/** Every .js under lib/, excluding the resolver itself. */
function libFiles() {
  const dir = path.join(ROOT, 'lib');
  return fs
    .readdirSync(dir)
    .filter(n => n.endsWith('.js') && n !== 'guardian_identity.js')
    .map(n => path.join(dir, n));
}

/**
 * The rule must live in ONE file. This is a scan and not three assertions about
 * three known sites, because "fixed the ones I remembered and missed the one I
 * did not" is the entire failure mode of this bug class — it happened on the PHP
 * side (ten sites, three missed here), and again with sameUser() (patched in one
 * file, missed in two). The next such join will be written by someone who has
 * never read this file.
 */
test('no other file under lib/ compares g.email to u.email', () => {
  const EMAIL_COMPARISON = /(?:LOWER\s*\(\s*)?\b\w+\.email\b\s*\)?\s*=\s*(?:LOWER\s*\(\s*)?\b\w+\.email\b/i;

  const offenders = [];
  for (const file of libFiles()) {
    fs.readFileSync(file, 'utf8')
      .split('\n')
      .forEach((line, i) => {
        if (EMAIL_COMPARISON.test(line)) {
          offenders.push(`lib/${path.basename(file)}:${i + 1}: ${line.trim()}`);
        }
      });
  }

  assert.deepStrictEqual(
    offenders,
    [],
    'guardian identity is resolved in lib/guardian_identity.js and nowhere else. ' +
      'An inlined email comparison silently drops every family whose login address ' +
      'differs from their guardian record:\n  ' + offenders.join('\n  ')
  );
});

/**
 * The scan above would pass a file that stopped resolving guardians altogether,
 * so pin that the identity sites exist and go through the shared constant.
 * server.js is included deliberately: its participant picker feeds the DM
 * boundary in participants.js, and a picker narrower than the boundary means a
 * coach cannot select a parent the rules already permit them to message.
 */
test('every guardian-identity site uses the shared join', () => {
  for (const [rel, what] of [
    ['lib/team_scope.js', 'guardian team scope'],
    ['lib/participants.js', 'DM participant allowlist'],
    ['server.js', 'participant picker'],
  ]) {
    const src = fs.readFileSync(path.join(ROOT, rel), 'utf8');
    assert.match(
      src,
      /\$\{GUARDIAN_JOIN_SQL\}/,
      `${rel} (${what}) no longer resolves guardians through lib/guardian_identity.js`
    );
    assert.match(
      src,
      /require\(['"]\.{1,2}\/(?:lib\/)?guardian_identity['"]\)/,
      `${rel} (${what}) does not import the shared resolver`
    );
  }
});

// ─── The predicate itself ─────────────────────────────────────────────────────

/**
 * Mirrors te_guardian_link_sql() in lib/guardian_identity.php. Those two, plus
 * the copy in lib/chat_notification_scope.php, are one rule written three times:
 * if chat scope and the notification audience disagree, we mail a family a link
 * to a 403.
 */
test('the predicate carries both branches and the blank-email guard', () => {
  assert.match(GUARDIAN_LINK_SQL, /EXISTS \(SELECT 1 FROM user_guardians ug/);
  assert.match(GUARDIAN_LINK_SQL, /ug\.user_id = u\.id AND ug\.guardian_id = g\.id/);
  assert.match(GUARDIAN_LINK_SQL, /LOWER\(g\.email\) = LOWER\(u\.email\)/);
  assert.match(GUARDIAN_LINK_SQL, /TRIM\(u\.email\) <> ''/);
  assert.match(GUARDIAN_LINK_SQL, / OR /, 'a UNION of the two branches, never one replacing the other');
});

/** No placeholders, so embedding it cannot disturb a caller's $n numbering. */
test('the predicate contains no bind placeholders', () => {
  assert.doesNotMatch(GUARDIAN_LINK_SQL, /\$\d/);
});

test('aliases are honoured, and refused when they are not identifiers', () => {
  const sql = guardianLinkSql('usr', 'gdn');
  assert.match(sql, /ug\.user_id = usr\.id AND ug\.guardian_id = gdn\.id/);
  assert.match(sql, /LOWER\(gdn\.email\) = LOWER\(usr\.email\)/);

  // Interpolated, so the "literals only" rule is enforced rather than documented.
  assert.throws(() => guardianLinkSql("u; DROP TABLE users --"), /unsafe table alias/);
  assert.throws(() => guardianLinkSql('u', ''), /unsafe table alias/);
});
