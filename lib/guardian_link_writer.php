<?php
/**
 * Write `user_guardians` links at the moment the account↔guardian relationship is
 * created, rather than re-deriving it from two email strings on every request.
 *
 * Phase 3 (part 2) of docs/user-guardians-identity-plan.md. Part 1 was
 * api/crew-link.php — the club-admin repair tool for families the email match cannot
 * reach at all. This file covers the two ordinary sources, so that new families stop
 * arriving in the state the repair tool exists to fix:
 *
 *   1. INVITE ACCEPT   — a Crew invite is redeemed and the account is resolved.
 *   2. REGISTRATION    — a public sign-up whose registrant email already has an account.
 *
 * ⚠️ THIS MUST LAND BEFORE THE EMAIL FALLBACK IS RETIRED (phase 4), not after.
 * 194 guardian emails have no account yet. Dropping the fallback first and adding
 * these writers second would land every newly-accepted family in an empty portal —
 * the exact bug this project exists to end, reintroduced by its own rollout.
 *
 * ⚠️ AMBIGUITY IS NEVER GUESSED. Six production addresses carry two guardian rows
 * (John & Jane Jones on `thejones@…`, Morgan & Zach Powell on `morganbmiles@…`, four
 * more), and `users.email` is UNIQUE, so those households have ONE account between
 * them. When only an address is known, a link is written only when it resolves to
 * exactly one guardian. Two candidates writes nothing and says so: a wrong row here
 * is a child-data disclosure, and unlike today's derived access it would survive the
 * one repair that works — correcting the address.
 *
 * ⚠️ A LINK IS AN ASSERTION, AND IT OUTLIVES THE STRINGS IT CAME FROM.
 * docs/user-guardians-identity-plan.md §1 and §5: today access through a shared
 * address is derived, so correcting the guardian's email removes it the same second.
 * A row does not go away on its own, and nothing prompts anyone to remove it. That is
 * why `source` is stored per-call rather than defaulted, why every write is audited
 * twice (the migration-072 trigger plus AuditLogger), and why the registration path
 * below is deliberately the narrowest of the three writers.
 *
 * ⚠️ `user_guardians.guardian_id` is a `guardians(id)`, like
 * `athlete_guardians.guardian_id`. `consent_records.guardian_id` is a `users(id)` —
 * the outlier. Never join those two because they share a name.
 */

require_once __DIR__ . '/guardian_identity.php';
require_once __DIR__ . '/db_actor.php';
require_once __DIR__ . '/AuditLogger.php';

/**
 * Vocabulary from migration 072.
 *
 * source     — backfill_email | invite_accept | admin_link | registration
 * confidence — exact | household | manual
 *
 * Both writers here record `exact`: either the caller handed us the specific guardian
 * the invite/registration was about, or the address resolved to exactly one guardian
 * row. Nothing in this file ever writes `household` — that is the judgement the plan
 * holds for a human, and the five remaining shared-address accounts are on that list.
 */
const TE_GUARDIAN_LINK_SOURCE_INVITE = 'invite_accept';
const TE_GUARDIAN_LINK_SOURCE_REGISTRATION = 'registration';
const TE_GUARDIAN_LINK_CONFIDENCE_EXACT = 'exact';

/** Outcomes. `linked` is the only one that wrote a row. */
const TE_GUARDIAN_LINK_LINKED = 'linked';
const TE_GUARDIAN_LINK_ALREADY_LINKED = 'already_linked';
const TE_GUARDIAN_LINK_AMBIGUOUS = 'ambiguous_email';
const TE_GUARDIAN_LINK_NO_GUARDIAN = 'no_guardian';
const TE_GUARDIAN_LINK_NO_ACCOUNT = 'no_account';
const TE_GUARDIAN_LINK_INVALID = 'invalid_input';
const TE_GUARDIAN_LINK_FAILED = 'write_failed';

/**
 * The result shape every function in this file returns.
 *
 * Spelled out rather than returning a bool because the non-writing answers are the
 * interesting ones: `ambiguous_email` is a household that needs a human, `no_account`
 * is a family who has not signed up yet, `no_guardian` is a staff-only account. A
 * boolean collapses all three into "false", which is how "no athletes are registered
 * to you" became indistinguishable from an error in the first place.
 *
 * @param int[] $candidates guardian ids considered, for the ambiguous case
 * @return array{outcome:string, user_id:?int, guardian_id:?int, candidates:int[], source:?string}
 */
function te_guardian_link_result(
    string $outcome,
    ?int $userId = null,
    ?int $guardianId = null,
    array $candidates = [],
    ?string $source = null
): array {
    return [
        'outcome' => $outcome,
        'user_id' => $userId,
        'guardian_id' => $guardianId,
        'candidates' => array_values(array_map('intval', $candidates)),
        'source' => $source,
    ];
}

/**
 * Guardian rows an address resolves to, refusing to choose between them.
 *
 * `te_guardian_ids_for_email()` is the resolver's own answer — guardians carrying the
 * address, UNION guardians already linked to an account carrying it — so this stays
 * consistent with what every read path believes. It is filtered to guardians that
 * still exist because the FK will reject anything else, and a rejected INSERT here
 * would surface as a 500 on a family's registration.
 *
 * @return array{guardian_id:?int, candidates:int[]}
 */
