# Chat Notifications — Scope

Reviewed 2026-08-20. Scope confirmed 2026-08-25: **email AND web push**.
Nothing built yet. Read this instead of re-deriving it.

Related: `chat-moderation-plan.md` (M5 admin notice), `sms-scheduled-and-replies-scope.md`
(the dispatcher-tick pattern this reuses), auto-memory
`project-worker-db-connection-dies-idle.md` (the prerequisite).

---

## 1. The decision

Email and web push, both. Push was previously deferred; moved into scope
2026-08-25 after the iOS constraint was stated — on iPhone, web push only
reaches a PWA the parent has added to their home screen. Maggie owns that
tradeoff. **Do not re-raise it as a blocker.**

SMS was rejected as a channel: real per-message cost, and per-message texting
on a chatty channel gets muted.

The two channels are complementary, not duplicate:

> **Try push first. Fall back to email when there is no live subscription or
> the push fails.**

Without that rule an installed user gets a push *and* an email for every
message. The email dispatcher runs minutes later and has no way to know a push
already went out, so **push must write a marker the email pass reads**.

---

## 2. Cost

Planning figure: 30 parents, 200 chat messages/week. Digest collapses bursts,
so roughly 1,500–2,500 emails/month.

| Line | Monthly |
|---|---|
| Heroku worker dyno | $0 — dispatcher is a tick in the worker already running |
| Chat server | $0 — needs no change |
| Neon | $0 — one small table plus marker columns |
| Web push delivery | $0 — there is no per-message charge |
| SendGrid | $0–20, depending on current plan tier |

**Estimate $0–20/month, most likely $0.** The only real variable is whether
added volume tips SendGrid to the next tier. Confirm the current plan before
building — it is the one number that decides.

---

## 3. What exists (verified in code — do not re-derive)

- Real-time in-app only. `chat-server/server.js` emits `receiveMessage` plus a
  conversation-list update.
- Badges work app-wide **while a tab is open**: staff via `<ChatWidget />`
  (`App.tsx:1116`), parents via `ChatProvider` in `ParentPortalLayout` →
  `BottomNavigation.tsx:61`.
- Auto-flagging already fires on every message (`evaluateMessage` →
  `chat_message_reports`).
- **The PWA is already installable** — `frontend/public/manifest.json` has
  `display: standalone`, 192/512 icons, maskable variants. No manifest work.
- **The service worker is hand-written and registered in production** —
  `frontend/public/service-worker.js` (97 lines, cache-only), registered from
  `frontend/src/index.tsx:18` via `serviceWorkerRegistration.ts`, served at
  `/service-worker.js`. Edit it directly; it is NOT workbox-generated.

## 4. What does not exist

- **No out-of-app notification of any kind.** No email, push or SMS from chat.
- Service worker has `install` / `activate` / `fetch` / `message` handlers only
  — **no `push`, no `notificationclick`**. No VAPID, no subscription table.
- **No PHP web-push library.** `composer.json` requires only phpmailer, predis,
  stripe. Needs `minishlink/web-push`. ⚠️ Verify Heroku has the extensions it
  wants — openssl/curl/mbstring are default, **gmp/bcmath may need enabling**.
  Check this in phase 2, not after writing the dispatcher.
- **`notifications` table is dead** (`id, user_id, type, title, message, data,
  read_at, created_at`) — exists in Neon, zero reads or writes anywhere.
- **`conversation_participants.muted` is dead** — column exists, zero references.
- **Admins are never told about flagged messages.** `ChatModeration.tsx` is
  pull-only; `chat-moderation-plan.md:328` still unchecked.
- **The chat server cannot send anything** — deps are socket.io, pg, dotenv,
  jsonwebtoken. It needs no change for this work.

---

## 5. ⚠️ The hard problem: "who missed what" is broken for team chats

**This is the risk in the whole build. Solve it before writing any send code.**

`server.js:305` reads the watermark as:

```js
const lastReadId = readReceipt.rows[0]?.last_read_message_id || 0;
```

Team conversations are created by `ensureTeamConversation()` with **no
participant rows** — members reach them through the team-scope branch of
`getUserConversations`, not through a membership row. So for a parent who has
never opened a team chat there is no row at all, the watermark falls back to
**0**, and *every message ever sent in that conversation* counts as unread.

For a badge that is cosmetic — a number reads too high. **For notifications it
means the first dispatcher run emails people the entire history of a
conversation they never opened.**

This is not hypothetical. `chat-server/lib/archive.js:19` records that team-chat
unread badges never cleared for exactly this reason, and `MARK_READ_SQL` had to
become an UPSERT because a bare UPDATE hit zero rows.

**Rule: absent participant row means "notify about nothing yet", never
"notify about everything".** A user's notification watermark starts at the
newest message at the time they gain access, not at zero.

---

## 6. Build order

**0. Worker DB reconnect fix — prerequisite, ~1 hour.**
`workers/queue-worker.php:23` opens one PDO handle at boot and shares it with
`EmailSendService`, `SmsSendService`, `ImportJobProcessor` and
`CalendarSyncService` for the dyno's whole life. Neon drops idle connections,
PDO does not reconnect. A dispatcher runs on a timer and would hit the dead
handle every night — which is exactly when nobody is in the app, i.e. the whole
point of this feature. Fix: ping/reconnect at the top of the loop, resolve `$db`
per job. See `project-worker-db-connection-dies-idle.md`.

