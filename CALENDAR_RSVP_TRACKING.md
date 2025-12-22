# Calendar RSVP Tracking System

Complete documentation for the automated RSVP tracking system that captures responses from calendar applications (Google Calendar, Outlook, Apple Calendar) and updates the Teams Elevated database in real-time.

## 🎯 Overview

This system enables seamless RSVP tracking by:
1. Sending calendar invitations with a dedicated organizer email (`maggie+rsvp@eyeinteams.com`)
2. Receiving iCalendar REPLY messages when users respond in their calendar app
3. Parsing the REPLY to extract attendee email and response status
4. Automatically updating the database with Accept/Decline/Tentative responses

## ✨ Features

- **Native Calendar Integration**: Users respond directly in Google Calendar, Outlook, or Apple Calendar
- **Automatic Database Updates**: No manual intervention needed to track RSVPs
- **Dual RSVP Methods**:
  - Primary: Calendar app buttons (Yes/Maybe/No)
  - Backup: Email buttons (✓ Yes/? Maybe/✗ No)
- **Real-time Tracking**: Database updates immediately when responses are received
- **Comprehensive Logging**: All REPLY parsing is logged for debugging
- **Fallback Matching**: Smart attendee matching by email address

## 🏗️ Architecture

```
┌─────────────────┐
│  Create Event   │
└────────┬────────┘
         │
         ▼
┌─────────────────────────────────┐
│ Send Calendar Invite            │
│ - FROM: maggie@eyeinteams.com   │
│ - ORGANIZER: maggie+rsvp@...    │
└────────┬────────────────────────┘
         │
         ▼
┌─────────────────────────────────┐
│ User Receives Invite            │
│ - Email client shows event      │
│ - Calendar app shows buttons    │
└────────┬────────────────────────┘
         │
         ▼
┌─────────────────────────────────┐
│ User Clicks Yes/Maybe/No        │
│ in Calendar App                 │
└────────┬────────────────────────┘
         │
         ▼
┌─────────────────────────────────┐
│ Calendar Sends iCalendar REPLY  │
│ TO: maggie+rsvp@eyeinteams.com  │
└────────┬────────────────────────┘
         │
         ▼
┌─────────────────────────────────┐
│ SendGrid Inbound Parse          │
│ Receives email, forwards to:    │
│ /api/calendar-reply-parser.php  │
└────────┬────────────────────────┘
         │
         ▼
┌─────────────────────────────────┐
│ Parse REPLY                     │
│ - Extract UID                   │
│ - Extract attendee email        │
│ - Extract PARTSTAT              │
└────────┬────────────────────────┘
         │
         ▼
┌─────────────────────────────────┐
│ Update Database                 │
│ calendar_event_attendees table  │
│ - rsvp_status = accepted/etc    │
│ - responded_at = now()          │
└─────────────────────────────────┘
```

## 📦 Components

### 1. CalendarInvite Library (`lib/CalendarInvite.php`)
Generates iCalendar format invitations with:
- METHOD:REQUEST for new invites
- METHOD:CANCEL for cancellations
- METHOD:REQUEST + incremented SEQUENCE for updates
- Unique UID for event tracking
- ORGANIZER field set to `maggie+rsvp@eyeinteams.com`
- ATTENDEE fields with RSVP=TRUE

### 2. Email Service (`lib/Email.php`)
Sends calendar invitations via SendGrid with:
- FROM: `maggie@eyeinteams.com` (verified sender)
- ORGANIZER: `maggie+rsvp@eyeinteams.com` (RSVP routing)
- Embedded .ics content (text/calendar MIME type)
- .ics attachment as fallback
- RSVP buttons in email body (backup method)

### 3. Calendar Events Gateway (`api/calendar-events-gateway.php`)
API endpoint for sending invites:
- Creates attendee records in database
- Generates unique RSVP tokens for each attendee
- Sends personalized calendar invitations
- Handles invite/update/cancel actions

### 4. RSVP Webhook (`api/rsvp-webhook.php`)
HTTP endpoint for email button responses:
- Accepts GET/POST requests with token + response
- Updates database based on RSVP token
- Returns confirmation JSON
- **Note**: This is the backup method; calendar responses use the parser below

### 5. Calendar REPLY Parser (`api/calendar-reply-parser.php`)
**Primary RSVP tracking method** - Processes iCalendar REPLY emails:
- Receives raw MIME messages from SendGrid
- Extracts iCalendar REPLY content
- Parses UID, attendee email, and PARTSTAT
- Maps PARTSTAT to database status:
  - `ACCEPTED` → `accepted`
  - `DECLINED` → `declined`
  - `TENTATIVE` → `tentative`
  - `NEEDS-ACTION` → `pending`
