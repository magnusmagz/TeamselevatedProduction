<?php
/**
 * "Coach invited player" — a coach's claim on a tryout registrant (CKU R86, 8.2).
 *
 * During tryouts a coach presses one button on an athlete's row: "Invite to my
 * team". Three things follow, and they are deliberately three separate facts
 * rather than one:
 *
 *   1. A `tryout_coach_invites` row records THAT COACH wanted THAT PLAYER.
 *   2. The family is told, using the EXISTING team-invitation email
 *      (lib/Email.php::sendTeamInvitation) with registration instructions.
 *   3. The director reads back who each coach claimed, and what happened next.
 *
 * ── The row and the email are not the same fact ─────────────────────────────
 * `email_sent_at` stays NULL until an address actually accepted the mail. The
 * write is committed first and the send runs after it, outside any transaction:
 * an email cannot be rolled back, so a transport failure must not undo a
 * selection the coach has already made, and a selection that failed to write
 * must never produce a mail. The response reports the two separately and never
 * says "sent" from the row's existence — that is the Phase 2 bug class
 * (send-offers answered "Offers sent successfully" with no send anywhere in the
 * handler).
 *
 * ── Rostered is COMPUTED, never stored ──────────────────────────────────────
 * Whether the athlete has since been rostered is read at query time from
 * `tryout_offers` and `team_members`. `tryout_coach_invites.status` carries the
 * COACH-SELECTION state only ('invited' / 'declined' / 'withdrawn'). The
 * constraint also admits 'registered' but nothing here writes it and nothing
 * should start: a stored copy is a second source that drifts the first time
 * someone is rostered by hand in psql, which this team does regularly.
 *
 * ── Recipients ──────────────────────────────────────────────────────────────
 * Resolved by `te_tryout_offer_recipients()` from lib/tryout_offer_notify.php,
 * reused rather than re-derived. It is the household-combining resolution:
 * guardians ordered primary-first, deduplicated on the LOWERCASED email, with
 * `registrations.registrant_email` as a fallback only. Two guardians sharing
 * `thejones@…` get ONE mail. A second implementation of that rule is a second
 * thing to fix when it changes, and the two will disagree.
 *
 * ── Absent table ────────────────────────────────────────────────────────────
 * Every read and write here tolerates `tryout_coach_invites` not existing yet.
 * Migrations are applied to Neon by hand and `main` is shared, so this code
 * reaches production the moment any session pushes — potentially days before
 * migration 087 runs. On Postgres a query against a missing table is 42P01, a
 * hard error; the probe turns that into a 503 with a sentence, so the Tryouts
 * screen keeps working and only this feature says "not available yet". Same
 * shape as lib/program_scope.php and lib/program_ordering.php.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/Email.php';
require_once __DIR__ . '/tryout_offer_notify.php';

/** The status values this API will write. See the note above about 'registered'. */
const TE_TRYOUT_COACH_INVITE_STATUSES = ['invited', 'declined', 'withdrawn'];

/** The one sentence every path answers with while migration 087 is unapplied. */
const TE_TRYOUT_COACH_INVITE_UNAVAILABLE =
    'Coach invites are not available yet — the database migration has not been applied.';

// ============================================================================
// TABLE PROBE
// ============================================================================

/**
 * Is the `tryout_coach_invites` table live?
 *
 * Memoised per PDO instance in a WeakMap rather than by spl_object_id: object
 * ids are REUSED after an object is freed, and the test suite builds one
 * connection with the table and one without — an id-keyed cache would let the
 * first connection's answer decide the second's.
 *
 * The information_schema probe is the Postgres answer. SQLite (the test
 * database) has no information_schema at all, so that query throws and the
 * fallback asks the table directly, which is safe there precisely because
 * SQLite has no transaction for a failed statement to poison.
 */
