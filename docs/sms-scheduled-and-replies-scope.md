# Scheduled Sends + Replies — Scope

**Status:** Planned, not started
**Written:** 2026-07-30 · for the week of 2026-08-03
**Supersedes:** Workstream C in `broadcast-sms-scope.md`, and Tier 1–2 of the reply tiers there.
**Related:** `../../unified-messaging-scope.md` (the full inbox, still out of scope)

Both features were scoped before per-club senders existed. That changed the ground under each
one — scheduled sends now have a "which number?" problem they didn't have, and replies became
routable in a way they weren't. This re-scopes both against what actually shipped.

---

## What changed underneath the original plans

Shipped 2026-07-30 (Heroku v451–v459):

- **`sms_phone_numbers`** — per-club senders. `te_resolve_sms_sender()` is the only answer to
  "what does this club send as", and it has **no fallback**: a club with no row cannot send.
- **`communication_log.from_number`** — the number in force at send time, not at read time.
- **Inbound auto-reply** (`api/webhooks/twilio-inbound.php`) — Tier 0. Answers replies, stores
  nothing, and the outgoing text says *"This number is not monitored."*
- **`queueSms` resolves the sender once per batch** and carries it in the Redis payload, so a job
  cannot be sent under a different number than it was queued for.

Two consequences that drive everything below:

1. **A scheduled send now has a sender question it didn't have.** Between scheduling and firing, a
   club can change its number, or clear it entirely and become unable to send at all.
2. **Replies are routable now.** `To` → `sms_phone_numbers.club_profile_id` is an exact lookup.
   With one shared number it was impossible to know which club a reply belonged to. That was the
   blocker, and it is gone.

---

## Part 1 — Scheduled sends

### Current state

`handleSendBroadcast` hard-rejects any `scheduled_at` with a 400
(`api/communications-gateway.php:490`): *"Scheduled sending is not available yet — please send
now."* That guard is the interim fix from the 2026-07-06 silent-failure sweep — before it,
scheduling stored a campaign that nothing ever dispatched.

`broadcast_campaigns` has `scheduled_at` and a `status` that accepts `'scheduled'`, so the table
looks ready. It is not.

### ⚠️ The blocker: the message body is not stored

Live columns:

```
id, club_profile_id, user_id, template_id, name, subject, channel, recipient_criteria,
status, scheduled_at, sent_at, total_recipients, sent_count, skipped_count, failed_count,
created_at, updated_at
```

**No `body`, no `html_body`.** For a send-now broadcast that's harmless — the body lives in the
request and goes straight to `queueSms`. For a scheduled one it means the campaign records
everything about the send *except what to say*. For SMS the only surviving trace is `name`, which
is `substr($body, 0, 80)` — truncated, and only by accident.

**A dispatcher alone cannot fix this.** Migration first.

> Note: an earlier version of this item in `CLAUDE.md` said "migration 057". 057 shipped as
> per-club SMS numbers; 058 and 059 are chat archive and chat retention. **Claim 060+**, and check
> `ls database/migrations/` in *both* the main checkout and `te-stripe-payments/` first.

### Build

**1. Migration 060** — `body TEXT`, `html_body TEXT` on `broadcast_campaigns`.

**2. Persist them** in the INSERT at `handleSendBroadcast`, for scheduled *and* immediate sends.
Immediate doesn't need it, but a campaign row that sometimes has a body and sometimes doesn't is a
trap for whoever writes reporting later.

**3. Dispatcher — a throttled tick inside `workers/queue-worker.php`, not a new process.**

That worker already runs as the `worker` dyno and already has exactly the right pattern —
`$lastImportSweep` at `:36`, used at `:64`. Add `$lastCampaignSweep` on the same model.

This is not a style preference. `workers/calendar-sync-scheduler.php` and
`waitlist-expiry-scheduler.php` are deliberately **not running** — scheduled jobs are off until a
paying customer justifies the dyno (auto-memory `project_scheduled_jobs_deferred`). A new scheduler
process hits that same wall on day one. A tick inside the already-running worker costs nothing.

Per tick: select due campaigns, claim one by flipping `'scheduled'` → `'sending'`, resolve
recipients from `recipient_criteria`, queue, mark `'sent'`.

**4. Remove the 400 guard** — only once 1–3 are proven, and delete its comment with it.

### The four things that will bite

