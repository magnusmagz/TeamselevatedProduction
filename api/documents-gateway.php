<?php
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();
/**
 * Documents Gateway — unified API for the documents/document_assignments/
 * document_acknowledgments schema (migration 032).
 *
 * Replaces the older club-document-center.php and club-documents-gateway.php
 * which both wrote to the legacy `club_documents` table. This gateway is
 * scoped at the club level (every doc has a club_profile_id) and supports
 * many-to-many assignment to club / team / athlete / user targets — `user`
 * covers BOTH coaches and volunteers.
 *
 * Endpoints (all return JSON):
 *
 *   Admin / writes
 *     GET    ?club_profile_id={id}                           List club's docs grouped by slot, with assignment summary
 *     GET    ?id={id}                                        Single doc with full assignment list
 *     POST                                                   Create doc (body: club_profile_id, title, file_path|link_url, slot, ...; optional assignments[])
 *     PUT    ?id={id}                                        Update doc metadata
 *     DELETE ?id={id}                                        Delete doc (cascades assignments + acknowledgments)
 *     POST   ?action=assign                                  Add assignments (body: document_id, targets[{target_type, target_id}])
 *     DELETE ?action=unassign&document_id=X
 *            &target_type=T&target_id=I                      Remove specific assignment
 *
 *   Read views
 *     GET    ?action=for-athlete&athlete_id={id}             Docs visible to athlete (direct + their teams + their club)
 *     GET    ?action=for-user&user_id={id}                   Docs visible to user (direct + their teams + their club)
 *     GET    ?action=for-target&target_type=T&target_id=I    Docs directly assigned to that target (no cascade)
 *     GET    ?action=expiring&club_profile_id={id}&days=30   Docs expiring within N days
 *
 * Permissions enforced server-side:
 *   - Club admin: full CRUD + assign on docs in their club(s)
 *   - Coach: read-only on docs assigned to themselves, their team(s), or
 *     athletes on their team(s). Read-only on club-wide docs of that club.
 *   - Parent/guardian: read-only on docs assigned to their athlete(s) or
 *     cascaded (athlete's team / club).
 *   - Anyone reading their own user-targeted docs.
 */

header('Content-Type: application/json; charset=UTF-8');


require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

$auth = AuthMiddleware::requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    if ($method === 'GET') {
        if ($action === 'for-athlete') {
            handleForAthlete($conn, $auth);
        } elseif ($action === 'for-user') {
            handleForUser($conn, $auth);
        } elseif ($action === 'for-target') {
            handleForTarget($conn, $auth);
        } elseif ($action === 'expiring') {
            handleExpiring($conn, $auth);
        } elseif (isset($_GET['id'])) {
            handleGetSingle($conn, $auth, (int) $_GET['id']);
        } else {
            handleListForClub($conn, $auth);
        }
    } elseif ($method === 'POST' && $action === 'assign') {
        handleAssign($conn, $auth);
    } elseif ($method === 'POST') {
        handleCreate($conn, $auth);
    } elseif ($method === 'PUT' && isset($_GET['id'])) {
        handleUpdate($conn, $auth, (int) $_GET['id']);
    } elseif ($method === 'DELETE' && $action === 'unassign') {
        handleUnassign($conn, $auth);
    } elseif ($method === 'DELETE' && isset($_GET['id'])) {
        handleDelete($conn, $auth, (int) $_GET['id']);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Endpoint not found']);
    }
} catch (Exception $e) {
    error_log('documents-gateway error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error',
        'details' => getenv('APP_ENV') === 'development' ? $e->getMessage() : null,
    ]);
}

// ============================================================================
// HANDLERS
// ============================================================================

