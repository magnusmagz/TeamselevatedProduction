<?php
/**
 * Club-admin controls for getting a COACH signed in when the coach cannot
 * manage it themselves. Three POST actions, all taking { user_id, club_id }:
 *
 *   ?action=invite                  not_invited → mint + mail a 7-day
 *                                   `:coach_invite`; invited / invite_expired →
 *                                   re-mint (the earlier link stops working)
 *   ?action=send-login-link         active → a 24h magic link, exactly as
 *                                   api/portal-access.php does for crew
 *   ?action=set-temporary-password  { ..., password } → bcrypt it onto the
 *                                   account and spend any outstanding invite
 *
 * WHY A NEW FILE
 * api/coach-invite.php is the PUBLIC redemption endpoint — the person holding
 * the link has no account yet. These are the opposite: authenticated, club-admin
 * only, acting on someone else's account. Keeping them apart means the public
 * file never grows an authenticated branch someone later forgets to gate.
 *
 * RULES THAT BITE
 *  - Standing is `te_is_club_admin()` of the COACH's club — never canAccessClub()
 *    (club membership; a parent row satisfies it). Super admin passes.
 *  - The coach is resolved by (user_id, club_id) and must hold an ACTIVE,
 *    unrevoked coach role in that club. The email is read from the users row,
 *    never from the body.
 *  - The token is never in a response. Neither is the password. The email is
 *    the channel for the first two; the admin's screen is the channel for the
 *    third, and it already has the password because they typed it.
 *  - The state is re-derived here. The page's button label is a hint; an
 *    invite to an account with a password is refused (409 already_active), a
 *    login link to an account without one is refused (409 not_active).
 *  - Setting a password spends every unused `:coach_invite` for the address,
 *    so a link mailed last week cannot later overwrite what the admin set.
 *  - No forced-change flag. Decided 2026-09-06: `users.password_set_by_admin_at`
 *    (migration 097) drives a dismissible banner and nothing else.
 *
 * Handlers return ['status' => int, 'body' => array] so CoachAccessTest runs the
 * real thing against SQLite instead of a copy of its SQL.
 */

// Test hook: defining this loads the collaborators and returns before any side
// effect. Never defined in production; must stay above everything with an effect.
if (defined('TE_COACH_ACCESS_LIB_ONLY')) {
    require_once __DIR__ . '/../config/env.php';
    require_once __DIR__ . '/../lib/club_standing.php';
    require_once __DIR__ . '/../lib/coach_invite.php';
    require_once __DIR__ . '/../lib/coach_access.php';
    require_once __DIR__ . '/../lib/magic_link.php';
    require_once __DIR__ . '/../lib/AuditLogger.php';
    require_once __DIR__ . '/../lib/feature_flags.php';
    return;
}

require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/club_standing.php';
require_once __DIR__ . '/../lib/coach_invite.php';
require_once __DIR__ . '/../lib/coach_access.php';
require_once __DIR__ . '/../lib/magic_link.php';
require_once __DIR__ . '/../lib/AuditLogger.php';
require_once __DIR__ . '/../lib/feature_flags.php';
require_once __DIR__ . '/../lib/Email.php';

// ─────────────────────────────────────────────────────────────────────────────
// Shared: standing + target resolution
// ─────────────────────────────────────────────────────────────────────────────

/** @return array{status:int, body:array} */
function coachAccess_fail(int $status, string $message, string $reason): array
{
    return ['status' => $status, 'body' => ['success' => false, 'error' => $message, 'reason' => $reason]];
}

/**
 * Gate + resolve. Returns the coach's users row (id, email, first_name,
 * last_name, password_hash) plus club_id, or a failure envelope.
 *
 * Standing is checked BEFORE the target is looked up, so a refused caller
 * learns nothing about which ids exist.
 *
 * @return array{ok:true, coach:array, club_id:int}|array{ok:false, fail:array}
 */
function coachAccess_resolve(PDO $pdo, $auth, array $body): array
{
    $userId = (int) ($body['user_id'] ?? 0);
    $clubId = (int) ($body['club_id'] ?? 0);
    if ($userId <= 0 || $clubId <= 0) {
        return ['ok' => false, 'fail' => coachAccess_fail(400, 'user_id and club_id are required', 'bad_request')];
    }

    if (!te_is_club_admin($auth, $clubId)) {
        return ['ok' => false, 'fail' => coachAccess_fail(403, 'Only club admins can manage a coach\'s access', 'forbidden')];
    }

    // Must be a coach OF THIS CLUB — the pair is what stops an admin acting on
    // any user id on the platform by passing their own club.
    $stmt = $pdo->prepare(
        "SELECT u.id, u.email, u.first_name, u.last_name, u.password_hash
           FROM users u
           JOIN user_club_access uca ON uca.user_id = u.id
          WHERE u.id = ? AND uca.club_profile_id = ? AND uca.role = 'coach'
            AND uca.active = TRUE AND uca.revoked_at IS NULL
          LIMIT 1"
    );
    $stmt->execute([$userId, $clubId]);
    $coach = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$coach) {
        return ['ok' => false, 'fail' => coachAccess_fail(404, 'That person is not a coach in this club', 'not_a_coach')];
    }

    return ['ok' => true, 'coach' => $coach, 'club_id' => $clubId];
}

