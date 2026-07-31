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
 * ─── Profanity is the LEAST valuable rule here ────────────────────────────────
 * A coach swearing about a referee is a bad look. The patterns that actually
 * matter in youth sports chat carry no profanity at all: moving a child off the
 * platform, asking them to keep something secret, pushing to another messaging
 * app. Profanity is rule #1 by ordering only — the pipeline is the deliverable
 * and the wordlist is the trivial part. Add rules here; do not mistake the list
 * for the feature.
 *
 * ─── Never break a send ───────────────────────────────────────────────────────
 * Every rule is evaluated inside its own try/catch and a throwing rule is simply
 * skipped. Moderation must never become a way for chat to stop working, and a bad
 * regex is a normal kind of mistake.
 */

/** Word-boundary matched, so "classic", "bass" and "Scunthorpe" are left alone. */
const PROFANITY = [
  'fuck', 'fucking', 'fucker', 'shit', 'shitty', 'bitch', 'bastard',
  'asshole', 'dickhead', 'cunt', 'slut', 'whore', 'wanker', 'prick',
];

function wordBoundaryRegex(words) {
  const escaped = words.map(w => w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
  return new RegExp(`\\b(${escaped.join('|')})\\b`, 'i');
}

const PROFANITY_RE = wordBoundaryRegex(PROFANITY);

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
  { name: 'secrecy', severity: 'high', test: t => SECRECY_RE.test(t) },
  { name: 'off_platform_contact', severity: 'high',
    test: t => OFF_PLATFORM_RE.test(t) || PHONE_RE.test(t) || EMAIL_RE.test(t) },
  { name: 'external_app', severity: 'medium', test: t => EXTERNAL_APP_RE.test(t) },
  { name: 'profanity', severity: 'low', test: t => PROFANITY_RE.test(t) },
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
  evaluateMessage,
  shouldNotify,
};
