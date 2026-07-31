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


---

## The screen

`/communications/inbox`, in the Communications dropdown beside Broadcast. Rendered in
`docs/mockups/sms-inbox.html` using the app's real chrome — three panes under the existing top nav
and comms sub-nav.

```
┌──────────── top nav ─────────────────────────────────────────┐
│ All │ Inbox │ Broadcast │ Email Tpl │ SMS Tpl │ Reporting    │
├──────────┬──────────────────┬────────────────────────────────┤
│ status   │ conversations    │ thread                         │
│ rail     │ (list)           │ (messages + composer)          │
│ 176px    │ 320px            │ fills                          │
└──────────┴──────────────────┴────────────────────────────────┘
```

### Pane 1 — status rail

Counts, not just labels: **Needs reply (4)** · Unread · All · Done. Plus a "Sending as" line naming
the club's number, so the identity of outgoing replies is visible before you write one.

Needs-reply carries a coloured count; the rest are plain. Only one thing on this screen is urgent
and the rail should say which.

### Pane 2 — conversation list

Per row: crew member name, their athlete, a two-line preview, timestamp, and a state tag.
Unread rows carry a dot and heavier name weight; read rows drop to normal.

Sorted by **needs-reply first, then recency** — the job is clearing a queue, not reading a feed.
Filter chips repeat the rail for narrow screens.

State tags are semantic and mutually exclusive: `Needs reply` (amber), `Opted out` (red), or none.

### Pane 3 — thread

Header names the crew member, their athlete and team, and the number. Quick actions sit here
because they are the answer to most replies: **Open profile · Send portal invite · Mark done**.

Messages are bubbles on a day-marked timeline:
- **outbound** — right, brand primary, stamped with delivery status and the broadcast it came from
- **inbound** — left, neutral surface
- **auto-reply** — left, dashed border, labelled "Auto-reply sent"

That third style is not decoration. The system answers before any human sees the thread; hiding it
lets an admin write a reply that contradicts what the family already received.

Composer states **which number it sends from** and the live segment count, matching Broadcast's
rules at the point the message is written rather than after.

### States that must be designed, not discovered

| State | Behavior |
|---|---|
| No threads at all | "No replies yet." Not an error, and not a spinner that never resolves |
| Filter empty (e.g. no needs-reply) | "Nothing waiting on you." — a good outcome, worded like one |
| Contact opted out | Composer replaced by the reason; thread still readable |
| Unknown sender | Number as the title, reply still allowed, offer to attach to a crew member |
| Ambiguous sender | Primary guardian named, ambiguity stated in the header |
| Club has no number | Whole page replaced by the existing "no SMS number configured" message and a link to Messaging |
| `inbox_enabled` off | Route and nav item absent entirely — not a disabled tab |
| Send fails | Message stays in the composer with the error. Never silently lose typed text |

### Responsive

Under 900px the three panes stack: rail collapses into the filter chips, list and thread become
separate views with a back affordance. An admin answering from a touchline is the likely case, not
the edge case.

### API surface

| Endpoint | Purpose |
|---|---|
| `GET  /api/inbox?action=threads` | List, with filter + counts |
| `GET  /api/inbox?action=thread&id=` | Messages for one thread |
| `POST /api/inbox?action=read` | Mark read |
| `POST /api/inbox?action=reply` | Reply — delegates to `SmsSendService::queueSms` |
| `POST /api/inbox?action=done` | Mark done |

New gateway rather than extending `communications-gateway.php`, which is already ~1900 lines and
serves a different job. Club-scoped and admin-gated on every action; `broadcastAuthError` is the
pattern to copy, not to reimplement.

### Deliberately not built

Real-time push, typing indicators, attachments/MMS, search within threads, bulk actions, per-admin
assignment. The list refreshes on load and on poll. Socket.IO already exists for chat and can be
adopted later — it is an optimization, not a requirement, and adding it in M3 would couple this
feature to the chat server for no user-visible gain.


## Milestones