function te_tryout_coach_invites_table_present(PDO $pdo): bool
{
    static $memo = null;
    $memo ??= new WeakMap();
    if (isset($memo[$pdo])) {
        return $memo[$pdo];
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM information_schema.tables
              WHERE table_name = 'tryout_coach_invites' LIMIT 1"
        );
        $stmt->execute();
        return $memo[$pdo] = (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        try {
            $pdo->query('SELECT 1 FROM tryout_coach_invites LIMIT 1');
            return $memo[$pdo] = true;
        } catch (Throwable $e2) {
            return $memo[$pdo] = false;
        }
    }
}

// ============================================================================
// CONTEXT
// ============================================================================

/**
 * Everything one invite's email needs, or null when the registration does not
 * resolve.
 *
 * The team is resolved from the DATABASE by id. A caller-supplied team NAME
 * would put whatever the client typed into a family's inbox.
 *
 * @return array{registration_id:int, athlete_id:?int, athlete_name:string,
 *               program_id:?int, program_name:string, club_id:?int,
 *               club_name:string, team_id:?int, team_name:?string,
 *               invite_name:string, link:string, recipients:array}|null
 */
function te_tryout_coach_invite_context(PDO $pdo, int $registrationId, $teamId = null): ?array
{
    if ($registrationId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT r.id, r.athlete_id, r.program_id, r.registrant_email,
               r.registrant_first_name, r.registrant_last_name,
               p.name AS program_name, p.club_id,
               a.first_name AS athlete_first_name,
               a.last_name  AS athlete_last_name,
               c.name AS club_name
          FROM registrations r
          LEFT JOIN programs p     ON p.id = r.program_id
          LEFT JOIN athletes a     ON a.id = r.athlete_id
          LEFT JOIN club_profile c ON c.id = p.club_id
         WHERE r.id = ?
    ");
    $stmt->execute([$registrationId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    $athleteName = trim(($row['athlete_first_name'] ?? '') . ' ' . ($row['athlete_last_name'] ?? ''));
    if ($athleteName === '') {
        // A registration can predate its athlete row.
        $athleteName = trim(($row['registrant_first_name'] ?? '') . ' ' . ($row['registrant_last_name'] ?? ''));
        $athleteName = $athleteName !== '' ? $athleteName : 'Your athlete';
    }

    $teamId = ($teamId === null || $teamId === '' || (int) $teamId <= 0) ? null : (int) $teamId;
    $teamName = null;
    $teamProgramId = null;
    if ($teamId !== null) {
        $t = $pdo->prepare("SELECT name, program_id FROM teams WHERE id = ?");
        $t->execute([$teamId]);
        $team = $t->fetch(PDO::FETCH_ASSOC);
        if ($team) {
            $teamName = ($team['name'] ?? '') !== '' ? (string) $team['name'] : null;
            $teamProgramId = ($team['program_id'] ?? null) !== null ? (int) $team['program_id'] : null;
        }
    }

    $programName = (string) ($row['program_name'] ?? 'the program');

    return [
        'registration_id' => (int) $row['id'],
        'athlete_id'      => isset($row['athlete_id']) ? (int) $row['athlete_id'] : null,
        'athlete_name'    => $athleteName,
        'program_id'      => isset($row['program_id']) ? (int) $row['program_id'] : null,
        'program_name'    => $programName,
        'club_id'         => isset($row['club_id']) ? (int) $row['club_id'] : null,
        'club_name'       => (string) ($row['club_name'] ?? 'Teams Elevated'),
        'team_id'         => $teamId,
        'team_name'       => $teamName,
        // What the email calls the thing being joined. sendTeamInvitation puts
        // this in the subject line, so it can never be blank — a coach who has
        // not picked a team yet still wants the family to hear from the club.
        'invite_name'     => $teamName ?? $programName,
        'link'            => te_tryout_coach_invite_link($pdo, $teamProgramId),
        'recipients'      => te_tryout_offer_recipients($pdo, $row),
    ];
}

/**
 * Where the family goes to register.
 *
 * A club's public registration page is keyed on `programs.embed_code`
 * (App.tsx route `/register/:embedCode`), and the program to register FOR is
 * the one the invited TEAM belongs to — not the tryout program, which they have
 * already registered for. When there is no team yet, or the team's program has
 * no embed code, the parent portal is the honest fallback: it is where the
 * family's actual next step lives, and it always exists. Never a link that
 * 404s, and never an invented one.
 */
function te_tryout_coach_invite_link(PDO $pdo, ?int $teamProgramId): string
{
    $appUrl = rtrim(Env::get('APP_URL', 'http://localhost:3003'), '/');

    if ($teamProgramId !== null && $teamProgramId > 0) {
        try {
            $stmt = $pdo->prepare("SELECT embed_code FROM programs WHERE id = ?");
            $stmt->execute([$teamProgramId]);
            $code = $stmt->fetchColumn();
            if ($code !== false && $code !== null && trim((string) $code) !== '') {
                return $appUrl . '/register/' . rawurlencode(trim((string) $code));
            }
        } catch (Throwable $e) {
            error_log('coach invite: registration link lookup failed: ' . $e->getMessage());
        }
    }

    return $appUrl . '/parent';
}

/**
 * The registration instructions, carried in the team-invitation email's
 * personal-message slot.
 *
 * Written as prose about what to do next, because the template's own copy only
 * says "accept your invitation" — the thing a family must actually do is
 * register, and an email that does not say so is the reason this button exists.
 */
function te_tryout_coach_invite_message(array $ctx, string $coachName): string
{
    $who = trim($coachName) !== '' ? trim($coachName) : 'A coach';
    $team = $ctx['invite_name'];

    return "{$who} would like {$ctx['athlete_name']} to join {$team} after "
         . "{$ctx['program_name']}. To accept, complete registration using the "
         . 'link in this email. Your place is held once registration is '
         . 'complete; reply to your club if you have any questions.';
}

/**
 * The inviting coach's name, resolved from the DATABASE against the token's
 * user id.
 *
 * Never from the request body and never from a token claim: this name goes into
 * a family's inbox as the person inviting their child, and `lib/AuthMiddleware`
 * exposes no name accessor anyway. An unnamed account falls back to the club,
 * which the caller supplies — a blank "has invited you" is worse than a
 * slightly less specific one.
 */
function te_tryout_coach_invite_coach_name(PDO $pdo, int $userId): string
{
    if ($userId <= 0) {
        return '';
    }
    try {
        $stmt = $pdo->prepare('SELECT first_name, last_name FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('coach invite: coach name lookup failed: ' . $e->getMessage());
        return '';
    }

    return $row ? trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) : '';
}

// ============================================================================
// SENDING
// ============================================================================

/**
 * Mail one family, household-deduplicated.
 *
 * Unlike lib/tryout_offer_notify.php this calls a PUBLIC method on
 * lib/Email.php — `sendTeamInvitation` is the existing template and needs no
 * access to the private transport. One Email is constructed per family so
 * `forClub()` stamps the right club on the From, which is also the one
 * construct / one forClub ratio EmailSenderTest enforces per file.
 *
 * @param callable|null $sender fn(to, teamName, invitedBy, link, message): bool
 *                              — injected by tests.
 * @return bool True only when EVERY address for the household was accepted. A
 *              family with no resolvable address is false, never true: "we told
 *              them" when we did not is the bug being avoided.
 */
function te_tryout_coach_invite_send(
    PDO $pdo,
    array $ctx,
    string $coachName,
    ?callable $sender = null
): bool {
    if (empty($ctx['recipients'])) {
        return false;
    }

    if ($sender === null) {
        $email = (new Email())->forClub($pdo, $ctx['club_id']);
        $sender = static function (string $to, string $team, string $by, string $link, string $msg) use ($email): bool {
            return (bool) $email->sendTeamInvitation($to, $team, $by, $link, $msg);
        };
    }

    $message = te_tryout_coach_invite_message($ctx, $coachName);
    $by = trim($coachName) !== '' ? trim($coachName) : $ctx['club_name'];

    $allOk = true;
    foreach ($ctx['recipients'] as $recipient) {
        try {
            $ok = (bool) $sender($recipient['email'], $ctx['invite_name'], $by, $ctx['link'], $message);
        } catch (Throwable $e) {
            error_log('coach invite: send failed for registration '
                . $ctx['registration_id'] . ': ' . $e->getMessage());
            $ok = false;
        }
        // A half-delivered household is a failure, not a rounding error.
        $allOk = $allOk && $ok;
    }

    return $allOk;
}

// ============================================================================
// WRITES
// ============================================================================

/**
 * Record the claim. Idempotent per (registration_id, invited_by).
 *
 * Pressing the button twice is an UPSERT, not a second claim — that is what the
 * unique constraint is for, and it is why `email_sent_at` survives the second
 * press: a family must not be mailed again because a coach double-clicked. A
 * re-press with a different team updates the team, which is the ordinary way a
 * coach changes their mind.
 *
 * `status` is deliberately NOT reset here. A coach re-pressing the button on a
 * registrant they had withdrawn is answered by the withdrawal being visible in
 * the director's table, not by silently erasing it.
 *
 * @return array{id:int, created:bool, email_sent_at:?string, status:string}
 */
function te_tryout_coach_invite_record(
    PDO $pdo,
    int $registrationId,
    ?int $teamId,
    int $invitedBy
): array {
    $existing = $pdo->prepare(
        'SELECT id, email_sent_at, status FROM tryout_coach_invites
          WHERE registration_id = ? AND invited_by = ?'
    );
    $existing->execute([$registrationId, $invitedBy]);
    $row = $existing->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        // Only the team moves. Touching invited_at would rewrite when the coach
        // made the selection, which is the column the director sorts on.
        $upd = $pdo->prepare('UPDATE tryout_coach_invites SET team_id = ? WHERE id = ?');
        $upd->execute([$teamId, (int) $row['id']]);

        return [
            'id'            => (int) $row['id'],
            'created'       => false,
            'email_sent_at' => $row['email_sent_at'] ?? null,
            'status'        => (string) ($row['status'] ?? 'invited'),
        ];
    }

    $ins = $pdo->prepare(
        'INSERT INTO tryout_coach_invites (registration_id, team_id, invited_by, status)
         VALUES (?, ?, ?, ?)'
    );
    $ins->execute([$registrationId, $teamId, $invitedBy, 'invited']);

    $existing->execute([$registrationId, $invitedBy]);
    $row = $existing->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'id'            => (int) ($row['id'] ?? 0),
        'created'       => true,
        'email_sent_at' => $row['email_sent_at'] ?? null,
        'status'        => (string) ($row['status'] ?? 'invited'),
    ];
}

/** Stamp the send. Called only after an address actually accepted the mail. */
function te_tryout_coach_invite_mark_sent(PDO $pdo, int $inviteId): void
{
    $stmt = $pdo->prepare(
        'UPDATE tryout_coach_invites SET email_sent_at = CURRENT_TIMESTAMP WHERE id = ?'
    );
    $stmt->execute([$inviteId]);
}

/**
 * Set the coach-selection status.
 *
 * 'registered' is refused: it is computed at read time and a stored copy would
 * drift. The caller turns false into a 400 that says so.
 */
function te_tryout_coach_invite_set_status(PDO $pdo, int $inviteId, string $status): bool
{
    if (!in_array($status, TE_TRYOUT_COACH_INVITE_STATUSES, true)) {
        return false;
    }

    $stmt = $pdo->prepare('UPDATE tryout_coach_invites SET status = ? WHERE id = ?');
    $stmt->execute([$status, $inviteId]);

    return $stmt->rowCount() > 0;
}

/** The program a coach invite belongs to, for the scope check. */
function te_tryout_coach_invite_program_id(PDO $pdo, int $inviteId): ?int
{
    if ($inviteId <= 0) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT r.program_id
           FROM tryout_coach_invites i
           JOIN registrations r ON r.id = i.registration_id
          WHERE i.id = ?'
    );
    $stmt->execute([$inviteId]);
    $programId = $stmt->fetchColumn();

    return ($programId === false || $programId === null) ? null : (int) $programId;
}

