<?php
/**
 * Dispatch broadcast campaigns that were scheduled for later.
 *
 * `handleSendBroadcast` has stored scheduled campaigns as status='scheduled' since
 * the feature shipped, and nothing has ever sent them. The 2026-07-06 silent-failure
 * sweep papered over that with a 400 ("Scheduled sending is not available yet") so
 * that at least the admin was told. This is the dispatcher that makes the 400
 * removable. Design and the four hazards it has to answer are in
 * docs/sms-scheduled-and-replies-scope.md Part 1 — read that, not this header, for
 * the reasoning.
 *
 * It runs as a throttled tick inside workers/queue-worker.php, NOT as its own dyno.
 * That is a cost decision, not a style one: workers/calendar-sync-scheduler.php and
 * waitlist-expiry-scheduler.php are deliberately switched off because scheduled jobs
 * do not yet justify a dyno, and a new scheduler process hits the same wall on day
 * one. A tick inside the worker that is already running costs nothing.
 *
 * ⚠️ Sharing that worker is also the constraint that shapes this file. The same
 * process drives email, SMS, imports and calendar sync, so an uncaught throw here
 * stops all four queues. `te_resolve_sms_sender` returns null for a club that
 * cleared its number and `queueSms` throws RuntimeException — a live, one-config-var
 * away failure. Every campaign is therefore dispatched inside its own try/catch and
 * a throw costs that club's campaign and nothing else.
 */

require_once __DIR__ . '/feature_flags.php';
require_once __DIR__ . '/coach_scope.php';
require_once __DIR__ . '/sms_merge.php';

/**
 * The columns migration 083 adds. All four arrive together or not at all.
 *
 * Read and written only behind te_broadcast_scheduled_columns_present(): `main` is
 * shared and deploys are by push, so this code can reach production days before the
 * migration is applied to Neon by hand, and on Postgres a missing column is 42703 —
 * a hard error that would break every broadcast for every club.
 */
const TE_BROADCAST_SCHEDULED_COLUMNS = ['body', 'html_body', 'event_id', 'failure_reason'];

/**
 * How late is too late.
 *
 * Part 1's hazard (d), the one people forget: if the worker is down for eight hours
 * a campaign scheduled for 8am fires at 6pm. "Practice is cancelled this morning"
 * arriving that evening is worse than never arriving, so past this window the
 * campaign is failed with a reason a human can read in Reporting rather than sent
 * blindly. Two hours is a starting position, not a researched number.
 */
const TE_BROADCAST_MAX_LATENESS_SECONDS = 7200;

/** Campaigns considered per tick. A cap, so one backlog cannot monopolise the loop. */
const TE_BROADCAST_DISPATCH_LIMIT = 10;

/**
 * Are the migration-083 columns live?
 *
 * One probe per process, memoised. A failed probe answers false — the degraded path
 * is always the safe one, and the degraded path here is "do nothing", which is
 * exactly the behaviour that shipped before this file existed.
 *
 * information_schema on Postgres, which is the only engine production runs and the
 * only one where a failed probe SELECT inside a transaction would poison it. The
 * SQLite branch exists for the test fixtures; there is no information_schema there.
 */
function te_broadcast_scheduled_columns_present(PDO $pdo): bool
{
    $memo = te_broadcast_probe_memo();
    if ($memo !== null) {
        return $memo;
    }

    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            $stmt = $pdo->prepare(
                "SELECT column_name FROM information_schema.columns
                  WHERE table_name = 'broadcast_campaigns'
                    AND column_name IN ('body', 'html_body', 'event_id', 'failure_reason')"
            );
            $stmt->execute();
            $found = $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
        } else {
            $found = [];
            foreach ($pdo->query('PRAGMA table_info(broadcast_campaigns)')->fetchAll(PDO::FETCH_ASSOC) as $col) {
                $found[] = $col['name'];
            }
        }
        $present = count(array_intersect(TE_BROADCAST_SCHEDULED_COLUMNS, $found))
                 === count(TE_BROADCAST_SCHEDULED_COLUMNS);
    } catch (Throwable $e) {
        error_log('te_broadcast_scheduled_columns_present: ' . $e->getMessage());
        $present = false;
    }

    te_broadcast_probe_memo($present);
    return $present;
}

