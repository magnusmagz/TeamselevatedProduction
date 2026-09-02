<?php

use PHPUnit\Framework\TestCase;

if (!defined('TE_RSVP_WEBHOOK_LIB_ONLY')) {
    define('TE_RSVP_WEBHOOK_LIB_ONLY', true);
}
require_once __DIR__ . '/../../api/rsvp-webhook.php';

/**
 * Token-keyed public RSVP — api/rsvp-webhook.php?action=respond.
 *
 * Note this endpoint uses the OTHER RSVP token: the per-row opaque
 * calendar_event_attendees.rsvp_token (a random hex string minted when the invite
 * is created), not the signed lib/RsvpToken.php payload that api/event-rsvp.php
 * carries in its emailed links. Two token systems for the same table; both are
 * live. This one is deliberately unauthenticated — the token IS the credential —
 * while `action=status` was auth-gated on 2026-09-02 (lib/event_standing.php).
 *
 * Shipped with no phpunit coverage; the only exercise was test-rsvp-parser.php at
 * the repo root, which writes rows into production Neon and mails a real invite.
 * Table shape mirrors tests/fixtures/production-schema.json.
 */
class RsvpRespondTest extends TestCase
{
    private PDO $pdo;
    private string $errorLog = '';

    protected function setUp(): void
    {
        $this->errorLog = (string) ini_get('error_log');
        ini_set('error_log', '/dev/null');

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("
            CREATE TABLE calendar_events (
                id INTEGER PRIMARY KEY, club_id INTEGER, name TEXT, type TEXT,
                event_date TEXT, start_time TEXT, end_time TEXT, location TEXT, status TEXT
            )
        ");
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
        $this->pdo->exec("
            INSERT INTO calendar_events (id, club_id, name, type, event_date, start_time, end_time, location, status)
            VALUES (4021, 51, 'Team Practice - Soccer U12', 'practice', '2026-09-10', '15:00:00', '17:00:00', 'Main Field', 'scheduled'),
                   (5099, 51, 'Jamboree', 'game', '2026-09-17', '09:00:00', '12:00:00', 'Sports Complex', 'scheduled')
        ");
        // Same guardian, invited to both events: one token per invitation.
        $this->attendee(1, 4021, 'tok-practice');
        $this->attendee(2, 5099, 'tok-jamboree');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->errorLog);
    }

    private function attendee(int $id, int $eventId, string $token): void
    {
        $this->pdo->prepare(
            "INSERT INTO calendar_event_attendees
             (id, event_id, user_id, email, rsvp_status, rsvp_token, responded_at, created_at, athlete_id)
             VALUES (?, ?, 16, 'maggie@4msquared.com', 'pending', ?, NULL, '2026-09-01 10:00:00', NULL)"
        )->execute([$id, $eventId, $token]);
    }

    private function row(int $id): array
    {
        $s = $this->pdo->prepare("SELECT * FROM calendar_event_attendees WHERE id = ?");
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC);
    }

    // Mutation: drop `responded_at = CURRENT_TIMESTAMP` from the UPDATE — the answer is stored with no time.
    public function testAcceptingSetsTheStatusAndStampsRespondedAt(): void
    {
        $result = handleRSVPResponse($this->pdo, 'tok-practice', 'accepted');

        $this->assertTrue($result['success']);
        $this->assertSame('accepted', $result['rsvp_status']);
        $this->assertSame('Team Practice - Soccer U12', $result['event']['name']);
        $this->assertSame('2026-09-10', $result['event']['date']);

        $row = $this->row(1);
        $this->assertSame('accepted', $row['rsvp_status']);
        $this->assertNotNull($row['responded_at']);
    }

    // Mutation: hardcode 'accepted' as the bound status — decline and maybe both read as attending.
    public function testDecliningAndTentativeAreStoredAsGiven(): void
    {
        $this->assertTrue(handleRSVPResponse($this->pdo, 'tok-practice', 'declined')['success']);
        $this->assertSame('declined', $this->row(1)['rsvp_status']);

        $this->assertTrue(handleRSVPResponse($this->pdo, 'tok-jamboree', 'tentative')['success']);
        $this->assertSame('tentative', $this->row(2)['rsvp_status']);
    }

