# Test Plan — Tournament Comms Triggers

**Feature:** Phase 2A item #1 — wire tournament events to existing email/SMS comms pipeline.
**Date:** 2026-04-29
**Status:** Code complete; awaiting deploy + smoke test on prod with demo data.
**Related:** `database/migrations/019_tournament_notification_triggers.sql`, `services/TournamentNotificationService.php`, `services/MergeFieldService.php`, `services/RecipientService.php`, `api/tournament-gateway.php` (6 call-sites).

---

## Test environment

- **Database:** prod Neon (demo data).
- **Smoke testing strategy:** flip feature flags one kind at a time. Master flag (`TOURNAMENT_TRIGGERS_ENABLED`) must be on for any kind to fire.
- **Required env vars** (already on Heroku — verify before testing): `SENDGRID_API_KEY`, `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, `TWILIO_FROM_NUMBER`, `SENDGRID_FROM_EMAIL`, `REDIS_URL`, `APP_URL`, `JWT_SECRET`.
- **New feature flag env vars** (add to Heroku, default false):
  - `TOURNAMENT_TRIGGERS_ENABLED`
  - `TOURNAMENT_TRIGGER_REGISTRATION_ACCEPTED`
  - `TOURNAMENT_TRIGGER_REGISTRATION_DECLINED`
  - `TOURNAMENT_TRIGGER_REGISTRATION_WAITLISTED`
  - `TOURNAMENT_TRIGGER_PAYMENT_RECEIVED`
  - `TOURNAMENT_TRIGGER_SCHEDULE_PUBLISHED`
  - `TOURNAMENT_TRIGGER_MATCH_RESCHEDULED`
  - `TOURNAMENT_TRIGGER_SCORE_POSTED`
  - `TOURNAMENT_TRIGGER_WEATHER_DELAY`
- **Test accounts** (per memory):
  - Admin: `maggie+ms@4msquared.com` (user 50, club 32, club_admin)
  - Parent: `maggie+david@4msquared.com` (guardian 211)

## Test fixtures (preconditions for every scenario)

A demo tournament with known IDs:
- 1 tournament in `draft` or `registration_open` status, with `public_url_slug` set
- ≥1 division
- ≥1 group
- ≥2 teams registered, status `pending` or `accepted`, with at least one athlete + one guardian per team (so we have someone to email)
- ≥1 match scheduled with both teams slotted

If you don't have one, create it via the UI before running these scenarios. Note the tournament ID, division ID, registration IDs, and match ID.

---

## Cross-cutting (run first — gates everything else)

### CC-1. Master flag off → no triggers fire

**Preconditions:** `TOURNAMENT_TRIGGERS_ENABLED=false` (default).
**Steps:**
1. Accept a pending registration via API.
2. Inspect `communication_log` for new rows since the test started.

**Expected:** Zero new rows. No Redis queue push. Action still succeeds (registration moves to `accepted`).

### CC-2. Action succeeds even when comms throws

**Preconditions:** Master flag on, `TOURNAMENT_TRIGGER_SCORE_POSTED=true`. Temporarily break the templates by setting the seeded score-posted template to `is_active=false`.
**Steps:**
1. Score a match via `match-score`.
2. Check Heroku logs: expect a line `TournamentNotificationService [tournament.score_posted]: no active template found`.
3. Verify match was scored anyway (`status='completed'`, scores recorded).

**Expected:** Match scored, no fatal error returned to client, error logged. **Re-enable the template after this test.**

### CC-3. All merge fields resolve

**Preconditions:** Pick the registration_accepted template; manually preview its rendered output via the email-templates API or by triggering an accept and inspecting `communication_log.html_body`.
**Expected:** No literal `{{...}}` strings appear in subject or body. All these fields are populated:
- `{{guardian_first_name}}` (or "" if guardian has no first_name)
- `{{registration_team_name}}`
- `{{tournament_name}}`
- `{{tournament_start_date}}` (formatted like "Saturday, June 6, 2026")
- `{{tournament_end_date}}`
- `{{tournament_location}}`
- `{{tournament_url}}` (absolute URL, e.g., `https://teamselevated-backend.herokuapp.com/tournament/spring-cup-2026`)
- `{{division_name}}`

### CC-4. Migration is idempotent

**Steps:** Re-apply `019_tournament_notification_triggers.sql` to the same DB.
**Expected:** No errors. Still exactly 8 rows where `tournament_event_kind IS NOT NULL`.

### CC-5. Permission — coach cannot trigger admin-only actions

**Steps:** As a coach token (not club_admin), call `registration-update-status?action=accepted`.
**Expected:** 403, no notification fires, no DB change.

---

## Per-trigger scenarios

Each scenario is structured: **preconditions → steps → DB assertions → email/SMS assertions**.

