# Chat moderation — plan

**Scheduled: week of Monday 2026-08-03.** Written 2026-07-30, after archive + retention shipped.

Phase 2 of `docs/chat-archive-plan.md`, expanded well past what that document assumed. It is not
"add a delete button" — it is a moderation system, and the sizing below reflects that.

## The decision

**Reported or auto-flagged message → admin notified → admin opens that conversation with full read
→ removes or dismisses.**

Maggie's stance, 2026-07-30: **club chat carries no expectation of privacy.** Club admins may read
conversations in their club in order to act on a report. This is a deliberate product position for a
youth sports org, not an accident of the permission model.

Two consequences follow, and they are requirements, not commentary:

1. **The notice lives in the chat UI, not only the ToS.** "No expectation of privacy" is only true if
   nobody forms the expectation, and nobody forms expectations from a document they clicked through
   in March. A persistent line in the conversation header — "Club administrators can review
   messages" — is what makes the position true rather than merely asserted.
2. **Admin reads are logged.** Under this stance the log is not a privacy control; it is a
   *defensibility* control. It is how the club shows it exercised oversight when something goes
   wrong, and how an admin shows they opened a thread because of a report rather than out of
   curiosity. Same cheap table, better reason.

**Priced-in risk:** when chat is known to be reviewable, sensitive conversations migrate to personal
text messages — the safety surface shrinks exactly where it matters, and the record this was all
built to preserve is the thing that leaves. Argues for the header notice reading as matter-of-fact
rather than ominous, and it is the strongest case for M6 below.

### Assumption taken (flag-gated, not blanket browse)
Admin read opens **because a report or flag exists on that conversation**. The admin's entry point is
the review queue; there is no conversation browser to build. This is what the decision describes, it
is a fraction of the UI, and blanket browse can be added later without reworking any of it.

If blanket browse is wanted instead, M3 grows a directory/search surface. Everything else is unchanged.

---

## Where the code goes

Chat data is written by the **Node chat server**; the CRM is **PHP**. Splitting writes across both
would mean two places that can soft-delete a message.

- **All chat writes stay in Node** (report, remove) — so removal broadcasts a live tombstone to
  everyone in the room instead of waiting for a reload.
- **The review queue is a PHP page** that reads the reports table and **deep-links into the chat UI**.
  Read-only on the PHP side.
- The admin therefore reviews in the queue, clicks through, reads the conversation in chat, and
  removes there. That matches "the admin goes and looks at the conversation."

Migration numbers: **claim at build time**, checking `ls database/migrations/ | sort` in every
checkout. 059 was the last applied; other sessions are numbering fast and 060+ may be taken by
Monday.

---

## M1 — Removal, tombstone, audit

Foundation; everything else depends on it. Writes the `chat_messages.deleted_at` that already exists
and is already filtered by every read path.

- Migration: `chat_messages.deleted_by INTEGER REFERENCES users(id)`, `removal_reason VARCHAR(40)`.
- Socket `removeMessage` — **club_admin only**, scoped by `conversations.club_id`. Not coaches, not
  senders removing their own words: that is the capability deliberately withheld in Phase 1, and a
  coach is often the person you least want able to scrub themselves.
- **No time limit.** Messenger's 10-minute window is for sender remorse. Moderation acts on a
  complaint that arrives hours or days later.
- Tombstone reads **"Message removed by an administrator"** — not "X deleted a message," which
  misattributes it to the sender.
- Audit via `lib/AuditLogger.php`: actor, message id, conversation id, reason. **Not the message
  text.** Removal has two motives that pull opposite ways — safety (preserve for investigation) and
  privacy (someone posted a phone number or PII). Copying the text into `audit_log` defeats the
  second, because audit retention is 2555 days against the message's own 90. The text stays on
  `chat_messages.message_text` for the 90-day window and then genuinely goes; that window is what
  `chat_messages_removed` in `lib/retention_plans.php` already enforces.

### The fiddly part: `deleted_at IS NULL` appears in 7 places and they want different answers