// ============================================================================
// THE DIRECTOR'S VIEW
// ============================================================================

/**
 * Every coach invite in one program, with what happened next.
 *
 * The "what happened next" columns are EXISTS subqueries, never JOINs. An
 * athlete can be on two teams and can hold several offers; joining those tables
 * would multiply the invite rows and show the director one coach's single
 * selection three times.
 *
 * `rostered` asks about the INVITED team when the coach named one, and about
 * any team when they did not — the question a director is asking is "did the
 * coach get the player", and against no particular team that degrades to "is
 * this athlete on a roster at all".
 *
 * EXISTS is wrapped in CASE ... THEN 1 ELSE 0 so the value crosses the wire as
 * an integer on both Postgres (which would send 't'/'f') and SQLite.
 *
 * @return array<int, array<string, mixed>>
 */
function te_tryout_coach_invite_list(PDO $pdo, int $programId): array
{
    if ($programId <= 0) {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT i.id,
               i.registration_id,
               i.team_id,
               i.invited_by,
               i.invited_at,
               i.email_sent_at,
               i.status,
               i.notes,
               t.name AS team_name,
               u.first_name AS coach_first_name,
               u.last_name  AS coach_last_name,
               r.athlete_id,
               r.tryout_status,
               r.tryout_number,
               a.first_name AS athlete_first_name,
               a.last_name  AS athlete_last_name,
               at.name AS assigned_team_name,
               (SELECT o.offer_type FROM tryout_offers o
                 WHERE o.registration_id = i.registration_id
                 ORDER BY o.id DESC LIMIT 1) AS offer_type,
               (SELECT o.response FROM tryout_offers o
                 WHERE o.registration_id = i.registration_id
                 ORDER BY o.id DESC LIMIT 1) AS offer_response,
               CASE WHEN EXISTS (
                   SELECT 1 FROM team_members tm
                    WHERE tm.athlete_id = r.athlete_id
                      AND tm.leave_date IS NULL
                      AND (i.team_id IS NULL OR tm.team_id = i.team_id)
               ) THEN 1 ELSE 0 END AS rostered
          FROM tryout_coach_invites i
          JOIN registrations r ON r.id = i.registration_id
          LEFT JOIN teams t    ON t.id = i.team_id
          LEFT JOIN teams at   ON at.id = r.assigned_team_id
          LEFT JOIN users u    ON u.id = i.invited_by
          LEFT JOIN athletes a ON a.id = r.athlete_id
         WHERE r.program_id = ?
         ORDER BY i.invited_at DESC, i.id DESC
    ");
    $stmt->execute([$programId]);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $coach = trim(($row['coach_first_name'] ?? '') . ' ' . ($row['coach_last_name'] ?? ''));
        $athlete = trim(($row['athlete_first_name'] ?? '') . ' ' . ($row['athlete_last_name'] ?? ''));

        $out[] = [
            'id'                 => (int) $row['id'],
            'registration_id'    => (int) $row['registration_id'],
            'athlete_id'         => $row['athlete_id'] !== null ? (int) $row['athlete_id'] : null,
            // An unnamed coach or athlete renders as "Unknown", never blank — a
            // blank cell reads as "nobody", which is a different claim.
            'athlete_name'       => $athlete !== '' ? $athlete : 'Unknown athlete',
            'tryout_number'      => $row['tryout_number'],
            'tryout_status'      => $row['tryout_status'],
            'team_id'            => $row['team_id'] !== null ? (int) $row['team_id'] : null,
            'team_name'          => $row['team_name'],
            'invited_by'         => $row['invited_by'] !== null ? (int) $row['invited_by'] : null,
            'invited_by_name'    => $coach !== '' ? $coach : 'Unknown coach',
            'invited_at'         => $row['invited_at'],
            'email_sent_at'      => $row['email_sent_at'],
            'status'             => (string) $row['status'],
            'notes'              => $row['notes'],
            'offer_type'         => $row['offer_type'],
            'offer_response'     => $row['offer_response'],
            'assigned_team_name' => $row['assigned_team_name'],
            'rostered'           => ((int) $row['rostered']) === 1,
        ];
    }

    return $out;
}
