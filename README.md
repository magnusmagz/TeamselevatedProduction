# Teams Elevated - Calendar Invite System

A robust calendar invite system for sports team management that sends native calendar invites (not attachments) to athletes, guardians, and coaches when events are created or updated.

## ✨ Features

- **Native Calendar Invites**: Sends proper iCal/ICS invites that appear as calendar items in Gmail, Outlook, and Apple Mail
- **Automatic Updates**: Event changes automatically update in recipients' calendars
- **Cancellation Notices**: Deleted events remove themselves from calendars
- **Multi-Team Support**: Send invites to multiple teams at once
- **Smart Recipients**: Automatically includes athletes, guardians, and coaches
- **Test Mode**: Preview emails without sending (perfect for development)
- **SendGrid Integration**: Production-ready email delivery

## 🚀 Quick Start

### Prerequisites
- PHP 8.0+
- MySQL 5.7+
- Composer
- SendGrid account (for production)

### Installation

1. **Clone the repository**
```bash
git clone https://github.com/yourusername/teamselevated-calendar.git
cd teamselevated-calendar
```

2. **Install dependencies**
```bash
composer install
```

3. **Run database migrations**
```bash
mysql -u root -p teams_elevated < migrations/add_calendar_invites.sql
```

4. **Configure environment**
```bash
cp .env.example .env
# Edit .env with your SendGrid API key
```

5. **Test the system**
```bash
php test-calendar-flow.php
# View results at http://localhost:8889/test-email-viewer.php
```

## 📧 Email Configuration

### SendGrid (Recommended)
```env
SENDGRID_API_KEY=your-api-key-here
SMTP_FROM_EMAIL=maggie@eyeinteams.com
```

### Gmail (Alternative)
```env
SMTP_USERNAME=your-email@gmail.com
SMTP_PASSWORD=your-app-password
```

## 🧪 Testing Without Email

The system includes a comprehensive test mode that captures all emails locally:

```bash
# Enable test mode
php test-email-api.php?action=enable_test

# View captured emails
http://localhost:8889/test-email-viewer.php
```

## 📚 API Endpoints

### Create Event with Invites
```bash
POST /events-gateway.php
{
    "name": "Team Practice",
    "event_date": "2025-10-01",
    "start_time": "15:00:00",
    "end_time": "17:00:00",
    "team_ids": [1, 2],
    "send_invites": true
}
```

### Update Event (Sends Updates)
```bash
PUT /events-gateway.php?id=123
{
    "start_time": "16:00:00",
    "send_updates": true
}
```

### Cancel Event (Sends Cancellations)
```bash
DELETE /events-gateway.php?id=123
```

## 🏗️ Architecture

```
├── services/
│   ├── CalendarInviteService.php   # Core invite logic
│   ├── RecipientService.php        # Recipient collection
│   └── MockMailerService.php       # Test mode handler
├── config/
│   └── mail.php                    # Email configuration
├── migrations/
│   └── add_calendar_invites.sql    # Database schema
├── test-output/                    # Test email storage
└── events-gateway.php              # Main API endpoint
```

## 📊 Database Schema

### event_invitations
Tracks all sent invitations with status, sequence numbers, and unique UIDs.

### invite_activity_log
Audit trail of all invite actions (sent, updated, cancelled).

## 🔧 Frontend Integration

### React/TypeScript Example
```typescript
// Create event with invites
const eventData = {
    name: 'Championship Game',
    event_date: '2025-10-15',
    team_ids: [teamId],
    send_invites: true
};

await fetch('/events-gateway.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(eventData)
});
```

## 📈 Production Deployment

1. **Set environment to production**
```bash
export APP_ENV=production
```

2. **Configure SendGrid**
```bash
export SENDGRID_API_KEY=your-production-key
```

3. **Verify domain in SendGrid**
- Add DNS records for eyeinteams.com
- Enable click/open tracking (optional)

4. **Monitor delivery**
- Check SendGrid dashboard for stats
- Review bounce/spam rates

## 🛡️ Security

- Never commit API keys
- Use environment variables for credentials
- Validate all email addresses
- Rate limit sending (30/min, 500/hour default)

## 📝 Testing Guide

See [TESTING_GUIDE.md](TESTING_GUIDE.md) for comprehensive testing instructions.

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch
3. Test using the test viewer
4. Submit a pull request

## 📄 License

MIT License - See LICENSE file for details

## 👥 Support

For issues or questions, please open a GitHub issue or contact support@eyeinteams.com

---

Built with ❤️ for Teams Elevated by Eye In Teams