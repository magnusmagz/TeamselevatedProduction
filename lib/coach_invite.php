<?php
/**
 * Coach invites — a per-person, single-use link with a real accepted timestamp
 * (GOTR G6, 2026-09-06).
 *
 * WHAT THIS REPLACES
 * `legacy/coaches-gateway.php` used to hash a shared default literal when no
 * password was supplied, and CoachManagement.tsx told the admin so on screen. Every
 * coach made through that page shared one credential, and a kickoff blast
 * emailed it in plaintext to eleven people. `CoachImportStrategy` did the
 * opposite thing — a NULL hash and no way in at all. Neither produced a fact
 * the funnel could count: an account with a password is not a person who has
 * accepted anything.
 *
 * THE SHAPE
 * Same store and same ladder as parent invites (lib/ParentInvite.php,
 * lib/parent_invite_token.php): a `magic_link_tokens` row keyed
 * `<email>:coach_invite`, 7 days, spent on redemption. `used_at IS NOT NULL` is
 * the "accepted" fact; nothing else is.
 *
 * RULES THAT BITE
 *  - THE TOKEN IS NEVER RETURNED. Not from the ensure function, not in a Redis
 *    payload, not in an API response. The email is the channel; the sender
 *    re-reads the freshest unused row itself. An admin who could read the token
 *    could sign in as the coach.
 *  - `users.email` IS UNIQUE, so an address that already has an account is
 *    ATTACHED (club access added) and never duplicated or refused. An account
 *    that already has a password is `already_active` and gets no invite —
 *    mailing a "set your password" link to someone who has one is how the
 *    2026-08-03 "your link expired" ticket happened.
 *  - A REVOKED access is not re-granted by an invite. Re-importing a roster
 *    must not quietly restore someone the council removed.
 *  - Every write to `user_club_access` calls te_role_cache_invalidate()
 *    (RoleCacheTest scans for it).
 *  - The send is a kill switch: TE_FEATURE_COACH_INVITE_EMAIL. Off means the
 *    account and token exist and nothing is mailed, and the caller is told so.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/parent_invite_token.php';
require_once __DIR__ . '/role_cache.php';
require_once __DIR__ . '/AuditLogger.php';
require_once __DIR__ . '/feature_flags.php';

const TE_COACH_INVITE_SUFFIX = ':coach_invite';
const TE_COACH_INVITE_TTL_SECONDS = 7 * 24 * 3600;

/** The magic_link_tokens.email key for an address. */
function te_coach_invite_email_key(string $email): string
{
    return strtolower(trim($email)) . TE_COACH_INVITE_SUFFIX;
}

/**
 * Same ladder as a parent invite — used before expired, unknown stays vague.
 * Delegates rather than copies so the two cannot drift.
 */
function te_classify_coach_invite_token($row, ?int $now = null): string
{
    return te_classify_parent_invite_token($row, $now);
}

/** @return array{status:int, error:string, reason:string} */
function te_coach_invite_token_error(string $classification): array
{
    return te_parent_invite_token_error($classification);
}

/** Where a coach-invite token belongs. Consumed by frontend /accept-coach-invite. */
function te_coach_invite_url(string $token): string
{
    $appUrl = rtrim((string) Env::get('APP_URL', 'http://localhost:3003'), '/');
    return $appUrl . '/accept-coach-invite?token=' . urlencode($token);
}

/**
 * Spend every unused token for the address and mint one fresh 7-day token.
 * Returns nothing: see the file header.
 */
function te_coach_invite_mint_token(PDO $pdo, string $email): void
{
    $key = te_coach_invite_email_key($email);

    $stmt = $pdo->prepare('UPDATE magic_link_tokens SET used_at = CURRENT_TIMESTAMP WHERE email = ? AND used_at IS NULL');
    $stmt->execute([$key]);

    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + TE_COACH_INVITE_TTL_SECONDS);
    $stmt = $pdo->prepare(
        'INSERT INTO magic_link_tokens (email, token, expires_at, created_at)
         VALUES (?, ?, ?, CURRENT_TIMESTAMP)'
    );
    $stmt->execute([$key, $token, $expires]);
}

