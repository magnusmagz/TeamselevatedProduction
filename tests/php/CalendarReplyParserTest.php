<?php

use PHPUnit\Framework\TestCase;

if (!defined('TE_CALENDAR_REPLY_LIB_ONLY')) {
    define('TE_CALENDAR_REPLY_LIB_ONLY', true);
}
require_once __DIR__ . '/../../api/calendar-reply-parser.php';

/**
 * Email-REPLY RSVP — api/calendar-reply-parser.php (SendGrid Inbound Parse).
 *
 * A family clicks Yes/No/Maybe in Gmail or Apple Mail; the calendar app mails an
 * iCalendar METHOD:REPLY to the ICS organizer address, events@rsvp.eyeinteams.com
 * (which is why CLAUDE.md pins that address — REPLY parsing keys off it), SendGrid
 * POSTs the raw MIME as `email`, and this file writes rsvp_status.
 *
 * Shipped with zero automated coverage: the only exercise was test-reply-webhook.php
 * at the repo root, which curls PRODUCTION and asserts nothing. The payloads below
 * are that script's, against an in-memory calendar_event_attendees mirroring
 * tests/fixtures/production-schema.json.
 *
 * ⚠️ NOT COVERED, and not coverable without a refactor: the MIME → iCalendar
 * extraction (the `BEGIN:VCALENDAR.*?END:VCALENDAR` preg_match) and the HTTP status
 * codes live inline in the request-dispatch block at the bottom of the file, not in
 * a function, so nothing can call them. These tests hand the raw MIME straight to
 * parseCalendarReply(), which tolerates the headers because it only matches lines
 * beginning UID:/ATTENDEE.
 */
class CalendarReplyParserTest extends TestCase
{
    private PDO $pdo;
    private string $errorLog = '';

    protected function setUp(): void
    {
        // The file error_log()s every branch. Keep the suite output readable.
        $this->errorLog = (string) ini_get('error_log');
        ini_set('error_log', '/dev/null');

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("
            CREATE TABLE calendar_event_attendees (
                id INTEGER PRIMARY KEY,
                event_id INTEGER,
                user_id INTEGER,
                email TEXT,
                rsvp_status TEXT,
                rsvp_token TEXT,
                responded_at TEXT,
                created_at TEXT,
                athlete_id INTEGER
            )
        ");
        // One invited guardian, not yet answered, on event 4021.
        $this->attendee(1, 4021, 'maggie@4msquared.com', 'pending', '2026-09-01 10:00:00');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->errorLog);
    }

    private function attendee(int $id, int $eventId, string $email, string $status, string $created): void
    {
        $this->pdo->prepare(
            "INSERT INTO calendar_event_attendees
             (id, event_id, user_id, email, rsvp_status, rsvp_token, responded_at, created_at, athlete_id)
             VALUES (?, ?, 16, ?, ?, 'tok' || ?, NULL, ?, NULL)"
        )->execute([$id, $eventId, $email, $status, $id, $created]);
    }

