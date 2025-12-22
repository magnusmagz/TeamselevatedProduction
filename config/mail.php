<?php
/**
 * Email Configuration
 *
 * Configure your SMTP settings here.
 * For Gmail: Use App Password (not regular password)
 * For SendGrid/AWS SES: Use API credentials
 */

return [
    'smtp' => [
        // SendGrid Configuration
        'host' => 'smtp.sendgrid.net',
        'port' => 587,
        'encryption' => 'tls',
        'username' => 'apikey',
        'password' => getenv('SENDGRID_API_KEY') ?: 'your-sendgrid-api-key',
        'from_email' => getenv('SMTP_FROM_EMAIL') ?: 'maggie@eyeinteams.com',
        'from_name' => getenv('SMTP_FROM_NAME') ?: 'Maggie - Teams Elevated',

        // Alternative configurations

        // Gmail Configuration
        // 'host' => 'smtp.gmail.com',
        // 'port' => 587,
        // 'encryption' => 'tls',
        // 'username' => getenv('SMTP_USERNAME') ?: 'your-email@gmail.com',
        // 'password' => getenv('SMTP_PASSWORD') ?: 'your-app-password',

        // AWS SES Configuration
        // 'host' => 'email-smtp.us-west-2.amazonaws.com',
        // 'port' => 587,
        // 'encryption' => 'tls',
        // 'username' => getenv('AWS_SES_USERNAME'),
        // 'password' => getenv('AWS_SES_PASSWORD'),
    ],

    'rate_limit' => [
        'max_per_minute' => 30,
        'max_per_hour' => 500,
        'max_per_day' => 2000
    ],

    'calendar' => [
        'timezone' => 'America/Los_Angeles',
        'organizer_email' => 'events@eyeinteams.com',
        'organizer_name' => 'Teams Elevated Events',
        'product_id' => '-//Teams Elevated//Calendar//EN',
        'reminder_minutes' => 60 // Default reminder 1 hour before event
    ],

    'features' => [
        'send_on_create' => true,    // Send invites when event is created
        'send_on_update' => true,     // Send updates when event is modified
        'send_on_cancel' => true,     // Send cancellation when event is cancelled
        'require_confirmation' => false, // Require coach confirmation before sending
        'track_responses' => true     // Track RSVP responses
    ]
];
?>