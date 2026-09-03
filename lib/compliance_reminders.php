<?php
/**
 * Compliance reminders: who is owed one, what it says, and the record that stops
 * it being sent twice (GOTR G4, docs/gotr-hierarchy-plan-2026-09.md §4).
 *
 * Expiry in this product used to be a screen somebody had to open. A council
 * with 300 coaches cannot open 300 screens, so the certificate lapses, the coach
 * is on the field uncleared, and nobody finds out until an insurer asks. This
 * file is the sweep that mails people before that happens.
 *
 * ⚠️ SIX RULES THAT ARE NOT OPTIONAL
 *
 * 1. **THE DEDUPE IS AN INSERT, AND IT HAPPENS BEFORE THE SEND.** A reminder
 *    that goes twice to 30,000 people cannot be unsent. `compliance_reminder_log`
 *    carries a unique key per (credential, stream, threshold); we insert first
 *    and only send if the insert took. A crash between the two costs one missed
 *    reminder, which is recoverable; the other ordering costs a duplicate blast,
 *    which is not. Same reasoning as te_broadcast_claim's "the claim IS the
 *    UPDATE".
 *
 * 2. **THE DEFAULT STREAM IS `stream_id IS NULL`, and migration 093 is what
 *    makes that dedupe.** Postgres does not consider two NULLs equal, so 091's
 *    `UNIQUE (credential_id, stream_id, days_before)` admits unlimited
 *    (7, NULL, 30) rows. 093 adds the partial unique index that actually holds.
 *    Without 093 applied this file still runs — and the insert stops deduping.
 *    te_compliance_reminder_dedupe_ready() reports that, and the tick refuses to
 *    send when it is false.
 *
 * 3. **ONE EMAIL PER PERSON PER THRESHOLD, NOT PER REQUIREMENT.** A coach whose
 *    background check and CPR lapse the same week gets one mail naming both. Per
 *    requirement, the busiest people get the most mail, which is exactly
 *    backwards.
 *
 * 4. **THE SMALLEST ELIGIBLE THRESHOLD WINS.** A credential recorded 20 days
 *    before it expires is eligible for 90, 60 and 30 at once. Sending all three
 *    is three emails in one tick; sending the largest is a "90 days left" notice
 *    that is false. We send 30 — the smallest threshold the person is inside —
 *    and the larger ones are never revisited because the day count only falls.
 *
 * 5. **NO MESSAGE TEXT BEYOND REQUIREMENT NAMES.** The mail says what is
 *    expiring, when, and "sign in to see your requirements". It carries no
 *    rejection reasons, no notes, no document names, no other person's status.
 *    Same rule as the chat-notification email: a reminder is read by more
 *    people, for longer, than the screen it points at — mail forwards, sits in
 *    shared family inboxes and cannot be recalled once moderation removes the
 *    thing it quoted.
 *
 * 6. **SEND VIA lib/Email.php + ->forClub(), NEVER EmailSendService.** The
 *    latter writes a communication_log row per send (which would fill Email
 *    Reporting with reminder noise and skew every campaign metric on that page)
 *    and applies `email_suppressions` — the club's MARKETING opt-out. A coach
 *    who unsubscribed from club broadcasts must still be told their background
 *    check expires next week. Both failures are invisible.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO
 *
 * A requirement a person has never had a record against is `missing`, and the
 * absence of a `person_credentials` row is how that is normally stored. This
 * sweep does NOT synthesise rows for those, and so does not mail about them.
 * That is a guard, not an oversight: 30,000 coaches × five inherited
 * requirements is 150,000 first-tick emails about paperwork nobody has been
 * asked for yet — the same replay the chat-notification lookback window exists
 * to prevent, at ten times the size. What it does mail about is a STORED
 * `missing` row, which only exists because an admin recorded "we asked, they
 * have not got one". The never-recorded case is covered in-product by the
 * dashboard alert card, and gets a real cadence when G7 ships authored streams.
 */

require_once __DIR__ . '/compliance.php';
require_once __DIR__ . '/feature_flags.php';

