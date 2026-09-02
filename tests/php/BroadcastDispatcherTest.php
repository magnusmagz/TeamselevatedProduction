<?php

use PHPUnit\Framework\TestCase;

// Load the gateway for resolveBroadcastRecipients only — no dispatch, no headers,
// no Neon connect. Same pattern as BroadcastRecipientResolutionTest.
if (!defined('TE_COMMUNICATIONS_LIB_ONLY')) {
    define('TE_COMMUNICATIONS_LIB_ONLY', true);
}
require_once __DIR__ . '/../../api/communications-gateway.php';
require_once __DIR__ . '/../../services/EmailSendService.php';
require_once __DIR__ . '/../../services/SmsSendService.php';
require_once __DIR__ . '/../../lib/broadcast_dispatcher.php';

/**
 * Nothing dispatched campaigns stored as status='scheduled'.
 *
 * Scheduling a broadcast wrote a complete campaign row and then nothing ever sent
 * it — the 2026-07-06 silent-failure sweep papered over that with a 400 at
 * handleSendBroadcast rather than fixing it. This is the dispatcher that makes the
 * 400 removable, and these are the properties that make it safe to run inside the
 * queue worker.
 *
 * The load-bearing one is the FIRST test. `workers/queue-worker.php` also drives
 * email, SMS, imports and calendar sync; a club that cleared its SMS number makes
 * queueSms throw a RuntimeException, and an uncaught throw in a worker tick stops
 * all four queues. One club's misconfiguration must cost that club's campaign and
 * nothing else.
 */
class BroadcastDispatcherTest extends TestCase
{
    private PDO $pdo;
    private FakeBroadcastEmailService $email;
    private FakeBroadcastSmsService $sms;
    private array $log = [];

    private const CLUB = 32;
    private const TEAM = 1;
    private const ADMIN = 12;
    private const COACH = 10;

