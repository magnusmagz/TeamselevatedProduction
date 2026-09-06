<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;
use CoachInviteService;

require_once __DIR__ . '/../../services/CoachInviteService.php';

/**
 * Coach invites for an import go through the queue (GOTR G6, "invites are a queue
 * you can pause"). The job rides on `email_queue` so the existing per-queue rate
 * limiter in workers/queue-worker.php paces it, and the worker hands it to a
 * dedicated service rather than to EmailSendService — the bulk path logs a
 * communication_log row per send and applies the club's marketing suppressions,
 * neither of which belongs on a sign-in link.
 *
 * Two worker invariants are pinned by parsing the worker source:
 *  - the service is built inside `$buildServices()`, so a reconnect rebuilds it
 *    (CLAUDE.md, "rebuild services, don't just reconnect");
 *  - the loop pops ONCE. On the merged worker two consecutive `$queue->pop()`
 *    calls meant the first job taken was overwritten by the second and lost.
 */
class CoachInviteQueueTest extends TestCase
{
    private PDO $pdo;
    private string $worker;

    protected function setUp(): void
    {
        $this->worker = file_get_contents(__DIR__ . '/../../workers/queue-worker.php');
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
        $this->pdo->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT UNIQUE, first_name TEXT, last_name TEXT,
                password_hash TEXT, role TEXT, auth_provider TEXT, phone TEXT, last_login_at TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE user_club_access (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, club_profile_id INTEGER, role TEXT,
                granted_at TEXT, granted_by INTEGER, revoked_at TEXT, revoked_by INTEGER, active BOOLEAN DEFAULT 1
            );
            CREATE TABLE magic_link_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT, token TEXT, expires_at TEXT, used_at TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP, invitation_id INTEGER, return_to TEXT
            );
            CREATE TABLE audit_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT, resource_type TEXT,
                resource_id INTEGER, ip_address TEXT, user_agent TEXT, details TEXT, created_at TEXT
            );
            CREATE TABLE club_profile (id INTEGER PRIMARY KEY, name TEXT, org_unit_id INTEGER);
            INSERT INTO club_profile (id, name) VALUES (100, 'GOTR Kansas');
            INSERT INTO users (id, email, first_name, last_name, password_hash) VALUES
                (8, 'shell@gotr.org', 'Sam', 'Shell', NULL),
                (7, 'active@gotr.org', 'Ann', 'Active', 'hash');
            INSERT INTO magic_link_tokens (email, token, expires_at) VALUES
                ('shell@gotr.org:coach_invite', 'tok8', '2099-01-01 00:00:00');
        ");
        putenv('TE_FEATURE_COACH_INVITE_EMAIL');
        unset($_ENV['TE_FEATURE_COACH_INVITE_EMAIL']);
    }

    public function testAJobSendsTheFreshestTokenThroughTheInjectedSender(): void
    {
        $sent = [];
        $svc = new CoachInviteService($this->pdo, function ($to, $name, $link) use (&$sent) { $sent[] = [$to, $link]; return true; });
        $r = $svc->processJob(['id' => 'coach_invite_8_100', 'type' => 'coach_invite', 'user_id' => 8, 'club_id' => 100]);

        $this->assertTrue($r['sent'], json_encode($r));
        $this->assertCount(1, $sent);
        $this->assertSame('shell@gotr.org', $sent[0][0]);
        $this->assertStringContainsString('tok8', $sent[0][1]);
        $audit = $this->pdo->query("SELECT action FROM audit_log")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains('coach_invite_sent', $audit);
    }

    public function testAJobForAnAccountThatSignedInMeanwhileSendsNothing(): void
    {
        $called = false;
        $svc = new CoachInviteService($this->pdo, function () use (&$called) { $called = true; return true; });
        $r = $svc->processJob(['id' => 'x', 'type' => 'coach_invite', 'user_id' => 7, 'club_id' => 100]);
        $this->assertFalse($called);
        $this->assertFalse($r['sent']);
        $this->assertSame('already_active', $r['reason']);
    }

    public function testATransportFailureThrowsSoTheWorkerRetries(): void
    {
        $svc = new CoachInviteService($this->pdo, fn() => false);
        $this->expectException(\RuntimeException::class);
        $svc->processJob(['id' => 'x', 'type' => 'coach_invite', 'user_id' => 8, 'club_id' => 100]);
    }

    public function testASwitchedOffSendIsNotARetry(): void
    {
        putenv('TE_FEATURE_COACH_INVITE_EMAIL=off');
        $_ENV['TE_FEATURE_COACH_INVITE_EMAIL'] = 'off';
        $called = false;
        $svc = new CoachInviteService($this->pdo, function () use (&$called) { $called = true; return true; });
        $r = $svc->processJob(['id' => 'x', 'type' => 'coach_invite', 'user_id' => 8, 'club_id' => 100]);
        $this->assertFalse($called);
        $this->assertFalse($r['sent']);
        $this->assertSame('COACH_INVITE_EMAIL', $r['feature_disabled'] ?? null);
    }

    public function testAMalformedPayloadIsRefusedWithoutAThrow(): void
    {
        $svc = new CoachInviteService($this->pdo, fn() => true);
        $r = $svc->processJob(['id' => 'x', 'type' => 'coach_invite']);
        $this->assertFalse($r['sent']);
        $this->assertSame('bad_payload', $r['reason']);
    }

    public function testThePayloadShapeCarriesNoToken(): void
    {
        $p = CoachInviteService::jobPayload(8, 100, 1);
        $this->assertSame('email_queue', CoachInviteService::QUEUE);
        $this->assertSame('coach_invite', $p['type']);
        $this->assertSame(8, $p['user_id']);
        $this->assertSame(100, $p['club_id']);
        $this->assertArrayNotHasKey('token', $p);
        $this->assertArrayHasKey('id', $p);
        $this->assertSame(3, $p['max_attempts']);
    }

    // ---------------------------------------------------------------- worker

    public function testTheWorkerBuildsTheServiceInsideBuildServices(): void
    {
        $start = strpos($this->worker, '$buildServices = function');
        $this->assertNotFalse($start);
        $end = strpos($this->worker, '};', $start);
        $factory = substr($this->worker, $start, $end - $start);
        $this->assertStringContainsString("'coach_invite'", $factory,
            'a service built outside $buildServices() keeps a dead DB handle after a reconnect');
        $this->assertStringContainsString('new CoachInviteService(', $factory);
    }

    public function testTheWorkerRoutesTheJobTypeOffTheEmailQueue(): void
    {
        $this->assertMatchesRegularExpression(
            "/\\\$fromQueue === 'email_queue'[\\s\\S]{0,800}CoachInviteService::TYPE[\\s\\S]{0,200}\\\$services\\['coach_invite'\\]->processJob/",
            $this->worker,
            'email_queue jobs of type coach_invite must go to the coach_invite service, not EmailSendService'
        );
    }

    public function testTheLoopPopsExactlyOnce(): void
    {
        $this->assertSame(1, preg_match_all('/\$queue->pop\(/', $this->worker),
            'two consecutive pops discard the first job taken');
    }
}
