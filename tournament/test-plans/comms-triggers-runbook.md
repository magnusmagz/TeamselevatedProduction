# Tester Runbook — Tournament Comms Triggers

A walk-through to validate each trigger end-to-end. **Action-first.** For full design + edge cases, see `comms-triggers.md`.

**Today's date:** 2026-04-29
**Heroku release:** v270 (or later)
**Tester:** maggie@4msquared.com (admin login: `maggie+ms@4msquared.com`)

---

## Pre-flight (one-time, ~3 min)

### 1. Confirm flag state

Run this in your terminal:

```bash
heroku config -a teamselevated-backend | grep TOURNAMENT_
```

You should see:
- `TOURNAMENT_TRIGGERS_ENABLED: true`
- One or more `TOURNAMENT_TRIGGER_*` values you've flipped to `true`
- The rest still `false`

### 2. Confirm worker is alive

```bash
heroku ps -a teamselevated-backend
```

Expect:
- `web.1: up`
- `worker.1: up` ← **this is the one that sends emails. If it's not up, scale it: `heroku ps:scale worker=1 -a teamselevated-backend`**

### 3. Open a log tail in a second terminal

```bash
heroku logs --tail -a teamselevated-backend | grep -E 'Tournament|email|sms|SendGrid|Twilio'
```

Leave this running. Anything weird shows up here.

### 4. Confirm test fixture

You're working with this demo data (already in prod):
- **Tournament:** Spring Classic 2026 (`tournament_id=1`, slug `spring-classic-2026`)
- **Division:** U14 State Cup (`division_id=3`)
- **Team:** Mustangs (`team_id=14`) — has 40 guardians + 1 coach
- **Test inbox:** `maggie+david@4msquared.com` (guardian David, real inbox you can check)
- **Registrations to use:** find any pending one in Spring Classic 2026 to accept/decline/waitlist. If none, create a new test registration via the UI first.

Open Gmail or whatever you use for `maggie+david@4msquared.com` in another tab.

---

## How to run each test

Each section below is **one trigger**. Steps are: enable flag → take action → verify inbox + DB. After each one, decide whether to roll forward to the next.

---

## T1. Registration Accepted

**Flag:** `TOURNAMENT_TRIGGER_REGISTRATION_ACCEPTED=true`

```bash
heroku config:set TOURNAMENT_TRIGGER_REGISTRATION_ACCEPTED=true -a teamselevated-backend
```

(Skip if already set.)

### Action
1. Log in to the CRM as `maggie+ms@4msquared.com`
2. Navigate to **Tournaments → Spring Classic 2026 → Registrations**
3. Find a registration in `pending` status. If none, register a team first.
4. Click **Accept**

### Expected — within 5 seconds
- ✅ UI shows the registration moved to `accepted` (no error)
- ✅ Log tail shows zero errors

### Expected — within 60 seconds
- ✅ `maggie+david@4msquared.com` inbox receives an email (only if Mustangs is the accepted team, since David is on Mustangs):
  - **Subject:** `You're in! Mustangs accepted into Spring Classic 2026`
  - **Body opens with:** `Hi David,`
  - **Body contains:** team name, tournament name (Spring Classic 2026), dates (Friday, May 8, 2026 – Sunday, May 10, 2026), location (Memorial Park, Seattle WA), View tournament page link
  - **No `{{...}}` placeholders anywhere**
- ✅ SendGrid Activity feed shows ~40 sends (one per recipient)

### Common gotchas
- Email may take up to 60s. If nothing after 90s, check log tail and `heroku logs --tail` without the grep filter.
- Demo `*@email.com` addresses will bounce — that's expected. SendGrid Activity will show "bounced" rows for those.
- If no pending registrations exist, create one: go to Registrations tab → **+ Register Team** → pick a team → submit.

### Pass / Fail / Notes
- [Pass] T1 passed
- Notes: ___________________________________________

---

## T2. Registration Declined

**Flag:** `TOURNAMENT_TRIGGER_REGISTRATION_DECLINED=true`

```bash
heroku config:set TOURNAMENT_TRIGGER_REGISTRATION_DECLINED=true -a teamselevated-backend
```

### Action
1. In Registrations tab, find a pending registration
2. Click **Decline** (or change status to `rejected`)

