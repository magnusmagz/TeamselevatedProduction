<?php
/**
 * Email Utility Class
 *
 * Handles sending emails for magic links, invitations, etc.
 * Supports both SendGrid and PHP mail() function.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/CalendarInvite.php';

class Email {
    private $provider;
    private $fromEmail;
    private $fromName;
    private $apiKey;

    public function __construct() {
        $this->provider = Env::get('EMAIL_PROVIDER', 'mail'); // 'sendgrid' or 'mail'
        $this->fromEmail = Env::get('EMAIL_FROM', 'noreply@teamselevated.com');
        $this->fromName = Env::get('EMAIL_FROM_NAME', 'Teams Elevated');
        $this->apiKey = Env::get('SENDGRID_API_KEY', '');
    }

    /**
     * Send magic link email
     *
     * @param string $to Recipient email
     * @param string $name Recipient name
     * @param string $magicLink The magic link URL
     * @return bool Success status
     */
    public function sendMagicLink($to, $name, $magicLink) {
        $subject = 'Your Teams Elevated Login Link';

        $htmlBody = $this->getMagicLinkTemplate($name, $magicLink);
        $textBody = "Hi $name,\n\n" .
                    "Click the link below to sign in to Teams Elevated:\n\n" .
                    "$magicLink\n\n" .
                    "This link expires in 15 minutes.\n\n" .
                    "If you didn't request this link, you can safely ignore this email.";

        return $this->send($to, $subject, $htmlBody, $textBody);
    }

    /**
     * Send password reset email
     *
     * @param string $to Recipient email
     * @param string $name Recipient name
     * @param string $resetLink The password reset URL
     * @return bool Success status
     */
    public function sendPasswordReset($to, $name, $resetLink) {
        $subject = 'Reset Your Teams Elevated Password';

        $htmlBody = $this->getPasswordResetTemplate($name, $resetLink);
        $textBody = "Hi $name,\n\n" .
                    "We received a request to reset your password for Teams Elevated.\n\n" .
                    "Click the link below to reset your password:\n\n" .
                    "$resetLink\n\n" .
                    "This link expires in 1 hour.\n\n" .
                    "If you didn't request this password reset, you can safely ignore this email. Your password will remain unchanged.";

        return $this->send($to, $subject, $htmlBody, $textBody);
    }

    /**
     * Send team invitation email
     *
     * @param string $to Recipient email
     * @param string $teamName Team name
     * @param string $invitedBy Name of person who invited
     * @param string $invitationLink The invitation link
     * @param string $personalMessage Optional personal message
     * @return bool Success status
     */
    public function sendTeamInvitation($to, $teamName, $invitedBy, $invitationLink, $personalMessage = '') {
        $subject = "You're invited to join $teamName";

        $htmlBody = $this->getTeamInvitationTemplate($teamName, $invitedBy, $invitationLink, $personalMessage);
        $textBody = "Hi,\n\n" .
                    "$invitedBy has invited you to join $teamName on Teams Elevated.\n\n" .
                    ($personalMessage ? "$personalMessage\n\n" : '') .
                    "Click the link below to accept your invitation:\n\n" .
                    "$invitationLink\n\n" .
                    "This invitation expires in 90 days.";

        return $this->send($to, $subject, $htmlBody, $textBody);
    }

    /**
     * Send calendar invitation
     *
     * @param array $event Event details with keys:
     *   - summary: Event title
     *   - startDateTime: Start date/time
     *   - endDateTime: End date/time
     *   - location: Event location (optional)
     *   - description: Event description (optional)
     *   - status: 'scheduled', 'cancelled', 'postponed'
     *   - organizerName: Name of organizer
     *   - organizerEmail: Email of organizer
     *   - attendees: Array of attendee objects with 'name' and 'email'
     * @param string $action 'invite' | 'update' | 'cancel'
     * @return bool Success status
     */
    public function sendCalendarInvite($event, $action = 'invite') {
        // Generate the iCalendar content
        if ($action === 'cancel') {
            $icsContent = CalendarInvite::generateCancellation($event);
        } elseif ($action === 'update') {
            $icsContent = CalendarInvite::generateUpdate($event);
        } else {
            $icsContent = CalendarInvite::generate($event);
        }

        // Build email subject
        $subject = $event['summary'];
        if ($action === 'cancel') {
            $subject = 'CANCELLED: ' . $subject;
        } elseif ($action === 'update') {
            $subject = 'UPDATED: ' . $subject;
        }

        // Send to each attendee with personalized RSVP links
        $allSent = true;
        if (!empty($event['attendees']) && is_array($event['attendees'])) {
            foreach ($event['attendees'] as $attendee) {
                if (!empty($attendee['email'])) {
                    // Build email body with RSVP token (personalized for each attendee)
                    $rsvpToken = $attendee['rsvp_token'] ?? null;
                    $htmlBody = $this->getCalendarInviteTemplate($event, $action, $rsvpToken);
                    $textBody = $this->getCalendarInviteText($event, $action, $rsvpToken);

                    $sent = $this->sendWithCalendar(
                        $attendee['email'],
                        $subject,
                        $htmlBody,
                        $textBody,
                        $icsContent
                    );
                    $allSent = $allSent && $sent;
                }
            }
        }

        return $allSent;
    }

    /**
     * Send email (main method)
     *
     * @param string $to Recipient email
     * @param string $subject Email subject
     * @param string $htmlBody HTML body
     * @param string $textBody Plain text body
     * @return bool Success status
     */
    private function send($to, $subject, $htmlBody, $textBody) {
        if ($this->provider === 'sendgrid' && !empty($this->apiKey)) {
            return $this->sendViaSendGrid($to, $subject, $htmlBody, $textBody);
        } else {
            return $this->sendViaPHPMail($to, $subject, $htmlBody, $textBody);
        }
    }

    /**
     * Send email via SendGrid API
     */
    private function sendViaSendGrid($to, $subject, $htmlBody, $textBody) {
        $payload = [
            'personalizations' => [
                [
                    'to' => [['email' => $to]],
                    'subject' => $subject
                ]
            ],
            'from' => [
                'email' => $this->fromEmail,
                'name' => $this->fromName
            ],
            'content' => [
                ['type' => 'text/plain', 'value' => $textBody],
                ['type' => 'text/html', 'value' => $htmlBody]
            ]
        ];

        $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            error_log("Email sent successfully to $to via SendGrid");
            return true;
        } else {
            error_log("SendGrid API error ($httpCode): $response");
            return false;
        }
    }

    /**
     * Send email via PHP mail() function
     */
    private function sendViaPHPMail($to, $subject, $htmlBody, $textBody) {
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $this->fromName . ' <' . $this->fromEmail . '>',
            'Reply-To: ' . $this->fromEmail,
            'X-Mailer: PHP/' . phpversion()
        ];

        $sent = mail($to, $subject, $htmlBody, implode("\r\n", $headers));

        if ($sent) {
            error_log("Email sent successfully to $to via PHP mail()");
        } else {
            error_log("Failed to send email to $to via PHP mail()");
        }

        return $sent;
    }

    /**
     * Magic link email template
     */
    private function getMagicLinkTemplate($name, $magicLink) {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #12443E 0%, #12443E 100%); color: white; padding: 30px; text-align: center; }
        .content { background: #f9f9f9; padding: 30px; }
        .button { display: inline-block; background: #12443E; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px; margin: 20px 0; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Teams Elevated</h1>
        </div>
        <div class="content">
            <h2>Hi {$name},</h2>
            <p>Click the button below to sign in to Teams Elevated:</p>
            <p style="text-align: center;">
                <a href="{$magicLink}" class="button">Sign In to Teams Elevated</a>
            </p>
            <p style="color: #666; font-size: 14px;">
                This link expires in 15 minutes. If you didn't request this link, you can safely ignore this email.
            </p>
            <p style="color: #999; font-size: 12px; word-break: break-all;">
                Or copy and paste this link: {$magicLink}
            </p>
        </div>
        <div class="footer">
            <p>&copy; 2025 Teams Elevated. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Password reset email template
     */
    private function getPasswordResetTemplate($name, $resetLink) {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #12443E 0%, #12443E 100%); color: white; padding: 30px; text-align: center; }
        .content { background: #f9f9f9; padding: 30px; }
        .button { display: inline-block; background: #12443E; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px; margin: 20px 0; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
        .warning { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; font-size: 14px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Teams Elevated</h1>
        </div>
        <div class="content">
            <h2>Reset Your Password</h2>
            <p>Hi {$name},</p>
            <p>We received a request to reset your password for your Teams Elevated account.</p>
            <p style="text-align: center;">
                <a href="{$resetLink}" class="button">Reset Password</a>
            </p>
            <div class="warning">
                <strong>This link expires in 1 hour.</strong> If you didn't request this password reset, you can safely ignore this email.
            </div>
            <p style="color: #999; font-size: 12px; word-break: break-all;">
                Or copy and paste this link: {$resetLink}
            </p>
        </div>
        <div class="footer">
            <p>&copy; 2025 Teams Elevated. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Team invitation email template
     */
    private function getTeamInvitationTemplate($teamName, $invitedBy, $invitationLink, $personalMessage) {
        $messageHtml = $personalMessage ? "<p style='background: #fff; padding: 15px; border-left: 4px solid #12443E; margin: 20px 0;'><em>\"$personalMessage\"</em></p>" : '';

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #12443E 0%, #12443E 100%); color: white; padding: 30px; text-align: center; }
        .content { background: #f9f9f9; padding: 30px; }
        .button { display: inline-block; background: #12443E; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px; margin: 20px 0; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>You're Invited!</h1>
        </div>
        <div class="content">
            <h2>Join {$teamName}</h2>
            <p><strong>{$invitedBy}</strong> has invited you to join <strong>{$teamName}</strong> on Teams Elevated.</p>
            {$messageHtml}
            <p style="text-align: center;">
                <a href="{$invitationLink}" class="button">Accept Invitation</a>
            </p>
            <p style="color: #666; font-size: 14px;">
                This invitation is valid for 90 days.
            </p>
            <p style="color: #999; font-size: 12px; word-break: break-all;">
                Or copy and paste this link: {$invitationLink}
            </p>
        </div>
        <div class="footer">
            <p>&copy; 2025 Teams Elevated. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Send email with calendar attachment
     */
    private function sendWithCalendar($to, $subject, $htmlBody, $textBody, $icsContent) {
        if ($this->provider === 'sendgrid' && !empty($this->apiKey)) {
            return $this->sendCalendarViaSendGrid($to, $subject, $htmlBody, $textBody, $icsContent);
        } else {
            return $this->sendCalendarViaPHPMail($to, $subject, $htmlBody, $textBody, $icsContent);
        }
    }

    /**
     * Send calendar invite via SendGrid
     */
    private function sendCalendarViaSendGrid($to, $subject, $htmlBody, $textBody, $icsContent) {
        // Encode the .ics content as base64 for attachment
        $icsBase64 = base64_encode($icsContent);

        $payload = [
            'personalizations' => [
                [
                    'to' => [['email' => $to]],
                    'subject' => $subject
                ]
            ],
            'from' => [
                'email' => $this->fromEmail,
                'name' => $this->fromName
            ],
            'content' => [
                ['type' => 'text/plain', 'value' => $textBody],
                ['type' => 'text/html', 'value' => $htmlBody],
                ['type' => 'text/calendar; method=REQUEST', 'value' => $icsContent]
            ],
            'attachments' => [
                [
                    'content' => $icsBase64,
                    'type' => 'text/calendar; method=REQUEST',
                    'filename' => 'invite.ics',
                    'disposition' => 'attachment'
                ]
            ]
        ];

        $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            error_log("Calendar invite sent successfully to $to via SendGrid");
            return true;
        } else {
            error_log("SendGrid calendar invite error ($httpCode): $response");
            return false;
        }
    }

    /**
     * Send calendar invite via PHP mail()
     */
    private function sendCalendarViaPHPMail($to, $subject, $htmlBody, $textBody, $icsContent) {
        $boundary = md5(uniqid(time()));

        $headers = [
            'From: ' . $this->fromName . ' <' . $this->fromEmail . '>',
            'Reply-To: ' . $this->fromEmail,
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
            'X-Mailer: PHP/' . phpversion()
        ];

        $message = "--$boundary\r\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
        $message .= $textBody . "\r\n\r\n";

        $message .= "--$boundary\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
        $message .= $htmlBody . "\r\n\r\n";

        $message .= "--$boundary\r\n";
        $message .= "Content-Type: text/calendar; method=REQUEST; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
        $message .= $icsContent . "\r\n\r\n";

        $message .= "--$boundary--\r\n";

        $sent = mail($to, $subject, $message, implode("\r\n", $headers));

        if ($sent) {
            error_log("Calendar invite sent successfully to $to via PHP mail()");
        } else {
            error_log("Failed to send calendar invite to $to via PHP mail()");
        }

        return $sent;
    }

    /**
     * Calendar invite email template
     */
    private function getCalendarInviteTemplate($event, $action, $rsvpToken = null) {
        $title = $event['summary'];
        $location = $event['location'] ?? 'TBD';
        $description = $event['description'] ?? '';
        $startDateTime = new DateTime($event['startDateTime']);
        $endDateTime = new DateTime($event['endDateTime']);

        $dateFormatted = $startDateTime->format('l, F j, Y');
        $timeFormatted = $startDateTime->format('g:i A') . ' - ' . $endDateTime->format('g:i A');

        $actionMessage = '';
        if ($action === 'cancel') {
            $actionMessage = '<div style="background: #fee; border-left: 4px solid #c00; padding: 15px; margin: 20px 0;"><strong>This event has been cancelled.</strong></div>';
        } elseif ($action === 'update') {
            $actionMessage = '<div style="background: #fef9e7; border-left: 4px solid #f39c12; padding: 15px; margin: 20px 0;"><strong>This event has been updated. Please check the details below.</strong></div>';
        }

        $descriptionHtml = '';
        if ($description) {
            $descriptionHtml = "<div class='detail-row'><span class='detail-label'>📝 Details:</span><br>{$description}</div>";
        }

        // Build RSVP buttons if token is provided and action is not cancel
        $rsvpButtons = '';
        if ($rsvpToken && $action !== 'cancel') {
            $apiUrl = getenv('API_URL') ?: 'https://teamselevated-backend-0485388bd66e.herokuapp.com';
            $acceptUrl = $apiUrl . '/api/rsvp-webhook.php?action=respond&token=' . $rsvpToken . '&response=accepted';
            $declineUrl = $apiUrl . '/api/rsvp-webhook.php?action=respond&token=' . $rsvpToken . '&response=declined';
            $tentativeUrl = $apiUrl . '/api/rsvp-webhook.php?action=respond&token=' . $rsvpToken . '&response=tentative';

            $rsvpButtons = <<<RSVP
            <div style="text-align: center; margin: 30px 0;">
                <p style="font-weight: bold; margin-bottom: 15px;">Will you attend?</p>
                <a href="{$acceptUrl}" style="display: inline-block; background: #28a745; color: white; padding: 12px 25px; text-decoration: none; border-radius: 5px; margin: 5px;">✓ Yes</a>
                <a href="{$tentativeUrl}" style="display: inline-block; background: #ffc107; color: #333; padding: 12px 25px; text-decoration: none; border-radius: 5px; margin: 5px;">? Maybe</a>
                <a href="{$declineUrl}" style="display: inline-block; background: #dc3545; color: white; padding: 12px 25px; text-decoration: none; border-radius: 5px; margin: 5px;">✗ No</a>
            </div>
RSVP;
        }

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #12443E 0%, #12443E 100%); color: white; padding: 30px; text-align: center; }
        .content { background: #f9f9f9; padding: 30px; }
        .event-details { background: white; padding: 20px; margin: 20px 0; border-left: 4px solid #12443E; }
        .event-details h3 { margin-top: 0; color: #12443E; }
        .detail-row { padding: 10px 0; border-bottom: 1px solid #eee; }
        .detail-label { font-weight: bold; color: #666; }
        .button { display: inline-block; background: #12443E; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px; margin: 10px 5px; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Calendar Invitation</h1>
        </div>
        <div class="content">
            {$actionMessage}
            <div class="event-details">
                <h3>{$title}</h3>
                <div class="detail-row">
                    <span class="detail-label">📅 Date:</span> {$dateFormatted}
                </div>
                <div class="detail-row">
                    <span class="detail-label">🕐 Time:</span> {$timeFormatted}
                </div>
                <div class="detail-row">
                    <span class="detail-label">📍 Location:</span> {$location}
                </div>
                {$descriptionHtml}
            </div>
            {$rsvpButtons}
            <p style="text-align: center; margin-top: 30px; color: #666;">
                <em>This event has been added to your calendar. You can also respond directly in your calendar application.</em>
            </p>
        </div>
        <div class="footer">
            <p>&copy; 2025 Teams Elevated. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Calendar invite plain text
     */
    private function getCalendarInviteText($event, $action, $rsvpToken = null) {
        $title = $event['summary'];
        $location = $event['location'] ?? 'TBD';
        $description = $event['description'] ?? '';
        $startDateTime = new DateTime($event['startDateTime']);
        $endDateTime = new DateTime($event['endDateTime']);

        $dateFormatted = $startDateTime->format('l, F j, Y');
        $timeFormatted = $startDateTime->format('g:i A') . ' - ' . $endDateTime->format('g:i A');

        $actionMessage = '';
        if ($action === 'cancel') {
            $actionMessage = "*** THIS EVENT HAS BEEN CANCELLED ***\n\n";
        } elseif ($action === 'update') {
            $actionMessage = "*** THIS EVENT HAS BEEN UPDATED ***\n\n";
        }

        $text = "CALENDAR INVITATION\n\n";
        $text .= $actionMessage;
        $text .= "Event: {$title}\n";
        $text .= "Date: {$dateFormatted}\n";
        $text .= "Time: {$timeFormatted}\n";
        $text .= "Location: {$location}\n";
        if ($description) {
            $text .= "Details: {$description}\n";
        }

        // Add RSVP links if token is provided and action is not cancel
        if ($rsvpToken && $action !== 'cancel') {
            $apiUrl = getenv('API_URL') ?: 'https://teamselevated-backend-0485388bd66e.herokuapp.com';
            $acceptUrl = $apiUrl . '/api/rsvp-webhook.php?action=respond&token=' . $rsvpToken . '&response=accepted';
            $declineUrl = $apiUrl . '/api/rsvp-webhook.php?action=respond&token=' . $rsvpToken . '&response=declined';
            $tentativeUrl = $apiUrl . '/api/rsvp-webhook.php?action=respond&token=' . $rsvpToken . '&response=tentative';

            $text .= "\n---\nWILL YOU ATTEND?\n\n";
            $text .= "Yes, I'll be there: {$acceptUrl}\n";
            $text .= "Maybe: {$tentativeUrl}\n";
            $text .= "No, I can't attend: {$declineUrl}\n";
            $text .= "---\n";
        }

        $text .= "\nThis event has been added to your calendar.\n";
        $text .= "You can also respond directly in your calendar application.\n\n";
        $text .= "Teams Elevated\n";

        return $text;
    }
}
