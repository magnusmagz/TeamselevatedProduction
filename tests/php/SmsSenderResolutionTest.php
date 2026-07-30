<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/sms_sender.php';

/**
 * Which number a club sends from — and the refusal when it has none.
 *
 * The refusal is the point of this feature, not a rough edge of it. Every club used
 * to send from one platform-wide TWILIO_FROM_NUMBER, and carrier STOP blocks the
 * (from-number, recipient) PAIR — so one parent replying STOP to one club went
 * unreachable for every club on the platform, at the carrier, whatever our
 * club-scoped suppression rows said. A fallback to the shared number would quietly
 * rebuild that for any club nobody configured. There must not be one.
 */
class SmsSenderResolutionTest extends TestCase
{
    private PDO $pdo;

    private const CLUB = 32;
    private const OTHER_CLUB = 51;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Mirrors migration 057 as APPLIED to Neon, CHECK constraint included.
        // Without it this fixture accepted a row with neither a number nor a
        // Messaging Service — a shape production rejects — which is how a test can
        // stay green against a schema that does not exist. See the MergeFieldService
        // `events` table in CLAUDE.md for where that road ends.
        $this->pdo->exec("
            CREATE TABLE sms_phone_numbers (
                id INTEGER PRIMARY KEY,
                club_profile_id INTEGER NOT NULL,
                user_id INTEGER,
                phone_number TEXT,
                twilio_phone_sid TEXT,
                messaging_service_sid TEXT,
                is_active INTEGER NOT NULL DEFAULT 1,
                provisioned_at TEXT,
                created_at TEXT,
                updated_at TEXT,
                CONSTRAINT sms_phone_numbers_has_a_sender
                    CHECK (phone_number IS NOT NULL OR messaging_service_sid IS NOT NULL)
            );
        ");
    }

