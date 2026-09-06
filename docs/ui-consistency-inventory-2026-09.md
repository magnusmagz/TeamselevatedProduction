# UI consistency — page headers and tables (staff app), 2026-09-06

Maggie: "we have different header treatments — an example is Programs and Tournaments —
let's fix it so we have one" and "we have different table treatments, we need one."

This is the inventory that chose the two treatments, the choice, and what was migrated.
The guard is `frontend/src/uiConsistency.test.ts`; the components are
`frontend/src/components/ui/PageHeader.tsx` and `frontend/src/components/ui/DataTable.tsx`.

## Maggie's two examples, before

| | Programs (`modules/registration/pages/ProgramManagement.tsx`) | Tournaments (`modules/tournament/pages/TournamentList.tsx`) |
|---|---|---|
| h1 | `text-2xl sm:text-3xl font-bold text-brand-primary uppercase tracking-wide` | `text-2xl font-bold text-gray-900` (no brand colour, no uppercase) |
| subtitle | `text-gray-600 mt-1 sm:mt-2 text-sm sm:text-base` | `text-sm text-gray-500 mt-1` |
| primary action | three filled buttons in a filter/action bar BELOW the header, `px-6 py-3 uppercase` | one `shadow-sm font-medium` button beside the h1, not uppercase |
| list | desktop `<table>` per program type: header row `border-b border-brand-secondary`, th `px-6 py-3 font-bold text-brand-primary uppercase`, rows `border-b border-gray-300 hover:bg-gray-50`; mobile cards | a card grid — no table at all, although the Programs page already lists the same tournaments in a table |
| empty state | bordered panel, `No programs yet.` | dashed panel with an icon and a repeated Create button |

So the two pages disagreed on h1 size, colour and case, on subtitle colour, on where the
action sits and how it is styled, and on whether a list is a table.

## The tally (before any change)

Counted with grep across `pages/`, `components/`, `components/superadmin/`, `modules/*/pages/`.

### h1 class strings (top of ~30 distinct variants)

| count | `<h1 className=…>` |
|---|---|
| **18** | `text-2xl font-bold text-brand-primary uppercase tracking-wide` |
| 7 | `text-2xl font-bold text-brand-primary` |
| 7 | `text-2xl font-bold text-brand-primary mb-2` |
| 6 | `text-3xl font-bold text-brand-primary uppercase` |
| 5 | `text-3xl font-bold text-brand-primary uppercase tracking-wide` |
| 4 | `text-xl font-bold text-brand-primary mb-2` |
| 4 | `text-3xl font-bold` |
| 4 | `text-2xl font-bold text-gray-900` |
| 4 | `text-2xl font-bold text-gray-900 mb-2` |
| 3 | `text-2xl font-bold text-brand-primary uppercase` |
| … | 20 more variants at 1–3 each (`text-4xl`, `text-5xl`, `text-white`, `text-lg`) |

Plus five routed staff pages whose page title was an **`<h2 className="text-3xl …">`** with
no h1 at all (AthleteManagement, TeamManagement, ExpirationDashboard, RosterManagement,
VenueManagement) and UserProfile.

Subtitle: `mt-1 text-sm text-gray-500` and `text-gray-600 mt-1/mt-2` about equally; two pages
used `text-brand-primary` for the subtitle. Primary action: same-row-right on about half the
pages, an action bar below on the other half, three different button recipes
(`px-6 py-3 uppercase` filled, `px-4 py-2 rounded-lg font-medium`, `shadow-sm font-medium`).
Back links: `← Back to X` as a `<Link>`, as a `history.back()` button, as an underlined link
under the subtitle, as a breadcrumb `<nav>`, and (Fundraisers) the same link uppercase on one
sibling page and not the other.

### table header cells (top variants)

