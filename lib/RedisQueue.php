<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/env.php';

class RedisQueue {
    private static $instance = null;
    private $client;

    /**
     * Singleton pattern matching Database class.
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Build an instance around an already-made client.
     *
     * Exists so the rate limiter can be exercised without a Redis server (and so
     * a caller can hand in a client it already has). Deliberately NOT the
     * singleton — nothing in production should reach for this.
     */
    public static function withClient($client) {
        $instance = new self($client);
        return $instance;
    }

    private function __construct($client = null) {
        if ($client !== null) {
            $this->client = $client;
            return;
        }

        // Parse REDIS_URL from env (Heroku format: rediss://:password@host:port)
        // Note: 'rediss://' = TLS, 'redis://' = plain
        $redisUrl = Env::get('REDIS_URL');
        if (!$redisUrl) {
            throw new RuntimeException('REDIS_URL environment variable is not set');
        }

        $parsed = parse_url($redisUrl);
        $options = [
            'scheme' => strpos($redisUrl, 'rediss://') === 0 ? 'tls' : 'tcp',
            'host'   => $parsed['host'],
            'port'   => $parsed['port'] ?? 6379,
        ];

        // Password may be prefixed with ':' in Heroku URL format
        if (!empty($parsed['pass'])) {
            $options['password'] = ltrim($parsed['pass'], ':');
        }

        // For TLS (Heroku Redis), disable peer verification
        if ($options['scheme'] === 'tls') {
            $options['ssl'] = ['verify_peer' => false, 'verify_peer_name' => false];
        }

        $this->client = new Predis\Client($options);
    }

    /**
     * Push a job onto a queue.
     *
     * @param string $queue   Queue name (e.g. 'email_queue', 'sms_queue')
     * @param array  $payload Job payload (will be JSON-encoded)
     * @return int Number of items in the queue after push
     */
    public function push($queue, array $payload) {
        return $this->client->lpush($queue, [json_encode($payload)]);
    }

    /**
     * Pop a job from one or more queues (blocking).
     *
     * @param string|array $queues  Queue name or array of queue names
     * @param int          $timeout Block timeout in seconds (0 = block forever)
     * @return array|null [queueName, decodedPayload] or null on timeout
     */
    public function pop($queues, $timeout = 2) {
        if (is_string($queues)) {
            $queues = [$queues];
        }

        $result = $this->client->brpop($queues, $timeout);

        if ($result === null) {
            return null;
        }

        // brpop returns [queueName, value]
        return [$result[0], json_decode($result[1], true)];
    }

    /**
     * Schedule a job for retry with a delay.
     * Uses a sorted set ({$queue}:retry) with score = unix timestamp when it should be re-queued.
     *
     * @param string $queue    Original queue name
     * @param array  $payload  Job payload
     * @param int    $delaySec Delay in seconds before retry
     */
    public function retry($queue, array $payload, $delaySec) {
        $retryAt = time() + $delaySec;
        $this->client->zadd("{$queue}:retry", [$retryAt => json_encode($payload)]);
    }

    /**
     * Sweep the retry sorted set and move any due jobs back to the main queue.
     *
     * @param string $queue Queue name
     * @return int Number of jobs moved back to the queue
     */
    public function sweepRetries($queue) {
        $retryKey = "{$queue}:retry";
        $now = time();

        // Get all jobs that are due (score <= now)
        $dueJobs = $this->client->zrangebyscore($retryKey, 0, $now);

        $moved = 0;
        foreach ($dueJobs as $job) {
            // Remove from retry set and push back to main queue atomically
            $removed = $this->client->zrem($retryKey, $job);
            if ($removed > 0) {
                $this->client->lpush($queue, [$job]);
                $moved++;
            }
        }

        return $moved;
    }

    /**
     * Move a permanently failed job to the failed_jobs list.
     *
     * @param array  $payload Original job payload
     * @param string $error   Error message
     */
    public function fail(array $payload, $error) {
        $failedJob = [
            'payload'   => $payload,
            'error'     => $error,
            'failed_at' => date('c'),
        ];
        $this->client->lpush('failed_jobs', [json_encode($failedJob)]);
    }

