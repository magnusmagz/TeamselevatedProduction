<?php
/**
 * Organization Branding API
 *
 * Fetches branding information (logo, colors) based on organizational context
 * with intelligent fallback hierarchy: team → club
 *
 * Endpoints:
 * - GET ?context_type=club&context_id=X - Get club branding
 * - GET ?context_type=team&context_id=X - Get team branding with club fallback
 */

header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();


require_once __DIR__ . '/../config/database.php';

try {
    $db = Database::getInstance();
    $connection = $db->getConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

$contextType = $_GET['context_type'] ?? null;
$contextId = $_GET['context_id'] ?? null;

if (!$contextType || !$contextId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required parameters: context_type and context_id']);
    exit();
}

if (!in_array($contextType, ['club', 'team'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid context_type. Must be: club or team']);
    exit();
}

try {
    $branding = null;

    switch ($contextType) {
        case 'club':
            $branding = getClubBranding($connection, $contextId);
            break;

        case 'team':
            $branding = getTeamBranding($connection, $contextId);
            break;
    }

    if (!$branding) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => ucfirst($contextType) . ' not found'
        ]);
        exit();
    }

    echo json_encode([
        'success' => true,
        'branding' => $branding
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    error_log("Organization Branding API Error: " . $e->getMessage());
}

/**
 * Get club branding
 */
function getClubBranding($connection, $clubId) {
    $stmt = $connection->prepare("
        SELECT
            cp.id,
            cp.name,
            cp.logo_url,
            cp.primary_color,
            cp.secondary_color
        FROM club_profile cp
        WHERE cp.id = ?
    ");
    $stmt->execute([$clubId]);
    $club = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$club) {
        return null;
    }

    return [
        'logo_url' => $club['logo_url'],
        'name' => $club['name'],
        'primary_color' => $club['primary_color'],
        'secondary_color' => $club['secondary_color'],
        'context_type' => 'club',
        'context_id' => (int)$club['id'],
        'fallback' => null
    ];
}

/**
 * Get team branding with club fallback
 */
function getTeamBranding($connection, $teamId) {
    $stmt = $connection->prepare("
        SELECT
            t.id,
            t.name,
            t.logo_url,
            t.team_color,
            t.club_id,
            cp.name as club_name,
            cp.logo_url as club_logo_url,
            cp.primary_color as club_primary_color
        FROM teams t
        LEFT JOIN club_profile cp ON t.club_id = cp.id
        WHERE t.id = ?
    ");
    $stmt->execute([$teamId]);
    $team = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$team) {
        return null;
    }

    // Build fallback to club
    $fallback = null;
    if ($team['club_id']) {
        $fallback = [
            'logo_url' => $team['club_logo_url'],
            'name' => $team['club_name'],
            'context_type' => 'club',
            'context_id' => (int)$team['club_id'],
            'fallback' => null
        ];
    }

    return [
        'logo_url' => $team['logo_url'],
        'name' => $team['name'],
        'team_color' => $team['team_color'],
        'context_type' => 'team',
        'context_id' => (int)$team['id'],
        'club_id' => $team['club_id'] ? (int)$team['club_id'] : null,
        'fallback' => $fallback
    ];
}
?>
