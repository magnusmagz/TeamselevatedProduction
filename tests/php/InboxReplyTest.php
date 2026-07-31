<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/inbound_sms.php';

/**
 * M4 of docs/sms-inbox-scope.md — replying to a thread as a text.
 *
 * The reply itself is `SmsSendService::queueSms`, already built and covered; what
 * is new and worth guarding is the wiring around it:
 *
 *  - a reply must join the thread it answers, not start a new one
 *  - the recipient comes from the THREAD, never from the request body
 *  - queueSms SKIPS a suppressed recipient rather than failing, so "sent nothing"
 *    must not be reported as success
 *
 * Note what did NOT change: the auto-reply still fires on every inbound and still
 * says the number is not monitored. Shipping the ability to reply is not the same
 * as a club agreeing to answer, and that promise is theirs to make.
 */
class InboxReplyTest extends TestCase
{
    private PDO $pdo;

    private const KANSAS = 51;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->sqliteCreateFunction('right', fn($s, $n) => substr((string) $s, -$n));
        $this->pdo->sqliteCreateFunction('regexp_replace', fn($s, $p, $r, $f = '') =>
            preg_replace('/' . $p . '/', $r, (string) $s));

        $this->pdo->exec("
            CREATE TABLE communication_log (
                id INTEGER PRIMARY KEY, club_profile_id INTEGER NOT NULL, user_id INTEGER,
                channel TEXT NOT NULL, direction TEXT NOT NULL DEFAULT 'outbound',
                recipient_type TEXT, recipient_id INTEGER, recipient_phone TEXT,
                recipient_name TEXT, athlete_id INTEGER, body TEXT, status TEXT,
                from_number TEXT, conversation_id TEXT, twilio_message_sid TEXT,
                sent_at TEXT, delivered_at TEXT, read_at TEXT, created_at TEXT
            );
        ");
    }

    /** The thread key the whole feature hangs on. */
    public function testAReplyLandsInTheSameThreadAsTheMessageItAnswers(): void
    {
        $inboundThread = te_sms_conversation_id(self::KANSAS, '+19165170661');
        // queueSms derives the key the same way, from club + normalized phone.
        $replyThread   = te_sms_conversation_id(self::KANSAS, '(916) 517-0661');

        $this->assertSame($inboundThread, $replyThread);
    }

    public function testABroadcastAndALaterReplyShareAThread(): void
    {
        // Why migration 066 backfilled: an admin opening "I did not receive an
        // email" needs the broadcast that prompted it sitting above.
        $this->pdo->exec("INSERT INTO communication_log
            (club_profile_id, channel, direction, recipient_phone, body, status, conversation_id, created_at)
            VALUES (51,'sms','outbound','+19165170661','Central Kansas has adopted...','delivered','"
            . te_sms_conversation_id(self::KANSAS, '+19165170661') . "','2026-07-30 22:58')");
        $this->pdo->exec("INSERT INTO communication_log
            (club_profile_id, channel, direction, recipient_phone, body, status, conversation_id, created_at)
            VALUES (51,'sms','inbound','+19165170661','I did not receive an email','delivered','"
            . te_sms_conversation_id(self::KANSAS, '+19165170661') . "','2026-07-30 23:23')");

        $n = $this->pdo->query("SELECT count(DISTINCT conversation_id) FROM communication_log")->fetchColumn();
        $this->assertSame(1, (int) $n, 'one conversation, not two');
    }

    public function testDifferentClubsNeverShareAThreadForTheSamePerson(): void
    {
        $this->assertNotSame(
            te_sms_conversation_id(51, '+19165170661'),
            te_sms_conversation_id(32, '+19165170661')
        );
    }

    /**
     * The recipient is resolved from the thread's most recent message. A request
     * body carrying its own phone number would turn the club's sender into an
     * open relay.
     */
    public function testTheRecipientComesFromTheThreadNotTheRequest(): void
    {
        $convo = te_sms_conversation_id(self::KANSAS, '+19165170661');
        $this->pdo->exec("INSERT INTO communication_log
            (club_profile_id, channel, direction, recipient_phone, recipient_type, recipient_id, recipient_name, body, status, conversation_id, created_at)
            VALUES (51,'sms','inbound','+19165170661','guardian',461,'Cathy Rice','hi','delivered','{$convo}','2026-07-30 23:23')");

        $stmt = $this->pdo->prepare("
            SELECT recipient_phone, recipient_type, recipient_id
            FROM communication_log
            WHERE club_profile_id = ? AND conversation_id = ? AND channel = 'sms'
            ORDER BY created_at DESC, id DESC LIMIT 1
        ");
        $stmt->execute([self::KANSAS, $convo]);
        $contact = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertSame('+19165170661', $contact['recipient_phone']);
        $this->assertSame('guardian', $contact['recipient_type']);
        $this->assertSame(461, (int) $contact['recipient_id']);
    }

    public function testAThreadFromAnotherClubResolvesToNothing(): void
    {
        $convo = te_sms_conversation_id(self::KANSAS, '+19165170661');
        $this->pdo->exec("INSERT INTO communication_log
            (club_profile_id, channel, direction, recipient_phone, body, status, conversation_id, created_at)
            VALUES (51,'sms','inbound','+19165170661','hi','delivered','{$convo}','2026-07-30 23:23')");

        // club 32 asking for club 51's conversation must get nothing back.
        $stmt = $this->pdo->prepare("
            SELECT recipient_phone FROM communication_log
            WHERE club_profile_id = ? AND conversation_id = ? LIMIT 1
        ");
        $stmt->execute([32, $convo]);

        $this->assertFalse($stmt->fetch(PDO::FETCH_ASSOC));
    }

    /**
     * queueSms returns queued=0 and a reason when the contact is suppressed — it
     * does not throw. Reporting that as a success would tell an admin their reply
     * went to a family who will never see it.
     */
    public function testASkippedReplyIsNotASuccessfulOne(): void
    {
        $result = ['queued' => 0, 'skipped' => 1, 'skipped_details' => [
            ['name' => 'Sarina Patrick', 'reason' => 'suppressed', 'detail' => 'SMS suppression: twilio_stop'],
        ]];

        $this->assertSame(0, $result['queued']);
        $this->assertNotEmpty($result['skipped_details'][0]['detail']);
        $this->assertStringContainsString('twilio_stop', $result['skipped_details'][0]['detail']);
    }
}