    /**
     * Get the number of pending jobs in a queue.
     *
     * @param string $queue Queue name
     * @return int
     */
    public function getQueueLength($queue) {
        return $this->client->llen($queue);
    }

    /**
     * Get the number of permanently failed jobs.
     *
     * @return int
     */
    public function getFailedCount() {
        return $this->client->llen('failed_jobs');
    }

    /**
     * Per-queue send rate limit, in jobs per minute, or null for no limit.
     *
     * Unset means UNLIMITED, which is today's behaviour and therefore the default:
     * a limiter that has to be switched off to restore normal running is a limiter
     * that will one day throttle a club's kickoff blast because nobody knew it was
     * there.
     *
     * @param string   $queue    Redis list name, e.g. 'email_queue'.
     * @param int|null $override Explicit limit, bypassing the environment.
     * @return int|null
     */
    public function rateLimitFor($queue, $override = null) {
        if ($override !== null) {
            return $override > 0 ? (int) $override : null;
        }

        $envKey = self::RATE_LIMIT_ENV[$queue] ?? null;
        if ($envKey === null) {
            return null;
        }

        $raw = Env::get($envKey);
        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            return null;
        }

        $limit = (int) $raw;

        return $limit > 0 ? $limit : null;
    }

    /**
     * May a job be taken from this queue right now?
     *
     * Reads the bucket WITHOUT spending a token, because the worker BRPOPs several
     * queues at once and usually gets nothing — spending on a peek would drain the
     * allowance on an idle system. The token is spent by rateLimitConsume() once a
     * job is actually in hand.
     *
     * ⚠️ Redis unavailable, or no limit configured, is ALWAYS true. Sends must
     * never be blocked by the thing that is supposed to pace them.
     *
     * @return bool
     */
    public function rateLimitAllows($queue, $override = null) {
        $limit = $this->rateLimitFor($queue, $override);
        if ($limit === null) {
            return true;
        }

        try {
            $used = $this->client->get(self::rateLimitKey($queue));
        } catch (Throwable $e) {
            error_log('[RedisQueue] rate limit read failed for ' . $queue . ': ' . $e->getMessage());
            return true;
        }

        return ((int) $used) < $limit;
    }

    /**
     * Spend one token for a job just dequeued.
     *
     * A fixed window anchored on the first job of the window: INCR, then EXPIRE on
     * the transition to 1. Cheaper than a sliding log and accurate enough for a
     * provider rate limit, whose only real question is "how many in the last
     * minute".
     *
     * @return int Tokens used in the current window (0 when unlimited).
     */
    public function rateLimitConsume($queue, $override = null) {
        if ($this->rateLimitFor($queue, $override) === null) {
            return 0;
        }

        $key = self::rateLimitKey($queue);

        try {
            $used = (int) $this->client->incr($key);
            if ($used === 1) {
                $this->client->expire($key, self::RATE_LIMIT_WINDOW_SECONDS);
            }
            return $used;
        } catch (Throwable $e) {
            error_log('[RedisQueue] rate limit consume failed for ' . $queue . ': ' . $e->getMessage());
            return 0;
        }
    }

    /** te:rate:<queue> — one key per queue, per window. */
    public static function rateLimitKey($queue) {
        return 'te:rate:' . $queue;
    }

    /** Which config var paces which queue. Only the send queues have one. */
    const RATE_LIMIT_ENV = [
        'email_queue' => 'TE_RATE_EMAIL_PER_MIN',
        'sms_queue'   => 'TE_RATE_SMS_PER_MIN',
    ];

    const RATE_LIMIT_WINDOW_SECONDS = 60;

    /**
     * Get the underlying Predis client (for advanced use).
     *
     * @return Predis\Client
     */
    public function getClient() {
        return $this->client;
    }
}