/**
 * Days before expiry at which a reminder goes out, LARGEST FIRST.
 *
 * Read by te_compliance_reminder_threshold(), which walks it backwards to find
 * the smallest one the person is inside (rule 4). Changing the list changes
 * nothing already sent — the log is keyed on the number, so a threshold added
 * later fires for everybody still inside it and a threshold removed simply
 * stops.
 */
const TE_COMPLIANCE_REMINDER_THRESHOLDS = [90, 60, 30, 7];

/**
 * The `days_before` recorded for a nudge about a stored `missing` row.
 *
 * Zero, because there is no expiry date to count back from — it is not "0 days
 * left", it is "this is not about an expiry at all". It cannot collide with a
 * real threshold (none of them is 0), and it means one nudge per recorded
 * missing row, ever. A repeating cadence for missing paperwork is a stream an
 * admin authors in G7, not a number invented here.
 */
const TE_COMPLIANCE_REMINDER_MISSING_THRESHOLD = 0;

/**
 * How recently somebody must have signed in to be nudged about a missing item.
 *
 * A dormant account is not a person who will act on an email; it is an address
 * that will bounce or be ignored, and mailing 30,000 of them is how a sending
 * domain's reputation dies. Expiry reminders carry no such guard on purpose — a
 * lapsing certificate matters whether or not its holder has logged in lately,
 * and there is a real person who was verified at some point.
 */
const TE_COMPLIANCE_REMINDER_MISSING_ACTIVE_DAYS = 90;

/**
 * The most people one tick will mail.
 *
 * The tick runs every six hours, so the remainder is picked up on the next pass
 * rather than dropped — but a council that imports 5,000 coaches on a Tuesday
 * must not turn into 5,000 sends inside one worker iteration, starving email,
 * SMS, imports and calendar sync behind it. Reported in the tick's line so a cap
 * that bites is visible rather than looking like a quiet night.
 */
const TE_COMPLIANCE_REMINDER_MAX_PEOPLE_PER_TICK = 400;

/** The partial unique index migration 093 adds. Named once; probed by name. */
const TE_COMPLIANCE_REMINDER_DEDUPE_INDEX = 'idx_compliance_reminder_log_default_stream';

/**
 * Which threshold, if any, this many days to expiry falls into.
 *
 * The SMALLEST threshold the day count is inside — see rule 4. Returns null when
 * expiry is further away than the largest threshold, or already past (an expired
 * credential is not reminded about here; it is `expired` on every screen and in
 * the rollup, which is a stronger signal than an email).
 */
function te_compliance_reminder_threshold(?int $daysToExpiry): ?int
{
    if ($daysToExpiry === null || $daysToExpiry < 0) {
        return null;
    }
    $match = null;
    foreach (TE_COMPLIANCE_REMINDER_THRESHOLDS as $threshold) {
        if ($daysToExpiry <= $threshold) {
            $match = $threshold;
        }
    }
    return $match;
}

/**
 * Is the partial unique index from migration 093 actually in place?
 *
 * ⚠️ The tick refuses to send when this is false, and that refusal is the point.
 * `main` is shared and migrations are applied by hand, so this file reaches
 * production before 093 does. Running without it would not fail loudly — it
 * would insert a second (credential, NULL, 30) row without complaint and mail
 * everybody again on the next tick, and again six hours later, for as long as
 * the certificate stayed inside the window. A feature that is silently off for a
 * day is recoverable; a reminder loop is not.
 *
 * SQLite (the test suite) reports its own partial index through the same
 * catalogue query shape, so the tests exercise the real predicate rather than a
 * stub.
 */