DB assertions cover the queue-side: `communication_log` row created with status `queued`, eventually moves to `sent`. Email/SMS assertions cover delivery: open the SendGrid Activity feed (or the recipient's inbox) and confirm receipt with rendered merge fields.

---

### T1. Tournament — Registration Accepted

**Flag:** `TOURNAMENT_TRIGGER_REGISTRATION_ACCEPTED=true`

#### T1.1 Happy path
**Preconditions:** Registration R in status `pending`, with team having ≥1 guardian email.
**Steps:** PUT `/api/tournament-gateway?action=registration-update-status&id=R` body `{"status":"accepted"}`.
**DB:** `tournament_registrations.status='accepted'`. ≥1 new row in `communication_log` with `template_id` matching the platform `tournament.registration_accepted` template, `status='queued'` initially. Within ~1 minute, status moves to `sent`.
**Email:** Recipient receives email with subject `You're in! {team_name} accepted into {tournament_name}`. Body includes resolved tournament dates, location, link.

#### T1.2 Negative — already accepted
**Preconditions:** Same registration, already `accepted`.
**Steps:** Re-PUT with `accepted`.
**Expected:** No new `communication_log` rows (transition guard).

#### T1.3 Edge — suppressed recipient
**Preconditions:** Add the test guardian's email to `email_suppressions` for the club.
**Steps:** Accept a different pending registration tied to that guardian.
**Expected:** No `communication_log` row for the suppressed email; other recipients (athlete, coach) still get rows.

#### T1.4 Permission
**Preconditions:** Coach token belongs to a different team than R's team.
**Steps:** Coach attempts the accept call.
**Expected:** 403. No notification.

---

### T2. Tournament — Registration Declined

**Flag:** `TOURNAMENT_TRIGGER_REGISTRATION_DECLINED=true`

#### T2.1 Happy path
**Preconditions:** Pending registration R.
**Steps:** PUT body `{"status":"rejected"}`.
**DB:** Single new `communication_log` row addressed to the user who registered the team (not the whole roster — declined uses the submitter, not all parents).
**Email:** Subject `Update on your registration for {tournament_name}`. Compassionate tone, no event details.

#### T2.2 Negative — same status
**Steps:** Re-call with same status.
**Expected:** No new comm.

---

### T3. Tournament — Registration Waitlisted

**Flag:** `TOURNAMENT_TRIGGER_REGISTRATION_WAITLISTED=true`

#### T3.1 Happy path
**Preconditions:** Pending registration; division at capacity OR director chose waitlist manually.
**Steps:** PUT body `{"status":"waitlisted"}`.
**DB:** Single comm row to submitter only.
**Email:** Subject `{team} is on the waitlist for {tournament}`. Body explains "first to know if a spot opens up."

---

### T4. Tournament — Payment Received

**Flag:** `TOURNAMENT_TRIGGER_PAYMENT_RECEIVED=true`

#### T4.1 Happy path — unpaid → paid
**Preconditions:** Registration with `payment_status='unpaid'`.
**Steps:** PUT `/api/tournament-gateway?action=registration-update-payment&id=R` body `{"payment_status":"paid","payment_amount_cents":15000,"payment_reference":"chk-1234"}`.
**DB:** Row updated. Single comm row to submitter (payment receipts are a 1:1 transactional comm; do not blast the roster).
**Email:** Subject contains "Payment received". Confirmation of registration.

#### T4.2 Negative — paid → paid (idempotent re-write)
**Steps:** Re-PUT with same `payment_status=paid`.
**Expected:** No new notification (transition guard prevents duplicate).

#### T4.3 Negative — paid → refunded
**Steps:** PUT body `{"payment_status":"refunded"}`.
**Expected:** No notification (we have no `refunded` template; intentionally silent).

---

### T5. Tournament — Schedule Published

**Flag:** `TOURNAMENT_TRIGGER_SCHEDULE_PUBLISHED=true`

#### T5.1 Happy path
**Preconditions:** Tournament in status `scheduling` with several `accepted` registrations and matches generated.
**Steps:** PUT `/api/tournament-gateway?action=update-status&id=T` body `{"status":"in_progress"}`.
**DB:** Many comm rows — one per recipient across all accepted teams (athletes + guardians + coaches), deduplicated by email.
**Email:** Subject `Schedule is live — {tournament_name}`. Each recipient sees the same merged template.

#### T5.2 Negative — already in_progress
**Steps:** Status already `in_progress`; transition validation in the gateway will reject the request (`Invalid status transition from 'in_progress' to 'in_progress'`).
**Expected:** 400, no notification.

#### T5.3 Edge — empty tournament
**Preconditions:** Tournament with zero accepted registrations.
**Steps:** Move to `in_progress`.
**Expected:** Zero comm rows. No errors logged. (Empty recipient list is a legitimate no-op in `dispatchEmail`.)

---

### T6. Tournament — Match Rescheduled

**Flag:** `TOURNAMENT_TRIGGER_MATCH_RESCHEDULED=true`

#### T6.1 Happy path — change time
**Preconditions:** Match M slotted with both teams.
**Steps:** PUT `/api/tournament-gateway?action=match-update&id=M` body `{"scheduled_time":"2026-06-07 14:30:00"}`.
**DB:** Comm rows for both teams' rosters.
**Email:** Subject `Schedule change: {home} vs {away}`. New kickoff and field rendered.

#### T6.2 Happy path — change field only
**Steps:** Same handler, body `{"field_id":42}`.
**Expected:** Notification fires.

#### T6.3 Negative — change notes only
**Steps:** Body `{"notes":"Updated note"}`.
**Expected:** No notification (notes-only edits don't count as a reschedule).

#### T6.4 Negative — same field/time
**Steps:** Body with the same `field_id` and `scheduled_time` already on the match.
**Expected:** No notification (transition guard: prior == new).

#### T6.5 Edge — placeholder match (no teams slotted)
**Preconditions:** Knockout match with `home_placeholder='Winner Match 5'` and no `home_registration_id` yet.
**Steps:** Reschedule it.
**Expected:** No comm rows (no recipients to fan to). No errors.

---

### T7. Tournament — Score Posted

**Flag:** `TOURNAMENT_TRIGGER_SCORE_POSTED=true`

#### T7.1 Happy path — group stage
**Preconditions:** Match M in scheduled status with both teams.
**Steps:** PUT `/api/tournament-gateway?action=match-score&id=M` body `{"home_score":3,"away_score":1}`.
**DB:** Email comm rows for both teams' rosters. SMS comm rows for guardians who have `mobile_phone` populated AND have not opted out (`guardians.sms_opt_out=false`).
**Email:** Subject `Final: {home} 3 – 1 {away}`. Body includes the score, round, field, link to standings.
**SMS:** ≤160-char message: `Final ({tournament}): {home} 3–1 {away}. Standings: {url}`.

#### T7.2 Happy path — knockout with PKs
**Steps:** PUT `/api/tournament-gateway?action=match-score-knockout&id=M` body `{"home_score":1,"away_score":1,"home_penalty_score":4,"away_penalty_score":3}`.
**Expected:** Same fan-out as T7.1.

#### T7.3 Edge — guardian with no phone
**Preconditions:** Guardian record has `mobile_phone IS NULL`.
**Steps:** Score a match involving their team.
**Expected:** Email queued, no SMS for that guardian. Other guardians with phones still get SMS.

#### T7.4 Edge — re-score (correction)
**Steps:** Re-score the same match with corrected values.
**Expected:** Notification fires again. Documented behavior: corrections are news.

---

### T8. Tournament — Weather Delay

**Flag:** `TOURNAMENT_TRIGGER_WEATHER_DELAY=true`

#### T8.1 Happy path
**Preconditions:** Tournament in status `in_progress`.
**Steps:** PUT `/api/tournament-gateway?action=update-status&id=T` body `{"status":"weather_delay"}`.
**DB:** Email rows for all teams (`accepted` + `pending` + `waitlisted`). SMS rows for guardians with phones + no opt-out.
**Email + SMS:** Subject/body indicate delay, link to live updates.

#### T8.2 Edge — back to in_progress (delay lifted)
**Steps:** Move from `weather_delay` back to `in_progress`.
**Expected:** This fires `tournament.schedule_published` (because that handler's "entering in_progress" rule). **This may be undesirable for "delay lifted"** — note as a follow-up to consider a `tournament.delay_lifted` template if user feedback wants it.

---

## Smoke test sequence (recommended order on prod)

Flip flags one at a time; verify before flipping the next. After each, watch `heroku logs --tail -a teamselevated-backend` for `TournamentNotificationService` errors.

1. Set `TOURNAMENT_TRIGGERS_ENABLED=true`.
2. `TOURNAMENT_TRIGGER_REGISTRATION_ACCEPTED=true` → run T1.1 against a demo registration.
3. Roll out `_DECLINED`, `_WAITLISTED`, `_PAYMENT_RECEIVED`, `_MATCH_RESCHEDULED` next; each is low-volume.
4. `TOURNAMENT_TRIGGER_SCHEDULE_PUBLISHED=true` last from the low-volume set — this is the first multi-recipient broadcast.
5. `TOURNAMENT_TRIGGER_SCORE_POSTED=true` after #4 succeeds; this also fires SMS so verify Twilio cost.
6. `TOURNAMENT_TRIGGER_WEATHER_DELAY=true` last — biggest blast radius.

Kill switch: any panic, set `TOURNAMENT_TRIGGERS_ENABLED=false`. Takes effect on next request. No deploy needed.

## Acceptance for "done"

- [ ] Migration applied to prod (verified — already done).
- [ ] All four code files lint-clean (verified — already done).
- [ ] CC-1 through CC-5 pass.
- [ ] T1 through T8: at minimum the happy-path scenario (X.1) passes for each kind.
- [ ] One full end-to-end flow on a demo tournament: register team → accept → pay → schedule → score → all 5 emails arrive at the expected recipients with all merge fields resolved.
- [ ] Heroku worker is `up` and processing email_queue + sms_queue (verified before/after with `heroku ps -a teamselevated-backend`).
- [ ] SendGrid Activity feed shows expected sends, no bounces from rendering issues.
