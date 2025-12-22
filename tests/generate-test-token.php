<?php
// Generate a test JWT token for the given user ID
// Usage: php generate-test-token.php <user_id> <club_id>

require_once __DIR__ . '/../lib/JWT.php';
require_once __DIR__ . '/../config/database.php';

if ($argc < 3) {
    fwrite(STDERR, "Usage: php generate-test-token.php <user_id> <club_id>\n");
    exit(1);
}

$userId = (int)$argv[1];
$clubId = (int)$argv[2];

try {
    $db = Database::getInstance()->getConnection();

    // Get user details
    $stmt = $db->prepare("SELECT id, email, first_name, last_name FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        fwrite(STDERR, "User not found: $userId\n");
        exit(1);
    }

    // Get club and league details
    $stmt = $db->prepare("SELECT id, name, league_id FROM club_profile WHERE id = ?");
    $stmt->execute([$clubId]);
    $club = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$club) {
        fwrite(STDERR, "Club not found: $clubId\n");
        exit(1);
    }

    // Build name
    $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));

    // Build additional claims
    $additionalClaims = [
        'org_id' => $club['id'],
        'org_type' => 'club',
        'org_name' => $club['name'],
        'roles' => [
            [
                'role' => 'club_admin',
                'scope_type' => 'club',
                'scope_id' => $club['id']
            ]
        ],
        'active_context' => [
            'role' => 'club_admin',
            'scope_type' => 'club',
            'scope_id' => $club['id'],
            'league_id' => $club['league_id'],
            'club_id' => $club['id']
        ]
    ];

    $token = JWT::generate($user['id'], $user['email'], $name, $additionalClaims);
    echo $token;
    exit(0);

} catch (Exception $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
