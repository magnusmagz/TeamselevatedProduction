# Teams Elevated — features shipped, 31 Aug – 6 Sep 2026

Only the things a user can see or do that they could not before. Fixes, security and plumbing are in `release-summary-2026-09-06.md`. **Dark** = deployed but switched off until Maggie turns it on.

## Club admins

| Feature | What it does | Where |
|---|---|---|
| Coach access controls | Invite, resend an invite, send a 24-hour login link, or set a temporary password (shown once) for any staff member. Every action audited. | Club Settings → Users |
| Assign coach to team | From a coach's row: pick a team and a role (head, assistant, manager). Warns before replacing a head coach. View Teams lists all roles with Unassign. | People → Coaches |
| Coach phone | Phone field on Add and Edit Coach, pre-filled from the account, stored in one normalized form. | People → Coaches → Edit |
| Assign coaches to programs | Program-level staff, so calendar and recipient search know who runs a program. | Programs → program → Staff |
| Connect a stuck account | Link a crew member's account to their family when the email didn't match. | People → Crew |
| Referee feedback review | Every coach's referee rating for the club, filters, incident flags, per-referee summary, CSV. | Programs menu → Referee Feedback |
| Home overview | Teams, athletes, revenue and programs at a glance; the app lands here. | Home |
| Programs reorder and archive | Drag order, archive old programs, collapse by type. | Programs |
| Consent column | Sortable, filterable consent status per athlete. | People → Athletes |
| Field sizes | 4v4 / 7v7 / 9v9 / 11v11 on a field, matched to age group when scheduling. | Facilities |
| Rich email signature | Formatted signature appended to every email a staff member sends. | Profile → Signature |
| Treasurer role | A money-only role: payments, reports, revenue, and nothing about athletes. Invitable from the Invite form. | Club Settings → Users → Invite |
| Documents, coherent | Club-wide documents readable by members only; assignment targets validated; expiring list for staff. | Documents |
| One look everywhere | Same header, table and buttons on every staff page, on the brand palette. | Everywhere |

## Coaches and team staff

| Feature | What it does | Where |
|---|---|---|
| Lineup builder | Formation presets by field size, tap to place and swap, a saved team default plus a lineup per game, absent players greyed, injured badged, publish to families, print. | Team → Lineups, or a game's event modal |
| Referee feedback | Rate the referee of a past game: name, 1 to 5, categories, comments, incident flag. Edit your own later. | Calendar → past game → Referee feedback |
| Mid-year evaluations | Performance tab with development goals and a season trend. Parents read-only. | Athlete → Performance |
| Invite a player to tryouts | Coach invites a specific player; the family gets an email once the switch is on. | Tryouts |
| Tryout list narrowed to your age groups | Coaches see registrations for the ages they coach, with an option to see all. | Tryouts |
| Your own invite | New coaches get a personal single-use link to set their password instead of a shared one. | Email |
| Evaluations sort | Sort and filter dropdown on evaluations. | Evaluations |

## Crew and athletes

| Feature | What it does | Where |
|---|---|---|
| Published lineup | See the starting lineup for a game once the coach publishes it, your child highlighted. | Portal → Schedule → game |
| Clickable chat links | Addresses in a chat message open in a new tab. | Chat |
| Chat times in your timezone | Message times shown in your local time, live and after reload. | Chat |
| Empty-portal guidance | If your account isn't connected to an athlete yet, the portal says so and what to do. | Portal |
| Consent captured at sign-up | Consent given on the registration form is recorded, not just the portal re-affirmation. | Registration form |
| Polls: pick more than one | Multi-choice polls in team chat. | Chat |

## Deployed, waiting on a switch

| Feature | What it does | Switch |
|---|---|---|
| Compliance tracking | Club-defined requirements per role (SafeSport, concussion, anything), credentials with expiry, coach dashboard alerts, review queue, CSV export, division and national rollups. | COMPLIANCE |
| Compliance reminders | Default 90/60/30/7 cadence, or admin-authored streams per requirement with tier resolution. | COMPLIANCE_REMINDERS |
| LMS intake | Keyed endpoint an LMS posts completions to; unmatched arrivals queue. | COMPLIANCE_INTAKE |
| National coach import | Multi-council import by council code, per-person invites, onboarding funnel. | NATIONAL_IMPORT |
| Transactional emails | Invoices, receipts, payment reminders, branded as the club. | TRANSACTIONAL_EMAIL |
| Registration confirmation, tryout offer and coach-invite emails | Three family-facing emails that were silent stubs. | REGISTRATION_CONFIRMATION, TRYOUT_OFFER_EMAIL, TRYOUT_COACH_INVITE_EMAIL |
| Scheduled broadcasts | Scheduled email and SMS campaigns go out at their time. | SCHEDULED_DISPATCH |
