<?php

namespace TeamsElevated\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RedisQueue;
use RuntimeException;

require_once __DIR__ . '/../../lib/worker_queue_assignment.php';
require_once __DIR__ . '/../../lib/worker_tick_lock.php';
require_once __DIR__ . '/../../lib/RedisQueue.php';

/**
 * One worker dyno drained every queue AND ran all three throttled ticks in one
 * single-threaded loop, so a 30,000-person GOTR onboarding blast would serialise
 * ahead of a scheduled broadcast (docs/gotr-hierarchy-plan-2026-09.md §5).
 *
 * The fix is a queue assignment, and the risk it introduces is the reason for most
 * of this file:
 *
 *  1. the DEFAULT must be byte-for-byte today's behaviour — all four queues, ticks
 *     on — because Procfile's `worker:` line is unchanged and that is the process
 *     actually running in production;
 *  2. a subset process must not touch the queues it was not given;
 *  3. two processes with ticks on must not both send the same digest;
 *  4. the rate limiter must SLEEP, never drop, and must be a no-op when Redis is
 *     unreachable — pacing must never be the reason a club's mail stops.
 */
class WorkerQueueAssignmentTest extends TestCase
{
    private string $worker;

    protected function setUp(): void
    {
        $this->worker = file_get_contents(__DIR__ . '/../../workers/queue-worker.php');
    }

    // ── 1. The default is today's behaviour ──────────────────────────────────

    /** No args, no env: every queue, ticks on. This is the production `worker:` line. */
    public function testNoArgumentsMeansEveryQueueAndTicksOn(): void
    {
        $a = te_worker_parse_queue_assignment(['workers/queue-worker.php'], []);

        $this->assertSame(
            ['email_queue', 'sms_queue', 'import_queue', 'calendar_sync_queue'],
            $a['queues'],
            'The unmodified Procfile worker line must still drain everything, in the same order.'
        );
        $this->assertTrue($a['ticks'], 'Ticks default ON — the single worker is what runs them today.');
    }

    /**
     * BRPOP serves queues left to right, so the order decides which queue starves.
     * A subset must keep the canonical order rather than the order it was typed in.
     */
    public function testQueueOrderIsCanonicalNotTheOrderTyped(): void
    {
        $a = te_worker_parse_queue_assignment(['w', '--queues=sms,email'], []);
        $this->assertSame(['email_queue', 'sms_queue'], $a['queues']);
    }

    public function testAllIsAcceptedExplicitly(): void
    {
        $this->assertSame(
            TE_WORKER_ALL_QUEUES,
            te_worker_parse_queue_assignment(['w', '--queues=all'], [])['queues']
        );
    }

    // ── 2. A subset process touches only its own queues ──────────────────────

    /**
     * THE ONE THAT MATTERS for the sends process.
     *
     * `--queues=email` must never pop from the sms, import or calendar lists. Popping
     * an import job on a process that also has `--ticks=off` would drive an import
     * with no reconciliation sweep behind it.
     */
    public function testASubsetNeverPopsFromTheQueuesItWasNotGiven(): void
    {
        $assignment = te_worker_parse_queue_assignment(['w', '--queues=email'], []);
        $this->assertSame(['email_queue'], $assignment['queues']);

        $redis = new FakeRedisQueue();
        $redis->push('email_queue', ['id' => 'e1']);
        $redis->push('sms_queue', ['id' => 's1']);
        $redis->push('import_queue', ['id' => 'i1']);
        $redis->push('calendar_sync_queue', ['id' => 'c1']);

        $popped = [];
        while (($job = $redis->pop(te_worker_rate_allowed_queues($redis, $assignment['queues']), 0)) !== null) {
            $popped[] = $job[0];
        }

        $this->assertSame(['email_queue'], $popped);
        $this->assertSame(1, $redis->length('sms_queue'), 'The sms job must still be sitting there.');
        $this->assertSame(1, $redis->length('import_queue'));
        $this->assertSame(1, $redis->length('calendar_sync_queue'));
    }

    /** The worker pops the assignment, not a hardcoded list. */
    public function testTheWorkerPopsTheAssignedQueues(): void
    {
        $this->assertStringNotContainsString(
            "\$queues = ['email_queue', 'sms_queue', 'import_queue', 'calendar_sync_queue'];",
            $this->worker,
            'The queue list must come from the assignment, not be hardcoded in the loop.'
        );
        $this->assertMatchesRegularExpression(
            '/\$queues\s*=\s*\$assignment\[.queues.\];/',
            $this->worker,
            'The loop must read its queue list from the parsed assignment.'
        );
        $this->assertStringContainsString('$queue->pop($popQueues, 2)', $this->worker);
    }

