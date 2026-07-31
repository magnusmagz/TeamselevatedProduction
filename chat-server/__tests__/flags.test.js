'use strict';

const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');

const { RULES, evaluateMessage, shouldNotify } = require('../lib/flags');

const serverSrc = fs.readFileSync(path.join(__dirname, '..', 'server.js'), 'utf8');
const sendHandler = serverSrc.slice(
  serverSrc.indexOf("socket.on('sendMessage'"),
  serverSrc.indexOf("socket.on('getTeamMembers'")
);

const fired = text => evaluateMessage(text).map(h => h.rule);

// ─── False positives are the whole risk ──────────────────────────────────────

test('the Scunthorpe problem: innocent words do not fire', () => {
  // These are the reason this flags rather than censors. A false positive here
  // costs an admin three seconds; a censored surname is a support ticket.
  const innocent = [
    'We play Scunthorpe United on Saturday',
    'Coach Hancock will run the drill',
    'That was a classic finish',
    'She plays bass in the school band',
    "Assassin's Creed is his favourite game",
    'Pass it to Bassett on the wing',
    'The class starts at 6',
  ];
  for (const text of innocent) {
    assert.deepStrictEqual(fired(text), [], `should not flag: ${text}`);
  }
});

test('a jersey number, a score and a time are not phone numbers', () => {
  for (const text of ['Wear number 555', 'We won 10-4', 'Kickoff 5:30', 'Meet at 12 30 pm']) {
    assert.ok(!fired(text).includes('off_platform_contact'), `should not flag: ${text}`);
  }
});

test('ordinary chat flags nothing at all', () => {
  assert.deepStrictEqual(fired('Practice is moved to 5pm at the north field.'), []);
  assert.deepStrictEqual(fired(''), []);
  assert.deepStrictEqual(evaluateMessage(null), []);
  assert.deepStrictEqual(evaluateMessage(undefined), []);
  assert.deepStrictEqual(evaluateMessage(42), []);
});

// ─── The rules that actually matter ──────────────────────────────────────────

test('asking a child to keep something secret is high severity', () => {
  for (const text of [
    "don't tell your mom about this",
    'do not tell anyone',
    "let's keep this between us",
    'this is our secret',
    'delete this message after you read it',
  ]) {
    const hits = evaluateMessage(text);
    assert.ok(hits.some(h => h.rule === 'secrecy'), `should flag secrecy: ${text}`);
    assert.ok(shouldNotify(hits), 'secrecy must notify immediately');
  }
});

test('moving the conversation off-platform is high severity', () => {
  for (const text of [
    'text me at 555-123-4567',
    'just call me directly',
    'my cell is the best way',
    'email me at coach@example.com',
    'reach me on my personal line',
  ]) {
    const hits = evaluateMessage(text);
    assert.ok(hits.some(h => h.rule === 'off_platform_contact'), `should flag: ${text}`);
    assert.ok(shouldNotify(hits));
  }
});

test('pushing to an unmonitored app is flagged', () => {
  for (const text of ['add me on snapchat', 'send it on WhatsApp', "I'm on telegram"]) {
    assert.ok(fired(text).includes('external_app'), `should flag: ${text}`);
  }
});

test('profanity is HIGH severity and escalates on its own', () => {
  // Changed 2026-07-31 (Maggie): in a product where adults talk to children,
  // swearing is not a tone problem to triage later.
  const hits = evaluateMessage('that was a shit call by the ref');
  assert.ok(hits.some(h => h.rule === 'profanity'));
  assert.strictEqual(hits.find(h => h.rule === 'profanity').severity, 'high');
  assert.strictEqual(shouldNotify(hits), true);
});

test('slurs fire as hate_speech, not as profanity', () => {
  // Separate rules at the same severity, so a reviewer can tell a swear word
  // from a slur at a glance and the compliance summary counts them apart.
  for (const text of ['you are such a slut', 'stop being a retard', 'that faggot']) {
    const hits = evaluateMessage(text);
    assert.ok(hits.some(h => h.rule === 'hate_speech'), `should flag hate_speech: ${text}`);
    assert.strictEqual(hits.find(h => h.rule === 'hate_speech').severity, 'high');
  }
});

