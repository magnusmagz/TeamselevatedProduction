# Broadcast SMS — Scope & Build Plan

**Status:** Workstreams A + B **deployed**. C + D still open.
**Written:** 2026-07-30 · **Built & deployed:** 2026-07-30

## Build status

| Workstream | State |
|---|---|
| A — Persist SMS broadcasts as campaigns | ✅ Deployed (Heroku v451 / Netlify `ccd2705`) |
| B — Broadcast compose UI (incl. club-wide) | ✅ Deployed |
| — Per-club SMS sender numbers | ✅ Deployed (migration 057, not in the original plan) |
| C — Scheduled broadcasts | ⬜ Open — needs a `body` column on `broadcast_campaigns` |
| D — Staff phone number on profile | ⬜ Open |

⚠️ **Broadcast SMS has never sent a real message.** It reached production before the staged gate
below ran — `main` is shared, and another session's push carried it. Run the gate on the first
real send.

⚠️ **SMS refuses for all 5 clubs until each configures a number** in Club Profile → Messaging.
Intended: `te_resolve_sms_sender` has no fallback to `TWILIO_FROM_NUMBER`, because a shared sender
makes one family's STOP silence every club at the carrier.

### What landed

**Backend**
- `lib/suppression.php` — new SMS half: `te_normalize_sms_phone`, `te_sms_suppression_map`,
  `te_sms_opted_out_guardian_ids`, `te_sms_skip_reason`. One predicate, bulk-loaded, scope-aware.
- `services/SmsSendService.php` — `queueSms` uses that predicate and bulk-loads once instead of
  two queries per recipient (a 300-person club-wide send was 600+ round trips). `normalizePhone`
  now delegates to `te_normalize_sms_phone` so there is a single normalization implementation.
- `api/communications-gateway.php` — `resolveBroadcastRecipients` gained `scope` +
  `clubProfileId`; `broadcastAuthError` extracted and shared by send and preview;
  `handlePreviewBroadcast` rewritten onto the shared predicate; `TE_COMMUNICATIONS_LIB_ONLY` guard.
