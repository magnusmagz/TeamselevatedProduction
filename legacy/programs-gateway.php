<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();


// Use centralized database connection
require_once __DIR__ . '/../config/database.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// This legacy gateway is redundant with registration/programs-api.php and has no
// frontend caller, but it is directly reachable — require auth so it can't be used
// for anonymous program CRUD across clubs.
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/club_standing.php';
require_once __DIR__ . '/../lib/program_ordering.php';
require_once __DIR__ . '/../lib/AuditLogger.php';
$auth = AuthMiddleware::requireAuth();
$accessibleClubIds = $auth->getAccessibleClubIds(); // null = super admin

$method = $_SERVER['REQUEST_METHOD'];

/**
 * Resolve a program's club, or null when it does not exist.
 *
 * Authorisation for archive/unarchive is `te_is_club_admin` against THIS club —
 * never against a club_id in the request body. Club membership (`canAccessClub`)
 * is deliberately not enough: a `parent` row satisfies that, and hiding a club's
 * programs from every screen is club-wide staff work.
 */
function pg_programClubId(PDO $pdo, int $programId): ?int
{
    $stmt = $pdo->prepare('SELECT club_id FROM programs WHERE id = ?');
    $stmt->execute([$programId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    return $row['club_id'] === null ? null : (int)$row['club_id'];
}

function pg_fail(int $status, string $error, array $extra = []): void
{
    http_response_code($status);
    echo json_encode(array_merge(['success' => false, 'error' => $error], $extra));
    exit;
}

/**
 * The three write actions added for CKU R89/R90. They live in front of the
 * method switch because that switch dispatches on REQUEST_METHOD alone and every
 * one of these is a POST.
 */
$action = $_GET['action'] ?? '';
if ($method === 'POST' && in_array($action, ['archive', 'unarchive', 'reorder'], true)) {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $actorId = (int)($auth->getUserId() ?? 0) ?: null;

    try {
        if ($action === 'reorder') {
            $ids = $body['program_ids'] ?? null;
            if (!is_array($ids) || $ids === []) {
                pg_fail(400, 'program_ids must be a non-empty array of program ids');
            }

            // The club comes from the FIRST program, then every other id is
            // verified against it inside te_program_reorder(). Taking it from
            // the body would let an admin of club A renumber club B by naming
            // their own club.
            $firstId = (int)$ids[0];
            $clubId = $firstId > 0 ? pg_programClubId($pdo, $firstId) : null;
            if ($clubId === null) {
                pg_fail(404, 'Program not found');
            }
            if (!te_is_club_admin($auth, $clubId)) {
                pg_fail(403, 'Forbidden: club admin required');
            }

            $result = te_program_reorder($pdo, $ids, $clubId);
            if (!$result['ok']) {
                if ($result['reason'] === 'schema') {
                    pg_fail(503, 'Program ordering is not available yet');
                }
                if ($result['reason'] === 'foreign_club') {
                    pg_fail(403, 'Forbidden: one or more programs belong to another club', [
                        'foreign_program_ids' => $result['foreign'] ?? [],
                    ]);
                }
                pg_fail(400, 'Nothing to reorder');
            }

            AuditLogger::log($pdo, $actorId, 'programs_reordered', 'programs', null, [
                'club_id' => $clubId,
                'program_ids' => array_map('intval', $ids),
            ]);

            echo json_encode([
                'success' => true,
                'updated' => $result['updated'],
                'club_id' => $clubId,
            ]);
            exit;
        }

        // archive / unarchive
        $programId = (int)($body['id'] ?? $_GET['id'] ?? 0);
        if ($programId <= 0) {
            pg_fail(400, 'Program ID required');
        }
        $clubId = pg_programClubId($pdo, $programId);
        if ($clubId === null) {
            pg_fail(404, 'Program not found');
        }
        if (!te_is_club_admin($auth, $clubId)) {
            pg_fail(403, 'Forbidden: club admin required');
        }

        $archiving = ($action === 'archive');
        $result = te_program_set_archived($pdo, $programId, $archiving, $actorId);
        if (!$result['ok']) {
            if ($result['reason'] === 'schema') {
                pg_fail(503, 'Program archiving is not available yet');
            }
            pg_fail(404, 'Program not found');
        }

        AuditLogger::log(
            $pdo,
            $actorId,
            $archiving ? 'program_archived' : 'program_unarchived',
            'programs',
            $programId,
            ['club_id' => $clubId]
        );

        echo json_encode([
            'success' => true,
            'id' => $programId,
            'archived' => $archiving,
        ]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

try {
    switch ($method) {
        case 'GET':
            if (isset($_GET['id'])) {
                // Get specific program
                $stmt = $pdo->prepare("
                    SELECT p.*,
                           COUNT(DISTINCT t.id) as team_count
                    FROM programs p
                    LEFT JOIN teams t ON p.id = t.program_id
                    WHERE p.id = ?
                    GROUP BY p.id
                ");
                $stmt->execute([$_GET['id']]);
                $program = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($program) {
                    // Get teams for this program
                    $teamStmt = $pdo->prepare("
                        SELECT t.*, u.first_name as coach_first_name, u.last_name as coach_last_name
                        FROM teams t
                        LEFT JOIN users u ON t.primary_coach_id = u.id
                        WHERE t.program_id = ?
                    ");
                    $teamStmt->execute([$_GET['id']]);
                    $program['teams'] = $teamStmt->fetchAll(PDO::FETCH_ASSOC);

                    echo json_encode($program);
                } else {
                    http_response_code(404);
                    echo json_encode(['error' => 'Program not found']);
                }
            } else {
                // Get all programs, optionally filtered
                $whereClause = "WHERE 1=1";
                $params = [];

                if (isset($_GET['season_year'])) {
                    $whereClause .= " AND p.season_year = ?";
                    $params[] = $_GET['season_year'];
                }

                if (isset($_GET['season_type'])) {
                    $whereClause .= " AND p.season_type = ?";
                    $params[] = $_GET['season_type'];
                }

                if (isset($_GET['type'])) {
                    $whereClause .= " AND p.type = ?";
                    $params[] = $_GET['type'];
                }

                if (isset($_GET['status'])) {
                    $whereClause .= " AND p.status = ?";
                    $params[] = $_GET['status'];
                }

                // Archived programs are hidden by default and returned only on
                // an explicit ?include_archived=1. The fragment is empty when
                // migration 084 has not been applied yet, so the list keeps
                // working rather than 42703-ing on a column that isn't there.
                $includeArchived = te_program_include_archived_requested($_GET['include_archived'] ?? null);
                $whereClause .= te_program_archive_filter($pdo, $includeArchived);

                // Manual order first (NULLS LAST — a program nobody has moved
                // keeps the order it always had), then the existing sort.
                //
                // The CASE below is not a style choice: FIELD() is MySQL, Postgres
                // has no such function, and this ORDER BY threw 42883 and 500'd the
                // Programs list for EVERY user until 2026-08-04. The same
                // substitution is in api/athletes-profile.php.
                $orderBy = te_program_order_by($pdo, "p.season_year DESC,
                             CASE p.season_type
                                 WHEN 'Spring' THEN 1
                                 WHEN 'Summer' THEN 2
                                 WHEN 'Fall' THEN 3
                                 WHEN 'Winter' THEN 4
                                 WHEN 'Year-Round' THEN 5
                                 ELSE 6
                             END,
                             p.name");

                $stmt = $pdo->prepare("
                    SELECT p.*,
                           COUNT(DISTINCT t.id) as team_count,
                           COUNT(DISTINCT tp.user_id) as player_count
                    FROM programs p
                    LEFT JOIN teams t ON p.id = t.program_id
                    LEFT JOIN team_members tp ON t.id = tp.team_id
                    $whereClause
                    GROUP BY p.id
                    ORDER BY $orderBy
                ");
                $stmt->execute($params);
                $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode(['success' => true, 'programs' => $programs]);
            }
            break;

        case 'POST':
            $data = json_decode(file_get_contents('php://input'), true);

            $stmt = $pdo->prepare("
                INSERT INTO programs (
                    club_id, name, type, description,
                    season_year, season_type, is_recurring,
                    start_date, end_date,
                    registration_opens, registration_closes,
                    min_age, max_age, capacity, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $data['club_id'] ?? 1,
                $data['name'],
                $data['type'] ?? 'league',
                $data['description'] ?? null,
                $data['season_year'] ?? date('Y'),
                $data['season_type'] ?? 'Year-Round',
                $data['is_recurring'] ?? false,
                $data['start_date'] ?? null,
                $data['end_date'] ?? null,
                $data['registration_opens'] ?? null,
                $data['registration_closes'] ?? null,
                $data['min_age'] ?? null,
                $data['max_age'] ?? null,
                $data['capacity'] ?? null,
                $data['status'] ?? 'draft'
            ]);

            echo json_encode([
                'success' => true,
                'id' => $pdo->lastInsertId(),
                'message' => 'Program created successfully'
            ]);
            break;

        case 'PUT':
            if (!isset($_GET['id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Program ID required']);
                exit;
            }

            $data = json_decode(file_get_contents('php://input'), true);

            $stmt = $pdo->prepare("
                UPDATE programs SET
                    name = ?,
                    type = ?,
                    description = ?,
                    season_year = ?,
                    season_type = ?,
                    is_recurring = ?,
                    start_date = ?,
                    end_date = ?,
                    registration_opens = ?,
                    registration_closes = ?,
                    min_age = ?,
                    max_age = ?,
                    capacity = ?,
                    status = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");

            $stmt->execute([
                $data['name'],
                $data['type'] ?? 'league',
                $data['description'] ?? null,
                $data['season_year'] ?? date('Y'),
                $data['season_type'] ?? 'Year-Round',
                $data['is_recurring'] ?? false,
                $data['start_date'] ?? null,
                $data['end_date'] ?? null,
                $data['registration_opens'] ?? null,
                $data['registration_closes'] ?? null,
                $data['min_age'] ?? null,
                $data['max_age'] ?? null,
                $data['capacity'] ?? null,
                $data['status'] ?? 'draft',
                $_GET['id']
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Program updated successfully'
            ]);
            break;

        case 'DELETE':
            if (!isset($_GET['id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Program ID required']);
                exit;
            }

            // Check if program has teams
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM teams WHERE program_id = ?");
            $checkStmt->execute([$_GET['id']]);
            $teamCount = $checkStmt->fetchColumn();

            if ($teamCount > 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Cannot delete program with existing teams']);
                exit;
            }

            $stmt = $pdo->prepare("DELETE FROM programs WHERE id = ?");
            $stmt->execute([$_GET['id']]);

            echo json_encode([
                'success' => true,
                'message' => 'Program deleted successfully'
            ]);
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch(Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>