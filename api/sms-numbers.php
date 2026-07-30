<?php
/**
 * Club SMS sending number — read/set/clear.
 *
 * Numbers are NOT purchased here. An admin buys the number in the Twilio console
 * and pastes it; we verify against Twilio's IncomingPhoneNumbers before storing, so
 * the only numbers that can reach the send path are ones the account demonstrably
 * owns and that are SMS-capable. A typo saves as an error, never as a sender that
 * fails silently at 2am on a game-cancellation blast.
 */

// Tests require this file for its helpers. See api/recipient-search-gateway.php:13.
if (defined('TE_SMS_NUMBERS_LIB_ONLY')) {
    require_once __DIR__ . '/../lib/sms_sender.php';
    return;
}

require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/sms_sender.php';
require_once __DIR__ . '/../lib/suppression.php';
require_once __DIR__ . '/../lib/AuditLogger.php';

try {
    $db = Database::getInstance();
    $connection = $db->getConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;

try {
    $auth = AuthMiddleware::requireAuth();

    switch ($action) {
        case 'get':
            handleGetClubSmsNumber($auth, $connection);
            break;
        case 'set':
            handleSetClubSmsNumber($auth, $connection);
            break;
        case 'clear':
            handleClearClubSmsNumber($auth, $connection);
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action. Valid: get, set, clear']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * Reading the number is admin-only too: it is account configuration, and a coach
 * has no reason to see the club's telephony setup.
 */
function requireClubAdminForSms($auth, $clubProfileId): bool
{
    if (!$clubProfileId) {
        http_response_code(400);
        echo json_encode(['error' => 'club_profile_id is required']);
        return false;
    }
    if (!$auth->canAccessClub($clubProfileId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied to this club']);
        return false;
    }
    if (!$auth->hasRole('club_admin', $clubProfileId, 'club')) {
        http_response_code(403);
        echo json_encode(['error' => 'Only club admins can manage the SMS number']);
        return false;
    }
    return true;
}

function handleGetClubSmsNumber($auth, $connection)
{
    $clubProfileId = $_GET['club_profile_id'] ?? null;
    if (!requireClubAdminForSms($auth, $clubProfileId)) {
        return;
    }

    $stmt = $connection->prepare("
        SELECT phone_number, messaging_service_sid, twilio_phone_sid, provisioned_at
        FROM sms_phone_numbers
        WHERE club_profile_id = ? AND user_id IS NULL AND is_active
        LIMIT 1
    ");
    $stmt->execute([$clubProfileId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => [
            'configured'            => (bool) $row,
            'phone_number'          => $row['phone_number'] ?? null,
            'messaging_service_sid' => $row['messaging_service_sid'] ?? null,
            'twilio_phone_sid'      => $row['twilio_phone_sid'] ?? null,
            'provisioned_at'        => $row['provisioned_at'] ?? null,
            // Surfaced so the UI can explain WHY sending is blocked, in the same
            // words the send path uses.
            'blocked_reason'        => $row ? null : te_sms_sender_missing_message(),
        ],
    ]);
}

function handleSetClubSmsNumber($auth, $connection)
{
    $data = json_decode(file_get_contents('php://input'), true);
    $clubProfileId = $data['club_profile_id'] ?? null;

    if (!requireClubAdminForSms($auth, $clubProfileId)) {
        return;
    }

    $rawNumber = trim((string) ($data['phone_number'] ?? ''));
    $messagingServiceSid = trim((string) ($data['messaging_service_sid'] ?? '')) ?: null;

    if ($rawNumber === '' && $messagingServiceSid === null) {
        http_response_code(400);
        echo json_encode(['error' => 'A phone number or a Messaging Service SID is required']);
        return;
    }

    $phoneNumber = null;
    $twilioSid   = null;

    if ($rawNumber !== '') {
        $phoneNumber = te_normalize_sms_phone($rawNumber);
        if ($phoneNumber === null) {
            http_response_code(400);
            echo json_encode([
                'error' => "'{$rawNumber}' is not a valid phone number. "
                         . 'Use the full number including area code, e.g. +1 360 555 0199.',
            ]);
            return;
        }

        $verification = te_verify_twilio_number($phoneNumber);
        if (!$verification['ok']) {
            http_response_code(422);
            echo json_encode(['error' => $verification['error']]);
            return;
        }
        $twilioSid = $verification['sid'];
    }

    // Wire the number's inbound webhook to the auto-reply handler. Non-fatal: a
    // club with a working send number should not be blocked from saving because
    // the reply hook failed. Reported back so the UI can say so rather than let
    // families text into silence without anyone knowing.
    $inboundWarning = null;
    if ($twilioSid) {
        $hook = te_configure_twilio_inbound($twilioSid);
        if (!$hook['ok']) {
            $inboundWarning = 'Number saved, but auto-reply could not be enabled: ' . $hook['error']
                            . ' Replies to this number will go unanswered until it is set in the Twilio console.';
            error_log('[sms-numbers] inbound hook failed for ' . $twilioSid . ': ' . $hook['error']);
        }
    }

    // Deactivate rather than delete: which number was in force when is exactly what
    // you need to reconstruct a carrier STOP months later.
    $connection->beginTransaction();
    try {
        $connection->prepare("
            UPDATE sms_phone_numbers
            SET is_active = FALSE, updated_at = CURRENT_TIMESTAMP
            WHERE club_profile_id = ? AND user_id IS NULL AND is_active
        ")->execute([$clubProfileId]);

        $insert = $connection->prepare("
            INSERT INTO sms_phone_numbers
                (club_profile_id, user_id, phone_number, twilio_phone_sid,
                 messaging_service_sid, is_active, provisioned_at, created_at, updated_at)
            VALUES (?, NULL, ?, ?, ?, TRUE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            RETURNING id
        ");
        $insert->execute([$clubProfileId, $phoneNumber, $twilioSid, $messagingServiceSid]);
        $newId = $insert->fetchColumn();

        $connection->commit();
    } catch (Exception $e) {
        $connection->rollBack();
        throw $e;
    }

    // Who changed the number a club texts families from is worth an audit row.
    try {
        AuditLogger::log(
            $connection,
            $auth->getUserId(),
            'sms_number.set',
            'sms_phone_numbers',
            (int) $newId,
            [
                'club_profile_id'       => (int) $clubProfileId,
                'phone_number'          => $phoneNumber,
                'messaging_service_sid' => $messagingServiceSid,
            ]
        );
    } catch (Throwable $e) {
        error_log('[sms-numbers] audit log failed: ' . $e->getMessage());
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'configured'            => true,
            'phone_number'          => $phoneNumber,
            'messaging_service_sid' => $messagingServiceSid,
            'twilio_phone_sid'      => $twilioSid,
            'inbound_warning'       => $inboundWarning,
        ],
    ]);
}

function handleClearClubSmsNumber($auth, $connection)
{
    $data = json_decode(file_get_contents('php://input'), true);
    $clubProfileId = $data['club_profile_id'] ?? null;

    if (!requireClubAdminForSms($auth, $clubProfileId)) {
        return;
    }

    $connection->prepare("
        UPDATE sms_phone_numbers
        SET is_active = FALSE, updated_at = CURRENT_TIMESTAMP
        WHERE club_profile_id = ? AND user_id IS NULL AND is_active
    ")->execute([$clubProfileId]);

    try {
        AuditLogger::log(
            $connection,
            $auth->getUserId(),
            'sms_number.cleared',
            'sms_phone_numbers',
            null,
            ['club_profile_id' => (int) $clubProfileId]
        );
    } catch (Throwable $e) {
        error_log('[sms-numbers] audit log failed: ' . $e->getMessage());
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'configured'     => false,
            // Clearing the number stops this club's SMS. Say so plainly rather
            // than returning a bare success.
            'blocked_reason' => te_sms_sender_missing_message(),
        ],
    ]);
}
