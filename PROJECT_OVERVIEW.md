# Teams Elevated - Project Documentation

## Overview

**Teams Elevated** is a comprehensive sports club management platform designed for youth sports organizations. It provides tools for club managers, coaches, and parents to manage teams, athletes, schedules, documents, and administrative tasks.

---

## Architecture

### Full-Stack Web Application

- **Frontend**: React 19 + TypeScript
- **Backend**: PHP REST API
- **Database**: PostgreSQL (Neon Serverless)
- **Authentication**: JWT with Magic Link (passwordless)

---

## Deployment

### Production Environment

| Component | Platform | URL |
|-----------|----------|-----|
| **Frontend** | Netlify | TBD (configured in netlify.toml) |
| **Backend API** | Heroku | https://teamselevated-backend-0485388bd66e.herokuapp.com |
| **Database** | Neon (PostgreSQL) | ep-gentle-smoke-adyqtxaa-pooler.us-east-1.aws.neon.tech |

### Local Development

| Component | URL |
|-----------|-----|
| **Frontend** | http://localhost:3003 |
| **Backend** | http://localhost:8888/teamselevated-backend |

---

## Tech Stack

### Frontend (`/frontend`)

**Core Technologies:**
- React 19.1.1
- TypeScript 4.9.5
- React Router DOM 7.9.1
- React Scripts 5.0.1

**UI & Styling:**
- TailwindCSS 3.4.17
- Custom design system with "forest" color theme

**Key Libraries:**
- `@react-google-maps/api` - Google Maps integration for venue management
- `@types/google.maps` - TypeScript definitions for Google Maps

**Testing:**
- Jest
- React Testing Library
- @testing-library/user-event

### Backend (`/backend`)

**Core:**
- PHP (latest)
- PostgreSQL via PDO

**Structure:**
- `/api/` - API gateway endpoints
- `/keys/` - JWT cryptographic keys
- `/uploads/` - Document storage

**Key Files:**
- `organization-gateway.php` - Organization creation & user registration
- `auth-gateway.php` - Authentication endpoints (referenced)

### Database

**Neon PostgreSQL** (Serverless)
- Host: `ep-gentle-smoke-adyqtxaa-pooler.c-2.us-east-1.aws.neon.tech`
- Port: 5432
- Database: `neondb`

**Key Tables:**
- `users` - User accounts
- `user_roles` - Multi-role assignments
- `club_profile` - Organization/club information
- `magic_link_tokens` - Passwordless authentication
- `calendar_event_attendees` - Event RSVP tracking
- `calendar_event_teams` - Team scheduling

---

## Core Features

### 1. Authentication & User Management

**Magic Link Authentication**
- Passwordless login via email
- JWT token-based sessions
- Automatic login from email links

**Multi-Role System:**
- Club Manager/Administrator
- Coach
- Parent/Guardian
- Team Manager
- League Admin

**Files:**
- `frontend/src/contexts/AuthContext.tsx` - Auth state management
- `frontend/src/pages/Login.tsx` - Login page
- `frontend/src/pages/VerifyMagicLink.tsx` - Magic link handler
- `frontend/src/components/ProtectedRoute.tsx` - Route guards

### 2. Organization Management

**Club Profiles**
- Organization setup and configuration
- Contact information
- Logo and branding (with color extraction)

**Files:**
- `frontend/src/pages/ClubProfilePage.tsx`
- `frontend/src/pages/GetStarted.tsx` - Onboarding flow
- `frontend/src/components/LogoColorExtractor.tsx`
- `backend/api/organization-gateway.php`

### 3. Team Management

**Features:**
- Create and manage teams
- Team rosters
- Assign coaches and volunteers
- Season organization
- Bulk operations

**Files:**
- `frontend/src/components/TeamManagement.tsx`
- `frontend/src/components/TeamList.tsx`
- `frontend/src/components/TeamForm.tsx`
- `frontend/src/components/TeamFormWithTabs.tsx`
- `frontend/src/components/RosterManagement.tsx`

### 4. Athlete Management

**Features:**
- Complete athlete profiles
- Guardian/parent information
- Medical information
- Jersey numbers and positions
- Document tracking
- Photo management

**Files:**
- `frontend/src/components/AthleteManagement.tsx` - Main athlete list/management
- `frontend/src/components/AthleteForm.tsx` - 48KB comprehensive form
- `frontend/src/components/AthleteProfile.tsx` - Basic profile view
- `frontend/src/components/AthleteProfileEnhanced.tsx` - Advanced profile
- `frontend/src/components/GuardianManagement.tsx` - Parent/guardian management
- `frontend/src/components/PlayerForm.tsx` - Player registration

