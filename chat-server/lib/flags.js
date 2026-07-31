'use strict';

/**
 * Automatic message flagging.
 *
 * ─── This FLAGS. It never censors. ────────────────────────────────────────────
 * Nothing here alters what recipients see. Censoring makes false positives
 * user-visible and embarrassing (the Scunthorpe problem — place names, surnames,
 * "class", "bass", "assassin"), and it fights the record-keeping posture the rest
 * of this feature is built on: masking at storage destroys evidence, masking at
 * display creates two versions of the truth. A false-positive flag costs an admin
 * three seconds.
 *
 * ─── Severity ─────────────────────────────────────────────────────────────────
 * Profanity and slurs are BOTH high (Maggie, 2026-07-31). In a product where
 * adults talk to children, swearing is not a tone problem to triage later.
 *
 * They stay SEPARATE RULES anyway so a reviewer can tell a swear word from a
 * slur at a glance, and so the compliance summary counts them apart.
 *
 * The wordlists are still the cheap part. The patterns with no profanity in them
 * at all — moving a child off-platform, asking them to keep a secret — remain
 * the highest-signal rules here. Add rules; do not mistake the list for the
 * feature.
 *
 * ─── Never break a send ───────────────────────────────────────────────────────
 * Every rule is evaluated inside its own try/catch and a throwing rule is simply
 * skipped. Moderation must never become a way for chat to stop working, and a bad
 * regex is a normal kind of mistake.
 */

/**
 * Generic profanity. Word-boundary matched, so "classic", "bass", "Scunthorpe"
 * and "Hancock" are left alone.
 *
 * HIGH severity by decision (Maggie, 2026-07-31): in a product where adults talk
 * to children, swearing is not a tone problem to be triaged later.
 */
const PROFANITY = [
  'fuck', 'fucking', 'fucker', 'motherfucker', 'shit', 'shitty', 'bullshit',
  'bitch', 'bastard', 'asshole', 'arsehole', 'dickhead', 'prick',
  'wanker', 'twat', 'piss', 'pissed', 'cock', 'douchebag', 'jackass',
];

/**
 * Slurs — attacks on who someone is rather than bad language about a situation.
 *
 * Kept as a SEPARATE RULE from profanity even though both are high severity, so
 * a reviewer can tell a swear word from a slur at a glance and the compliance
 * summary can count them apart. A racial slur aimed at a child is a safeguarding
 * incident; "that was a shit call" is not, even though both now escalate.
 *
 * `slut`, `whore` and `cunt` moved here from PROFANITY — they are gendered
 * attacks, and having them sit next to "shitty" understated what they are.
 *
 * DELIBERATELY EXCLUDED because innocent usage overwhelms the slur usage and
 * this list must not cry wolf: "cracker", "spade", "colored", "savage" (routine
 * sports praise), "tribe", "chief". `dick` is excluded from PROFANITY for the
 * same reason — it is a common given name — while `dickhead` stays.
 *
 * KNOWN false positives, accepted because this flags rather than censors and a
 * false flag costs a reviewer three seconds: "chink in the armor", "spic and
 * span", and "dyke" in its literal water-barrier or surname sense.
 */
const HATE_SPEECH = [
  // Racial and ethnic
  'nigger', 'nigga', 'niggers', 'niggas', 'chink', 'gook', 'spic', 'beaner',
  'wetback', 'kike', 'wop', 'dago', 'raghead', 'towelhead', 'sandnigger',
  'paki', 'coon', 'jap', 'zipperhead', 'halfbreed',
  // Sexuality and gender identity
  'faggot', 'faggots', 'fag', 'fags', 'dyke', 'tranny', 'trannies', 'shemale',
  // Disability
  'retard', 'retarded', 'retards', 'mongoloid', 'spastic',
  // Gendered attacks
  'slut', 'sluts', 'whore', 'whores', 'cunt', 'cunts',
];

/**
 * Fold letter-for-symbol evasion before matching: "b!tch", "a$$hole", "n1gger",
 * "f4ggot". Stretched letters are handled by the pattern instead — see
 * repeatTolerantRegex below.
 *
 * This does NOT defeat separator evasion ("n-i-g-g-e-r"). Allowing arbitrary
 * separators between every letter matches innocent text across word boundaries,
 * and a filter that cries wolf gets switched off. Known gap, deliberately left.
 */
