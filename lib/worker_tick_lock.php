<?php
/**
 * A cheap, best-effort Redis lock so a tick runs in ONE worker process at a time.
 *
 * Ticks used to have exactly one process by construction — there was one worker
 * dyno. Adding a second process type makes "two workers both had ticks on" a
 * configuration mistake somebody will eventually make, and the three ticks are not
 * equally safe when that happens:
 *
 *   scheduled broadcasts — ALREADY SAFE. te_broadcast_claim() is an UPDATE whose
 *     WHERE clause is the claim ("the claim IS the UPDATE"), so two processes
 *     cannot both take a campaign. Locked here anyway for uniformity, not because
 *     it needs it.
 *
 *   chat notifications — NOT SAFE. te_chat_pending_notifications() SELECTs, the
 *     dispatcher sends, and only then does te_chat_mark_notified() upsert the
 *     watermark. Two processes overlapping in that gap both send: a family gets
 *     the same digest twice, or a push AND an email for one message.
 *
 *   moderation alerts — NOT SAFE. The high-severity path checks
 *     te_chat_admin_already_alerted() and inserts afterwards; the UNIQUE partial
 *     index on (user_id, report_id) stops the duplicate ROW but not the duplicate
 *     EMAIL, which has already been sent by the time the insert throws. The digest
 *     path has no unique constraint at all — its cadence check is MAX(sent_at).
 *
 * ⚠️ Correctness never depends on this lock. It is an optimisation against
 * duplicate work, and Redis being down must not stop a tick — a worker that
 * silently stops dispatching scheduled broadcasts because a lock server blinked is
 * a worse failure than sending a digest twice. Failure to acquire for any reason
 * other than "someone else holds it" therefore runs the tick.
 */

/**
 * Try to take the lock for one tick.
 *
 * @param object|null $client A Predis client (or anything with set()/del()), or
 *                            null when Redis is unavailable — which runs the tick.
 * @param string      $name   Tick name, e.g. 'chat_notify'.
 * @param int         $ttl    Seconds. The crash guard, NOT the tick interval — the
 *                            happy path releases explicitly in a finally.
 * @return string|null The token to release with, or null if another process holds
 *                     it. A non-null token means "you may run".
 */
function te_worker_tick_lock(?object $client, string $name, int $ttl = TE_WORKER_TICK_LOCK_TTL): ?string
{
    if ($client === null) {
        // No Redis: single-process semantics, which is what we had before.
        return TE_WORKER_TICK_LOCK_UNHELD;
    }

    $token = bin2hex(random_bytes(8));

    try {
        // SET key token NX EX ttl — one round trip, atomic, self-expiring.
        $ok = $client->set(te_worker_tick_lock_key($name), $token, 'EX', $ttl, 'NX');
    } catch (Throwable $e) {
        error_log("[Worker] tick lock unavailable for {$name}: " . $e->getMessage());
        return TE_WORKER_TICK_LOCK_UNHELD;
    }

    // Predis returns a Status object for OK and null when NX refused.
    if ($ok === null || $ok === false) {
        return null;
    }

    return $token;
}

/**
 * Release a lock, but only if we still hold it.
 *
 * The token check matters: a tick that overran its TTL must not delete the lock a
 * DIFFERENT process has since taken, which would let a third process in.
 *
 * Safe to call with the sentinel token (no Redis / degraded acquire) — it is a
 * no-op then.
 */
function te_worker_tick_unlock(?object $client, string $name, ?string $token): void
{
    if ($client === null || $token === null || $token === TE_WORKER_TICK_LOCK_UNHELD) {
        return;
    }

    $key = te_worker_tick_lock_key($name);

    try {
        // Compare-and-delete. Not a Lua script on purpose: the worst case of the
        // tiny race here is releasing a lock 25 seconds early, and every tick is
        // idempotent enough to survive that (the broadcast claim genuinely is).
        if ((string) $client->get($key) === $token) {
            $client->del([$key]);
        }
    } catch (Throwable $e) {
        error_log("[Worker] tick unlock failed for {$name}: " . $e->getMessage());
    }
}

function te_worker_tick_lock_key(string $name): string
{
    return 'te:tick:' . $name;
}

/**
 * 25 seconds: longer than any tick's normal run, shorter than the 30s broadcast
 * cadence so a crashed process cannot stall the next tick by more than one beat.
 * The chat-notify tick fires every 10s, which is fine BECAUSE the happy path
 * releases explicitly — relying on the TTL alone would throttle a single process
 * down to one sweep per 25s.
 */
const TE_WORKER_TICK_LOCK_TTL = 25;

/**
 * Returned when there is no lock server to ask. Distinct from a real token so
 * unlock knows there is nothing to delete, and distinct from null so the caller
 * still runs the tick.
 */
const TE_WORKER_TICK_LOCK_UNHELD = 'unheld';