function te_compliance_reminder_dedupe_ready(PDO $pdo): bool
{
    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    } catch (Throwable $e) {
        $driver = 'pgsql';
    }

    try {
        if ($driver === 'sqlite') {
            // PRAGMA, not a SELECT against sqlite_master: QueriedTablesExistTest
            // scans every FROM/JOIN in the runtime tree against the production
            // schema snapshot, and a catalogue table is not in it. A pragma has
            // no FROM clause and is the documented way to ask this anyway.
            $stmt = $pdo->query("PRAGMA index_list('compliance_reminder_log')");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                if (($row['name'] ?? '') === TE_COMPLIANCE_REMINDER_DEDUPE_INDEX) {
                    return true;
                }
            }
            return false;
        }
        // Schema-qualified so the same scan recognises it as a system catalogue.
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM pg_catalog.pg_indexes WHERE indexname = ?');
        $stmt->execute([TE_COMPLIANCE_REMINDER_DEDUPE_INDEX]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        error_log('te_compliance_reminder_dedupe_ready: ' . $e->getMessage());
        return false;
    }
}

/**
 * Everything owed right now, grouped into one envelope per (person, club,
 * threshold).
 *
 * Reads are done through te_compliance_status() rather than a bespoke join, so
 * the reminder sees exactly the requirement list the person's own page shows:
 * the same inheritance, the same role filtering, the same "a verified row whose
 * date has passed is expired" re-check. A second implementation of "what does
 * this person owe" is how a reminder ends up naming a requirement that no longer
 * applies to them.
 *
 * The candidate scan comes first and is one indexed read, so the expensive
 * per-person walk only happens for people who actually have something due.
 *
 * @param array $opts 'today' (YYYY-MM-DD), 'limit' (people per tick)
 * @return array<int, array{user_id:int, club_id:int, threshold:int,
 *                          items: array<int, array{credential_id:int, name:string,
 *                                                  expires_at:?string, days:?int}>}>
 */
function te_compliance_pending_reminders(PDO $pdo, array $opts = []): array
{
    if (!te_compliance_tables_present($pdo)) {
        return [];
    }

    $today = $opts['today'] ?? te_compliance_today();
    $limit = (int) ($opts['limit'] ?? TE_COMPLIANCE_REMINDER_MAX_PEOPLE_PER_TICK);

    $userIds = te_compliance_reminder_candidate_users($pdo, $today, $limit);
    if (!$userIds) {
        return [];
    }

    $sent = te_compliance_reminder_already_sent($pdo);

    $envelopes = [];
    foreach ($userIds as $userId) {
        foreach (te_compliance_user_club_ids($pdo, $userId) as $clubId) {
            $status = te_compliance_status($pdo, $userId, $clubId, $today);
            foreach ($status['requirements'] as $row) {
                $credentialId = $row['credential_id'];
                if ($credentialId === null) {
                    // No stored row: nothing to key the dedupe on, and the
                    // never-recorded case is deliberately not mailed. See the
                    // note at the top of this file.
                    continue;
                }

                if ($row['status'] === 'verified') {
                    $threshold = te_compliance_reminder_threshold($row['days_to_expiry']);
                } elseif ($row['status'] === 'missing' && $row['requirement']['required']) {
                    $threshold = TE_COMPLIANCE_REMINDER_MISSING_THRESHOLD;
                } else {
                    // `submitted` is with a reviewer and the person cannot act;
                    // `rejected` and `expired` are already loud on their own page
                    // and in the admin roll call.
                    continue;
                }

                if ($threshold === null || isset($sent[$credentialId][$threshold])) {
                    continue;
                }

                $key = $userId . ':' . $clubId . ':' . $threshold;
                $envelopes[$key] ??= [
                    'user_id'   => $userId,
                    'club_id'   => $clubId,
                    'threshold' => $threshold,
                    'items'     => [],
                ];
                $envelopes[$key]['items'][] = [
                    'credential_id' => $credentialId,
                    'name'          => (string) $row['requirement']['name'],
                    'expires_at'    => $row['expires_at'],
                    'days'          => $row['days_to_expiry'],
                ];
            }
        }
    }

    return array_values($envelopes);
}

