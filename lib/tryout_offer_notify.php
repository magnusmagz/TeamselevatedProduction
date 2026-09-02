<?php
/**
 * Telling families about a tryout result.
 *
 * `POST registration/tryouts-api.php?path=send-offers` wrote `tryout_offers`
 * rows, flipped `registrations.tryout_status` and answered "Offers sent
 * successfully" — with no send of any kind anywhere in the handler. Staff
 * pressed the button, saw the word "sent", and families were never told. That
 * is the Phase 2 bug class: a UI that promises delivery a backend never makes.
 *
 * Everything here runs AFTER the transaction commits. An email cannot be rolled
 * back, so a send that throws must not undo an offer staff have already made;
 * conversely an offer that fails to write must never produce a mail. The two
 * halves are therefore sequential, not nested.
 *
 * ── Recipient resolution ────────────────────────────────────────────────────
 * registration -> athlete -> athlete_guardians -> guardians, ordered by the link
 * id. Crew members are EQUAL — there is no primary guardian in this product
 * (2026-09-02) — so nobody leads the greeting by rank; the order is simply
 * deterministic and independent of physical row order, which a vacuum can change.
 * Every guardian on the household is mailed. Addresses are deduplicated on the
 * LOWERCASED email (six live addresses are held by two guardians each, and
 * Postgres `=` on email is case-sensitive — the bug that left three families
 * with an empty parent portal), so John and Jane on `thejones@…` receive ONE
 * mail addressed to both rather than two identical ones.
 *
 * `registrations.registrant_email` is a FALLBACK, not an addition: it is used
 * only when the guardian chain yields no usable address at all. A registration
 * taken online before the athlete was linked to anyone is otherwise a family
 * with an offer and no way to hear about it.
 *
 * ── What this file deliberately does NOT do ─────────────────────────────────
 * It writes no `communication_log` row. None of the lib/Email.php transactional
 * paths do — magic link, password reset, parent invite, consent confirmation,
 * receipts — logging is a property of `services/EmailSendService.php`, the bulk
 * path, which also applies `email_suppressions` (the club's MARKETING opt-out).
 * Routing a tryout result through that service would both flood Email Reporting
 * and silently withhold an offer from an unsubscribed parent. Inventing a
 * one-off logging path for this one send would make it the only transactional
 * send that logs, which is a decision for the whole path, not for this slice.
 *
 * It also has no reply-by column to read: `tryout_offers` is
 * (id, registration_id, offer_type, team_id, response, notes, sent_at,
 * responded_at, created_at, updated_at). A deadline can only come from the
 * request, so it is rendered when the caller supplies `respond_by` on the offer
 * and omitted otherwise — never invented.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/Email.php';
require_once __DIR__ . '/NameFormatter.php';
require_once __DIR__ . '/suppression.php';
require_once __DIR__ . '/sms_sender.php';

// ============================================================================
// COPY
// ============================================================================

/**
 * How an offer_type reads to a parent.
 *
 * `tryout_offers.offer_type` is written by the staff UI as roster / waitlist /
 * not_selected (see the match() in send-offers). An unrecognised value gets a
 * neutral line rather than a raw slug — a family should never be shown a column
 * value — but it is still sent, because the alternative is silence.
 *
 * @return array{headline: string, body: string, sms: string}
 */
function te_tryout_offer_copy(string $offerType, string $athlete, ?string $team): array
{
    $onTeam = ($team !== null && $team !== '') ? " on {$team}" : '';

    switch ($offerType) {
        case 'roster':
            return [
                'headline' => 'A roster spot has been offered',
                'body'     => "{$athlete} has been offered a roster spot{$onTeam}.",
                'sms'      => "{$athlete} has been offered a roster spot{$onTeam}.",
            ];
        case 'waitlist':
            return [
                'headline' => 'A place on the waitlist',
                'body'     => "{$athlete} has been placed on the waitlist{$onTeam}. "
                            . 'We will be in touch if a place opens up.',
                'sms'      => "{$athlete} has been placed on the waitlist{$onTeam}.",
            ];
        case 'not_selected':
            return [
                'headline' => 'Tryout result',
                'body'     => "{$athlete} has not been selected for a place this season. "
                            . 'Thank you for trying out.',
                'sms'      => "{$athlete} has not been selected for a place this season.",
            ];
        default:
            return [
                'headline' => 'Tryout result',
                'body'     => "There is an update on {$athlete}'s tryout.",
                'sms'      => "There is an update on {$athlete}'s tryout.",
            ];
    }
}