    private function addNumber(array $overrides = []): void
    {
        $row = array_merge([
            'club_profile_id'       => self::CLUB,
            'user_id'               => null,
            'phone_number'          => '+13605550199',
            'messaging_service_sid' => null,
            'is_active'             => 1,
        ], $overrides);

        $stmt = $this->pdo->prepare("
            INSERT INTO sms_phone_numbers
                (club_profile_id, user_id, phone_number, messaging_service_sid, is_active)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $row['club_profile_id'],
            $row['user_id'],
            $row['phone_number'],
            $row['messaging_service_sid'],
            $row['is_active'],
        ]);
    }

    // ── The refusal ───────────────────────────────────────────────────────────
    public function testUnconfiguredClubResolvesToNullNotAFallback(): void
    {
        $this->assertNull(te_resolve_sms_sender($this->pdo, self::CLUB));
    }

    public function testAnotherClubsNumberIsNeverBorrowed(): void
    {
        $this->addNumber(['club_profile_id' => self::OTHER_CLUB]);

        $this->assertNull(
            te_resolve_sms_sender($this->pdo, self::CLUB),
            'club 32 must not send as club 51 — that is the shared-sender bug in miniature'
        );
    }

    public function testDeactivatedNumberDoesNotResolve(): void
    {
        $this->addNumber(['is_active' => 0]);

        $this->assertNull(te_resolve_sms_sender($this->pdo, self::CLUB));
    }

    /**
     * A row with neither a number nor a Messaging Service resolves to nothing, so
     * the club would appear configured in the UI while silently unable to send.
     * The database refuses to store that state at all — this pins the constraint,
     * because te_resolve_sms_sender's own null-guard would otherwise hide its
     * absence.
     */
    public function testDatabaseRejectsARowThatIsNotASender(): void
    {
        $this->expectException(PDOException::class);

        $this->addNumber(['phone_number' => null, 'messaging_service_sid' => null]);
    }

    public function testAMessagingServiceAloneIsAValidSender(): void
    {
        // A Messaging Service holds its own pool of numbers, so a club configured
        // this way has no single bare number — phone_number stays null and that is
        // correct, not incomplete.
        $this->addNumber(['phone_number' => null, 'messaging_service_sid' => 'MG0123456789abcdef']);

        $sender = te_resolve_sms_sender($this->pdo, self::CLUB);

        $this->assertSame('MG0123456789abcdef', $sender['from']);
        $this->assertNull($sender['phone_number']);
    }

    public function testMissingSenderMessageNamesWhereToFixIt(): void
    {
        // An error that does not say what to do produces a support ticket.
        $msg = te_sms_sender_missing_message();

        $this->assertStringContainsString('no SMS number configured', $msg);
        $this->assertStringContainsString('Club Profile', $msg);
    }

    // ── Resolution ────────────────────────────────────────────────────────────
    public function testConfiguredClubResolvesToItsNumber(): void
    {
        $this->addNumber();

        $sender = te_resolve_sms_sender($this->pdo, self::CLUB);

        $this->assertSame('+13605550199', $sender['from']);
        $this->assertSame('+13605550199', $sender['phone_number']);
        $this->assertNull($sender['messaging_service_sid']);
    }

    public function testMessagingServiceWinsOverTheBareNumber(): void
    {
        // A2P 10DLC campaigns register against the Messaging Service, not the long
        // code, so once one exists it must be what Twilio is told.
        $this->addNumber(['messaging_service_sid' => 'MG0123456789abcdef']);

        $sender = te_resolve_sms_sender($this->pdo, self::CLUB);

        $this->assertSame('MG0123456789abcdef', $sender['from']);
        $this->assertSame('MG0123456789abcdef', $sender['messaging_service_sid']);
        // The number is still reported, because communication_log records it.
        $this->assertSame('+13605550199', $sender['phone_number']);
    }

    public function testEachClubResolvesToItsOwnNumber(): void
    {
        $this->addNumber(['club_profile_id' => self::CLUB,       'phone_number' => '+13605550199']);
        $this->addNumber(['club_profile_id' => self::OTHER_CLUB, 'phone_number' => '+13165550142']);

        $this->assertSame('+13605550199', te_resolve_sms_sender($this->pdo, self::CLUB)['from']);
        $this->assertSame('+13165550142', te_resolve_sms_sender($this->pdo, self::OTHER_CLUB)['from']);
    }

    public function testReplacingANumberKeepsHistoryAndUsesTheLiveOne(): void
    {
        // The API deactivates rather than deletes, so a carrier STOP months old can
        // still be traced to the number that was in force at the time.
        $this->addNumber(['phone_number' => '+13605550100', 'is_active' => 0]);
        $this->addNumber(['phone_number' => '+13605550199', 'is_active' => 1]);

        $this->assertSame('+13605550199', te_resolve_sms_sender($this->pdo, self::CLUB)['from']);
        $this->assertSame(
            2,
            (int) $this->pdo->query('SELECT COUNT(*) FROM sms_phone_numbers')->fetchColumn(),
            'the superseded row is retained'
        );
    }

    // ── Forward compatibility with per-coach numbers ──────────────────────────
    /**
     * user_id is unused today. These pin the resolution order now so the
     * per-coach phase (unified-messaging-scope Phase 1) drops in as rows rather
     * than a second table and a second resolver.
     */
    public function testACoachesOwnNumberOutranksTheClubNumber(): void
    {
        $this->addNumber(['user_id' => null, 'phone_number' => '+13605550199']);
        $this->addNumber(['user_id' => 77,   'phone_number' => '+13605550177']);

        $this->assertSame('+13605550177', te_resolve_sms_sender($this->pdo, self::CLUB, 77)['from']);
    }

    public function testACoachWithoutTheirOwnNumberFallsBackToTheClub(): void
    {
        $this->addNumber(['user_id' => null, 'phone_number' => '+13605550199']);
        $this->addNumber(['user_id' => 77,   'phone_number' => '+13605550177']);

        $this->assertSame('+13605550199', te_resolve_sms_sender($this->pdo, self::CLUB, 88)['from']);
    }

    public function testAnotherCoachesNumberIsNeverUsed(): void
    {
        // Only the club row and the requester's own row are eligible.
        $this->addNumber(['user_id' => 77, 'phone_number' => '+13605550177']);

        $this->assertNull(te_resolve_sms_sender($this->pdo, self::CLUB, 88));
    }
}
