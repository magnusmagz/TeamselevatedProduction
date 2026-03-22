# Tournament Module — Requirements Gap Analysis

**Date:** March 21, 2026
**Source Doc:** Youth Soccer Tournament Management Software — System Design & Implementation Blueprint
**Current State:** Phase 1 MVP deployed

---

## A. Registration Module (60% complete)

| Requirement | Status | Phase |
|---|---|---|
| Team/Club Account Creation | Done (CRM) | — |
| Player & Staff Roster Entry | Done (CRM) | — |
| Upload Required Documents (Player Cards, Waivers, Insurance) | Not built | 2 |
| Age Group & Division Selection | Done | 1 |
| Automatic Age Validation (DOB check against age group) | Not built | 2 |
| Payment Integration (Stripe/PayPal/ACH) | Not built (manual tracking only) | 2 |
| Confirmation Emails/SMS on registration | Not built (infra exists, not wired) | 2 |
| Duplicate entry detection | Done (team+tournament unique constraint) | 1 |
| Guest/external team registration | Done | 1 |
| Waitlist when division full | Done | 1 |

---

## B. Scheduling & Bracketing (75% complete)

| Requirement | Status | Phase |
|---|---|---|
| Round Robin scheduling | Done (circle method algorithm) | 1 |
| Group Stage + Knockout | Done | 1 |
| Single Elimination | Done | 1 |
| Double Elimination | Schema supports, algorithm not built | 3 |
| Field assignment to matches | Done | 1 |
| Field availability / block-out times | Not built | 2 |
| Minimum rest time enforcement | Done | 1 |
| No field double-booking | Done | 1 |
| Auto adjustments for overtime/reschedules | Not built | 2 |
| Digital schedule publishing (public page) | Done | 1 |
| Schedule versioning & history | Not built | 3 |
| Weather delay — push all matches by X minutes | Not built | 2 |

---

## C. Referee Management (10% complete)

| Requirement | Status | Phase |
|---|---|---|
| Referee Registration & Profile | Schema only (table created, no UI) | 2 |
| Certification tracking | Schema only (column exists) | 2 |
| Availability Scheduling | Not built | 2 |
| Auto-assign matches (priority, skill, travel) | Not built | 2 |
| Manual assign matches | Schema only (table created, no UI) | 2 |
| Referee Fee Rules (by match type/time) | Not built | 3 |
| Digital Match Reports & Uploads | Not built | 3 |
| Referee conflict detection (child on team) | Not built | 2 |
| Referee confirmation of assignments | Not built | 3 |

---

## D. Fee Management & Payments (20% complete)

| Requirement | Status | Phase |
|---|---|---|
| Registration fee tracking | Done (manual: paid/unpaid/refunded/waived + reference) | 1 |
| Payment gateway (Stripe/PayPal) | Not built | 2 |
| Retries, Refunds, Invoicing | Not built | 2 |
| Discount codes / coupons | Not built (exists in CRM payment system) | 3 |
| Referee payouts (batch processing) | Not built | 3 |
| Financial dashboard & reporting | Not built | 2 |
| Multi-currency | Not built | 3 |
| Export payout reports | Not built | 3 |

---

## E. Communication Hub (15% complete)

| Requirement | Status | Phase |
|---|---|---|
| In-app messaging | Done (CRM chat system) | — |
| SMS/Push/Email alerts | Infra built (SendGrid/Twilio), not wired to tournament events | 2 |
| Match update notifications (score posted) | Not built | 2 |
| Schedule change notifications | Not built | 2 |
| Weather alerts & emergency broadcast | Not built | 2 |
| Group notifications by team/referee/coach/parent | Not built (recipient service exists) | 2 |
| Read status confirmation | Not built | 3 |
| Tournament-specific email templates | Not built (template system exists) | 2 |

---

## F. Field/Facility Management (70% complete)

