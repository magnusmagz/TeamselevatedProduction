# Scholarship on an athlete's invoice — plan (2026-09-09)

Status: SHIPPED 2026-09-12 (Heroku v644, migration 102 applied). Maggie agreed with all five decisions below; built on `feature/stripe-payments`, commit 0e642e2.

## What already exists (verified in code + `tests/fixtures/production-schema.json`)

- Migration 001 created `scholarships` (fund: name, budget, award type/amount, program/season) and
  `scholarship_applications` (athlete, approved amount, status). Both are live in Neon and have
  **zero writers** anywhere in the tree.
- `athlete_payments.scholarship_amount` and `scholarship_application_id` exist. Every INSERT
  (`registration/registrations-api.php`, two sites) writes `0` / NULL.
- `invoices` has `subtotal`, `discount_amount`, `total_amount`, `amount_paid`. On creation
  `discount_amount = athlete_payments.discount_amount + scholarship_amount` — so a scholarship
  already has a slot, but it is folded into "discount" with no breakdown, and there is no way to
  set it after the invoice exists.
- **There is no invoice update action.** `api/invoices.php` has list / get / create /
  mark-viewed / send / family.
- Balance owed is `total_amount - amount_paid`, computed in `services/PaymentService.php`
  (allocation, status → `partial` / `paid`) and `services/StripeCheckoutService.php` (checkout
  capped at remaining; **minimum online payment $1.00**; `remaining <= 0` throws). Contribution
  links cap at the same live balance. Reducing `total_amount` therefore flows through everywhere
  without touching those files.
- `invoices.status` CHECK is `draft / sent / viewed / paid / overdue / cancelled`. No `waived`.
- Money roles: `te_assert_financial_admin` = super admin / `club_admin` / `treasurer`
  (`lib/financial_scope.php`). Coaches, parents, volunteers must never reach this.
- Treasurer summary (`PaymentReportService::summary`) reports collected / refunded / net only —
  no discount or scholarship line.
- Discount codes were punted 2026-09-02 ("clubs are not using discount codes"). A scholarship is
  different: staff award it, a family never redeems it. Nothing from that slice is a dependency.
- Rule 1 in CLAUDE.md: payments work happens on `feature/stripe-payments` in
  `te-stripe-payments/`. `PaymentReportService.php` and `api/payment-*` are on that worktree's
  do-not-touch-from-elsewhere list, so **build this there**.

## Recommendation: award a scholarship ON THE INVOICE, fund tracking later

Option A (recommended, ~2 days): a financial admin opens an athlete's invoice, clicks
"Apply scholarship", enters an amount (or a percent that the modal converts to dollars), a
family-facing label (default "Scholarship") and an internal reason. The invoice total drops,
the family sees a scholarship line, the treasurer sees scholarships as their own number.