Five, each independently shippable and each leaving the product in a defensible state. **M1–M3
change nothing a family can see** — capture is invisible, and the inbox is admin-only behind a
flag that is off. The first family-visible change is M4, which is also the first that makes a
promise, and therefore the one where the copy has to move.

| | Milestone | Family-visible? | Ships behind the flag? |
|---|---|---|---|
| M1 | Capture inbound | No | No — always on |
| M2 | Record STOP on arrival | No | No — compliance |
| M3 | Read-only inbox | No (admin UI) | Yes |
| M4 | Reply as SMS + new copy | **Yes** | Yes |
| M5 | Polish: unknown senders, ambiguity, mark done | No | Yes |

Every milestone's fixtures mirror `tests/fixtures/production-schema.json`, and every migration is
rehearsed on a Neon branch before prod — 057's `phone_number NOT NULL` defect was caught only by
checking the real database after applying, on a table that happened to be empty. `communication_log`
is not empty.

---

### M1 — Capture inbound

Migration 060 and the webhook writing rows. Nothing reads them yet.

**Migration 060**

```sql
ALTER TABLE communication_log
  ADD COLUMN direction       VARCHAR(10) NOT NULL DEFAULT 'outbound'
    CHECK (direction IN ('outbound','inbound')),
  ADD COLUMN conversation_id VARCHAR(64),
  ADD COLUMN read_at         TIMESTAMP;

CREATE INDEX communication_log_conversation_idx
  ON communication_log (conversation_id, created_at);

ALTER TABLE sms_phone_numbers
  ADD COLUMN inbox_enabled BOOLEAN NOT NULL DEFAULT FALSE;
```

`DEFAULT 'outbound'` is what makes this safe on a live table: the 500+ existing rows are all
outbound and become correct without a backfill.

`conversation_id` = hash of `club_profile_id + normalized contact phone`. **Keyed on the club, not
the sender** — the club owns the number today, and when per-coach numbers arrive the key gains the
user without orphaning existing threads.

`api/webhooks/twilio-inbound.php` resolves `To` → club via `sms_phone_numbers`, `From` → person via
`te_normalize_sms_phone` across `guardians.mobile_phone`, `athletes.phone`, `users.phone`, and
writes an inbound row. It keeps sending the current auto-reply — storing is not monitoring, so
"this number is not monitored" stays true.

`SmsAutoReplyTest::testNothingIsStored` is retired here. It pinned a Tier 0 promise we are
deliberately leaving; it is replaced by A1/A2 in M4.

**Tests — M1**

| # | Test | Expected |
|---|---|---|
| M1.1 | Inbound to club 51's number | Row written, `club_profile_id` 51, never 32 |
| M1.2 | Inbound to a number no club owns | No crash, no row misattributed, logged |
| M1.3 | `From` raw vs stored `(785) 555-0100` | Matched by normalization, not string equality |
| M1.4 | `From` matches nobody | Row still written, recipient null, marked unknown — **never dropped** |
| M1.5 | Two messages, same person | Identical `conversation_id` |
| M1.6 | Same person texts two clubs | **Two** `conversation_id`s — clubs never share a thread |
| M1.7 | Existing outbound rows after migration | All read `direction='outbound'`, no backfill needed |
| M1.8 | Inbound row on the contact's Communications tab | Appears, without that page being changed |
| M1.9 | Auto-reply still sent | Unchanged wording at this milestone |

**Done when:** a text to the Kansas number appears as a row with the right club and person, and
nothing about the product has visibly changed.

---

### M2 — Record STOP when it arrives

Standalone compliance fix; valuable even if the inbox never ships.

Today the only STOP sync is reactive — `handleStatusCallback` on Twilio error 21610, i.e. after a
later send has already failed. Verified on 2026-07-30: a guardian texted `Stop` then `Start`, Twilio
blocked at the carrier, and `email_suppressions` and `guardians.sms_opt_out` both stayed empty.
Between a STOP and the next send, preview counts overstate and the failure surfaces as "failed"
rather than "opted out".