### 5. Coach Management

**Features:**
- Coach profiles
- Team assignments
- Coach dashboard
- Practice and game management

**Files:**
- `frontend/src/components/CoachManagement.tsx`
- `frontend/src/components/CoachDashboard.tsx`

### 6. Calendar & Scheduling

**Features:**
- Team calendar
- Practice scheduling
- Game scheduling
- Smart scheduler
- Attendance tracking
- RSVP management

**Files:**
- `frontend/src/components/TeamCalendar.tsx` - Main calendar (43KB)
- `frontend/src/components/PracticeScheduler.tsx`
- `frontend/src/components/SmartScheduler.tsx`
- `frontend/src/components/AttendanceTracker.tsx`

### 7. Venue Management

**Features:**
- Venue/field management
- Google Maps integration
- Address autocomplete
- Location picker
- Field scheduling

**Files:**
- `frontend/src/components/VenueManagement.tsx` - Main venue management (24KB)
- `frontend/src/pages/VenueManagementPage.tsx`
- `frontend/src/components/AddressAutocomplete.tsx`
- `frontend/src/components/GooglePlacePicker.tsx`
- `frontend/src/components/GooglePlacePickerV2.tsx`
- `frontend/src/components/GooglePlacePickerV3.tsx`

### 8. Document Management

**Features:**
- Upload and track documents
- Expiration tracking
- Document categories
- Compliance monitoring
- Athlete-specific documents

**Files:**
- `frontend/src/components/DocumentManager.tsx`
- `frontend/src/components/ExpirationDashboard.tsx`

### 9. Program & Season Management

**Features:**
- Season creation and management
- Program organization
- Public registration
- Registration embed codes

**Files:**
- `frontend/src/components/ProgramManagement.tsx` - Unified program management
- `frontend/src/components/SeasonManagement.tsx`
- `frontend/src/components/SeasonsPage.tsx`
- `frontend/src/modules/registration/pages/ProgramManagement.tsx`
- `frontend/src/modules/registration/pages/PublicRegistration.tsx`

### 10. Reporting

**Features:**
- Jersey number reports
- Position reports

**Files:**
- `frontend/src/components/JerseyReport.tsx`
- `frontend/src/components/PositionReport.tsx`

---

## Project Structure

```
teamselevated/
├── frontend/                    # React application
│   ├── public/                 # Static assets
│   ├── src/
│   │   ├── components/         # 31 React components
│   │   ├── pages/              # 6 main pages
│   │   ├── contexts/           # React contexts (Auth, etc.)
│   │   ├── hooks/              # Custom React hooks
│   │   ├── modules/            # Feature modules (registration)
│   │   ├── types/              # TypeScript type definitions
│   │   ├── utils/              # Utility functions
│   │   ├── config/             # Configuration
│   │   ├── App.tsx             # Main app component
│   │   └── index.tsx           # Entry point
│   ├── package.json
│   ├── tailwind.config.js
│   ├── netlify.toml            # Netlify deployment config
│   └── .env.local              # Environment variables
│
├── backend/                    # PHP API
│   ├── api/
│   │   └── organization-gateway.php
│   ├── keys/                   # JWT keys
│   ├── uploads/                # File uploads
│   └── .env                    # Backend environment variables
│
├── .env                        # Root environment variables
├── .gitignore
└── README.md
```

---

## Environment Variables

### Frontend (`frontend/.env.local`)

```bash
# Google Maps API
REACT_APP_GOOGLE_MAPS_API_KEY=AIzaSyBxWyLOa2xG7nlRouty5V-JHG9jxRFxFOk

# Backend API
REACT_APP_API_URL=https://teamselevated-backend-0485388bd66e.herokuapp.com
```

### Backend (`backend/.env`)

```bash
# Database
DB_HOST=ep-gentle-smoke-adyqtxaa-pooler.c-2.us-east-1.aws.neon.tech
DB_PORT=5432
DB_NAME=neondb
DB_USER=neondb_owner
DB_PASSWORD=<password>

# JWT
JWT_SECRET=<secret>
JWT_ALGORITHM=RS256
JWT_EXPIRY=86400

# Email (SendGrid)
EMAIL_PROVIDER=sendgrid
SENDGRID_API_KEY=<key>
EMAIL_FROM=noreply@teamselevated.com
EMAIL_FROM_NAME=Teams Elevated

# App
APP_URL=http://localhost:3003
API_URL=http://localhost:8888/teamselevated-backend
APP_ENV=development
APP_DEBUG=true
```