/**
 * The people worth walking: anybody with a verified credential inside a
 * threshold window they have not already been mailed about, or a stored
 * `missing` row against a required requirement, having signed in recently.
 *
 * ⚠️ **The already-sent exclusion is IN THE QUERY, and the cap depends on it.**
 * Slicing a candidate list that still contained people with nothing owed would
 * hand the same person to every tick forever while the people behind them in the
 * list were never reached — the cap would stop being a cap and start being a
 * wall. Excluding them here means each pass advances.
 *
 * The exclusion is a NOT EXISTS per threshold BAND rather than a blanket "has
 * any log row", because a credential legitimately gets a second reminder as it
 * crosses from the 30-day band into the 7-day one.
 *
 * Bands are half-open and mirror te_compliance_reminder_threshold() exactly:
 * 0–7 days is the 7 step, 8–30 the 30 step, 31–60 the 60, 61–90 the 90. If the
 * two ever disagree, a person is either mailed twice for one band or walked on
 * every tick and never mailed at all.
 *
 * Ordered by user id and capped, so a tick that hits the cap resumes
 * deterministically rather than picking a random subset.
 *
 * @return int[]
 */
function te_compliance_reminder_candidate_users(PDO $pdo, string $today, int $limit): array
{
    $ids = [];
    $true = te_compliance_true_literal($pdo);

    // [threshold => [lowest day count, highest day count]]
    $bands = [];
    $previous = -1;
    foreach (array_reverse(TE_COMPLIANCE_REMINDER_THRESHOLDS) as $threshold) {
        $bands[$threshold] = [$previous + 1, $threshold];
        $previous = $threshold;
    }

    foreach ($bands as $threshold => [$low, $high]) {
        try {
            // One range scan per band over idx_person_credentials_expires, which
            // is partial on status = 'verified' precisely for this query.
            $stmt = $pdo->prepare(
                "SELECT DISTINCT pc.user_id
                   FROM person_credentials pc
                  WHERE pc.status = 'verified'
                    AND pc.expires_at IS NOT NULL
                    AND pc.expires_at >= ?
                    AND pc.expires_at <= ?
                    AND NOT EXISTS (
                        SELECT 1 FROM compliance_reminder_log l
                         WHERE l.credential_id = pc.id
                           AND l.days_before = ?
                           AND l.stream_id IS NULL
                    )
                  ORDER BY pc.user_id"
            );
            $stmt->execute([
                te_compliance_reminder_shift($today, $low),
                te_compliance_reminder_shift($today, $high),
                $threshold,
            ]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: [] as $id) {
                if ((int) $id > 0) {
                    $ids[(int) $id] = true;
                }
            }
        } catch (Throwable $e) {
            error_log('te_compliance_reminder_candidate_users expiring: ' . $e->getMessage());
        }
    }

    try {
        // Missing: only required requirements, only recently-active accounts,
        // only people who have not had the one nudge already.
        $cutoff = te_compliance_reminder_shift($today, -TE_COMPLIANCE_REMINDER_MISSING_ACTIVE_DAYS);
        $stmt = $pdo->prepare(
            "SELECT DISTINCT pc.user_id
               FROM person_credentials pc
               JOIN compliance_requirements r ON r.id = pc.requirement_id
               JOIN users u ON u.id = pc.user_id
              WHERE pc.status = 'missing'
                AND r.required = {$true}
                AND r.active = {$true}
                AND u.last_login_at IS NOT NULL
                AND u.last_login_at >= ?
                AND NOT EXISTS (
                    SELECT 1 FROM compliance_reminder_log l
                     WHERE l.credential_id = pc.id
                       AND l.days_before = ?
                       AND l.stream_id IS NULL
                )
              ORDER BY pc.user_id"
        );
        $stmt->execute([$cutoff, TE_COMPLIANCE_REMINDER_MISSING_THRESHOLD]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: [] as $id) {
            if ((int) $id > 0) {
                $ids[(int) $id] = true;
            }
        }
    } catch (Throwable $e) {
        error_log('te_compliance_reminder_candidate_users missing: ' . $e->getMessage());
    }

    $out = array_keys($ids);
    sort($out);
    return $limit > 0 ? array_slice($out, 0, $limit) : $out;
}

