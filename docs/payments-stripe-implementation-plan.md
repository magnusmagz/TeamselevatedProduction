# Payments Implementation Plan — Stripe Connect

**Status:** Approved direction as of 2026-07-06. Processor decision: Stripe Connect (Express accounts, direct charges) + Stripe Checkout. See `~/.claude` auto-memory `project-payment-processor-decision.md` for the full processor evaluation (Finix is the fallback; Maverick deferred to a volume-renegotiation conversation).

**Owner:** Maggie (PM) · 2 eng + 1 designer
**Codebase:** NEW backend/frontend at `teamselevated/` (git-active). Do not touch `teamselevated-backend-folder/` (OLD) or `TeamselevatedProduction/`.

---

## Product goals

1. **Real money movement** — replace the demo `StubPaymentProcessor` with live card (later ACH) processing; each club is its own merchant (Stripe connected account), platform can take a per-transaction fee.
2. **Split payment between parents** — two guardians pay one athlete's invoice from separate cards/banks, unequal amounts, tracked against one balance.
3. **Contribution link ("sphere of influence")** — a shareable public link where anyone can pay any amount toward a child's camp/registration balance until it's covered.

### Two design decisions that govern everything

- **Our Postgres ledger is the source of truth for invoice balances.** No processor natively supports multi-payer invoices. Every payment — parent, co-parent, or grandma via link — is an independent Stripe Checkout Session whose webhook feeds the existing `PaymentService` allocation logic. Stripe is the money-mover; we are the ledger.
- **The contribution link is a payment toward a fee owed to the club, not a donation and not crowdfunding.** Funds always settle to the club's connected account, sessions are capped at the invoice's remaining balance, and the link closes at goal. This keeps us inside ordinary-commerce underwriting (no KYC on contributors, no money-transmitter exposure) and out of Stripe's restricted-business categories. UI must state contributions are not tax-deductible.

### Ordering rationale

Each phase is independently shippable and de-risks the next: groundwork → clubs can onboard → one parent can really pay (MVP) → two parents can pay (builds the multi-payer ledger + race handling) → strangers can pay via link (split-pay generalized to unauthenticated payers) → ACH/autopay (adds delayed-settlement complexity, deliberately last among money features) → reconciliation and go-live hardening. The contribution link (Phase 4) is the market differentiator — Snap! Raise takes ~20% of what kids raise; we deliver the same outcome at ~3–4% — but it is intentionally sequenced after split-pay because it reuses that phase's multi-payer transaction model and overpayment/race handling.

---

## Phase 0 — Groundwork & cleanup (no Stripe yet) · ~1 wk

Make the codebase honest before adding a processor.

**Work items**

