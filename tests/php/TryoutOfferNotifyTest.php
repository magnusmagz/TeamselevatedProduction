<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;

require_once __DIR__ . '/../../lib/tryout_offer_notify.php';

/**
 * "Offers sent successfully" — with no send anywhere in the handler.
 *
 * send-offers wrote tryout_offers rows, flipped registrations.tryout_status and
 * told staff the families had been told. Nothing had been. These tests cover the
 * two halves of the fix: who an offer resolves to, and whether the handler
 * actually calls the notifier — in the right place, behind the right switch.
 *
 * The parse assertions matter as much as the behavioural ones. A notify function
 * that is never called, or called before the INSERT, or called when the kill
 * switch is off, reproduces the original bug with more code in it.
 */
class TryoutOfferNotifyTest extends TestCase
{
    private PDO $pdo;

    /** Emails captured instead of sent. Nothing here touches SendGrid. */
    private array $sent = [];

    protected function setUp(): void
    {
        $this->sent = [];

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // Mirrors tests/fixtures/production-schema.json for the columns used here.
        // A fixture that does not mirror the live shape is worse than no fixture —
        // MergeFieldServiceTest stayed green for months against a table that had
        // been renamed out from under it.
        $this->pdo->exec("
            CREATE TABLE club_profile (id INTEGER PRIMARY KEY, name TEXT, email TEXT);
            CREATE TABLE programs (id INTEGER PRIMARY KEY, name TEXT, club_id INTEGER);
            CREATE TABLE teams (id INTEGER PRIMARY KEY, name TEXT, club_id INTEGER);
            CREATE TABLE athletes (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT);
            CREATE TABLE guardians (
                id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT,
                email TEXT, mobile_phone TEXT, sms_opt_out INTEGER DEFAULT 0
            );
            CREATE TABLE athlete_guardians (
                id INTEGER PRIMARY KEY, athlete_id INTEGER, guardian_id INTEGER,
                relationship TEXT, is_primary INTEGER
            );
            CREATE TABLE registrations (
                id INTEGER PRIMARY KEY, program_id INTEGER, athlete_id INTEGER,
                tryout_status TEXT, registrant_first_name TEXT,
                registrant_last_name TEXT, registrant_email TEXT
            );
        ");

        $this->pdo->exec("
            INSERT INTO club_profile (id, name, email)
                VALUES (51, 'Central Kansas United', 'office@cku.example');
            INSERT INTO programs (id, name, club_id)
                VALUES (10, 'Fall 2026 Select', 51);
            INSERT INTO teams (id, name, club_id)
                VALUES (7, 'Thunder U12', 51);

            INSERT INTO athletes (id, first_name, last_name) VALUES (100, 'Maya', 'Rivera');
            INSERT INTO athletes (id, first_name, last_name) VALUES (200, 'Sam', 'Alvarez');

            -- One household, two guardians, ONE address spelled two ways. Six
            -- live addresses are held by two guardians each, and Postgres '=' on
            -- email is case-sensitive, so the dedupe key has to be lowercased.
            INSERT INTO guardians (id, first_name, last_name, email, mobile_phone)
                VALUES (1, 'John', 'Rivera', 'theRiveras@Gmail.com', '620-555-0101');
            INSERT INTO guardians (id, first_name, last_name, email, mobile_phone)
                VALUES (2, 'Jane', 'Rivera', 'THERIVERAS@gmail.com', '620-555-0102');

            -- Jane's link has the LOWER id and JOHN's guardian row the lower id,
            -- on purpose. Crew members are equal (2026-09-02), so the household
            -- is ordered by LINK id and Jane leads — if the ordering ever falls
            -- back to the guardian id, or to a resurrected primary flag (link 2
            -- still carries a stale `is_primary = 1`), John leads and this fails.
            INSERT INTO athlete_guardians (id, athlete_id, guardian_id, relationship, is_primary)
                VALUES (1, 100, 2, 'Parent', 0);
            INSERT INTO athlete_guardians (id, athlete_id, guardian_id, relationship, is_primary)
                VALUES (2, 100, 1, 'Parent', 1);

            -- Registration with guardians: registrant_email must NOT be used.
            INSERT INTO registrations (id, program_id, athlete_id, registrant_first_name, registrant_last_name, registrant_email)
                VALUES (1000, 10, 100, 'John', 'Rivera', 'front-desk@example.com');

            -- Registration with no guardian link at all: the fallback case.
            INSERT INTO registrations (id, program_id, athlete_id, registrant_first_name, registrant_last_name, registrant_email)
                VALUES (2000, 10, 200, 'dana', 'alvarez', 'Dana.Alvarez@example.com');
        ");
    }

    /** A transport that records instead of sending, and can be told to fail. */
    private function recorder(array $failFor = []): callable
    {
        return function (string $to, string $subject, string $html, string $text) use ($failFor): bool {
            $this->sent[] = ['to' => $to, 'subject' => $subject, 'html' => $html, 'text' => $text];
            return !in_array(strtolower($to), array_map('strtolower', $failFor), true);
        };
    }

    /** SMS disabled: this suite must not reach Redis or Twilio. */
    private function noSms(): callable
    {
        return static fn(array $ctx, string $body): ?array => null;
    }

    private function context(int $registrationId, $teamId = null): array
    {
        $ctx = te_tryout_offer_context($this->pdo, $registrationId, $teamId);
        $this->assertNotNull($ctx, "registration $registrationId should resolve");
        return $ctx;
    }

    // ── Recipient resolution ────────────────────────────────────────────────

    public function testTwoGuardiansOnOneAddressReceiveOneEmail(): void
    {
        $recipients = $this->context(1000)['recipients'];

        $this->assertCount(1, $recipients, 'a shared household address is one send, not two');
        $this->assertSame('theriveras@gmail.com', $recipients[0]['key']);
        $this->assertCount(2, $recipients[0]['guardians'],
            'both people stay on the entry — the SMS opt-out check is per guardian id');
    }

    /**
     * NOBODY leads by rank. There is no primary guardian (2026-09-02), so the
     * household is ordered by the athlete_guardians link id: deterministic,
     * independent of physical row order, and carrying no claim about which
     * parent matters more.
     *
     * Link 1 is Jane and link 2 is John, and link 2 still carries a stale
     * `is_primary = 1` from before the rule changed. Jane leading is therefore
     * the assertion that the flag is not being consulted.
     */
    public function testTheHouseholdIsOrderedByLinkIdAndNobodyOutranksAnybody(): void
    {
        $recipients = $this->context(1000)['recipients'];

        $this->assertSame(2, $recipients[0]['guardians'][0]['id'], 'the link id orders the household');
        $this->assertSame('Jane & John', $recipients[0]['name']);
        // The address kept is the first-by-link-id row's stored spelling.
        $this->assertSame('THERIVERAS@gmail.com', $recipients[0]['email']);
        $this->assertArrayNotHasKey('is_primary', $recipients[0],
            'a recipient carries no primary flag — there is nothing for it to mean');
    }

    public function testRegistrantEmailIsAFallbackAndNotAnExtraRecipient(): void
    {
        $recipients = $this->context(1000)['recipients'];

        $addresses = array_column($recipients, 'key');
        $this->assertNotContains('front-desk@example.com', $addresses,
            'registrant_email must not be added alongside a resolved guardian chain');
    }

    public function testAnAthleteWithNoGuardiansFallsBackToTheRegistrant(): void
    {
        $recipients = $this->context(2000)['recipients'];

        $this->assertCount(1, $recipients);
        $this->assertSame('registrant', $recipients[0]['source']);
        $this->assertSame('Dana.Alvarez@example.com', $recipients[0]['email']);
        $this->assertSame('Dana', $recipients[0]['name'], 'the stored lower-case name is title-cased');
    }

    public function testAGuardianWithABlankEmailIsNotAddressable(): void
    {
        // 25 live guardians carry email = '' — an empty STRING, so they compare
        // equal to each other. Matching on it merges unrelated people; mailing it
        // sends nowhere.
        $this->pdo->exec("UPDATE guardians SET email = '' WHERE id = 1");
        $this->pdo->exec("UPDATE guardians SET email = '' WHERE id = 2");

        $recipients = $this->context(1000)['recipients'];

        $this->assertCount(1, $recipients);
        $this->assertSame('registrant', $recipients[0]['source'],
            'a household with no usable address falls through to the registrant');
    }

    // ── Rendering ───────────────────────────────────────────────────────────

    public function testTheTemplateRendersTheRealValuesAndNoMergeTags(): void
    {
        $ctx = $this->context(1000, 7);
        $ctx['offer_type'] = 'roster';

        $mail = te_tryout_offer_render($ctx, $ctx['recipients'][0]);

        foreach (['html', 'text'] as $part) {
            $this->assertStringContainsString('Central Kansas United', $mail[$part], "club name missing from $part");
            $this->assertStringContainsString('Maya Rivera', $mail[$part], "athlete missing from $part");
            $this->assertStringContainsString('Fall 2026 Select', $mail[$part], "program missing from $part");
            $this->assertStringContainsString('Thunder U12', $mail[$part], "team missing from $part");
            $this->assertStringNotContainsString('{{', $mail[$part],
                "an unresolved merge tag in a $part body cannot be unsent");
        }

        $this->assertStringContainsString('Jane &amp; John', $mail['html'], 'both parents are greeted');
        $this->assertStringContainsString('Jane & John', $mail['text']);
        $this->assertStringContainsString('roster spot', $mail['text']);
        $this->assertStringContainsString('/parent', $mail['text'], 'the response instructions point at the portal');
        $this->assertStringContainsString('Maya Rivera', $mail['subject']);
    }

    public function testAnOfferWithNoTeamRendersWithoutOne(): void
    {
        $ctx = $this->context(2000);
        $ctx['offer_type'] = 'waitlist';

        $mail = te_tryout_offer_render($ctx, $ctx['recipients'][0]);

        $this->assertStringNotContainsString('Thunder', $mail['html']);
        $this->assertStringContainsString('waitlist', $mail['text']);
        $this->assertStringNotContainsString('{{', $mail['html']);
    }

    public function testAReplyByDateIsRenderedOnlyWhenTheOfferCarriesOne(): void
    {
        $ctx = $this->context(1000, 7);
        $ctx['offer_type'] = 'roster';

        $without = te_tryout_offer_render($ctx, $ctx['recipients'][0], null);
        $this->assertStringNotContainsString('reply by', strtolower($without['text']));

        $with = te_tryout_offer_render($ctx, $ctx['recipients'][0], '2026-09-15');
        $this->assertStringContainsString('September 15, 2026', $with['text']);

        // An unparseable date is dropped, never printed raw — a wrong deadline in
        // a family's inbox cannot be recalled.
        $garbage = te_tryout_offer_render($ctx, $ctx['recipients'][0], 'whenever');
        $this->assertStringNotContainsString('whenever', $garbage['text']);
    }

    // ── The batch ───────────────────────────────────────────────────────────

    public function testEveryFamilyInTheBatchIsNotifiedOnce(): void
    {
        $result = te_tryout_offer_notify_all($this->pdo, [
            ['registration_id' => 1000, 'offer_type' => 'roster', 'team_id' => 7],
            ['registration_id' => 2000, 'offer_type' => 'waitlist'],
        ], 9, $this->recorder(), $this->noSms());

        $this->assertSame(2, $result['notified']);
        $this->assertSame([], $result['failed']);
        $this->assertSame(2, $result['emails_sent'], 'the shared household is one email, not two');
        $this->assertSame(
            ['THERIVERAS@gmail.com', 'Dana.Alvarez@example.com'],
            array_column($this->sent, 'to')
        );
    }

    public function testOneFamilyFailingDoesNotStopTheRest(): void
    {
        $result = te_tryout_offer_notify_all($this->pdo, [
            ['registration_id' => 1000, 'offer_type' => 'roster', 'team_id' => 7],
            ['registration_id' => 2000, 'offer_type' => 'roster'],
        ], 9, $this->recorder(['theriveras@gmail.com']), $this->noSms());

        $this->assertSame(1, $result['notified']);
        $this->assertSame([1000], $result['failed'],
            'the registration id is what staff need to retry, not a count');
        $this->assertCount(2, $this->sent, 'the batch continued past the failure');
    }

    public function testAThrowingTransportIsAFailureNotACrash(): void
    {
        $boom = static function (): bool {
            throw new \RuntimeException('SendGrid 502');
        };

        $result = te_tryout_offer_notify_all($this->pdo, [
            ['registration_id' => 1000, 'offer_type' => 'roster'],
        ], 9, $boom, $this->noSms());

        $this->assertSame(0, $result['notified']);
        $this->assertSame([1000], $result['failed']);
    }

    public function testARegistrationThatResolvesToNobodyIsReportedNotSkipped(): void
    {
        $this->pdo->exec("UPDATE registrations SET registrant_email = '' WHERE id = 2000");

        $result = te_tryout_offer_notify_all($this->pdo, [
            ['registration_id' => 2000, 'offer_type' => 'roster'],
            ['registration_id' => 999999, 'offer_type' => 'roster'],
        ], 9, $this->recorder(), $this->noSms());

        $this->assertSame(0, $result['notified']);
        $this->assertSame([2000, 999999], $result['failed'],
            'a family with no address and a registration that does not exist are both failures');
        $this->assertSame([], $this->sent);
    }

    public function testTheSmsBodyCarriesNoMergeTagsAndStaysAscii(): void
    {
        $ctx = $this->context(1000, 7);
        $ctx['offer_type'] = 'roster';

        $body = te_tryout_offer_sms_body($ctx);

        $this->assertStringContainsString('Central Kansas United', $body);
        $this->assertStringContainsString('Maya Rivera', $body);
        $this->assertStringNotContainsString('{{', $body);
        // A single non-ASCII character forces the whole message to UCS-2, where
        // the segment limit collapses from 160 to 70 and the cost triples.
        $this->assertSame(1, preg_match('/^[\x20-\x7E]+$/', $body),
            'straight quotes and hyphens only — a curly apostrophe triples the bill');
    }

    // ── The handler actually calls it, in the right place ───────────────────

    private function sendOffersBlock(): string
    {
        $src = file_get_contents(__DIR__ . '/../../registration/tryouts-api.php');
        $start = strpos($src, "case 'send-offers':");
        $end = strpos($src, "case 'update-offer':", $start ?: 0);

        $this->assertNotFalse($start, "the send-offers case must still exist");
        $this->assertNotFalse($end, "the update-offer case marks the end of the block");

        return substr($src, $start, $end - $start);
    }

    public function testSendOffersNotifiesAfterTheOffersAreWrittenAndCommitted(): void
    {
        $block = $this->sendOffersBlock();

        $insert = strpos($block, 'INSERT INTO tryout_offers');
        $commit = strpos($block, '$connection->commit()');
        $flag   = strpos($block, "te_feature_enabled('TRYOUT_OFFER_EMAIL')");
        $notify = strpos($block, 'te_tryout_offer_notify_all(');

        $this->assertNotFalse($insert, 'the offers must still be written');
        $this->assertNotFalse($commit);
        $this->assertNotFalse($flag, "send-offers must consult te_feature_enabled('TRYOUT_OFFER_EMAIL')");
        $this->assertNotFalse($notify, 'send-offers must actually notify the families');

        $this->assertLessThan($commit, $insert, 'the rows are written before the commit');
        $this->assertLessThan($flag, $commit,
            'notification runs AFTER commit — an email cannot be rolled back');
        $this->assertLessThan($notify, $flag,
            'the notify call must sit inside the kill-switch check, not beside it');
        $this->assertLessThan($notify, $insert,
            'nothing may be mailed before the offer it describes exists');
    }

    public function testTheSwitchedOffBranchNeverSaysSent(): void
    {
        $block = $this->sendOffersBlock();

        $flag   = strpos($block, "te_feature_enabled('TRYOUT_OFFER_EMAIL')");
        $notify = strpos($block, 'te_tryout_offer_notify_all(');
        // Strip // comments: the branch is commented with the very word being
        // asserted against, and the assertion is about what the API RETURNS.
        $disabledBranch = preg_replace('#^\s*//.*$#m', '', substr($block, $flag, $notify - $flag));

        $this->assertStringContainsString("'feature_disabled' => 'TRYOUT_OFFER_EMAIL'", $disabledBranch,
            'the response has to name the switch that stopped the send');
        $this->assertStringContainsString("'notified' => 0", $disabledBranch);
        $this->assertDoesNotMatchRegularExpression('/\bsent\b/i', $disabledBranch,
            'never the word "sent" when nothing was sent — that is the bug being fixed');
    }

    public function testTheOldUnconditionalSuccessMessageIsGone(): void
    {
        $this->assertStringNotContainsString(
            "'message' => 'Offers sent successfully'",
            $this->sendOffersBlock(),
            'a fixed message cannot be true on both the send and the no-send path'
        );
    }

    /**
     * tryouts-api.php was authenticated on 2026-09-02. Adding a send must not
     * move, weaken or skip the per-registration scope check — the batch is a list
     * of ids from the client, so checking one of them is checking none.
     */
    public function testTheScopeCheckStillRunsOnEveryOfferBeforeAnythingIsWritten(): void
    {
        $block = $this->sendOffersBlock();

        $scope = strpos($block, 'tryout_requireClubStaff(');
        $begin = strpos($block, '$connection->beginTransaction()');

        $this->assertNotFalse($scope, 'send-offers must still check standing per registration');
        $this->assertLessThan($begin, $scope, 'the scope check runs before any write');
        $this->assertStringContainsString('tryout_programIdForRegistration($connection, $offer[', $block,
            'the club is resolved from each registration, never from the request body');
    }

    /**
     * A 'not_selected' row is recorded but never emailed by this path — that message is
     * the club's to deliver (decisions doc item 12). Mutation: remove the array_filter.
     */
    public function testNotSelectedFamiliesAreNotEmailed(): void
    {
        $src = file_get_contents(__DIR__ . '/../../registration/tryouts-api.php');
        $start = strpos($src, "case 'send-offers':");
        $end = strpos($src, "case 'update-offer':", $start);
        $body = substr($src, $start, $end - $start);
        $filter = strpos($body, "!== 'not_selected'");
        $notify = strpos($body, 'te_tryout_offer_notify_all(');
        $this->assertNotFalse($filter);
        $this->assertLessThan($notify, $filter, 'not_selected must be filtered out before the notifier runs');
        $this->assertStringContainsString("'not_notified_not_selected'", $body);
    }
}