### Expected
- Inbox of the **registering user** (the one who submitted) — NOT all guardians — receives:
  - **Subject:** `Update on your registration for Spring Classic 2026`
  - **Body opens with:** `Hi {first_name},`
  - **Compassionate tone**, no event details

> **Mustangs case:** the registering user is **`eli@teamselevated.com`** (Elias Ulvi, user 69). Decline/waitlist/payment notifications go to him only, not to the 40 guardians. By design — these are transactional 1:1 comms.

### Pass / Fail / Notes
- [?] T2 passed
- Notes: Unsure if passed or fail, which user or person should have gotten an email in the instance of the mustangs?

---

## T3. Registration Waitlisted

**Flag:** `TOURNAMENT_TRIGGER_REGISTRATION_WAITLISTED=true`

```bash
heroku config:set TOURNAMENT_TRIGGER_REGISTRATION_WAITLISTED=true -a teamselevated-backend
```

### Action
1. Find a pending registration
2. Change status to `waitlisted`

### Expected
- Single email to the **registering user** only (Mustangs: `eli@teamselevated.com`)
- **Subject:** `{Team} is on the waitlist for Spring Classic 2026`
- Body says "first to know if a spot opens up"

> **Re-test gotcha:** the trigger only fires on actual *transition*. If the registration is already `waitlisted` and you "save" without changing it, no email fires. To re-test: move it back to `pending` first, then waitlist again.

### Pass / Fail / Notes
- [ ] T3 passed
- Notes: ___________________________________________

---

## T4. Payment Received

**Flag:** `TOURNAMENT_TRIGGER_PAYMENT_RECEIVED=true`

```bash
heroku config:set TOURNAMENT_TRIGGER_PAYMENT_RECEIVED=true -a teamselevated-backend
```

### Action
1. Find a registration with `payment_status=unpaid`
2. Mark it `paid` (whatever the UI control is — could be a dropdown or button)

### Expected
- Single email to the **registering user**
- **Subject:** `Payment received — {Team} confirmed for Spring Classic 2026`
- Body confirms tournament dates + location

### Edge case to verify
- Mark the same registration `paid` again (no-op transition). **Expect:** no second email.
- Mark `paid` → `refunded`. **Expect:** no email (no refund template; intentional).

### Pass / Fail / Notes
- [ ] T4 passed
- Notes: ___________________________________________

---

## T5. Schedule Published — ⚠️ multi-recipient blast

**Flag:** `TOURNAMENT_TRIGGER_SCHEDULE_PUBLISHED=true`

```bash
heroku config:set TOURNAMENT_TRIGGER_SCHEDULE_PUBLISHED=true -a teamselevated-backend
```

> ⚠️ **First high-volume trigger.** Fans to all accepted teams' rosters. Spring Classic 2026 has ~40 guardians on Mustangs alone. Watch SendGrid send count.

### Action
1. Tournament in status `scheduling`. (If it's `in_progress` already, you'll need a different test tournament or move it back to `scheduling` via direct DB if possible.)
2. Generate the schedule (Schedule tab → Generate)
3. Move tournament status to `in_progress` (this is the trigger point — the gateway transition `scheduling → in_progress` fires the comm)

### Expected
- One email per recipient across all `accepted` teams
- **Subject:** `Schedule is live — Spring Classic 2026`
- Each recipient sees their own first name in the greeting

### Pass / Fail / Notes
- [ ] T5 passed
- Notes: ___________________________________________

---

## T6. Match Rescheduled

**Flag:** `TOURNAMENT_TRIGGER_MATCH_RESCHEDULED=true`

```bash
heroku config:set TOURNAMENT_TRIGGER_MATCH_RESCHEDULED=true -a teamselevated-backend
```

### Action
1. Tournament must be at `in_progress` with at least one match scheduled and both teams slotted (not bracket placeholders)
2. Edit the match: change `scheduled_time` OR `field_id`
3. Save

### Expected
- Email goes to **both teams' rosters**
- **Subject:** `Schedule change: {home} vs {away}`
- Body shows new kickoff + field