    /**
     * Retry sweeps follow the assignment too. A process sweeping a queue it does not
     * drain moves a delayed job back onto a list nobody in that process will pop.
     */
    public function testRetrySweepsFollowTheAssignment(): void
    {
        $this->assertMatchesRegularExpression(
            '/foreach\s*\(\s*\$queues\s+as\s+\$q\s*\)\s*\{\s*\$queue->sweepRetries\(\$q\);/',
            $this->worker,
            'sweepRetries must iterate the assigned queues.'
        );
    }

    // ── Bad input is fatal, never a silent fallback ──────────────────────────

    public function testAnUnknownQueueNameIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        te_worker_parse_queue_assignment(['w', '--queues=emial'], []);
    }

    /**
     * A typo must not fall back to "all". A worker draining everything when it was
     * told to drain one queue is indistinguishable from a healthy worker.
     */
    public function testAnUnknownQueueDoesNotFallBackToEverything(): void
    {
        try {
            te_worker_parse_queue_assignment(['w', '--queues=email,nope'], []);
            $this->fail('Expected the bad name to be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('nope', $e->getMessage());
        }
    }

    public function testTheWorkerExitsNonZeroOnABadAssignment(): void
    {
        $this->assertMatchesRegularExpression(
            '/catch\s*\(\s*InvalidArgumentException[^)]*\)\s*\{[^}]*exit\(1\)/s',
            $this->worker,
            'A misread assignment must kill the process, not run with a guessed one.'
        );
    }

    public function testTicksMustBeOnOrOff(): void
    {
        $this->expectException(InvalidArgumentException::class);
        te_worker_parse_queue_assignment(['w', '--ticks=maybe'], []);
    }

    // ── Environment fallback ─────────────────────────────────────────────────

    public function testTheEnvironmentSuppliesTheAssignmentWhenArgvDoesNot(): void
    {
        $a = te_worker_parse_queue_assignment(['w'], [
            'TE_WORKER_QUEUES' => 'import',
            'TE_WORKER_TICKS'  => 'off',
        ]);

        $this->assertSame(['import_queue'], $a['queues']);
        $this->assertFalse($a['ticks']);
    }

    public function testArgvBeatsTheEnvironment(): void
    {
        $a = te_worker_parse_queue_assignment(['w', '--queues=sms', '--ticks=on'], [
            'TE_WORKER_QUEUES' => 'email,import',
            'TE_WORKER_TICKS'  => 'off',
        ]);

        $this->assertSame(['sms_queue'], $a['queues']);
        $this->assertTrue($a['ticks']);
    }

    /** An empty config var is "unset", not "no queues". */
    public function testAnEmptyEnvironmentValueIsIgnored(): void
    {
        $a = te_worker_parse_queue_assignment(['w'], ['TE_WORKER_QUEUES' => '', 'TE_WORKER_TICKS' => '']);
        $this->assertSame(TE_WORKER_ALL_QUEUES, $a['queues']);
        $this->assertTrue($a['ticks']);
    }

    // ── 3. --ticks=off runs no tick ──────────────────────────────────────────

    public function testTicksOffIsParsed(): void
    {
        $this->assertFalse(te_worker_parse_queue_assignment(['w', '--ticks=off'], [])['ticks']);
    }

    /**
     * Every tick — including the import reconciliation sweep — must be behind the
     * flag. Missing one means the sends process quietly does work it was excluded
     * from, which is the whole failure the assignment exists to prevent.
     */
    public function testEveryTickIsGatedOnTheTicksFlag(): void
    {
        $gates = [
            '$lastImportSweep'      => 'import reconciliation',
            '$lastChatNotifySweep'  => 'chat notifications',
            '$lastModAlertSweep'    => 'moderation alerts',
            '$lastBroadcastSweep'   => 'scheduled broadcasts',
        ];

        foreach ($gates as $var => $label) {
            $this->assertMatchesRegularExpression(
                '/if\s*\(\s*\$ticksEnabled\s*&&.{0,200}?' . preg_quote($var, '/') . '/s',
                $this->worker,
                "The {$label} tick must be gated on \$ticksEnabled."
            );
        }
    }

    // ── The tick lock ────────────────────────────────────────────────────────

    /**
     * A second run inside the TTL is refused.
     *
     * This is what protects the two ticks that are NOT atomic: chat notifications
     * SELECT-send-then-mark, and moderation alerts check-send-then-insert. The
     * unique index on the alert table stops a duplicate row, not a duplicate email.
     */
    public function testTheTickLockPreventsASecondRunInsideTheTtl(): void
    {
        $redis = new FakeRedisClient();

        $first = te_worker_tick_lock($redis, 'chat_notify');
        $this->assertNotNull($first, 'The first process must get the lock.');

        $second = te_worker_tick_lock($redis, 'chat_notify');
        $this->assertNull($second, 'A second process inside the TTL must be refused.');

        $this->assertSame('te:tick:chat_notify', array_key_first($redis->store));
    }

    public function testReleasingTheLockLetsTheNextTickRun(): void
    {
        $redis = new FakeRedisClient();

        $token = te_worker_tick_lock($redis, 'broadcast');
        te_worker_tick_unlock($redis, 'broadcast', $token);

        $this->assertNotNull(
            te_worker_tick_lock($redis, 'broadcast'),
            'The happy path releases explicitly; waiting out the 25s TTL would throttle the '
            . '10-second chat tick to one sweep per 25 seconds.'
        );
    }

    /** A late finisher must not delete the lock someone else now holds. */
    public function testUnlockOnlyDeletesTheLockItStillOwns(): void
    {
        $redis = new FakeRedisClient();
        $redis->store['te:tick:chat_notify'] = 'somebody-elses-token';

        te_worker_tick_unlock($redis, 'chat_notify', 'my-stale-token');

        $this->assertSame(
            'somebody-elses-token',
            $redis->store['te:tick:chat_notify'],
            'Deleting another process lock would admit a third process to the tick.'
        );
    }

    /**
     * Redis down must RUN the tick, not skip it.
     *
     * A worker that silently stopped dispatching scheduled broadcasts because a lock
     * server blinked is a worse failure than a digest sent twice.
     */
    public function testALockServerFailureStillRunsTheTick(): void
    {
        $this->assertSame(
            TE_WORKER_TICK_LOCK_UNHELD,
            te_worker_tick_lock(null, 'chat_notify'),
            'No Redis client at all means single-process semantics — run the tick.'
        );

        $this->assertSame(
            TE_WORKER_TICK_LOCK_UNHELD,
            te_worker_tick_lock(new ExplodingRedisClient(), 'chat_notify'),
            'A throwing lock server must not stop the tick.'
        );

        // And releasing the sentinel is a harmless no-op.
        te_worker_tick_unlock(new ExplodingRedisClient(), 'chat_notify', TE_WORKER_TICK_LOCK_UNHELD);
        $this->addToAssertionCount(1);
    }

    public function testTheTtlIsShorterThanTheBroadcastCadence(): void
    {
        $this->assertLessThan(
            30,
            TE_WORKER_TICK_LOCK_TTL,
            'A crashed process must not stall the next broadcast tick by more than one beat.'
        );
    }

    /** All three ticks take a lock, and all three release it in a finally. */
    public function testEachTickTakesAndReleasesALock(): void
    {
        foreach (['chat_notify', 'chat_moderation', 'broadcast'] as $tick) {
            $this->assertStringContainsString(
                "te_worker_tick_lock(\$lockClient, '{$tick}')",
                $this->worker,
                "The {$tick} tick must take the lock."
            );
            $this->assertStringContainsString(
                "te_worker_tick_unlock(\$lockClient, '{$tick}'",
                $this->worker,
                "The {$tick} tick must release the lock."
            );
        }

        $this->assertSame(
            3,
            preg_match_all('/\}\s*finally\s*\{\s*(?:\/\/[^\n]*\n\s*)*te_worker_tick_unlock/', $this->worker),
            'Every tick must release in a finally, or one throw leaks the lock for its full TTL.'
        );
    }

    /**
     * A held lock is control flow, not an error. It must be caught ahead of the
     * Throwable arm — otherwise every skipped tick writes an error line every ten
     * seconds and buries the real ones.
     */
    public function testAHeldLockIsNotLoggedAsAnError(): void
    {
        $this->assertSame(
            3,
            substr_count($this->worker, 'catch (TeWorkerTickHeld'),
            'Each tick needs its own held-lock arm.'
        );

        foreach (['chat notification sweep error', 'moderation alert sweep error', 'scheduled broadcast sweep error'] as $line) {
            $errPos  = strpos($this->worker, $line);
            $heldPos = strrpos(substr($this->worker, 0, $errPos), 'catch (TeWorkerTickHeld');
            $this->assertNotFalse($heldPos, "No held-lock arm before '{$line}'.");
        }
    }

    // ── 4. The rate limiter ──────────────────────────────────────────────────

    /** Unset means unlimited. That is today's behaviour and must stay the default. */
    public function testNoConfiguredLimitMeansNoLimit(): void
    {
        $client = new FakeRedisClient();
        $rq = RedisQueue::withClient($client);

        $this->assertNull($rq->rateLimitFor('import_queue'), 'Only the send queues are paced.');
        $this->assertTrue($rq->rateLimitAllows('import_queue'));
        $this->assertSame(0, $rq->rateLimitConsume('import_queue'));
        $this->assertSame([], $client->store, 'An unlimited queue must not even write a key.');
    }

    public function testTheLimiterRefusesOnceTheWindowIsSpent(): void
    {
        $client = new FakeRedisClient();
        $rq = RedisQueue::withClient($client);

        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue($rq->rateLimitAllows('email_queue', 3), "Job {$i} should be allowed.");
            $rq->rateLimitConsume('email_queue', 3);
        }

        $this->assertFalse($rq->rateLimitAllows('email_queue', 3), 'The fourth job is over the cap.');
        $this->assertSame('te:rate:email_queue', array_key_first($client->store));
        $this->assertSame(60, $client->ttl['te:rate:email_queue'], 'The window must expire, or the cap is permanent.');
    }

    /**
     * THE ONE THAT MATTERS for the limiter: it SLEEPS, it does not drop.
     *
     * Exhausting the cap removes the queue from the next BRPOP. Nothing leaves
     * Redis, so the jobs are still there when the window rolls over.
     */
    public function testAnExhaustedQueueIsSkippedNotDrained(): void
    {
        $redis = new FakeRedisQueue(['email_queue' => 1]);
        $redis->push('email_queue', ['id' => 'e1']);
        $redis->push('email_queue', ['id' => 'e2']);

        $assigned = ['email_queue'];

        $first = $redis->pop(te_worker_rate_allowed_queues($redis, $assigned), 0);
        $this->assertNotNull($first);
        $redis->rateLimitConsume('email_queue');

        $this->assertSame(
            [],
            te_worker_rate_allowed_queues($redis, $assigned),
            'Over the cap, the queue is simply not offered to BRPOP.'
        );
        $this->assertSame(1, $redis->length('email_queue'), 'The second job must still be queued, not dropped.');
    }

    /** And the worker sleeps rather than spinning or failing the job. */
    public function testTheWorkerSleepsWhenEveryQueueIsRateLimited(): void
    {
        $pos = strpos($this->worker, '$popQueues = te_worker_rate_allowed_queues($queue, $queues);');
        $this->assertNotFalse($pos, 'The worker must filter its pop list through the limiter.');

        $window = substr($this->worker, $pos, 900);

        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*\$popQueues\s*===\s*\[\]\s*\)\s*\{[^}]*usleep\(/s',
            $window,
            'An exhausted assignment must sleep briefly, not spin and not discard.'
        );
        $this->assertStringNotContainsString('$queue->fail(', $window,
            'A rate limit is not a job failure.');
    }

    /** The token is spent on a real job, not on the peek. */
    public function testTheTokenIsSpentOnlyWhenAJobIsInHand(): void
    {
        $consumePos = strpos($this->worker, '$queue->rateLimitConsume($fromQueue);');
        $popPos     = strpos($this->worker, '$job = $queue->pop($popQueues, 2);');

        $this->assertNotFalse($consumePos, 'The worker must spend a token for a dequeued job.');
        $this->assertGreaterThan(
            $popPos,
            $consumePos,
            'Spending on the peek would drain the allowance on an idle system, where BRPOP usually returns nothing.'
        );
    }

    /**
     * Redis unreachable must mean NO limit.
     *
     * The limiter paces sends; it must never be the reason they stop.
     */
    public function testTheLimiterIsANoOpWhenRedisIsDown(): void
    {
        $rq = RedisQueue::withClient(new ExplodingRedisClient());

        $this->assertTrue($rq->rateLimitAllows('email_queue', 1), 'A dead Redis must not block sends.');
        $this->assertSame(0, $rq->rateLimitConsume('email_queue', 1), 'Nor must consuming throw.');
    }

    public function testTheKeyIsTheAgreedShape(): void
    {
        $this->assertSame('te:rate:email_queue', RedisQueue::rateLimitKey('email_queue'));
        $this->assertSame('te:rate:sms_queue', RedisQueue::rateLimitKey('sms_queue'));
    }

    public function testTheConfigVarsAreTheAgreedNames(): void
    {
        $this->assertSame(
            ['email_queue' => 'TE_RATE_EMAIL_PER_MIN', 'sms_queue' => 'TE_RATE_SMS_PER_MIN'],
            RedisQueue::RATE_LIMIT_ENV
        );
    }

    /** A zero or negative configured limit is "no limit", not "send nothing". */
    public function testANonPositiveLimitIsTreatedAsUnlimited(): void
    {
        $rq = RedisQueue::withClient(new FakeRedisClient());
        $this->assertNull($rq->rateLimitFor('email_queue', 0));
        $this->assertTrue($rq->rateLimitAllows('email_queue', 0));
    }
}