function coachAccess_displayName(array $coach): string
{
    $name = trim(((string) ($coach['first_name'] ?? '')) . ' ' . ((string) ($coach['last_name'] ?? '')));
    return $name !== '' ? $name : (string) $coach['email'];
}

// ─────────────────────────────────────────────────────────────────────────────
// invite / resend
// ─────────────────────────────────────────────────────────────────────────────

/**
 * @param callable|null $sender fn(string $to, string $name, string $link): bool
 *                              — a test injects one; production mails as the club.
 * @return array{status:int, body:array}
 */
function coachAccess_invite(PDO $pdo, $auth, array $body, ?callable $sender = null): array
{
    $r = coachAccess_resolve($pdo, $auth, $body);
    if (!$r['ok']) {
        return $r['fail'];
    }
    $coach = $r['coach'];
    $clubId = $r['club_id'];
    $email = strtolower(trim((string) ($coach['email'] ?? '')));

    if ($email === '') {
        return coachAccess_fail(422, 'That coach has no email address on file', 'no_email');
    }
    if (!empty($coach['password_hash'])) {
        return coachAccess_fail(409, 'They already have a password — send them a login link instead.', 'already_active');
    }
    if (!te_feature_enabled('COACH_INVITE_EMAIL')) {
        return ['status' => 503, 'body' => te_feature_disabled_response('COACH_INVITE_EMAIL')];
    }

    // Was there an earlier link? That decides the audit verb and the outcome
    // word; either way the earlier link is spent and a fresh one is mailed.
    $stmt = $pdo->prepare('SELECT count(*) FROM magic_link_tokens WHERE email = ?');
    $stmt->execute([te_coach_invite_email_key($email)]);
    $resend = ((int) $stmt->fetchColumn()) > 0;

    te_coach_invite_mint_token($pdo, $email);

    $sent = te_coach_invite_send(
        $pdo,
        (int) $coach['id'],
        $clubId,
        $sender,
        (int) $auth->getUserId() ?: null,
        $resend ? 'coach_invite_resent' : 'coach_invite_sent'
    );

    if (empty($sent['sent'])) {
        if (!empty($sent['feature_disabled'])) {
            return ['status' => 503, 'body' => $sent];
        }
        return coachAccess_fail(502, 'We could not send the email. Please try again.', (string) ($sent['reason'] ?? 'send_failed'));
    }

    return ['status' => 200, 'body' => [
        'success' => true,
        'outcome' => $resend ? 'resent' : 'sent',
        'email' => $email,
        'expires_in' => te_magic_link_ttl_phrase(TE_COACH_INVITE_TTL_SECONDS),
    ]];
}

// ─────────────────────────────────────────────────────────────────────────────
// send-login-link — mirrors api/portal-access.php for a coach
// ─────────────────────────────────────────────────────────────────────────────

/**
 * @param callable|null $sender fn(string $to, string $name, string $link): bool
 * @return array{status:int, body:array}
 */
