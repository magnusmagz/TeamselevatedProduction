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
     * Create an athlete, and (if an email is supplied) link it to a `player` user.
     *
     * Fixes two long-standing crashes in the old create path:
     *   1. It reused an existing user's email → users_email_key unique violation.
     *      Now: reuse the existing user instead of blindly inserting.
     *   2. It set athletes.id = users.id, colliding with sequence-generated athlete
     *      ids → athletes_pkey unique violation. Now: athletes.id is ALWAYS
     *      sequence-generated; the user link lives in athletes.user_id.
     *
     * Also stamps athletes.club_id when the caller supplies one. Without it a
     * team-less athlete is invisible to club admins: AthleteScope derives club
     * membership from team_members OR athletes.club_id, and an admin-created
     * athlete has neither (the write-side half of CA-18 — the read side already
     * honors club_id).
     *
     * @param PDO   $pdo   Connection (caller owns the transaction).
     * @param array $input Athlete fields (first_name, last_name required; email,
     *                     club_id optional).
     * @return array{athlete_id:int, user_id:?int}
     * @throws InvalidArgumentException when required fields are missing.
     */
    function te_create_athlete(PDO $pdo, array $input): array
    {
        $first_name = $input['first_name'] ?? null;
        $last_name  = $input['last_name'] ?? null;
        if (!$first_name || !$last_name) {
            throw new InvalidArgumentException('First name and last name are required');
        }

        $email = $input['email'] ?? null;

        // Find-or-create the linked player user. Reusing an existing email is the fix
        // for the users_email_key crash — two athletes/guardians can share an email.
        $user_id = null;
        if ($email) {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
            $stmt->execute([$email]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $user_id = (int) $existing['id'];
            } else {
                $password = $input['password'] ?? password_hash('defaultpass', PASSWORD_DEFAULT);
                $stmt = $pdo->prepare(
                    "INSERT INTO users (first_name, last_name, email, password_hash, role)
                     VALUES (?, ?, ?, ?, 'player') RETURNING id"
                );
                $stmt->execute([$first_name, $last_name, $email, $password]);
                $user_id = (int) $stmt->fetch(PDO::FETCH_ASSOC)['id'];
            }
        }

        $club_id = (isset($input['club_id']) && is_numeric($input['club_id']))
            ? (int) $input['club_id']
            : null;

        // Always sequence-generate athletes.id; store the link in user_id (never as the PK).
        $stmt = $pdo->prepare(
            "INSERT INTO athletes (
                first_name, middle_initial, last_name, preferred_name,
                date_of_birth, gender, home_address_line1, city, state, zip_code,
                school_name, grade_level, jersey_size, user_id, club_id, active_status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, true)
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
            $user_id,
            $club_id,
        ]);
        $athlete_id = (int) $stmt->fetch(PDO::FETCH_ASSOC)['id'];

        return ['athlete_id' => $athlete_id, 'user_id' => $user_id];
    }
}
