<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/suppression.php';

/**
 * Preview and send must agree about who is excluded.
 *
 * Until 2026-07-30 they did not, and both mismatches pushed the same direction —
 * preview promised MORE recipients than the send delivered:
 *
 *   1. handlePreviewBroadcast never looked at guardians.sms_opt_out. queueSms did.
 *   2. handlePreviewBroadcast compared the RAW guardians.mobile_phone value against
 *      email_suppressions.phone, which stores E.164 (SmsSendService writes $to
 *      straight from Twilio). "360-555-0201" never equals "+13605550201", so
 *      preview's suppression check could not match a real STOP at all.
 *
 * Both paths now call te_sms_skip_reason. These tests pin the predicate; the
 * "OldPreviewLogic" cases document what the bug looked like so a well-meaning
 * revert reads as a failure rather than a cleanup.
 */
class BroadcastSuppressionParityTest extends TestCase
{
    private PDO $pdo;

    private const CLUB = 32;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("
            CREATE TABLE athletes (id INTEGER PRIMARY KEY, club_id INTEGER,
                deleted_at TEXT, active_status INTEGER);
            CREATE TABLE guardians (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT,
                mobile_phone TEXT, sms_opt_out INTEGER DEFAULT 0);
            CREATE TABLE athlete_guardians (id INTEGER PRIMARY KEY, athlete_id INTEGER, guardian_id INTEGER);
            CREATE TABLE email_suppressions (id INTEGER PRIMARY KEY, club_profile_id INTEGER,
                email TEXT, phone TEXT, channel TEXT, reason TEXT, scope TEXT, team_id INTEGER);
        ");

        $p = $this->pdo;
        $p->exec("INSERT INTO athletes (id, club_id, deleted_at, active_status) VALUES
            (1, 32, NULL, 1), (9, 51, NULL, 1)");

        // 1 opted out, no suppression row  → the case preview used to miss entirely
        // 2 suppression row, not opted out → the case preview used to miss on format
        // 3 both (a real Twilio STOP)      → must not double-count
        // 4 clean
        // 9 other club
        $p->exec("INSERT INTO guardians (id, first_name, last_name, mobile_phone, sms_opt_out) VALUES
            (1, 'OptedOut',   'Olsen',  '360-555-0201', 1),
            (2, 'Suppressed', 'Sousa',  '360-555-0202', 0),
            (3, 'Stopped',    'Stein',  '360-555-0203', 1),
            (4, 'Clean',      'Clark',  '360-555-0204', 0),
            (9, 'OtherClub',  'Ogden',  '360-555-0209', 1)");

        $p->exec("INSERT INTO athlete_guardians (id, athlete_id, guardian_id) VALUES
            (1, 1, 1), (2, 1, 2), (3, 1, 3), (4, 1, 4), (9, 9, 9)");

        // Suppressions are stored E.164, as SmsSendService::handleStatusCallback writes them.
        $p->exec("INSERT INTO email_suppressions (id, club_profile_id, email, phone, channel, reason, scope, team_id) VALUES
            (1, 32, NULL, '+13605550202', 'sms', 'twilio_stop', 'club', NULL),
            (2, 32, NULL, '+13605550203', 'sms', 'twilio_stop', 'club', NULL)");
    }

    private function guardian(int $id, string $phone): array
    {
        return ['id' => $id, 'type' => 'guardian', 'phone' => $phone, 'name' => "G{$id}"];
    }

    private function skip(array $recipient, array $teamIds = []): ?array
    {
        return te_sms_skip_reason(
            $recipient,
            te_sms_suppression_map($this->pdo, self::CLUB),
            te_sms_opted_out_guardian_ids($this->pdo),
            $teamIds
        );
    }

    /** Faithful reproduction of the pre-fix preview check. */
    private function oldPreviewWouldSuppress(string $rawPhone): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM email_suppressions
             WHERE phone = ? AND club_profile_id = ? AND channel = 'sms'"
        );
        $stmt->execute([$rawPhone, self::CLUB]);
        return (int) $stmt->fetchColumn() > 0;
    }

    // ── B1 ────────────────────────────────────────────────────────────────────
    public function testOptedOutGuardianWithNoSuppressionRowIsSkipped(): void
    {
        $skip = $this->skip($this->guardian(1, '360-555-0201'));

        $this->assertNotNull($skip, 'sms_opt_out alone must exclude');
        $this->assertSame('opted_out', $skip['reason']);

        // What the old preview said about the same person.
        $this->assertFalse(
            $this->oldPreviewWouldSuppress('360-555-0201'),
            'documents the bug: preview counted this person as a recipient'
        );
    }

    // ── B2 ────────────────────────────────────────────────────────────────────
    public function testSuppressionRowMatchesDespiteRawVsE164Formatting(): void
    {
        $skip = $this->skip($this->guardian(2, '360-555-0202'));

        $this->assertNotNull($skip, 'a stored E.164 suppression must match a raw-format recipient');
        $this->assertSame('suppressed', $skip['reason']);

        $this->assertFalse(
            $this->oldPreviewWouldSuppress('360-555-0202'),
            'documents the bug: raw vs E.164 comparison never matched'
        );
    }

    public function testAllEquivalentPhoneFormatsResolveToTheSameDecision(): void
    {
        foreach (['360-555-0202', '(360) 555-0202', '3605550202', '+13605550202', '13605550202'] as $format) {
            $skip = $this->skip($this->guardian(2, $format));
            $this->assertNotNull($skip, "format {$format} must be recognized as suppressed");
        }
    }

    // ── B3 ────────────────────────────────────────────────────────────────────
    public function testTwilioStopSetsBothAndIsCountedOnce(): void
    {
        $skip = $this->skip($this->guardian(3, '360-555-0203'));

        // One reason, not two exclusions — the caller increments a counter per
        // recipient, so returning early is what keeps the count honest.
        $this->assertNotNull($skip);
        $this->assertSame('suppressed', $skip['reason']);
    }

    // ── B4 ────────────────────────────────────────────────────────────────────
    public function testCleanGuardianIsNotSkipped(): void
    {
        $this->assertNull($this->skip($this->guardian(4, '360-555-0204')));
    }

    public function testMissingOrUnusablePhoneIsSkippedAsInvalidNotSuppressed(): void
    {
        $this->assertSame('invalid_phone', $this->skip($this->guardian(4, ''))['reason']);
        $this->assertSame('invalid_phone', $this->skip($this->guardian(4, '555-0204'))['reason']);
        $this->assertSame('invalid_phone', $this->skip($this->guardian(4, 'not a phone'))['reason']);
    }

    /**
     * sms_opt_out is a flag on the PERSON, and the pre-2026-07-30 code read it that
     * way (`WHERE id = ?`, no club filter). Guardian 9 is in another club and must
     * still be in the set: someone who replied STOP has not consented to hear from
     * a second club that happens to share the platform. Club-scoping this lookup
     * would narrow a compliance check — the one direction it must never move.
     */
    public function testOptOutLookupIsGlobalNotClubScoped(): void
    {
        $optedOut = te_sms_opted_out_guardian_ids($this->pdo);

        $this->assertArrayHasKey(1, $optedOut);
        $this->assertArrayHasKey(3, $optedOut);
        $this->assertArrayHasKey(9, $optedOut, 'an opt-out in another club still counts');
        $this->assertArrayNotHasKey(4, $optedOut, 'a guardian who has not opted out is absent');
    }

    public function testNonGuardianRecipientsAreUnaffectedByGuardianOptOut(): void
    {
        // An athlete who happens to share guardian 1's id must not inherit the opt-out.
        $athlete = ['id' => 1, 'type' => 'athlete', 'phone' => '360-555-0301', 'name' => 'A1'];

        $this->assertNull($this->skip($athlete));
    }

    // ── Scope, mirroring te_email_suppressed ──────────────────────────────────
    public function testTeamScopedSuppressionOnlyBlocksThatTeamsSend(): void
    {
        $this->pdo->exec("INSERT INTO email_suppressions
            (id, club_profile_id, email, phone, channel, reason, scope, team_id)
            VALUES (3, 32, NULL, '+13605550204', 'sms', 'unsubscribe', 'team', 7)");

        $clean = $this->guardian(4, '360-555-0204');

        $this->assertNotNull($this->skip($clean, [7]), 'blocked for the team they left');
        $this->assertNull($this->skip($clean, [8]), 'still reachable for a different team');
        $this->assertNull($this->skip($clean, []), 'club-wide send is not blocked by a team opt-out');
    }

    public function testClubWideSuppressionOutranksATeamScopedRowForTheSameNumber(): void
    {
        $this->pdo->exec("INSERT INTO email_suppressions
            (id, club_profile_id, email, phone, channel, reason, scope, team_id)
            VALUES (4, 32, NULL, '+13605550202', 'sms', 'unsubscribe', 'team', 7)");

        // Guardian 2 already has a club-scoped row. Adding a narrower one must not
        // widen their reachability.
        $this->assertNotNull($this->skip($this->guardian(2, '360-555-0202'), [8]));
    }

    // ── Normalization contract ────────────────────────────────────────────────
    public function testNormalizerAgreesWithSmsSendServiceOnValidInput(): void
    {
        $this->assertSame('+13605550202', te_normalize_sms_phone('360-555-0202'));
        $this->assertSame('+13605550202', te_normalize_sms_phone('(360) 555-0202'));
        $this->assertSame('+13605550202', te_normalize_sms_phone('+1 360 555 0202'));
        $this->assertSame('+443605550202', te_normalize_sms_phone('+44 360 555 0202'));

        $this->assertNull(te_normalize_sms_phone(''));
        $this->assertNull(te_normalize_sms_phone(null));
        $this->assertNull(te_normalize_sms_phone('555-0202'), '7 digits is not resolvable');
        $this->assertNull(te_normalize_sms_phone('abc'));
    }
}