Now that inbound is captured, record it at arrival: `STOP` and friends write the suppression and set
`sms_opt_out`; `START`/`UNSTOP` clear both. Still no auto-reply to carrier keywords.

**Tests — M2**

| # | Test | Expected |
|---|---|---|
| M2.1 | `STOP` arrives | Suppression row + `sms_opt_out` set **immediately** |
| M2.2 | `START` after a STOP | Both cleared; contact reachable again |
| M2.3 | Any carrier keyword | Still empty TwiML — no auto-reply (regression on Tier 0) |
| M2.4 | "can we stop by the field at 6?" | Ordinary message; only the bare keyword counts |
| M2.5 | Preview count after a STOP | Excludes them without waiting for a failed send |
| M2.6 | STOP then a broadcast | They are skipped as `opted_out`, not `failed` |

**Done when:** texting STOP from a handset removes that person from the next preview count, with no
send in between.

---

### M3 — Read-only inbox

`/communications/inbox`, admin-only, behind `inbox_enabled`. Full screen spec above — panes, states, API surface. This milestone builds all of it **except** the composer, which is M4, and the unknown/ambiguous handling, which is M5.

Ships value on its own: at this point the four "where is my invite" replies are readable in the
product instead of via the Twilio API.

Thread state is **derived, not stored** — `read_at` plus "is the newest message inbound". Do not add
a second status vocabulary alongside `communication_log.status`; it will drift.

**Tests — M3**

| # | Test | Expected |
|---|---|---|
| M3.1 | Thread whose newest message is inbound | Listed under "Needs reply" |
| M3.2 | Thread whose newest is an admin reply | Not in "Needs reply" |
| M3.3 | Thread whose newest is the **auto-reply** | Still "Needs reply" — a robot answer is not an answer |
| M3.4 | Opening a thread | `read_at` set; unread count drops |
| M3.5 | Coach opens the inbox | 403 / not in nav — admin-only |
| M3.6 | Admin of club 32 requests a club 51 thread | 403 |
| M3.7 | `inbox_enabled = false` | Route and nav item absent |
| M3.8 | Auto-reply in a thread | Rendered as machine-sent, visually distinct |
| M3.9 | Thread header | Names crew member, athlete and team |

**Done when:** an admin can read yesterday's seven replies in the app, attributed to the right
families.

---

### M4 — Reply as SMS, and change the copy

The first family-visible milestone. **Both halves ship together** — the moment a human can answer,
"this number is not monitored" is false.

Replies go through `SmsSendService::queueSms`, inheriting per-club sender resolution, the
suppression predicate, segment counting, `from_number` and retry. A reply is an ordinary outbound
message carrying a `conversation_id`.

New copy, ≤160 GSM-7, ASCII only — one curly apostrophe forces UCS-2 and the limit drops to 70:

> "Thanks for your message! Someone from your club will get back to you here. You can also chat
> with your coach in our parent portal."

Send it only for clubs with `inbox_enabled`; unflagged clubs keep the current wording. That is the
whole reason the flag is per-club rather than global — the promise has to match who is actually
watching.

**Tests — M4**

| # | Test | Expected |
|---|---|---|
| M4.1 | Admin replies | Sent via `queueSms` from the club's number; `from_number` recorded |
| M4.2 | Reply row | `direction='outbound'`, same `conversation_id` |
| M4.3 | Club has no number configured | Existing refusal message, not a silent failure |
| M4.4 | Contact has STOPped | Composer disabled with the reason — not an error after sending |
| M4.5 | Reply >160 chars | Segment count shown before sending |
| M4.6 | Coach attempts a reply | 403 |
| M4.7 | A1: new copy | ≤160 chars, ASCII only |
| M4.8 | A2: new copy | Does **not** claim the number is unmonitored |
| M4.9 | Club with flag off | Still receives the old wording |
| M4.10 | Reply then refresh | Thread leaves "Needs reply" |

**Done when:** texting the club number from a handset and answering in the app lands a text back on
that handset, from the club's number.