/** The parent portal, where a family checks their athlete's status. */
function te_tryout_offer_portal_url(): string
{
    return rtrim(Env::get('APP_URL', 'http://localhost:3003'), '/') . '/parent';
}

// ============================================================================
// RESOLUTION
// ============================================================================

/**
 * Everything one offer's email needs, or null when the registration or its
 * program cannot be resolved.
 *
 * A registration that does not resolve is reported as a failure, never skipped
 * quietly — "we told 4 of 5 families" is actionable; "we told everyone" when we
 * did not is the bug being fixed.
 *
 * @return array{registration_id:int, athlete_id:?int, athlete_name:string,
 *               program_name:string, club_id:?int, club_name:string,
 *               club_email:?string, team_id:?int, team_name:?string,
 *               recipients:array}|null
 */
function te_tryout_offer_context(PDO $pdo, int $registrationId, $teamId = null): ?array
{
    if ($registrationId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT r.id, r.athlete_id, r.registrant_email,
               r.registrant_first_name, r.registrant_last_name,
               p.name  AS program_name,
               p.club_id,
               a.first_name AS athlete_first_name,
               a.last_name  AS athlete_last_name,
               c.name  AS club_name,
               c.email AS club_email
          FROM registrations r
          LEFT JOIN programs p      ON p.id = r.program_id
          LEFT JOIN athletes a      ON a.id = r.athlete_id
          LEFT JOIN club_profile c  ON c.id = p.club_id
         WHERE r.id = ?
    ");
    $stmt->execute([$registrationId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    $athleteName = trim(($row['athlete_first_name'] ?? '') . ' ' . ($row['athlete_last_name'] ?? ''));
    if ($athleteName === '') {
        // A registration can predate its athlete row. The registrant's own name
        // is the next best thing, and "your athlete" beats an empty sentence.
        $athleteName = trim(($row['registrant_first_name'] ?? '') . ' ' . ($row['registrant_last_name'] ?? ''));
        $athleteName = $athleteName !== '' ? $athleteName : 'Your athlete';
    }

    $teamName = null;
    $teamId = ($teamId === null || $teamId === '' || (int) $teamId <= 0) ? null : (int) $teamId;
    if ($teamId !== null) {
        $t = $pdo->prepare("SELECT name FROM teams WHERE id = ?");
        $t->execute([$teamId]);
        $found = $t->fetchColumn();
        $teamName = ($found === false || $found === null || $found === '') ? null : (string) $found;
    }

    return [
        'registration_id' => (int) $row['id'],
        'athlete_id'      => isset($row['athlete_id']) ? (int) $row['athlete_id'] : null,
        'athlete_name'    => $athleteName,
        'program_name'    => (string) ($row['program_name'] ?? 'the program'),
        'club_id'         => isset($row['club_id']) ? (int) $row['club_id'] : null,
        'club_name'       => (string) ($row['club_name'] ?? 'Teams Elevated'),
        'club_email'      => ($row['club_email'] ?? '') !== '' ? (string) $row['club_email'] : null,
        'team_id'         => $teamId,
        'team_name'       => $teamName,
        'recipients'      => te_tryout_offer_recipients($pdo, $row),
    ];
}

/**
 * One entry per distinct household address, primary guardian first.
 *
 * Each entry carries every guardian sharing that address, because the greeting
 * combines them ("Hi John & Jane") and the SMS side needs each person's own
 * mobile and guardian id — `te_sms_skip_reason` keys the opt-out check on the
 * guardian id, so collapsing two people into one recipient would text someone
 * who has replied STOP.
 *
 * @param array $registration The joined registration row.
 * @return array<int, array{email:string, key:string, name:string,
 *                          guardians:array, source:string}>
 */
function te_tryout_offer_recipients(PDO $pdo, array $registration): array
{
    $athleteId = isset($registration['athlete_id']) ? (int) $registration['athlete_id'] : 0;

    $rows = [];
    if ($athleteId > 0) {
        // Link id, so the order is deterministic and does not depend on physical
        // row order (which a vacuum can change). No guardian outranks another.
        $stmt = $pdo->prepare("
            SELECT g.id, g.first_name, g.last_name, g.email, g.mobile_phone
              FROM athlete_guardians ag
              JOIN guardians g ON g.id = ag.guardian_id
             WHERE ag.athlete_id = ?
             ORDER BY ag.id
        ");
        $stmt->execute([$athleteId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $byKey = [];
    foreach ($rows as $g) {
        $email = trim((string) ($g['email'] ?? ''));
        // 25 live guardians carry email = '' (an empty STRING, not NULL, which
        // is why they compare equal to each other). They are not addressable.
        if ($email === '' || strpos($email, '@') === false) {
            continue;
        }
        $key = mb_strtolower($email);

        if (!isset($byKey[$key])) {
            $byKey[$key] = [
                'email'      => $email,
                'key'        => $key,
                'name'       => '',
                'guardians'  => [],
                'source'     => 'guardian',
            ];
        }
        $byKey[$key]['guardians'][] = [
            'id'           => (int) $g['id'],
            'first_name'   => (string) ($g['first_name'] ?? ''),
            'last_name'    => (string) ($g['last_name'] ?? ''),
            'mobile_phone' => $g['mobile_phone'] ?? null,
        ];
    }

    if (!empty($byKey)) {
        foreach ($byKey as $key => $entry) {
            $byKey[$key]['name'] = NameFormatter::combineFirstNames(
                array_column($entry['guardians'], 'first_name')
            );
        }
        return array_values($byKey);
    }

    // Fallback only. Reached when the athlete has no guardian link, or every
    // linked guardian has a blank email — a family that would otherwise be
    // offered a place and never told.
    $fallback = trim((string) ($registration['registrant_email'] ?? ''));
    if ($fallback === '' || strpos($fallback, '@') === false) {
        return [];
    }

    return [[
        'email'      => $fallback,
        'key'        => mb_strtolower($fallback),
        'name'       => NameFormatter::titleCaseName((string) ($registration['registrant_first_name'] ?? '')),
        'guardians'  => [],
        'source'     => 'registrant',
    ]];
}

// ============================================================================
// RENDERING
// ============================================================================

/**
 * Subject, HTML and plain text for one household.
 *
 * There are no merge tags here and there must never be any: an unresolved
 * `{{tag}}` in a template is the reason send-time now blocks bulk sends, and a
 * transactional path has no resolver behind it to catch one.
 *
 * @return array{subject:string, html:string, text:string}
 */
function te_tryout_offer_render(array $ctx, array $recipient, ?string $respondBy = null): array
{
    $copy    = te_tryout_offer_copy((string) ($ctx['offer_type'] ?? ''), $ctx['athlete_name'], $ctx['team_name']);
    $club    = $ctx['club_name'];
    $program = $ctx['program_name'];
    $portal  = te_tryout_offer_portal_url();
    $greet   = $recipient['name'] !== '' ? $recipient['name'] : 'there';

    $subject = "{$club}: tryout result for {$ctx['athlete_name']}";

    $deadline = null;
    if ($respondBy !== null && trim($respondBy) !== '') {
        $ts = strtotime(trim($respondBy));
        // An unparseable date is dropped rather than printed raw. A wrong
        // deadline in a family's inbox cannot be recalled.
        if ($ts !== false) {
            $deadline = date('l, F j, Y', $ts);
        }
    }

    $contact = $ctx['club_email'] !== null
        ? "If you have any questions, contact {$club} at {$ctx['club_email']}."
        : "If you have any questions, contact {$club}.";

    // Deliberately NOT "click accept in the portal": there is no accept/decline
    // control on the parent portal today (tryouts-api's update-offer is staff
    // only). Promising a button that does not exist is the exact pattern this
    // workstream is removing.
    $instructions = 'Sign in to the parent portal to see this athlete\'s status, '
                  . 'and reply to your club to confirm.';

    $e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

    $teamHtml = $ctx['team_name'] !== null
        ? '<tr><td style="padding:4px 12px 4px 0;color:#666;">Team</td><td style="padding:4px 0;"><strong>' . $e($ctx['team_name']) . '</strong></td></tr>'
        : '';
    $deadlineHtml = $deadline !== null
        ? '<div class="warning"><strong>Please reply by ' . $e($deadline) . '.</strong></div>'
        : '';

    $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #12443E; color: white; padding: 30px; text-align: center; }
        .content { background: #f9f9f9; padding: 30px; }
        .button { display: inline-block; background: #12443E; color: #ffffff !important; padding: 15px 30px; text-decoration: none; border-radius: 5px; margin: 20px 0; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
        .warning { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; font-size: 14px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header"><h1>' . $e($club) . '</h1></div>
        <div class="content">
            <h2>' . $e($copy['headline']) . '</h2>
            <p>Hi ' . $e($greet) . ',</p>
            <p>' . $e($copy['body']) . '</p>
            <table cellpadding="0" cellspacing="0" style="margin:20px 0;font-size:15px;">
                <tr><td style="padding:4px 12px 4px 0;color:#666;">Athlete</td><td style="padding:4px 0;"><strong>' . $e($ctx['athlete_name']) . '</strong></td></tr>
                <tr><td style="padding:4px 12px 4px 0;color:#666;">Program</td><td style="padding:4px 0;"><strong>' . $e($program) . '</strong></td></tr>
                ' . $teamHtml . '
            </table>
            ' . $deadlineHtml . '
            <p>' . $e($instructions) . '</p>
            <p style="text-align: center;">
                <a href="' . $e($portal) . '" class="button" style="display:inline-block;background:#12443E;color:#ffffff !important;padding:15px 30px;text-decoration:none;border-radius:5px;margin:20px 0;"><span style="color:#ffffff !important;text-decoration:none;">Open the Parent Portal</span></a>
            </p>
            <p>' . $e($contact) . '</p>
            <p style="color:#999;font-size:12px;word-break:break-all;">Or copy and paste this link: ' . $e($portal) . '</p>
        </div>
        <div class="footer"><p>' . $e($club) . ' &middot; sent through Teams Elevated</p></div>
    </div>
</body>
</html>';

    $text = "Hi {$greet},\n\n"
          . $copy['body'] . "\n\n"
          . "Athlete: {$ctx['athlete_name']}\n"
          . "Program: {$program}\n"
          . ($ctx['team_name'] !== null ? "Team: {$ctx['team_name']}\n" : '')
          . ($deadline !== null ? "Please reply by {$deadline}.\n" : '')
          . "\n" . $instructions . "\n"
          . "{$portal}\n\n"
          . $contact . "\n";

    return ['subject' => $subject, 'html' => $html, 'text' => $text];
}

/** The one-line SMS for a household. Straight ASCII punctuation only. */
function te_tryout_offer_sms_body(array $ctx): string
{
    $copy = te_tryout_offer_copy((string) ($ctx['offer_type'] ?? ''), $ctx['athlete_name'], $ctx['team_name']);

    return $ctx['club_name'] . ': ' . $copy['sms']
         . ' Details in the parent portal: ' . te_tryout_offer_portal_url();
}

// ============================================================================
// SENDING
// ============================================================================

/**
 * The default email sender: a club-branded lib/Email.php instance.
 *
 * lib/Email.php exposes no generic public send — every template there is its own
 * public method and the transport (`send()`) is private. Adding one belongs in
 * that file, which another workstream owns today, so this binds a closure into
 * Email's scope rather than duplicating the SendGrid/mail() transport here. It
 * keeps working unchanged if `send()` later becomes public; when it does, this
 * body collapses to a direct call.
 *
 * The instance is built once per family so `forClub()` stamps the right club
 * name on the From — one Email construct, one forClub, which is the ratio
 * EmailSenderTest enforces per file.
 */
function te_tryout_offer_sender(PDO $pdo, ?int $clubId): callable
{
    $email = (new Email())->forClub($pdo, $clubId);

    $invoke = Closure::bind(
        function (string $to, string $subject, string $html, string $text) {
            return $this->send($to, $subject, $html, $text);
        },
        $email,
        Email::class
    );

    return static function (string $to, string $subject, string $html, string $text) use ($invoke): bool {
        return (bool) $invoke($to, $subject, $html, $text);
    };
}

/**
 * The default SMS queuer: the existing Redis-backed path, unchanged.
 *
 * `SmsSendService::queueSms` owns suppression (`te_sms_skip_reason`), phone
 * normalisation and the `communication_log` row for SMS. Nothing about that is
 * reimplemented here — this only decides who to hand it.
 *
 * Returns null when the club has no active sender in `sms_phone_numbers`.
 * There is deliberately no fallback to the platform TWILIO_FROM_NUMBER: carrier
 * STOP blocks the (from-number, recipient) pair, so a shared sender makes one
 * club's opt-out mute every other club.
 */
function te_tryout_offer_sms_queue(PDO $pdo, ?int $userId): callable
{
    return static function (array $ctx, string $body) use ($pdo, $userId): ?array {
        $clubId = $ctx['club_id'] ?? null;
        if ($clubId === null || te_resolve_sms_sender($pdo, (int) $clubId) === null) {
            return null;
        }

        $recipients = [];
        foreach ($ctx['recipients'] as $r) {
            foreach ($r['guardians'] as $g) {
                if (te_normalize_sms_phone($g['mobile_phone'] ?? null) === null) {
                    continue;
                }
                $recipients[] = [
                    'phone'      => $g['mobile_phone'],
                    'name'       => trim($g['first_name'] . ' ' . $g['last_name']),
                    'type'       => 'guardian',
                    'id'         => $g['id'],
                    'athlete_id' => $ctx['athlete_id'],
                ];
            }
        }
        if (empty($recipients)) {
            return null;
        }

        require_once __DIR__ . '/../services/SmsSendService.php';
        return (new SmsSendService($pdo))->queueSms([
            'user_id'         => $userId,
            'club_profile_id' => (int) $clubId,
            'recipients'      => $recipients,
            'body'            => $body,
            'team_ids'        => $ctx['team_id'] !== null ? [$ctx['team_id']] : [],
        ]);
    };
}

/**
 * Notify every family in a batch of offers.
 *
 * A family is `notified` when every address resolved for it was accepted by the
 * transport. A family appears in `failed` when it resolved to no address at all,
 * or when any of its addresses failed — a half-delivered household is a fact
 * staff need, not a rounding error. The two are not mutually exclusive by
 * accident: a family with two addresses, one of which bounced at the API, is
 * reported as failed and not counted as notified.
 *
 * One family's failure never stops the batch. The offers are already committed;
 * abandoning the loop would leave the rest of the club silently untold.
 *
 * @param array         $offers  The request's offers array (registration_id,
 *                               offer_type, team_id, optional respond_by).
 * @param callable|null $sender  fn(to, subject, html, text): bool — injected by tests.
 * @param callable|null $sms     fn(ctx, body): ?array — injected by tests. Null uses
 *                               the real Redis-backed queue; inject a closure
 *                               returning null to disable SMS.
 * @return array{notified:int, failed:int[], emails_sent:int, sms_queued:int, sms_skipped:int}
 */
function te_tryout_offer_notify_all(
    PDO $pdo,
    array $offers,
    ?int $userId = null,
    ?callable $sender = null,
    ?callable $sms = null
): array {
    $notified = 0;
    $failed = [];
    $emailsSent = 0;
    $smsQueued = 0;
    $smsSkipped = 0;

    // Resolved once: the default queuer holds no per-family state, and building
    // it per offer would re-query sms_phone_numbers for every family in a batch.
    $smsQueue = $sms ?? te_tryout_offer_sms_queue($pdo, $userId);

    foreach ($offers as $offer) {
        $registrationId = (int) ($offer['registration_id'] ?? 0);

        try {
            $ctx = te_tryout_offer_context($pdo, $registrationId, $offer['team_id'] ?? null);
        } catch (Throwable $e) {
            error_log("tryout offer notify: context failed for registration {$registrationId}: " . $e->getMessage());
            $ctx = null;
        }

        if ($ctx === null || empty($ctx['recipients'])) {
            $failed[] = $registrationId;
            continue;
        }

        $ctx['offer_type'] = (string) ($offer['offer_type'] ?? '');

        // Built per family so the From name is the club that owns the program.
        $send = $sender ?? te_tryout_offer_sender($pdo, $ctx['club_id']);

        $familyOk = true;
        foreach ($ctx['recipients'] as $recipient) {
            $mail = te_tryout_offer_render($ctx, $recipient, $offer['respond_by'] ?? null);
            try {
                $ok = (bool) $send($recipient['email'], $mail['subject'], $mail['html'], $mail['text']);
            } catch (Throwable $e) {
                error_log("tryout offer notify: send failed for registration {$registrationId}: " . $e->getMessage());
                $ok = false;
            }
            if ($ok) {
                $emailsSent++;
            } else {
                $familyOk = false;
            }
        }

        if ($familyOk) {
            $notified++;
        } else {
            $failed[] = $registrationId;
        }

        // SMS is best-effort and never changes the email verdict. A club with no
        // number, a guardian who replied STOP, or Redis being unreachable are all
        // reasons the text did not go — none is a reason to report the email as
        // failed. Inject a queuer that returns null to disable SMS.
        try {
            $result = $smsQueue($ctx, te_tryout_offer_sms_body($ctx));
            if ($result === null) {
                $smsSkipped++;
            } else {
                $smsQueued  += (int) ($result['queued'] ?? 0);
                $smsSkipped += (int) ($result['skipped'] ?? 0);
            }
        } catch (Throwable $e) {
            error_log("tryout offer notify: SMS failed for registration {$registrationId}: " . $e->getMessage());
            $smsSkipped++;
        }
    }

    return [
        'notified'    => $notified,
        'failed'      => array_values(array_unique($failed)),
        'emails_sent' => $emailsSent,
        'sms_queued'  => $smsQueued,
        'sms_skipped' => $smsSkipped,
    ];
}
