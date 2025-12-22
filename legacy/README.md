# Legacy Files

This folder contains old MySQL-based gateway files that are no longer in use.

## Migration to PostgreSQL

The system has been migrated to PostgreSQL (Neon) with a new architecture:
- **Old**: MySQL gateway files in root directory
- **New**: PostgreSQL API files in `/api` directory using `config/database.php`

## Legacy Files

These files used MAMP's MySQL with hardcoded credentials:
- `athletes-gateway.php`
- `coaches-gateway.php`
- `club-profile-gateway.php`
- `events-gateway.php`
- `fields-gateway.php`
- `guardian-gateway.php`
- `medical-gateway.php`
- `programs-gateway.php`
- `seasons-gateway.php`
- `team-players-gateway.php`
- `teams-gateway.php`
- `venues-gateway.php`

## Current Production Files

Use these instead:
- `/api/auth-gateway.php` - Authentication
- `/api/organization-gateway.php` - Organization management
- `/api/calendar-events-gateway.php` - Calendar events
- `/api/rsvp-webhook.php` - RSVP tracking
- `/api/calendar-reply-parser.php` - Calendar REPLY parsing

---

**Note**: These legacy files are kept for reference only. Do not use in production.
