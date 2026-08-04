<?php
/**
 * Admin-initiated portal access — "Send login link".
 *
 * WHY THIS EXISTS
 * `ParentInvite::send` returns `already_active` for anyone who already has a
 * password, and `handleSendParentInvite` only emails inside the `invited`
 * branch. So clicking "Invite to portal" for an existing account sent NOTHING:
 * the admin saw "They already have an account" and the parent never knew the
 * button was pressed. That was the only tool on the Crew page, so a family who
 * could not get in had no path that did anything.
 *
 * WHY A NEW FILE RATHER THAN A NEW ACTION IN auth-gateway.php
 * That file is on the do-not-modify list. The one edit made there on 2026-08-03
 * was approved for a specific bug fix; a whole new admin capability does not
 * belong in it.
 *
 * WHY THIS IS NOT `send-magic-link` WITH A DIFFERENT CALLER
 * The login page's version is deliberately unauthenticated — it only ever mails
 * the account owner, so proving identity adds nothing. Exposing that from inside
 * the app as an admin button would be an unauthenticated way to mail any parent
 * on demand, so this path requires club-admin standing and audits every send.
 * The token itself is never returned in the response: the email is the channel,
 * which is what keeps an admin from gaining access to a family's account.
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/AuditLogger.php';
require_once __DIR__ . '/../lib/Email.php';
require_once __DIR__ . '/../lib/magic_link.php';

function portalAccessFail(int $status, string $message, string $reason = ''): void
{
    http_response_code($status);
    echo json_encode(array_filter([
        'success' => false,
        'error' => $message,
        'reason' => $reason ?: null,
    ], fn($v) => $v !== null));
    exit;
}

try {
    $pdo = Database::getInstance()->getConnection();
} catch (Exception $e) {
    error_log('portal-access: DB connection failed: ' . $e->getMessage());
    portalAccessFail(500, 'Database connection failed');
}

$auth = AuthMiddleware::requireAuth();
$action = $_GET['action'] ?? '';

if ($action !== 'send-login-link') {
    portalAccessFail(400, 'Unknown action');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    portalAccessFail(405, 'Method not allowed');
}

$body = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
$guardianId = (int) ($body['guardian_id'] ?? 0);
$clubId = (int) ($body['club_id'] ?? 0);

if ($guardianId <= 0 || $clubId <= 0) {
    portalAccessFail(400, 'guardian_id and club_id are required');
}

// CLUB ADMIN ONLY. Deliberately not canAccessClub() — that is club membership,
// and a `parent` row satisfies it (see the open finding about handleClubParents
// in CLAUDE.md). Mailing a sign-in link to another family is staff work.
if (!$auth->isSuperAdmin() && !$auth->hasRole('club_admin', $clubId, 'club')) {
    portalAccessFail(403, 'Only club admins can send a login link');
}

// The guardian must belong to this club, or a club admin could mail a link to
// any guardian id in the system by passing their own club.
$stmt = $pdo->prepare(
    'SELECT DISTINCT g.id, g.first_name, g.last_name, g.email
     FROM guardians g
     JOIN athlete_guardians ag ON ag.guardian_id = g.id
     JOIN athletes a ON a.id = ag.athlete_id
     WHERE g.id = ? AND a.club_id = ?
     LIMIT 1'
);
$stmt->execute([$guardianId, $clubId]);
$guardian = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$guardian) {
    portalAccessFail(404, 'That crew member is not in this club', 'not_in_club');
}

$email = strtolower(trim((string) ($guardian['email'] ?? '')));
if ($email === '') {
    portalAccessFail(422, 'That crew member has no email address on file', 'no_email');
}

// A login link only means anything for an account that can be signed into. If
// there is no account yet, the honest answer is "invite them" — sending a magic
// link to a non-existent user would mint a token that logs nobody in.
$stmt = $pdo->prepare('SELECT id, first_name, last_name, password_hash FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    portalAccessFail(409, 'They do not have an account yet — send them an invite instead.', 'no_account');
}

$token = te_mint_magic_link_token($pdo, $email, TE_MAGIC_LINK_TTL_ADMIN_SENT);
$link = te_magic_link_url($token);
$name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))
    ?: trim(($guardian['first_name'] ?? '') . ' ' . ($guardian['last_name'] ?? ''));

$sent = false;
try {
    $sent = (bool) (new Email())->sendMagicLink($email, $name, $link);
} catch (Throwable $e) {
    error_log('portal-access: sendMagicLink failed for ' . $email . ': ' . $e->getMessage());
}

// Audited whether or not the mail left: an admin caused a sign-in link to exist
// for someone else's account, and that is the fact worth being able to show.
AuditLogger::log(
    $pdo,
    (int) $auth->getUserId() ?: null,
    'portal_login_link_sent',
    'users',
    (int) $user['id'],
    ['guardian_id' => $guardianId, 'club_id' => $clubId, 'emailed' => $sent]
);

if (!$sent) {
    portalAccessFail(502, 'We could not send the email. Please try again.', 'send_failed');
}

echo json_encode([
    'success' => true,
    'email' => $email,
    'expires_in' => te_magic_link_ttl_phrase(TE_MAGIC_LINK_TTL_ADMIN_SENT),
]);