test('gendered slurs moved out of generic profanity', () => {
  // slut/whore/cunt sitting next to "shitty" understated what they are.
  const hits = evaluateMessage('slut');
  assert.ok(hits.some(h => h.rule === 'hate_speech'));
  assert.ok(!hits.some(h => h.rule === 'profanity'));
});

test('symbol substitution does not evade', () => {
  for (const text of ['b!tch', 'a$$hole', 'n1gger', 'f4ggot', 'sh1t']) {
    assert.ok(evaluateMessage(text).length > 0, `should flag: ${text}`);
  }
});

test('stretched letters do not evade', () => {
  // An earlier normalisation collapsed runs to two characters, which turned
  // "shiiiit" into "shiit" and matched nothing. Repetition now lives in the
  // pattern, so this is a regression guard on that exact bug.
  for (const text of ['shiiiiit', 'shhhit', 'shittttt', 'fuuuuck']) {
    assert.ok(evaluateMessage(text).length > 0, `should flag: ${text}`);
  }
});

test('common names and words that contain slur substrings stay clean', () => {
  for (const text of [
    'Dick Vitale is calling the game',
    'we saw a raccoon by the field',
    'the Cooney twins are both playing',
    'she plays with such passion',
    'that was a spectacular assist',
  ]) {
    assert.deepStrictEqual(evaluateMessage(text), [], `should not flag: ${text}`);
  }
});

test('a message can fire several rules at once', () => {
  const hits = fired("don't tell anyone, just text me at 555-123-4567");
  assert.ok(hits.includes('secrecy'));
  assert.ok(hits.includes('off_platform_contact'));
});

// ─── Never break a send ──────────────────────────────────────────────────────

test('a rule that throws is skipped, not fatal', () => {
  // A bad regex is an ordinary mistake and must not take chat down.
  const original = RULES[0].test;
  RULES[0].test = () => { throw new Error('boom'); };
  try {
    const hits = evaluateMessage('text me at 555-123-4567');
    assert.ok(Array.isArray(hits), 'evaluation must still return');
    assert.ok(hits.some(h => h.rule === 'off_platform_contact'), 'other rules still run');
  } finally {
    RULES[0].test = original;
  }
});

test('flagging happens after the message is saved and broadcast', () => {
  const broadcastAt = sendHandler.indexOf("emit('receiveMessage'");
  const flagAt = sendHandler.indexOf('evaluateMessage');
  assert.ok(broadcastAt !== -1 && flagAt !== -1);
  assert.ok(broadcastAt < flagAt, 'delivery must not wait on moderation');
});

test('the flag block is wrapped so it cannot fail a send', () => {
  const block = sendHandler.slice(sendHandler.indexOf('evaluateMessage') - 400);
  assert.match(block, /catch \(error\)[\s\S]{0,120}auto-flagging/,
    'auto-flagging needs its own catch');
  const afterCatch = block.slice(block.indexOf('auto-flagging'), block.indexOf('auto-flagging') + 200);
  assert.ok(!/return;/.test(afterCatch), 'a flag failure must not abort the handler');
});

// ─── It flags; it does not censor ────────────────────────────────────────────

test('nothing here alters or blocks the message', () => {
  const hits = evaluateMessage('that was a shit call');
  for (const h of hits) {
    assert.deepStrictEqual(Object.keys(h).sort(), ['rule', 'severity'],
      'a hit carries no replacement text — this pipeline never rewrites a message');
  }
  const block = sendHandler.slice(sendHandler.indexOf('evaluateMessage'));
  assert.ok(!/replace\(/.test(block.slice(0, 600)), 'no masking of message text');
});

test('auto flags land in the same queue as human reports', () => {
  assert.match(sendHandler, /FILE_AUTO_REPORT_SQL/);
});

test('the flag is filed against the conversation club, not the sender club', () => {
  // Otherwise a flag lands in the wrong admin's queue whenever those differ.
  assert.match(sendHandler, /SELECT club_id FROM conversations WHERE id = \$1/);
});