| Requirement | Status | Phase |
|---|---|---|
| Field roster listing (location, ID) | Done (fields + venues tables) | — |
| Map uploads / field layout diagrams | Not built | 3 |
| Block-out times | Not built | 2 |
| Field-specific notes (turf, lighting) | Done (surface_type, supports_lighting, location_notes) | 1 |
| Integration with schedules | Done (matches FK to fields) | 1 |
| Directions / parking notes | Done (location_notes column) | 1 |

---

## G. Electronic Roster & Docs Portal (40% complete)

| Requirement | Status | Phase |
|---|---|---|
| Team roster display | Done (check-in pulls from team_members) | 1 |
| Player eligibility verification | Partial (check-in with photos, no age/doc validation) | 2 |
| Document repository (waivers, insurance, player cards) | Not built | 2 |
| Age proof validation automation | Not built | 2 |
| Insurance verification | Not built | 3 |
| Waiver e-signatures | Not built (exists in CRM for tryouts) | 2 |

---

## H. Rankings & Standings (80% complete)

| Requirement | Status | Phase |
|---|---|---|
| Automatic scoring updates | Done (standings recalculate on score entry) | 1 |
| Standings leaderboard | Done (P/W/D/L/GF/GA/GD/Pts table) | 1 |
| Tie-breaking logic (configurable order) | Done (points, H2H, GD, GF, GA, wins, coin flip) | 1 |
| Goal differential cap | Done (configurable per division) | 1 |
| Head-to-head tiebreaker | Done | 1 |
| Filters by age group | Done (per-division standings) | 1 |
| Filters by region | Not built | 3 |
| Exportable ranking reports | Not built | 3 |
| Integration with governing bodies | Not built | 3 |
| National/regional weighting | Not built | 3 |

---

## Additional Blueprint Requirements

### Security & Compliance

| Requirement | Status | Phase |
|---|---|---|
| Role-based access | Done (club_admin, coach scoping) | 1 |
| Data encryption in transit | Done (HTTPS/SSL) | — |
| GDPR/CCPA privacy | Partial (consent system in CRM) | 2 |
| PCI compliance for payments | Not applicable until payment gateway added | 2 |

### Integration Options

| Requirement | Status | Phase |
|---|---|---|
| Maps API for field directions | Not built (Google Places exists in CRM) | 3 |
| SMS Gateway (Twilio) | Done (CRM) | — |
| Payment Gateway (Stripe) | Not built | 2 |
| Live Scores API | Not built (public page has 60s auto-refresh) | 3 |

### Mobile

| Requirement | Status | Phase |
|---|---|---|
| Mobile-responsive web | Done (public page is mobile-first) | 1 |
| Push notifications | Infra exists (PWA), not wired to tournament events | 2 |
| Native mobile app (iOS/Android) | Not built (PWA serves as mobile experience) | 3 |

---

## Summary Scorecard

| Module | Phase 1 | Gap |
|--------|---------|-----|
| A. Registration | 60% | Docs upload, age validation, payment gateway, confirmation emails |
| B. Scheduling | 75% | Field availability, weather delays, versioning |
| C. Referee | 10% | Full module needs UI + assignment logic |
| D. Payments | 20% | Gateway integration, invoicing, referee payouts |
| E. Communications | 15% | Wire existing infra to tournament events |
| F. Fields | 70% | Maps, block-out times |
| G. Roster/Docs | 40% | Document portal, age/eligibility validation |
| H. Rankings | 80% | Export, regional filters, governing body integration |

**Overall: ~45% of full blueprint implemented in Phase 1 MVP**

---

## Recommended Phase 2 Priorities

Based on gap analysis, highest-impact Phase 2 items:

1. **Payment gateway** (Stripe) — entry fee collection during registration
2. **Tournament notifications** — wire email/SMS to registration confirmations, score updates, schedule changes
3. **Referee management UI** — basic assignment and tracking (schema already exists)
4. **Age validation** — auto-check athlete DOB against division age group
5. **Document upload** — player cards, waivers for tournament registration
6. **Weather delay tools** — push all matches by X minutes, cancel with auto-notify
7. **Financial dashboard** — revenue tracking, payment status summary
