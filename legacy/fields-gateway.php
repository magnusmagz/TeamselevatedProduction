<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();


// Use centralized database connection
require_once __DIR__ . '/../config/database.php';

try {
    $db = Database::getInstance();
    $connection = $db->getConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

// Fields are scoped to the caller's clubs via their venue's club_id. Without
// this, the home-field dropdown leaked every club's fields (cross-club pollution).
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/field_size.php';
require_once __DIR__ . '/../lib/team_roster_scope.php';
$auth = AuthMiddleware::requireAuth();
$accessibleClubIds = $auth->getAccessibleClubIds(); // null = super admin (all clubs)

$action = $_GET['action'] ?? 'list';

/**
 * ?action=for-team&team_id=N — the club's fields, each labelled with whether it
 * suits that team's age group (CKU R73). Read-only.
 *
 * Gated on te_team_view_standing(), the SAME predicate that gates the team page
 * (legacy/team-players-gateway.php delegates to it) — a parent or player on the
 * team passes, which is right: this is a list of the club's own pitches with a
 * fit hint, not a roster and not anyone's contact details. The stricter staff
 * predicate in the same lib gates the roster download, which is a file of
 * minors' details; this is not that.
 *
 * Nothing is filtered out; see lib/field_size.php. The list is ordered
 * fits-first so an old client that renders it flat still leads with the right
 * answer.
 */
if ($action === 'for-team') {
    $teamId = isset($_GET['team_id']) ? (int) $_GET['team_id'] : 0;
    if ($teamId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'team_id is required']);
        exit();
    }

    try {
        switch (te_team_view_standing($connection, $auth, $teamId)) {
            case TE_TEAM_ROSTER_OK:
                break;
            case TE_TEAM_ROSTER_NOT_FOUND:
                http_response_code(404);
                echo json_encode(['error' => 'Team not found']);
                exit();
            default:
                http_response_code(403);
                echo json_encode(['error' => 'You do not have permission to view this team']);
                exit();
        }

        echo json_encode(te_fields_for_team($connection, $teamId));
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit();
}

try {
    $scopeSql = '';
    $params = [];
    if ($accessibleClubIds !== null) {
        if (empty($accessibleClubIds)) { echo json_encode([]); exit(); }
        // CLUB ids, not athlete or team ids — see NoScopeIdListsTest's allowlist.
        $scopeSql = 'AND v.club_id IN (' . implode(',', array_fill(0, count($accessibleClubIds), '?')) . ')';
        $params = $accessibleClubIds;
    }

    // `field_size` (migration 088) is selected only when the column is actually
    // there. `main` is shared and deploys are by push, so this file can reach
    // production days before the migration runs, and on Postgres a missing
    // column is 42703 — which would take the home-field dropdown down for every
    // club rather than merely omitting a new key.
    $sizeSelect = te_field_size_available($connection) ? 'f.field_size' : 'NULL';

    // Get the caller's active fields with their venue information
    $stmt = $connection->prepare("
        SELECT f.id,
               CONCAT(v.name, ' - ', f.name) as name,
               f.venue_id,
               v.name as venue_name,
               f.field_type,
               f.surface_type,
               f.dimensions,
               f.capacity,
               $sizeSelect as field_size,
               f.active
        FROM fields f
        JOIN venues v ON f.venue_id = v.id
        WHERE f.active = true
        $scopeSql
        ORDER BY v.name, f.name
    ");
    $stmt->execute($params);
    $fields = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($fields);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
