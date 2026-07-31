# SMS Inbox — Scope

**Status:** Planned, not started
**Written:** 2026-07-31
**Mockup:** `docs/mockups/sms-inbox.html`
**Supersedes:** replies Tier 1/2 in `sms-scheduled-and-replies-scope.md`. That doc's Tier 1
(forward to email) is now skipped — see "Why not Tier 1" below.
**Still out of scope:** merging SMS with team chat — `../../unified-messaging-scope.md` Phase 2/3.

---

## Why this, and why now

On 2026-07-30 a broadcast went to 129 Central Kansas guardians. Seven replied within 25 minutes.
Four said the same thing:

> *"I did not receive an email ir in my spam"* — Cathy Rice
> *"Unfortunately I haven't seen an email? Can you tell me which email it was sent to?"* — Amber Benning
> *"I did not receive anything. Was that sent today?"* — Lily Ramirez
> *"I don't have any email, could you send it again"* — Monica Cruz Arias

They were right, and the reason took four database queries and a Twilio API call to find: **152 crew
in that club, 2 had ever been sent a portal invite.** The announcement email promised an invitation;
the invitation had not been sent.

**Nobody in the product could see any of this.** The replies exist only in Twilio's message log.
The auto-reply told four families the number was unmonitored and pointed them at a portal they had
no invite to.

That is the case for this feature, and it is worth being precise about it: the sending worked
perfectly. What was missing was the return path.

---

## Why not Tier 1 (forward replies to email)

The earlier scope proposed forwarding replies to a club admin's inbox as the cheap first step. Now
that we have seen real replies, that is the wrong shape:

- The four useful replies needed an **answer sent back as a text**. Email forwarding gives the admin
  the message but no way to respond in the channel the parent used.
- Replying by email to a parent who texted is a channel switch the parent did not ask for, and for
  four of these seven the answer needed to reach a phone.
- Forwarding puts club business in a personal inbox, outside the audit trail, invisible to anyone
  else at the club.

Tier 1 was a way to avoid building UI. The UI is the feature.

---

## Scope

An admin sees replies to their club's number, answers them, and the answer goes out as a text from
that same number.

**In:** inbound capture and storage · a threaded inbox · reply-as-SMS · read/needs-reply state ·
attribution to the crew member and their athlete.

**Out:** team chat merging · email threading · per-coach numbers and routing (`staff_phone_numbers`)
· parent-facing view · real-time push. The list refreshes on load and on poll; Socket.IO is a later
optimization, not a requirement.

---

## Build

### 1. Capture — migration 060

`api/webhooks/twilio-inbound.php` currently stores **nothing**, deliberately, and
`SmsAutoReplyTest::testNothingIsStored` enforces that. This inverts it, so that test changes with
the feature.

```
ALTER TABLE communication_log
  ADD COLUMN direction        VARCHAR(10) NOT NULL DEFAULT 'outbound'
    CHECK (direction IN ('outbound','inbound')),
  ADD COLUMN conversation_id  VARCHAR(64),
  ADD COLUMN read_at          TIMESTAMP;
```

`conversation_id` = a hash of `club_profile_id + normalized contact phone`. **Keyed on the club,
not on the sender**, because the club owns the number today — when per-coach numbers arrive
(`unified-messaging-scope.md` Phase 1) the key gains the user and existing threads keep working.

Reusing `communication_log` rather than a new table is what makes inbound show up on the contact's
existing Communications tab for free, and keeps one delivery/status vocabulary.

### 1b. The flag — per club, not global

Migration 060 also adds:

```
ALTER TABLE sms_phone_numbers
  ADD COLUMN inbox_enabled BOOLEAN NOT NULL DEFAULT FALSE;
```

It lives on `sms_phone_numbers`, not `club_profile`, mirroring
`club_payment_accounts.charges_enabled` and `payment_items.sibling_discount_enabled` — this repo
has no global flag system, it puts capability booleans on the row that owns the capability and
checks them server-side. An inbox cannot exist without a number, so this is that row.