    protected function setUp(): void
    {
        putenv('TE_FEATURE_SCHEDULED_DISPATCH');
        unset($_ENV['TE_FEATURE_SCHEDULED_DISPATCH']);
        te_broadcast_reset_column_probe();

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // broadcast_campaigns mirrors tests/fixtures/production-schema.json PLUS the
        // four columns migration 083 adds. A fixture that does not mirror the
        // snapshot is worse than no fixture (MergeFieldServiceTest's invented
        // `events` table kept the suite green for months while prod was broken).
        $this->pdo->exec("
            CREATE TABLE broadcast_campaigns (
                id INTEGER PRIMARY KEY, club_profile_id INTEGER, user_id INTEGER,
                template_id INTEGER, name TEXT, subject TEXT, channel TEXT,
                recipient_criteria TEXT, status TEXT, scheduled_at TEXT, sent_at TEXT,
                total_recipients INTEGER, sent_count INTEGER, skipped_count INTEGER,
                failed_count INTEGER, created_at TEXT, updated_at TEXT,
                body TEXT, html_body TEXT, event_id INTEGER, failure_reason TEXT);

            CREATE TABLE athletes (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT,
                email TEXT, phone TEXT, club_id INTEGER, deleted_at TEXT, active_status INTEGER);
            CREATE TABLE guardians (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT,
                email TEXT, personal_email TEXT, mobile_phone TEXT,
                sms_opt_out INTEGER DEFAULT 0, receive_invites INTEGER DEFAULT 1);
            CREATE TABLE athlete_guardians (id INTEGER PRIMARY KEY, athlete_id INTEGER, guardian_id INTEGER);
            CREATE TABLE users (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT,
                email TEXT, phone TEXT);
            CREATE TABLE user_club_access (id INTEGER PRIMARY KEY, user_id INTEGER,
                club_profile_id INTEGER, role TEXT, active INTEGER, revoked_at TEXT);
            CREATE TABLE teams (id INTEGER PRIMARY KEY, name TEXT, club_id INTEGER,
                primary_coach_id INTEGER, deleted_at TEXT);
            CREATE TABLE team_members (id INTEGER PRIMARY KEY, team_id INTEGER, athlete_id INTEGER,
                user_id INTEGER, role TEXT, status TEXT);
        ");

        $p = $this->pdo;
        $p->exec("INSERT INTO teams (id, name, club_id, primary_coach_id, deleted_at)
            VALUES (1, 'Eagles U14', 32, 10, NULL), (2, 'Hawks U12', 32, NULL, NULL)");
        $p->exec("INSERT INTO athletes (id, first_name, last_name, email, phone, club_id, deleted_at, active_status)
            VALUES (1, 'Rachel', 'Jones', 'rachel@example.com', '360-555-0101', 32, NULL, 1)");
        $p->exec("INSERT INTO team_members (id, team_id, athlete_id, user_id, role, status)
            VALUES (1, 1, 1, NULL, 'player', 'active')");
        $p->exec("INSERT INTO guardians (id, first_name, last_name, email, personal_email, mobile_phone, sms_opt_out, receive_invites)
            VALUES (1, 'John', 'Jones', 'thejones@example.com', NULL, '360-555-0201', 0, 1)");
        $p->exec("INSERT INTO athlete_guardians (id, athlete_id, guardian_id) VALUES (1, 1, 1)");
        $p->exec("INSERT INTO users (id, first_name, last_name, email, phone) VALUES
            (10, 'Coach', 'Lee', 'coach@example.com', '360-555-0301'),
            (12, 'Admin', 'Ada', 'admin@example.com', '360-555-0302')");
        $p->exec("INSERT INTO user_club_access (id, user_id, club_profile_id, role, active, revoked_at) VALUES
            (1, 10, 32, 'coach', 1, NULL),
            (2, 12, 32, 'club_admin', 1, NULL)");

        $this->email = new FakeBroadcastEmailService($this->pdo);
        $this->sms   = new FakeBroadcastSmsService($this->pdo);
        $this->log   = [];
    }

    /** @return callable */
    private function logger()
    {
        return function (string $line) { $this->log[] = $line; };
    }

    private function dispatch(): array
    {
        return te_broadcast_dispatch_due($this->pdo, $this->email, $this->sms, $this->logger());
    }

    /**
     * @param array<string,mixed> $over
     */
    private function campaign(array $over = []): int
    {
        static $next = 100;
        $row = array_merge([
            'id'                 => $next++,
            'club_profile_id'    => self::CLUB,
            'user_id'            => self::ADMIN,
            'channel'            => 'email',
            'subject'            => 'Practice moved',
            'body'               => 'Practice is at 6.',
            'html_body'          => '<p>Practice is at 6.</p>',
            'status'             => 'scheduled',
            'scheduled_at'       => gmdate('Y-m-d H:i:s', time() - 120),
            'criteria'           => ['scope' => 'teams', 'team_ids' => [self::TEAM],
                                     'recipient_types' => ['guardian'], 'exclude_ids' => []],
        ], $over);

        $stmt = $this->pdo->prepare(
            "INSERT INTO broadcast_campaigns
                (id, club_profile_id, user_id, name, subject, channel, recipient_criteria,
                 status, scheduled_at, body, html_body, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?, CURRENT_TIMESTAMP)"
        );
        $stmt->execute([
            $row['id'], $row['club_profile_id'], $row['user_id'], 'Campaign ' . $row['id'],
            $row['subject'], $row['channel'], json_encode($row['criteria']),
            $row['status'], $row['scheduled_at'], $row['body'], $row['html_body'],
        ]);
        return (int) $row['id'];
    }

    private function row(int $id): array
    {
        $s = $this->pdo->prepare('SELECT * FROM broadcast_campaigns WHERE id = ?');
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC);
    }

    // ────────────────────────────────────────────────────────────────
    // (a) One club's misconfiguration must not stop the tick
    // ────────────────────────────────────────────────────────────────

    /**
     * The whole reason this runs inside queue-worker.php rather than its own dyno:
     * a throw here would take email, SMS, imports and calendar sync down with it.
     */
    public function testAThrowingCampaignIsMarkedFailedAndTheNextOneStillSends(): void
    {
        $bad  = $this->campaign(['channel' => 'sms', 'subject' => null, 'html_body' => null]);
        $good = $this->campaign();

        // Exactly the live failure: te_resolve_sms_sender returns null for a club
        // that cleared its number and queueSms throws RuntimeException.
        $this->sms->throw = new RuntimeException('This club has no SMS number configured.');

        $result = $this->dispatch();

        $this->assertSame('failed', $this->row($bad)['status'],
            'A campaign whose send threw must be marked failed, not left in sending forever.');
        $this->assertStringContainsString('no SMS number', (string) $this->row($bad)['failure_reason'],
            'The reason must be recorded — a failed campaign with no reason is unexplainable later.');

        $this->assertSame('sent', $this->row($good)['status'],
            'The throwing campaign must not stop the ones behind it in the same tick.');
        $this->assertCount(1, $this->email->calls);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(1, $result['sent']);
        $this->assertNotEmpty($result['errors'], 'The failure must be reported back to the worker to log.');
    }

    /** A failure is per campaign; the tick itself returns normally. */
    public function testTheTickDoesNotRethrow(): void
    {
        $this->campaign(['channel' => 'sms', 'subject' => null, 'html_body' => null]);
        $this->sms->throw = new RuntimeException('boom');

        $result = $this->dispatch();
        $this->assertIsArray($result);
    }

    // ────────────────────────────────────────────────────────────────
    // (b) Dispatched exactly once
    // ────────────────────────────────────────────────────────────────

    public function testADueCampaignDispatchesOnceAndASecondTickFindsNothing(): void
    {
        $id = $this->campaign();

        $first = $this->dispatch();
        $this->assertSame(1, $first['claimed']);
        $this->assertSame(1, $first['sent']);
        $this->assertCount(1, $this->email->calls);

        $row = $this->row($id);
        $this->assertSame('sent', $row['status']);
        $this->assertNotNull($row['sent_at']);
        $this->assertSame(1, (int) $row['total_recipients']);
        $this->assertSame(1, (int) $row['sent_count']);

        $second = $this->dispatch();
        $this->assertSame(0, $second['claimed'], 'A sent campaign must never be claimed again.');
        $this->assertCount(1, $this->email->calls, 'The second tick must not re-send.');
    }

    /**
     * The claim is what makes two ticks safe, and it has to be the UPDATE itself —
     * a SELECT-then-UPDATE leaves a window where both ticks see 'scheduled'.
     */
    public function testTheClaimIsAtomicSoASecondClaimerGetsNothing(): void
    {
        $id = $this->campaign();

        $this->assertNotNull(te_broadcast_claim($this->pdo, $id), 'First claim wins.');
        $this->assertNull(te_broadcast_claim($this->pdo, $id),
            'The second claimer must get null — the row is no longer status=scheduled.');
    }

    public function testTheClaimSqlFiltersOnStatusAndUsesReturning(): void
    {
        $src = file_get_contents(__DIR__ . '/../../lib/broadcast_dispatcher.php');
        $this->assertMatchesRegularExpression(
            '/UPDATE\s+broadcast_campaigns.*?WHERE\s+id\s*=\s*\?\s+AND\s+status\s*=\s*\'scheduled\'.*?RETURNING/is',
            $src,
            "The claim must be a single UPDATE ... WHERE status='scheduled' ... RETURNING."
        );
    }

    // ────────────────────────────────────────────────────────────────
    // (c) Not yet due
    // ────────────────────────────────────────────────────────────────

    public function testANotYetDueCampaignIsUntouched(): void
    {
        $id = $this->campaign(['scheduled_at' => gmdate('Y-m-d H:i:s', time() + 3600)]);

        $result = $this->dispatch();

        $this->assertSame(0, $result['claimed']);
        $this->assertSame('scheduled', $this->row($id)['status']);
        $this->assertSame([], $this->email->calls);
    }

    public function testACampaignWithNoScheduledAtIsNeverPickedUp(): void
    {
        $id = $this->campaign(['scheduled_at' => null]);

        $this->dispatch();

        $this->assertSame('scheduled', $this->row($id)['status']);
        $this->assertSame([], $this->email->calls);
    }

    // ────────────────────────────────────────────────────────────────
    // (d) The kill switch
    // ────────────────────────────────────────────────────────────────

    public function testWithTheSwitchOffNothingIsClaimed(): void
    {
        $id = $this->campaign();
        putenv('TE_FEATURE_SCHEDULED_DISPATCH=off');
        $_ENV['TE_FEATURE_SCHEDULED_DISPATCH'] = 'off';

        $result = $this->dispatch();

        $this->assertSame(0, $result['claimed']);
        $this->assertSame('SCHEDULED_DISPATCH', $result['feature_disabled'] ?? null,
            'A tick that skipped work must say so rather than report an empty success.');
        $this->assertSame('scheduled', $this->row($id)['status'],
            'The switch must leave the campaign claimable once it is flipped back on.');
        $this->assertSame([], $this->email->calls);
    }

    public function testTheSwitchDefaultsOnWhenUnset(): void
    {
        $this->campaign();
        $result = $this->dispatch();
        $this->assertArrayNotHasKey('feature_disabled', $result);
        $this->assertSame(1, $result['sent']);
    }

    // ────────────────────────────────────────────────────────────────
    // Part 1's "four things that will bite"
    // ────────────────────────────────────────────────────────────────

    /**
     * (d) Staleness. "Practice is cancelled this morning" arriving that evening is
     * worse than never arriving, so a campaign more than the window late is failed
     * rather than fired blindly.
     */
    public function testACampaignMoreThanTheWindowLateIsFailedNotSent(): void
    {
        $id = $this->campaign([
            'scheduled_at' => gmdate('Y-m-d H:i:s', time() - TE_BROADCAST_MAX_LATENESS_SECONDS - 600),
        ]);

        $result = $this->dispatch();

        $this->assertSame('failed', $this->row($id)['status']);
        $this->assertStringContainsString('late', strtolower((string) $this->row($id)['failure_reason']));
        $this->assertSame([], $this->email->calls, 'A stale campaign must not reach the send services.');
        $this->assertSame(1, $result['stale']);
    }

    public function testACampaignInsideTheWindowStillSends(): void
    {
        $id = $this->campaign([
            'scheduled_at' => gmdate('Y-m-d H:i:s', time() - TE_BROADCAST_MAX_LATENESS_SECONDS + 600),
        ]);

        $this->dispatch();

        $this->assertSame('sent', $this->row($id)['status']);
    }

    /**
     * (c) Permission is checked at schedule time and was enforced never. A coach who
     * loses the team between scheduling and firing must not still reach those families.
     */
    public function testACoachWhoLostTheTeamHasTheCampaignFailedNotSent(): void
    {
        $id = $this->campaign(['user_id' => self::COACH,
            'criteria' => ['scope' => 'teams', 'team_ids' => [2],
                           'recipient_types' => ['guardian'], 'exclude_ids' => []]]);

        $this->dispatch();

        $this->assertSame('failed', $this->row($id)['status']);
        $this->assertStringContainsString('access', strtolower((string) $this->row($id)['failure_reason']));
        $this->assertSame([], $this->email->calls);
    }

    public function testACoachStillOnTheTeamStillSends(): void
    {
        $id = $this->campaign(['user_id' => self::COACH]);
        $this->dispatch();
        $this->assertSame('sent', $this->row($id)['status']);
    }

    public function testACoachCannotFireAClubWideCampaign(): void
    {
        $id = $this->campaign(['user_id' => self::COACH,
            'criteria' => ['scope' => 'club', 'team_ids' => [],
                           'recipient_types' => ['guardian'], 'exclude_ids' => []]]);

        $this->dispatch();

        $this->assertSame('failed', $this->row($id)['status']);
        $this->assertSame([], $this->email->calls);
    }

    /** A revoked role is the newer fact — same rule lib/JWT.php learned in 2026-08. */
    public function testARevokedRoleCannotFireACampaign(): void
    {
        $this->pdo->exec("UPDATE user_club_access SET revoked_at = '2026-01-01' WHERE user_id = 12");
        $id = $this->campaign();

        $this->dispatch();

        $this->assertSame('failed', $this->row($id)['status']);
        $this->assertSame([], $this->email->calls);
    }

    // ────────────────────────────────────────────────────────────────
    // Recipients are re-resolved server-side at dispatch, not replayed
    // ────────────────────────────────────────────────────────────────

    /**
     * ⚠️ recipient_types is SINGULAR on this path. The plural forms belong to
     * resolve-group in recipient-search-gateway.php and resolve nobody here — a
     * 200 with total_recipients: 0. Same lock as
     * BroadcastRecipientResolutionTest::testPluralRecipientTypesResolveNobody.
     */
    public function testTheDispatcherResolvesThroughTheGatewayResolver(): void
    {
        $src = file_get_contents(__DIR__ . '/../../lib/broadcast_dispatcher.php');
        $this->assertStringContainsString('resolveBroadcastRecipients(', $src,
            'Recipients must be re-resolved by the same function send-broadcast uses, not stored and replayed.');
    }

    public function testRecipientsAreResolvedFreshSoARosterChangeIsHonoured(): void
    {
        $id = $this->campaign();

        // A second family joins the team after the campaign was scheduled.
        $this->pdo->exec("INSERT INTO athletes (id, first_name, last_name, email, phone, club_id, deleted_at, active_status)
            VALUES (2, 'New', 'Kid', 'newkid@example.com', '360-555-0102', 32, NULL, 1)");
        $this->pdo->exec("INSERT INTO team_members (id, team_id, athlete_id, user_id, role, status)
            VALUES (2, 1, 2, NULL, 'player', 'active')");
        $this->pdo->exec("INSERT INTO guardians (id, first_name, last_name, email, personal_email, mobile_phone, sms_opt_out, receive_invites)
            VALUES (2, 'Nadia', 'Kid', 'nadia@example.com', NULL, '360-555-0202', 0, 1)");
        $this->pdo->exec("INSERT INTO athlete_guardians (id, athlete_id, guardian_id) VALUES (2, 2, 2)");

        $this->dispatch();

        $this->assertSame(2, (int) $this->row($id)['total_recipients'],
            'The roster at dispatch time is the audience, not the roster at schedule time.');
    }

    public function testTheStoredBodyIsWhatGetsSent(): void
    {
        $this->campaign(['subject' => 'Kickoff', 'body' => 'See you Saturday.',
                         'html_body' => '<p>See you Saturday.</p>']);

        $this->dispatch();

        $this->assertSame('Kickoff', $this->email->calls[0]['subject']);
        $this->assertSame('<p>See you Saturday.</p>', $this->email->calls[0]['html_body']);
        $this->assertSame('See you Saturday.', $this->email->calls[0]['body']);
    }

    public function testAnSmsCampaignGoesThroughTheSmsService(): void
    {
        $id = $this->campaign(['channel' => 'sms', 'subject' => null, 'html_body' => null,
                               'body' => 'Practice at 6.']);

        $this->dispatch();

        $this->assertCount(1, $this->sms->calls);
        $this->assertSame([], $this->email->calls);
        $this->assertSame('Practice at 6.', $this->sms->calls[0]['body']);
        $this->assertSame('sent', $this->row($id)['status']);
    }

    /**
     * A campaign that resolves to nobody is 'sent' with zero, not 'failed'. An empty
     * audience is a real answer — a team whose families all unsubscribed — and
     * failing it would send an admin chasing a bug that is not there.
     */
    public function testACampaignWithNoRecipientsIsSentWithZeroNotFailed(): void
    {
        $id = $this->campaign(['criteria' => ['scope' => 'teams', 'team_ids' => [2],
            'recipient_types' => ['guardian'], 'exclude_ids' => []]]);

        $this->dispatch();

        $this->assertSame('sent', $this->row($id)['status']);
        $this->assertSame(0, (int) $this->row($id)['total_recipients']);
        $this->assertSame([], $this->email->calls);
    }

    // ────────────────────────────────────────────────────────────────
    // The schema probe: main ships before 083 is applied by hand
    // ────────────────────────────────────────────────────────────────

    public function testWithoutTheMigrationColumnsNothingIsClaimed(): void
    {
        $bare = new PDO('sqlite::memory:');
        $bare->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $bare->exec("CREATE TABLE broadcast_campaigns (id INTEGER PRIMARY KEY, status TEXT, scheduled_at TEXT)");
        $bare->exec("INSERT INTO broadcast_campaigns VALUES (1, 'scheduled', '2020-01-01 00:00:00')");
        te_broadcast_reset_column_probe();

        $result = te_broadcast_dispatch_due($bare, $this->email, $this->sms, $this->logger());

        $this->assertTrue($result['schema_pending'] ?? false,
            'Before migration 083 the dispatcher must stand down and say so, not 42703 the worker.');
        $this->assertSame(0, $result['claimed']);
        $this->assertSame('scheduled', $bare->query('SELECT status FROM broadcast_campaigns')->fetchColumn());
    }

    // ────────────────────────────────────────────────────────────────
    // Parse-based: the worker tick, and the gateway's surviving 400
    // ────────────────────────────────────────────────────────────────

    /**
     * The tick must take its services from $buildServices(), not construct its own.
     *
     * A service built at boot keeps the dead PDO handle after ensureConnection()
     * replaces it — three queues recover and one silently does not, which is worse
     * than the original bug. Same rule the four existing services follow.
     */
    public function testTheWorkerTickUsesTheRebuiltServices(): void
    {
        $tick = $this->workerTick();

        $this->assertStringContainsString('$services[\'email\']', $tick,
            "The tick must use \$services['email'] so a reconnect rebuilds it.");
        $this->assertStringContainsString('$services[\'sms\']', $tick,
            "The tick must use \$services['sms'] so a reconnect rebuilds it.");
        $this->assertStringNotContainsString('new EmailSendService', $tick,
            'Constructing a service in the tick pins it to the boot-time handle.');
        $this->assertStringNotContainsString('new SmsSendService', $tick,
            'Constructing a service in the tick pins it to the boot-time handle.');
        $this->assertStringContainsString('$ensureDb()', $tick,
            'A tick may be the first database work in hours — probe the handle first.');
    }

    /** Its own catch, for the same reason the chat and moderation ticks have theirs. */
    public function testTheWorkerTickHasItsOwnCatch(): void
    {
        $tick = $this->workerTick();
        $this->assertMatchesRegularExpression('/\btry\s*\{/', $tick,
            'The tick must guard itself; an uncaught throw stops every queue.');
        $this->assertMatchesRegularExpression('/catch\s*\(\s*\\\\?Throwable\b/', $tick,
            'Catch Throwable — a TypeError is as fatal to the loop as an Exception.');
    }

    public function testTheWorkerThrottlesTheSweep(): void
    {
        $worker = file_get_contents(__DIR__ . '/../../workers/queue-worker.php');
        $this->assertStringContainsString('$lastBroadcastSweep', $worker);
        $this->assertMatchesRegularExpression(
            '/const\s+TE_BROADCAST_TICK_SECONDS\s*=\s*30\s*;/', $worker,
            'The sweep is throttled to 30s — an unthrottled tick queries Neon on every loop pass.'
        );
    }

    /**
     * `main` is shared and deploys are by push, so this code reaches production
     * before migration 083 is applied by hand. Until it is, scheduling must keep
     * being refused with the message families' admins already see — accepting a
     * schedule whose body cannot be stored recreates the exact silent failure the
     * guard was added for.
     */
    public function testTheGatewayStillRefusesSchedulingWhenTheColumnsAreAbsent(): void
    {
        $src = file_get_contents(__DIR__ . '/../../api/communications-gateway.php');

        $this->assertStringContainsString(
            'Scheduled sending is not available yet — please send now.', $src,
            'The 400 message must survive for the pre-083 window.'
        );
        $this->assertStringContainsString('te_broadcast_scheduled_columns_present(', $src,
            'The guard must be conditional on the probe, not deleted outright.');
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*!\s*te_broadcast_scheduled_columns_present\s*\(\s*\$connection\s*\)\s*\)\s*\{[^}]*Scheduled sending is not available yet/s',
            $src,
            'The surviving 400 must be inside the not-present branch.'
        );
    }

    public function testTheDispatcherIsGatedOnItsKillSwitch(): void
    {
        $src = file_get_contents(__DIR__ . '/../../lib/broadcast_dispatcher.php');
        $this->assertStringContainsString("te_feature_enabled('SCHEDULED_DISPATCH')", $src);
    }

    /** The tick block, from its throttle test to the end of its catch. */
    private function workerTick(): string
    {
        $worker = file_get_contents(__DIR__ . '/../../workers/queue-worker.php');
        $start = strpos($worker, '$lastBroadcastSweep = time();');
        $this->assertNotFalse($start, 'workers/queue-worker.php has no scheduled-broadcast tick.');
        $end = strpos($worker, 'te_broadcast', $start);
        $this->assertNotFalse($end);
        // Take a generous window past the call so the catch is included.
        return substr($worker, $start - 200, 2000);
    }
}

/** Records what it was asked to queue; optionally throws, as the live services do. */
class FakeBroadcastEmailService extends EmailSendService
{
    public array $calls = [];
    public ?Throwable $throw = null;

    public function queueEmail($params)
    {
        $this->calls[] = $params;
        if ($this->throw !== null) {
            throw $this->throw;
        }
        return ['queued' => count($params['recipients']), 'skipped' => 0, 'skipped_details' => []];
    }
}

class FakeBroadcastSmsService extends SmsSendService
{
    public array $calls = [];
    public ?Throwable $throw = null;

    public function queueSms($params)
    {
        $this->calls[] = $params;
        if ($this->throw !== null) {
            throw $this->throw;
        }
        return ['queued' => count($params['recipients']), 'skipped' => 0, 'skipped_details' => []];
    }
}
