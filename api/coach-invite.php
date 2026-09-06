<?php
/**
 * Coach invite redemption (GOTR G6).
 *
 *   POST ?action=redeem   { token, password }   → sets the password, spends the
 *                                                 token, returns a session
 *
 * Public by design — the person holding the link has no account yet, so there
 * is nothing to authenticate with. The token IS the credential, and everything
 * that decides whether it is good lives in lib/coach_invite.php
 * (te_coach_invite_redeem): the three-answer ladder, resolve-before-spend, one
 * transaction for the password and the used_at.
 *
 * The JWT is minted the way handleSetParentPassword mints it, so the frontend
 * stores it and lands in the staff app signed in. That file is do-not-modify,
 * which is why this endpoint exists instead of a new action on it.
 */

require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/JWT.php';
require_once __DIR__ . '/../lib/coach_invite.php';

try {
    $db = Database::getInstance()->getConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = (string) ($_GET['action'] ?? '');

if ($action !== 'redeem') {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode((string) file_get_contents('php://input'), true) ?: [];
$token = (string) ($input['token'] ?? '');
$password = (string) ($input['password'] ?? '');

if ($token === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['error' => $token === '' ? 'Token is required' : 'Password is required']);
    exit;
}

$result = te_coach_invite_redeem($db, $token, $password);

if (empty($result['success'])) {
    http_response_code((int) ($result['status'] ?? 400));
    echo json_encode(['error' => $result['error'], 'reason' => $result['reason']]);
    exit;
}

$name = trim($result['first_name'] . ' ' . $result['last_name']);
$jwt = JWT::generateEnhanced($db, $result['user_id'], $result['email'], $name);

echo json_encode([
    'success' => true,
    'message' => 'Your coach account is ready.',
    'token'   => $jwt,
    'user'    => [
        'id'    => (int) $result['user_id'],
        'email' => $result['email'],
        'name'  => $name,
    ],
]);