| count | `<th className=…>` |
|---|---|
| **20** | `px-4 py-3 text-left text-xs font-medium text-brand-primary uppercase tracking-wide` |
| 15 | `px-4 py-2 text-left text-xs font-bold text-brand-primary uppercase` |
| 14 | `px-6 py-3 … font-bold text-brand-primary uppercase tracking-wider border-r border-gray-300` |
| 14 | `px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase` |
| 14 | `px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider` |
| 13 | `px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase` |
| 13 | `px-6 py-3 … font-bold text-brand-primary uppercase border-r border-gray-300` |
| 12 | `px-6 py-3 text-left text-xs font-semibold text-brand-primary uppercase tracking-wider` |
| 12 | `px-4 py-2.5 text-left text-xs font-bold text-gray-500 uppercase` |

`<thead>`: `bg-gray-50` ×16 (the most common), `bg-brand-secondary` ×6 (superadmin house
style), unstyled with a `border-b border-brand-secondary` header row (the club pages:
Athletes, Teams, Facilities, Programs, Coaches), `bg-brand-primary text-white` (AttendanceModal),
`bg-red-100` (ExpirationDashboard's expired table).

Three families, in short:
1. **club pages** — header row `border-b border-brand-secondary`, `th font-bold text-brand-primary`,
   `border-r` vertical gridlines, rows `border-b border-gray-300 hover:bg-gray-50`;
2. **the gray family** — `thead bg-gray-50`, `th font-medium text-gray-500 (tracking-wider)`,
   `tbody divide-y divide-gray-200`, rows `hover:bg-gray-50` (Crew, Templates, Payments,
   Volunteers, Compliance, tournament panels);
3. **superadmin** — `thead bg-brand-secondary`, `th font-semibold`, rows `border-t`.

Row hover: present on most, absent on every tournament panel table and on the superadmin
modals. Striping: none anywhere (two files carried a dead striping ternary whose branches
were identical). Empty states: in-table `colSpan` row / rendered instead of the table /
rendered after the table / early return — all four. Sorting: only AthleteManagement (own
buttons) and OrgCompliance (`SortHeader`). Pagination: Load More (CommunicationLog,
Volunteers, Athletes), Previous/Next (EmailReporting), none elsewhere.

## The choice

Biased to whatever was already the most common, so the migration changes the fewest pixels.

**Header** — `PageHeader`: h1 `text-2xl font-bold text-brand-primary uppercase tracking-wide`
(the 18-count winner, and what Crew / Templates / Volunteers / Communication Log / Compliance
already had); subtitle `mt-1 text-sm text-gray-500`; actions right-aligned on the same row on
`sm+`, stacked under the title on mobile, primary action first; optional `backTo` link above
the title (`← label`, `text-sm text-brand-primary hover:underline`); optional `meta` slot for
chips and counts under the subtitle; `mb-6`. Button recipes inside `actions` are left to the
page — that was out of scope and is the next sweep.

Rejected: `text-3xl` (the club-pages' h2 titles and a handful of h1s — larger than most of
the app and it wrapped on phones), `text-gray-900` without uppercase (the tournament module —
the only family not using the brand colour), action bars below the header (two rows of chrome
before content).

**Table** — `DataTable`: wrapper `overflow-x-auto rounded-lg border border-gray-200 bg-white`
(so a wide table scrolls itself and the page never scrolls sideways); `thead bg-gray-50
sticky top-0`; `th px-4 py-3 text-xs font-medium text-brand-primary uppercase tracking-wide`
(the 20-count winner — the gray family's shell with the brand-coloured th that Volunteers,
Compliance and Signup Requests already used); `tbody divide-y divide-gray-200`; `tr
hover:bg-gray-50` (+ `cursor-pointer` when clickable); `td px-4 py-3 text-sm text-gray-900`;
empty state as a full-width row inside the table (`py-12 text-center text-gray-500`, optional
action) so the header still shows what the list would contain; an `actions` column convention:
right-aligned, nowrap, clicks inside it do not fire `onRowClick`. Optional client-side sort
(`sortable` / `sortValue`, nulls last in both directions, `aria-sort`). No striping (there
never was any). No pagination — Load More / Previous-Next stay in the page after the table.

Rejected: `border-r` column gridlines (club pages — nowhere else in the app), the filled
`bg-brand-secondary` header (superadmin only), `px-6 py-4` cells (wider than the median and the
reason several tables needed `whitespace-nowrap` everywhere).

## What was migrated

Every file under `pages/`, `modules/registration/pages/`, `modules/tournament/pages/`, and the
page-level components routed from `App.tsx` (`STAFF_COMPONENTS` in the scan test), plus the
tab panels those pages render tables through (superadmin lists and modals, tournament
Registrations / Standings / Disciplinary / roster modal, Tryout management, Club Settings →
Users / Invitations / Payments). Behaviour was kept identical: same columns, same cell content,
same links and handlers, same test ids and strings. Two deliberate small changes:

- `TournamentList` renders its tournaments as a DataTable (name, dates, location, divisions,
  teams, entry fee, status, Manage) instead of a card grid — the Programs page already listed
  the same rows as a table, and "one table treatment" cannot mean cards on one page and a
  table on the other. Row click still opens the tournament. It also stopped calling
  `new Date()` on a date-only string (the CLAUDE.md rule) and uses `formatDateOnly`.
- `TournamentCreate` gained a `← Back to Tournaments` link; it had no way back but the browser.

Programs keeps its mobile card view (divs, not a table) and its admin reorder column; the
three create buttons moved from the filter bar into the header actions, the filters stayed in
their bar. Programs and Tournaments now render the same header and the same table.

## Not migrated, and why

**Not the staff app** (`NOT_STAFF_APP` in the scan test, one line each): the auth cards
(Login, SignUp, ForgotPassword, ResetPassword, SetParentPassword, VerifyMagicLink, AcceptInvitation,
AcceptCoachInvite, GetStarted), the public and family pages reached from email/shared links
(ConsentConfirm, ContributePage, DonationSuccess, FundraiserCampaign, WaitlistResponse,
PaymentPage, PaymentCheckout, MultiPaymentCheckout, PaymentReceipt, RegistrationCart,
DemoPaymentPage, AthletePaymentsDashboard, FamilyInvoices, MyRequirements), the legal documents
and marketing home, the public registration / tournament / scoreboard pages, and the printable
lineup. Each has its own layout on purpose.

**Allowlisted staff files** (`ALLOWLIST`, reason per entry):
- `pages/HelpArticlePage.tsx` — its `<table` is a ReactMarkdown element override for tables
  *authored* inside help articles; prose, not a data list. Its h1 is `PageHeader`.
- see the test for the current list; each entry is a debt with a reason.

**Panels left alone** (not in the scan's scope, listed here so nobody re-inventories them):
- `components/AttendanceModal.tsx` — attendance is an editable form grid (row click cycles
  status, per-row button group); `components/PracticeScheduler.tsx` review step — editable
  form grid with a skip checkbox and a notes input per row; `components/SmartScheduler.tsx` —
  an availability matrix with a two-row header and a button in every cell. All three are
  forms, not lists of records.
- `components/AttendanceTracker.tsx` and `components/CoachDashboard.tsx` — the legacy dark
  coach dashboard, not routed from `App.tsx`.
- `components/lineup/LineupBuilder.tsx` — the mobile-first lineup screen (h1 `text-lg`); its
  own compact layout by design (`docs/lineup-builder-spec-2026-09.md`).

**Parent portal** (`frontend/src/parent-portal/`) — out of scope, own mobile layout. Tables
there, for the record: none rendered with `<table` (lists are cards).

## Follow-ups this surfaced (not done here)

- Button recipes inside `actions` still vary (`px-6 py-3 uppercase` vs `px-4 py-2 rounded-lg
  font-medium` vs `shadow-sm`). A `Button` primitive is the obvious next sweep.
- `ClubDocumentCenter` / `CrewRoster` / `SmsTemplates` buttons use `hover:bg-brand-primary`
  on a `bg-brand-primary` button — a no-op hover.
- `EmailReporting`'s expanded-row `colSpan={7}` was hardcoded while the header column count
  varies with `smsOnly` — worth a look when the pagination is next touched.
- Two dead striping ternaries and one `{isAdmin ? '' : ''}` header cell were removed with the
  tables they lived in.