- `lib/coach_scope.php` — new. `getCoachTeamIds` was copy-pasted byte-for-byte into two gateways;
  now one copy. (A conditional `function_exists` guard does **not** work in these files — it
  defeats PHP's early binding, so the LIB_ONLY early `return` skips the declaration entirely.)

**Frontend**
- `pages/BroadcastCompose.tsx` — new page at `/communications/broadcast`, in the Communications
  dropdown. The only caller of `send-broadcast` in the codebase.
- `utils/smsSegments.ts` — new. 160/153 and the segment maths shared with `SmsCompose`.
- `App.tsx` — route, nav entry, and an `exact` flag on the comms links (every comms route is
  nested under `/communications`, so a prefix match lit up "All" everywhere).

**Tests — 62 new, all passing**
- `BroadcastRecipientResolutionTest` (14), `BroadcastSuppressionParityTest` (11),
  `BroadcastScopeTest` (9) → PHP suite **333 passed, 921 assertions**.
- `BroadcastCompose.test.tsx` (14) → passing; `tsc --noEmit` clean.
- Pre-existing unrelated frontend failures: 10 suites / 33 tests (App, VenueManagement,
  payments, tournament, parent-portal). **Verified identical before and after** by stashing —
  not caused by this work, not fixed by it.

### Two extra bugs found and fixed while building

Neither was in the original plan; both were live.

1. **Exclusions collided across recipient types.** `exclude_ids` was a flat list of bare ids
   compared with `in_array`, so excluding guardian 5 also excluded athlete 5 — a different person.
   Exclusions are now keyed `"type:id"`. Bare ids still work and still match any type, which
   preserves the old behavior for any hand-rolled API caller; the UI sends typed keys.
2. **Dedupe missed the same person entered two ways.** SMS dedupe compared raw phone strings, so
   `(360) 555-1234` and `3605551234` were two recipients and got two messages. It now keys on the
   normalized number — falling back to the raw value when a number won't normalize, so an
   unreachable phone still *resolves*, gets counted, and is reported as skipped rather than
   silently vanishing from the total.
**Relationship to other docs:** This is the *outbound* half of SMS. `unified-messaging-scope.md`
covers *inbound* (replies, per-coach Twilio numbers, threaded inbox) and is roadmap item #27 at
P5. This doc is the prerequisite nobody wrote down: it closes the gaps in the broadcast send path
that already exists in production.

---

## Why this doc exists

Outbound SMS was never scoped as its own phase — it shipped inside the original email/SMS
communications build, whose spec is the "SMS Feature Requirements" section of `CLAUDE.md` plus
`email-sms-test-plan.md`. So a lot is already built and working, and the remaining gaps were never
written down anywhere. This doc is that missing list.

### Already built — do NOT rebuild

Code-verified 2026-07-30:

| Piece | Location |
|---|---|
| Send to N individually-resolved recipients | `handleSendSms`, `api/communications-gateway.php:351` |
| Team-level bulk send (both channels) | `handleSendBroadcast`, `:397` |
| Recipient resolution by team + type | `resolveBroadcastRecipients`, `:1549` — athletes, guardians, coaches; email and SMS branches both complete |
| Recipient count preview | `handlePreviewBroadcast`, `:588` |
| "Add whole team" in compose | `RecipientSelector.tsx` → `recipient-search?action=resolve-group` |
| Coach team scoping on send + preview | `getCoachTeamIds` checks in both handlers |
| Suppression + STOP opt-out at queue time | `SmsSendService.php:64-95`; STOP handling `:449-485` |
| Redis queue, retry, status callbacks | `sms_queue` → `SmsSendService::processJob`; `workers/queue-worker.php` |
| Segment/character counting | `SmsCompose.tsx:39-40` (160 single / 153 concatenated) |
| Missing-phone warning | `SmsCompose.tsx:281-293` |
| SMS templates | `SmsTemplates.tsx` → `email-templates.php?channel=sms` |

> `CLAUDE.md` still says SMS is "Free-form text only (no templates required for SMS)". That is
> stale — SMS templates exist and are in the nav at `/sms-templates`. Correct it when this
> workstream lands.

### The actual gaps

**`send-broadcast` has zero callers. For either channel.** `SmsCompose.tsx:148-151` and
`EmailCompose.tsx:244` both deliberately route every send — 2 recipients or 200 — through the
individual `send-sms` / `send-email` actions. The comment (tagged CA-49) explains why: those
actions take a `recipients` array, whereas `send-broadcast` takes `team_ids` + `recipient_types`
and would reject a resolved-chip payload as malformed.

So the broadcast pipeline is complete, tested-adjacent, and unreachable. Consequences:

1. **SMS blasts never create a `broadcast_campaigns` row**, so they are invisible as campaigns in
   reporting. They appear only as N individual `communication_log` rows with no grouping.
2. **No pre-send preview of the real number.** The user sees chips, not "139 of 142 will receive
   this; 3 have opted out."
3. **Payload size** — a 200-recipient team send is one large JSON POST of fully-resolved contacts.
4. **Scheduling is hard-blocked** at `:429-437` with a 400 ("Scheduled sending is not available
   yet — please send now"), the interim guard from the 2026-07-06 silent-failure sweep.

---

## ⚠️ Three landmines found while scoping — read before writing code

These are code-verified and each one will silently produce a wrong result if missed.

### 1. `broadcast_campaigns` cannot store a message body

The table's columns are:

```
id, club_profile_id, user_id, template_id, name, subject, channel, recipient_criteria,
status, scheduled_at, sent_at, total_recipients, sent_count, skipped_count, failed_count,
created_at, updated_at
```

There is **no `body` and no `html_body`**. The INSERT at `:459` never persists the message. For a
send-now broadcast this is harmless — the body lives in the request and goes straight to
`queueSms`. But it means a *scheduled* campaign stores everything about the send except what to
say. For SMS the only surviving trace is `name`, which is `substr($body, 0, 80)` (`:462`) —
truncated, and only by accident.

**This is the real reason scheduling can't ship, and a dispatcher alone would not fix it.**
Migration 057 must add the columns. See Workstream C.

### 2. `recipient_types` is singular in the broadcast API and plural in the group API

- `resolveBroadcastRecipients` (`:1555`, `:1596`, `:1652`) tests `in_array('athlete', …)`,
  `'guardian'`, `'coach'` — **singular**.
- `RecipientSelector.tsx:166` posts `recipient_types: ['athletes','guardians','coaches']` to
  `recipient-search?action=resolve-group` — **plural**.

Both are correct for their own endpoint. A new broadcast UI that copies the plural array from the
existing selector will resolve **zero recipients and send nothing**, with a 200 response and
`total_recipients: 0`. No error. Assert on this in tests rather than trusting review to catch it.

### 3. Preview and send disagree about opt-outs — ✅ FIXED

*Worse than first written. There were **two** independent mismatches, not one.*

`handlePreviewBroadcast` (`:628-650`) counts a recipient as suppressed only if there is an
`email_suppressions` row with `channel='sms'`. `SmsSendService::queueSms` skips on **either** that
row **or** `guardians.sms_opt_out = TRUE` (`:86-95`).

A Twilio STOP writes both (`:449-485`), so those agree. But an `sms_opt_out` set any other way —
admin toggle, data import, manual DB fix — has no suppression row, so **preview overcounts and the
send silently delivers to fewer people than promised**.

The second mismatch, found while building: preview compared the **raw** `guardians.mobile_phone`
value against `email_suppressions.phone`, which stores **E.164** (`handleStatusCallback` writes
Twilio's `$to` verbatim). `360-555-0201` never equals `+13605550201`, so preview's suppression
check could not match a real STOP *at all* — it wasn't merely incomplete, it was inert.

Both pushed the same way: preview promised more recipients than the send delivered. Both paths now
call `te_sms_skip_reason`. `BroadcastSuppressionParityTest` pins the predicate and keeps a faithful
reproduction of the old check alongside it, so a well-meaning revert reads as a failure rather than
a cleanup.

### Not a landmine, but note it

`resolveBroadcastRecipients` reads `athletes.phone` for athletes, `guardians.mobile_phone` for
guardians, and `users.phone` for coaches. All three columns exist. `users.phone` is very sparsely
populated, which is exactly what roadmap P0 #1 ("Add phone number to My Profile") is about — see
Workstream D.

---

## Workstreams

Ordered by dependency. A and B together are the shippable core; C and D are separable.

### A — Persist SMS broadcasts as campaigns *(core)*

Make a team-scoped SMS send go through `send-broadcast` so it produces a `broadcast_campaigns`
row and shows up in reporting.

- Fix the preview/send opt-out mismatch (landmine 3) by extracting the skip test into one helper
  used by both `handlePreviewBroadcast` and `SmsSendService::queueSms`. One predicate, two callers
  — not two implementations kept in sync by hand.
- Add a `TE_COMMUNICATIONS_LIB_ONLY` guard to `api/communications-gateway.php`, mirroring
  `api/recipient-search-gateway.php:13`, so `resolveBroadcastRecipients` and the new helper are
  unit-testable without a dispatch or a Neon connection.
- Leave `handleSendSms` alone. Ad-hoc and single-recipient sends keep working exactly as they do.

**No migration.** All columns used here already exist.

### B — Broadcast compose UI *(core)*

New page at `/communications/broadcast`, added to the `commsLinks` dropdown in `App.tsx:335-340`
(currently: All / Email Templates / SMS Templates / Reporting).

Group-first, which is the shape `send-broadcast` actually wants:

- Channel toggle — **SMS first**; the same screen serves email later at no extra backend cost.
- Team multi-select, scoped by role (admin: all club teams; coach: `getCoachTeamIds` only).
- Recipient-type checkboxes: Athletes / Crew / Coaches. Must emit **singular** values (landmine 2).
  Label them per `project-crew-terminology` — "Crew", never "Parents".
- Live count from `preview-broadcast`, debounced: *"142 recipients · 3 opted out · 139 will
  receive."*
- Message body with the segment counter from `SmsCompose.tsx:39-40`. Reuse those constants;
  don't retype 160/153.
- Expandable recipient list with per-person removal → `exclude_ids`.
- Send → `send-broadcast`, then surface `campaign_id` with a link into reporting.

Reuses `SmsCompose`'s existing warnings for missing phone numbers and suppressed contacts.

### C — Scheduled broadcasts

1. **Migration 057** — `057_broadcast_campaign_body.sql`: add `body TEXT` and `html_body TEXT` to
   `broadcast_campaigns`. (057 confirmed free; 048–056 taken. Re-check `ls database/migrations/`
   in **both** the main checkout and `te-stripe-payments/` before creating it, per CLAUDE.md.)
2. Persist `body`/`html_body` in the INSERT at `:459`.
3. **Dispatcher — piggyback on the existing worker, do not add a process.** `workers/queue-worker.php`
   already runs as the `worker` dyno and already has the throttled-sweep pattern
   (`$lastImportSweep`, `:34` and `:62-80`). Add a `$lastCampaignSweep` tick on the same model:
   select `broadcast_campaigns WHERE status='scheduled' AND scheduled_at <= NOW()`, claim by
   flipping to `'sending'`, resolve recipients from `recipient_criteria`, queue, mark `'sent'`.

   This matters for cost. `workers/calendar-sync-scheduler.php` and `waitlist-expiry-scheduler.php`
   are deliberately **not running** — scheduled jobs are off until a paying customer justifies the
   dyno (auto-memory `project_scheduled_jobs_deferred`). A new scheduler process would hit that same
   wall; a tick inside the already-running worker does not.
4. Remove the 400 guard at `:429-437` **only once 1–3 are proven**, and delete the comment with it.

**Sender identity note:** a scheduled campaign is queued by the worker, not by a request, so
permission was checked at *schedule* time. If the scheduling user loses access to a team before the
send fires, the send still goes. Re-check `user_club_access` at dispatch time.

### D — Staff phone number on profile

Roadmap **P0 #1**, and the reason coach SMS recipients resolve near-empty today: `users.phone`
exists but is rarely populated, so `resolveBroadcastRecipients`' coach branches (`:1702`, `:1727`)
filter almost everyone out.

- Add phone to the profile edit form; normalize through `SmsSendService::normalizePhone` (`:333`)
  so stored values are E.164 and match suppression lookups.
- **No migration** — `users.phone` already exists.

---

## Testing criteria

Existing harness: PHPUnit against in-memory SQLite (`phpunit.xml`, `tests/php/bootstrap.php`) —
tests never touch Neon. Frontend is `react-scripts test` (RTL + jest). Model new PHP tests on
`tests/php/SpecialRecipientGroupsTest.php` and new frontend tests on
`components/communications/SmsCompose.test.tsx`.

> Fixture rule, learned the hard way: any SQLite fixture must mirror
> `tests/fixtures/production-schema.json`. `MergeFieldServiceTest` created an `events` table that
> production did not have, and the suite stayed green for months while every `{{event_*}}` tag
> resolved to nothing in production. A fixture that does not mirror the snapshot is worse than no
> fixture.

### Must-pass — automated

**`BroadcastRecipientResolutionTest.php`** (new, needs the LIB_ONLY guard from A)

| # | Test | Expected |
|---|---|---|
| A1 | `recipient_types: ['athlete','guardian','coach']` on a team with all three | All three resolve with phone numbers |
| A2 | **Plural** `['athletes','guardians','coaches']` | Resolves **0** — locks landmine 2 so a future refactor can't silently reintroduce it |
| A3 | Athlete with `active_status = false` | Excluded |
| A4 | Athlete/guardian/coach with NULL or `''` phone | Excluded from SMS resolution |
| A5 | Guardian linked to two rostered athletes on the same team | Appears **once** (DISTINCT holds) |
| A6 | `exclude_ids` containing a resolved guardian | That guardian absent |
| A7 | Same team, `channel: 'email'` vs `'sms'` | Email uses `guardians.email`, SMS uses `mobile_phone`; email includes `personal_email` as a second row, SMS does not |

**`BroadcastSuppressionParityTest.php`** (new — landmine 3)

| # | Test | Expected |
|---|---|---|
| B1 | Guardian with `sms_opt_out = TRUE`, **no** `email_suppressions` row | Preview count and queued count **agree**; guardian in neither |
| B2 | Guardian with an `email_suppressions` row (`channel='sms'`), `sms_opt_out = FALSE` | Both exclude |
| B3 | Twilio STOP path — both set | Excluded once, not double-counted |
| B4 | Clean guardian | Included in both |

B1 fails against today's code. That is the point — it is the regression test for the bug.

**`BroadcastCampaignPersistenceTest.php`** (new — Workstream C)

| # | Test | Expected |
|---|---|---|
| C1 | Send-now SMS broadcast | `broadcast_campaigns` row: `channel='sms'`, `status='sent'`, `sent_at` set, `total_recipients`/`sent_count`/`skipped_count` matching the queue result |
| C2 | Scheduled broadcast (after migration 057) | `body` persisted verbatim — **not** truncated to `name`'s 80 chars |
| C3 | Body >80 chars, scheduled then dispatched | Message delivered in full |
| C4 | Dispatcher tick with `scheduled_at` in the future | Not sent |
| C5 | Dispatcher tick with `scheduled_at` in the past | Claimed once; a second tick does not re-send |
| C6 | Scheduling user's `user_club_access` revoked between schedule and fire | Send blocked |

**`BroadcastScopeTest.php`** (new — permissions; server-side enforcement per CLAUDE.md)

| # | Test | Expected |
|---|---|---|
| D1 | Coach broadcasts to their own team | 200 |
| D2 | Coach includes one team they don't coach | 403, **nothing queued** — not a partial send |
| D3 | Coach previews a team outside scope | 403 |
| D4 | Club admin, any team in club | 200 |
| D5 | Any user, team in a different club | 403 |

**`BroadcastCompose.test.tsx`** (new — Workstream B)

| # | Test | Expected |
|---|---|---|
| E1 | Select a team + Athletes/Crew, hit send | POST hits `action=send-broadcast` with `channel:'sms'` |
| E2 | Payload inspection | `recipient_types` is **singular** |
| E3 | Changing team or type selection | `preview-broadcast` re-fetched (debounced), count updates |
| E4 | Preview returns `suppressed: 3` | UI shows the opted-out count, doesn't silently drop it |
| E5 | Removing a person from the resolved list | Their id in `exclude_ids` |
| E6 | 161-character message | Segment counter reads 2 |
| E7 | Empty resolution (0 recipients) | Send button disabled with a reason |

**Regression — must still pass unchanged:** `SmsCompose.test.tsx` (individual sends keep using
`send-sms`), `suppression-scope-test.php`, `SpecialRecipientGroupsTest.php`,
`SchemaConformanceTest.php` (migration 057 must be reflected in the schema snapshot),
`AnalyticsReportingTest.php`.

### Must-pass — manual QA

Run in the style of `email-sms-test-plan.md`, against the club-32 / club-47 test accounts.

| # | Scenario | Expected |
|---|---|---|
| M1 | Club admin broadcasts to one small real team | All recipients receive one SMS each; no duplicates |
| M2 | The send appears in Communications → Reporting | Grouped as one campaign, not N loose rows |
| M3 | Guardian replies STOP, admin broadcasts again | That guardian excluded; count reflects it |
| M4 | Coach logs in | Team picker shows only their teams |
| M5 | Recipient with no phone | Warned pre-send, excluded, send still proceeds for everyone else |
| M6 | Same person as guardian of two athletes on one team | One message, not two |
| M7 | Schedule for +5 min, leave it | Fires within one worker tick; body intact |
| M8 | Long (>160 char) broadcast | Arrives as one concatenated message, not split visibly |

### Pre-production gate

Staged rollout, matching how the Stripe pilot was run:

1. Full suite green: `vendor/bin/phpunit` and `cd frontend && npm test`.
2. Send to a **single test phone** through the real broadcast path — not a script. Per CLAUDE.md:
   a send test must call the same service the product calls. `scripts/send-kickoff-test.php`
   reflected the wrong service and produced a phantom bug.
3. Send to **one small real team** (<10 recipients), confirm the campaign row and per-recipient
   `communication_log` status.
4. Only then open it club-wide.

**Deploy order:** frontend (`git push origin main`, wait for Netlify `ready`) **before** backend
(`git push heroku main:main`), per CLAUDE.md — and `git fetch heroku && git merge heroku/main`
first. Never `netlify deploy --prod`. Migration 057 applies to Neon manually before the backend
push.

---

## Out of scope

Deliberately excluded — these belong to `unified-messaging-scope.md` (roadmap #27):

- Inbound SMS / replies / `twilio-inbound` webhook
- Per-coach Twilio numbers, `staff_phone_numbers`, A2P 10DLC provisioning
- Threaded conversation UI, unread counts, the unified inbox
- `communication_log.direction` / `conversation_id` / `read_at`

Also out of scope here: the email side of `send-broadcast`. Workstream B's channel toggle makes it
reachable, but email broadcast QA is its own pass.

---

## Decisions made (2026-07-30)

1. **Club-wide broadcast is in scope now**, alongside the team picker. Rationale: a club-scoped
   send reaches athletes who have registered but are not yet rostered — exactly the population a
   team picker structurally cannot see, and exactly who a season-open announcement is for.

   **Design:** `send-broadcast` gains `scope: 'club' | 'teams'` (default `'teams'`, so the existing
   contract is unchanged). `recipient_types` keeps identical meaning in both scopes — that is the
   whole point of extending `resolveBroadcastRecipients` rather than routing through the
   `special_group` ids in `recipient-search-gateway.php`. Those ids (`all`, `all_crew`) bundle type
   selection into the group name and cannot express "athletes only, club-wide."

   **Permission:** club-wide requires `club_admin`. A coach broadcasting to the entire club is a
   scope escalation — their team picker is the boundary.

   Club-scoping follows the existing club-wide queries in `resolveSpecialGroup`
   (`recipient-search-gateway.php:1042-1073`): athletes by `athletes.club_id` + `deleted_at IS NULL`
   + `active_status`, guardians through `athlete_guardians` → those athletes, coaches via
   `user_club_access` (role `coach`/`club_admin`, `active = true`) — authoritative, not `users.role`.

2. **Coaches unchecked by default.** Athletes + Crew on, Coaches available but off. A message
   written for families reads oddly to staff.

3. **No send confirmation dialog.** The live preview count is on screen next to the button. Cost
   guardrails are deferred to the platform-absorbs-cost discussion in `unified-messaging-scope.md`.

### Worth reusing: `resolveSpecialGroup` already solved landmine 3

`recipient-search-gateway.php:935-1010` is the better implementation of recipient resolution, and
the club-wide work should follow it rather than the team path:

- It bulk-loads all club suppressions into a map **once** instead of one query per recipient. Its
  own comment explains why: the team path's per-person `checkSuppression()` is fine for ~20 people
  and times the request out at 200+.
- It **already checks `guardians.sms_opt_out`** (`:990-997`) alongside the suppression table — the
  exact parity fix Workstream A owes the team path.
- It dedupes by resolved send address across recipient types, so a guardian who is also a coach
  gets one message.
- It **flags** suppressed recipients and returns a count rather than silently dropping them.

Port these four properties into `resolveBroadcastRecipients`; do not write a fifth resolver.