/**
 * The memo cell for the probe above, in its own function purely so the tests can
 * clear it — a `static` inside the probe would be unreachable from outside.
 */
function te_broadcast_probe_memo(?bool $set = null, bool $clear = false): ?bool
{
    static $value = null;
    if ($clear) {
        $value = null;
        return null;
    }
    if ($set !== null) {
        $value = $set;
    }
    return $value;
}

/** Tests only. The probe is memoised for the life of the process. */
function te_broadcast_reset_column_probe(): void
{
    te_broadcast_probe_memo(null, true);
}

/**
 * Load resolveBroadcastRecipients without executing the gateway.
 *
 * PHP early-binds unconditional top-level functions, so requiring the gateway under
 * TE_COMMUNICATIONS_LIB_ONLY defines the resolver while skipping CORS, the headers,
 * the request dispatch and the Neon connect. When the gateway itself is the caller
 * the function already exists and this returns immediately, so there is no recursion.
 *
 * Recipients are RE-RESOLVED at dispatch, never stored at schedule time and replayed.
 * The roster at dispatch time is the audience: a family who joined the team on
 * Tuesday should get Wednesday's scheduled message, and a family who left should not.
 */
function te_broadcast_load_resolver(): void
{
    if (function_exists('resolveBroadcastRecipients')) {
        return;
    }
    if (!defined('TE_COMMUNICATIONS_LIB_ONLY')) {
        define('TE_COMMUNICATIONS_LIB_ONLY', true);
    }
    require_once __DIR__ . '/../api/communications-gateway.php';
}

/**
 * Claim one campaign, or return null if somebody else already has it.
 *
 * The claim IS the UPDATE. A SELECT that picks due rows followed by an UPDATE that
 * flips them leaves a window in which two ticks both see 'scheduled' and both send —
 * and a duplicate broadcast to a whole club cannot be unsent. Postgres evaluates the
 * WHERE and the write atomically, so exactly one caller gets a row back.
 *
 * @return array<string,mixed>|null The claimed row, already flipped to 'sending'.
 */
