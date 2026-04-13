<?php
/**
 * Imports Gateway API
 *
 * Bulk CSV imports. Supports athletes + guardians family-row format with
 * user-configurable column mapping.
 *
 * Actions:
 *   POST ?action=preview-athletes  — upload CSV, get headers + auto-detected
 *                                    mapping + preview rows (stateless)
 *   POST ?action=upload-athletes   — upload CSV with column_mapping, enqueue job
 *   GET  ?action=status            — poll job status
 */

require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/RedisQueue.php';

const IMPORT_REQUIRED_DEST_FIELDS = [
    'athlete_first_name',
    'athlete_last_name',
    'athlete_dob',
    'athlete_gender',
    'guardian1_first_name',
    'guardian1_last_name',
    'guardian1_email',
    'guardian1_mobile',
];

const IMPORT_OPTIONAL_DEST_FIELDS = [
    'athlete_grade_level',
    'athlete_school',
    'guardian1_relationship',
    'guardian1_is_primary',
    'guardian2_first_name',
    'guardian2_last_name',
    'guardian2_email',
    'guardian2_mobile',
    'guardian2_relationship',
];

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
        case 'preview-athletes':
            if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }
            handleAthletePreview();
            break;

        case 'upload-athletes':
            if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }
            handleAthleteUpload($auth, $pdo);
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

function normalizeHeader(string $s): string {
    return preg_replace('/[^a-z0-9]/', '', strtolower($s));
}

function autoDetectMapping(array $headers): array {
    // Synonym groups: destination => list of normalized header patterns that should match
    $synonyms = [
        'athlete_first_name' => ['athletefirstname', 'playerfirstname', 'childfirstname', 'firstname', 'first', 'givenname'],
        'athlete_last_name'  => ['athletelastname', 'playerlastname', 'childlastname', 'lastname', 'last', 'surname', 'familyname'],
        'athlete_dob'        => ['athletedob', 'dob', 'dateofbirth', 'birthdate', 'birthday'],
        'athlete_gender'     => ['athletegender', 'gender', 'sex'],
        'athlete_grade_level' => ['athletegradelevel', 'gradelevel', 'grade'],
        'athlete_school'     => ['athleteschool', 'schoolname', 'school'],
        'guardian1_first_name' => ['guardian1firstname', 'parent1firstname', 'parentfirstname', 'guardianfirstname', 'primaryparentfirstname'],
        'guardian1_last_name'  => ['guardian1lastname', 'parent1lastname', 'parentlastname', 'guardianlastname', 'primaryparentlastname'],
        'guardian1_email'      => ['guardian1email', 'parent1email', 'parentemail', 'guardianemail', 'primaryparentemail', 'email'],
        'guardian1_mobile'     => ['guardian1mobile', 'parent1mobile', 'parent1phone', 'parentmobile', 'parentphone', 'guardianmobile', 'guardianphone', 'mobile', 'phone', 'cell'],
        'guardian1_relationship' => ['guardian1relationship', 'parent1relationship', 'relationship'],
        'guardian1_is_primary' => ['guardian1isprimary', 'guardian1primary', 'isprimary', 'primarycontact'],
        'guardian2_first_name' => ['guardian2firstname', 'parent2firstname', 'secondaryparentfirstname'],
        'guardian2_last_name'  => ['guardian2lastname', 'parent2lastname', 'secondaryparentlastname'],
        'guardian2_email'      => ['guardian2email', 'parent2email', 'secondaryparentemail'],
        'guardian2_mobile'     => ['guardian2mobile', 'parent2mobile', 'parent2phone', 'secondaryparentmobile'],
        'guardian2_relationship' => ['guardian2relationship', 'parent2relationship'],
    ];

    $normalizedHeaders = [];
    foreach ($headers as $h) {
        $normalizedHeaders[normalizeHeader($h)] = $h;
    }

    $mapping = [];
    foreach ($synonyms as $dest => $candidates) {
        foreach ($candidates as $cand) {
            if (isset($normalizedHeaders[$cand])) {
                $mapping[$dest] = $normalizedHeaders[$cand];
                break;
            }
        }
    }
    return $mapping;
}

function validateMapping(array $mapping, array $headers): array {
    $errors = [];
    foreach (IMPORT_REQUIRED_DEST_FIELDS as $dest) {
        if (!isset($mapping[$dest]) || $mapping[$dest] === '') {
            $errors[] = "Required field '{$dest}' is not mapped";
            continue;
        }
        if (!in_array($mapping[$dest], $headers, true)) {
            $errors[] = "Mapped column '{$mapping[$dest]}' for '{$dest}' is not in the CSV headers";
        }
    }
    return $errors;
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

function handleAthletePreview(): void {
    $content = readUploadedCsv();
    $parsed = parseCsvHeadersAndRows($content, 5);
    $mapping = autoDetectMapping($parsed['headers']);

    echo json_encode([
        'success'            => true,
        'headers'            => $parsed['headers'],
        'suggested_mapping'  => $mapping,
        'required_fields'    => IMPORT_REQUIRED_DEST_FIELDS,
        'optional_fields'    => IMPORT_OPTIONAL_DEST_FIELDS,
        'preview_rows'       => $parsed['rows'],
        'total_rows'         => $parsed['total'],
    ]);
}

function handleAthleteUpload(AuthMiddleware $auth, PDO $pdo): void {
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

    $parsed = parseCsvHeadersAndRows($content, 0);
    $mappingErrors = validateMapping($mapping, $parsed['headers']);
    if (!empty($mappingErrors)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid column mapping', 'details' => $mappingErrors]);
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO import_jobs
            (user_id, club_profile_id, team_id, entity_type, status, original_filename, csv_content, column_mapping, total_rows)
        VALUES
            (:user_id, :club_profile_id, :team_id, 'athletes', 'queued', :filename, :csv, :mapping, :total)
        RETURNING id
    ");
    $stmt->execute([
        'user_id'         => $auth->getUserId(),
        'club_profile_id' => $clubProfileId,
        'team_id'         => $teamId,
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
