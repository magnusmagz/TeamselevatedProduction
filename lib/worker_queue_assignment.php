<?php
/**
 * Which queues does THIS worker process drain, and does it run the ticks?
 *
 * One worker dyno used to drain email, SMS, imports and calendar sync plus three
 * throttled ticks in one single-threaded loop, so a 30,000-person onboarding blast
 * serialised behind everything else (docs/gotr-hierarchy-plan-2026-09.md §5). The
 * answer is more PROCESSES with an assignment, not more code in the loop.
 *
 * ⚠️ The default is the whole point of this file. Run with no arguments and no
 * environment, the worker must behave exactly as it did before this existed: all
 * four queues, ticks on. `Procfile`'s `worker:` line is unchanged, so the dyno that
 * is actually running in production takes that path.
 *
 * Assignment is NOT exclusivity. Two processes may both list email_queue; BRPOP
 * hands each job to exactly one of them. The point is throughput.
 */

/** Short name → the Redis list the worker actually pops. */
const TE_WORKER_QUEUE_ALIASES = [
    'email'    => 'email_queue',
    'sms'      => 'sms_queue',
    'import'   => 'import_queue',
    'calendar' => 'calendar_sync_queue',
];

/**
 * Every queue, in the order the single-process worker has always listed them.
 *
 * ⚠️ Order is load-bearing for BRPOP: Redis serves the queues left to right, so
 * changing it changes which queue starves under load. Keep it.
 */
const TE_WORKER_ALL_QUEUES = ['email_queue', 'sms_queue', 'import_queue', 'calendar_sync_queue'];

/**
 * Resolve the assignment from argv and the environment.
 *
 * CLI beats environment beats default, so `heroku run` can override a config var
 * without editing it.
 *
 * @param array<int,string>    $argv Raw argv (element 0 is ignored, as PHP passes it).
 * @param array<string,string> $env  Environment map (pass getenv() or a fixture).
 * @return array{queues:list<string>, ticks:bool, source:string}
 *
 * @throws InvalidArgumentException on an unrecognised queue name. Deliberately
 *         fatal: a typo that silently drained nothing would look exactly like a
 *         quiet queue, and the process would sit there consuming a dyno forever.
 */
function te_worker_parse_queue_assignment(array $argv, array $env = []): array
{
    $queuesArg = null;
    $ticksArg  = null;
    $source    = 'default';

    foreach (array_slice($argv, 1) as $arg) {
        if (!is_string($arg)) {
            continue;
        }
        if (str_starts_with($arg, '--queues=')) {
            $queuesArg = substr($arg, strlen('--queues='));
            $source = 'argv';
        } elseif (str_starts_with($arg, '--ticks=')) {
            $ticksArg = substr($arg, strlen('--ticks='));
            $source = 'argv';
        } elseif ($arg === '--queues' || $arg === '--ticks') {
            // Space-separated form is not supported on purpose: Procfile lines are
            // not shell-quoted the way people expect, and a silently-dropped value
            // would fall back to "all queues" — the failure mode this whole file
            // exists to make impossible to reach by accident.
            throw new InvalidArgumentException(
                "Worker option {$arg} needs an '=' (for example --queues=email,sms)."
            );
        }
    }

    if ($queuesArg === null && isset($env['TE_WORKER_QUEUES']) && $env['TE_WORKER_QUEUES'] !== '') {
        $queuesArg = $env['TE_WORKER_QUEUES'];
        $source = 'env';
    }
    if ($ticksArg === null && isset($env['TE_WORKER_TICKS']) && $env['TE_WORKER_TICKS'] !== '') {
        $ticksArg = $env['TE_WORKER_TICKS'];
        $source = $source === 'default' ? 'env' : $source;
    }

    return [
        'queues' => te_worker_resolve_queues($queuesArg),
        'ticks'  => te_worker_resolve_ticks($ticksArg),
        'source' => $source,
    ];
}

/**
 * Expand a `--queues=` value into real Redis list names.
 *
 * null or 'all' means every queue — that is the no-arguments default.
 *
 * @return list<string>
 */
function te_worker_resolve_queues(?string $spec): array
{
    if ($spec === null || trim($spec) === '' || strtolower(trim($spec)) === 'all') {
        return TE_WORKER_ALL_QUEUES;
    }

    $wanted = [];
    foreach (explode(',', $spec) as $piece) {
        $name = strtolower(trim($piece));
        if ($name === '') {
            continue;
        }
        if (isset(TE_WORKER_QUEUE_ALIASES[$name])) {
            $wanted[] = TE_WORKER_QUEUE_ALIASES[$name];
            continue;
        }
        // The full list name is accepted too, so a log line can be pasted back in.
        if (in_array($name, TE_WORKER_ALL_QUEUES, true)) {
            $wanted[] = $name;
            continue;
        }
        throw new InvalidArgumentException(
            "Unknown worker queue '{$piece}'. Known: "
            . implode(', ', array_keys(TE_WORKER_QUEUE_ALIASES)) . ', all.'
        );
    }

    if ($wanted === []) {
        throw new InvalidArgumentException('--queues was given but resolved to no queues.');
    }

    // De-duplicate while keeping the canonical BRPOP order, and re-index: a
    // non-sequential array is the AccessibleClubIds bug in a different costume.
    $ordered = array_values(array_filter(
        TE_WORKER_ALL_QUEUES,
        static fn(string $q): bool => in_array($q, $wanted, true)
    ));

    return $ordered;
}

/**
 * `--ticks=on|off`. Default on, so the unmodified `worker:` line keeps every tick.
 */
function te_worker_resolve_ticks(?string $spec): bool
{
    if ($spec === null || trim($spec) === '') {
        return true;
    }

    $value = strtolower(trim($spec));

    if (in_array($value, ['on', '1', 'true', 'yes'], true)) {
        return true;
    }
    if (in_array($value, ['off', '0', 'false', 'no'], true)) {
        return false;
    }

    throw new InvalidArgumentException("--ticks must be on or off, got '{$spec}'.");
}

/**
 * Drop the queues whose send rate is currently exhausted.
 *
 * ⚠️ Returns the queues that may be POPPED, never a decision to discard a job.
 * Nothing is removed from Redis here — an exhausted queue is simply not listed in
 * the next BRPOP, so its jobs sit in the list until the window rolls over. The
 * caller sleeps when this comes back empty; it must never treat an empty result as
 * "nothing to do" and mark anything failed.
 *
 * @param object       $queue  RedisQueue (or any object exposing rateLimitAllows()).
 * @param list<string> $queues The process's assignment.
 * @return list<string>
 */
function te_worker_rate_allowed_queues(object $queue, array $queues): array
{
    if (!method_exists($queue, 'rateLimitAllows')) {
        return array_values($queues);
    }

    $allowed = [];
    foreach ($queues as $q) {
        if ($queue->rateLimitAllows($q)) {
            $allowed[] = $q;
        }
    }

    return $allowed;
}