### Edge cases
- Edit only `notes` (not time/field). **Expect:** no email (notes-only edit doesn't trigger).
- Edit and "save" without actually changing the value. **Expect:** no email (transition guard).
- Edit a knockout placeholder match (no teams slotted yet). **Expect:** no email (no recipients).

### Pass / Fail / Notes
- [ ] T6 passed
- Notes: ___________________________________________

---

## T7. Score Posted — ⚠️ email + SMS

**Flag:** `TOURNAMENT_TRIGGER_SCORE_POSTED=true`

```bash
heroku config:set TOURNAMENT_TRIGGER_SCORE_POSTED=true -a teamselevated-backend
```

> ⚠️ **First trigger that also fans SMS.** Verify Twilio cost before flipping if you're cost-sensitive.

### Action
1. Score a group-stage match (Schedule/Standings tab → enter score)

### Expected
- Email to both teams' rosters: subject `Final: {home} 3 – 1 {away}`
- SMS to guardians who have `mobile_phone` populated AND `sms_opt_out=false`: short text with the same score + standings link
- Standings update in the UI immediately

### Then test knockout
1. Score a knockout match (with PKs if it's a draw)
2. Same email + SMS expected

### Edge case
- Re-score the same match (correction). **Expect:** triggers fire again. This is intentional — corrections are news.

### Pass / Fail / Notes
- [ ] T7 group stage passed
- [ ] T7 knockout passed
- Notes: ___________________________________________

---

## T8. Weather Delay — ⚠️ biggest blast

**Flag:** `TOURNAMENT_TRIGGER_WEATHER_DELAY=true`

```bash
heroku config:set TOURNAMENT_TRIGGER_WEATHER_DELAY=true -a teamselevated-backend
```

> ⚠️ **Biggest fan-out.** Hits accepted + pending + waitlisted registrations. Fires both email AND SMS. Save for last.

### Action
1. Tournament must be `in_progress`
2. Move status to `weather_delay`

### Expected
- Email + SMS to everyone (all team rosters)

### To return to normal
- Move status back to `in_progress`. Note: this currently fires `tournament.schedule_published` again as a side effect (documented gotcha — see test plan §T8.2). If you want to test that quietly, turn `TOURNAMENT_TRIGGER_SCHEDULE_PUBLISHED=false` first.

### Pass / Fail / Notes
- [ ] T8 passed
- Notes: ___________________________________________

---

## Kill switch (any time)

If anything goes off the rails:

```bash
# Master kill — disables ALL kinds instantly
heroku config:set TOURNAMENT_TRIGGERS_ENABLED=false -a teamselevated-backend
```

Or kill one specific kind:

```bash
heroku config:set TOURNAMENT_TRIGGER_SCORE_POSTED=false -a teamselevated-backend
```

Takes effect on next request (no deploy). The action that would have triggered still completes — only the comm is suppressed.

---

## What "good" looks like across all 8

- ✅ Every email subject and body has zero `{{...}}` placeholders
- ✅ Recipient first names render correctly ("Hi David," not "Hi Guardian,")
- ✅ Tournament dates, location, links resolve to real values
- ✅ Score-posted SMS arrives on the test phone within ~30s of scoring
- ✅ Weather-delay broadcast hits both email + SMS
- ✅ Worker stays `up`, queue length returns to 0 within ~2 minutes of any send
- ✅ No `Failed: ` lines in `heroku logs --tail` for `TournamentNotificationService`
- ✅ SendGrid Activity feed shows expected counts (1 per recipient per trigger)

---

## When something fails

1. **Action errors out in UI:** copy the error, check `heroku logs --tail` for the matching stack trace. The action should NOT fail because of comms (comms is wrapped in try/catch). If the action fails, that's a different bug — note it.
2. **Action succeeds but no email arrives:**
   - Check log tail for `TournamentNotificationService [kind] failed`
   - Check the kind's flag: `heroku config:get TOURNAMENT_TRIGGER_<KIND> -a teamselevated-backend`
   - Check master flag: `TOURNAMENT_TRIGGERS_ENABLED`
   - Check worker dyno: `heroku ps -a teamselevated-backend`
   - Check email_queue length: usually 0 between triggers (worker drains quickly)
3. **Email arrives but rendering broken (`{{...}}` visible):** screenshot the subject/body and ping me — it's a template or merge-field issue.
4. **Wrong recipients:** note who got it and who didn't, plus the tournament/registration IDs. Recipient resolution lives in `RecipientService`.

---

## After all 8 pass

You're done with item #1. Triggers stay live (flags stay on) — no rollback needed.

Move on to item #2 (likely Tournament Cloning per current plan, or whatever you redirect to).