const LEET = { '0': 'o', '1': 'i', '3': 'e', '4': 'a', '5': 's', '7': 't', '@': 'a', '$': 's', '!': 'i', '|': 'i' };

function normalize(text) {
  return String(text).toLowerCase().replace(/[013457@$!|]/g, c => LEET[c] || c);
}

/**
 * Build a matcher that tolerates stretched letters: "shiiiit", "shhhit", "shittt".
 *
 * Each letter becomes `x+`, so repetition is handled by the PATTERN rather than
 * by squashing the input. An earlier version collapsed runs of 3+ down to two
 * characters, which quietly turned "shiiiit" into "shiit" and matched nothing —
 * the folding has to be in the regex or a stretched word slips straight through.
 *
 * Word boundaries still anchor both ends, so "classic", "bass", "Scunthorpe",
 * "Hancock" and "raccoon" remain untouched.
 */
function repeatTolerantRegex(words) {
  const parts = words.map(w =>
    w
      .replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
      .split('')
      .map(c => (/[a-z]/i.test(c) ? `${c}+` : c))
      .join('')
  );
  return new RegExp(`\\b(${parts.join('|')})\\b`, 'i');
}

const PROFANITY_RE = repeatTolerantRegex(PROFANITY);
const HATE_SPEECH_RE = repeatTolerantRegex(HATE_SPEECH);

/**
 * A phone number offered in conversation. Requires 10+ digits with common
 * separators — deliberately not matching jersey numbers, scores, dates or times.
 */
const PHONE_RE = /(?:\+?1[-.\s]?)?\(?\d{3}\)?[-.\s]\d{3}[-.\s]\d{4}\b/;
const EMAIL_RE = /\b[\w.+-]+@[\w-]+\.[\w.]{2,}\b/;

/** "text me at", "call me on", "here's my number" — moving the conversation off-platform. */
const OFF_PLATFORM_RE =
  /\b(text|call|dm|message)\s+me\b|\bmy\s+(cell|number|phone)\b|\breach\s+me\s+(at|on)\b/i;

/** Asking a child to conceal the conversation. The highest-signal pattern here. */
const SECRECY_RE =
  /\b(don'?t\s+tell|do\s+not\s+tell|between\s+us|just\s+between|our\s+secret|keep\s+(this|it)\s+(secret|quiet|between)|delete\s+(this|the\s+message)|don'?t\s+(let|say)\s+anyone)\b/i;

/** Pushing to a platform with no oversight. */
const EXTERNAL_APP_RE =
  /\b(snapchat|snap\s?chat|whatsapp|whats\s?app|telegram|kik|discord|signal\s+app)\b/i;

/**
 * Ordered by value, not by how impressive they sound. Severity drives whether an
 * admin is notified individually or the item just joins the queue.
 */
const RULES = [
  { name: 'hate_speech', severity: 'high', test: t => HATE_SPEECH_RE.test(normalize(t)) },
  { name: 'secrecy', severity: 'high', test: t => SECRECY_RE.test(t) },
  { name: 'off_platform_contact', severity: 'high',
    test: t => OFF_PLATFORM_RE.test(t) || PHONE_RE.test(t) || EMAIL_RE.test(t) },
  { name: 'profanity', severity: 'high', test: t => PROFANITY_RE.test(normalize(t)) },
  { name: 'external_app', severity: 'medium', test: t => EXTERNAL_APP_RE.test(t) },
];

/**
 * Rules that fired, as [{ rule, severity }]. Never throws and never returns
 * anything that would alter the message.
 */
function evaluateMessage(text) {
  if (!text || typeof text !== 'string') return [];

  const hits = [];
  for (const rule of RULES) {
    try {
      if (rule.test(text)) hits.push({ rule: rule.name, severity: rule.severity });
    } catch (e) {
      // A broken rule must not take the send down with it.
      console.error(`flag rule "${rule.name}" threw:`, e.message);
    }
  }
  return hits;
}

/** True when any hit warrants telling an admin now rather than at the next digest. */
function shouldNotify(hits) {
  return hits.some(h => h.severity === 'high');
}

module.exports = {
  RULES,
  PROFANITY,
  HATE_SPEECH,
  normalize,
  evaluateMessage,
  shouldNotify,
};
