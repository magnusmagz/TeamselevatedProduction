<?php
/**
 * Calendar Invite Service
 *
 * Handles creation and sending of calendar invites for events
 * Supports native calendar invites that appear directly in email clients
 */

require_once __DIR__ . '/../vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

class CalendarInviteService {
    private $pdo;
    private $config;
    private $mailer;
    private $testMode = false;
    private $mockMailer = null;

    public function __construct($pdo, $testMode = false) {
        $this->pdo = $pdo;
        $this->config = require(__DIR__ . '/../config/mail.php');
        $this->testMode = $testMode || (getenv('APP_ENV') === 'test');

        if ($this->testMode) {
            require_once __DIR__ . '/MockMailerService.php';
            $this->mockMailer = new MockMailerService();
        } else {
            $this->initializeMailer();
        }
    }

    /**
     * Initialize PHPMailer with configuration
     */
    private function initializeMailer() {
        $this->mailer = new PHPMailer(true);

        // Server settings
        $this->mailer->isSMTP();
        $this->mailer->Host = $this->config['smtp']['host'];
        $this->mailer->SMTPAuth = true;
        $this->mailer->Username = $this->config['smtp']['username'];
        $this->mailer->Password = $this->config['smtp']['password'];
        $this->mailer->SMTPSecure = $this->config['smtp']['encryption'] === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
        $this->mailer->Port = $this->config['smtp']['port'];

        // Default sender
        $this->mailer->setFrom(
            $this->config['smtp']['from_email'],
            $this->config['smtp']['from_name']
        );
    }

    /**
     * Send event invitations to all recipients
     */
    public function sendEventInvites($event, $recipients) {
        $results = [
            'sent' => 0,
            'failed' => 0,
            'errors' => []
        ];

        // Generate unique UID for this event
        $uid = $this->generateUID($event['id']);

        foreach ($recipients as $recipient) {
            try {
                // Check if invite already exists
                $existingInvite = $this->getExistingInvite($event['id'], $recipient['email']);

                if ($existingInvite) {
                    // Update existing invite
                    $this->sendEventUpdate($event, $recipient, $existingInvite);
                } else {
                    // Send new invite
                    $this->sendNewInvite($event, $recipient, $uid);
                }

                $results['sent']++;
            } catch (Exception $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'recipient' => $recipient['email'],
                    'error' => $e->getMessage()
                ];

                // Log error
                $this->logInviteError($event['id'], $recipient, $e->getMessage());
            }
        }