function te_broadcast_claim(PDO $pdo, int $campaignId): ?array
{
    $stmt = $pdo->prepare(
        "UPDATE broadcast_campaigns
            SET status = 'sending', updated_at = CURRENT_TIMESTAMP
          WHERE id = ? AND status = 'scheduled'
      RETURNING id, club_profile_id, user_id, template_id, subject, channel,
                recipient_criteria, scheduled_at, body, html_body, event_id"
    );
    $stmt->execute([$campaignId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row === false ? null : $row;
}

/**
 * Does the scheduling user still have the standing this campaign needs?
 *
 * Part 1's hazard (c): permission was checked at schedule time and enforced never,
 * so a coach who left the club between scheduling and firing still reached those
 * families. This is broadcastAuthError's rule re-derived against the database,
 * because a worker tick has no request and therefore no AuthMiddleware to ask.
 *
 * ⚠️ Standing comes from ROLE, and a revoked row is the newer fact — `active` and
 * `revoked_at` can disagree, and lib/JWT.php learned in 2026-08 that when they do
 * the revocation wins.
 *
 * @return string|null A human-readable reason to fail the campaign, or null to proceed.
 */
function te_broadcast_scheduled_scope_error(
    PDO $pdo,
    ?int $userId,
    int $clubId,
    bool $isClubWide,
    array $teamIds
): ?string {
    if ($userId === null || $userId <= 0) {
        return 'The user who scheduled this campaign is no longer on record.';
    }

    $stmt = $pdo->prepare(
        "SELECT role FROM user_club_access
          WHERE user_id = ? AND club_profile_id = ? AND active AND revoked_at IS NULL"
    );
    $stmt->execute([$userId, $clubId]);
    $roles = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: []);

    if (!$roles) {
        return 'The user who scheduled this campaign no longer has access to this club.';
    }
    if (in_array('club_admin', $roles, true)) {
        return null;
    }

    // A club-wide send reaches families a coach has no relationship with. The team
    // picker IS the coach's boundary, so widening past it is an escalation — the
    // same refusal broadcastAuthError makes at schedule time.
    if ($isClubWide) {
        return 'Only club admins can send a club-wide broadcast, and the scheduling user is not one.';
    }

    $coachTeamIds = array_map('intval', getCoachTeamIds($pdo, $userId, $clubId));
    foreach ($teamIds as $tid) {
        if (!in_array((int) $tid, $coachTeamIds, true)) {
            return 'The user who scheduled this campaign no longer has access to one or more of the selected teams.';
        }
    }

    return null;
}

/** Mark a campaign failed, with the reason a human will read in Reporting. */
function te_broadcast_mark_failed(PDO $pdo, int $campaignId, string $reason): void
{
    try {
        $stmt = $pdo->prepare(
            'UPDATE broadcast_campaigns
                SET status = ?, failure_reason = ?, updated_at = CURRENT_TIMESTAMP
              WHERE id = ?'
        );
        // Bound rather than inlined so the reason cannot be truncated by quoting,
        // and so the status literal does not have to be repeated in three places.
        $stmt->execute(['failed', mb_substr($reason, 0, 500), $campaignId]);
    } catch (Throwable $e) {
        // The campaign is already lost; losing the worker over the bookkeeping
        // would be worse.
        error_log("te_broadcast_mark_failed($campaignId): " . $e->getMessage());
    }
}

/**
 * Dispatch every campaign whose scheduled time has arrived.
 *
 * @param callable $log fn(string $line): void — the worker's echo.
 * @return array{claimed:int, sent:int, failed:int, stale:int, queued:int,
 *               skipped:int, errors:string[]}
 */
function te_broadcast_dispatch_due(PDO $pdo, EmailSendService $email, SmsSendService $sms, callable $log): array
{
    $result = ['claimed' => 0, 'sent' => 0, 'failed' => 0, 'stale' => 0,
               'queued' => 0, 'skipped' => 0, 'errors' => []];

    // A switch that skipped work must SAY it skipped, never report an empty success.
    if (!te_feature_enabled('SCHEDULED_DISPATCH')) {
        $result['feature_disabled'] = 'SCHEDULED_DISPATCH';
        return $result;
    }

    // Before 083 is applied by hand there is nowhere to read the body from, so the
    // dispatcher stands down entirely. Claiming a row we cannot send would move it
    // out of 'scheduled' and lose it.
    if (!te_broadcast_scheduled_columns_present($pdo)) {
        $result['schema_pending'] = true;
        return $result;
    }

    te_broadcast_load_resolver();

    $due = $pdo->prepare(
        "SELECT id FROM broadcast_campaigns
          WHERE status = 'scheduled'
            AND scheduled_at IS NOT NULL
            AND scheduled_at <= CURRENT_TIMESTAMP
          ORDER BY scheduled_at, id
          LIMIT " . TE_BROADCAST_DISPATCH_LIMIT
    );
    $due->execute();
    $ids = array_map('intval', $due->fetchAll(PDO::FETCH_COLUMN, 0) ?: []);

    foreach ($ids as $campaignId) {
        // ⚠️ Per campaign, and it is the point of the whole file. This tick shares a
        // process with email, SMS, imports and calendar sync; one club with no SMS
        // number must not take those down, and must not stop the campaigns behind it.
        try {
            $row = te_broadcast_claim($pdo, $campaignId);
            if ($row === null) {
                continue; // Another tick got there first. Not an error.
            }
            $result['claimed']++;

            $outcome = te_broadcast_dispatch_one($pdo, $row, $email, $sms, $log);

            $result['sent']    += $outcome['sent'];
            $result['failed']  += $outcome['failed'];
            $result['stale']   += $outcome['stale'];
            $result['queued']  += $outcome['queued'];
            $result['skipped'] += $outcome['skipped'];
            if ($outcome['error'] !== null) {
                $result['errors'][] = "campaign {$campaignId}: {$outcome['error']}";
            }
        } catch (Throwable $e) {
            $result['failed']++;
            $result['errors'][] = "campaign {$campaignId}: " . $e->getMessage();
            te_broadcast_mark_failed($pdo, $campaignId, $e->getMessage());
        }
    }

    return $result;
}

/**
 * Send one already-claimed campaign.
 *
 * Split out so the claim loop above reads as the concurrency control it is. Throws
 * are the caller's to handle — every failure this function decides for itself
 * (stale, out of scope, unresolved tags) is returned as a reason instead, because
 * those are not exceptional and the distinction matters when reading the log.
 *
 * @param array<string,mixed> $row
 * @return array{sent:int, failed:int, stale:int, queued:int, skipped:int, error:string|null}
 */
function te_broadcast_dispatch_one(PDO $pdo, array $row, EmailSendService $email, SmsSendService $sms, callable $log): array
{
    $out = ['sent' => 0, 'failed' => 0, 'stale' => 0, 'queued' => 0, 'skipped' => 0, 'error' => null];

    $campaignId = (int) $row['id'];
    $clubId     = (int) $row['club_profile_id'];
    $userId     = $row['user_id'] === null ? null : (int) $row['user_id'];
    $channel    = $row['channel'] ?: 'email';

    // Staleness is computed in PHP, not in SQL, so the comparison reads the same on
    // Postgres and in the test fixture and does not depend on interval syntax.
    $scheduledTs = strtotime((string) $row['scheduled_at'] . ' UTC');
    if ($scheduledTs === false) {
        $scheduledTs = strtotime((string) $row['scheduled_at']);
    }
    if ($scheduledTs !== false) {
        $lateBy = time() - $scheduledTs;
        if ($lateBy > TE_BROADCAST_MAX_LATENESS_SECONDS) {
            $hours  = round($lateBy / 3600, 1);
            $reason = "Not sent: this campaign was {$hours} hours late by the time the dispatcher"
                    . ' reached it, past the ' . (TE_BROADCAST_MAX_LATENESS_SECONDS / 3600)
                    . '-hour window. A time-sensitive message arriving hours after the fact is'
                    . ' worse than one that never arrives, so it was failed rather than sent.';
            te_broadcast_mark_failed($pdo, $campaignId, $reason);
            $log("scheduled broadcast {$campaignId} skipped — {$hours}h late");
            $out['failed'] = 1;
            $out['stale']  = 1;
            $out['error']  = $reason;
            return $out;
        }
    }

    $criteria       = json_decode((string) $row['recipient_criteria'], true) ?: [];
    $scope          = $criteria['scope'] ?? 'teams';
    $isClubWide     = ($scope === 'club');
    $teamIds        = $isClubWide ? [] : ($criteria['team_ids'] ?? []);
    // ⚠️ SINGULAR — 'athlete', 'guardian', 'coach'. The resolve-group endpoint in
    // recipient-search-gateway.php takes the plural forms; passing those here
    // resolves nobody and sends nothing.
    $recipientTypes = $criteria['recipient_types'] ?? ['athlete', 'guardian'];
    $excludeIds     = $criteria['exclude_ids'] ?? [];

    $scopeError = te_broadcast_scheduled_scope_error($pdo, $userId, $clubId, $isClubWide, $teamIds);
    if ($scopeError !== null) {
        te_broadcast_mark_failed($pdo, $campaignId, $scopeError);
        $log("scheduled broadcast {$campaignId} refused — {$scopeError}");
        $out['failed'] = 1;
        $out['error']  = $scopeError;
        return $out;
    }

    $recipients = resolveBroadcastRecipients(
        $pdo, $teamIds, $recipientTypes, $excludeIds, $channel, $scope, $clubId
    );

    $update = $pdo->prepare('UPDATE broadcast_campaigns SET total_recipients = ? WHERE id = ?');
    $update->execute([count($recipients), $campaignId]);

    $subject   = $row['subject'] ?? '';
    $body      = $row['body'];
    $htmlBody  = $row['html_body'];
    $templateId = $row['template_id'];
    $eventId   = $row['event_id'];

    $queued = 0;
    $skipped = 0;

    if ($channel === 'email') {
        // Same guard as the immediate path: an event-less template still has to
        // merge, or {{club_name}} ships literally.
        $needsMerge = $templateId || strpos((string) $subject . (string) $htmlBody, '{{') !== false;

        if ($needsMerge && $recipients) {
            $merge = te_broadcast_merge_service($pdo);
            foreach ($recipients as $recipient) {
                $context = [
                    'event_id'        => $eventId,
                    'athlete_id'      => $recipient['athlete_id'] ?? null,
                    'guardian_id'     => (($recipient['type'] ?? '') === 'guardian') ? ($recipient['id'] ?? null) : null,
                    'user_id'         => $userId,
                    'club_profile_id' => $clubId,
                ];
                $r = $email->queueEmail([
                    'user_id'               => $userId,
                    'club_profile_id'       => $clubId,
                    'recipients'            => [$recipient],
                    'subject'               => $merge->resolveVariables($subject, $context),
                    'html_body'             => $merge->resolveVariables($htmlBody, $context, true),
                    'body'                  => $body ? $merge->resolveVariables($body, $context) : null,
                    'template_id'           => $templateId,
                    'event_id'              => $eventId,
                    'broadcast_campaign_id' => $campaignId,
                    'team_ids'              => $teamIds,
                ]);
                $queued  += $r['queued'];
                $skipped += $r['skipped'];
            }
        } elseif ($recipients) {
            $r = $email->queueEmail([
                'user_id'               => $userId,
                'club_profile_id'       => $clubId,
                'recipients'            => $recipients,
                'subject'               => $subject,
                'html_body'             => $htmlBody,
                'body'                  => $body,
                'template_id'           => $templateId,
                'event_id'              => $eventId,
                'broadcast_campaign_id' => $campaignId,
                'team_ids'              => $teamIds,
            ]);
            $queued  = $r['queued'];
            $skipped = $r['skipped'];
        }
    } else {
        if ($recipients) {
            [$recipients, $unresolved] = resolveSmsBodies(
                $recipients,
                $body,
                strpos((string) $body, '{{') === false ? null : te_broadcast_merge_service($pdo),
                [
                    'user_id'         => $userId,
                    'club_profile_id' => $clubId,
                    'event_id'        => $eventId,
                    'team_id'         => count($teamIds) === 1 ? $teamIds[0] : null,
                ]
            );

            // The immediate path 422s on this and refuses to send. A raw {{tag}} in
            // a text cannot be unsent, and at dispatch time there is nobody to ask —
            // so fail the campaign with the tags named rather than text the club.
            if ($unresolved) {
                $reason = 'Not sent: the message still has unfilled fields ('
                        . implode(', ', $unresolved) . ') at dispatch time.';
                te_broadcast_mark_failed($pdo, $campaignId, $reason);
                $log("scheduled broadcast {$campaignId} refused — unresolved merge tags");
                $out['failed'] = 1;
                $out['error']  = $reason;
                return $out;
            }

            // ⚠️ The sender is resolved HERE, inside queueSms, not at schedule time.
            // A club that changed numbers on Tuesday sends Wednesday's blast from
            // Wednesday's number — that is what families have in their phones. A
            // club that CLEARED its number makes this throw, which is the case the
            // caller's per-campaign catch exists for.
            $r = $sms->queueSms([
                'user_id'               => $userId,
                'club_profile_id'       => $clubId,
                'recipients'            => $recipients,
                'body'                  => $body,
                'broadcast_campaign_id' => $campaignId,
                'team_ids'              => $teamIds,
            ]);
            $queued  = $r['queued'];
            $skipped = $r['skipped'];
        }
    }

    $done = $pdo->prepare(
        "UPDATE broadcast_campaigns
            SET sent_count = ?, skipped_count = ?, status = 'sent',
                sent_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
          WHERE id = ?"
    );
    $done->execute([$queued, $skipped, $campaignId]);

    // An empty audience is a real answer — a team whose families all unsubscribed —
    // and is recorded as a send of zero, not as a failure. Failing it would send an
    // admin looking for a bug that is not there.
    $log("scheduled broadcast {$campaignId} sent: {$queued} queued, {$skipped} skipped");

    $out['sent']    = 1;
    $out['queued']  = $queued;
    $out['skipped'] = $skipped;
    return $out;
}

/** Lazily built — most campaigns have no merge tags and never need one. */
function te_broadcast_merge_service(PDO $pdo)
{
    static $service = null;
    if ($service === null || $service[0] !== $pdo) {
        require_once __DIR__ . '/../services/MergeFieldService.php';
        $service = [$pdo, new MergeFieldService($pdo)];
    }
    return $service[1];
}