/**
 * A date-only string shifted by whole calendar days.
 *
 * DateInterval, in UTC, formatted straight back out — the same construction as
 * te_compliance_expiry_from(). Never `strtotime` on a local clock and never
 * 86,400-second arithmetic: both move the answer by a day across a DST boundary
 * for half the country, which is the bug frontend/src/utils/dateFormat.ts exists
 * to prevent on the other side.
 */
function te_compliance_reminder_shift(string $today, int $days): string
{
    $day = substr(trim($today), 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
        $day = te_compliance_today();
    }
    try {
        $date = new DateTimeImmutable($day, new DateTimeZone('UTC'));
    } catch (Throwable $e) {
        return $day;
    }
    $interval = new DateInterval('P' . abs($days) . 'D');
    return ($days < 0 ? $date->sub($interval) : $date->add($interval))->format('Y-m-d');
}

/**
 * Everything the default stream has already sent, as [credential_id][threshold].
 *
 * Read once per tick rather than per candidate: the table is small (one row per
 * reminder ever sent, and reminders are rare per person) and the alternative is
 * a query per credential inside a nested loop.
 *
 * ⚠️ This is an optimisation, NOT the dedupe. The dedupe is the unique index —
 * two workers could both read this map before either inserted. Never remove the
 * insert-first ordering in te_compliance_claim_reminder() on the grounds that
 * this map already checked.
 *
 * @return array<int, array<int, true>>
 */
function te_compliance_reminder_already_sent(PDO $pdo): array
{
    $out = [];
    try {
        $stmt = $pdo->query(
            'SELECT credential_id, days_before FROM compliance_reminder_log WHERE stream_id IS NULL'
        );
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[(int) $row['credential_id']][(int) $row['days_before']] = true;
        }
    } catch (Throwable $e) {
        error_log('te_compliance_reminder_already_sent: ' . $e->getMessage());
    }
    return $out;
}

/**
 * Claim one (credential, threshold) for the default stream.
 *
 * The INSERT is the claim. It runs BEFORE the send (rule 1) and a duplicate-key
 * failure means somebody else already has it — return false and send nothing.
 *
 * Each insert is its own statement with its own catch, so one already-claimed
 * item in an envelope does not poison a transaction and abort the rest. On
 * Postgres a failed statement inside a transaction poisons the whole thing,
 * which is why there is no transaction here at all.
 */
function te_compliance_claim_reminder(PDO $pdo, int $credentialId, int $threshold): bool
{
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO compliance_reminder_log (credential_id, stream_id, days_before, sent_at)
             VALUES (?, NULL, ?, ?)'
        );
        $stmt->execute([$credentialId, $threshold, date('Y-m-d H:i:s')]);
        return true;
    } catch (Throwable $e) {
        // A unique violation is the normal, expected outcome of a race or a
        // re-run. Anything else is logged for the same reason: either way the
        // right answer is "do not send".
        return false;
    }
}

/** Undo a claim whose send then failed, so the next tick may try again. */
function te_compliance_release_reminder(PDO $pdo, int $credentialId, int $threshold): void
{
    try {
        $pdo->prepare(
            'DELETE FROM compliance_reminder_log
              WHERE credential_id = ? AND days_before = ? AND stream_id IS NULL'
        )->execute([$credentialId, $threshold]);
    } catch (Throwable $e) {
        error_log('te_compliance_release_reminder: ' . $e->getMessage());
    }
}

/**
 * The subject and body for one envelope.
 *
 * Requirement NAMES and dates only — rule 5. No notes, no rejection reasons, no
 * document names, and nothing about anybody else. The call to action is "sign in
 * to see your requirements", because the page is the place where the state is
 * current and the mail cannot be.
 *
 * `lines` is the plain-text form, `rows` the same content as name => detail for
 * the HTML table. Both are built here rather than one being derived from the
 * other in lib/Email.php, so there is one place to read when somebody asks what
 * a reminder actually said.
 *
 * @return array{subject: string, heading: string, lines: string[],
 *               rows: array<string, string>, intro: string, cta: string}
 */