    // Mutation: replace the UPDATE with an INSERT of a new row — the second answer duplicates instead of replacing.
    public function testAnsweringTwiceWithTheSameTokenUpdatesRatherThanDuplicating(): void
    {
        handleRSVPResponse($this->pdo, 'tok-practice', 'accepted');
        $first = $this->row(1)['responded_at'];

        $second = handleRSVPResponse($this->pdo, 'tok-practice', 'declined');

        $this->assertTrue($second['success']);
        $this->assertSame('declined', $second['rsvp_status']);
        $this->assertSame(
            1,
            (int) $this->pdo->query("SELECT COUNT(*) FROM calendar_event_attendees WHERE rsvp_token = 'tok-practice'")->fetchColumn(),
            'a change of mind must not create a second attendee row'
        );
        $this->assertSame('declined', $this->row(1)['rsvp_status']);
        $this->assertNotNull($first);
        // Unlike the email-REPLY path, this endpoint has no `rsvp_status = pending`
        // filter, so re-answering works. Same table, two behaviours — see
        // CalendarReplyParserTest::testASecondReplyCannotChangeTheAnswer_KNOWN_DEFECT.
    }

    // Mutation: delete the `if (!$attendee)` branch — the follow-on query dereferences null and 500s.
    public function testAnUnknownTokenGetsTheVagueRefusalAndTouchesNothing(): void
    {
        $result = handleRSVPResponse($this->pdo, 'not-a-real-token', 'accepted');

        $this->assertArrayNotHasKey('success', $result);
        $this->assertSame('Invalid or expired RSVP token', $result['error']);
        // The refusal must not confirm what exists, and must not be the 500 path —
        // 'Failed to record RSVP' would mean an exception escaped the lookup.
        $this->assertStringNotContainsString('Failed', $result['error']);
        $this->assertSame(
            0,
            (int) $this->pdo->query("SELECT COUNT(*) FROM calendar_event_attendees WHERE responded_at IS NOT NULL")->fetchColumn()
        );
    }

    // Mutation: delete the $validResponses check — 'yes' is written straight into rsvp_status.
    public function testTheEmailLinkVocabularyIsRejectedHere(): void
    {
        // api/event-rsvp.php accepts r=yes|no|maybe and maps them; this endpoint
        // does not, and must refuse rather than store 'yes' as a status.
        foreach (['yes', 'no', 'maybe', 'ACCEPTED', 'attending'] as $bad) {
            $result = handleRSVPResponse($this->pdo, 'tok-practice', $bad);
            $this->assertSame('Invalid response type', $result['error'], "accepted: '$bad'");
        }
        $this->assertSame('pending', $this->row(1)['rsvp_status']);
    }

    // Mutation: delete the empty() guard — an empty token reaches the query and matches nothing silently.
    public function testAMissingTokenOrResponseIsRefusedBeforeAnyQuery(): void
    {
        $this->assertSame('Token and response are required', handleRSVPResponse($this->pdo, '', 'accepted')['error']);
        $this->assertSame('Token and response are required', handleRSVPResponse($this->pdo, 'tok-practice', '')['error']);
        $this->assertSame('pending', $this->row(1)['rsvp_status']);
    }

    // Mutation: take the event from a request parameter instead of the attendee row — one token then opens both events.
    public function testATokenAnswersOnlyItsOwnEventAndInvitation(): void
    {
        handleRSVPResponse($this->pdo, 'tok-practice', 'accepted');

        // The event is read off the attendee row the token resolved to, never supplied
        // by the caller, so the practice token cannot touch the jamboree invitation.
        $this->assertSame('accepted', $this->row(1)['rsvp_status']);
        $this->assertSame('pending', $this->row(2)['rsvp_status']);
        $this->assertNull($this->row(2)['responded_at']);
    }
}