1. **Schema-drift migration(s)** (`database/migrations/041_...`): codify what exists in Neon but not in migrations —
   - `payment_items.accounting_code` (queried at `api/invoices.php:191`)
   - `payment_items.sibling_discount_enabled/type/value` (queried at `registration/registrations-api.php:303-309`)
   - `campaign_donations` table (used by `api/campaign-donations.php`, absent from migrations)
   - Verify the live `invoices.status` CHECK constraint actually admits `'partial'` (code writes it — `services/PaymentService.php:192` — but `003_invoice_schema.sql:35-36` doesn't allow it). If prod drifted, add a migration extending the CHECK. ⚠️ This technically alters an existing constraint — flagged for Maggie's sign-off per the "additive changes only" rule; it is codifying current behavior, not changing it.
2. **Processor-agnostic columns** (additive, per repo rules — do not rename `maverick_*`): add to `payment_transactions`: `processor VARCHAR(20)` (`'stub'|'stripe'`), `processor_transaction_id`, `processor_charge_id`, `processor_customer_id`, `processor_payment_method_id` + indexes. Stub keeps writing its demo IDs; Stripe writes here. `maverick_*` columns are frozen (demo data only) and documented as deprecated.
3. **Extract `PaymentProcessorInterface`** from `lib/StubPaymentProcessor.php` (`processPayment`, `createCustomer`, `savePaymentMethod`, `chargeSavedPaymentMethod`, `refund`, `getTransaction`) + a factory keyed on `PAYMENT_MODE` (`demo` → stub, `live` → Stripe in Phase 2). Demo mode must keep working forever — it's the sales-demo path.
4. **Kill the dead endpoint call:** `MakePaymentPage.tsx:90` fetches nonexistent `api/payment-methods.php` — guard/remove now; the real endpoint ships in Phase 5.
5. **Fix the latent bug** at `api/financial-permissions.php:161` (undefined `$activeContext`).

**Dependency note:** the pending shared-email Phase 0 fixes (`api/athletes.php`, `api/invoices.php`, `api/financial-permissions.php` — see `project_household_shared_email.md`) determine *which guardians can see/pay an invoice*. Not blocking Phases 1–2; strongly recommended before Phase 3 ships, since split-pay is only as good as both guardians being able to see the invoice.

**Testing criteria (gate to Phase 1)**

- [ ] Migrations apply cleanly to a fresh DB **and** to a Neon branch copy of prod (Neon branching makes this cheap — use it for every migration in this plan).
- [ ] Full existing PHPUnit suite green, `tests/php/PaymentServiceTest.php` untouched and green.
- [ ] Demo checkout flow (`PAYMENT_MODE=demo`) works end-to-end exactly as before (manual smoke: registration cart → multi-checkout → stub success + decline cards).
- [ ] Grep proves no code path writes `maverick_*` except the stub.

---

## Phase 1 — Stripe platform foundation & club onboarding · ~1–2 wk

Clubs become connected accounts. No money moves yet.

**Setup (Maggie + eng, day 1):** create Stripe account; enable Connect; complete the platform profile; choose loss-liability model (Express = Stripe carries most underwriting/KYC burden). Start on the **"Stripe bills the club"** pricing model ($0 platform cost) — platform monetization via `application_fee_amount` and/or a payer-facing fee line comes in Phase 2/6.

**Work items**

1. `composer require stripe/stripe-php`.
2. Env vars via existing `Env` class / Heroku config: `STRIPE_SECRET_KEY`, `STRIPE_PUBLISHABLE_KEY`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_CONNECT_WEBHOOK_SECRET`. Never in code.
3. **Migration:** `club_payment_accounts` (club_id FK, stripe_account_id, onboarding_status, charges_enabled BOOL, payouts_enabled BOOL, details_submitted BOOL, requirements JSONB, created_at/updated_at).
4. **`api/payment-accounts.php`** (club_admin only, via `user_club_access` — never `users.role`): `create` (Express account + Account Link, return onboarding URL), `status`, `refresh-link`. Follow the existing gateway file pattern.
5. **`api/webhooks/stripe-connect.php`**: handle `account.updated` → update flags. Mirror the signature-verification + logging pattern of `api/webhooks/sendgrid.php`.
6. **Frontend:** "Payments" section in club settings — onboarding CTA, status badges (Onboarding started / Charges enabled / Payouts enabled), re-entry link for incomplete onboarding. NEW frontend only.
7. **Gate:** live checkout (Phase 2) refuses to create sessions for clubs where `charges_enabled = false`.

**Testing criteria**

- [ ] Test-mode Express account created via UI; test onboarding completed; `account.updated` webhook flips `charges_enabled` within seconds (verify via Stripe CLI `stripe listen --forward-to`).
- [ ] Webhook rejects: bad signature (400), replayed event (idempotent no-op), unknown account (logged, 200).
- [ ] AuthZ: coach/parent hitting `payment-accounts.php` → 403 (unit + curl test).
- [ ] Unit tests for the account service with a mocked Stripe client (follow `PaymentServiceTest` injected-PDO style).
- [ ] Restart/redeploy safety: onboarding resumable after abandoning mid-flow.

---

## Phase 2 — Single-payer invoice checkout (real money MVP) · ~2–3 wk

One parent really pays one (or more) invoices by card. This is the go-live milestone.

**Work items**

1. **`services/StripeCheckoutService.php`** — creates a Checkout Session on the club's connected account (direct charge; `application_fee_amount` plumbing included but may be $0 initially): line item(s) from invoice data, `metadata: {invoice_ids, athlete_payment_id, payer_user_id, club_id}`, 30-min expiry, success/cancel URLs back into the PWA.
2. **`api/checkout-sessions.php`** — POST `{invoice_ids, amount}`; authenticates, enforces ownership through the same `AthleteScope` contract as `PaymentService::recordPayment` (`services/PaymentService.php:96-128`), validates amount ≤ remaining balance, returns session URL. **Never trust the frontend's amount or scope.**
3. **`api/webhooks/stripe.php`** — verify signature, insert event into new `stripe_webhook_events` table (event_id UNIQUE — this is the idempotency backstop), push job to Redis (`payment_events_queue`) via existing `RedisQueue::push`, return 200 fast. Worker (`workers/queue-worker.php` — add queue to the `$queues` array) processes: `checkout.session.completed` / `payment_intent.succeeded` → apply payment; `payment_intent.payment_failed` → record failure (feed `api/payment-failures.php` data); `charge.refunded` → reverse ledger.
4. **Extend `PaymentService`** with `applyProcessorPayment(...)`: wraps the existing allocation logic in a DB transaction with `SELECT ... FOR UPDATE` on the invoice rows, inserts a `payment_transactions` row (`processor='stripe'`, processor ids, `paid_by_user_id`, `payment_type` full/partial), then allocates. Existing `recordPayment` and its tests remain untouched (regression guard).
5. **Receipts & log:** payment confirmation email through existing `lib/Email.php` / comms pipeline; entry in `communication_log`; receipt surface via existing `api/payment-receipt.php` (replace its "in demo mode, just log" stubs).
6. **Frontend:** `PaymentCheckout.tsx` / `MultiPaymentCheckout.tsx` / parent-portal `MakePaymentPage.tsx` — in live mode, replace the card-number form with a "Pay securely" redirect to Stripe Checkout (this **deletes raw card handling → PCI scope drops to SAQ-A**; card fields remain only behind demo mode). Success page polls invoice status (webhook is the truth, not the redirect).
7. **Refunds (admin):** minimal club-admin refund action on a transaction → Stripe refund API → webhook reverses ledger.

**Testing criteria**

- [ ] Unit: `applyProcessorPayment` allocation math (reuse `PaymentServiceTest` fixture style) — full pay, partial pay, multi-invoice allocation order, already-paid invoice no-op, out-of-scope invoice → `OwnershipException`.
- [ ] Unit: webhook handler idempotency — same `event_id` delivered 3× → exactly one ledger bump, one receipt email.
- [ ] Integration (Stripe test mode + Stripe CLI): happy path card `4242…`; decline `4000…0002`; 3-D Secure challenge card `4000…3155`; session expiry → invoice unchanged.
- [ ] Redirect-vs-webhook race: land on success URL before webhook processed → UI shows "processing", settles correct.
- [ ] Refund: full + partial refund reverse `amount_paid` and status correctly, receipt/refund email sent.
- [ ] Security: forged webhook signature rejected; parent A cannot create a session for parent B's invoice (403); amount > balance rejected (400).
- [ ] Worker crash mid-job → job retried from Redis retry queue without double-applying (idempotency table catches it).
- [ ] Demo mode still fully functional.
- [ ] **Pilot gate:** one real live-mode transaction ($1 test invoice on Maggie's own club) charged and refunded before any customer club is enabled.

---

## Phase 3 — Split payment between parents · ~1–2 wk

Two guardians, one invoice, N transactions. Mostly UI + concurrency hardening — the ledger already supports it.

**Work items**

1. **Visibility:** both guardians see family invoices (current `invoices.php` family lookup unions guardians by email; the shared-email/`user_guardians` work strengthens this — coordinate).
2. **Pay-any-amount UI** on invoice detail + `MakePaymentPage`: amount input (min $1, max = live balance), each payer gets their own Checkout Session (Phase 2 machinery unchanged).
3. **"Who paid what" ledger view** on the invoice: transaction list with payer name (`paid_by_user_id` → users), amount, date, method, status. Visible to both guardians and club admin.
4. **Notifications:** on each partial payment, notify the other guardian(s) with remaining balance (existing comms pipeline; respect suppressions).
5. **Concurrency & overpayment:** two sessions created against the same balance can both complete (race). Rules: session amount validated at *creation*; at *webhook apply* time re-check under `FOR UPDATE` — apply up to remaining balance, **auto-refund any excess** on the same PaymentIntent, note it on the transaction, email the payer. Link/session for a zeroed invoice → friendly "already paid" page.
6. (Nice-to-have, can slip) "Request from co-parent": guardian A sends guardian B an email with a prefilled pay-link for a chosen amount.

**Testing criteria**

- [ ] Unit: race simulation — balance $500, webhook A $300 then webhook B $300 → invoice `paid` at $500, $100 auto-refunded on B, ledger + transaction rows exact.
- [ ] Unit: 3 payers × uneven amounts sum precisely in integer cents (no float drift — keep `toCents` discipline).
- [ ] Integration: two test accounts (two browsers) each pay a share in Stripe test mode; both see updated balance and each other's payment in the ledger view; status transitions pending → partial → paid.
- [ ] Notification: partial payment triggers co-guardian email exactly once (idempotency).
- [ ] Regression: single-payer flow and `PaymentServiceTest` untouched and green.
- [ ] AuthZ: a guardian of a *different* athlete cannot view the ledger or pay (403) — PAR-18 contract holds.

---

## Phase 4 — Contribution link ("Help send Jimmy to camp") · ~2 wk

Split-pay generalized to unauthenticated payers. **The differentiator feature.**

**Work items**

1. **Migration:** `contribution_links` (id, token UNIQUE — signed/high-entropy, mirror the unsubscribe-token pattern; invoice_id FK, created_by_user_id, display_name, message, status active/completed/closed/expired, expires_at, created_at) and `invoice_contributions` (id, contribution_link_id FK, payment_transaction_id FK, contributor_name, contributor_email, is_anonymous BOOL, comment, created_at). Note: this is deliberately **not** `fundraiser_campaigns`/`campaign_donations` — those are club-level campaigns; this is invoice-bound. Keep them separate.
2. **Link creation:** guardian (from invoice detail in parent portal) or club admin generates a link; choose display name (default: athlete **first name only** + program, e.g. "Jamie — Summer Camp 2026") and optional message. Copy/share via SMS-friendly short URL.
3. **Public page** `/contribute/:token` (repurpose the existing `/contribute/:invoiceId` route in `App.tsx` — **stop exposing raw invoice IDs**): progress bar (raised / goal = invoice total), remaining amount, preset chips ($25/$50/$100) + custom amount capped at remaining, contributor name/message (optional, anonymous allowed), → Stripe Checkout (guest, no login). Required copy: *"Your payment goes to [Club Name] toward [name]'s program fees. Contributions are not tax-deductible."*
4. **Privacy (minors):** first name only, no last name/photo/team schedule on the public page; public payload contains zero PII beyond what the creator typed into display_name/message. Contributor comments visible only to the family, not publicly listed (v1).
5. **Compliance guardrails in code:** session max = live `amount_remaining` at creation; webhook apply re-validates under lock and auto-refunds overage (Phase 3 machinery); balance hits zero → link status `completed`, page flips to "Goal reached 🎉"; closed/expired token → 410 page. Funds always settle to the club's connected account — there is no code path that pays out to a family.
6. **Notifications:** contributor receipt (adapt `Email::sendDonationReceipt`, reworded as payment-toward-fees); guardian notified per contribution and at goal; club admin sees contributions in the invoice ledger like any transaction.

**Testing criteria**

- [ ] Token security: ≥128-bit entropy, invalid/expired/closed → 410 with no information leak; public endpoint rate-limited.
- [ ] Cap enforcement: contribution > remaining clamped at session creation; race between two contributors → overage auto-refunded (reuse Phase 3 test harness).
- [ ] Lifecycle e2e (test mode): create link → 3 contributions (one anonymous, one with message, one card decline) → goal reached → link auto-closes → 4th visitor sees goal-reached page → guardian got 3 notifications + goal email, contributors got receipts, invoice status `paid`.
- [ ] Privacy audit: fetch public page JSON as an anonymous user — assert no athlete last name, DOB, guardian email, or invoice ID present.
- [ ] Ledger integrity: contributions appear in "who paid what" and in club reporting identically to guardian payments.
- [ ] Copy review (Maggie): non-tax-deductible disclaimer + club-as-recipient language present on page and receipt.
- [ ] Mobile: page is share-target friendly (Open Graph tags, renders in in-app browsers — this link lives in group texts and Facebook).

**Follow-up (logged 2026-07-06, shipped without):** per-link share previews. The contribution page is a CRA SPA, so iMessage/Facebook previews show the generic app card, not the child's campaign. Fix = a small server-side rendering step for `/contribute/:token` only — e.g. a Netlify Edge Function (or backend route) that serves crawlers an HTML shell with per-link `og:title` ("Help send Jamie to camp"), `og:description` (progress), and a generated `og:image` progress card. Low effort, high share-conversion value; schedule with Phase 4 polish.

---

## Phase 5 — ACH, saved payment methods, autopay installments · ~2–3 wk

The cost-saver (0.8% capped at $5 vs 2.9%+30¢ — matters on $500+ camp fees) and the convenience layer.

**Work items**

1. **ACH in Checkout** (`us_bank_account` incl. instant verification via Financial Connections): ledger learns **pending vs settled** — `payment_intent.processing` marks the transaction `pending` (show "bank payment processing, 3–5 days"); only `succeeded` bumps `amount_paid`. Handle post-settlement failures/returns (e.g. insufficient funds days later): reverse ledger, reopen invoice, notify payer + admin. Steer UX: show ACH first with fee savings on large invoices.
2. **Saved payment methods:** Stripe Customer per guardian **per club** (direct charges → customers live on the connected account), SetupIntents, and build the real **`api/payment-methods.php`** (list/add/delete, owner-scoped) — closes the Phase 0 dead call.
3. **Autopay for payment plans:** wire the existing `payment_plans` / `payment_installments` schema (`001_payment_schema.sql:274-303`) to scheduled off-session PaymentIntents from the worker (daily due-installment sweep); retries per the existing `auto_pay_attempts`/`next_retry_at` columns (3 attempts, then permanently failed); dunning through `api/payment-failures.php` + `payment-reminders.php` (replace their "in production would…" stubs).
4. **Fee configuration:** per-club payer-facing platform fee as an invoice/session line item (market norm: TeamSnap 3.25%+$1.50, SportsEngine 3.75%+$1.75 — we undercut). Label as technology/processing fee applied to all payment methods, **not** a card surcharge; ACH discount allowed and encouraged.

**Testing criteria**

- [ ] ACH happy path (test routing 110000000): pending → settled transitions correct; invoice not `paid` while pending.
- [ ] **ACH return after settlement** (Stripe test scenarios): ledger reversed, invoice reopened to `partial`, payer + admin notified — this is the highest-risk path in the phase; it gates release.
- [ ] Saved methods: add/list/delete scoped to owner; parent A can never see or charge parent B's method (403).
- [ ] Autopay: installment due → charged off-session; decline → 3 retries on schedule → permanent failure → dunning email + in-app notification; unit tests on schedule math (weekly/biweekly/monthly, down payment %, late fees).
- [ ] Fee math: fee line correct at $1 / $499.99 / $10,000; ACH cap kicks in at $625.
- [ ] Regression: card-only flows from Phases 2–4 unchanged.

---

## Phase 6 — Reporting, reconciliation & go-live hardening · ~1–2 wk

**Work items**

1. Wire `api/transaction-report.php`, `api/revenue-summary.php`, `api/outstanding-balances.php`, `api/roster-fee-status.php` to `payment_transactions` as truth (they currently read demo data).
2. **Payout reconciliation:** `payout.paid` webhook + Balance Transactions API → club-facing payouts view (what hit the bank, which payments, fees breakdown). This is the #1 treasurer support question — build it before scale.
3. Platform revenue report (application fees / payer fees collected) — internal.
4. Overdue dunning: scheduled reminders for `overdue` invoices incl. contribution-link suggestion ("share this link to get help").
5. **Go-live checklist:** Stripe live keys on Heroku `teamselevated-backend` config; live + Connect webhooks registered and verified; `PAYMENT_MODE=live` per-club rollout flag (pilot club first); Radar defaults reviewed; refund/dispute runbook written for support; monitor first 2 weeks of pilot volume before general availability.

**Testing criteria**

- [ ] Reconciliation invariant test: Σ(payouts + Stripe fees + refunds + pending) == Σ(captured transactions) over a seeded test-mode dataset.
- [ ] Coach vs club-admin report scoping enforced server-side (coach sees own team only — same contract as comms reporting).
- [ ] Dispute webhook (`charge.dispute.created`) at minimum logs + notifies admin (full dispute tooling can wait).
- [ ] Pilot exit criteria: ≥25 real transactions incl. ≥1 refund, ≥1 split-pay invoice, ≥1 completed contribution link; zero ledger mismatches vs Stripe dashboard.

---

## Roadmapped: team-level Stripe accounts ("Phase 2.5", opt-in) · +1–2 wk

Clubs have asked for teams to have their own Stripe accounts (2026-07-06). Decision: roadmap, don't build yet.

**The gating reality:** a connected account needs a legal entity — an EIN, or an individual's SSN. Teams that are their own nonprofit/LLC (own EIN + bank account) fit cleanly. Teams that are just part of the club would need a person (team manager) to attach their SSN and personal bank account — that person then receives a **1099-K for the team's entire payment volume** and personally carries chargeback liability. Most of the demand is actually for *team-level money visibility*, not team-level bank accounts.

**Plan:**
1. **Team-tagged accounting for everyone (fold into Phases 2 + 6):** stamp team attribution on every transaction; per-team collected/outstanding/payout views for club treasurers. No KYC, no new accounts — covers the common need.
2. **Team-level connected accounts (opt-in, after Phase 2 is stable):**
   - Additive migration generalizing `club_payment_accounts` → owner (club | team), or a parallel `team_payment_accounts` table.
   - Same Payments settings section on team management, club-admin initiated (reuses `StripeConnectService` unchanged — it's owner-agnostic already except for the table).
   - Checkout-session creation routes to the team's connected account when it exists and is `charges_enabled`; falls back to the club's.
   - Onboarding UI gate question: "Does this team have its own EIN and bank account?" If no → steer to team-tagged reporting instead, with a plain-language warning about personal 1099-K/liability if they insist.
3. **Explicitly deferred:** club-collects-then-splits-to-teams (Stripe separate charges & transfers). Rarer need, real complexity; revisit only on demand after 1+2 ship.

**Open question (2026-07-06, raised for Park City club):** split *distributions* — parent pays the club's registration, team automatically receives XX% of it. Mechanically this is Stripe separate charges & transfers (item 3 above): charge settles to the club's account, a Transfer moves the team's share to the team's connected account. Prerequisites if ever built: (a) the receiving team must be a connected account (Phase 2.5 legal-entity gate — EIN or someone's SSN/1099-K), (b) a split-percentage config per program or team, (c) reconciliation views showing gross → club share → team share. Same-currency/region only. Interim answer that needs no Stripe machinery: team-tagged accounting (roadmap item 1) + club treasurer distributes internally. Decision deferred until a club commits to team-level accounts.

---

## Cross-cutting

**Security & compliance**
- PCI: hosted Checkout only → SAQ-A. The demo card form must be unreachable in live mode; no PAN ever touches our servers or logs.
- All webhooks signature-verified; all payment writes idempotent (event-id table) and scope-checked server-side (`AthleteScope` contract).
- Secrets in Heroku config vars via `Env::get` only.
- Public contribution pages: minors' privacy rules in Phase 4; rate limiting on all unauthenticated endpoints.
- Do not touch `lib/JWT.php`, `lib/AuthMiddleware.php`, `api/auth-gateway.php` (repo rule).

**Test infrastructure (applies to every phase)**
- PHPUnit with injected PDO/SQLite fixtures — follow `tests/php/PaymentServiceTest.php`; mock the Stripe client in unit tests.
- Stripe test mode + Stripe CLI (`stripe listen`, `stripe trigger`) for integration; a staging Heroku app with test keys mirrors prod config.
- Neon branch of prod for every migration rehearsal.
- Each phase's checklist is a gate: no phase starts live work until the previous phase's criteria are checked.

**Rough timeline (2 eng):** ~10–14 weeks total. Phases 0–2 are sequential (~4–6 wk to real-money MVP). Phase 3 and the Phase 4 frontend can overlap once Phase 2's webhook machinery is stable. Designer's heavy phases: 4 (public page) and 1 (onboarding UX).

**Open items for Maggie**
1. ~~Maverick relationship check~~ — resolved 2026-07-06: no engagement ever existed; stub branding was cosmetic.
2. Decide initial platform monetization: $0 fee at launch vs payer-facing fee from day one (market tolerates 3.25%+; recommend launching *with* a modest fee — retrofitting fees is harder than lowering them).
3. Legal/copy review of the contribution-page disclaimer language before Phase 4 ships.
4. ~~CHECK-constraint sign-off~~ — approved and applied to prod Neon 2026-07-06 (migrations 041–043).
