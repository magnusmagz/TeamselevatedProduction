<?php
/**
 * Athlete write helpers.
 *
 * Extracted from legacy/athletes-gateway.php so the create path is unit-testable
 * and shared. Callers manage their own transaction (these functions participate in
 * the caller's transaction and do NOT commit/rollback).
 */

require_once __DIR__ . '/jersey_size.php';

if (!function_exists('te_create_athlete')) {
    /**
     * Create an athlete. Athletes get NO login identity.
     *
     * ─── Athletes do not have accounts (decided 2026-08-15) ───────────────────
     * This used to find-or-create a `player` user from `$input['email']`, and the
     * email on a youth athlete's form is the PARENT's — the old comment here said
     * so outright. Because `users.email` is unique, that address then belonged to
     * the CHILD's account, and the parent had no account of their own. When the
     * parent signed in with a magic link they authenticated **as their own child**,
     * into a row with no club roles, which routed them to the staff app.
     *
     * That is how CKU parents reported "I logged in and saw the coach's portal".
     * 24 such accounts existed; one had already been signed into and had COPPA
     * consent recorded against it.
     *
     * An earlier fix (2026-07-30) stopped these rows getting a default password,
     * which closed the password door but not the magic-link one — a passwordless
     * account is still an account, and `send-magic-link` resolves purely by email.
     * **The account itself was the bug**, so it is not created at all now.
     *
     * Athletes may log in again some day; when that changes, the identity must be
     * the athlete's OWN address, and it must not be minted silently as a side
     * effect of saving a roster record. Nothing depends on these rows today:
     * `user_club_access` has zero `player` entries, and `lib/participants.js` in
     * the chat server already documents that `athletes.user_id` mostly points at a
     * guardian and must never be read as "this account is the child".
     *
     * Fixes two long-standing crashes in the old create path:
     *   1. It reused an existing user's email → users_email_key unique violation.
     *   2. It set athletes.id = users.id, colliding with sequence-generated athlete
     *      ids → athletes_pkey unique violation. Now: athletes.id is ALWAYS
     *      sequence-generated.
     *
     * Also stamps athletes.club_id when the caller supplies one. Without it a
     * team-less athlete is invisible to club admins: AthleteScope derives club
     * membership from team_members OR athletes.club_id, and an admin-created
     * athlete has neither (the write-side half of CA-18 — the read side already
     * honors club_id).
     *
     * @param PDO   $pdo   Connection (caller owns the transaction).
     * @param array $input Athlete fields (first_name, last_name required;
     *                     club_id optional). Any `email` is ignored — it belongs
     *                     on the guardian record, which is where the club already
     *                     reads contact details from.
     * @return array{athlete_id:int, user_id:null}
     * @throws InvalidArgumentException when required fields are missing.
     */
    function te_create_athlete(PDO $pdo, array $input): array
    {
        $first_name = $input['first_name'] ?? null;
        $last_name  = $input['last_name'] ?? null;
        if (!$first_name || !$last_name) {
            throw new InvalidArgumentException('First name and last name are required');
        }

        // No user row, ever. See the docblock: an athlete has no login identity, and
        // linking to an EXISTING user by email would be worse than creating one — the
        // account on a youth athlete's email is the parent's, so `athletes.user_id`
        // would point at the parent and every "is this account the child" question
        // would answer wrong.
        $user_id = null;

        $club_id = (isset($input['club_id']) && is_numeric($input['club_id']))
            ? (int) $input['club_id']
            : null;

        // Always sequence-generate athletes.id; store the link in user_id (never as the PK).
        $stmt = $pdo->prepare(
            "INSERT INTO athletes (
                first_name, middle_initial, last_name, preferred_name,
                date_of_birth, gender, home_address_line1, city, state, zip_code,
                school_name, grade_level, jersey_size, email, phone,
                user_id, club_id, active_status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, true)
            RETURNING id"
        );
        $stmt->execute([
            $first_name,
            $input['middle_initial'] ?? null,
            $last_name,
            $input['preferred_name'] ?? null,
            $input['date_of_birth'] ?? '2000-01-01',
            $input['gender'] ?? 'Male',
            $input['home_address_line1'] ?? 'TBD',
            $input['city'] ?? 'TBD',
            $input['state'] ?? 'CA',
            $input['zip_code'] ?? '00000',
            $input['school_name'] ?? null,
            $input['grade_level'] ?? null,
            // Callers that don't pre-normalize still can't violate the CHECK
            // constraint — te_normalize_jersey_size is idempotent, so running it
            // here as well as in the gateway is safe.
            te_normalize_jersey_size($input['jersey_size'] ?? null),
            // The email now lands on the ATHLETE, not on a minted account. It was
            // previously write-only into `users`, which is why athletes.email is
            // NULL for every athlete in production while the form displayed a value.
            ($input['email'] ?? '') !== '' ? $input['email'] : null,
            ($input['phone'] ?? '') !== '' ? $input['phone'] : null,
            $user_id,
            $club_id,
        ]);
        $athlete_id = (int) $stmt->fetch(PDO::FETCH_ASSOC)['id'];

        return ['athlete_id' => $athlete_id, 'user_id' => $user_id];
    }
}
