# Teams Elevated — what shipped to production, 31 Aug – 6 Sep 2026

Heroku v559 → v622 (backend), Netlify through `6e652d7` (frontend), migrations 083–098 applied to
Neon, 197 commits on `main`. Every deploy was followed by the production smoke test (104 checks as
of today, 0 failures). Automated tests grew from about 1,100 PHP + 500 frontend to 1,705 PHP +
1,254 frontend.

Nearly all of it landed between 2 and 6 September. Items marked **dark** are deployed but switched
off until Maggie flips a config var; see the Operator Queue page.

## Security and access (2 Sep, 3 Sep)
- Three endpoints accepted a forged token (they decoded it without checking the signature). Fixed;
  a scan test keeps it fixed.
- Fifteen team routes, the registrations API, the tryouts API, `/api/athletes`, player search and
  seasons were reachable with no login. All authenticated and scoped to the caller's club.
- Attendance and RSVP status were readable and writable by any signed-in parent for any event.
  Gated to event staff. Payment reminder filter precedence fixed.
- Invitations could mint a club-admin link for any club. Gated to club admins.
- The club user list could be read by any parent in the club (every staff name and email). Gated
  to club admins (6 Sep).
- Treasurer kept as a money-only role and finished: it reaches every financial screen, is
  invitable, and can never see athlete or crew data (pinned by a scan test).
- Tryout creation restricted to club admins (product decision).

## Families and crew
- Guardian identity resolved in one place: recorded account-to-guardian links plus a
  case-insensitive email match. A family whose sign-in address drifts from their crew record no
  longer lands in an empty portal. Chat server updated to the same rule.
- Parent portal empty state, and a club-admin tool to connect a stuck account to its family.
- Links are now written at every source: registration, shareable invite, and (3 Sep) the
  invite-to-portal redemption in the auth gateway (the one approved exception to its do-not-modify
  rule).
- "Primary guardian" removed from the product. Crew members are equal; billing and reporting
  pick a deterministic contact instead. Athlete list returns every guardian.
- Age rule unified: the season year rolls on 1 August everywhere, in PHP and TypeScript, pinned
  to one shared fixture. Tryout registration lists narrow to a coach's age groups.
- Published lineups visible on the portal schedule page, child highlighted (6 Sep).

## Coaches and teams
- Assign coaches to programs; mid-year evaluations with development goals and a season trend;
  coach-invited players for tryouts; field size on venues with an age-group map for scheduling.
- Coach accounts no longer get the shared `password123`. Add Coach and imports create a
  passwordless account and a single-use seven-day invite; acceptance is a recorded fact. The
  invite email is **on**.
- Club Settings → Users: per-staff Invite / Resend invite / Send login link / Set password (shown
  once), audited. The Coaches page keeps Edit / View schedule / View teams and gained Assign to
  Team (head, assistant, manager) and a phone field on the modal.
- Lineup builder: formation presets by field size, tap to place, template and per-game lineups,
  absent and injured players marked, publish to families, print.
- Referee feedback: coaches rate the referee of a past game; admins review, filter incidents,
  export CSV. Families see nothing.
- Roster and evaluations sort; Home overview page.

## Girls on the Run (all **dark** behind `TE_FEATURE_COMPLIANCE*`, except G2's plumbing)
- G1 Organisation tiers: national / division / council above the club, set-based scope.
- G2 Scale: indexes, keyset pagination, subquery scopes, a second send-only worker lane (0 dynos),
  cached role context and a slim sign-in token (both off) so a 270-council admin can sign in.
- G3/G4 Compliance: club-defined requirements per role with expiry, credentials, coach dashboard
  alert, review queue, CSV export, default reminders. Document upload waits on durable storage.
- G5 Division and national rollups, drill-down, expiry trend, cross-council CSV.
- G6 Onboarding: multi-council coach import by council code (streamed, 50k rows tested),
  per-person invites through the queue, national onboarding funnel.
- G7 Admin-authored reminder streams per requirement with tier resolution (club over division over
  national over default); keyed LMS intake with an unmatched queue.

## Communications
- Five silent send paths now actually send, each behind its own switch (all **off** until a staged
  test): invoices and receipts, registration confirmation, tryout offers, coach tryout invites,
  scheduled broadcasts. When off, the API says so instead of claiming success.
- Rich email signatures with an editor; the plain-text signature path was emitting raw HTML to
  families (nothing malicious was on file) and is now escaped.
- Chat: links are clickable; message times show in the viewer's timezone (live messages had been
  stamped in UTC by the server).

## Money
- Treasurer role finished (above). Payment reminder precedence fix. Public checkout guard.

## Documents
- Documents areas made coherent: predicates in one file, club-wide documents readable by members
  only, assignment targets validated. Uploads to the dyno disk refused (they vanished on restart
  and were public while they existed); durable S3 storage is next and needs a bucket.

## Product polish (6 Sep)
- One page header, one table, one button across the staff app: 54 pages, 56 tables, 628 buttons,
  504 distinct button styles collapsed to six. Tables re-skinned to the brand palette from the
  brand PDF. A scan test stops the next page from drifting.

## Infrastructure and process
- Migrations applied through `scripts/apply-migration.php`, which runs one file in a transaction
  and writes an audit row. Schema fixture refreshed after each.
- Kill-switch pattern (`lib/feature_flags.php`), twelve switches now.
- Worker: queue assignment per process, tick locks, rate limiter; a double-pop bug that dropped
  jobs fixed on the way.
- A long-lived Heroku token so a 2FA session expiry can no longer stall a deploy.
- Smoke test walks every route with no token and fails on a new unguarded one.

## Decisions closed this week
All fifteen from the pending-decisions doc, plus lineup builder 16–19 built to recommendation.

## What needs Maggie
- An AWS bucket (or the Bucketeer add-on) to start durable document storage.
- Flipping switches in the order on the Operator Queue page.
- Three no-deadline calls: org-viewer CSV download, LMS completions as verified or submitted,
  referee name registry.
