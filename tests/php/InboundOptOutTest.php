<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/inbound_sms.php';

/**
 * M2 of docs/sms-inbox-scope.md — record STOP when it arrives.
 *
 * The gap this closes was observed, not theorised. On 2026-07-30 a Central Kansas
 * guardian texted `Stop` and then `Start` fourteen seconds later. Twilio blocked
 * and unblocked at the carrier; `email_suppressions` and `guardians.sms_opt_out`
 * both stayed empty, because the only sync was reactive —
 * SmsSendService::handleStatusCallback on error 21610, which fires only AFTER a
 * later send has already failed.
 *
 * The consequence was not merely untidy: between a STOP and the next send, the
 * broadcast preview counted that family as reachable, and when the send finally
 * failed it read as "failed" rather than "opted out".
 */
class InboundOptOutTest extends TestCase
{
    private PDO $pdo;

    private const KANSAS = 51;
    private const OTHER  = 32;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->sqliteCreateFunction('right', fn($s, $n) => substr((string) $s, -$n));
        $this->pdo->sqliteCreateFunction('regexp_replace', fn($s, $p, $r, $f = '') =>
            preg_replace('/' . $p . '/', $r, (string) $s));

        $this->pdo->exec("
            CREATE TABLE sms_phone_numbers (id INTEGER PRIMARY KEY, club_profile_id INTEGER NOT NULL,
                user_id INTEGER, phone_number TEXT, messaging_service_sid TEXT,
                is_active INTEGER NOT NULL DEFAULT 1, inbox_enabled INTEGER NOT NULL DEFAULT 0);
            CREATE TABLE guardians (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT,
                email TEXT, mobile_phone TEXT, sms_opt_out INTEGER DEFAULT 0, sms_opt_out_at TEXT);
            CREATE TABLE athletes (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT,
                phone TEXT, club_id INTEGER, deleted_at TEXT);
            CREATE TABLE athlete_guardians (id INTEGER PRIMARY KEY, athlete_id INTEGER, guardian_id INTEGER);
            CREATE TABLE users (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, phone TEXT);
            CREATE TABLE user_club_access (id INTEGER PRIMARY KEY, user_id INTEGER,
                club_profile_id INTEGER, role TEXT, active INTEGER);
            CREATE TABLE email_suppressions (id INTEGER PRIMARY KEY, club_profile_id INTEGER NOT NULL,
                email TEXT, phone TEXT, channel TEXT NOT NULL, reason TEXT NOT NULL,
                scope TEXT, team_id INTEGER, communication_log_id INTEGER, created_at TEXT);
            CREATE TABLE communication_log (
                id INTEGER PRIMARY KEY, club_profile_id INTEGER NOT NULL, user_id INTEGER,
                channel TEXT NOT NULL, direction TEXT NOT NULL DEFAULT 'outbound',
                recipient_type TEXT, recipient_id INTEGER, recipient_phone TEXT,
                recipient_name TEXT, athlete_id INTEGER, body TEXT, status TEXT,
                from_number TEXT, conversation_id TEXT, twilio_message_sid TEXT,
                sent_at TEXT, delivered_at TEXT, read_at TEXT, created_at TEXT
            );
        ");
        // Mirrors migration 065 — without it a repeated STOP piles up rows.
        $this->pdo->exec("
            CREATE UNIQUE INDEX email_suppressions_sms_unique
                ON email_suppressions (club_profile_id, phone, scope, COALESCE(team_id, 0))
                WHERE channel = 'sms' AND phone IS NOT NULL;
        ");

        $p = $this->pdo;
        $p->exec("INSERT INTO sms_phone_numbers (id, club_profile_id, phone_number) VALUES
            (1, 51, '+17854654221'), (2, 32, '+13605164604')");
        $p->exec("INSERT INTO athletes (id, first_name, last_name, club_id, deleted_at) VALUES
            (1,'Kid','One',51,NULL), (9,'Kid','Two',32,NULL)");
        // Sarina is the guardian who actually did this on 2026-07-30.
        $p->exec("INSERT INTO guardians (id, first_name, last_name, email, mobile_phone, sms_opt_out) VALUES
            (1,'Sarina','Patrick','sarina@example.com','(619) 534-5754',0),
            (9,'Two','Clubs','both@example.com','(619) 534-5754',0)");
        $p->exec("INSERT INTO athlete_guardians (id, athlete_id, guardian_id) VALUES (1,1,1),(9,9,9)");
    }

    private function inbound(string $body, string $from = '+16195345754', string $to = '+17854654221'): ?int
    {
        return te_record_inbound_sms($this->pdo, [
            'From' => $from, 'To' => $to, 'Body' => $body, 'MessageSid' => 'SM' . md5($body . $from),
        ]);
    }

    private function suppressions(int $club = self::KANSAS): int
    {
        $s = $this->pdo->prepare("SELECT count(*) FROM email_suppressions WHERE club_profile_id=? AND channel='sms'");
        $s->execute([$club]);
        return (int) $s->fetchColumn();
    }

    private function optedOut(int $guardianId): bool
    {
        $s = $this->pdo->prepare('SELECT sms_opt_out FROM guardians WHERE id = ?');
        $s->execute([$guardianId]);
        return (bool) $s->fetchColumn();
    }

    // ── M2.1 ─────────────────────────────────────────────────────────────────
    public function testStopIsRecordedImmediately(): void
    {
        $this->inbound('Stop');

        $this->assertSame(1, $this->suppressions(), 'suppression written at arrival');
        $this->assertTrue($this->optedOut(1), 'and the person flagged');
    }

    public function testEveryOptOutKeywordCounts(): void
    {
        foreach (['STOP', 'stopall', 'Unsubscribe', 'CANCEL', 'end', 'Quit.'] as $kw) {
            $this->pdo->exec("DELETE FROM email_suppressions");
            $this->pdo->exec("UPDATE guardians SET sms_opt_out = 0");

            $this->inbound($kw);
            $this->assertSame(1, $this->suppressions(), "'{$kw}' must opt them out");
        }
    }

    public function testTheSuppressionPointsAtTheMessageThatCausedIt(): void
    {
        // "why is this family suppressed?" should be answerable.
        $logId = $this->inbound('STOP');

        $s = $this->pdo->query("SELECT communication_log_id, reason FROM email_suppressions LIMIT 1")
                       ->fetch(PDO::FETCH_ASSOC);
        $this->assertSame($logId, (int) $s['communication_log_id']);
        $this->assertSame('twilio_stop', $s['reason']);
    }

    // ── M2.2 ─────────────────────────────────────────────────────────────────
    public function testStartAfterStopClearsBoth(): void
    {
        // Exactly what Sarina Patrick did, fourteen seconds apart.
        $this->inbound('Stop');
        $this->assertSame(1, $this->suppressions());

        $this->inbound('Start');
        $this->assertSame(0, $this->suppressions(), 'suppression removed');
        $this->assertFalse($this->optedOut(1), 'and the flag cleared');
    }

    public function testEveryOptInKeywordCounts(): void
    {
        foreach (['START', 'yes', 'UnStop'] as $kw) {
            $this->inbound('STOP');
            $this->inbound($kw);
            $this->assertSame(0, $this->suppressions(), "'{$kw}' must opt them back in");
        }
    }

    /**
     * A parent texting START must not resurrect an address the club deliberately
     * stopped using, or one a mail provider hard-bounced.
     */
    public function testStartOnlyClearsATwilioStopNotOtherSuppressions(): void
    {
        $this->pdo->exec("INSERT INTO email_suppressions (club_profile_id, phone, channel, reason, scope)
                          VALUES (51,'+16195345754','sms','manual','club')");
        $this->inbound('Start');

        $this->assertSame(1, $this->suppressions(), 'the manual suppression survives');
    }

    // ── M2.3 / M2.4 ──────────────────────────────────────────────────────────
    public function testHelpIsNeitherOptOutNorOptIn(): void
    {
        $this->inbound('HELP');

        $this->assertSame(0, $this->suppressions());
        $this->assertFalse($this->optedOut(1));
    }

    public function testAKeywordInsideASentenceIsAnOrdinaryMessage(): void
    {
        // "Can we stop by the field at 6?" must not mute a family.
        $this->inbound('Can we stop by the field at 6?');

        $this->assertSame(0, $this->suppressions());
        $this->assertFalse($this->optedOut(1));
        $this->assertNull(te_sms_keyword_intent('Can we stop by the field at 6?'));
    }

    public function testIntentClassification(): void
    {
        $this->assertSame('opt_out', te_sms_keyword_intent('STOP'));
        $this->assertSame('opt_out', te_sms_keyword_intent(' stop. '));
        $this->assertSame('opt_in',  te_sms_keyword_intent('Start'));
        $this->assertSame('help',    te_sms_keyword_intent('HELP'));
        $this->assertNull(te_sms_keyword_intent('stopping by later'));
        $this->assertNull(te_sms_keyword_intent(''));
    }

    // ── Idempotency (migration 065) ──────────────────────────────────────────
    public function testStoppingTwiceLeavesOneSuppression(): void
    {
        $this->inbound('STOP');
        $this->inbound('stop');

        $this->assertSame(1, $this->suppressions(), 'the unique index makes this idempotent');
    }

    // ── Club scoping ─────────────────────────────────────────────────────────
    /**
     * Now that each club sends from its own number, a STOP to one club's number is
     * a STOP to THAT club. The suppression row is club-scoped accordingly.
     */
    public function testTheSuppressionIsScopedToTheClubThatWasTexted(): void
    {
        $this->inbound('STOP', '+16195345754', '+17854654221');   // Kansas number

        $this->assertSame(1, $this->suppressions(self::KANSAS));
        $this->assertSame(0, $this->suppressions(self::OTHER), 'club 32 was not texted');
    }

    // ── M2.5 — the preview agrees straight away ──────────────────────────────
    /**
     * The point of the whole milestone: no send has to fail first.
     */
    public function testThePersonIsExcludedFromTheNextSendWithoutOneFailingFirst(): void
    {
        $recipient = ['type' => 'guardian', 'id' => 1, 'phone' => '(619) 534-5754'];

        $this->assertNull(
            te_sms_skip_reason($recipient, te_sms_suppression_map($this->pdo, self::KANSAS),
                te_sms_opted_out_guardian_ids($this->pdo)),
            'reachable before the STOP'
        );

        $this->inbound('STOP');

        $skip = te_sms_skip_reason($recipient, te_sms_suppression_map($this->pdo, self::KANSAS),
            te_sms_opted_out_guardian_ids($this->pdo));
        $this->assertNotNull($skip, 'excluded immediately after');
        $this->assertContains($skip['reason'], ['suppressed', 'opted_out']);
    }

    // ── Unknown sender ───────────────────────────────────────────────────────
    public function testAStopFromAnUnrecognisedNumberStillSuppresses(): void
    {
        // We do not know who they are, but we know not to text that number.
        $this->inbound('STOP', '+15559990000');

        $this->assertSame(1, $this->suppressions());
    }
}