- Updates `calendar_event_attendees` table
- Comprehensive error logging

### 6. Database Table (`calendar_event_attendees`)
```sql
CREATE TABLE calendar_event_attendees (
    id SERIAL PRIMARY KEY,
    event_id INTEGER NOT NULL REFERENCES calendar_events(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    email VARCHAR(255) NOT NULL,
    rsvp_status VARCHAR(50) DEFAULT 'pending',
    rsvp_token VARCHAR(255) UNIQUE,
    responded_at TIMESTAMP WITH TIME ZONE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(event_id, user_id)
);
```

## 🚀 Setup Instructions

### Step 1: DNS Configuration (IT Department)

Add MX record to `eyeinteams.com` domain:
```
Type:     MX
Host:     @  (or eyeinteams.com)
Points to: mx.sendgrid.net
Priority:  10
TTL:       3600 (or default)
```

**Verification:**
```bash
dig eyeinteams.com MX
# Should show: eyeinteams.com. 3600 IN MX 10 mx.sendgrid.net
```

### Step 2: SendGrid Inbound Parse Configuration

1. **Log in to SendGrid** (https://sendgrid.com)

2. **Navigate to Inbound Parse**
   - Settings → Inbound Parse
   - Click "Add Host & URL"

3. **Configure Settings**
   ```
   Domain:          eyeinteams.com
   Subdomain:       (leave blank)
   Destination URL: https://teamselevated-backend-0485388bd66e.herokuapp.com/api/calendar-reply-parser.php

   ✅ Check "POST the raw, full MIME message"
   ✅ Check "Spam Check" (optional)
   ```

4. **Save Configuration**

5. **Test the Webhook**
   ```bash
   # Send a test GET request to verify the endpoint is accessible
   curl https://teamselevated-backend-0485388bd66e.herokuapp.com/api/calendar-reply-parser.php

   # Expected response:
   # {"status":"ok","message":"Calendar REPLY parser is ready","timestamp":"2025-10-02 23:38:27"}
   ```

### Step 3: Verify Configuration

#### Test 1: Health Check
```bash
curl https://teamselevated-backend-0485388bd66e.herokuapp.com/api/calendar-reply-parser.php
```
Expected: `{"status":"ok",...}`

#### Test 2: Send Test Invite
```bash
cd /Applications/MAMP/htdocs/teamselevated-backend
php test-calendar-invite.php
```

#### Test 3: Check Email
1. Open the invitation in your email
2. Verify organizer shows `maggie+rsvp@eyeinteams.com`
3. Click on event in calendar to confirm

#### Test 4: Respond in Calendar
1. Open Google Calendar
2. Click on the event
3. Click "Yes" (or Maybe/No)
4. Wait 30 seconds

#### Test 5: Verify Database Update
```bash
PGPASSWORD='npg_3Oe0xzCYVGlJ' psql -h ep-gentle-smoke-adyqtxaa-pooler.c-2.us-east-1.aws.neon.tech -U neondb_owner -d neondb -c "
SELECT email, rsvp_status, responded_at
FROM calendar_event_attendees
ORDER BY responded_at DESC
LIMIT 5;
"
```

Expected: Your email with `rsvp_status = 'accepted'`

#### Test 6: Check Heroku Logs
```bash
heroku logs --tail --app teamselevated-backend | grep "Calendar REPLY"
```

Look for:
- `Calendar REPLY: Request received`
- `Calendar REPLY: Received email, length: X bytes`
- `Calendar REPLY: Found iCalendar content`
- `Calendar REPLY: Successfully updated attendee`

## 📊 RSVP Status API

### Get RSVP Status for an Event
```bash
GET /api/rsvp-webhook.php?action=status&event_id=123
```

**Response:**
```json
{
  "success": true,
  "event_id": 123,
  "counts": {
    "accepted": 15,
    "declined": 3,
    "tentative": 2,
    "pending": 8
  },
  "attendees": [
    {
      "name": "John Doe",
      "email": "john@example.com",
      "status": "accepted",
      "responded_at": "2025-10-02 14:35:22"
    }
  ]
}
```

### Manual RSVP (Email Buttons)
```bash
POST /api/rsvp-webhook.php?action=respond&token=abc123&response=accepted
```

## 🔍 Troubleshooting

### Issue: No REPLY received after clicking Yes

**Diagnosis:**
1. Check MX records: `dig eyeinteams.com MX`
2. Verify SendGrid Inbound Parse is configured
3. Check Heroku logs: `heroku logs --tail --app teamselevated-backend`

**Common Causes:**
- MX records not propagated yet (wait 24-48 hours)
- SendGrid Inbound Parse not configured
- Wrong destination URL in SendGrid
- Organizer email incorrect

### Issue: REPLY received but not parsed

**Check Logs:**
```bash
heroku logs --tail --app teamselevated-backend | grep "Calendar REPLY"
```

**Look for:**
- "No iCalendar content found" → Email format issue
- "Missing required data" → UID or PARTSTAT not extracted
- "No matching attendee found" → Email address mismatch

**Fix:**
1. Verify the raw MIME message contains iCalendar data
2. Check attendee email matches exactly (case-insensitive)
3. Ensure event was created through the system (has attendee record)

### Issue: Database not updating

**Verify:**
1. Attendee record exists:
   ```sql
   SELECT * FROM calendar_event_attendees WHERE email = 'user@example.com';
   ```

2. Check for errors in logs:
   ```bash
   heroku logs --tail --app teamselevated-backend | grep -i error
   ```

3. Verify database connection:
   ```bash
   heroku config --app teamselevated-backend | grep DB_
   ```

## 📈 Production Monitoring

### Key Metrics to Track

1. **RSVP Conversion Rate**
   ```sql
   SELECT
     COUNT(CASE WHEN rsvp_status != 'pending' THEN 1 END) * 100.0 / COUNT(*) as response_rate
   FROM calendar_event_attendees;
   ```

2. **Response Time Distribution**
   ```sql
   SELECT
     rsvp_status,
     AVG(EXTRACT(EPOCH FROM (responded_at - created_at))/3600) as avg_hours_to_respond
   FROM calendar_event_attendees
   WHERE responded_at IS NOT NULL
   GROUP BY rsvp_status;
   ```

3. **REPLY Parser Success Rate**
   - Monitor Heroku logs for "Successfully updated" vs errors
   - Set up alerting for high error rates

### SendGrid Monitoring

1. **Inbound Parse Statistics**
   - Log in to SendGrid
   - Navigate to Statistics → Inbound Parse
   - Monitor: Received, Processed, Errors

2. **Email Deliverability**
   - Check bounce rates (should be < 5%)
   - Monitor spam complaints (should be < 0.1%)
   - Review blocked sends

## 🔐 Security Considerations

1. **Email Validation**
   - All emails are validated before database insertion
   - RSVP tokens are cryptographically secure (32 bytes)

2. **Rate Limiting**
   - SendGrid Inbound Parse has built-in rate limiting
   - Consider adding application-level rate limiting for high-volume

3. **Data Privacy**
   - RSVP tokens are single-use and unique per attendee
   - No sensitive data in URL parameters
   - All database queries use parameterized statements

## 🚧 Future Enhancements

### Phase 1: UID Tracking (Recommended)
Add UID column to `calendar_events` table for more reliable matching:
```sql
ALTER TABLE calendar_events ADD COLUMN calendar_uid VARCHAR(255) UNIQUE;
```

Update `CalendarInvite::generate()` to store and reuse UIDs.

### Phase 2: RSVP Notifications
Send notifications to organizers when RSVPs are received:
- Email digest of new responses
- Slack/Discord integration
- In-app notifications

### Phase 3: Analytics Dashboard
- Real-time RSVP tracking
- Response trends over time
- Attendance predictions based on historical data

### Phase 4: Multi-Organizer Support
- Allow different organizers per event
- Custom RSVP email addresses per organization
- White-label support

## 📞 Support

For issues or questions:
- **Email**: support@eyeinteams.com
- **GitHub Issues**: https://github.com/magnusmagz/teamselevated-calendar/issues
- **Heroku Logs**: `heroku logs --tail --app teamselevated-backend`

## 📝 Changelog

### v2.0.0 - 2025-10-02
- ✅ Added calendar REPLY parser
- ✅ Implemented SendGrid Inbound Parse integration
- ✅ Created `calendar_event_attendees` table
- ✅ Added RSVP token system
- ✅ Dual RSVP methods (calendar + email buttons)
- ✅ Comprehensive logging and error handling

### v1.0.0 - 2025-10-01
- ✅ Initial calendar invitation system
- ✅ iCalendar (.ics) generation
- ✅ SendGrid email delivery
- ✅ Basic RSVP tracking via email buttons

---

**Built with ❤️ for Teams Elevated**
