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

// Require authentication for all endpoints
$auth = AuthMiddleware::requireAuth();

/**
 * If the caller submitted a base64-encoded logo on the BrandingTab, write it
 * to disk under uploads/team-logos/ and return the public URL. Returns null
 * when there's nothing to do (no logo_data or it's already a URL). Existing
 * logo_url values pass through unchanged.
 *
 * Accepts either:
 *   - $data['logo_url']  — already-uploaded URL (passes through)
 *   - $data['logo_data'] — base64 string (with or without data:image/* prefix)
 *                          plus optional $data['logo_filename']
 */
function resolveTeamLogoUrl(array $data): ?string {
    // Pre-uploaded URL takes precedence — passes through unchanged.
    if (!empty($data['logo_url']) && is_string($data['logo_url'])) {
        return $data['logo_url'];
    }

    $base64 = $data['logo_data'] ?? null;
    if (!$base64 || !is_string($base64)) return null;

    // Strip the data: prefix if present, e.g. "data:image/png;base64,iVBOR..."
    $mime = 'image/png';
    if (preg_match('/^data:(image\/[a-zA-Z+\-]+);base64,(.+)$/s', $base64, $m)) {
        $mime = $m[1];
        $base64 = $m[2];
    }
    $bytes = base64_decode($base64, true);
    if ($bytes === false || strlen($bytes) === 0) return null;

    $extByMime = [
        'image/png'     => 'png',
        'image/jpeg'    => 'jpg',
        'image/jpg'     => 'jpg',
        'image/webp'    => 'webp',
        'image/svg+xml' => 'svg',
        'image/gif'     => 'gif',
    ];
    $ext = $extByMime[$mime] ?? 'png';

    // Storage path: same uploads/ directory used by api/upload.php so the
    // public URL pattern matches what the rest of the platform serves.
    $uploadDir = __DIR__ . '/../uploads/team-logos/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    $filename = uniqid('team_logo_' . time() . '_') . '.' . $ext;
    $filepath = $uploadDir . $filename;
    if (file_put_contents($filepath, $bytes) === false) return null;

    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . '/uploads/team-logos/' . $filename;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$team_id = $_GET['id'] ?? null;

// Get query parameters for filtering
$search = $_GET['search'] ?? '';
$season_id = $_GET['season_id'] ?? '';
$age_group = $_GET['age_group'] ?? '';
$division = $_GET['division'] ?? '';
$primary_coach_id = $_GET['primary_coach_id'] ?? '';

try {
    switch ($method) {
        case 'GET':
            if ($team_id) {
                // Get specific team - check if user has access
                $stmt = $connection->prepare("
                    SELECT t.*,
                           s.name as season_name,
                           CONCAT(u.first_name, ' ', u.last_name) as coach_name,
                           f.name as home_field_name,
                           COUNT(DISTINCT tm.id) as player_count
                    FROM teams t
                    LEFT JOIN seasons s ON t.season_id = s.id
                    LEFT JOIN users u ON t.primary_coach_id = u.id
                    LEFT JOIN team_members tm ON t.id = tm.team_id
                    LEFT JOIN fields f ON t.home_field_id = f.id
                    WHERE t.id = ? AND t.deleted_at IS NULL
                    GROUP BY t.id, t.name, t.program_id, t.season_id, t.primary_coach_id, t.division,
                             t.skill_level, t.age_group, t.gender, t.max_players, t.team_color,
                             t.logo_url, t.status, t.created_at, t.updated_at, t.club_id, t.home_field_id,
                             t.primary_color, t.secondary_color, t.accent_color,
                             s.name, u.first_name, u.last_name, f.name
                ");
                $stmt->execute([$team_id]);
                $team = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$team) {
                    http_response_code(404);
                    echo json_encode(['error' => 'Team not found']);
                    exit();
                }

                // Check if user has access to this team's club
                if ($team['club_id'] && !$auth->canAccessClub($team['club_id'])) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Access denied']);
                    exit();
                }

                echo json_encode($team);
            } else {
                // Get all teams with filters
                $query = "
                    SELECT t.*,
                           s.name as season_name,
                           CONCAT(u.first_name, ' ', u.last_name) as coach_name,
                           f.name as home_field_name,
                           COUNT(DISTINCT tm.id) as player_count
                    FROM teams t
                    LEFT JOIN seasons s ON t.season_id = s.id
                    LEFT JOIN users u ON t.primary_coach_id = u.id
                    LEFT JOIN team_members tm ON t.id = tm.team_id
                    LEFT JOIN fields f ON t.home_field_id = f.id
                    WHERE t.deleted_at IS NULL
                ";

                $params = [];

                if ($search) {
                    $query .= " AND t.name ILIKE ?";
                    $params[] = "%$search%";
                }

                if ($season_id) {
                    // Support both season_id and program_id for backward compatibility
                    $query .= " AND (t.season_id = ? OR t.program_id = ?)";
                    $params[] = $season_id;
                    $params[] = $season_id;
                }

                if ($age_group) {
                    $query .= " AND t.age_group = ?";
                    $params[] = $age_group;
                }

                if ($division) {
                    $query .= " AND t.division = ?";
                    $params[] = $division;
                }

                if ($primary_coach_id) {
                    $query .= " AND t.primary_coach_id = ?";
                    $params[] = $primary_coach_id;
                }

                // Apply club scoping - show teams from user's accessible clubs
                // For super admins, use active context club instead of showing all
                $activeContext = $auth->getActiveContext();
                $activeClubId = is_object($activeContext) ? ($activeContext->scope_id ?? null) : ($activeContext['scope_id'] ?? null);

                if ($activeClubId) {
                    $query .= " AND t.club_id = ?";
                    $params[] = (int)$activeClubId;
                } else {
                    $clubScope = $auth->getClubScopeWhereClause('t.club_id');
                    $query .= " " . $clubScope['where'];
                    $params = array_merge($params, $clubScope['params']);
                }

                $query .= " GROUP BY t.id, t.name, t.program_id, t.season_id, t.primary_coach_id, t.division,
                                     t.skill_level, t.age_group, t.gender, t.max_players, t.team_color,
                                     t.logo_url, t.status, t.created_at, t.updated_at, t.club_id, t.home_field_id,
                                     s.name, u.first_name, u.last_name, f.name
                            ORDER BY t.created_at DESC";

                $stmt = $connection->prepare($query);
                $stmt->execute($params);
                $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode(['teams' => $teams]);
            }
            break;

        case 'POST':
            $data = json_decode(file_get_contents("php://input"), true);

            // Determine club_id from active context
            $clubId = null;

            // Get active context from auth
            $activeContext = $auth->getActiveContext();

            if ($activeContext && $activeContext->scope_type === 'club') {
                $clubId = $activeContext->scope_id;
            }

            // Check if user can create teams
            if (!$auth->can('create_team', $clubId, 'club')) {
                http_response_code(403);
                echo json_encode(['error' => 'You do not have permission to create teams']);
                exit();
            }

            // program_id is optional, defaults to null
            $program_id = $data['program_id'] ?? null;

            // Branding: BrandingTab submits primary_color + logo_data (base64).
            // team_color is mirrored from primary_color so legacy readers keep
            // working. Logo is written to uploads/team-logos/ when base64 was
            // sent; pre-uploaded URLs pass through.
            $logoUrl = resolveTeamLogoUrl($data);
            $primaryColor   = $data['primary_color']   ?? $data['team_color'] ?? '#3b82f6';
            $secondaryColor = $data['secondary_color'] ?? null;
            $accentColor    = $data['accent_color']    ?? null;

            $stmt = $connection->prepare("
                INSERT INTO teams (name, program_id, season_id, primary_coach_id, age_group, division,
                                 max_players, team_color, logo_url, skill_level, gender, status,
                                 club_id,
                                 primary_color, secondary_color, accent_color,
                                 social_facebook, social_instagram, social_twitter,
                                 social_tiktok, social_youtube, social_linkedin)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $data['name'],
                $program_id,
                isset($data['season_id']) && $data['season_id'] ? $data['season_id'] : null,
                isset($data['primary_coach_id']) && $data['primary_coach_id'] ? $data['primary_coach_id'] : null,
                $data['age_group'] ?? null,
                $data['division'] ?? null,
                $data['max_players'] ?? 20,
                $primaryColor,
                $logoUrl,
                $data['skill_level'] ?? 'Beginner',
                $data['gender'] ?? 'Mixed',
                $data['status'] ?? 'forming',
                $clubId,
                $primaryColor,
                $secondaryColor,
                $accentColor,
                $data['social_facebook']  ?? null,
                $data['social_instagram'] ?? null,
                $data['social_twitter']   ?? null,
                $data['social_tiktok']    ?? null,
                $data['social_youtube']   ?? null,
                $data['social_linkedin']  ?? null,
            ]);

            echo json_encode([
                'success' => true,
                'id' => $connection->lastInsertId(),
                'message' => 'Team created successfully'
            ]);
            break;

        case 'PUT':
            if (!$team_id) {
                throw new Exception('Team ID required for update');
            }

            // Get team to check access
            $stmt = $connection->prepare("SELECT club_id FROM teams WHERE id = ?");
            $stmt->execute([$team_id]);
            $team = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$team) {
                http_response_code(404);
                echo json_encode(['error' => 'Team not found']);
                exit();
            }

            // Check if user can edit this team
            if (!$auth->can('edit_team', $team['club_id'], 'club')) {
                http_response_code(403);
                echo json_encode(['error' => 'You do not have permission to edit this team']);
                exit();
            }

            $data = json_decode(file_get_contents("php://input"), true);

            // Branding: BrandingTab submits primary_color + logo_data (base64).
            // team_color mirrors primary_color for backward-compat readers.
            // Logo: if logo_data was sent, write it and use the new URL.
            // Otherwise pass through whatever logo_url was sent. If neither
            // was sent, we *preserve* the existing logo_url instead of
            // wiping it (the previous version of this handler did, which is
            // why the Branding tab destroyed the logo on every save of the
            // Info tab).
            $logoUrl = resolveTeamLogoUrl($data);
            if ($logoUrl === null && !array_key_exists('logo_url', $data) && !array_key_exists('logo_data', $data)) {
                // Nothing logo-related submitted — preserve existing.
                $existing = $connection->prepare("SELECT logo_url FROM teams WHERE id = ?");
                $existing->execute([$team_id]);
                $existingRow = $existing->fetch(PDO::FETCH_ASSOC);
                $logoUrl = $existingRow ? $existingRow['logo_url'] : null;
            }
            $primaryColor   = $data['primary_color']   ?? $data['team_color'] ?? '#3b82f6';
            $secondaryColor = $data['secondary_color'] ?? null;
            $accentColor    = $data['accent_color']    ?? null;

            $stmt = $connection->prepare("
                UPDATE teams
                SET name = ?, age_group = ?, division = ?, season_id = ?, primary_coach_id = ?,
                    max_players = ?, team_color = ?, logo_url = ?, skill_level = ?, gender = ?, status = ?,
                    home_field_id = ?,
                    primary_color = ?, secondary_color = ?, accent_color = ?,
                    social_facebook = ?, social_instagram = ?, social_twitter = ?,
                    social_tiktok = ?, social_youtube = ?, social_linkedin = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $data['name'],
                $data['age_group'] ?? null,
                $data['division'] ?? null,
                isset($data['season_id']) && $data['season_id'] ? $data['season_id'] : null,
                isset($data['primary_coach_id']) && $data['primary_coach_id'] ? $data['primary_coach_id'] : null,
                $data['max_players'] ?? 20,
                $primaryColor,
                $logoUrl,
                $data['skill_level'] ?? 'Beginner',
                $data['gender'] ?? 'Mixed',
                $data['status'] ?? 'forming',
                isset($data['home_field_id']) && $data['home_field_id'] ? $data['home_field_id'] : null,
                $primaryColor,
                $secondaryColor,
                $accentColor,
                $data['social_facebook'] ?? null,
                $data['social_instagram'] ?? null,
                $data['social_twitter'] ?? null,
                $data['social_tiktok'] ?? null,
                $data['social_youtube'] ?? null,
                $data['social_linkedin'] ?? null,
                $team_id
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Team updated successfully'
            ]);
            break;

        case 'DELETE':
            if (!$team_id) {
                throw new Exception('Team ID required for deletion');
            }

            // Get team to check access
            $stmt = $connection->prepare("SELECT club_id FROM teams WHERE id = ?");
            $stmt->execute([$team_id]);
            $team = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$team) {
                http_response_code(404);
                echo json_encode(['error' => 'Team not found']);
                exit();
            }

            // Check if user can delete this team
            if (!$auth->can('delete_team', $team['club_id'], 'club')) {
                http_response_code(403);
                echo json_encode(['error' => 'You do not have permission to delete this team']);
                exit();
            }

            // Soft delete — set deleted_at instead of removing the record
            $stmt = $connection->prepare("UPDATE teams SET deleted_at = NOW(), deleted_by = ? WHERE id = ?");
            $stmt->execute([$auth->getUserId(), $team_id]);

            echo json_encode([
                'success' => true,
                'message' => 'Team archived successfully'
            ]);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>