Option B (the plan-doc #24 shape): scholarship funds with budgets, applications, awards drawn
against a budget, usage report. Tables exist, but it is a week of work and nobody has asked for
budgets. Shape A so B can attach: the award row carries a nullable `scholarship_id` FK to the
existing `scholarships` table.

## Data model (migration 102 — 101 is the club link page; re-check `ls database/migrations | sort` in EVERY worktree first)

Additive only. New columns on `invoices`:

| column | type | note |
|---|---|---|
| `scholarship_amount` | NUMERIC(10,2) NOT NULL DEFAULT 0 | its own column, NOT folded into `discount_amount` (that is the sibling discount today) |
| `scholarship_label` | VARCHAR(80) NULL | what the family sees; default "Scholarship" |
| `scholarship_reason` | TEXT NULL | internal, staff only, never emailed |
| `scholarship_id` | INT NULL REFERENCES scholarships(id) | for option B later |
| `scholarship_awarded_by` | INT NULL REFERENCES users(id) | |
| `scholarship_awarded_at` | TIMESTAMP NULL | |

Invariant: `total_amount = subtotal - discount_amount - scholarship_amount`, recomputed by the
one write function. Mirror onto `athlete_payments` (`scholarship_amount`, `final_amount`,
`amount_remaining`) when `athlete_payment_id` is set, so the old payments dashboard and
`payments-stub.php` agree with the invoice. Registration-time creation keeps summing
`discount + scholarship` into `discount_amount` today; change both creation sites to write the
new column instead so a future registration-time award is not double counted.

Migration 001's `scholarship_applications` stays unused for now — an award is a staff decision
about a bill, not an application.

## Backend — `lib/scholarship.php` + two actions on `api/invoices.php`

`te_scholarship_apply(PDO, AuthMiddleware, int $invoiceId, array $input): array` is the ONE
write path. `award-scholarship` and `revoke-scholarship` (POST) call it; both gate on
`te_assert_financial_admin($auth, $pdo, ['invoice' => $id])` — not `te_assert_financial_scope`,
which a coach passes.

Rules, each pinned by a test:

1. **Amount is validated, not trusted.** `> 0`, `<= subtotal - discount_amount`. Percent is a
   UI convenience; the API takes dollars only, so what is stored is what was shown.
2. **Never below what is already paid.** If `subtotal - discount - scholarship < amount_paid`
   the request is a 422 naming the paid amount ("$150.00 already paid; refund first"). We do not
   create a credit or a negative balance — the refund path exists and is audited.
3. **Fully covered ⇒ `status = 'paid'`, `paid_at = now()`.** Same rule `PaymentService` applies
   when balance reaches zero; every reader (portal, dashboard, reminders, checkout) already
   understands `paid`. The scholarship columns say why. Adding `waived` to the CHECK is a
   constraint change and buys nothing. (Decision 2.)
4. **Revoke restores** the previous total and, if the invoice was `paid` only because of the
   scholarship (`amount_paid < new total`), moves it back to `partial` or `sent` (whichever it was
   — store nothing extra; derive: `amount_paid > 0 ? partial : sent`). Refuse (422) if
   `amount_paid > restored total`? Cannot happen: paid never exceeds total.
5. **Replace, not stack.** A second award on the same invoice overwrites the first (one
   scholarship per invoice); the audit row carries old and new.
6. **One transaction** across `invoices` + `athlete_payments` + audit.
7. **Audit** via `lib/AuditLogger.php`: `scholarship_awarded` / `scholarship_revoked` with
   invoice id, athlete id, old/new `scholarship_amount`, old/new `total_amount`, reason.
8. **Reads**: `get`, `list`, `family`, and the parent portal payload return `scholarship_amount`
   and `scholarship_label`; `scholarship_reason` and `awarded_by` only when the caller is a
   financial admin of the club. A scholarship is a statement about a family's finances —
   coaches see nothing, and the family sees the label and amount, never the reason.
9. **Contribution links** read the live balance, so an award shrinks the goal automatically;
   verify (do not modify) that `ContributionLinkService` auto-completes a link whose invoice
   reached zero, and add a smoke check. If it does not, that is a finding for the payments
   dossier, not a change in this slice.
10. **Payment reminders** must skip a `paid` invoice — they key on status, so rule 3 covers it;
    add a test case anyway.

Treasurer reporting: `PaymentReportService::summary` gains `scholarships_awarded` (SUM over the
club's invoices by `scholarship_awarded_at` in range) and the Revenue tile shows it beside
Collected / Refunded. Same file, same worktree, so no cross-session conflict.

Schema test hygiene: new columns go in `PENDING_MIGRATION` until 102 is applied, then refresh
the fixture with `heroku run --no-tty … dump-production-schema.php` and check the diff is a few
dozen lines, in the same commit that deletes the entry.

## Frontend

- **Staff**: `AthletePaymentsDashboard` invoices tab (already gated by `canViewAmounts`) gets an
  "Apply scholarship" `Button variant="secondary" size="sm"` per open invoice, rendered only for
  financial admins (read the role the same way the Revenue tile does). Modal: amount **or**
  percent toggle (percent shown converting live to dollars, dollars submitted), label
  (default "Scholarship"), internal reason (required — the audit needs it), optional
  "remove scholarship" on an invoice that has one. Preview row: subtotal, sibling discount,
  scholarship, new total, already paid. The 422 texts render verbatim.
- **Family view** (`FamilyInvoices`, `RegistrationsModal` amount chip): show the new total;
  `RegistrationsModal` reads `total_amount`, so nothing to do beyond the API.
- **Parent portal** (`PaymentStatusPage` already parses `discount_amount`): add a
  `scholarship_amount` line labelled with `scholarship_label`. `MakePaymentPage` uses the
  balance, so it follows.
- **Invoice email** (`lib/email_invoice_and_registration.php`): a "Scholarship" row between
  items and total, HTML and text, label only. Send goes through the branded club sender as now.
- Components use `components/ui/` (`Button`, `DataTable` conventions); the `uiConsistency`
  scan will fail a raw `<button>`.

## Tests

- `tests/php/ScholarshipAwardTest.php` (SQLite fixture mirroring the live columns): the ten
  rules above, plus coach → 403, treasurer → 200, parent → 403, amount above subtotal → 422,
  award on a `cancelled` invoice → 422, mirror onto `athlete_payments`, audit row content.
- `frontend/src/…/ScholarshipModal.test.tsx`: percent→dollars conversion, submit payload is
  dollars, 422 text shown, button absent for non-financial roles.
- `scripts/smoke-test.php`: `invoices.php?action=get` on an invoice with a scholarship as a
  coach must NOT return `scholarship_reason`.

## Deploy

Frontend first is not required (no auth change), but the portal should be able to read the new
fields before the backend writes them — push `origin` then `heroku`, apply 102 via
`scripts/apply-migration.php`, CHANGELOG entry, then award one test scholarship on a demo club
invoice and confirm checkout caps at the new balance.

## Decisions for Maggie

1. **Fund/budget tracking now?** Recommend no — award on the invoice; the FK is there for later.
2. **A fully covered invoice shows as `paid`** (with the scholarship visible), not a new
   `waived` status. Recommend yes.
3. **Awarding after a family already paid more than the new total is refused**; staff refund
   first. Recommend yes — no silent credits.
4. **Who can award**: club admin and treasurer. Coaches never. Recommend yes.
5. **What the family sees**: a "Scholarship" line and amount, never the internal reason.
   Recommend yes. If clubs want it invisible ("adjustment"), the label field covers that.
