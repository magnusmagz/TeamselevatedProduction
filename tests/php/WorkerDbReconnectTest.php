<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The queue worker must survive Neon dropping an idle connection.
 *
 * Found 2026-08-20. workers/queue-worker.php opened one PDO handle at boot and
 * handed it to EmailSendService, SmsSendService, ImportJobProcessor and
 * CalendarSyncService for the dyno's whole life. Neon's pooler drops idle
 * connections and PDO does not reconnect, so after a quiet stretch the handle
 * was dead permanently:
 *
 *   226 consecutive log lines, one per minute —
 *   "[Worker] import reconciliation error: SQLSTATE[HY000] … no connection to the server"
 *
 * It self-healed only because Heroku cycles dynos daily. A job enqueued during a
 * dead window failed three times over ~8 minutes into failed_jobs with no
 * recovery, and failed_jobs already held one such row from 2026-07-09 — so this
 * had already cost a real send before anyone looked.
 *
 * Reconnecting is only half the fix, which is what this test mostly exists to
 * pin: a fresh PDO object does nothing for four services still holding the dead
 * one. They must be rebuilt whenever the connection is replaced.
 *
 * These are structural assertions on purpose. The failure needs a real Neon
 * handle left idle for hours, so there is nothing a unit test can execute — but
 * the SHAPE that caused it (boot-time handles, no liveness check before use) is
 * readable, and that is what must not come back.
 */
class WorkerDbReconnectTest extends TestCase
{
    private string $worker;
    private string $database;

    protected function setUp(): void
    {
        $this->worker = file_get_contents(__DIR__ . '/../../workers/queue-worker.php');
        $this->database = file_get_contents(__DIR__ . '/../../config/database.php');
    }

    /** The reconnect primitives exist at all. */
    public function testDatabaseExposesALivenessCheckAndAReconnect(): void
    {
        $this->assertMatchesRegularExpression(
            '/function\s+isAlive\s*\(/',
            $this->database,
            'Database must expose isAlive() — a dead PDO handle stays an object, so nothing else can tell.'
        );
        $this->assertMatchesRegularExpression(
            '/function\s+ensureConnection\s*\(/',
            $this->database,
            'Database must expose ensureConnection() for long-running processes.'
        );
    }

    /**
     * The probe must be a real query.
     *
     * PDO holds a dead Neon connection as a perfectly ordinary object — there is
     * no flag to read, no getAttribute that reports it. Only issuing a statement
     * finds out.
     */
    public function testLivenessIsProvedByIssuingAQuery(): void
    {
        $body = $this->methodBody($this->database, 'isAlive');
        $this->assertStringContainsString(
            'SELECT 1',
            $body,
            'isAlive() must issue a real query; a dead handle is indistinguishable from a live one otherwise.'
        );
    }

    /**
     * connect() must not die.
     *
     * The constructor's failure path is a 500 plus die(), which is right for a web
     * request and fatal for a worker — a transient Neon blip would exit the dyno
     * mid-queue. Reconnecting has to throw instead so the caller can decide.
     */
    public function testTheReconnectPathDoesNotKillTheProcess(): void
    {
        $connect = $this->methodBody($this->database, 'connect');

        $this->assertStringNotContainsString('die(', $connect,
            'connect() must not die() — a worker reconnect would exit the dyno on a transient outage.');
        $this->assertStringNotContainsString('http_response_code(', $connect,
            'connect() must not write an HTTP status — it runs in a CLI worker too.');
        $this->assertStringNotContainsString('exit', $connect,
            'connect() must not exit.');

        $ensure = $this->methodBody($this->database, 'ensureConnection');
        $this->assertStringNotContainsString('die(', $ensure,
            'ensureConnection() must not die().');
    }