**a. Which sender fires?** Resolve at **dispatch** time, not schedule time, and record it. A club
that changed numbers on Tuesday should send Wednesday's scheduled blast from Wednesday's number —
that's what families will have in their phones. `communication_log.from_number` already captures
the answer per message.

**b. A club with no sender at dispatch time.** `te_resolve_sms_sender` returns null and `queueSms`
throws `RuntimeException`. Inside a worker tick that is a real hazard: **an uncaught throw kills the
loop and every queue stops** — email, SMS, imports, calendar sync. Catch per campaign, mark it
`failed` with a reason, continue. Never let one club's misconfiguration take down the worker.

**c. Permission is checked at schedule time, enforced never.** If the scheduling user loses access
to a team — or leaves the club — before the send fires, it still goes. Re-check `user_club_access`
and team scope at dispatch via the existing `broadcastAuthError`, and fail the campaign rather than
sending outside scope.

**d. Staleness — the one people forget.** If the worker is down for eight hours, a campaign
scheduled for 8am fires at 6pm. "Practice is cancelled this morning" arriving that evening is worse
than never arriving. Needs an explicit window (proposed: **skip and mark `failed` if more than 2
hours late**, surfaced in reporting) rather than firing blindly.

### Deliberately out of scope

Editing or cancelling a scheduled campaign from the UI. Ship dispatch first; a cancel button on a
thing that has never dispatched is speculative.

---

## Part 2 — Replies

### Current state: Tier 0

Inbound SMS gets an auto-reply pointing at the parent portal. Nothing is stored — deliberately, and
`SmsAutoReplyTest::testNothingIsStored` fails if that changes.

### ⚠️ Building Tier 1 makes the current auto-reply text a lie

It says *"This number is not monitored."* The moment a human starts receiving these, that sentence
is false and has to change in the **same** commit — along with the test that pins the no-storage
promise. Treat the copy as part of the feature, not a follow-up.

Proposed replacement (must stay ≤160 GSM-7 chars, straight quotes only):
> "Thanks! We got your message and someone will follow up. For faster help, chat with your coach in
> our parent portal."

### Tier 1 — forward to a human (recommended floor)

Inbound webhook resolves club from `To`, sender from `From`, and emails the club admin via the
existing `EmailSendService`. Replies land somewhere a human already looks. No new UI.

### Tier 2 — also log it (recommended, small increment)

**Migration 061:** `communication_log.direction` (`outbound`/`inbound`, default `outbound`).

One column, and inbound replies then appear automatically in the **Communication Log** page and the
contact's **Communications tab** — both already built and deployed. No new screens.

**Do Tier 1 and 2 together.** They share the webhook, which is the bulk of the work; doing 2 later
means reopening the same file and re-testing the same paths.

### The reply loop already exists — don't build a reply box

`SmsCompose` is already on the contact's Communications tab with the guardian pre-selected. So:

> parent texts back → admin gets an email → clicks through to the contact → hits Send SMS

Round trip closed with zero new interface. That is the actual reason Tier 2 earns its half day over
Tier 1: it puts the inbound message *next to* the existing send button.

### Identity resolution — where this gets vague

`From` is a phone number. Matching it means checking `guardians.mobile_phone`, `athletes.phone`,
and `users.phone`, normalized through `te_normalize_sms_phone` (raw column values will not match
E.164 — the mistake that made preview and send disagree, see `broadcast-sms-scope.md` landmine 3).

Three outcomes, and all three need defined behavior:
- **exact match** → attribute to that person
- **no match** → still forward, labeled "unknown sender". Do not drop it.
- **multiple matches** (a shared household mobile) → forward once, attribute to the primary
  guardian, and say in the email that it's ambiguous. Do not guess silently.

The last case is the `user_guardians` gap again — the same missing link table behind the
shared-email role loss and the inferred portal status. Tier 1–2 can live without it; anything that
merges SMS and chat into one thread cannot.

### Explicitly not in scope

Threading, unread counts, Socket.IO, replying from an inbox, merging with chat. That is
`unified-messaging-scope.md` Phase 1, and it is gated on `user_guardians`, not on effort.

**Never merge an inbound SMS into a team chat conversation.** SMS is 1:1; team chat is group. One
mis-routed "Ava is back in hospital" reaches thirty families. If SMS ever merges with chat, it
merges with 1:1 DMs only.

---

## Testing criteria

