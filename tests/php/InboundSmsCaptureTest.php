<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/inbound_sms.php';

/**
 * M1 of docs/sms-inbox-scope.md — recording an inbound reply.
 *
 * The routing rules live in lib/inbound_sms.php rather than the webhook so they
 * are testable without a request. What matters here is not that a row is written,
 * but that it is attributed to the right club and the right person — a reply
 * routed to the wrong club is one family's private message shown to another.
 *
 * Fixture mirrors tests/fixtures/production-schema.json, including the constraint
 * that caused a real defect during this build: communication_log.user_id is an FK
 * to users, so an inbound row cannot invent a sender.
 */
class InboundSmsCaptureTest extends TestCase
{
    private PDO $pdo;

    private const KANSAS = 51;
    private const OTHER  = 32;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // SQLite has no regexp_replace; the queries use it to compare digits.
        // Postgres builtins SQLite lacks; the queries under test use both.
        $this->pdo->sqliteCreateFunction('right', fn($str, $n) => substr((string) $str, -$n));
        $this->pdo->sqliteCreateFunction('regexp_replace', function ($subject, $pattern, $replace, $flags = '') {
            return preg_replace('/' . $pattern . '/', $replace, (string) $subject);
        });

        $this->pdo->exec("
            CREATE TABLE sms_phone_numbers (id INTEGER PRIMARY KEY, club_profile_id INTEGER NOT NULL,
                user_id INTEGER, phone_number TEXT, messaging_service_sid TEXT,
                is_active INTEGER NOT NULL DEFAULT 1, inbox_enabled INTEGER NOT NULL DEFAULT 0);
            CREATE TABLE guardians (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT,
                email TEXT, mobile_phone TEXT);
            CREATE TABLE athletes (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT,
                phone TEXT, club_id INTEGER, deleted_at TEXT);
            CREATE TABLE athlete_guardians (id INTEGER PRIMARY KEY, athlete_id INTEGER, guardian_id INTEGER);
            CREATE TABLE users (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, phone TEXT);
            CREATE TABLE user_club_access (id INTEGER PRIMARY KEY, user_id INTEGER,
                club_profile_id INTEGER, role TEXT, active INTEGER);
            CREATE TABLE communication_log (
                id INTEGER PRIMARY KEY, club_profile_id INTEGER NOT NULL, user_id INTEGER,
                channel TEXT NOT NULL, direction TEXT NOT NULL DEFAULT 'outbound',
                recipient_type TEXT, recipient_id INTEGER, recipient_phone TEXT,
                recipient_name TEXT, athlete_id INTEGER, body TEXT, status TEXT,
                from_number TEXT, conversation_id TEXT, twilio_message_sid TEXT,
                sent_at TEXT, delivered_at TEXT, read_at TEXT, created_at TEXT
            );
        ");

        $p = $this->pdo;
        $p->exec("INSERT INTO sms_phone_numbers (id, club_profile_id, phone_number, is_active) VALUES
            (1, 51, '+17854654221', 1),
            (2, 32, '+13605164604', 1),
            (3, 51, '+17850000000', 0)");   // a number club 51 has since replaced

        $p->exec("INSERT INTO athletes (id, first_name, last_name, phone, club_id, deleted_at) VALUES
            (1,'Jayce','Darrington',NULL,51,NULL),
            (2,'Teen','Player','7855551234',51,NULL),
            (9,'Other','Kid',NULL,32,NULL)");

        // 1 = ordinary crew. 2 and 3 share a household mobile. 9 is another club.
        $p->exec("INSERT INTO guardians (id, first_name, last_name, email, mobile_phone) VALUES
            (1,'Cathy','Rice','crice70@yahoo.com','(916) 517-0661'),
            (2,'John','House','house@example.com','7855550100'),
            (3,'Jane','House','house@example.com','7855550100'),
            (9,'Other','Parent','other@example.com','3605551111')");
        $p->exec("INSERT INTO athlete_guardians (id, athlete_id, guardian_id) VALUES
            (1,1,1),(2,1,2),(3,1,3),(9,9,9)");

        $p->exec("INSERT INTO users (id, first_name, last_name, phone) VALUES
            (10,'Coach','Lee','7855559999')");
        $p->exec("INSERT INTO user_club_access (id,user_id,club_profile_id,role,active) VALUES (1,10,51,'coach',1)");
    }

    private function record(string $from, string $to, string $body = 'hello'): ?int
    {
        return te_record_inbound_sms($this->pdo, [
            'From' => $from, 'To' => $to, 'Body' => $body, 'MessageSid' => 'SM' . md5($from . $body),
        ]);
    }

    private function row(int $id): array
    {
        $s = $this->pdo->prepare('SELECT * FROM communication_log WHERE id = ?');
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC);
    }

    // ── M1.1 / M1.6 — club routing ───────────────────────────────────────────
    public function testRoutesToTheClubThatOwnsTheNumber(): void
    {
        $row = $this->row($this->record('+19165170661', '+17854654221'));

        $this->assertSame(51, (int) $row['club_profile_id']);
        $this->assertSame('inbound', $row['direction']);
    }

    public function testTheSamePersonTextingTwoClubsGetsTwoThreads(): void
    {
        // Kansas and club 32 must never share a conversation.
        $a = $this->row($this->record('+13605551111', '+17854654221'));
        $b = $this->row($this->record('+13605551111', '+13605164604'));

        $this->assertNotSame($a['conversation_id'], $b['conversation_id']);
        $this->assertNotSame($a['club_profile_id'], $b['club_profile_id']);
    }

    public function testAReplyToARetiredNumberStillReachesItsClub(): void
    {
        // A club replaced its number; a reply to the old one is still their message.
        $row = $this->row($this->record('+19165170661', '+17850000000'));

        $this->assertSame(51, (int) $row['club_profile_id']);
    }

    // ── M1.2 — unowned number ────────────────────────────────────────────────
    public function testAReplyToANumberNoClubOwnsIsNotRecorded(): void
    {
        // club_profile_id is NOT NULL, so there is nothing to attach it to.
        // The requirement is that it fails quietly rather than crashing the webhook.
        $this->assertNull($this->record('+19165170661', '+15005550000'));
    }

    // ── M1.3 — normalization ─────────────────────────────────────────────────
    public function testMatchesAStoredNumberInAnyFormat(): void
    {
        // Twilio always sends E.164; the stored value is hand-typed "(916) 517-0661".
        $row = $this->row($this->record('+19165170661', '+17854654221'));

        $this->assertSame('guardian', $row['recipient_type']);
        $this->assertSame(1, (int) $row['recipient_id']);
        $this->assertSame('Cathy Rice', $row['recipient_name']);
    }

    // ── M1.4 — unknown sender ────────────────────────────────────────────────
    /**
     * The one unacceptable outcome is dropping it. A reply from a number we do not
     * recognise is still a person saying something.
     */
    public function testAnUnknownSenderIsStillRecorded(): void
    {
        $id = $this->record('+15559998888', '+17854654221', 'who is this');
        $this->assertNotNull($id);

        $row = $this->row($id);
        $this->assertNull($row['recipient_id']);
        $this->assertSame('user', $row['recipient_type']);
        $this->assertSame('who is this', $row['body']);
    }

    // ── Ambiguity ────────────────────────────────────────────────────────────
    public function testASharedHouseholdMobileAttributesToOneAndIsFlagged(): void
    {
        $sender = te_resolve_inbound_sender($this->pdo, self::KANSAS, '+17855550100');

        $this->assertSame('guardian', $sender['type']);
        $this->assertSame(2, $sender['id'], 'lowest id wins, deterministically');
        $this->assertTrue($sender['ambiguous'], 'two guardians share this number');
    }

    public function testAnUnambiguousSenderIsNotFlagged(): void
    {
        $this->assertFalse(te_resolve_inbound_sender($this->pdo, self::KANSAS, '+19165170661')['ambiguous']);
    }

    // ── Sender types ─────────────────────────────────────────────────────────
    public function testAnAthleteAndACoachAreRecognised(): void
    {
        $this->assertSame('athlete', te_resolve_inbound_sender($this->pdo, self::KANSAS, '+17855551234')['type']);
        $this->assertSame('coach',   te_resolve_inbound_sender($this->pdo, self::KANSAS, '+17855559999')['type']);
    }

    public function testAGuardianOfAnotherClubIsNotMatched(): void
    {
        // Same number, wrong club — must not attribute across clubs.
        $this->assertNull(te_resolve_inbound_sender($this->pdo, self::KANSAS, '+13605551111')['id']);
    }

    // ── M1.5 — threading ─────────────────────────────────────────────────────
    public function testTwoMessagesFromTheSamePersonShareAThread(): void
    {
        $a = $this->row($this->record('+19165170661', '+17854654221', 'first'));
        $b = $this->row($this->record('(916) 517-0661', '+17854654221', 'second'));

        $this->assertSame($a['conversation_id'], $b['conversation_id']);
        $this->assertNotNull($a['conversation_id']);
    }

    public function testConversationIdIsStableAcrossPhoneFormatting(): void
    {
        $this->assertSame(
            te_sms_conversation_id(51, '+19165170661'),
            te_sms_conversation_id(51, '(916) 517-0661')
        );
        $this->assertNotSame(
            te_sms_conversation_id(51, '+19165170661'),
            te_sms_conversation_id(32, '+19165170661'),
            'club is part of the key'
        );
    }

    // ── The sender field ─────────────────────────────────────────────────────
    /**
     * user_id means "the staff member who sent this". An inbound reply has none,
     * and the column is an FK to users — writing 0 threw a constraint violation
     * when this was rehearsed against Neon. NULL is the honest value.
     */
    public function testInboundHasNoSendingStaffMember(): void
    {
        $row = $this->row($this->record('+19165170661', '+17854654221'));

        $this->assertNull($row['user_id']);
    }

    public function testInboundIsRecordedAsDelivered(): void
    {
        // status has no 'received' value, and the message did arrive. Analytics
        // filters direction='outbound' so this never counts as a send.
        $row = $this->row($this->record('+19165170661', '+17854654221'));

        $this->assertSame('delivered', $row['status']);
        $this->assertSame('inbound', $row['direction']);
    }

    public function testTheClubsOwnNumberIsRecordedAsTheDestination(): void
    {
        $row = $this->row($this->record('+19165170661', '+17854654221'));

        $this->assertSame('+17854654221', $row['from_number']);
        $this->assertSame('+19165170661', $row['recipient_phone'], 'stored normalized');
    }
}
