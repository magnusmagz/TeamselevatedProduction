# Tournament Module — Integration Map

How the tournament module connects to existing TeamsElevated CRM tables and infrastructure.

---

## Existing Tables Reused (No Changes)

| Table | Tournament Use |
|-------|---------------|
| `club_profile` | `tournaments.club_id` — every tournament is scoped to a club |
| `teams` | `tournament_registrations.team_id` — register existing CRM teams, no data re-entry |
| `team_members` | Roster verification: validate registered players against actual roster |
| `athletes` | Match events (goals, cards) link to `athletes.id` for player-level stats |
| `guardians` / `athlete_guardians` | Tournament notifications route through existing parent-athlete contact graph |
| `user_club_access` | Permission enforcement: club_admin = full access, coach = own teams only |
| `users` | `tournaments.created_by`, `tournament_registrations.registered_by`, score entry tracking |
| `seasons` | Optional `tournaments.season_id` link for seasonal grouping |
| `events` | Tournament matches can create `events` records (type `'tournament'`) for calendar sync |
| `fields` | `tournament_matches.field_id` — match field assignment uses existing facility records |

## Existing Tables Extended (Minor Additions)

| Table | Columns Added | Purpose |
|-------|--------------|---------|
| `fields` | `surface_type`, `supports_lighting`, `location_notes` | Tournament-relevant field metadata |
| `communication_log` | `tournament_id` (deferred) | Link tournament comms to tournament record |

## Existing Infrastructure Reused

| System | Tournament Use |
|--------|---------------|
| **Email/SMS (SendGrid/Twilio)** | Schedule changes, weather delays, results notifications |
| **Email templates (Unlayer)** | Tournament-specific templates with `{{tournament_name}}`, `{{game_time}}`, `{{field}}` vars |
| **Redis queue workers** | Queue tournament notification sends (same pattern as regular comms) |
| **PWA push notifications** | "Your game starts in 30 min on Field 3", "Final score posted" |
| **Payment system (Maverick)** | Entry fees as `payment_items` (type `'tournament'`), processed through existing flow |
| **Audit log** | Tournament actions logged to existing audit trail |

## New Tables (Tournament-Specific)

| Table | Phase | Purpose |
|-------|-------|---------|
| `tournaments` | 1 | Top-level entity: name, dates, location, status, fees, public URL slug |
| `tournament_divisions` | 1 | Age group/gender brackets with format, rules, tiebreaker config |
| `tournament_groups` | 1 | Pools within divisions for round-robin play |
| `tournament_registrations` | 1 | Team-to-division registration with status, payment, seeding, group assignment |
| `tournament_matches` | 1 | Scheduled/scored matches with bracket progression, field/time assignment |
| `tournament_standings` | 1 | Auto-calculated group standings (P/W/D/L/GF/GA/GD/Pts) |
| `tournament_match_events` | 2 | Per-match detail: goals, cards, substitutions linked to athletes |
| `tournament_referees` | 2 | Referee pool per tournament |
| `tournament_match_referees` | 2 | Referee-to-match assignments with role |

## Relationship Diagram

```
club_profile
  │
  ├──► tournaments
  │       │
  │       ├──► tournament_divisions
  │       │       │
  │       │       ├──► tournament_groups
  │       │       │       │
  │       │       │       └──► tournament_standings ◄── tournament_registrations
  │       │       │
  │       │       └──► tournament_matches
  │       │               │
  │       │               ├──► fields (existing)
  │       │               ├──► events (existing, optional calendar sync)
  │       │               ├──► tournament_match_events ──► athletes (existing)
  │       │               └──► tournament_match_referees ──► tournament_referees
  │       │
  │       └──► tournament_registrations
  │               │
  │               ├──► teams (existing)
  │               ├──► users (existing, registered_by)
  │               └──► payment_items (existing, optional)
  │
  ├──► user_club_access (permissions)
  ├──► team_members / athletes / guardians (contact graph)
  └──► communication_log / email_templates (notifications)
```

## Guest Teams (External Clubs)

Tournament registrations support teams outside the CRM via:
- `team_name_override` — display name for external teams
- `club_name_override` — external club name

This allows a club to host a tournament where visiting teams register without needing full CRM accounts. The `team_id` FK still points to a minimal team record created during registration.

## API Endpoints (Proposed)

```
# Tournament CRUD
POST   /api/tournaments
GET    /api/tournaments
GET    /api/tournaments/{id}
PUT    /api/tournaments/{id}
DELETE /api/tournaments/{id}

# Divisions
POST   /api/tournaments/{id}/divisions
GET    /api/tournaments/{id}/divisions
PUT    /api/tournaments/{id}/divisions/{divId}

# Registration
POST   /api/tournaments/{id}/register
GET    /api/tournaments/{id}/registrations
PUT    /api/tournaments/{id}/registrations/{regId}    # accept/reject/waitlist

# Groups & Seeding
POST   /api/tournaments/{id}/divisions/{divId}/groups
PUT    /api/tournaments/{id}/registrations/{regId}/assign-group
PUT    /api/tournaments/{id}/registrations/{regId}/seed

# Schedule & Matches
POST   /api/tournaments/{id}/divisions/{divId}/generate-schedule
GET    /api/tournaments/{id}/matches
PUT    /api/tournaments/{id}/matches/{matchId}         # update time/field
PUT    /api/tournaments/{id}/matches/{matchId}/score    # enter score

# Standings & Brackets
GET    /api/tournaments/{id}/divisions/{divId}/standings
GET    /api/tournaments/{id}/divisions/{divId}/bracket

# Public (no auth)
GET    /api/public/tournaments/{slug}
GET    /api/public/tournaments/{slug}/schedule
GET    /api/public/tournaments/{slug}/standings/{divId}
GET    /api/public/tournaments/{slug}/bracket/{divId}

# Communication
POST   /api/tournaments/{id}/notify                    # send to all/division/team
```

## Permission Matrix

| Action | Club Admin | Coach | Parent |
|--------|-----------|-------|--------|
| Create/edit tournament | Yes | No | No |
| Manage divisions/groups | Yes | No | No |
| Accept/reject registrations | Yes | No | No |
| Register own team | Yes | Yes (own teams) | No |
| Enter scores | Yes | Yes (own matches) | No |
| View all registrations | Yes | Own teams | No |
| Send tournament notifications | Yes | Own team parents | No |
| View public pages | Yes | Yes | Yes |
