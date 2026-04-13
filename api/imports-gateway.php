<?php
/**
 * Imports Gateway API
 *
 * Bulk CSV imports. Thin slice supports athletes + guardians family-row format.
 * Future: column mapping, preview, dry-run, additional entity types.
 */

require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/RedisQueue.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

$auth = AuthMiddleware::requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;

try {
    switch ($action) {
        case 'upload-athletes':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit;
            }
            handleAthleteUpload($auth, $pdo);
            break;

        case 'status':
            if ($method !== 'GET') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit;
            }
            handleStatus($auth, $pdo);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

function handleAthleteUpload(AuthMiddleware $auth, PDO $pdo) {
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => 'No file uploaded or upload error']);
        return;
    }

    $file = $_FILES['file'];

    if ($file['size'] > 5 * 1024 * 1024) {
        http_response_code(400);
        echo json_encode(['error' => 'File exceeds 5MB limit']);
        return;
    }

    $clubProfileId = isset($_POST['club_profile_id']) ? (int) $_POST['club_profile_id'] : 0;
    $teamId = isset($_POST['team_id']) && $_POST['team_id'] !== '' ? (int) $_POST['team_id'] : null;

    if ($clubProfileId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'club_profile_id is required']);
        return;
    }

    if (!$auth->canAccessClub($clubProfileId) && !$auth->isSuperAdmin()) {
        http_response_code(403);
        echo json_encode(['error' => 'You do not have access to this club']);
        return;
    }

    if ($teamId !== null) {
        $teamCheck = $pdo->prepare('SELECT club_profile_id FROM teams WHERE id = :id');
        $teamCheck->execute(['id' => $teamId]);
        $teamRow = $teamCheck->fetch(PDO::FETCH_ASSOC);
        if (!$teamRow || (int) $teamRow['club_profile_id'] !== $clubProfileId) {
            http_response_code(403);
            echo json_encode(['error' => 'Team not found or not in this club']);
            return;
        }
    }

    $isClubAdmin = $auth->hasRole('club_admin', $clubProfileId, 'club') || $auth->isSuperAdmin();
    $isCoach = $auth->hasRole('coach', $clubProfileId, 'club');

    if (!$isClubAdmin && !$isCoach) {
        http_response_code(403);
        echo json_encode(['error' => 'club_admin or coach role required']);
        return;
    }

    if ($isCoach && !$isClubAdmin && $teamId === null) {
        http_response_code(400);
        echo json_encode(['error' => 'Coaches must select a team for the import']);
        return;
    }

    $csvContent = file_get_contents($file['tmp_name']);
    if ($csvContent === false || trim($csvContent) === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Could not read uploaded file']);
        return;
    }

    $rowCount = max(0, substr_count($csvContent, "\n") - 1);

    $stmt = $pdo->prepare("
        INSERT INTO import_jobs
            (user_id, club_profile_id, team_id, entity_type, status, original_filename, csv_content, total_rows)
        VALUES
            (:user_id, :club_profile_id, :team_id, 'athletes', 'queued', :filename, :csv, :total)
        RETURNING id
    ");
    $stmt->execute([
        'user_id'         => $auth->getUserId(),
        'club_profile_id' => $clubProfileId,
        'team_id'         => $teamId,
        'filename'        => $file['name'],
        'csv'             => $csvContent,
        'total'           => $rowCount,
    ]);
    $jobId = (int) $stmt->fetchColumn();

    try {
        $redis = RedisQueue::getInstance();
        $redis->push('import_queue', [
            'id'           => 'import_' . $jobId,
            'type'         => 'athlete_import',
            'job_id'       => $jobId,
            'max_attempts' => 1,
        ]);
    } catch (Exception $e) {
        $pdo->prepare("UPDATE import_jobs SET status = 'failed', finished_at = NOW() WHERE id = :id")
            ->execute(['id' => $jobId]);
        http_response_code(500);
        echo json_encode(['error' => 'Failed to enqueue import job: ' . $e->getMessage()]);
        return;
    }

    echo json_encode([
        'success'    => true,
        'job_id'     => $jobId,
        'total_rows' => $rowCount,
        'status'     => 'queued',
    ]);
}

function handleStatus(AuthMiddleware $auth, PDO $pdo) {
    $jobId = isset($_GET['job_id']) ? (int) $_GET['job_id'] : 0;
    if ($jobId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'job_id required']);
        return;
    }

    $stmt = $pdo->prepare('
        SELECT id, user_id, club_profile_id, team_id, entity_type, status,
               original_filename, total_rows, processed_rows,
               created_count, updated_count, skipped_count, error_count,
               created_at, started_at, finished_at
        FROM import_jobs
        WHERE id = :id
    ');
    $stmt->execute(['id' => $jobId]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$job) {
        http_response_code(404);
        echo json_encode(['error' => 'Job not found']);
        return;
    }

    if (!$auth->canAccessClub((int) $job['club_profile_id']) && !$auth->isSuperAdmin()) {
        http_response_code(403);
        echo json_encode(['error' => 'No access to this job']);
        return;
    }

    $errStmt = $pdo->prepare('
        SELECT row_number, error_message, row_json
        FROM import_job_errors
        WHERE job_id = :id
        ORDER BY row_number
        LIMIT 100
    ');
    $errStmt->execute(['id' => $jobId]);
    $errors = $errStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'job'     => $job,
        'errors'  => $errors,
    ]);
}