function te_compliance_reminder_copy(array $envelope): array
{
    $threshold = (int) $envelope['threshold'];
    $items = $envelope['items'];
    $count = count($items);
    $noun = $count === 1 ? 'requirement' : 'requirements';

    if ($threshold === TE_COMPLIANCE_REMINDER_MISSING_THRESHOLD) {
        $subject = $count === 1
            ? 'Action needed: 1 requirement is outstanding'
            : "Action needed: {$count} requirements are outstanding";
        $heading = 'Outstanding requirements';
        $intro = "Your club has {$count} {$noun} on file for you that we have no record of yet.";
    } else {
        $subject = $count === 1
            ? "Your requirement expires in {$threshold} days"
            : "{$count} of your requirements expire within {$threshold} days";
        $heading = $threshold === 7 ? 'Expiring this week' : "Expiring within {$threshold} days";
        $intro = "{$count} of your {$noun} " . ($count === 1 ? 'is' : 'are')
            . " due to expire. Renewing before the date keeps you cleared to take part.";
    }

    $lines = [];
    $rows = [];
    foreach ($items as $item) {
        $name = (string) $item['name'];
        if ($threshold === TE_COMPLIANCE_REMINDER_MISSING_THRESHOLD) {
            $detail = 'Not on file';
        } elseif (!empty($item['expires_at'])) {
            $detail = 'Expires ' . te_compliance_reminder_format_date($item['expires_at']);
        } else {
            $detail = 'Needs attention';
        }
        $lines[] = $name . ' — ' . $detail;
        $key = $name;
        while (array_key_exists($key, $rows)) {
            // Two tiers can name a rule the same thing; a collapsed key would
            // drop one of them from the list without saying so.
            $key .= "\u{200B}";
        }
        $rows[$key] = $detail;
    }

    return [
        'subject' => $subject,
        'heading' => $heading,
        'intro'   => $intro,
        'lines'   => $lines,
        'rows'    => $rows,
        'cta'     => 'Sign in to see your requirements',
    ];
}

/**
 * A stored 'YYYY-MM-DD' rendered for reading, without ever becoming a moment.
 *
 * The parts are split off the STRING and reassembled — `new DateTime($d)` would
 * make it UTC midnight and any formatter running in a US zone would print the
 * previous day, which is exactly the PracticeScheduler bug that reached
 * production. Falls back to the raw string rather than guessing.
 */
function te_compliance_reminder_format_date(?string $date): string
{
    if ($date === null || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', trim($date), $m)) {
        return (string) $date;
    }
    $months = ['', 'January', 'February', 'March', 'April', 'May', 'June',
               'July', 'August', 'September', 'October', 'November', 'December'];
    $month = (int) $m[2];
    return ($months[$month] ?? $m[2]) . ' ' . (int) $m[3] . ', ' . $m[1];
}

/**
 * Send everything owed. Called from the tick in workers/queue-worker.php.
 *
 * ⚠️ Every send is wrapped individually and nothing is rethrown. This runs
 * inside the worker that also drives email, SMS, imports and calendar sync; one
 * club with a broken sender must not stop the other four.
 *
 * @param array $opts 'today', 'limit', 'mailer' (callable(array $envelope,
 *                    array $copy, array $person): bool) to substitute the sender
 *                    in tests.
 * @return array{sent:int, skipped:int, failed:int, people:int, capped:bool, errors:string[]}
 */