---

## Development Workflow

### Getting Started

**Frontend:**
```bash
cd frontend
npm install
npm start
```
Runs on http://localhost:3003

**Backend:**
- Deployed on Heroku
- Local development uses existing server setup

### Testing

**Frontend:**
```bash
cd frontend
npm test              # Run tests
npm run build         # Production build
```

**Backend:**
- Shell scripts available:
  - `test_athlete_creation.sh`
  - `test-auth-flow.sh`
  - `test-signup.sh`

### Deployment

**Frontend (Netlify):**
- Automatic deployment from git push
- Configuration in `netlify.toml`

**Backend (Heroku):**
- Deployed at: https://teamselevated-backend-0485388bd66e.herokuapp.com
- Database: Neon PostgreSQL

---

## Key Routes

### Public Routes
- `/` - Home page
- `/get-started` - Organization onboarding
- `/login` - Login page
- `/verify-magic-link` - Magic link verification
- `/register/:embedCode` - Public registration

### Protected Routes (Club Manager)
- `/dashboard` - Team management
- `/athletes` - Athlete management
- `/coaches` - Coach management
- `/calendar` - Team calendar
- `/documents/expiring` - Document expiration dashboard
- `/venues` - Venue management
- `/program-management` - Program & season management
- `/club-profile` - Club profile settings
- `/teams/:teamId/roster` - Team roster
- `/athlete/:athleteId` - Athlete profile
- `/athlete/:athleteId/enhanced` - Enhanced athlete profile
- `/athlete/:athleteId/documents` - Athlete documents

### Protected Routes (Coach)
- `/dashboard` - Coach dashboard (My Teams)
- `/athletes` - View athletes
- `/calendar` - Team calendar
- `/documents/expiring` - Document tracking

---

## Design System

**Color Scheme:**
- Primary: "Forest" green theme
- Custom Tailwind colors configured in `tailwind.config.js`

**Typography:**
- Uppercase headings
- Bold, clean navigation
- Accessible font sizes

**UI Patterns:**
- Tab-based forms
- Modal dialogs
- Responsive tables
- Card-based layouts

---

## API Structure

### Authentication Flow

1. User enters email on login page
2. Backend generates magic link token
3. Email sent with magic link
4. User clicks link → redirected to `/verify-magic-link`
5. Frontend exchanges token for JWT
6. JWT stored in localStorage
7. AuthContext manages auth state

### API Endpoints (Referenced)

```
POST /api/organization-gateway.php?action=create
GET  /api/auth-gateway.php?action=verify-session
POST /api/auth-gateway.php?action=logout
```

---

## Database Schema (Key Tables)

### Users & Authentication
- `users` - User accounts
- `user_roles` - Multi-role assignments per user
- `magic_link_tokens` - Passwordless auth tokens

### Organization
- `club_profile` - Club/organization information

### Teams & Athletes
- `teams` - Team records
- `athletes` - Athlete profiles
- `guardians` - Parent/guardian information

### Calendar & Events
- `calendar_events` - Practices, games, events
- `calendar_event_teams` - Team assignments
- `calendar_event_attendees` - RSVP tracking

### Venues
- `venues` - Field/facility information

### Documents
- `documents` - Document tracking with expiration

---

## Git Repository

**GitHub:** https://github.com/magnusmagz/TeamselevatedProduction

**Branches:**
- `main` - Production branch

---

## Testing Tools

### Frontend Tests
- `test-create-athlete.js` - Athlete creation test script
- `test_athlete_creation.sh` - Shell script for testing

### Backend Tests
- `test-auth-flow.sh` - Authentication flow testing
- `test-signup.sh` - Signup flow testing

---

## Known Configuration Notes

1. **Google Maps API**: Configured for venue/address autocomplete
2. **Email Provider**: SendGrid for magic link emails
3. **File Uploads**: Stored in `/backend/uploads/`
4. **JWT Keys**: Stored in `/backend/keys/`
5. **Database**: Neon PostgreSQL with connection pooling

---

## Future Considerations

Based on the codebase structure, potential areas for expansion:
- Payment processing for registration fees
- Mobile app (React Native)
- Messaging system for coaches/parents
- Advanced analytics and reporting
- Multi-sport support
- Tournament brackets

---

## Support & Documentation

For questions or issues:
- Check component files for inline documentation
- Review test scripts for API usage examples
- Examine AuthContext for authentication patterns

---

**Last Updated:** October 2025
**Version:** 0.1.0
**Status:** Active Development