function coachAccess_sendLoginLink(PDO $pdo, $auth, array $body, ?callable $sender = null): array
{
    $r = coachAccess_resolve($pdo, $auth, $body);
    if (!$r['ok']) {
        return $r['fail'];
    }
    $coach = $r['coach'];
    $clubId = $r['club_id'];
    $email = strtolower(trim((string) ($coach['email'] ?? '')));

    if ($email === '') {
        return coachAccess_fail(422, 'That coach has no email address on file', 'no_email');
    }
    // A sign-in link only helps an account that can be signed into. Without a
    // password the honest answer is "invite them".
    if (empty($coach['password_hash'])) {
        return coachAccess_fail(409, 'They have not set a password yet — send them an invite instead.', 'not_active');
    }

    // Same mint, same TTL, same URL as the Crew page. Not re-implemented.
    $magicToken = te_mint_magic_link_token($pdo, $email, TE_MAGIC_LINK_TTL_ADMIN_SENT);
    $magicUrl = te_magic_link_url($magicToken);
    $name = coachAccess_displayName($coach);

    if ($sender === null) {
        $sender = static function (string $to, string $name, string $url) use ($pdo, $clubId): bool {
            return (bool) (new Email())->forClub($pdo, $clubId)->sendMagicLink($to, $name, $url);
        };
    }

    $sent = false;
    try {
        $sent = (bool) $sender($email, $name, $magicUrl);
    } catch (Throwable $e) {
        error_log('coach-access: sendMagicLink failed for ' . $email . ': ' . $e->getMessage());
    }

    // Audited whether or not the mail left: an admin caused a sign-in link to
    // exist for someone else's account. Same action name as the Crew page.
    AuditLogger::log($pdo, (int) $auth->getUserId() ?: null, 'portal_login_link_sent', 'users', (int) $coach['id'], [
        'club_id' => $clubId, 'target_user_id' => (int) $coach['id'], 'audience' => 'coach', 'emailed' => $sent,
    ]);

    if (!$sent) {
        return coachAccess_fail(502, 'We could not send the email. Please try again.', 'send_failed');
    }

    return ['status' => 200, 'body' => [
        'success' => true,
        'email' => $email,
        'expires_in' => te_magic_link_ttl_phrase(TE_MAGIC_LINK_TTL_ADMIN_SENT),
    ]];
}

// ─────────────────────────────────────────────────────────────────────────────
// set-temporary-password
// ─────────────────────────────────────────────────────────────────────────────

/** @return array{status:int, body:array} */
function coachAccess_setTemporaryPassword(PDO $pdo, $auth, array $body): array
{
    $r = coachAccess_resolve($pdo, $auth, $body);
    if (!$r['ok']) {
        return $r['fail'];
    }
    $coach = $r['coach'];
    $clubId = $r['club_id'];
    $email = strtolower(trim((string) ($coach['email'] ?? '')));

    $newPassword = (string) ($body['password'] ?? '');
    if (strlen($newPassword) < TE_COACH_TEMP_PASSWORD_MIN_LENGTH) {
        return coachAccess_fail(
            422,
            'Password must be at least ' . TE_COACH_TEMP_PASSWORD_MIN_LENGTH . ' characters',
            'weak_password'
        );
    }

    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $markColumn = te_password_set_by_admin_column_present($pdo);

    try {
        $pdo->beginTransaction();

        $sql = $markColumn
            ? "UPDATE users SET password_hash = ?, auth_provider = 'password', password_set_by_admin_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?"
            : "UPDATE users SET password_hash = ?, auth_provider = 'password', updated_at = CURRENT_TIMESTAMP WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$hash, (int) $coach['id']]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('password update affected ' . $stmt->rowCount() . ' rows');
        }

        // Spend every outstanding invite for the address, so a link mailed
        // earlier cannot later overwrite what the admin just set.
        $spent = 0;
        if ($email !== '') {
            $stmt = $pdo->prepare('UPDATE magic_link_tokens SET used_at = CURRENT_TIMESTAMP WHERE email = ? AND used_at IS NULL');
            $stmt->execute([te_coach_invite_email_key($email)]);
            $spent = $stmt->rowCount();
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('coach-access: set-temporary-password failed: ' . $e->getMessage());
        return coachAccess_fail(500, 'We could not set the password. Please try again.', 'write_failed');
    }

    // Actor, target, club. Never the password, never the hash.
    AuditLogger::log($pdo, (int) $auth->getUserId() ?: null, 'password_set_by_admin', 'users', (int) $coach['id'], [
        'club_id' => $clubId, 'target_user_id' => (int) $coach['id'],
        'invite_tokens_spent' => $spent, 'marked' => $markColumn,
    ]);

    return ['status' => 200, 'body' => [
        'success' => true,
        'email' => $email,
        'name' => coachAccess_displayName($coach),
    ]];
}

// ─────────────────────────────────────────────────────────────────────────────
// Dispatch
// ─────────────────────────────────────────────────────────────────────────────

try {
    $pdo = Database::getInstance()->getConnection();
} catch (Exception $e) {
    error_log('coach-access: DB connection failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$auth = AuthMiddleware::requireAuth();
$action = (string) ($_GET['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode((string) file_get_contents('php://input'), true) ?: [];

switch ($action) {
    case 'invite':
        $result = coachAccess_invite($pdo, $auth, $input);
        break;
    case 'send-login-link':
        $result = coachAccess_sendLoginLink($pdo, $auth, $input);
        break;
    case 'set-temporary-password':
        $result = coachAccess_setTemporaryPassword($pdo, $auth, $input);
        break;
    default:
        $result = coachAccess_fail(400, 'Unknown action', 'unknown_action');
}

http_response_code($result['status']);
echo json_encode($result['body']);