function te_compliance_dispatch_reminders(PDO $pdo, array $opts = []): array
{
    $result = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'people' => 0, 'capped' => false, 'errors' => []];

    // ⚠️ The switches are checked HERE as well as in the worker tick.
    // A kill switch is per FEATURE, not per caller — a script or a future
    // endpoint that reaches this function must not be able to mail 30,000
    // people while the config var says the reminders are off. The tick checks
    // too, so it can skip the whole pass without opening a connection.
    //
    // Written out rather than looped over a list: FeatureFlagsTest scans this
    // file for the literal `te_feature_enabled('NAME')`, and a variable flag
    // name is invisible to it — which would leave the roll call of gated send
    // paths silently incomplete, exactly the "fixed one, missed three" shape
    // that scan exists to catch.
    if (!te_feature_enabled('COMPLIANCE')) {
        // Said out loud, never reported as a successful quiet pass.
        $result['errors'][] = 'feature_disabled: COMPLIANCE';
        return $result;
    }
    if (!te_feature_enabled('COMPLIANCE_REMINDERS')) {
        $result['errors'][] = 'feature_disabled: COMPLIANCE_REMINDERS';
        return $result;
    }

    if (!te_compliance_tables_present($pdo)) {
        $result['errors'][] = 'compliance tables are not present (migration 091 not applied)';
        return $result;
    }
    if (!te_compliance_reminder_dedupe_ready($pdo)) {
        // Rule 2. Refusing is the safe answer: the alternative is a loop.
        $result['errors'][] = 'compliance_reminder_log has no default-stream unique index '
            . '(migration 093 not applied) — refusing to send, because without it a reminder repeats every tick';
        return $result;
    }

    $limit = (int) ($opts['limit'] ?? TE_COMPLIANCE_REMINDER_MAX_PEOPLE_PER_TICK);
    $envelopes = te_compliance_pending_reminders($pdo, $opts + ['limit' => $limit]);
    if (!$envelopes) {
        return $result;
    }

    $people = [];
    foreach ($envelopes as $envelope) {
        $people[$envelope['user_id']] = true;
    }
    $result['people'] = count($people);
    $result['capped'] = $result['people'] >= $limit && $limit > 0;

    $mailer = $opts['mailer'] ?? function (array $envelope, array $copy, array $person) use ($pdo): bool {
        require_once __DIR__ . '/Email.php';
        // ->forClub, so the coach sees their own club's name in the From line
        // and recognises it. lib/Email.php, never EmailSendService — rule 6.
        $email = (new Email())->forClub($pdo, (int) $envelope['club_id']);
        return (bool) $email->sendComplianceReminder(
            (string) $person['email'],
            (string) $person['first_name'],
            $copy
        );
    };

    foreach ($envelopes as $envelope) {
        try {
            $person = te_compliance_reminder_person($pdo, (int) $envelope['user_id']);
            if ($person === null || trim((string) $person['email']) === '') {
                // No address is not a failure to retry forever; it is a person
                // who can only be reached in the product. Counted as skipped so
                // the number is visible.
                $result['skipped']++;
                continue;
            }

            // Claim BEFORE sending — rule 1. Partial claims are kept and
            // released together if the send fails.
            $claimed = [];
            foreach ($envelope['items'] as $item) {
                if (te_compliance_claim_reminder($pdo, (int) $item['credential_id'], (int) $envelope['threshold'])) {
                    $claimed[] = (int) $item['credential_id'];
                }
            }
            if (!$claimed) {
                $result['skipped']++;
                continue;
            }

            $copy = te_compliance_reminder_copy($envelope);
            $ok = $mailer($envelope, $copy, $person);

            if ($ok) {
                $result['sent']++;
            } else {
                $result['failed']++;
                foreach ($claimed as $credentialId) {
                    te_compliance_release_reminder($pdo, $credentialId, (int) $envelope['threshold']);
                }
                $result['errors'][] = sprintf(
                    'reminder to user %d (club %d, %d-day) was not accepted by the mailer',
                    $envelope['user_id'],
                    $envelope['club_id'],
                    $envelope['threshold']
                );
            }
        } catch (Throwable $e) {
            $result['failed']++;
            $result['errors'][] = sprintf(
                'reminder user %d club %d threshold %d: %s',
                $envelope['user_id'] ?? 0,
                $envelope['club_id'] ?? 0,
                $envelope['threshold'] ?? 0,
                $e->getMessage()
            );
            error_log('[ComplianceReminder] ' . end($result['errors']));
        }
    }

    return $result;
}

/** Name and address for one recipient, or null when the account has gone. */
function te_compliance_reminder_person(PDO $pdo, int $userId): ?array
{
    try {
        $stmt = $pdo->prepare('SELECT id, first_name, last_name, email FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('te_compliance_reminder_person: ' . $e->getMessage());
        return null;
    }
    return $row ?: null;
}