/** Minimal in-memory stand-in for the Predis client the lock and limiter use. */
class FakeRedisClient
{
    /** @var array<string,string|int> */
    public array $store = [];
    /** @var array<string,int> */
    public array $ttl = [];

    /** SET key value EX ttl NX */
    public function set($key, $value, ...$flags)
    {
        $upper = array_map(static fn($f) => is_string($f) ? strtoupper($f) : $f, $flags);

        if (in_array('NX', $upper, true) && array_key_exists($key, $this->store)) {
            return null;
        }

        $this->store[$key] = $value;

        $exIndex = array_search('EX', $upper, true);
        if ($exIndex !== false && isset($flags[$exIndex + 1])) {
            $this->ttl[$key] = (int) $flags[$exIndex + 1];
        }

        return 'OK';
    }

    public function get($key)
    {
        return $this->store[$key] ?? null;
    }

    public function del($keys)
    {
        foreach ((array) $keys as $k) {
            unset($this->store[$k], $this->ttl[$k]);
        }
        return 1;
    }

    public function incr($key)
    {
        $this->store[$key] = ((int) ($this->store[$key] ?? 0)) + 1;
        return $this->store[$key];
    }

    public function expire($key, $seconds)
    {
        $this->ttl[$key] = (int) $seconds;
        return 1;
    }
}