---

### M5 — The awkward cases

Everything the happy path skips. Worth its own milestone because each is a decision, not a detail.

- **Unknown sender** — a thread with no matched person. Show the number, allow a reply, offer to
  attach it to an existing crew member.
- **Ambiguous sender** — a shared household mobile matching two guardians. Attribute to the primary
  and say so in the thread; never guess silently.
- **Mark done** — the only thread state beyond read. Shared queue, no per-admin assignment (see the
  open question).
- **Quick actions** — open profile, send portal invite. The most common answer to the seven real
  replies was literally "send the invite".

**Tests — M5**

| # | Test | Expected |
|---|---|---|
| M5.1 | Unknown sender thread | Listed, replyable, labelled unknown |
| M5.2 | Attaching an unknown to a crew member | Thread re-attributes; history preserved |
| M5.3 | Shared mobile, two guardians | One thread, primary guardian, flagged ambiguous |
| M5.4 | Mark done | Leaves "Needs reply"; a new inbound returns it |
| M5.5 | Send invite from the thread | Uses the existing invite path, no duplicate flow |

**Done when:** none of the seven real replies from 2026-07-30 land in a state the UI cannot explain.

---

---

## Landmines

**STOP is not recorded until a send fails.** Verified 2026-07-30: a guardian texted `Stop` then
`Start`, Twilio blocked at the carrier, and both `email_suppressions` and `guardians.sms_opt_out`
stayed empty. The only sync is reactive — `handleStatusCallback` on error 21610, i.e. after a send
has already failed. M2 exists to fix this.

**Do not create a second delivery-status vocabulary.** `communication_log.status` already means
queued/sent/delivered/failed. Thread state is `read_at` plus "is the newest message inbound" —
derived on read, never a stored status that can drift out of step with the messages it describes.

**Identity resolution is the `user_guardians` gap again** — the same missing link table behind the
shared-email role loss and the inferred portal status. This feature survives without it: attribute
by phone, flag ambiguity, allow manual attach (M5). Anything that later merges SMS with chat does
not survive without it, because chat identity is a `users` row and SMS identity is a phone number
on a `guardians` row.

**Never merge an inbound SMS into a team chat conversation.** SMS is 1:1; team chat is group. One
mis-routed *"Ava is back in hospital"* reaches thirty families. If SMS and chat ever share a view,
they share it with 1:1 DMs only.

**Guardian rows duplicate.** `createOrFindGuardian` matches on email + first_name with no last
name, and 25 guardians carry an empty-STRING email that compares equal. Two Taylor Cooks and two
Maddison Mathises were merged by hand on 2026-07-31. A thread keyed to a guardian id points at
whichever duplicate the router matched — so the underlying bug should be fixed before, or with, M1.

**A reply must respect opt-out.** `queueSms` already refuses a suppressed recipient, so an
un-guarded composer produces a send that silently skips. Disable the composer with the reason
instead of discovering it in `skipped_count`.


## Manual QA before M4 goes to a real club

1. Full suite green, plus `npm run lint:ci` — the ratchet gates Netlify and `main` is shared, so a
   warning blocks everyone.
2. Migration 060 rehearsed on a Neon branch, then applied; regenerate
   `tests/fixtures/production-schema.json` **from the database**, not by hand.
3. Text the club number from a real handset: reply appears attributed correctly; answering in the
   app arrives as a text; STOP disables the composer and records the opt-out.
4. Enable `inbox_enabled` for **Central Kansas only** — they generated the replies and are the only
   club whose families are actively texting.

**Deploy order:** backend before frontend while SMS traffic is still light, as with v451. Once
families are relying on it, revert to the default frontend-first and re-read the rules in CLAUDE.md.

---

## Open question

**Shared queue or assignable threads?** Shared is simpler and right for a club with one or two
admins — which is every club today. Assignment only matters at the size where two people answer the
same parent twice. Recommend shared, with "mark done" as the only state, and revisit when a club
actually collides.
