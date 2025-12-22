<?php
/**
 * Organization Branding API
 *
 * Fetches branding information (logo, colors) based on organizational context
 * with intelligent fallback hierarchy: team → club → league
 *
 * Endpoints:
 * - GET ?context_type=league&context_id=X - Get league branding
 * - GET ?context_type=club&context_id=X - Get club branding with league fallback
 * - GET ?context_type=team&context_id=X - Get team branding with club/league fallback
 */

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

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

if (!in_array($contextType, ['league', 'club', 'team'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid context_type. Must be: league, club, or team']);
    exit();
}

try {
    $branding = null;

    switch ($contextType) {
        case 'league':
            $branding = getLeagueBranding($connection, $contextId);
            break;

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
 * Get league branding
 */
function getLeagueBranding($connection, $leagueId) {
    $stmt = $connection->prepare("
        SELECT
            id,
            name,
            logo_url,
            'league' as context_type
        FROM leagues
        WHERE id = ? AND active = TRUE
    ");
    $stmt->execute([$leagueId]);
    $league = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$league) {
        return null;
    }

    return [
        'logo_url' => $league['logo_url'],
        'name' => $league['name'],
        'context_type' => 'league',
        'context_id' => (int)$league['id'],
        'fallback' => null
    ];
}

/**
 * Get club branding with league fallback
 */
function getClubBranding($connection, $clubId) {
    $stmt = $connection->prepare("
        SELECT
            cp.id,
            cp.name,
            cp.logo_url,
            cp.primary_color,
            cp.secondary_color,
            cp.league_id,
            l.name as league_name,
            l.logo_url as league_logo_url
        FROM club_profile cp
        LEFT JOIN leagues l ON cp.league_id = l.id
        WHERE cp.id = ?
    ");
    $stmt->execute([$clubId]);
    $club = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$club) {
        return null;
    }

    $fallback = null;
    if ($club['league_id']) {
        $fallback = [
            'logo_url' => $club['league_logo_url'],
            'name' => $club['league_name'],
            'context_type' => 'league',
            'context_id' => (int)$club['league_id']
        ];
    }

    return [
        'logo_url' => $club['logo_url'],
        'name' => $club['name'],
        'primary_color' => $club['primary_color'],
        'secondary_color' => $club['secondary_color'],
        'context_type' => 'club',
        'context_id' => (int)$club['id'],
        'league_id' => $club['league_id'] ? (int)$club['league_id'] : null,
        'fallback' => $fallback
    ];
}

/**
 * Get team branding with club and league fallback
 */
function getTeamBranding($connection, $teamId) {
    $stmt = $connection->prepare("
        SELECT
            t.id,
            t.name,
            t.logo_url,
            t.team_color,
            t.club_id,
            t.league_id,
            cp.name as club_name,
            cp.logo_url as club_logo_url,
            cp.primary_color as club_primary_color,
            l.name as league_name,
            l.logo_url as league_logo_url
        FROM teams t
        LEFT JOIN club_profile cp ON t.club_id = cp.id
        LEFT JOIN leagues l ON t.league_id = l.id
        WHERE t.id = ?
    ");
    $stmt->execute([$teamId]);
    $team = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$team) {
        return null;
    }

    // Build fallback chain: club → league
    $fallback = null;
    if ($team['club_id']) {
        $clubFallback = null;
        if ($team['league_id']) {
            $clubFallback = [
                'logo_url' => $team['league_logo_url'],
                'name' => $team['league_name'],
                'context_type' => 'league',
                'context_id' => (int)$team['league_id']
            ];
        }

        $fallback = [
            'logo_url' => $team['club_logo_url'],
            'name' => $team['club_name'],
            'context_type' => 'club',
            'context_id' => (int)$team['club_id'],
            'fallback' => $clubFallback
        ];
    } elseif ($team['league_id']) {
        // Direct league fallback if no club
        $fallback = [
            'logo_url' => $team['league_logo_url'],
            'name' => $team['league_name'],
            'context_type' => 'league',
            'context_id' => (int)$team['league_id']
        ];
    }

    return [
        'logo_url' => $team['logo_url'],
        'name' => $team['name'],
        'team_color' => $team['team_color'],
        'context_type' => 'team',
        'context_id' => (int)$team['id'],
        'club_id' => $team['club_id'] ? (int)$team['club_id'] : null,
        'league_id' => $team['league_id'] ? (int)$team['league_id'] : null,
        'fallback' => $fallback
    ];
}
?>
