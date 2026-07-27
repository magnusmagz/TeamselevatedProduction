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
        $htmlBody = $this->generateHTMLBody($event, 'new', $recipient);

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
     * Send series invitations for a recurring event: ONE email per recipient
     * whose ICS carries the series RRULE, so the whole repeating schedule
     * lands in their calendar as a single recurring event.
     *
     * $series = ['group_id', 'calendar_uid', 'rrule', 'dates' => string[],
     *            'label' => human-readable rule]
     * $event is the FIRST occurrence (with venue_name/venue_address/team_names).
     *
     * Invitations are tracked against the first occurrence's event id with
     * calendar_uid = the series UID — every later ICS message about this
     * series (cancel now; RECURRENCE-ID exceptions in Phase 2) must reference
     * that UID.
     */
    public function sendSeriesInvites($event, $recipients, $series) {
        $results = ['sent' => 0, 'failed' => 0, 'errors' => []];

        $ical = $this->generateCalendarInvite($event, $series['calendar_uid'], 'REQUEST', 0, $series['rrule']);
        $subject = "Invitation: {$event['name']} — {$series['label']}";

        foreach ($recipients as $recipient) {
            try {
                $htmlBody = $this->generateSeriesHTMLBody($event, $series, $recipient);
                if ($this->testMode) {
                    $result = $this->mockMailer->send($recipient['email'], $subject, $htmlBody, $ical, 'invite');
                    if (!$result['success']) {
                        throw new Exception('Mock mailer failed');
                    }
                } else {
                    $this->mailer->clearAddresses();
                    $this->mailer->clearAttachments();
                    $this->mailer->addAddress($recipient['email'], $recipient['name']);
                    $this->mailer->Subject = $subject;
                    $this->mailer->isHTML(true);
                    $this->mailer->Body = $htmlBody;
                    $this->mailer->AltBody = $ical;
                    $this->mailer->addStringEmbeddedImage(
                        $ical, 'invite.ics', 'invite.ics', 'base64',
                        'text/calendar; charset=utf-8; method=REQUEST', 'attachment'
                    );
                    $this->mailer->Ical = $ical;
                    $this->mailer->send();
                }

                $this->trackInvitation($event['id'], $recipient, $series['calendar_uid'], 0);
                $results['sent']++;
            } catch (Exception $e) {
                $results['failed']++;
                $results['errors'][] = ['recipient' => $recipient['email'], 'error' => $e->getMessage()];
                $this->logInviteError($event['id'], $recipient, $e->getMessage());
            }
        }

        return $results;
    }

    /**
     * Cancel a WHOLE series: METHOD:CANCEL against the series UID removes the
     * recurring event (all occurrences) from recipients' calendars. Only
     * correct when the entire series is being deleted — mid-series truncation
     * must use sendSeriesChangeNotice() instead.
     */
    public function sendSeriesCancellation($groupId) {
        $series = $this->getSeries($groupId);
        if (!$series) {
            return ['sent' => 0];
        }

        $invitations = $this->getSeriesInvitations($series['calendar_uid']);
        if (empty($invitations)) {
            return ['sent' => 0];
        }

        $newSequence = ((int) $series['ics_sequence']) + 1;
        $event = $this->getEventDetails($invitations[0]['event_id']);
        $sent = 0;

        foreach ($invitations as $invite) {
            try {
                $ical = $this->generateCancellation($event, $series['calendar_uid'], $newSequence);

                if ($this->testMode) {
                    $this->mockMailer->send($invite['recipient_email'], "Cancelled: {$event['name']} (all dates)", $this->generateHTMLBody($event, 'cancel'), $ical, 'cancel');
                } else {
                    $this->mailer->clearAddresses();
                    $this->mailer->clearAttachments();
                    $this->mailer->addAddress($invite['recipient_email'], $invite['recipient_name']);
                    $this->mailer->Subject = "Cancelled: {$event['name']} (all dates)";
                    $this->mailer->isHTML(true);
                    $this->mailer->Body = $this->generateHTMLBody($event, 'cancel');
                    $this->mailer->AltBody = $ical;
                    $this->mailer->Ical = $ical;
                    $this->mailer->send();
                }

                $this->updateInvitationStatus($invite['id'], 'cancelled');
                $sent++;
            } catch (Exception $e) {
                $this->logInviteError($invite['event_id'], ['email' => $invite['recipient_email']], $e->getMessage());
            }
        }

        $stmt = $this->pdo->prepare("UPDATE calendar_event_series SET ics_sequence = ? WHERE group_id = ?");
        $stmt->execute([$newSequence, $groupId]);

        return ['sent' => $sent];
    }

    /**
     * Plain notification email (NO ICS) to everyone invited to a series.
     * Used for changes we don't yet express as iCal exceptions: a single
     * occurrence edited or removed, or a mid-series truncation. The email
     * tells recipients to update their calendars by hand — honest until
     * Phase 2 sends real RECURRENCE-ID exceptions.
     */
    public function sendSeriesChangeNotice($groupId, $subject, $messageHtml) {
        $series = $this->getSeries($groupId);
        if (!$series) {
            return ['sent' => 0];
        }

        $invitations = $this->getSeriesInvitations($series['calendar_uid']);
        $sent = 0;

        $html = <<<HTML
<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 20px auto;">
    <div style="background-color: #ffc107; color: #333; padding: 16px; text-align: center; border-radius: 8px 8px 0 0;">
        <h2 style="margin: 0;">Schedule Change</h2>
    </div>
    <div style="padding: 24px; border: 1px solid #eee; border-top: none; border-radius: 0 0 8px 8px;">
        {$messageHtml}
        <p style="color: #666; font-size: 13px;">Please update this in your calendar — this change is not applied automatically.</p>
        <p style="color: #999; font-size: 12px;">Sent by Teams Elevated. This is an automated message.</p>
    </div>
</div>
HTML;

        foreach ($invitations as $invite) {
            try {
                if ($this->testMode) {
                    $this->mockMailer->send($invite['recipient_email'], $subject, $html, '', 'notice');
                } else {
                    $this->mailer->clearAddresses();
                    $this->mailer->clearAttachments();
                    $this->mailer->Ical = ''; // plain email — never attach calendar data here
                    $this->mailer->addAddress($invite['recipient_email'], $invite['recipient_name']);
                    $this->mailer->Subject = $subject;
                    $this->mailer->isHTML(true);
                    $this->mailer->Body = $html;
                    $this->mailer->AltBody = strip_tags(str_replace(['<br>', '</p>'], "\n", $messageHtml));
                    $this->mailer->send();
                }
                $sent++;
            } catch (Exception $e) {
                $this->logInviteError($invite['event_id'], ['email' => $invite['recipient_email']], $e->getMessage());
            }
        }

        return ['sent' => $sent];
    }

    /**
     * Invite email body for a recurring series: the rule, time, place, and
     * the first dates (capped — the ICS carries the full schedule).
     */
    private function generateSeriesHTMLBody($event, $series, $recipient = null) {
        $timeStr = '';
        if (!empty($event['start_time'])) {
            $timeStr = date('g:i A', strtotime($event['start_time']));
            if (!empty($event['end_time'])) {
                $timeStr .= ' - ' . date('g:i A', strtotime($event['end_time']));
            }
        }

        $location = $event['venue_name'] ?? $event['location'] ?? '';

        $dates = $series['dates'];
        $shown = array_slice($dates, 0, 8);
        $dateItems = '';
        foreach ($shown as $d) {
            $dateItems .= '<li>' . date('l, F j, Y', strtotime($d)) . '</li>';
        }
        $more = count($dates) - count($shown);
        if ($more > 0) {
            $dateItems .= "<li>… and {$more} more</li>";
        }

        $detailRows = "<p><strong>Schedule:</strong> {$series['label']}</p>";
        if ($timeStr) {
            $detailRows .= "<p><strong>Time:</strong> {$timeStr}</p>";
        }
        if ($location) {
            $detailRows .= "<p><strong>Location:</strong> {$location}</p>";
        }
        if (!empty($event['team_names'])) {
            $detailRows .= "<p><strong>Teams:</strong> {$event['team_names']}</p>";
        }
        if (!empty($event['description'])) {
            $detailRows .= "<p><strong>Details:</strong> {$event['description']}</p>";
        }

        $appUrl = rtrim(getenv('APP_URL') ?: 'https://teams-elevated.netlify.app', '/');
        $quick = $this->rsvpQuickButtons($event, $recipient);
        $rsvpButton = ($quick ?: '')
            . '<p style="text-align:center; font-size:13px; margin-top:4px;">'
            . '<a href="' . htmlspecialchars($appUrl . '/parent/schedule/rsvp/' . ($event['id'] ?? ''), ENT_QUOTES) . '" style="color:#12443e;">Manage RSVP in the app &rarr;</a>'
            . ' &nbsp;·&nbsp; The full recurring schedule is attached for your calendar.</p>';

        return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family: Arial, sans-serif; margin: 0; padding: 0; background-color: #f4f4f4;">
    <div style="max-width: 600px; margin: 20px auto; background-color: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <div style="background-color: #28a745; color: white; padding: 20px; text-align: center;">
            <h1 style="margin: 0;">You're Invited!</h1>
        </div>
        <div style="padding: 30px;">
            <h2>{$event['name']}</h2>
            <div style="background-color: #f8f9fa; border-left: 4px solid #28a745; padding: 15px; margin: 20px 0;">
                {$detailRows}
            </div>
            <p><strong>Dates:</strong></p>
            <ul>{$dateItems}</ul>
            {$rsvpButton}
        </div>
        <div style="background-color: #f8f9fa; padding: 20px; text-align: center; font-size: 12px; color: #666;">
            <p>Sent by Teams Elevated</p>
            <p>This is an automated message. Please do not reply to this email.</p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    private function getSeries($groupId) {
        $stmt = $this->pdo->prepare("SELECT * FROM calendar_event_series WHERE group_id = ?");
        $stmt->execute([$groupId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function getSeriesInvitations($calendarUid) {
        $stmt = $this->pdo->prepare("
            SELECT * FROM event_invitations
            WHERE calendar_uid = ? AND status != 'cancelled'
        ");
        $stmt->execute([$calendarUid]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
        $this->mailer->Body = $this->generateHTMLBody($event, 'update', $recipient);

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
                // Generate cancellation iCal (sequence must exceed the last REQUEST's)
                $ical = $this->generateCancellation($event, $invite['calendar_uid'], ((int) ($invite['sequence'] ?? 0)) + 1);

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
    private function generateCalendarInvite($event, $uid, $method = 'REQUEST', $sequence = 0, $rrule = null) {
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
        if (!empty($rrule)) {
            $ical .= "RRULE:{$rrule}\r\n";
        }
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
    private function generateCancellation($event, $uid, $sequence = 1) {
        $now = gmdate('Ymd\THis\Z');

        $ical = "BEGIN:VCALENDAR\r\n";
        $ical .= "VERSION:2.0\r\n";
        $ical .= "PRODID:{$this->config['calendar']['product_id']}\r\n";
        $ical .= "METHOD:CANCEL\r\n";
        $ical .= "BEGIN:VEVENT\r\n";
        $ical .= "UID:{$uid}\r\n";
        $ical .= "DTSTAMP:{$now}\r\n";
        $ical .= "SEQUENCE:{$sequence}\r\n";
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
    /**
     * One-click RSVP buttons (Yes / Maybe / No) for an invite email. Each links
     * to the public api/event-rsvp.php with a signed token for this recipient —
     * writes straight into the RSVP system, no login. Returns '' if we can't
     * identify the recipient (e.g. coaches, or no event id).
     */
    private function rsvpQuickButtons($event, $recipient) {
        if (empty($recipient) || empty($event['id'])) return '';
        require_once __DIR__ . '/../lib/RsvpToken.php';
        $type = $recipient['type'] ?? '';
        if ($type === 'guardian' && !empty($recipient['id'])) {
            $payload = ['e' => (int)$event['id'], 'g' => (int)$recipient['id']];
        } elseif ($type === 'athlete' && !empty($recipient['id'])) {
            $payload = ['e' => (int)$event['id'], 'a' => (int)$recipient['id']];
        } else {
            return '';
        }
        $token = RsvpToken::make($payload);
        $base = rtrim(getenv('BACKEND_URL') ?: 'https://teamselevated-backend-0485388bd66e.herokuapp.com', '/');
        $link = function ($r) use ($base, $token) {
            return htmlspecialchars($base . '/api/event-rsvp.php?token=' . urlencode($token) . '&r=' . $r, ENT_QUOTES);
        };
        return '<div style="text-align:center; margin:24px 0;">'
            . '<p style="margin:0 0 12px; font-weight:bold;">Can they make it?</p>'
            . '<a href="' . $link('yes') . '" style="display:inline-block; background:#28a745; color:#fff; text-decoration:none; padding:12px 22px; border-radius:6px; font-weight:bold; margin:4px;">&#10003; Yes</a>'
            . '<a href="' . $link('maybe') . '" style="display:inline-block; background:#e0a800; color:#fff; text-decoration:none; padding:12px 22px; border-radius:6px; font-weight:bold; margin:4px;">Maybe</a>'
            . '<a href="' . $link('no') . '" style="display:inline-block; background:#dc3545; color:#fff; text-decoration:none; padding:12px 22px; border-radius:6px; font-weight:bold; margin:4px;">&#10007; No</a>'
            . '<p style="font-size:12px; color:#666; margin-top:10px;">One tap &mdash; no login needed. You can change it anytime.</p></div>';
    }

    private function generateHTMLBody($event, $type = 'new', $recipient = null) {
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

        $appUrl = rtrim(getenv('APP_URL') ?: 'https://teams-elevated.netlify.app', '/');
        $quick = $this->rsvpQuickButtons($event, $recipient);
        $manageLink = '<p style="text-align:center; font-size:13px; margin-top:4px;">'
            . '<a href="' . htmlspecialchars($appUrl . '/parent/schedule/rsvp/' . ($event['id'] ?? ''), ENT_QUOTES) . '" style="color:#12443e;">Manage RSVP in the app &rarr;</a></p>';

        if ($type === 'new') {
            $html .= $quick . ($quick ? $manageLink : '');
        } elseif ($type === 'update') {
            $html .= "<p><strong>This event has been updated.</strong> Your calendar will update automatically — please re-confirm below.</p>";
            $html .= $quick . ($quick ? $manageLink : '');
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