| Site | Wanted |
|---|---|
| `loadConversationMessages` | **Return** the row, text nulled — this is the tombstone |
| unread count | Exclude |
| last-message preview | Exclude |
| `markRead` `MAX(id)` | **Include** — otherwise a removed newest message leaves the badge stuck unread forever |

Getting this uniformly wrong is the likely bug. Each site gets an explicit decision and a test.

**Tests** (`chat-server/__tests__/moderation.test.js`, `node:test`)
- non-admin refused; coach refused; cross-club admin refused
- soft delete only — a `DELETE` in this path fails the test
- audit row written; **message text absent from the audit payload**
- tombstone returned by history; text unreadable through *every* list query
- `markRead` includes removed messages; unread count and preview exclude them

---

## M2 — Reports (B)

- Migration: `chat_message_reports` — id, message_id, conversation_id, reported_by, source
  (`user` | `auto`), rule, severity, note, status (`open` | `actioned` | `dismissed`), reviewed_by,
  reviewed_at, created_at.
- Socket `reportMessage` — any participant of the conversation.
- Human reports and automated flags land in the **same table and the same queue**. Admins get one
  inbox, not two.
- PHP `api/chat-moderation.php` — list open reports scoped to the admin's club, dismiss, and the
  deep link. Read + status writes only; the removal itself is M1's socket.
- Notify on new high-severity reports. Not on everything, or admins tune it out.

**Tests** (`tests/php/ChatModerationQueueTest.php` + node)
- reporter must be a participant
- queue is club-scoped; an admin of club A never sees club B's reports
- dismiss records reviewer and timestamp
- duplicate reports on one message collapse rather than flooding the queue

---

## M3 — Admin read access (C) + access logging

- `isConversationParticipant` gains an admin branch. Today `server.js:388` returns false for any
  `direct`/`group` conversation the user is not explicitly in — that line is why admins cannot see
  DMs, and it is what changes.
- The branch grants access **only when an open report exists on that conversation** and the admin is
  a club_admin of `conversations.club_id`.
- Migration: `chat_access_log` — id, user_id, conversation_id, report_id, created_at.
- **Every admin open writes a row.** Non-negotiable per the decision above.

**Tests**
- admin cannot open an unflagged DM (the flag-gate is the whole control)
- admin can open a flagged one
- an access row is written on open, with the report that justified it
- cross-club admin refused; coach refused; ordinary participants unaffected
- closing the report closes the access

---

## M4 — Auto-flag pipeline (profanity is rule #1, not the point)

**Flag only. Nothing is censored.** Censoring alters what recipients see, so false positives are
user-visible and embarrassing (the Scunthorpe problem — place names, surnames, "class", "bass",
"assassin"), and it fights the record-keeping posture: masking at storage destroys evidence, masking
at display creates two versions of the truth. A false-positive flag costs an admin three seconds.

**Profanity is close to the least dangerous content in youth sports chat.** A coach swearing about a
referee is a bad look. The patterns that matter carry no profanity at all. So the deliverable is the
**pipeline**; the wordlist is the trivial part.

`chat-server/lib/flags.js` — pure rule evaluation, testable without sockets or Postgres (same shape
as `lib/archive.js`). Rules, roughly in ascending value:

1. profanity — wordlist, **word-boundary matched**
2. off-platform contact — phone numbers, email addresses, "text me at"
3. secrecy — "don't tell", "between us", "keep this quiet"
4. links to external messaging apps

Two rules that are not negotiable:

- Runs **inline in `sendMessage`**, no external call — no added latency, no new dependency.
- **If evaluation throws, the message still sends.** Moderation must never become a way for chat to
  break. Wrapped in try/catch with the send outside it.

**Tests** (`chat-server/__tests__/flags.test.js`)
- word boundaries hold: "Scunthorpe", "Hancock", "classic", "bass", "assassin" do **not** fire
- phone and email patterns fire; secrecy phrases fire
- severity mapping is stable
- **a rule that throws does not prevent the send** — asserted directly, since this is the failure
  mode that would take chat down

---

## M5 — Notice + ToS — out of the build, follows later