function te_resolve_single_guardian_for_email(PDO $pdo, string $email): array
{
    $email = trim($email);
    if ($email === '') {
        return ['guardian_id' => null, 'candidates' => []];
    }

    $candidates = te_guardian_ids_for_email($pdo, $email);
    $candidates = array_values(array_filter(
        $candidates,
        static fn(int $id): bool => te_guardian_row_exists($pdo, $id)
    ));

    return [
        'guardian_id' => count($candidates) === 1 ? (int) $candidates[0] : null,
        'candidates' => $candidates,
    ];
}

/** Does this guardians(id) still exist? The FK will ask the same question. */
function te_guardian_row_exists(PDO $pdo, int $guardianId): bool
{
    if ($guardianId <= 0) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT 1 FROM guardians WHERE id = :gid LIMIT 1');
    $stmt->execute([':gid' => $guardianId]);

    return $stmt->fetch() !== false;
}

/**
 * The account holding this address, if there already is one. Case-insensitive, because
 * `users.email` and `guardians.email` differ by capitalisation on three live accounts
 * and `=` is case-sensitive in Postgres (Emily Govier, 2026-08-18).
 *
 * ⚠️ NEVER CREATES A ROW. `users.email` is UNIQUE, so creating an account from a public
 * form is how a child's shell ended up owning a parent's address for 33 families
 * (migration 067). A registration is not an invitation.
 */
