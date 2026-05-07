# Pre-Launch Checklist

One-time setup steps that aren't in code and are easy to forget. Walk this list before pointing a real customer at the platform. Routine deploy steps are in `DEPLOYMENT.md`; this is the "first-time you spin up a real customer" list.

---

## Heroku Scheduler jobs

The Heroku Scheduler add-on runs PHP scripts on a cron-like cadence. None of these will fire until they're registered in the Heroku dashboard (`heroku addons:open scheduler -a teamselevated-backend`).

| Job | Cadence | Purpose | Symptom if missing |
|---|---|---|---|
| `php workers/waitlist-expiry-scheduler.php` | Every 15 minutes | Marks waitlist offers expired after their 48-hour deadline + cascades to next team | Stale "Offered" badges in the registration manager. Director can manually re-promote until this is on. |
| `php workers/calendar-sync-scheduler.php` | Every 10 minutes | Pulls ICS feed updates for active calendar subscriptions | Subscribed calendars never refresh |

Add others here as new schedulers ship.

---

## Worker dyno

```
heroku ps -a teamselevated-backend
```

Look for `worker.1: up`. If it's `worker.1: idle` or missing, scale it up:

```
heroku ps:scale worker=1 -a teamselevated-backend
```

If the worker is at 0:
- Outbound emails queue but never send
- SMS queue but never send
- The data importer sits in `queued` forever
- Calendar sync jobs never get drained

Cost: roughly $7/month flat (Heroku Basic dyno).

---

## Required env vars

Set via `heroku config:set KEY=value -a teamselevated-backend`. Verify with `heroku config -a teamselevated-backend`.

| Var | Used by | Notes |
|---|---|---|
| `DB_HOST` / `DB_NAME` / `DB_USER` / `DB_PASSWORD` | All DB access | Direct Neon connection — *not* a Heroku Postgres add-on |
| `SENDGRID_API_KEY` | EmailSendService | Outbound email |
| `SENDGRID_FROM_EMAIL` | EmailSendService | Verified sender domain |
| `SENDGRID_FROM_NAME` | EmailSendService | Display name on outbound |
| `TWILIO_ACCOUNT_SID` / `TWILIO_AUTH_TOKEN` / `TWILIO_FROM_NUMBER` | SmsSendService | Outbound SMS |
| `REDIS_URL` | RedisQueue (worker dyno) | Job queue + cache |
| `APP_URL` | Email links (waitlist, unsubscribe, etc.) | Public-facing URL of the frontend (e.g. `https://teams-elevated.netlify.app`) |
| `JWT_SECRET` | AuthMiddleware | Token signing |
| `GOOGLE_MAPS_API_KEY` | (frontend, set on Netlify) | Address autocomplete |

Tournament notification triggers are gated behind feature flags — flip them on once you've smoke-tested:
- `TOURNAMENT_TRIGGERS_ENABLED=1` (master)
- `TOURNAMENT_TRIGGER_REGISTRATION_ACCEPTED=1`
- `TOURNAMENT_TRIGGER_WAITLIST_OFFER=1` (and the other per-kind flags)

---

## Webhooks

### SendGrid
Configure event webhook in the SendGrid dashboard → **Settings → Mail Settings → Event Webhook** to:
```
{APP_URL}/api/webhooks/sendgrid
```
Subscribe to: delivered, opened, clicked, bounced, dropped, spam_report, unsubscribe.

### Twilio
Configure status callback on the messaging service → **Status Callback URL**:
```
{APP_URL}/api/webhooks/twilio-status
```

Without these, comms still send but delivery state never updates in the communication log.

---

## Database migrations

Walk `database/migrations/` in numeric order. As of the current main branch, latest is **034_waitlist_auto_cascade.sql**.

```
heroku run "PGPASSWORD=\$DB_PASSWORD psql -h \$DB_HOST -U \$DB_USER -d \$DB_NAME -f database/migrations/0NN_xxx.sql" -a teamselevated-backend
```

All migrations are designed to be idempotent (`IF NOT EXISTS` / `WHERE NOT EXISTS` guards) so running an already-applied migration is safe.

---

## Frontend (Netlify)

Auto-deploys from `main` on push. One-time config:
- Netlify env: `REACT_APP_API_URL=https://teamselevated-backend-0485388bd66e.herokuapp.com`
- Netlify env: `REACT_APP_GOOGLE_MAPS_API_KEY=…`
- Domain: confirm the production domain in Netlify dashboard matches `APP_URL` on the backend (so email links land on the right host).

---

## Smoke test before handing keys to a customer

1. Log in as a club admin → can see dashboard
2. Create a tournament → publish → public microsite renders at `/tournament/{slug}`
3. Open team form → upload logo → save → reload → logo persists (regression check for the team-branding fix)
4. Calendar → click an event → RSVP "Attending" → reopen → status round-trips
5. Send a test broadcast email → arrives within ~30s, status flips to "delivered" in the log within 2 min
6. (Once Heroku Scheduler is on) waitlist offer → wait past expires_at → cron marks it expired and cascades

If any step fails, the worker dyno or a webhook is the most likely culprit.