**It must be per club, because the flag drives the auto-reply copy.** "Someone from your club will
get back to you here" is only true where someone is watching. A global toggle would promise it to
every club at once, including ones with no one reading. Tie the promise to the same boolean that
turns the inbox on and the two can never disagree.

What the flag does and does not gate:

| | Flagged? |
|---|---|
| Capturing inbound (writing the rows) | **No — always on** |
| Recording STOP on arrival | **No — always on**, it is a compliance fix |
| Inbox route, nav item, reply | Yes |
| Which auto-reply copy is sent | Yes — branches on it |

Capture stays on for every club because storing is not monitoring: the current copy says the number
is *not monitored*, which remains true for an unflagged club, and it means the inbox has real
history the day it is switched on rather than opening empty. `SmsAutoReplyTest::testNothingIsStored`
is retired at that point — it pinned a Tier 0 promise we are deliberately leaving behind, and the
test that replaces it should assert the copy matches the flag.

Enable it for Central Kansas first. They are the club that generated the replies, and the only one
whose families are actively texting.

### 2. Route inbound

`To` → `sms_phone_numbers` → club. That lookup is exact and only became possible when per-club
numbers shipped on 2026-07-30; with one shared number there was no way to know which club a reply
belonged to.

`From` → the person, via `te_normalize_sms_phone` against `guardians.mobile_phone`,
`athletes.phone`, `users.phone`. Three outcomes, all of which need defined behavior:

- **one match** → attribute the thread to them
- **no match** → thread still created, labelled "Unknown sender". **Never drop it.**
- **several** (a shared household mobile) → attribute to the primary guardian and mark the thread
  ambiguous in the UI. Do not guess silently.

### 3. Reply — `send-sms` with a thread

Replies go through the existing `SmsSendService::queueSms`, so they inherit everything already
built and tested: per-club sender resolution, the suppression/opt-out predicate, segment counting,
`from_number` recording, retry. A reply is an ordinary outbound message that happens to carry a
`conversation_id`.

**A reply must respect opt-out.** If the contact has STOPped, the composer is disabled with the
reason — not an error after the fact.

### 4. The inbox — `/communications/inbox`

Three panes under the existing nav (see mockup): status rail, conversation list, thread. Added to
the Communications dropdown next to Broadcast.

Design decisions the mockup encodes, each for a reason:

- **"Needs reply" is the default view**, not "newest". The job is clearing a queue. A thread needs a
  reply when its most recent message is inbound and unanswered by a human.
- **The auto-reply is shown in the thread**, marked as machine-sent. If it were hidden, an admin
  would write an answer that contradicts what the family already received.
- **The composer states the sending number and segment count.** Same rules as Broadcast, surfaced
  where the message is actually written.
- **Every thread names the crew member, their athlete and team.** A phone number is not a person;
  the admin needs to know who is asking before answering.
- **Quick actions in the thread header** — open profile, send portal invite, mark done — because the
  most common answer to these four replies *was* "send the invite".

### 5. The copy has to change in the same commit

The live auto-reply says:

> "Thanks for your message! This number is not monitored. Chat is in our new parent portal - check
> your email for an invite or ask your coach."

Once a human reads and answers these, that is **false**. Ship the new wording with the feature, not
after it. Constraints are unchanged and non-negotiable: **≤160 GSM-7 characters, ASCII only** — one
curly apostrophe forces UCS-2 and the limit drops to 70. Proposed (139):

> "Thanks for your message! Someone from your club will get back to you here. You can also chat
> with your coach in our parent portal."

Consider suppressing the auto-reply entirely once a club has an active inbox user — an instant
robot reply followed by a human answer is worse than just the human answer. Decide before shipping.

---

## Landmines

**STOP is not recorded until a send fails.** Found 2026-07-30: a guardian texted `Stop` then
`Start`; Twilio blocked at the carrier, and `email_suppressions` and `guardians.sms_opt_out` were
both left empty. Our only STOP sync is reactive — `handleStatusCallback` on error 21610, i.e. after
a later send has already failed. Now that inbound is captured, record the opt-out **at the moment
the STOP arrives**, and the un-suppress on `Start`. Until then, preview counts overstate.