function handleListForClub(PDO $conn, $auth): void {
    $clubId = (int) ($_GET['club_profile_id'] ?? $_GET['clubId'] ?? 0);
    if ($clubId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'club_profile_id is required']);
        return;
    }
    if (!$auth->canAccessClub($clubId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        return;
    }

    $isAdmin = $auth->isSuperAdmin() || $auth->hasRole('club_admin', $clubId, 'club');

    if ($isAdmin) {
        $stmt = $conn->prepare("
            SELECT d.*, CONCAT(u.first_name, ' ', u.last_name) AS uploaded_by_name
            FROM documents d
            LEFT JOIN users u ON d.uploaded_by = u.id
            WHERE d.club_profile_id = :cid
            ORDER BY d.created_at DESC
        ");
        $stmt->execute(['cid' => $clubId]);
        $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $coachTeamIds = getCoachTeamIds($conn, (int) $auth->getUserId(), $clubId);
        $athleteIds = [];
        if (!empty($coachTeamIds)) {
            $ph = implode(',', array_fill(0, count($coachTeamIds), '?'));
            $athStmt = $conn->prepare("SELECT DISTINCT athlete_id FROM team_members WHERE team_id IN ($ph) AND athlete_id IS NOT NULL");
            $athStmt->execute($coachTeamIds);
            $athleteIds = array_column($athStmt->fetchAll(PDO::FETCH_ASSOC), 'athlete_id');
        }
        $docs = listDocumentsForCascade($conn, $clubId, $coachTeamIds, $athleteIds, [(int) $auth->getUserId()]);
    }

    if (!empty($docs)) {
        $ids = array_column($docs, 'id');
        $assignments = fetchAssignmentsForDocuments($conn, $ids);
        foreach ($docs as &$doc) {
            $doc['assignments'] = $assignments[$doc['id']] ?? [];
        }
        unset($doc);
    }

    $slotLabels = [
        'background_check' => 'Background Check',
        'abuse_prevention' => 'Abuse Prevention Training',
        'concussion' => 'Concussion Protocol',
        'code_of_conduct' => 'Code of Conduct',
        'coaches_certificate' => 'Coaches Certificate',
        'attestation' => 'Attestation',
        'first_aid' => 'First Aid',
        'cpr' => 'CPR',
        'trainings' => 'Trainings',
    ];

    $slots = [];
    foreach ($slotLabels as $key => $label) {
        $slots[] = ['key' => $key, 'label' => $label, 'documents' => []];
    }
    $customDocuments = [];

    foreach ($docs as $doc) {
        $slotKey = $doc['slot'] ?? null;
        $placed = false;
        if ($slotKey) {
            foreach ($slots as &$slot) {
                if ($slot['key'] === $slotKey) {
                    $slot['documents'][] = $doc;
                    $placed = true;
                    break;
                }
            }
            unset($slot);
        }
        if (!$placed) {
            $customDocuments[] = $doc;
        }
    }

    echo json_encode([
        'success' => true,
        'documents' => $docs,
        'slots' => $slots,
        'custom_documents' => $customDocuments,
    ]);
}

function handleGetSingle(PDO $conn, $auth, int $docId): void {
    $stmt = $conn->prepare("
        SELECT d.*, CONCAT(u.first_name, ' ', u.last_name) AS uploaded_by_name
        FROM documents d
        LEFT JOIN users u ON d.uploaded_by = u.id
        WHERE d.id = ?
    ");
    $stmt->execute([$docId]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$doc) {
        http_response_code(404);
        echo json_encode(['error' => 'Document not found']);
        return;
    }
    if (!userCanReadDocument($conn, $auth, $doc)) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        return;
    }
    $assignments = fetchAssignmentsForDocuments($conn, [$docId]);
    $doc['assignments'] = $assignments[$docId] ?? [];
    echo json_encode(['success' => true, 'document' => $doc]);
}

function handleCreate(PDO $conn, $auth): void {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $clubId = (int) ($data['club_profile_id'] ?? 0);
    $title = trim((string) ($data['title'] ?? $data['name'] ?? ''));
    $filePath = $data['file_path'] ?? $data['url'] ?? null;
    $linkUrl = $data['link_url'] ?? null;

    if ($clubId <= 0 || $title === '') {
        http_response_code(400);
        echo json_encode(['error' => 'club_profile_id and title are required']);
        return;
    }
    if (!$filePath && !$linkUrl) {
        http_response_code(400);
        echo json_encode(['error' => 'Either file_path (uploaded) or link_url is required']);
        return;
    }
    if (!isClubAdmin($auth, $clubId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Only club admins can create documents']);
        return;
    }

    $conn->beginTransaction();
    try {
        $stmt = $conn->prepare("
            INSERT INTO documents (
                club_profile_id, title, description, file_path, file_name,
                file_size, mime_type, link_url, slot, notes,
                is_required, expires_at, uploaded_by
            ) VALUES (
                :cid, :title, :desc, :fp, :fn,
                :fs, :mt, :lu, :slot, :notes,
                :req, :exp, :uid
            )
            RETURNING id
        ");
        $stmt->execute([
            'cid' => $clubId,
            'title' => $title,
            'desc' => $data['description'] ?? null,
            'fp' => $filePath ?: null,
            'fn' => $data['file_name'] ?? null,
            'fs' => $data['file_size'] ?? null,
            'mt' => $data['mime_type'] ?? null,
            'lu' => $linkUrl ?: null,
            'slot' => $data['slot'] ?? null,
            'notes' => $data['notes'] ?? null,
            'req' => !empty($data['is_required']) ? 'true' : 'false',
            'exp' => $data['expires_at'] ?? null,
            'uid' => $auth->getUserId(),
        ]);
        $docId = (int) $stmt->fetchColumn();

        $assignments = $data['assignments'] ?? [];
        $hasClubAssignment = false;
        foreach ($assignments as $a) {
            if (($a['target_type'] ?? '') === 'club' && (int) ($a['target_id'] ?? 0) === $clubId) {
                $hasClubAssignment = true;
                break;
            }
        }
        if (!$hasClubAssignment && empty($assignments)) {
            $assignments[] = ['target_type' => 'club', 'target_id' => $clubId];
        }

        insertAssignments($conn, $docId, $assignments, (int) $auth->getUserId(), $clubId);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollBack();
        throw $e;
    }

    echo json_encode([
        'success' => true,
        'id' => $docId,
        'message' => 'Document created',
    ]);
}

function handleUpdate(PDO $conn, $auth, int $docId): void {
    $stmt = $conn->prepare("SELECT club_profile_id FROM documents WHERE id = ?");
    $stmt->execute([$docId]);
    $clubId = $stmt->fetchColumn();
    if (!$clubId) {
        http_response_code(404);
        echo json_encode(['error' => 'Document not found']);
        return;
    }
    if (!isClubAdmin($auth, (int) $clubId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Only club admins can update documents']);
        return;
    }

    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $allowed = [
        'title', 'description', 'file_path', 'file_name', 'file_size',
        'mime_type', 'link_url', 'slot', 'notes', 'is_required', 'expires_at',
    ];
    $sets = [];
    $params = ['id' => $docId];
    foreach ($allowed as $col) {
        if (array_key_exists($col, $data)) {
            $sets[] = "$col = :$col";
            $params[$col] = $col === 'is_required'
                ? (!empty($data[$col]) ? 'true' : 'false')
                : ($data[$col] === '' ? null : $data[$col]);
        }
    }
    if (empty($sets)) {
        echo json_encode(['success' => true, 'message' => 'Nothing to update']);
        return;
    }
    $sets[] = 'updated_at = CURRENT_TIMESTAMP';
    $sql = 'UPDATE documents SET ' . implode(', ', $sets) . ' WHERE id = :id';
    $conn->prepare($sql)->execute($params);

    echo json_encode(['success' => true, 'message' => 'Document updated']);
}

function handleDelete(PDO $conn, $auth, int $docId): void {
    $stmt = $conn->prepare("SELECT club_profile_id FROM documents WHERE id = ?");
    $stmt->execute([$docId]);
    $clubId = $stmt->fetchColumn();
    if (!$clubId) {
        http_response_code(404);
        echo json_encode(['error' => 'Document not found']);
        return;
    }
    if (!isClubAdmin($auth, (int) $clubId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Only club admins can delete documents']);
        return;
    }
    $conn->prepare("DELETE FROM documents WHERE id = ?")->execute([$docId]);
    echo json_encode(['success' => true, 'message' => 'Document deleted']);
}

function handleAssign(PDO $conn, $auth): void {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $docId = (int) ($data['document_id'] ?? 0);
    $targets = $data['targets'] ?? [];
    if ($docId <= 0 || empty($targets)) {
        http_response_code(400);
        echo json_encode(['error' => 'document_id and non-empty targets[] are required']);
        return;
    }
    $stmt = $conn->prepare("SELECT club_profile_id FROM documents WHERE id = ?");
    $stmt->execute([$docId]);
    $clubId = $stmt->fetchColumn();
    if (!$clubId) {
        http_response_code(404);
        echo json_encode(['error' => 'Document not found']);
        return;
    }
    if (!isClubAdmin($auth, (int) $clubId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Only club admins can assign documents']);
        return;
    }
    insertAssignments($conn, $docId, $targets, (int) $auth->getUserId(), (int) $clubId);
    echo json_encode(['success' => true, 'message' => 'Assignments added']);
}

function handleUnassign(PDO $conn, $auth): void {
    $docId = (int) ($_GET['document_id'] ?? 0);
    $type = $_GET['target_type'] ?? '';
    $tid = (int) ($_GET['target_id'] ?? 0);
    if ($docId <= 0 || !in_array($type, ['club', 'team', 'athlete', 'user'], true) || $tid <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'document_id, valid target_type, and target_id are required']);
        return;
    }
    $stmt = $conn->prepare("SELECT club_profile_id FROM documents WHERE id = ?");
    $stmt->execute([$docId]);
    $clubId = $stmt->fetchColumn();
    if (!$clubId) {
        http_response_code(404);
        echo json_encode(['error' => 'Document not found']);
        return;
    }
    if (!isClubAdmin($auth, (int) $clubId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Only club admins can unassign documents']);
        return;
    }
    $stmt = $conn->prepare("DELETE FROM document_assignments WHERE document_id = ? AND target_type = ? AND target_id = ?");
    $stmt->execute([$docId, $type, $tid]);
    echo json_encode(['success' => true, 'removed' => $stmt->rowCount()]);
}

function handleForAthlete(PDO $conn, $auth): void {
    $athleteId = (int) ($_GET['athlete_id'] ?? 0);
    if ($athleteId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'athlete_id is required']);
        return;
    }

    $stmt = $conn->prepare("
        SELECT DISTINCT t.club_id, t.id AS team_id
        FROM team_members tm
        JOIN teams t ON t.id = tm.team_id
        WHERE tm.athlete_id = ?
    ");
    $stmt->execute([$athleteId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $clubIds = array_unique(array_filter(array_column($rows, 'club_id')));
    $teamIds = array_unique(array_filter(array_column($rows, 'team_id')));

    if (empty($clubIds)) {
        echo json_encode(['success' => true, 'documents' => []]);
        return;
    }
    $clubId = (int) reset($clubIds);

    if (!userCanReadAthleteDocs($conn, $auth, $athleteId, $clubId, $teamIds)) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        return;
    }

    $docs = listDocumentsForCascade($conn, $clubId, $teamIds, [$athleteId], []);
    echo json_encode(['success' => true, 'documents' => $docs]);
}

function handleForUser(PDO $conn, $auth): void {
    $targetUserId = (int) ($_GET['user_id'] ?? 0);
    if ($targetUserId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'user_id is required']);
        return;
    }
    $isSelf = $targetUserId === (int) $auth->getUserId();

    $stmt = $conn->prepare("
        SELECT DISTINCT t.club_id, t.id AS team_id
        FROM teams t
        LEFT JOIN team_members tm ON tm.team_id = t.id AND tm.user_id = ?
        WHERE t.primary_coach_id = ? OR tm.id IS NOT NULL
    ");
    $stmt->execute([$targetUserId, $targetUserId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $clubIds = array_unique(array_filter(array_column($rows, 'club_id')));
    $teamIds = array_unique(array_filter(array_column($rows, 'team_id')));

    try {
        $vstmt = $conn->prepare("
            SELECT DISTINCT t.club_id, t.id AS team_id
            FROM team_volunteers tv
            JOIN teams t ON t.id = tv.team_id
            WHERE tv.user_id = ?
        ");
        $vstmt->execute([$targetUserId]);
        foreach ($vstmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['club_id']) $clubIds[] = (int) $r['club_id'];
            if ($r['team_id']) $teamIds[] = (int) $r['team_id'];
        }
        $clubIds = array_unique($clubIds);
        $teamIds = array_unique($teamIds);
    } catch (PDOException $e) {
        // table might not exist in some envs — ignore
    }

    if (empty($clubIds)) {
        echo json_encode(['success' => true, 'documents' => []]);
        return;
    }
    $clubId = (int) reset($clubIds);

    if (!$isSelf && !$auth->isSuperAdmin() && !$auth->hasRole('club_admin', $clubId, 'club')) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        return;
    }

    $docs = listDocumentsForCascade($conn, $clubId, $teamIds, [], [$targetUserId]);
    echo json_encode(['success' => true, 'documents' => $docs]);
}

function handleForTarget(PDO $conn, $auth): void {
    $type = $_GET['target_type'] ?? '';
    $tid = (int) ($_GET['target_id'] ?? 0);
    if (!in_array($type, ['club', 'team', 'athlete', 'user'], true) || $tid <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'valid target_type and target_id are required']);
        return;
    }

    $clubId = resolveClubForTarget($conn, $type, $tid);
    if (!$clubId) {
        http_response_code(404);
        echo json_encode(['error' => 'Target not found']);
        return;
    }
    if (!$auth->canAccessClub($clubId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        return;
    }

    $stmt = $conn->prepare("
        SELECT d.*, CONCAT(u.first_name, ' ', u.last_name) AS uploaded_by_name
        FROM documents d
        JOIN document_assignments da ON da.document_id = d.id
        LEFT JOIN users u ON d.uploaded_by = u.id
        WHERE da.target_type = ? AND da.target_id = ?
        ORDER BY d.created_at DESC
    ");
    $stmt->execute([$type, $tid]);
    $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'documents' => $docs]);
}

function handleExpiring(PDO $conn, $auth): void {
    $clubId = (int) ($_GET['club_profile_id'] ?? 0);
    $days = max(1, (int) ($_GET['days'] ?? 30));
    if ($clubId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'club_profile_id is required']);
        return;
    }
    if (!$auth->canAccessClub($clubId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        return;
    }
    $stmt = $conn->prepare("
        SELECT d.*, CONCAT(u.first_name, ' ', u.last_name) AS uploaded_by_name
        FROM documents d
        LEFT JOIN users u ON d.uploaded_by = u.id
        WHERE d.club_profile_id = ?
          AND d.expires_at IS NOT NULL
          AND d.expires_at <= (CURRENT_TIMESTAMP + (? || ' days')::interval)
        ORDER BY d.expires_at ASC
    ");
    $stmt->execute([$clubId, $days]);
    $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'documents' => $docs]);
}

// ============================================================================
// HELPERS
// ============================================================================

function listDocumentsForCascade(PDO $conn, int $clubId, array $teamIds, array $athleteIds, array $userIds): array {
    $where = ["(da.target_type = 'club' AND da.target_id = ?)"];
    $params = [$clubId];

    if (!empty($teamIds)) {
        $ph = implode(',', array_fill(0, count($teamIds), '?'));
        $where[] = "(da.target_type = 'team' AND da.target_id IN ($ph))";
        $params = array_merge($params, $teamIds);
    }
    if (!empty($athleteIds)) {
        $ph = implode(',', array_fill(0, count($athleteIds), '?'));
        $where[] = "(da.target_type = 'athlete' AND da.target_id IN ($ph))";
        $params = array_merge($params, $athleteIds);
    }
    if (!empty($userIds)) {
        $ph = implode(',', array_fill(0, count($userIds), '?'));
        $where[] = "(da.target_type = 'user' AND da.target_id IN ($ph))";
        $params = array_merge($params, $userIds);
    }

    $sql = "
        SELECT DISTINCT d.*, CONCAT(u.first_name, ' ', u.last_name) AS uploaded_by_name
        FROM documents d
        JOIN document_assignments da ON da.document_id = d.id
        LEFT JOIN users u ON d.uploaded_by = u.id
        WHERE d.club_profile_id = ?
          AND (" . implode(' OR ', $where) . ")
        ORDER BY d.created_at DESC
    ";
    $allParams = array_merge([$clubId], $params);
    $stmt = $conn->prepare($sql);
    $stmt->execute($allParams);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetchAssignmentsForDocuments(PDO $conn, array $docIds): array {
    if (empty($docIds)) return [];
    $ph = implode(',', array_fill(0, count($docIds), '?'));
    $stmt = $conn->prepare("
        SELECT da.id, da.document_id, da.target_type, da.target_id, da.assigned_at,
               CASE da.target_type
                   WHEN 'club' THEN cp.name
                   WHEN 'team' THEN t.name
                   WHEN 'athlete' THEN a.first_name || ' ' || a.last_name
                   WHEN 'user' THEN u.first_name || ' ' || u.last_name
               END AS target_name
        FROM document_assignments da
        LEFT JOIN club_profile cp ON da.target_type = 'club' AND cp.id = da.target_id
        LEFT JOIN teams t ON da.target_type = 'team' AND t.id = da.target_id
        LEFT JOIN athletes a ON da.target_type = 'athlete' AND a.id = da.target_id
        LEFT JOIN users u ON da.target_type = 'user' AND u.id = da.target_id
        WHERE da.document_id IN ($ph)
    ");
    $stmt->execute($docIds);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $byDoc = [];
    foreach ($rows as $r) {
        $byDoc[(int) $r['document_id']][] = $r;
    }
    return $byDoc;
}

function insertAssignments(PDO $conn, int $docId, array $targets, int $assignedBy, int $clubId): void {
    $stmt = $conn->prepare("
        INSERT INTO document_assignments (document_id, target_type, target_id, assigned_by)
        VALUES (?, ?, ?, ?)
        ON CONFLICT (document_id, target_type, target_id) DO NOTHING
    ");
    foreach ($targets as $t) {
        $type = $t['target_type'] ?? null;
        $tid = (int) ($t['target_id'] ?? 0);
        if (!in_array($type, ['club', 'team', 'athlete', 'user'], true) || $tid <= 0) {
            continue;
        }
        $stmt->execute([$docId, $type, $tid, $assignedBy]);
    }
}

function resolveClubForTarget(PDO $conn, string $type, int $id): ?int {
    if ($type === 'club') {
        $stmt = $conn->prepare("SELECT id FROM club_profile WHERE id = ?");
        $stmt->execute([$id]);
        $r = $stmt->fetchColumn();
        return $r ? (int) $r : null;
    }
    if ($type === 'team') {
        $stmt = $conn->prepare("SELECT club_id FROM teams WHERE id = ?");
        $stmt->execute([$id]);
        $r = $stmt->fetchColumn();
        return $r ? (int) $r : null;
    }
    if ($type === 'athlete') {
        $stmt = $conn->prepare("
            SELECT t.club_id FROM team_members tm
            JOIN teams t ON t.id = tm.team_id
            WHERE tm.athlete_id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $r = $stmt->fetchColumn();
        return $r ? (int) $r : null;
    }
    if ($type === 'user') {
        $stmt = $conn->prepare("SELECT club_profile_id FROM user_club_access WHERE user_id = ? AND active = TRUE LIMIT 1");
        $stmt->execute([$id]);
        $r = $stmt->fetchColumn();
        if ($r) return (int) $r;
        $stmt = $conn->prepare("
            SELECT t.club_id FROM teams t
            LEFT JOIN team_members tm ON tm.team_id = t.id AND tm.user_id = ?
            WHERE t.primary_coach_id = ? OR tm.id IS NOT NULL
            LIMIT 1
        ");
        $stmt->execute([$id, $id]);
        $r = $stmt->fetchColumn();
        return $r ? (int) $r : null;
    }
    return null;
}

function isClubAdmin($auth, int $clubId): bool {
    return $auth->isSuperAdmin() || $auth->hasRole('club_admin', $clubId, 'club');
}

function userCanReadDocument(PDO $conn, $auth, array $doc): bool {
    $clubId = (int) $doc['club_profile_id'];
    if (isClubAdmin($auth, $clubId)) {
        return true;
    }
    $userId = (int) $auth->getUserId();
    $stmt = $conn->prepare("
        SELECT 1 FROM document_assignments da
        WHERE da.document_id = ?
          AND (
            (da.target_type = 'club' AND da.target_id = ?)
            OR (da.target_type = 'user' AND da.target_id = ?)
            OR (da.target_type = 'team' AND da.target_id IN (
                  SELECT t.id FROM teams t
                  LEFT JOIN team_members tm ON tm.team_id = t.id AND tm.user_id = ?
                  WHERE t.primary_coach_id = ? OR tm.id IS NOT NULL
            ))
            OR (da.target_type = 'athlete' AND da.target_id IN (
                  SELECT ag.athlete_id FROM athlete_guardians ag
                  JOIN guardians g ON g.id = ag.guardian_id
                  JOIN users u ON u.email = g.email
                  WHERE u.id = ?
            ))
          )
        LIMIT 1
    ");
    $stmt->execute([$doc['id'], $clubId, $userId, $userId, $userId, $userId]);
    return (bool) $stmt->fetchColumn();
}

function userCanReadAthleteDocs(PDO $conn, $auth, int $athleteId, int $clubId, array $teamIds): bool {
    if (isClubAdmin($auth, $clubId)) return true;
    $userId = (int) $auth->getUserId();

    if (!empty($teamIds)) {
        $ph = implode(',', array_fill(0, count($teamIds), '?'));
        $stmt = $conn->prepare("
            SELECT 1 FROM teams t
            LEFT JOIN team_members tm ON tm.team_id = t.id AND tm.user_id = ?
            WHERE t.id IN ($ph) AND (t.primary_coach_id = ? OR tm.id IS NOT NULL)
            LIMIT 1
        ");
        $stmt->execute(array_merge([$userId], $teamIds, [$userId]));
        if ($stmt->fetchColumn()) return true;
    }

    $stmt = $conn->prepare("
        SELECT 1 FROM athlete_guardians ag
        JOIN guardians g ON g.id = ag.guardian_id
        JOIN users u ON u.email = g.email
        WHERE ag.athlete_id = ? AND u.id = ?
        LIMIT 1
    ");
    $stmt->execute([$athleteId, $userId]);
    return (bool) $stmt->fetchColumn();
}

function getCoachTeamIds(PDO $conn, int $userId, int $clubId): array {
    $stmt = $conn->prepare("
        SELECT DISTINCT t.id FROM teams t
        LEFT JOIN team_members tm ON tm.team_id = t.id AND tm.user_id = ?
            AND tm.role IN ('assistant_coach','team_manager') AND tm.status = 'active'
        WHERE (t.primary_coach_id = ? OR tm.id IS NOT NULL) AND t.club_id = ?
    ");
    $stmt->execute([$userId, $userId, $clubId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}
