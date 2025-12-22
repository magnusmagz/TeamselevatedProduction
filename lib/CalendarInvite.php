<?php
/**
 * CalendarInvite Library
 *
 * Generates iCalendar (.ics) format invitations that work with:
 * - Google Calendar
 * - Outlook
 * - Apple Calendar
 * - Other standards-compliant calendar apps
 */

class CalendarInvite {

    /**
     * Generate an iCalendar invitation
     *
     * @param array $event Event details
     * @return string iCalendar formatted string
     */
    public static function generate($event) {
        $required = ['summary', 'startDateTime', 'endDateTime', 'organizerEmail', 'organizerName'];
        foreach ($required as $field) {
            if (empty($event[$field])) {
                throw new Exception("Missing required field: $field");
            }
        }

        // Generate unique ID for this event
        $uid = self::generateUID();

        // Format dates to iCalendar format (UTC)
        $dtStart = self::formatDateTime($event['startDateTime']);
        $dtEnd = self::formatDateTime($event['endDateTime']);
        $dtStamp = self::formatDateTime(new DateTime());

        // Build the iCalendar content
        $ics = "BEGIN:VCALENDAR\r\n";
        $ics .= "VERSION:2.0\r\n";
        $ics .= "PRODID:-//Teams Elevated//Calendar Invite//EN\r\n";
        $ics .= "METHOD:REQUEST\r\n";
        $ics .= "CALSCALE:GREGORIAN\r\n";
        $ics .= "BEGIN:VEVENT\r\n";
        $ics .= "UID:" . $uid . "\r\n";
        $ics .= "DTSTAMP:" . $dtStamp . "\r\n";
        $ics .= "DTSTART:" . $dtStart . "\r\n";
        $ics .= "DTEND:" . $dtEnd . "\r\n";
        $ics .= "SUMMARY:" . self::escapeString($event['summary']) . "\r\n";

        // Optional fields
        if (!empty($event['description'])) {
            $ics .= "DESCRIPTION:" . self::escapeString($event['description']) . "\r\n";
        }

        if (!empty($event['location'])) {
            $ics .= "LOCATION:" . self::escapeString($event['location']) . "\r\n";
        }

        // Organizer (the person sending the invite)
        $ics .= "ORGANIZER;CN=" . self::escapeString($event['organizerName']) . ":MAILTO:" . $event['organizerEmail'] . "\r\n";

        // Attendees (optional)
        if (!empty($event['attendees']) && is_array($event['attendees'])) {
            foreach ($event['attendees'] as $attendee) {
                $attendeeName = $attendee['name'] ?? '';
                $attendeeEmail = $attendee['email'] ?? '';

                if ($attendeeEmail) {
                    $ics .= "ATTENDEE;CN=" . self::escapeString($attendeeName) .
                           ";RSVP=TRUE;PARTSTAT=NEEDS-ACTION;ROLE=REQ-PARTICIPANT:MAILTO:" .
                           $attendeeEmail . "\r\n";
                }
            }
        }

        // Status
        $status = $event['status'] ?? 'CONFIRMED';
        $ics .= "STATUS:" . strtoupper($status) . "\r\n";

        // Sequence (for updates)
        $sequence = $event['sequence'] ?? 0;
        $ics .= "SEQUENCE:" . $sequence . "\r\n";

        // Reminder (15 minutes before)
        $ics .= "BEGIN:VALARM\r\n";
        $ics .= "TRIGGER:-PT15M\r\n";
        $ics .= "ACTION:DISPLAY\r\n";
        $ics .= "DESCRIPTION:Reminder\r\n";
        $ics .= "END:VALARM\r\n";

        $ics .= "END:VEVENT\r\n";
        $ics .= "END:VCALENDAR\r\n";

        return $ics;
    }

    /**
     * Generate a cancellation notice
     */
    public static function generateCancellation($event) {
        $event['status'] = 'CANCELLED';
        $event['sequence'] = ($event['sequence'] ?? 0) + 1;

        $ics = self::generate($event);
        // Change METHOD to CANCEL
        $ics = str_replace('METHOD:REQUEST', 'METHOD:CANCEL', $ics);

        return $ics;
    }

    /**
     * Generate an update notice
     */
    public static function generateUpdate($event) {
        $event['sequence'] = ($event['sequence'] ?? 0) + 1;
        return self::generate($event);
    }

    /**
     * Generate a unique ID for the event
     */
    private static function generateUID() {
        return uniqid() . '@teams-elevated.com';
    }

    /**
     * Format DateTime to iCalendar format (YYYYMMDDTHHMMSSZ)
     */
    private static function formatDateTime($dateTime) {
        if (is_string($dateTime)) {
            $dateTime = new DateTime($dateTime);
        }

        // Convert to UTC
        $dateTime->setTimezone(new DateTimeZone('UTC'));
        return $dateTime->format('Ymd\THis\Z');
    }

    /**
     * Escape special characters in strings
     */
    private static function escapeString($string) {
        $string = str_replace('\\', '\\\\', $string);
        $string = str_replace(',', '\,', $string);
        $string = str_replace(';', '\;', $string);
        $string = str_replace("\n", '\\n', $string);
        return $string;
    }
}