**Decision (Maggie, 2026-07-30): chat is live and goes to the beta clubs now. The ToS comes from the
attorney afterwards, and the business owns this risk tolerance explicitly.** Not an oversight and
not pending — decided. Do not re-raise it as a blocker.

Still to land, in the attorney's time:

- Persistent line in the conversation header: "Club administrators can review messages."
- ToS/privacy copy + acceptance via `users.tos_accepted_at` / `tos_version`.

**Fact for whoever builds M3, not a caution:** once beta clubs are live, admin read touches real
families' conversations rather than internal test accounts. As of 2026-07-30 every `direct` message
in prod was between internal accounts (6 of the 8 senders ever were `maggie+*@4msquared.com` /
`@teamselevated.com`), so that ceases to be true as beta usage starts. It changes what the access
log in M3 is holding, which is a reason to build M3 with the log rather than after it — not a reason
to delay M3.

---

## M0 — `createConversation` participant allowlist — **DECIDED, SHIPS FIRST**

Supersedes the co-adult rule. Maggie, 2026-07-30: **coaches cannot DM athletes at all.** DMs are
between coaches and the crew (guardians). So this is not "require a second adult" — athletes are
simply not permissible participants.

**`createConversation` validates nothing today.** It takes `participantIds` from the client, looks
the names up in `users`, and inserts them. No check of club, team, or role. Any user passing
`canInitiateConversation` (which includes `parent`) can open a DM with **any user id in the system,
in any club**. Same shape as the athlete/guardian gateway bug in CLAUDE.md — *bound what the endpoint
accepts, not what the form happens to send.* `getTeamMembersForPicker` already returns only guardians
and coaches, so the UI is correct and the endpoint is the hole.

**Never exploited against a child:** zero athletes have ever been a conversation participant. One
participant *is* genuinely out of scope — **conversation 52** (group, club 32), user 27
`john@nomail.com`, connected to that club by neither the guardian chain nor a staff role. So the hole
is reachable and has been reached, not theoretical.

> Correction, 2026-07-30: an earlier draft cited **conversation 18** as a cross-club DM. That was
> wrong. User 91 is a guardian of an athlete on a club 32 team, so the DM is legitimate; their
> `user_club_access` row says club 25, which is a stale secondary role. The mistake was comparing
> `user_club_access.club_profile_id` against `conversations.club_id` instead of walking the guardian
> chain. **Club membership for a guardian is the guardian chain, not their `user_club_access` row.**

### Implement as an ALLOWLIST, never a blocklist on `athletes.user_id`

Verified against live Neon 2026-07-30, and this is the trap:

| | |
|---|---|
| athletes with `user_id` | 26 |
| …whose account email is **a guardian's** | **23** |
| …whose account holds a **staff role** | **10** (6 coach, 4 club_admin) |
| users holding the `player` role | **0** |

`athletes.user_id` is not a reliable "this account is the child" signal — it mostly points at the
parent. Measured directly against the finished allowlist: **club 51's allowlist is 26 people, and 16
of them are also `athletes.user_id` values.** A blocklist would have removed **62% of that club's
reachable contacts**, most of them guardians — breaking exactly the coach↔crew conversation the
feature exists for.

So: participants must be **in the allowlist the picker already computes** — guardians of athletes on
the creator's teams, plus coaches/admins of the same club. Anything else is rejected. Athletes are
excluded by never being in the set, which stays true however messy `athletes.user_id` becomes.

**Tests**
- a participant outside the creator's club is rejected (conversation 18 could not be created today)
- a participant on another team is rejected for a coach; allowed for a club admin of that club
- an athlete `user_id` is rejected **even when that account also holds a coach or guardian role** —
  the allowlist decides, not athlete-ness
- a guardian whose account is mis-linked as `athletes.user_id` is **still reachable** (the regression
  a blocklist would cause)
- the endpoint rejects ids the picker would never have offered, with the picker stubbed out entirely

---

## M6 — Co-adult rule — **SUPERSEDED by M0**

Kept for history. The co-adult rule ("require a second adult on a coach↔athlete DM") is moot once
coach↔athlete DMs cannot exist at all. M0 is the stronger form of the same control.