Harness: PHPUnit against in-memory SQLite (`phpunit.xml`); frontend `react-scripts test`.
**Fixtures must mirror `tests/fixtures/production-schema.json`** — `MergeFieldServiceTest` invented
an `events` table and stayed green for months while production resolved every `{{event_*}}` tag to
nothing. And **verify against Neon, not the fixture**, before declaring done: that is how the
`phone_number NOT NULL` defect was caught on 057 and it would have 500'd in production.

### Scheduled sends

| # | Test | Expected |
|---|---|---|
| S1 | Schedule a campaign, inspect the row | `body` persisted **verbatim**, not truncated to `name`'s 80 chars |
| S2 | Body >80 chars, scheduled then dispatched | delivered in full |
| S3 | Tick with `scheduled_at` in the future | not sent |
| S4 | Tick with `scheduled_at` in the past | claimed once; a second tick does **not** re-send |
| S5 | Two ticks racing the same campaign | exactly one dispatch (claim via conditional UPDATE) |
| S6 | Club cleared its number between schedule and fire | campaign `failed` with a reason; **worker survives** |
| S7 | Scheduling user's club access revoked before fire | send blocked, campaign `failed` |
| S8 | Coach scheduled to a team they've since left | blocked at dispatch, not just at schedule |
| S9 | `scheduled_at` 3 hours late (worker was down) | skipped as stale, marked `failed`, not sent |
| S10 | Club changed its number between schedule and fire | sends from the **new** number; `from_number` records it |
| S11 | Immediate send (regression) | unchanged behaviour, campaign row still correct |

**S6 is the one to write first.** An uncaught exception in the worker tick stops email, SMS,
imports and calendar sync platform-wide — a far bigger blast radius than the feature itself.

### Replies

| # | Test | Expected |
|---|---|---|
| R1 | Inbound to club 32's number | resolved to club 32, never club 51 |
| R2 | Inbound to a number no club owns | handled gracefully, no crash, no misattribution |
| R3 | `From` matches `guardians.mobile_phone` in raw format | matched via normalization, not string equality |
| R4 | `From` matches nobody | forwarded as "unknown sender", **not dropped** |
| R5 | `From` matches two guardians (shared mobile) | forwarded once, ambiguity stated |
| R6 | Inbound row written | `direction='inbound'`, appears in Communication Log and the contact's tab |
| R7 | `STOP` | still silent, still no auto-reply, still no forward (regression on Tier 0) |
| R8 | Auto-reply copy | ≤160 chars, ASCII only, and **no longer claims the number is unmonitored** |
| R9 | Notification email | goes to club admins of the owning club only |

### Manual QA

| # | Scenario | Expected |
|---|---|---|
| M1 | Schedule a real send +10 min to one test phone | fires within one tick, body intact, correct number |
| M2 | Clear the club's number, wait for a scheduled fire | campaign fails cleanly; **check other queues still drain** |
| M3 | Reply to a club broadcast from a real handset | admin email arrives; reply visible on the contact |
| M4 | Reply STOP | no auto-reply, no forward, suppression recorded |

### Pre-production gate

1. Full suite green: `vendor/bin/phpunit`, `cd frontend && npm test`, and `npm run lint:ci` —
   the lint ratchet (ceiling 74) now gates Netlify, and `main` is shared, so a warning blocks
   everyone's deploy.
2. Apply the migration to Neon, then regenerate `production-schema.json` **from the database**
   rather than hand-editing. On 057 that caught a column another session had added and not recorded.
3. Schedule a send to a **single test phone** through the real product path — not a script.
   `scripts/send-kickoff-test.php` reflected the wrong service once and produced a phantom bug.
4. Then one small real team.

**Deploy order:** backend before frontend again *if* SMS still has no meaningful traffic — that is
what made v451 safe. If real families are receiving texts by then, revert to the default
frontend-first and re-read the deploy rules in `CLAUDE.md`.

---

## Sequencing for the week

1. **Migration 060 + persist body** — unblocks everything, half a day
2. **Dispatcher tick + S6 first** — the worker-survival test before the happy path
3. **Remove the 400 guard**, staged send test
4. **Replies Tier 1+2 together**, including the auto-reply copy change

1–3 are self-contained and shippable mid-week. 4 is the one with an open question worth deciding
before starting: **who gets the notification email** — all club admins, or the user who sent the
original broadcast? The latter is more useful and more likely to be read; the former survives
someone leaving.
