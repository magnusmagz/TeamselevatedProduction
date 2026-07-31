<?php
/**
 * Crew — club-scoped edits to a guardian's own contact details.
 *
 * The Crew page is club-wide; `legacy/guardian-gateway.php` is athlete-scoped and
 * resolves a guardian through an `athlete_guardians` link id the Crew page does
 * not have. More to the point, a person's name, email and phone belong to the
 * PERSON, not to their relationship with one child — a guardian with three
 * athletes has one phone number, and editing it from any of those three rows
 * should mean the same thing.
 *
 * So this writes `guardians` once, club-scoped, rather than per-link.
 *
 * Staff-only by design. This is not a widening of a staff gateway to serve
 * parents (see the note in CLAUDE.md about narrow parent-facing doors) — it is a
 * club admin maintaining their own club's roster.
 */

if (defined('TE_CREW_LIB_ONLY')) {
    return;
}

require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/AuditLogger.php';

try {
    $db = Database::getInstance();
    $connection = $db->getConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

$action = $_GET['action'] ?? null;

try {
    $auth = AuthMiddleware::requireAuth();

    switch ($action) {
        case 'update-contact':
            handleUpdateCrewContact($auth, $connection);
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action. Valid: update-contact']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    error_log('Crew Gateway Error: ' . $e->getMessage());
}

function handleUpdateCrewContact($auth, $connection)
{
    $data = json_decode(file_get_contents('php://input'), true) ?: [];

    $clubProfileId = $data['club_profile_id'] ?? null;
    $guardianId    = $data['guardian_id'] ?? null;

    if (!$clubProfileId || !$guardianId) {
        http_response_code(400);
        echo json_encode(['error' => 'club_profile_id and guardian_id are required']);
        return;
    }

    if (!$auth->canAccessClub($clubProfileId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied to this club']);
        return;
    }

    // Editing someone's contact details is a club-roster action, not a coaching
    // one. A coach can message their team; they do not maintain the club's records.
    if (!$auth->hasRole('club_admin', $clubProfileId, 'club')) {
        http_response_code(403);
        echo json_encode(['error' => 'Only club admins can edit crew details']);
        return;
    }

    // Scope check: the guardian must be attached to an athlete in THIS club.
    // Without it, a club admin could edit any guardian in the platform by id.
    $scope = $connection->prepare("
        SELECT g.id, g.first_name, g.last_name, g.email, g.mobile_phone
        FROM guardians g
        WHERE g.id = ?
          AND EXISTS (
            SELECT 1 FROM athlete_guardians ag
            JOIN athletes a ON a.id = ag.athlete_id
            WHERE ag.guardian_id = g.id AND a.club_id = ? AND a.deleted_at IS NULL
          )
        LIMIT 1
    ");
    $scope->execute([$guardianId, $clubProfileId]);
    $before = $scope->fetch(PDO::FETCH_ASSOC);

    if (!$before) {
        http_response_code(404);
        echo json_encode(['error' => 'Crew member not found in this club']);
        return;
    }

    $firstName = trim((string)($data['first_name'] ?? ''));
    $lastName  = trim((string)($data['last_name'] ?? ''));
    $email     = trim((string)($data['email'] ?? ''));
    $phone     = trim((string)($data['mobile_phone'] ?? ''));

    if ($firstName === '' || $lastName === '') {
        http_response_code(400);
        echo json_encode(['error' => 'First and last name are required']);
        return;
    }

    // All four columns are NOT NULL in Neon, and 25 rows already carry an empty
    // string for email. So blank means '' here, never null — writing null throws.
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['error' => "'{$email}' is not a valid email address"]);
        return;
    }

    $update = $connection->prepare("
        UPDATE guardians
        SET first_name = ?, last_name = ?, email = ?, mobile_phone = ?
        WHERE id = ?
    ");
    $update->execute([$firstName, $lastName, $email, $phone, $guardianId]);

    // Changing the email moves this person's parent-portal identity. Portal status
    // is inferred by matching guardians.email to a users row (the known-weak
    // inference documented in CLAUDE.md), so a new address can silently reset them
    // to "not invited" and strand an invite sent to the old one. Tell the admin
    // rather than letting them discover it from the status column later.
    $warnings = [];
    if (strcasecmp($before['email'], $email) !== 0) {
        $hadInvite = $connection->prepare(
            "SELECT 1 FROM magic_link_tokens WHERE email = ? LIMIT 1"
        );
        $hadInvite->execute([strtolower(trim($before['email'])) . ':parent_invite']);
        if ($hadInvite->fetchColumn()) {
            $warnings[] = 'Their portal invite was sent to the old address, so it will no longer '
                        . 'match. Send a new invite to ' . ($email !== '' ? $email : 'their new address') . '.';
        }
    }

    try {
        AuditLogger::log(
            $connection,
            $auth->getUserId(),
            'crew.contact_updated',
            'guardians',
            (int)$guardianId,
            [
                'club_profile_id' => (int)$clubProfileId,
                'before' => [
                    'first_name'   => $before['first_name'],
                    'last_name'    => $before['last_name'],
                    'email'        => $before['email'],
                    'mobile_phone' => $before['mobile_phone'],
                ],
                'after' => [
                    'first_name'   => $firstName,
                    'last_name'    => $lastName,
                    'email'        => $email,
                    'mobile_phone' => $phone,
                ],
            ]
        );
    } catch (Throwable $e) {
        error_log('[crew] audit log failed: ' . $e->getMessage());
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'guardian_id'  => (int)$guardianId,
            'first_name'   => $firstName,
            'last_name'    => $lastName,
            'email'        => $email,
            'mobile_phone' => $phone,
            'warnings'     => $warnings,
        ],
    ]);
}