/** The newest unused token row for the address, or null. */
function te_coach_invite_freshest_token(PDO $pdo, string $email): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, email, token, expires_at, used_at, created_at
           FROM magic_link_tokens
          WHERE email = ? AND used_at IS NULL
          ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([te_coach_invite_email_key($email)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Make sure a coach account and club access exist for a person, and mint an
 * invite token unless they can already sign in.
 *
 * @param array $person  first_name, last_name, email, phone (phone optional)
 * @param int   $clubId  the club granting the coach role
 * @param int|null $actorId who asked (granted_by / audit); null for a system path
 * @param string $source 'coaches_page' | 'import' — audit detail only
 *
 * @return array One of:
 *   ['status' => 'error', 'message' => ...]
 *   ['status' => 'access_revoked', 'user_id' => int, 'email' => string]
 *   ['status' => 'already_active', 'user_id' => int, 'email' => string, 'access' => 'granted'|'existing', 'created' => false]
 *   ['status' => 'invited', 'user_id' => int, 'email' => string, 'name' => string, 'access' => ..., 'created' => bool]
 */
function te_coach_invite_ensure_user_and_token(PDO $pdo, array $person, int $clubId, ?int $actorId = null, string $source = 'coaches_page'): array
{
    $email = strtolower(trim((string) ($person['email'] ?? '')));
    $first = trim((string) ($person['first_name'] ?? ''));
    $last  = trim((string) ($person['last_name'] ?? ''));
    $phone = trim((string) ($person['phone'] ?? ''));

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['status' => 'error', 'message' => $email === '' ? 'email is required' : "Invalid email '{$email}'"];
    }
    if ($first === '' || $last === '') {
        return ['status' => 'error', 'message' => 'first_name and last_name are required'];
    }
    if ($clubId <= 0) {
        return ['status' => 'error', 'message' => 'club is required'];
    }

    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }

    try {
        $stmt = $pdo->prepare('SELECT id, password_hash, first_name, last_name FROM users WHERE LOWER(email) = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        $created = false;
        if (!$user) {
            // No password. The invite is the only way in.
            $stmt = $pdo->prepare(
                'INSERT INTO users (first_name, last_name, email, phone, role, auth_provider, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                 RETURNING id'
            );
            $stmt->execute([$first, $last, $email, $phone !== '' ? $phone : null, 'coach', 'invitation']);
            $userId = (int) $stmt->fetchColumn();
            $user = ['id' => $userId, 'password_hash' => null, 'first_name' => $first, 'last_name' => $last];
            $created = true;
        }
        $userId = (int) $user['id'];

        // Club access. A row that exists is left alone — including a revoked one.
        $stmt = $pdo->prepare(
            "SELECT id, active, revoked_at FROM user_club_access
              WHERE user_id = ? AND club_profile_id = ? AND role = 'coach' LIMIT 1"
        );
        $stmt->execute([$userId, $clubId]);
        $accessRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($accessRow) {
            $revoked = !empty($accessRow['revoked_at'])
                || in_array(strtolower((string) $accessRow['active']), ['0', 'f', 'false', ''], true);
            if ($revoked) {
                if ($own) {
                    $pdo->commit();
                }
                return ['status' => 'access_revoked', 'user_id' => $userId, 'email' => $email];
            }
            $access = 'existing';
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO user_club_access (user_id, club_profile_id, role, granted_by, active, granted_at)
                 VALUES (?, ?, 'coach', ?, TRUE, CURRENT_TIMESTAMP)"
            );
            $stmt->execute([$userId, $clubId, $actorId ?: null]);
            // A coach who cannot see their own club for five minutes is a support ticket.
            te_role_cache_invalidate($userId);
            $access = 'granted';
        }

        if (!empty($user['password_hash'])) {
            if ($own) {
                $pdo->commit();
            }
            return ['status' => 'already_active', 'user_id' => $userId, 'email' => $email,
                    'access' => $access, 'created' => false];
        }

        te_coach_invite_mint_token($pdo, $email);

        if ($own) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    AuditLogger::log($pdo, $actorId, 'coach_invite_created', 'users', $userId, [
        'club_id' => $clubId, 'email' => $email, 'source' => $source,
        'user_created' => $created, 'access' => $access,
    ]);

    $name = trim(((string) ($user['first_name'] ?? '')) . ' ' . ((string) ($user['last_name'] ?? '')));
    return ['status' => 'invited', 'user_id' => $userId, 'email' => $email,
            'name' => $name !== '' ? $name : $email, 'access' => $access, 'created' => $created];
}

/**
 * Mail the invite to one coach, as the club.
 *
 * Re-reads the freshest unused token (minting a new one if the only one has
 * expired), builds the link, and hands (to, name, link) to the sender. The
 * default sender is lib/Email.php branded with forClub(); a test injects its
 * own. Returns sent:false with a reason rather than throwing — a queue worker
 * decides for itself which reasons are worth a retry.
 *
 * @param callable|null $sender fn(string $to, string $name, string $link): bool
 * @param int|null $actorId who asked — the audit row's user_id; null for a system path
 * @param string $auditAction 'coach_invite_sent' (default) or 'coach_invite_resent'
 * @return array{sent: bool, reason: string, feature_disabled?: string}
 */
function te_coach_invite_send(
    PDO $pdo,
    int $userId,
    int $clubId,
    ?callable $sender = null,
    ?int $actorId = null,
    string $auditAction = 'coach_invite_sent'
): array {
    if (!te_feature_enabled('COACH_INVITE_EMAIL')) {
        return te_feature_disabled_response('COACH_INVITE_EMAIL') + ['reason' => 'feature_disabled'];
    }

    $stmt = $pdo->prepare('SELECT id, email, first_name, last_name, password_hash FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        return ['sent' => false, 'reason' => 'user_missing'];
    }
    if (!empty($user['password_hash'])) {
        return ['sent' => false, 'reason' => 'already_active'];
    }
    $email = strtolower(trim((string) $user['email']));
    if ($email === '') {
        return ['sent' => false, 'reason' => 'no_email'];
    }

    $row = te_coach_invite_freshest_token($pdo, $email);
    if ($row === null || te_classify_coach_invite_token($row) !== TE_INVITE_TOKEN_VALID) {
        te_coach_invite_mint_token($pdo, $email);
        $row = te_coach_invite_freshest_token($pdo, $email);
    }
    if ($row === null) {
        return ['sent' => false, 'reason' => 'no_token'];
    }

    $link = te_coach_invite_url((string) $row['token']);
    $name = trim(((string) $user['first_name']) . ' ' . ((string) $user['last_name']));
    if ($name === '') {
        $name = $email;
    }

    if ($sender === null) {
        $sender = static function (string $to, string $name, string $link) use ($pdo, $clubId): bool {
            require_once __DIR__ . '/Email.php';
            return (bool) (new Email())->forClub($pdo, $clubId)->sendCoachInvite($to, $name, $link);
        };
    }

    $ok = false;
    try {
        $ok = (bool) $sender($email, $name, $link);
    } catch (Throwable $e) {
        error_log('te_coach_invite_send: ' . $e->getMessage());
    }

    // $actorId is the admin who pressed the button (api/coach-access.php);
    // null for the create path and the importer. $auditAction lets a re-send
    // record itself as one — one row per action, not two.
    AuditLogger::log($pdo, $actorId, $auditAction, 'users', $userId, [
        'club_id' => $clubId, 'email' => $email, 'delivered_to_transport' => $ok,
        'expires_at' => $row['expires_at'] ?? null,
    ]);

    return ['sent' => $ok, 'reason' => $ok ? 'sent' : 'transport_failed'];
}

/**
 * Redeem an invite: set the password and spend the token, together.
 *
 * Mirrors handleSetParentPassword in auth-gateway.php, which is do-not-modify:
 * classify without folding the predicates into the WHERE, resolve the account
 * BEFORE spending the token, key the write on users.id, check the row count.
 * A valid token with no account leaves the token unspent so the link still
 * works once the account is repaired.
 *
 * @return array success:true → user_id, email, first_name, last_name;
 *               success:false → reason, error, status (HTTP)
 */
function te_coach_invite_redeem(PDO $pdo, string $token, string $password): array
{
    $token = trim($token);
    if ($token === '') {
        return ['success' => false, 'reason' => TE_INVITE_TOKEN_NOT_FOUND, 'status' => 400,
                'error' => te_coach_invite_token_error(TE_INVITE_TOKEN_NOT_FOUND)['error']];
    }
    if (strlen($password) < 8 || !preg_match('/[A-Z]/', $password)
        || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        return ['success' => false, 'reason' => 'weak_password', 'status' => 400,
                'error' => 'Password must be at least 8 characters and contain uppercase, lowercase, and numbers'];
    }

    $stmt = $pdo->prepare(
        "SELECT id, email, expires_at, used_at FROM magic_link_tokens
          WHERE token = ? AND email LIKE '%" . TE_COACH_INVITE_SUFFIX . "' LIMIT 1"
    );
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $classification = te_classify_coach_invite_token($row ?: null);
    if ($classification !== TE_INVITE_TOKEN_VALID) {
        $err = te_coach_invite_token_error($classification);
        return ['success' => false, 'reason' => $err['reason'], 'status' => $err['status'], 'error' => $err['error']];
    }

    $email = substr((string) $row['email'], 0, -strlen(TE_COACH_INVITE_SUFFIX));

    $stmt = $pdo->prepare('SELECT id, email, first_name, last_name FROM users WHERE LOWER(email) = ? LIMIT 1');
    $stmt->execute([strtolower($email)]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        error_log("coach invite: valid token for '$email' but no users row; token left unspent");
        return ['success' => false, 'reason' => 'account_missing', 'status' => 500,
                'error' => 'Your account is not set up correctly. Please contact your club.'];
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            "UPDATE users SET password_hash = ?, auth_provider = 'password', updated_at = CURRENT_TIMESTAMP WHERE id = ?"
        );
        $stmt->execute([$hash, (int) $user['id']]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('password update affected ' . $stmt->rowCount() . ' rows');
        }
        $stmt = $pdo->prepare('UPDATE magic_link_tokens SET used_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->execute([(int) $row['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('coach invite redeem: ' . $e->getMessage());
        return ['success' => false, 'reason' => 'write_failed', 'status' => 500,
                'error' => 'We could not finish setting up your account. Please try again.'];
    }

    AuditLogger::log($pdo, (int) $user['id'], 'coach_invite_accepted', 'users', (int) $user['id'], [
        'email' => $email, 'token_id' => (int) $row['id'],
    ]);

    return ['success' => true, 'user_id' => (int) $user['id'], 'email' => (string) $user['email'],
            'first_name' => (string) $user['first_name'], 'last_name' => (string) $user['last_name']];
}