function te_existing_user_id_for_email(PDO $pdo, string $email): ?int
{
    $email = trim($email);
    if ($email === '') {
        return null;
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1');
    $stmt->execute([':email' => $email]);
    $id = $stmt->fetchColumn();

    return ($id === false || $id === null) ? null : (int) $id;
}

/**
 * Write one link, idempotently.
 *
 * Idempotence has two halves and both are needed. The pre-check makes
 * `already_linked` a reportable answer instead of a silent no-op, and the
 * `ON CONFLICT DO NOTHING` makes the write safe when two requests race — an invite
 * accepted twice, a registration double-submitted.
 *
 * **DO NOTHING, never DO UPDATE.** A row written by a club admin
 * (`admin_link`/`manual`) or by an earlier accept must not be rewritten by a later,
 * weaker source: `source` and `confidence` are the record of how the assertion came to
 * exist, and overwriting them destroys the only evidence available the day a wrong
 * link surfaces.
 *
 * `te_db_set_actor()` runs BEFORE the INSERT because migration 072's trigger reads
 * `app.user_id` at insert time and has no other way to learn who is acting. A NULL
 * actor is honest on the public registration path — nobody was signed in — and is a
 * meaningful signal rather than a gap.
 *
 * @param int|null $actorId users(id) of whoever caused this, or null for a public path.
 */
function te_write_guardian_link(
    PDO $pdo,
    int $userId,
    int $guardianId,
    string $source,
    ?int $actorId = null,
    string $confidence = TE_GUARDIAN_LINK_CONFIDENCE_EXACT,
    array $auditContext = []
): array {
    if ($userId <= 0 || $guardianId <= 0) {
        return te_guardian_link_result(TE_GUARDIAN_LINK_INVALID, $userId ?: null, $guardianId ?: null, [], $source);
    }

    if (!te_guardian_row_exists($pdo, $guardianId)) {
        return te_guardian_link_result(TE_GUARDIAN_LINK_NO_GUARDIAN, $userId, null, [], $source);
    }

    $existing = $pdo->prepare(
        'SELECT id FROM user_guardians WHERE user_id = :uid AND guardian_id = :gid LIMIT 1'
    );
    $existing->execute([':uid' => $userId, ':gid' => $guardianId]);
    $existingId = $existing->fetchColumn();

    if ($existingId !== false && $existingId !== null) {
        // Deliberately untouched. See the "DO NOTHING, never DO UPDATE" note above.
        return te_guardian_link_result(TE_GUARDIAN_LINK_ALREADY_LINKED, $userId, $guardianId, [$guardianId], $source);
    }

    try {
        te_db_set_actor($pdo, $actorId);

        $ins = $pdo->prepare('
            INSERT INTO user_guardians (user_id, guardian_id, source, confidence, linked_by)
            VALUES (:uid, :gid, :source, :confidence, :by)
            ON CONFLICT (user_id, guardian_id) DO NOTHING
        ');
        $ins->execute([
            ':uid' => $userId,
            ':gid' => $guardianId,
            ':source' => $source,
            ':confidence' => $confidence,
            // linked_by names a person who chose. Nobody chose on these paths — the
            // invite or the form did — so it stays NULL and `source` carries the story.
            ':by' => null,
        ]);
    } catch (Throwable $e) {
        // A link is bookkeeping about a relationship that already exists in
        // athlete_guardians. It must never be the reason a family's registration or
        // account setup fails; the email fallback still answers for them until phase 4.
        error_log('te_write_guardian_link: ' . $e->getMessage());
        return te_guardian_link_result(TE_GUARDIAN_LINK_FAILED, $userId, $guardianId, [$guardianId], $source);
    }

    // Read the id back rather than asking PDO for it. On Postgres `lastInsertId()`
    // with no argument is `lastval()`, and migration 072's audit trigger has just
    // inserted an `audit_log` row on the way out of this statement — so lastval()
    // answers with the audit row's id, not the link's.
    $idStmt = $pdo->prepare('SELECT id FROM user_guardians WHERE user_id = :uid AND guardian_id = :gid');
    $idStmt->execute([':uid' => $userId, ':gid' => $guardianId]);
    $linkId = (int) $idStmt->fetchColumn();

    AuditLogger::log(
        $pdo,
        $actorId,
        'guardian_account_linked',
        'user_guardians',
        $linkId ?: null,
        array_merge([
            'user_id' => $userId,
            'guardian_id' => $guardianId,
            'source' => $source,
            'confidence' => $confidence,
        ], $auditContext)
    );

    return te_guardian_link_result(TE_GUARDIAN_LINK_LINKED, $userId, $guardianId, [$guardianId], $source);
}

/**
 * Called once the invited account exists and the invite has been redeemed.
 *
 * `$guardianId` is passed when the caller knows which guardian the invite was minted
 * for — `parentInvite_ensureUserAndToken()` is handed one, so any caller that still
 * has it in scope should pass it and skip the address entirely. When only the address
 * is available (the `:parent_invite` token carries `<email>:parent_invite` and nothing
 * else), it resolves through the exactly-one rule and refuses a household.
 *
 * The actor is the accepting user themselves. They are the person who acted, and
 * attributing their own account setup to nobody would waste the one column that says
 * a link had a human behind it.
 */
function te_link_guardian_on_accept(
    PDO $pdo,
    int $userId,
    ?int $guardianId,
    string $inviteEmail
): array {
    if ($userId <= 0) {
        return te_guardian_link_result(TE_GUARDIAN_LINK_INVALID, null, $guardianId, [], TE_GUARDIAN_LINK_SOURCE_INVITE);
    }

    $candidates = [];
    if ($guardianId === null || $guardianId <= 0) {
        $resolved = te_resolve_single_guardian_for_email($pdo, $inviteEmail);
        $candidates = $resolved['candidates'];
        $guardianId = $resolved['guardian_id'];

        if ($guardianId === null) {
            // Nothing to link, and the two reasons are not the same problem. No
            // guardian at all is an ordinary staff/coach invite; more than one is a
            // household that needs a club admin to choose (api/crew-link.php).
            $outcome = count($candidates) > 1
                ? TE_GUARDIAN_LINK_AMBIGUOUS
                : TE_GUARDIAN_LINK_NO_GUARDIAN;

            return te_guardian_link_result($outcome, $userId, null, $candidates, TE_GUARDIAN_LINK_SOURCE_INVITE);
        }
    }

    return te_write_guardian_link(
        $pdo,
        $userId,
        (int) $guardianId,
        TE_GUARDIAN_LINK_SOURCE_INVITE,
        $userId,
        TE_GUARDIAN_LINK_CONFIDENCE_EXACT,
        ['invite_email' => trim($inviteEmail), 'candidates' => $candidates]
    );
}

/**
 * Called after a public registration has COMMITTED, for the guardian row the form just
 * created or matched.
 *
 * Two deliberate narrowings:
 *
 *  - **It never creates a users row.** A public form must not mint an account; that is
 *    what invites are for, and `users.email` being UNIQUE makes a wrong one permanent.
 *    No account yet is the common case (194 guardian emails have none) and is reported
 *    as `no_account`, not an error.
 *  - **The actor is NULL.** A public sign-up has no operator, and CLAUDE.md is explicit
 *    that NULL is the honest record there rather than a gap to fill in.
 *
 * The caller passes the guardian id it already resolved, so the household ambiguity
 * that stops the invite path does not arise: the form said which adult it was about.
 */
function te_link_guardian_on_registration(
    PDO $pdo,
    int $guardianId,
    string $registrantEmail
): array {
    if ($guardianId <= 0) {
        return te_guardian_link_result(TE_GUARDIAN_LINK_INVALID, null, null, [], TE_GUARDIAN_LINK_SOURCE_REGISTRATION);
    }

    $userId = te_existing_user_id_for_email($pdo, $registrantEmail);
    if ($userId === null) {
        return te_guardian_link_result(
            TE_GUARDIAN_LINK_NO_ACCOUNT,
            null,
            $guardianId,
            [$guardianId],
            TE_GUARDIAN_LINK_SOURCE_REGISTRATION
        );
    }

    return te_write_guardian_link(
        $pdo,
        $userId,
        $guardianId,
        TE_GUARDIAN_LINK_SOURCE_REGISTRATION,
        null,
        TE_GUARDIAN_LINK_CONFIDENCE_EXACT,
        ['registrant_email' => trim($registrantEmail)]
    );
}