    /**
     * THE ONE THAT MATTERS.
     *
     * A new PDO does nothing for a service constructed with the old one. If the
     * worker ever reconnects without rebuilding, every symptom returns while the
     * logs claim a healthy reconnect.
     */
    public function testReconnectingRebuildsEveryServiceHoldingTheHandle(): void
    {
        $this->assertMatchesRegularExpression(
            '/\$buildServices\s*=\s*function/',
            $this->worker,
            'Services must come from a factory so they can be rebuilt, not be constructed once at boot.'
        );

        $refresh = $this->closureBody($this->worker, 'ensureDb');

        $this->assertStringContainsString('ensureConnection()', $refresh,
            'The refresh helper must ask Database whether the handle is still usable.');
        $this->assertStringContainsString('getConnection()', $refresh,
            'The refresh helper must pick up the NEW handle after a reconnect.');
        $this->assertStringContainsString('$buildServices(', $refresh,
            'A reconnect MUST rebuild the services — they hold the dead handle, and that was the actual bug.');
    }

    /**
     * A job may be the first database work in hours, so the check has to happen
     * before the handle is used — not once at boot, and not only on the timer.
     */
    public function testTheConnectionIsVerifiedBeforeEachJobIsDispatched(): void
    {
        $ensurePos = strpos($this->worker, '$ensureDb();');
        $this->assertNotFalse($ensurePos, 'The worker must call the refresh helper.');

        $dispatchPos = strpos($this->worker, "if (\$fromQueue === 'email_queue')");
        $this->assertNotFalse($dispatchPos, 'Dispatch block not found — this test needs updating.');

        $lastEnsureBeforeDispatch = strrpos(
            substr($this->worker, 0, $dispatchPos),
            '$ensureDb();'
        );
        $this->assertNotFalse(
            $lastEnsureBeforeDispatch,
            'The connection must be verified BEFORE a job is handed to a service, or the send fails on a '
            . 'dead handle and burns a retry for a reason unrelated to the job.'
        );
    }

    /** The once-a-minute sweep is what surfaced the bug; it must be guarded too. */
    public function testTheImportSweepVerifiesTheConnection(): void
    {
        $sweepStart = strpos($this->worker, '$lastImportSweep = time();');
        $this->assertNotFalse($sweepStart, 'Import sweep not found — this test needs updating.');

        $sweepEnd = strpos($this->worker, 'import reconciliation error', $sweepStart);
        $this->assertNotFalse($sweepEnd);

        $this->assertStringContainsString(
            '$ensureDb();',
            substr($this->worker, $sweepStart, $sweepEnd - $sweepStart),
            'The import sweep runs on a timer against an idle handle — it is where the 226 error lines came from.'
        );
    }

    /**
     * No boot-time service variable may survive.
     *
     * $emailService et al were captured once at startup. Leaving even one behind
     * means that queue alone keeps using the dead connection, which is a worse
     * failure than the original: three queues recover and one silently does not.
     */
    public function testNoServiceIsPinnedToTheBootTimeHandle(): void
    {
        foreach (['emailService', 'smsService', 'importProcessor', 'calendarSyncService'] as $stale) {
            $this->assertStringNotContainsString(
                '$' . $stale,
                $this->worker,
                "\${$stale} is a boot-time handle. Dispatch through the rebuildable \$services map instead, "
                . 'or that queue keeps using the dead connection after a reconnect.'
            );
        }
    }

    /** Extract a method body by brace matching from its signature. */
    private function methodBody(string $src, string $name): string
    {
        $this->assertMatchesRegularExpression(
            '/function\s+' . preg_quote($name, '/') . '\s*\(/',
            $src,
            "Method {$name}() not found."
        );
        preg_match('/function\s+' . preg_quote($name, '/') . '\s*\(/', $src, $m, PREG_OFFSET_CAPTURE);
        return $this->braceBlock($src, $m[0][1]);
    }

    /** Extract a closure body assigned to $name. */
    private function closureBody(string $src, string $name): string
    {
        $pattern = '/\$' . preg_quote($name, '/') . '\s*=\s*function/';
        $this->assertMatchesRegularExpression($pattern, $src, "Closure \${$name} not found.");
        preg_match($pattern, $src, $m, PREG_OFFSET_CAPTURE);
        return $this->braceBlock($src, $m[0][1]);
    }

    private function braceBlock(string $src, int $from): string
    {
        $open = strpos($src, '{', $from);
        $depth = 0;
        for ($i = $open, $len = strlen($src); $i < $len; $i++) {
            if ($src[$i] === '{') { $depth++; }
            if ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $open, $i - $open + 1);
                }
            }
        }
        return substr($src, $open);
    }
}
