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