/** Every call throws — the "Redis is down" case. */
class ExplodingRedisClient extends FakeRedisClient
{
    public function set($key, $value, ...$flags)
    {
        throw new RuntimeException('connection refused');
    }
    public function get($key)
    {
        throw new RuntimeException('connection refused');
    }
    public function del($keys)
    {
        throw new RuntimeException('connection refused');
    }
    public function incr($key)
    {
        throw new RuntimeException('connection refused');
    }
    public function expire($key, $seconds)
    {
        throw new RuntimeException('connection refused');
    }
}

/**
 * In-memory stand-in for RedisQueue, exposing only what the worker loop uses:
 * pop() over a list of queues, and the rate-limit predicate.
 */
class FakeRedisQueue
{
    /** @var array<string,list<array>> */
    private array $lists = [];
    /** @var array<string,int> */
    private array $limits;
    /** @var array<string,int> */
    private array $used = [];

    public function __construct(array $limits = [])
    {
        $this->limits = $limits;
    }

    public function push(string $queue, array $payload): void
    {
        $this->lists[$queue][] = $payload;
    }

    public function length(string $queue): int
    {
        return count($this->lists[$queue] ?? []);
    }

    /** BRPOP semantics: first non-empty queue in the given order wins. */
    public function pop(array $queues, int $timeout = 2): ?array
    {
        foreach ($queues as $q) {
            if (!empty($this->lists[$q])) {
                return [$q, array_shift($this->lists[$q])];
            }
        }
        return null;
    }

    public function rateLimitAllows(string $queue): bool
    {
        if (!isset($this->limits[$queue])) {
            return true;
        }
        return ($this->used[$queue] ?? 0) < $this->limits[$queue];
    }

    public function rateLimitConsume(string $queue): int
    {
        if (!isset($this->limits[$queue])) {
            return 0;
        }
        return $this->used[$queue] = ($this->used[$queue] ?? 0) + 1;
    }
}