---

## M7 — Queue health + compliance summary

Added 2026-07-30. Maggie: this is a **compliance feature that sells** — club admins hold queue
access and the capability itself is the pitch.

That is legitimate, and it has a design consequence. A queue that is demoed but not watched is worse
for a club than no queue: an unactioned flag sitting for eight months is discoverable evidence that
they were told and did nothing. **Make inaction visible** — it protects the club and it demos better.

- Oldest unreviewed item and open count, surfaced on the queue and the admin dashboard.
- Weekly digest to club admins when anything is open. Silence when the queue is clean.
- **Compliance summary** — messages reviewed, flags raised, actions taken, over a date range. This
  is the artifact a club hands to a board or an insurer, and it is the reason a buyer cares.

Sellable claims in descending strength, which is roughly the inverse of how impressive they sound:

1. Coaches cannot privately message athletes (**M0** — structural, verifiable)
2. No message can be deleted by anyone (**shipped 2026-07-30**), retained under a stated policy
   (`lib/retention_plans.php`)
3. Removals are tombstoned and audited; nothing vanishes silently (**M1**)
4. Any participant can report; admins review (**M2**)
5. Admin access is itself logged (**M3**) — the answer to "so admins read everyone's messages?"
6. Automated flagging (**M4**) — sounds strongest, is weakest

---

## How this gets built (Maggie, 2026-07-30)

Run all milestones autonomously. Where something needs a decision, **make the best guess, build it,
and add it to "Revisit after launch" below** rather than stopping. Deploy per milestone, not one
drop at the end — `main` is shared and holding code back has already misfired in this repo.

### No destructive actions — the boundary, since this feature removes messages

- **Allowed:** message removal (soft delete — row survives, tombstoned, audited, reversible);
  additive migrations only (new tables, new nullable columns).
- **Never:** `retention-check.php --purge`; arming a retention policy; hard-deleting any row;
  `DROP`; destructive `UPDATE` on prod data; overwriting backfills.
- If a milestone genuinely requires something destructive, **stop and flag it.** Do not guess.

### Revisit after launch

Best guesses taken to avoid blocking. None are load-bearing; all are cheap to change.

- [ ] Admin notification is **email** via the existing transactional path + an in-app queue badge.
      Weekly digest; individual alerts only for high severity.
- [ ] **The sender is not notified** when their message is removed — the tombstone is visible
      in-thread to everyone including them. Silent-to-sender read worse than no notification.
- [ ] `super_admin` / `owner` get queue access, scoped per club, for platform support.
- [ ] Review queue is a **new nav item** under the admin section rather than folded into a page.
- [ ] Profanity list is a conservative standard set, word-boundary matched. **Expect to tune it once
      real flag rates exist** — the first version is a guess about a club's tolerance, not a fact.
- [ ] Severity thresholds and what each tier triggers.
- [ ] Whether the plan should also live as Jira tickets on TE (raised, never settled).

### Verification limits — say this again when M2 lands

There is no staging environment: one Netlify site, one Heroku app, one Neon database. Verification is
unit tests, rolled-back transactions against prod, and deployed-bundle checks. **Nobody has clicked
through the review queue as a real club admin.** That was acceptable for an archive button; for a
workflow an admin is expected to operate under compliance pressure, someone should drive it once
before a beta club relies on it.

---

## Order

**M0 → M1 → M2 → M3 → M4 → M7.** M5 is no longer in the build (attorney), and is a rollout gate.

M0 first: it is a security fix on its own merits and it is the structural control — it prevents
rather than detects, and needs nobody watching a queue. M1 is the foundation for the rest. M3 is
deliberately late; admin read is the sharpest edge and should land once the queue justifying each
open exists.

## Deploy notes

- Chat server is a **separate Heroku app**, deployed by subtree:
  `git subtree split --prefix=chat-server -b <ref>` then push to the `chat` remote. `git push heroku`
  does not deploy it.
- Migration first, then chat server, then frontend — the same ordering as the archive work, and for
  the same reason.
- Frontend has a lint ratchet (`--max-warnings 74`); a new warning fails the shared build.