**1. "Who missed what" — its own piece, no sending.**
Shared by both channels, so build once. Section 5 is the whole content of this
phase. Migration **073** (072 is `user_guardians`): `chat_notification_prefs`,
a `notified_at` marker, and a per-user notification watermark that does not
depend on a participant row existing.

**2. Email for missed messages.** Dispatcher tick in the existing worker.

**3. Admin alert on high-severity flags** + count badge on the Chat Moderation
nav. **Moved earlier deliberately** — auto-flagging fires today and nobody is
told, which is a child-safety gap open right now. It is small and shares none
of the digest logic, so it does not need to wait behind push.
**`chat-moderation-plan.md:328` proposes a weekly digest PLUS individual alerts
for high severity only** — match that, don't collapse it to one or the other.
The reasoning there is that admins tune out per-flag alerting.

**4. Web push.** VAPID keys, `push_subscriptions` table (per-device — one user
has many), `push` + `notificationclick` handlers added to the existing service
worker, permission-request UI.

**4a. iOS "Add to Home Screen" prompt — design work, not engineering.**
Tracked separately because it is the piece that decides whether push reaches
most of the 30 parents or almost none, and it needs real thought about wording
and timing. Engineering cannot absorb it as a checkbox.

**5. In-app notification centre** using the currently-dead `notifications` table.

**5a. No notification bell — decided 2026-08-26.** A bell was built and then
removed. Maggie's question was the right one: staff already see unread chat in
the `ChatWidget` bubble, families in the parent portal's bottom nav, and admins
got a Reported Messages count badge in phase 3. A bell showed the same three
things a second time.

What survives is server-side and invisible: the dispatcher writes one
`notifications` row per notification it closes, and `api/notifications.php` can
read them back. That is worth keeping for one concrete reason — a person with no
email address and no push device has to be marked as told, or the dispatcher
re-derives them as owed on every tick forever. `in_app` is a real channel in
`chat_notification_state.last_notified_channel` (migration 077) for exactly that
case. If a non-chat notification type ever needs a surface, the record is already
there and the bell can come back.

---

## 7. Invariants

⚠️ **Use `lib/Email.php` with `->forClub()`, NOT `EmailSendService`.**
`EmailSendService` writes a `communication_log` row per send (floods Email
Reporting with chat noise) and applies `email_suppressions` — so a parent who
unsubscribed from club *marketing* would silently stop getting chat alerts from
their coach. Both failures are silent, which is why this is a scan test.

⚠️ **An uncaught throw in the tick stops every queue** — email, SMS, imports,
calendar sync all share that worker. Catch per conversation. Same warning the
scheduled-SMS scope carries.

⚠️ **Push needs its own throttle.** "Instant" sounds right and is wrong: a coach
sending six messages becomes six buzzes, which is how people learn to mute.
Collapse pushes over a short window — shorter than the email window, not zero.

⚠️ **Prune dead push subscriptions.** Push services answer 404/410 when a
subscription expires (cleared browser, reinstall, new phone). Delete on that
response or the table fills with garbage and every send wastes a request.

⚠️ **VAPID private key is a Heroku config var, never committed.** The public key
ships in the frontend bundle — that is expected and fine.

---

## 8. Test outline

Nothing exists yet. The bar is 82 PHP tests, 8 chat-server suites, 64 frontend.
The pattern worth copying from this repo's own history: the guards that earn
their keep are **scans that catch a whole class**, not single unit tests —
several exist precisely because a fix landed in one file and missed three others.

**Write this one FIRST, before any dispatcher code:**

1. **A throw in the tick must not kill the worker.** Simulate one club failing
   (e.g. cleared sender config) and assert the other campaigns still process and
   the loop survives. Per-conversation catch.

Then:

2. **No participant row ⇒ notify about nothing.** The section 5 case. Assert a
   user with no `conversation_participants` row on a team conversation is owed
   zero notifications, not the full history. This is the test that would have
   caught the bug found on 2026-08-25.
3. **No double-send.** One message, one user, one notification — never both a
   push and an email. Asserts the push marker is written and the email pass
   reads it.
4. **Scan: chat alerts go through `lib/Email.php` with `->forClub()`**, never
   `EmailSendService`. Same shape as the existing `EmailSenderTest`, which
   already counts `new Email()` against `->forClub(` per file.
5. **Digest collapse.** Six messages in one window produce one email, not six.
6. **Dead subscriptions are deleted** on a 404/410 from the push service.
7. **Suppression list does NOT apply to chat alerts** — the inverse of the usual
   rule, and the reason invariant 1 in section 7 exists.

---

## 9. Defaults — CONFIRMED by Maggie 2026-08-25

- **On by default**, opt-out per conversation via the existing unused `muted`
  column. (This is the first thing that column has ever been read for — see
  section 4.)
- **Team chat and DMs both.**
- **One digest per conversation per window**, never one per message.
- **~5 minute email delay**, so an active back-and-forth never emails
  mid-exchange.
- **Push collapsed over a shorter window** — see the throttle invariant in
  section 7. Shorter than the email window, not zero.

These are settled. Changing one is a product decision, not a refactor.
