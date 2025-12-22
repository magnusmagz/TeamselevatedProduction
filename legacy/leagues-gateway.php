<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Use centralized database connection
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';

try {
    $db = Database::getInstance();
    $connection = $db->getConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$league_id = $_GET['id'] ?? null;

// Get query parameters for filtering
$search = $_GET['search'] ?? '';
$active_only = isset($_GET['active']) ? filter_var($_GET['active'], FILTER_VALIDATE_BOOLEAN) : null;

try {
    // Require authentication for all endpoints
    $auth = AuthMiddleware::requireAuth();

    switch ($method) {
        case 'GET':
            if ($league_id) {
                // Get specific league
                // Only super admins and league admins can view league details
                if (!$auth->isSuperAdmin() && !$auth->hasRole('league_admin', $league_id, 'league')) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Access denied']);
                    exit();
                }

                $stmt = $connection->prepare("
                    SELECT l.*,
                           COUNT(DISTINCT c.id) as club_count,
                           COUNT(DISTINCT t.id) as team_count
                    FROM leagues l
                    LEFT JOIN club_profile c ON l.id = c.league_id
                    LEFT JOIN teams t ON l.id = t.league_id
                    WHERE l.id = ?
                    GROUP BY l.id, l.name, l.description, l.website, l.contact_email, l.contact_phone,
                             l.logo_url, l.address, l.city, l.state, l.zip_code, l.active,
                             l.created_at, l.updated_at
                ");
                $stmt->execute([$league_id]);
                $league = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$league) {
                    http_response_code(404);
                    echo json_encode(['error' => 'League not found']);
                    exit();
                }

                echo json_encode($league);
            } else {
                // Get all leagues with filtering based on user's access
                $query = "
                    SELECT l.*,
                           COUNT(DISTINCT c.id) as club_count,
                           COUNT(DISTINCT t.id) as team_count
                    FROM leagues l
                    LEFT JOIN club_profile c ON l.id = c.league_id
                    LEFT JOIN teams t ON l.id = t.league_id
                    WHERE 1=1
                ";

                $params = [];

                // Apply search filter
                if ($search) {
                    $query .= " AND l.name ILIKE ?";
                    $params[] = "%$search%";
                }

                // Apply active filter
                if ($active_only !== null) {
                    $query .= " AND l.active = ?";
                    $params[] = $active_only;
                }

                // Apply league scoping (non-super admins only see leagues they have access to)
                $leagueScope = $auth->getLeagueScopeWhereClause('l.id');
                $query .= " " . $leagueScope['where'];
                $params = array_merge($params, $leagueScope['params']);

                $query .= " GROUP BY l.id, l.name, l.description, l.website, l.contact_email, l.contact_phone,
                                     l.logo_url, l.address, l.city, l.state, l.zip_code, l.active,
                                     l.created_at, l.updated_at
                            ORDER BY l.created_at DESC";

                $stmt = $connection->prepare($query);
                $stmt->execute($params);
                $leagues = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode(['leagues' => $leagues]);
            }
            break;

        case 'POST':
            // Only super admins can create leagues
            if (!$auth->can('create_league')) {
                http_response_code(403);
                echo json_encode(['error' => 'Only super admins can create leagues']);
                exit();
            }

            $data = json_decode(file_get_contents("php://input"), true);

            // Validate required fields
            if (empty($data['name'])) {
                http_response_code(400);
                echo json_encode(['error' => 'League name is required']);
                exit();
            }

            // Check for duplicate league name
            $stmt = $connection->prepare("SELECT id FROM leagues WHERE name = ?");
            $stmt->execute([$data['name']]);
            if ($stmt->fetch()) {
                http_response_code(409);
                echo json_encode(['error' => 'A league with this name already exists']);
                exit();
            }

            $stmt = $connection->prepare("
                INSERT INTO leagues (name, description, website, contact_email, contact_phone,
                                   logo_url, address, city, state, zip_code, active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                RETURNING id
            ");

            $stmt->execute([
                $data['name'],
                $data['description'] ?? null,
                $data['website'] ?? null,
                $data['contact_email'] ?? null,
                $data['contact_phone'] ?? null,
                $data['logo_url'] ?? null,
                $data['address'] ?? null,
                $data['city'] ?? null,
                $data['state'] ?? null,
                $data['zip_code'] ?? null,
                isset($data['active']) ? $data['active'] : true
            ]);

            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'id' => $result['id'],
                'message' => 'League created successfully'
            ]);
            break;

        case 'PUT':
            if (!$league_id) {
                http_response_code(400);
                echo json_encode(['error' => 'League ID required for update']);
                exit();
            }

            // Check if user can edit this league
            if (!$auth->can('edit_league', $league_id, 'league')) {
                http_response_code(403);
                echo json_encode(['error' => 'Access denied']);
                exit();
            }

            $data = json_decode(file_get_contents("php://input"), true);

            // Validate required fields
            if (empty($data['name'])) {
                http_response_code(400);
                echo json_encode(['error' => 'League name is required']);
                exit();
            }

            // Check for duplicate league name (excluding current league)
            $stmt = $connection->prepare("SELECT id FROM leagues WHERE name = ? AND id != ?");
            $stmt->execute([$data['name'], $league_id]);
            if ($stmt->fetch()) {
                http_response_code(409);
                echo json_encode(['error' => 'A league with this name already exists']);
                exit();
            }

            $stmt = $connection->prepare("
                UPDATE leagues
                SET name = ?, description = ?, website = ?, contact_email = ?, contact_phone = ?,
                    logo_url = ?, address = ?, city = ?, state = ?, zip_code = ?, active = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");

            $stmt->execute([
                $data['name'],
                $data['description'] ?? null,
                $data['website'] ?? null,
                $data['contact_email'] ?? null,
                $data['contact_phone'] ?? null,
                $data['logo_url'] ?? null,
                $data['address'] ?? null,
                $data['city'] ?? null,
                $data['state'] ?? null,
                $data['zip_code'] ?? null,
                isset($data['active']) ? $data['active'] : true,
                $league_id
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'League updated successfully'
            ]);
            break;

        case 'DELETE':
            if (!$league_id) {
                http_response_code(400);
                echo json_encode(['error' => 'League ID required for deletion']);
                exit();
            }

            // Only super admins can delete leagues
            if (!$auth->can('delete_league')) {
                http_response_code(403);
                echo json_encode(['error' => 'Only super admins can delete leagues']);
                exit();
            }

            // Check if league has clubs
            $stmt = $connection->prepare("SELECT COUNT(*) as count FROM club_profile WHERE league_id = ?");
            $stmt->execute([$league_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result['count'] > 0) {
                http_response_code(409);
                echo json_encode([
                    'error' => 'Cannot delete league with existing clubs',
                    'club_count' => $result['count']
                ]);
                exit();
            }

            $stmt = $connection->prepare("DELETE FROM leagues WHERE id = ?");
            $stmt->execute([$league_id]);

            echo json_encode([
                'success' => true,
                'message' => 'League deleted successfully'
            ]);
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    error_log("Leagues Gateway Error: " . $e->getMessage());
}
?>