    private function row(int $id): array
    {
        $s = $this->pdo->prepare("SELECT * FROM calendar_event_attendees WHERE id = ?");
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * The shape SendGrid Inbound Parse POSTs in `email` — copied from
     * test-reply-webhook.php, which is what Google Calendar actually sends.
     */
    private function inboundReply(string $partstat, string $from, string $uid = '68dfde87bbcc1@teams-elevated.com'): string
    {
        $verb = ['ACCEPTED' => 'Accepted', 'DECLINED' => 'Declined', 'TENTATIVE' => 'Tentative'][$partstat] ?? 'Updated';
        return "Received: from mail-example.com\r\n"
            . "Subject: {$verb}: Team Practice - Soccer U12\r\n"
            . "From: {$from}\r\n"
            . "To: events@rsvp.eyeinteams.com\r\n"
            . "\r\n"
            . "BEGIN:VCALENDAR\r\n"
            . "VERSION:2.0\r\n"
            . "PRODID:-//Google Inc//Google Calendar 70.9054//EN\r\n"
            . "METHOD:REPLY\r\n"
            . "BEGIN:VEVENT\r\n"
            . "UID:{$uid}\r\n"
            . "DTSTAMP:20260901T143500Z\r\n"
            . "SUMMARY:Team Practice - Soccer U12\r\n"
            . "ATTENDEE;CN=Maggie;PARTSTAT={$partstat};RSVP=TRUE:MAILTO:{$from}\r\n"
            . "ORGANIZER:MAILTO:events@rsvp.eyeinteams.com\r\n"
            . "SEQUENCE:0\r\n"
            . "END:VEVENT\r\n"
            . "END:VCALENDAR\r\n";
    }

    // Mutation: map 'ACCEPTED' => 'declined' in mapPartstatToRsvpStatus — the write is wrong.
    public function testAnAcceptedReplyWritesAccepted(): void
    {
        $reply = parseCalendarReply($this->inboundReply('ACCEPTED', 'maggie@4msquared.com'));
        $result = processCalendarReply($this->pdo, $reply);

        $this->assertTrue($result['success']);
        $this->assertSame('accepted', $result['status']);
        $this->assertSame(4021, (int) $result['event_id']);

        $row = $this->row(1);
        $this->assertSame('accepted', $row['rsvp_status']);
        $this->assertNotNull($row['responded_at'], 'responded_at must be stamped, not left NULL');
    }

    // Mutation: map 'DECLINED' => 'accepted' — a family that cannot come is marked attending.
    public function testADeclinedReplyWritesDeclined(): void
    {
        $result = processCalendarReply($this->pdo, parseCalendarReply($this->inboundReply('DECLINED', 'maggie@4msquared.com')));

        $this->assertTrue($result['success']);
        $this->assertSame('declined', $this->row(1)['rsvp_status']);
    }

    // Mutation: drop the 'TENTATIVE' entry from the mapping — Maybe silently becomes 'pending'.
    public function testATentativeReplyWritesTentative(): void
    {
        $result = processCalendarReply($this->pdo, parseCalendarReply($this->inboundReply('TENTATIVE', 'maggie@4msquared.com')));

        $this->assertTrue($result['success']);
        $this->assertSame('tentative', $this->row(1)['rsvp_status']);
    }

    // Mutation: change the `?? 'pending'` fallback to `?? 'accepted'` — a DELEGATED reply marks them attending.
    public function testAnUnrecognisedPartstatFallsBackToPendingRatherThanGuessing(): void
    {
        $this->assertSame('pending', mapPartstatToRsvpStatus('DELEGATED'));
        $this->assertSame('pending', mapPartstatToRsvpStatus('NEEDS-ACTION'));
        $this->assertSame('pending', mapPartstatToRsvpStatus(''));
    }

    // Mutation: delete the "Missing required data" guard at the top of processCalendarReply.
    public function testAPlainTextReplyWithNoCalendarPartWritesNothing(): void
    {
        $body = "Subject: Re: Team Practice - Soccer U12\r\n"
            . "From: maggie@4msquared.com\r\n"
            . "To: events@rsvp.eyeinteams.com\r\n"
            . "\r\n"
            . "Sure, we will be there! Thanks -- Maggie\r\n";

        $reply = parseCalendarReply($body);
        $this->assertNull($reply['uid']);
        $this->assertNull($reply['attendee_email']);
        $this->assertNull($reply['partstat']);

        $result = processCalendarReply($this->pdo, $reply);
        $this->assertFalse($result['success']);
        $this->assertSame('Missing required data in REPLY', $result['error']);
        $this->assertSame('pending', $this->row(1)['rsvp_status'], 'an unreadable reply must not move anyone');
    }

    // Mutation: delete the `if (!$attendeeRecord)` branch — the UPDATE then runs on id NULL.
    public function testAReplyFromSomebodyWhoIsNotAnAttendeeWritesNothing(): void
    {
        $reply = parseCalendarReply($this->inboundReply('ACCEPTED', 'stranger@example.com'));
        $result = processCalendarReply($this->pdo, $reply);

        $this->assertFalse($result['success']);
        $this->assertSame('No matching attendee found', $result['error']);
        $this->assertSame('stranger@example.com', $result['email']);
        $this->assertSame('pending', $this->row(1)['rsvp_status']);
        $this->assertSame(
            0,
            (int) $this->pdo->query("SELECT COUNT(*) FROM calendar_event_attendees WHERE responded_at IS NOT NULL")->fetchColumn()
        );
    }

    // Mutation: drop strtolower() in parseCalendarReply — 'Maggie@…' then misses the stored row.
    public function testTheAttendeeAddressIsLowercasedOutOfTheIcs(): void
    {
        $reply = parseCalendarReply($this->inboundReply('ACCEPTED', 'Maggie@4msquared.com'));

        $this->assertSame('maggie@4msquared.com', $reply['attendee_email']);
        $this->assertSame('ACCEPTED', $reply['partstat']);
        $this->assertSame('68dfde87bbcc1@teams-elevated.com', $reply['uid']);
        $this->assertTrue(processCalendarReply($this->pdo, $reply)['success']);
    }

    /**
     * ⚠️ FINDING. processCalendarReply matches `WHERE email = :email`, which is
     * case-sensitive in Postgres (and in SQLite). The parser lowercases the address
     * off the ICS, so a stored attendee row carrying any capital letter can never be
     * matched and the family's reply is silently dropped — HTTP 400, no record.
     *
     * This is the guardian-email-case class CLAUDE.md documents (2026-08-18, ten
     * query sites fixed with LOWER() on both sides); this site was not in that sweep,
     * and GuardianEmailCaseTest does not scan it. Fixed 2026-09-02 with `LOWER()` on both sides.
     */
    // Mutation: revert `LOWER(email) = LOWER(:email)` to `email = :email` in processCalendarReply.
    public function testAStoredAddressWithACapitalLetterStillMatches(): void
    {
        $this->pdo->exec("DELETE FROM calendar_event_attendees");
        $this->attendee(2, 4021, 'Emilygovier0@gmail.com', 'pending', '2026-09-01 10:00:00');

        $reply = parseCalendarReply($this->inboundReply('ACCEPTED', 'emilygovier0@gmail.com'));
        $result = processCalendarReply($this->pdo, $reply);

        $this->assertTrue($result['success'], 'one capital letter in the stored address must not drop the reply');
        $this->assertSame('accepted', $this->row(2)['rsvp_status']);
    }

    /**
     * ⚠️ FINDING. The parsed UID is logged and then discarded: the lookup is
     * "most recently created PENDING row for this email", so a REPLY about one event
     * lands on whichever invitation happens to be newest. A family invited to two
     * events who declines the first one marks the second instead.
     *
     * The file says so itself ("In production, we should store the UID in the
     * calendar_events table"). Nothing stores it, so there is nothing to match on.
     */
    // Mutation: none — this test fails once the UID is honoured, which is the point.
    public function testTheReplyIsRoutedByEmailAndRecencyNotByUid_KNOWN_DEFECT(): void
    {
        // Same guardian, two events. Event 5099 was invited later.
        $this->attendee(2, 5099, 'maggie@4msquared.com', 'pending', '2026-09-02 10:00:00');

        // A REPLY that names event 4021's invitation UID.
        $reply = parseCalendarReply($this->inboundReply('DECLINED', 'maggie@4msquared.com', 'uid-for-event-4021@teams-elevated.com'));
        $result = processCalendarReply($this->pdo, $reply);

        $this->assertTrue($result['success']);
        $this->assertSame(5099, (int) $result['event_id'], 'the UID is now honoured — delete this test and the note above it');
        $this->assertSame('pending', $this->row(1)['rsvp_status'], 'the event they actually replied about is untouched');
        $this->assertSame('declined', $this->row(2)['rsvp_status']);
    }

    /**
     * ⚠️ FINDING. The lookup filters `rsvp_status = 'pending'`, so a family can
     * answer by email exactly once. Changing their mind ("Actually we can't make
     * it") returns 'No matching attendee found' and the stale answer stands — while
     * the one-click link in api/event-rsvp.php explicitly invites them to change it.
     */
    // Mutation: none — this test fails once a second reply is accepted, which is the point.
    public function testASecondReplyCannotChangeTheAnswer_KNOWN_DEFECT(): void
    {
        processCalendarReply($this->pdo, parseCalendarReply($this->inboundReply('ACCEPTED', 'maggie@4msquared.com')));
        $this->assertSame('accepted', $this->row(1)['rsvp_status']);

        $second = processCalendarReply($this->pdo, parseCalendarReply($this->inboundReply('DECLINED', 'maggie@4msquared.com')));

        $this->assertFalse($second['success'], 'a change of mind is now accepted — delete this test and the note above it');
        $this->assertSame('accepted', $this->row(1)['rsvp_status']);
    }
}