        return $results;
    }

    /**
     * Send a new calendar invite
     */
    private function sendNewInvite($event, $recipient, $uid) {
        // Create iCalendar content
        $ical = $this->generateCalendarInvite($event, $uid, 'REQUEST', 0);

        // Set subject
        $eventDate = date('M j, Y', strtotime($event['event_date']));
        $subject = "Invitation: {$event['name']} @ {$eventDate}";

        // Set HTML body
        $htmlBody = $this->generateHTMLBody($event, 'new');

        // Handle test mode
        if ($this->testMode) {
            $result = $this->mockMailer->send(
                $recipient['email'],
                $subject,
                $htmlBody,
                $ical,
                'invite'
            );

            if (!$result['success']) {
                throw new Exception('Mock mailer failed');
            }
        } else {
            // Prepare email
            $this->mailer->clearAddresses();
            $this->mailer->clearAttachments();
            $this->mailer->addAddress($recipient['email'], $recipient['name']);

            $this->mailer->Subject = $subject;

            // Set HTML body
            $this->mailer->isHTML(true);
            $this->mailer->Body = $htmlBody;

            // Add calendar data as alternative content
            $this->mailer->AltBody = $ical;

            // CRITICAL: Add calendar invite with proper MIME type
            $this->mailer->addStringEmbeddedImage(
                $ical,
                'invite.ics',
                'invite.ics',
                'base64',
                'text/calendar; charset=utf-8; method=REQUEST',
                'attachment'
            );

            // Alternative method that works better with some clients
            $this->mailer->Ical = $ical;

            // Send the email
            $this->mailer->send();
        }

        // Track the invitation
        $this->trackInvitation($event['id'], $recipient, $uid, 0);
    }

    /**
     * Send an update to an existing calendar invite
     */
    public function sendEventUpdate($event, $recipient, $existingInvite) {
        // Increment sequence number
        $newSequence = $existingInvite['sequence'] + 1;

        // Use same UID as original
        $uid = $existingInvite['calendar_uid'];

        // Create updated iCalendar content
        $ical = $this->generateCalendarInvite($event, $uid, 'REQUEST', $newSequence);

        // Prepare email
        $this->mailer->clearAddresses();
        $this->mailer->clearAttachments();
        $this->mailer->addAddress($recipient['email'], $recipient['name']);

        // Set subject for update
        $eventDate = date('M j, Y', strtotime($event['event_date']));
        $this->mailer->Subject = "Updated: {$event['name']} @ {$eventDate}";

        // Set HTML body
        $this->mailer->isHTML(true);
        $this->mailer->Body = $this->generateHTMLBody($event, 'update');

        // Add calendar data
        $this->mailer->AltBody = $ical;
        $this->mailer->Ical = $ical;

        // Send the email
        $this->mailer->send();

        // Update invitation tracking
        $this->updateInvitationSequence($existingInvite['id'], $newSequence);
    }

    /**
     * Send event cancellation
     */
    public function sendEventCancellation($eventId) {
        // Get all invitations for this event
        $stmt = $this->pdo->prepare("
            SELECT * FROM event_invitations
            WHERE event_id = ? AND status != 'cancelled'
        ");
        $stmt->execute([$eventId]);
        $invitations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get event details
        $event = $this->getEventDetails($eventId);

        foreach ($invitations as $invite) {
            try {
                // Generate cancellation iCal
                $ical = $this->generateCancellation($event, $invite['calendar_uid']);

                // Prepare email
                $this->mailer->clearAddresses();
                $this->mailer->clearAttachments();
                $this->mailer->addAddress($invite['recipient_email'], $invite['recipient_name']);

                // Set subject
                $eventDate = date('M j, Y', strtotime($event['event_date']));
                $this->mailer->Subject = "Cancelled: {$event['name']} @ {$eventDate}";

                // Set HTML body
                $this->mailer->isHTML(true);
                $this->mailer->Body = $this->generateHTMLBody($event, 'cancel');

                // Add calendar cancellation
                $this->mailer->AltBody = $ical;
                $this->mailer->Ical = $ical;

                // Send the email
                $this->mailer->send();

                // Update invitation status
                $this->updateInvitationStatus($invite['id'], 'cancelled');

            } catch (Exception $e) {
                $this->logInviteError($eventId, ['email' => $invite['recipient_email']], $e->getMessage());
            }
        }
    }

    /**
     * Generate iCalendar content for the invite
     */
    private function generateCalendarInvite($event, $uid, $method = 'REQUEST', $sequence = 0) {
        $timezone = $this->config['calendar']['timezone'];
        $dtstart = $this->formatDateTimeForICal($event['event_date'], $event['start_time'], $timezone);
        $dtend = $this->formatDateTimeForICal($event['event_date'], $event['end_time'], $timezone);
        $now = gmdate('Ymd\THis\Z');

        // Build location string
        $location = $event['venue_name'] ?? '';
        if (!empty($event['venue_address'])) {
            $location .= ', ' . $event['venue_address'];
        } elseif (!empty($event['location'])) {
            $location = $event['location'];
        }

        // Build description
        $description = $event['description'] ?? '';
        if (!empty($event['team_names'])) {
            $description .= "\\n\\nTeams: " . $event['team_names'];
        }

        $ical = "BEGIN:VCALENDAR\r\n";
        $ical .= "VERSION:2.0\r\n";
        $ical .= "PRODID:{$this->config['calendar']['product_id']}\r\n";
        $ical .= "CALSCALE:GREGORIAN\r\n";
        $ical .= "METHOD:{$method}\r\n";

        // Add timezone definition
        $ical .= "BEGIN:VTIMEZONE\r\n";
        $ical .= "TZID:{$timezone}\r\n";
        $ical .= "BEGIN:STANDARD\r\n";
        $ical .= "DTSTART:20231105T020000\r\n";
        $ical .= "TZOFFSETFROM:-0700\r\n";
        $ical .= "TZOFFSETTO:-0800\r\n";
        $ical .= "TZNAME:PST\r\n";
        $ical .= "END:STANDARD\r\n";
        $ical .= "BEGIN:DAYLIGHT\r\n";
        $ical .= "DTSTART:20240310T020000\r\n";
        $ical .= "TZOFFSETFROM:-0800\r\n";
        $ical .= "TZOFFSETTO:-0700\r\n";
        $ical .= "TZNAME:PDT\r\n";
        $ical .= "END:DAYLIGHT\r\n";
        $ical .= "END:VTIMEZONE\r\n";

        // Add event
        $ical .= "BEGIN:VEVENT\r\n";
        $ical .= "UID:{$uid}\r\n";
        $ical .= "DTSTAMP:{$now}\r\n";
        $ical .= "ORGANIZER;CN={$this->config['calendar']['organizer_name']}:mailto:{$this->config['calendar']['organizer_email']}\r\n";
        $ical .= "DTSTART;TZID={$timezone}:{$dtstart}\r\n";
        $ical .= "DTEND;TZID={$timezone}:{$dtend}\r\n";
        $ical .= "SEQUENCE:{$sequence}\r\n";
        $ical .= "SUMMARY:{$this->escapeICalText($event['name'])}\r\n";

        if (!empty($location)) {
            $ical .= "LOCATION:{$this->escapeICalText($location)}\r\n";
        }

        if (!empty($description)) {
            $ical .= "DESCRIPTION:{$this->escapeICalText($description)}\r\n";
        }

        $ical .= "STATUS:CONFIRMED\r\n";
        $ical .= "TRANSP:OPAQUE\r\n";

        // Add reminder
        $reminderMinutes = $this->config['calendar']['reminder_minutes'];
        $ical .= "BEGIN:VALARM\r\n";
        $ical .= "TRIGGER:-PT{$reminderMinutes}M\r\n";
        $ical .= "ACTION:DISPLAY\r\n";
        $ical .= "DESCRIPTION:Event Reminder: {$this->escapeICalText($event['name'])}\r\n";
        $ical .= "END:VALARM\r\n";

        $ical .= "END:VEVENT\r\n";
        $ical .= "END:VCALENDAR\r\n";

        return $ical;
    }

    /**
     * Generate cancellation iCal
     */
    private function generateCancellation($event, $uid) {
        $now = gmdate('Ymd\THis\Z');

        $ical = "BEGIN:VCALENDAR\r\n";
        $ical .= "VERSION:2.0\r\n";
        $ical .= "PRODID:{$this->config['calendar']['product_id']}\r\n";
        $ical .= "METHOD:CANCEL\r\n";
        $ical .= "BEGIN:VEVENT\r\n";
        $ical .= "UID:{$uid}\r\n";
        $ical .= "DTSTAMP:{$now}\r\n";
        $ical .= "ORGANIZER;CN={$this->config['calendar']['organizer_name']}:mailto:{$this->config['calendar']['organizer_email']}\r\n";
        $ical .= "SUMMARY:{$this->escapeICalText($event['name'])}\r\n";
        $ical .= "STATUS:CANCELLED\r\n";
        $ical .= "END:VEVENT\r\n";
        $ical .= "END:VCALENDAR\r\n";

        return $ical;
    }

    /**
     * Generate HTML body for the email
     */
    private function generateHTMLBody($event, $type = 'new') {
        $eventDate = date('l, F j, Y', strtotime($event['event_date']));
        $eventTime = '';

        if ($event['start_time']) {
            $eventTime = date('g:i A', strtotime($event['start_time']));
            if ($event['end_time']) {
                $eventTime .= ' - ' . date('g:i A', strtotime($event['end_time']));
            }
        }

        $title = $type === 'update' ? 'Event Updated' : ($type === 'cancel' ? 'Event Cancelled' : 'You\'re Invited!');
        $bgColor = $type === 'cancel' ? '#dc3545' : ($type === 'update' ? '#ffc107' : '#28a745');

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; background-color: #f4f4f4; }
        .container { max-width: 600px; margin: 20px auto; background-color: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .header { background-color: {$bgColor}; color: white; padding: 20px; text-align: center; }
        .content { padding: 30px; }
        .event-details { background-color: #f8f9fa; border-left: 4px solid {$bgColor}; padding: 15px; margin: 20px 0; }
        .footer { background-color: #f8f9fa; padding: 20px; text-align: center; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>{$title}</h1>
        </div>
        <div class="content">
            <h2>{$event['name']}</h2>
            <div class="event-details">
                <p><strong>Date:</strong> {$eventDate}</p>
HTML;

        if ($eventTime) {
            $html .= "<p><strong>Time:</strong> {$eventTime}</p>";
        }

        if (!empty($event['venue_name'])) {
            $html .= "<p><strong>Location:</strong> {$event['venue_name']}</p>";
        }

        if (!empty($event['team_names'])) {
            $html .= "<p><strong>Teams:</strong> {$event['team_names']}</p>";
        }

        if (!empty($event['description'])) {
            $html .= "<p><strong>Details:</strong> {$event['description']}</p>";
        }

        $html .= <<<HTML
            </div>

HTML;

        if ($type === 'new') {
            $html .= "<p>This event has been added to your calendar. Please accept or decline the invitation in your calendar application.</p>";
        } elseif ($type === 'update') {
            $html .= "<p><strong>This event has been updated.</strong> Your calendar will be automatically updated with the new details.</p>";
        } elseif ($type === 'cancel') {
            $html .= "<p><strong>This event has been cancelled.</strong> It will be removed from your calendar automatically.</p>";
        }

        $html .= <<<HTML
        </div>
        <div class="footer">
            <p>Sent by Teams Elevated</p>
            <p>This is an automated message. Please do not reply to this email.</p>
        </div>
    </div>
</body>
</html>
HTML;

        return $html;
    }

    /**
     * Helper Functions
     */

    private function generateUID($eventId) {
        return sprintf('%s-%d@teamselevated.com', uniqid('evt'), $eventId);
    }

    private function formatDateTimeForICal($date, $time, $timezone) {
        if (empty($time)) {
            $time = '00:00:00';
        }
        $dt = new DateTime("{$date} {$time}", new DateTimeZone($timezone));
        return $dt->format('Ymd\THis');
    }

    private function escapeICalText($text) {
        // Escape special characters for iCal format
        $text = str_replace('\\', '\\\\', $text);
        $text = str_replace(',', '\,', $text);
        $text = str_replace(';', '\;', $text);
        $text = str_replace("\n", '\\n', $text);
        return $text;
    }

    /**
     * Database operations
     */

    private function trackInvitation($eventId, $recipient, $uid, $sequence) {
        $stmt = $this->pdo->prepare("
            INSERT INTO event_invitations
            (event_id, recipient_email, recipient_name, recipient_type, recipient_id,
             calendar_uid, sequence, status, sent_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'sent', NOW())
        ");

        $stmt->execute([
            $eventId,
            $recipient['email'],
            $recipient['name'] ?? '',
            $recipient['type'] ?? 'guardian',
            $recipient['id'] ?? null,
            $uid,
            $sequence
        ]);

        $inviteId = $this->pdo->lastInsertId();

        // Log activity
        $this->logActivity($inviteId, 'sent', ['method' => 'email']);
    }

    private function updateInvitationSequence($inviteId, $sequence) {
        $stmt = $this->pdo->prepare("
            UPDATE event_invitations
            SET sequence = ?, sent_at = NOW(), status = 'sent'
            WHERE id = ?
        ");
        $stmt->execute([$sequence, $inviteId]);

        $this->logActivity($inviteId, 'updated', ['sequence' => $sequence]);
    }

    private function updateInvitationStatus($inviteId, $status) {
        $stmt = $this->pdo->prepare("
            UPDATE event_invitations
            SET status = ?
            WHERE id = ?
        ");
        $stmt->execute([$status, $inviteId]);

        $this->logActivity($inviteId, 'cancelled', []);
    }

    private function getExistingInvite($eventId, $email) {
        $stmt = $this->pdo->prepare("
            SELECT * FROM event_invitations
            WHERE event_id = ? AND recipient_email = ?
        ");
        $stmt->execute([$eventId, $email]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function getEventDetails($eventId) {
        $stmt = $this->pdo->prepare("
            SELECT e.*, v.name as venue_name, v.address as venue_address
            FROM calendar_events e
            LEFT JOIN venues v ON e.venue_id = v.id
            WHERE e.id = ?
        ");
        $stmt->execute([$eventId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function logActivity($inviteId, $action, $details) {
        $stmt = $this->pdo->prepare("
            INSERT INTO invite_activity_log (invitation_id, action, details)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$inviteId, $action, json_encode($details)]);
    }

    private function logInviteError($eventId, $recipient, $error) {
        error_log("Calendar invite error for event {$eventId} to {$recipient['email']}: {$error}");
    }
}
?>