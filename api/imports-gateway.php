<?php
/**
 * Imports Gateway API
 *
 * Generic bulk CSV import gateway. Dispatches to per-entity strategies
 * registered in ImportJobProcessor.
 *
 * Actions:
 *   GET  ?action=entities                          — list supported entity types
 *   GET  ?action=teams&club_profile_id=X           — list club-scoped teams the user can target
 *   POST ?action=preview&entity=X                  — upload CSV, get headers + auto-detected mapping + preview rows
 *   POST ?action=upload&entity=X                   — upload CSV with column_mapping, enqueue job
 *   GET  ?action=status&job_id=X                   — poll job status
 *
 * Legacy aliases (kept for zero-downtime frontend/backend drift):
 *   POST ?action=preview-athletes   → preview&entity=athletes
 *   POST ?action=upload-athletes    → upload&entity=athletes
 */

require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/RedisQueue.php';
require_once __DIR__ . '/../services/ImportJobProcessor.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

$auth = AuthMiddleware::requireAuth();
$processor = ImportJobProcessor::buildDefault($pdo);

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;
$entity = $_GET['entity'] ?? null;

// Legacy aliases — resolve them to the generic action + entity pair.
if ($action === 'preview-athletes') { $action = 'preview'; $entity = 'athletes'; }
elseif ($action === 'upload-athletes') { $action = 'upload'; $entity = 'athletes'; }

try {
    switch ($action) {
        case 'entities':
            if ($method !== 'GET') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }
            echo json_encode(['success' => true, 'entities' => $processor->getEntityTypes()]);
            break;

        case 'teams':
            if ($method !== 'GET') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }
            handleTeamsList($auth, $pdo);
            break;

        case 'preview':
            if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }
            handlePreview($processor, $entity);
            break;

        case 'upload':
            if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }
            handleUpload($auth, $pdo, $processor, $entity);
            break;

        case 'status':
            if ($method !== 'GET') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }
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

// ─────────────────────────────────────────────────────────────────────
// Helpers

function resolveStrategy(ImportJobProcessor $processor, ?string $entity): ImportStrategy {
    if ($entity === null || $entity === '') {
        http_response_code(400);
        echo json_encode(['error' => 'entity parameter is required']);
        exit;
    }
    $strategy = $processor->getStrategy($entity);
    if (!$strategy) {
        http_response_code(400);
        echo json_encode(['error' => "Unknown entity type '{$entity}'"]);
        exit;
    }
    return $strategy;
}

function parseCsvHeadersAndRows(string $content, int $previewLimit = 5): array {
    $lines = preg_split("/\r\n|\n|\r/", trim($content));
    if (count($lines) < 1) return ['headers' => [], 'rows' => [], 'total' => 0];

    $headers = array_map('trim', str_getcsv(array_shift($lines), ',', '"', '\\'));

    $totalDataRows = 0;
    $preview = [];
    foreach ($lines as $line) {
        if (trim($line) === '') continue;
        $totalDataRows++;
        if (count($preview) < $previewLimit) {
            $values = str_getcsv($line, ',', '"', '\\');
            $values = array_pad($values, count($headers), '');
            $preview[] = array_combine($headers, array_map('trim', $values));
        }
    }
    return ['headers' => $headers, 'rows' => $preview, 'total' => $totalDataRows];
}

function readUploadedCsv(): string {
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('No file uploaded or upload error');
    }
    if ($_FILES['file']['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('File exceeds 5MB limit');
    }
    $content = file_get_contents($_FILES['file']['tmp_name']);
    if ($content === false || trim($content) === '') {
        throw new RuntimeException('Could not read uploaded file');
    }
    return $content;
}

// ─────────────────────────────────────────────────────────────────────
// Handlers

