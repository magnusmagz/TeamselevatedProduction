# Calendar Invite Testing Guide

## Overview
This testing system allows you to test the calendar invite functionality without actually sending emails. All emails are captured locally and can be viewed in a web interface.

## Quick Start

### 1. Enable Test Mode
The system automatically uses test mode when you run the test scripts. No emails will be sent to real addresses.

### 2. View Test Emails
Open in your browser:
```
http://localhost:8889/test-email-viewer.php
```

This interface shows:
- All captured emails
- Full HTML preview
- iCal file contents
- Download options for .ics files

### 3. Test Through the UI

#### Create an Event with Invites:
1. Go to Teams Elevated frontend
2. Navigate to Team Calendar
3. Click "Add Event"
4. Fill in event details
5. Select teams (make sure teams have athletes with email addresses)
6. ✅ Check "Send calendar invites to all team members"
7. Create the event
8. Check the test viewer to see captured emails

#### Update an Event:
1. Click on an existing event
2. Make changes (date, time, location)
3. ✅ Check "Send update notifications to all invitees"
4. Save changes
5. Updates will appear in the test viewer

#### Cancel an Event:
1. Delete an event from the calendar
2. Cancellation notices are automatically sent
3. Check test viewer for CANCEL method emails

## Test Scripts

### 1. Basic Test
```bash
php test-invite.php
```
Tests basic functionality without sending emails.

### 2. Full Flow Test
```bash
php test-calendar-flow.php
```
Runs a complete test:
- Creates event with invites
- Updates event (sends updates)
- Cancels event (sends cancellations)
- Shows all captured emails

### 3. Manual Testing with Curl

#### Create Event:
```bash
curl -X POST http://localhost:8889/events-gateway.php \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Test Practice",
    "type": "practice",
    "event_date": "2025-10-01",
    "start_time": "15:00:00",
    "end_time": "17:00:00",
    "team_ids": [6],
    "send_invites": true,
    "status": "scheduled"
  }'
```

#### Update Event:
```bash
curl -X PUT http://localhost:8889/events-gateway.php?id=EVENT_ID \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Updated Practice",
    "event_date": "2025-10-02",
    "start_time": "16:00:00",
    "send_updates": true
  }'
```

## What Gets Tested

### Email Components:
- ✅ HTML email body with event details
- ✅ iCalendar (.ics) file generation
- ✅ Proper MIME types for calendar invites
- ✅ Unique UIDs for event tracking
- ✅ Sequence numbers for updates

### Calendar Features:
- ✅ REQUEST method for new invites
- ✅ CANCEL method for cancellations
- ✅ Timezone handling (Pacific Time)
- ✅ Reminders (1 hour before)
- ✅ Location and description

### Update Tracking:
- ✅ Same UID maintained for updates
- ✅ Sequence number increments
- ✅ Change detection (date, time, location)
- ✅ Selective update sending

## Test Data Requirements

### Athletes Need:
- Email addresses (can be fake like test@example.com)
- Active status
- Team assignment

### Guardians Need:
- Email addresses
- Associated athletes
- receive_invites = true

### Teams Need:
- Active athletes assigned
- Primary coach (optional)

## Viewing Test Results

### In Test Viewer:
1. **Email List**: Shows all captured emails chronologically
2. **Preview Pane**: Full HTML preview of selected email
3. **Stats**: Total emails, invites sent, updates sent
4. **Download**: Get .ics files to test in calendar apps

### Files Generated:
- HTML preview: `/test-output/emails/[timestamp].html`
- iCal file: `/test-output/emails/[timestamp].ics`
- Log file: `/test-output/email-log.json`

## Testing Calendar Import

1. Download .ics file from test viewer
2. Double-click to open in default calendar app
3. Verify:
   - Event appears with correct details
   - Time and date are correct
   - Location is included
   - Reminder is set

## Troubleshooting

### No Emails Captured:
- Verify test mode is enabled
- Check teams have athletes with emails
- Ensure "Send invites" checkbox is checked

### Database Issues:
- Run migration: `mysql < migrations/add_calendar_invites.sql`
- Check tables exist: `event_invitations`, `invite_activity_log`

### Clear Test Data:
- Click "Clear All" in test viewer
- Or manually: `rm -rf test-output/emails/*`

## Production Setup

When ready for production:

1. **Configure SMTP**:
   - Edit `config/mail.php`
   - Add real SMTP credentials
   - Test with a single recipient first

2. **Disable Test Mode**:
   - Remove `.env.test` file
   - Set `APP_ENV=production`

3. **Verify Email Delivery**:
   - Start with small test group
   - Monitor bounce rates
   - Check spam scores

## Security Notes

- Never commit SMTP credentials
- Use environment variables for passwords
- Test mode files are gitignored
- No real emails sent during testing