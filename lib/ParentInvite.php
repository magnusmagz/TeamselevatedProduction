<?php
/**
 * Parent Invite helper
 *
 * Shared logic for the parent-invite feature: given a guardian + club, make sure
 * a parent login exists for that guardian's email and mint a one-time
 * "set your password" token. Reuses the existing magic_link_tokens store with a
 * ':parent_invite' email suffix (mirrors the ':password_reset' pattern used by
 * auth-gateway.php) and a 7-day expiry.
 *
 * Called from:
 *  - registration/registrations-api.php (auto, on approval)
 *  - api/auth-gateway.php (manual "Invite to parent portal" button)
 *
 * Returns one of:
 *  - ['status' => 'error', 'message' => ...]
 *  - ['status' => 'already_active', 'user_id' => int, 'email' => string]
 *  - ['status' => 'invited', 'user_id' => int, 'token' => string,
 *     'email' => string, 'name' => string]
 */

require_once __DIR__ . '/AuditLogger.php';

if (!function_exists('parentInvite_ensureUserAndToken')) {
    /**
     * Ensure a parent login exists for the guardian and mint an invite token.
     *
     * @param PDO $db        Active database connection.
     * @param int $guardianId Guardian to invite.
     * @param int $clubId      Club granting parent access.
     * @return array          See file docblock for shape.
     */
    function parentInvite_ensureUserAndToken(PDO $db, int $guardianId, int $clubId): array
    {
        // 1. Look up the guardian.
        $stmt = $db->prepare('
            SELECT id, email, first_name, last_name
            FROM guardians
            WHERE id = ?
            LIMIT 1
        ');
        $stmt->execute([$guardianId]);
        $guardian = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$guardian) {
            return ['status' => 'error', 'message' => 'guardian not found'];
        }

        $email = strtolower(trim($guardian['email'] ?? ''));
        if ($email === '') {
            return ['status' => 'error', 'message' => 'guardian has no email'];
        }

        $gFirst = trim($guardian['first_name'] ?? '');
        $gLast = trim($guardian['last_name'] ?? '');

        // 2. Find an existing user by exact email. If one already has a password
        //    set, they can already log in — caller should skip the invite.
        $stmt = $db->prepare('
            SELECT id, password_hash
            FROM users
            WHERE email = ?
            LIMIT 1
        ');
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && !empty($user['password_hash'])) {
            return [
                'status' => 'already_active',
                'user_id' => (int)$user['id'],
                'email' => $email,
            ];
        }

        // 2b. RECLAIM: the row we just found may not be the guardian's at all.
        //
        // `users.email` is UNIQUE (users_email_key), so there can only ever be ONE
        // account per address — and until 2026-07-30 te_create_athlete() created the
        // athlete's linked user row using whatever email was on the athlete form.
        // For a youth athlete that is the PARENT's email, so the child's shell
        // account holds the parent's address and this lookup returns the child.
        //
        // Reusing it (what step 3's else-branch would do) means the parent sets a
        // password on an account named after their kid, with users.role='player',
        // that athletes.user_id still points at — one login shared by two people.
        // Before the password cleanup this failed differently and more quietly: the
        // shell carried a 'defaultpass' hash, so step 2 returned 'already_active'
        // and the invite was silently never sent.
        //
        // Neither is recoverable by the caller, and a second row cannot be created
        // (UNIQUE email). So repair the linkage instead: detach the athlete and
        // rename the row to its rightful owner, the guardian.
        //
        // This is only reachable for a PASSWORD-LESS row — step 2 has already
        // returned for anything anyone can actually log into — so no live account
        // can be taken over here. Athletes lose only an auto-generated shell they
        // never had access to; athletes.user_id is nullable and is already NULL for
        // every athlete created without an email. The two live readers of that
        // column (legacy/athletes-gateway.php) both LEFT JOIN it, so NULL is a
        // shape they already handle.
        if ($user) {
            $stmt = $db->prepare('SELECT id FROM athletes WHERE user_id = ?');
            $stmt->execute([(int)$user['id']]);
            $squattedAthleteIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($squattedAthleteIds)) {
                $ownTransaction = !$db->inTransaction();
                if ($ownTransaction) {
                    $db->beginTransaction();
                }

                try {
                    $stmt = $db->prepare('UPDATE athletes SET user_id = NULL WHERE user_id = ?');
                    $stmt->execute([(int)$user['id']]);

                    $stmt = $db->prepare("
                        UPDATE users
                           SET first_name = ?, last_name = ?, role = 'parent', updated_at = NOW()
                         WHERE id = ?
                    ");
                    $stmt->execute([$gFirst, $gLast, (int)$user['id']]);

                    if ($ownTransaction) {
                        $db->commit();
                    }
                } catch (Throwable $e) {
                    if ($ownTransaction && $db->inTransaction()) {
                        $db->rollBack();
                    }
                    throw $e;
                }

                AuditLogger::log(
                    $db,
                    null,
                    'parent_invite_reclaimed_athlete_shell',
                    'users',
                    (int)$user['id'],
                    [
                        'guardian_id'          => $guardianId,
                        'email'                => $email,
                        'detached_athlete_ids' => array_map('intval', $squattedAthleteIds),
                        'reason'               => 'athlete shell account held the guardian email',
                    ]
                );
            }
        }

        // 3. No user yet -> create a parent shell account (no password).
        if (!$user) {
            $stmt = $db->prepare('
                INSERT INTO users (email, first_name, last_name, role, auth_provider, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                RETURNING id
            ');
            $stmt->execute([$email, $gFirst, $gLast, 'parent', 'invitation']);
            $userId = (int)$stmt->fetchColumn();
        } else {
            $userId = (int)$user['id'];
        }

        // 4. Ensure an active parent role for this club. user_club_access has a
        //    UNIQUE (user_id, club_profile_id, role) constraint, so upsert on it.
        $stmt = $db->prepare("
            INSERT INTO user_club_access (user_id, club_profile_id, role, active, granted_at)
            VALUES (?, ?, 'parent', TRUE, NOW())
            ON CONFLICT (user_id, club_profile_id, role)
            DO UPDATE SET active = TRUE, revoked_at = NULL, revoked_by = NULL
        ");
        $stmt->execute([$userId, $clubId]);

        // 5. Invalidate any prior unused parent_invite tokens for this email so
        //    only the freshest link works.
        $suffixedEmail = $email . ':parent_invite';
        $stmt = $db->prepare('
            UPDATE magic_link_tokens
            SET used_at = NOW()
            WHERE email = ? AND used_at IS NULL
        ');
        $stmt->execute([$suffixedEmail]);

        // 6. Mint a new 7-day token.
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + 7 * 24 * 3600);

        $stmt = $db->prepare('
            INSERT INTO magic_link_tokens (email, token, expires_at, created_at)
            VALUES (?, ?, ?, NOW())
        ');
        $stmt->execute([$suffixedEmail, $token, $expires]);

        return [
            'status' => 'invited',
            'user_id' => $userId,
            'token' => $token,
            'email' => $email,
            'name' => trim($gFirst . ' ' . $gLast),
        ];
    }
}