**Do not let a thread become a second delivery-status vocabulary.** `communication_log.status`
already means queued/sent/delivered/failed. Thread state is `read_at` plus "was the last message
inbound" — derived, not a new stored status that can drift out of step.

**Identity resolution is the same `user_guardians` gap** behind the shared-email role loss and the
inferred portal status. This feature survives without it (attribute by phone, flag ambiguity), but
anything that later merges SMS with chat does not.

**Never merge an inbound SMS into a team chat conversation.** SMS is 1:1, team chat is group. One
mis-routed *"Ava is back in hospital"* reaches thirty families.

---

## Testing criteria

Fixtures mirror `tests/fixtures/production-schema.json`; verify against Neon before declaring done —
that is how the `phone_number NOT NULL` defect on 057 was caught.

### Capture and routing

| # | Test | Expected |
|---|---|---|
| C1 | Inbound to club 51's number | Threaded under club 51, never club 32 |
| C2 | Inbound to a number no club owns | Handled, logged, no crash, no misattribution |
| C3 | `From` in raw format vs stored `(785) 555-0100` | Matched via normalization, not string equality |
| C4 | `From` matches nobody | Thread created as "Unknown sender", **not dropped** |
| C5 | `From` matches two guardians (shared mobile) | One thread, primary guardian, flagged ambiguous |
| C6 | Two messages from the same person | Same `conversation_id`, one thread |
| C7 | Same person, two clubs | **Two** threads — clubs must not see each other's |
| C8 | Inbound row | `direction='inbound'`, visible on the contact's Communications tab |

### STOP

| # | Test | Expected |
|---|---|---|
| S1 | `STOP` arrives | Suppression + `sms_opt_out` recorded **immediately**, no auto-reply |
| S2 | `START` after STOP | Opt-out cleared, contact reachable again |
| S3 | Composer for a STOPped contact | Disabled, with the reason stated |
| S4 | "can we stop by the field at 6?" | Ordinary message — only the bare keyword counts |

### Reply

| # | Test | Expected |
|---|---|---|
| R1 | Admin replies | Goes out via `queueSms` from the club's number; `from_number` recorded |
| R2 | Club has no number configured | Refused with the existing message, not a silent failure |
| R3 | Reply >160 chars | Segment count shown before sending |
| R4 | Coach tries to open another club's thread | 403 |
| R5 | Reply appears in thread | `direction='outbound'`, same `conversation_id` |

### Inbox

| # | Test | Expected |
|---|---|---|
| I1 | Thread whose last message is inbound | Appears under "Needs reply" |
| I2 | After an admin replies | Leaves "Needs reply" |
| I3 | Auto-reply only | Still "Needs reply" — a robot answer is not an answer |
| I4 | Opening a thread | `read_at` set; unread count drops |
| I5 | Auto-reply in thread | Visibly marked machine-sent |

### Auto-reply copy

| # | Test | Expected |
|---|---|---|
| A1 | New wording | ≤160 chars, ASCII only |
| A2 | New wording | Does **not** claim the number is unmonitored |

### Manual QA

Text the club number from a real handset: reply arrives in the inbox attributed to the right crew
member; answering from the app arrives as a text from the club's number; STOP disables the composer
and records the opt-out.

---

## Sequencing

1. Migration 060 (columns + `inbox_enabled`) + capture + routing (C-tests) — the data has to exist before anything can show it. Capture ships unflagged; nothing is user-visible yet
2. STOP-on-inbound (S-tests) — a compliance fix, and it stands alone
3. Inbox read-only (I-tests) — at this point the four "where is my invite" replies are visible
4. Reply-as-SMS + the copy change together (R, A) — the copy cannot lag the capability

Step 3 is the point where this becomes worth having; 1–3 are shippable without 4.

## Open question

**Who counts as "the inbox user"?** All club admins share one queue, or is a thread assignable?
Shared is simpler and right for a club with one or two admins — which is every club today. Assignment
matters at the size where two people answer the same parent twice. Recommend shared now, with
"mark done" as the only state, and revisit if a club actually collides.
