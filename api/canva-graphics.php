<?php
/**
 * Branded graphics for a club, generated through Canva.
 *
 *   GET  ?action=status&club_id=&graphic_type=   Is this one available here?
 *   GET  ?action=available&club_id=&subject_kind= Every template for that subject
 *   GET  ?action=list&club_id=                   Recent graphics (metadata only)
 *   GET  ?action=image&id=                       The PNG itself
 *   POST ?action=generate                        {club_id, graphic_type, subject_id}
 *
 * AUTHORIZATION IS te_is_club_admin, NOT te_is_club_staff AND NOT canAccessClub
 * Generating burns a Canva API call against the platform's single Enterprise
 * service account, and the output carries the club's identity into public social
 * media. `canAccessClub()` is club MEMBERSHIP — a `parent` row satisfies it, which
 * is the same mistake that exposed every guardian in a club through
 * handleClubParents. Coaches are excluded for a softer reason: club-wide brand
 * assets are not team-scoped, so a coach has no bounded version of this to be
 * given. Widen it deliberately if that changes; do not widen it to fix a 403.
 *
 * THE IMAGE ROUTE IS AUTHENTICATED TOO, AND THAT COSTS SOMETHING
 * `api/club-logo.php` is public because email clients cannot send an
 * Authorization header. Nothing here is embedded in email, so this stays gated —
 * which means the frontend cannot use a plain <img src>, and fetches the bytes
 * with the bearer token instead. Same shape as RosterDownloadButton: an <a href>
 * or bare <img> would save/render a JSON 401.
 */

require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/club_standing.php';
require_once __DIR__ . '/../services/CanvaDesignService.php';

/** Emit a JSON error and stop. */
function te_canva_fail(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

try {
    $pdo = Database::getInstance()->getConnection();
} catch (Exception $e) {
    error_log('canva-graphics: DB connection failed: ' . $e->getMessage());
    te_canva_fail(500, 'Database connection failed');
}

$auth   = AuthMiddleware::requireAuth();
$method = $_SERVER['REQUEST_METHOD'];

$body = [];
if ($method === 'POST') {
    $raw  = file_get_contents('php://input');
    $body = $raw ? (json_decode($raw, true) ?: []) : [];
}

$action  = $_GET['action'] ?? ($body['action'] ?? '');
$service = new CanvaDesignService($pdo);

// ── The PNG ─────────────────────────────────────────────────────────────────
// Handled first because it is the one route that does not answer with JSON, and
// because its club is derived from the asset rather than supplied by the caller.
if ($action === 'image') {
    $assetId = (int) ($_GET['id'] ?? 0);
    if ($assetId <= 0) {
        te_canva_fail(400, 'id is required');
    }

    $asset = $service->describe($assetId);
    if (!$asset) {
        te_canva_fail(404, 'Not found');
    }
    if (!te_is_club_admin($auth, (int) $asset['club_profile_id'])) {
        te_canva_fail(403, 'Access denied');
    }

    $image = $service->imageBytes($assetId);
    if (!$image) {
        te_canva_fail(404, 'That graphic has no image yet');
    }

    header('Content-Type: ' . $image['mime_type']);
    header('Content-Length: ' . strlen($image['bytes']));
    // Private: the bytes are club material behind an auth check, so a shared
    // proxy must not keep a copy. Immutable because a generated graphic is never
    // rewritten — a regenerate produces a new row with a new id.
    header('Cache-Control: private, max-age=86400, immutable');
    echo $image['bytes'];
    exit;
}

header('Content-Type: application/json');

$clubId = (int) ($_GET['club_id'] ?? $body['club_id'] ?? 0);
if ($clubId <= 0) {
    te_canva_fail(400, 'club_id is required');
}
if (!te_is_club_admin($auth, $clubId)) {
    te_canva_fail(403, 'Access denied');
}

if ($action === 'status') {
    $graphicType = (string) ($_GET['graphic_type'] ?? 'sponsor_thanks');
    if (!in_array($graphicType, CanvaDesignService::GRAPHIC_TYPES, true)) {
        te_canva_fail(400, 'Unknown graphic type');
    }

    $template = $service->activeTemplate($clubId, $graphicType);

    // "Not configured" is a normal answer, not an error: most clubs have no
    // template yet, and the UI needs to hide the button rather than show one that
    // always fails.
    echo json_encode([
        'success'      => true,
        'graphic_type' => $graphicType,
        'available'    => $template !== null,
        'template'     => $template ? [
            'title'      => $template['title'],
            'updated_at' => $template['dataset_fetched_at'],
        ] : null,
    ]);
    exit;
}

if ($action === 'available') {
    $kind = (string) ($_GET['subject_kind'] ?? '');
    if (!in_array($kind, CanvaDesignService::SUBJECT_KINDS, true)) {
        te_canva_fail(400, 'Unknown subject kind');
    }

    // An empty list is the normal answer for most clubs, not an error — the UI
    // renders nothing rather than a control that could only fail.
    echo json_encode([
        'success'   => true,
        'templates' => $service->availableFor($clubId, $kind),
    ]);
    exit;
}

if ($action === 'list') {
    echo json_encode([
        'success' => true,
        'assets'  => $service->recent($clubId, (int) ($_GET['limit'] ?? 50)),
    ]);
    exit;
}

if ($action === 'generate') {
    if ($method !== 'POST') {
        te_canva_fail(405, 'generate requires POST');
    }

    $graphicType = (string) ($body['graphic_type'] ?? '');
    $subjectId   = (int) ($body['subject_id'] ?? 0);
    if ($subjectId <= 0) {
        te_canva_fail(400, 'subject_id is required');
    }

    try {
        // Roughly 5-15 seconds: two async Canva jobs polled to completion, then a
        // download. The caller is a person watching a spinner, so it is answered
        // synchronously rather than queued. See the note on CanvaDesignService.
        $asset = $service->generate($clubId, $graphicType, $subjectId, (int) $auth->getUserId());
    } catch (RuntimeException $e) {
        // The service raises RuntimeException for every case that leaves no usable
        // image, and its messages are written to be read by a club admin.
        te_canva_fail(422, $e->getMessage());
    } catch (Throwable $e) {
        error_log('canva-graphics generate: ' . $e->getMessage());
        te_canva_fail(500, 'The graphic could not be generated.');
    }

    echo json_encode(['success' => true, 'asset' => $asset]);
    exit;
}

te_canva_fail(400, 'Unknown action');