function handleTeamsList(AuthMiddleware $auth, PDO $pdo): void {
    $clubProfileId = isset($_GET['club_profile_id']) ? (int) $_GET['club_profile_id'] : 0;
    if ($clubProfileId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'club_profile_id is required']);
        return;
    }

    if (!$auth->canAccessClub($clubProfileId) && !$auth->isSuperAdmin()) {
        http_response_code(403);
        echo json_encode(['error' => 'No access to this club']);
        return;
    }

    $isClubAdmin = $auth->hasRole('club_admin', $clubProfileId, 'club') || $auth->isSuperAdmin();

    if ($isClubAdmin) {
        $stmt = $pdo->prepare('
            SELECT id, name
            FROM teams
            WHERE club_id = :club AND deleted_at IS NULL
            ORDER BY name
        ');
        $stmt->execute(['club' => $clubProfileId]);
    } else {
        $stmt = $pdo->prepare("
            SELECT DISTINCT t.id, t.name
            FROM teams t
            LEFT JOIN team_members tm
                ON tm.team_id = t.id
               AND tm.user_id = :user_id
               AND tm.role IN ('assistant_coach', 'team_manager')
            WHERE t.club_id = :club
              AND t.deleted_at IS NULL
              AND (t.primary_coach_id = :user_id OR tm.id IS NOT NULL)
            ORDER BY t.name
        ");
        $stmt->execute(['user_id' => $auth->getUserId(), 'club' => $clubProfileId]);
    }

    echo json_encode(['success' => true, 'teams' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function handlePreview(ImportJobProcessor $processor, ?string $entity): void {
    $strategy = resolveStrategy($processor, $entity);
    $content = readUploadedCsv();
    $parsed = parseCsvHeadersAndRows($content, 5);
    $mapping = $strategy->autoDetectMapping($parsed['headers']);

    echo json_encode([
        'success'           => true,
        'entity'            => $strategy->getEntityType(),
        'headers'           => $parsed['headers'],
        'suggested_mapping' => $mapping,
        'required_fields'   => $strategy->getRequiredFields(),
        'optional_fields'   => $strategy->getOptionalFields(),
        'field_labels'      => $strategy->getFieldLabels(),
        'preview_rows'      => $parsed['rows'],
        'total_rows'        => $parsed['total'],
    ]);
}

function handleUpload(AuthMiddleware $auth, PDO $pdo, ImportJobProcessor $processor, ?string $entity): void {
    $strategy = resolveStrategy($processor, $entity);
    $content = readUploadedCsv();

    $clubProfileId = isset($_POST['club_profile_id']) ? (int) $_POST['club_profile_id'] : 0;
    $teamId = isset($_POST['team_id']) && $_POST['team_id'] !== '' ? (int) $_POST['team_id'] : null;
    $mappingRaw = $_POST['column_mapping'] ?? '';
    $mapping = $mappingRaw !== '' ? json_decode($mappingRaw, true) : null;

    if (!is_array($mapping)) {
        http_response_code(400);
        echo json_encode(['error' => 'column_mapping is required and must be JSON']);
        return;
    }

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
        $teamCheck = $pdo->prepare('SELECT club_id FROM teams WHERE id = :id AND deleted_at IS NULL');
        $teamCheck->execute(['id' => $teamId]);
        $teamRow = $teamCheck->fetch(PDO::FETCH_ASSOC);
        if (!$teamRow || (int) $teamRow['club_id'] !== $clubProfileId) {
            http_response_code(403);
            echo json_encode(['error' => 'Team not found or not in this club']);
            return;
        }
    }

    $isClubAdmin = $auth->hasRole('club_admin', $clubProfileId, 'club') || $auth->isSuperAdmin();
    $isCoach     = $auth->hasRole('coach', $clubProfileId, 'club');

    if (!$isClubAdmin && !$isCoach) {
        http_response_code(403);
        echo json_encode(['error' => 'club_admin or coach role required']);
        return;
    }

    if ($strategy->requiresUploadTeamId() && $isCoach && !$isClubAdmin && $teamId === null) {
        http_response_code(400);
        echo json_encode(['error' => 'Coaches must select a team for the import']);
        return;
    }

    $parsed = parseCsvHeadersAndRows($content, 0);
    $mappingErrors = $strategy->validateMapping($mapping, $parsed['headers']);
    if (!empty($mappingErrors)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid column mapping', 'details' => $mappingErrors]);
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO import_jobs
            (user_id, club_profile_id, team_id, entity_type, status, original_filename, csv_content, column_mapping, total_rows)
        VALUES
            (:user_id, :club_profile_id, :team_id, :entity, 'queued', :filename, :csv, :mapping, :total)
        RETURNING id
    ");
    $stmt->execute([
        'user_id'         => $auth->getUserId(),
        'club_profile_id' => $clubProfileId,
        'team_id'         => $teamId,
        'entity'          => $strategy->getEntityType(),
        'filename'        => $_FILES['file']['name'],
        'csv'             => $content,
        'mapping'         => json_encode($mapping),
        'total'           => $parsed['total'],
    ]);
    $jobId = (int) $stmt->fetchColumn();

    try {
        $redis = RedisQueue::getInstance();
        $redis->push('import_queue', [
            'id'           => 'import_' . $jobId,
            'type'         => $strategy->getEntityType() . '_import',
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
        'total_rows' => $parsed['total'],
        'status'     => 'queued',
    ]);
}

function handleStatus(AuthMiddleware $auth, PDO $pdo): void {
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
