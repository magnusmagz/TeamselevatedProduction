<?php
/**
 * Public sponsor logo bytes.
 *
 *   GET /api/sponsor-logo.php?sponsor_id=<id>[&v=<cachebust>]
 *
 * WHY PUBLIC, WHEN api/canva-graphics.php GATES ITS IMAGE ROUTE
 * Canva's asset upload takes a URL and CANVA'S SERVERS FETCH IT — no browser, no
 * session, no Authorization header. An authenticated endpoint is therefore not an
 * option here; the upload would receive a JSON 401 and store it as the logo.
 * Same constraint that makes api/club-logo.php public for email clients.
 *
 * What is exposed is a sponsor's own logo: material the sponsor supplied to be
 * displayed publicly, and which already appears on the club's public sponsor
 * marquee. Nothing else about the sponsor is readable here — not the contact
 * name, email or phone.
 *
 * (api/sponsors.php currently has no authentication at all and returns those
 * contact details to anyone. That is a separate, worse hole, noted in
 * SCOPE-Canva-Integration.md and not created by this file.)
 *
 * Serves whatever format is stored, including AVIF: the caller is Canva, not an
 * email client, so the narrow raster whitelist api/club-logo.php needs does not
 * apply. If a format turns out to be unacceptable to Canva the fix is to convert
 * at rest, not to refuse here.
 */

require_once __DIR__ . '/../config/database.php';

function sponsorLogoNotFound(): void
{
    header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found', true, 404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'no logo';
    exit;
}

$sponsorId = isset($_GET['sponsor_id']) ? (int) $_GET['sponsor_id'] : 0;
if (!$sponsorId) {
    sponsorLogoNotFound();
}

try {
    $db   = Database::getInstance()->getConnection();
    $stmt = $db->prepare('SELECT logo_data FROM sponsors WHERE id = ? AND deleted_at IS NULL');
    $stmt->execute([$sponsorId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('sponsor-logo: ' . $e->getMessage());
    sponsorLogoNotFound();
}

if (!$row || empty($row['logo_data'])) {
    sponsorLogoNotFound();
}

// Stored as a data URI (that is how the sponsor form saves it), but tolerate a
// bare base64 payload — the column is free text and has been written by hand.
$stored = (string) $row['logo_data'];
if (preg_match('#^data:(image/[a-z0-9.+-]+);base64,(.*)$#is', $stored, $m)) {
    $contentType = strtolower($m[1]);
    $payload     = $m[2];
} else {
    $contentType = 'image/png';
    $payload     = $stored;
}

$bin = base64_decode($payload, true);
if ($bin === false || $bin === '') {
    sponsorLogoNotFound();
}

header('Content-Type: ' . $contentType);
header('Content-Length: ' . strlen($bin));
header('Cache-Control: public, max-age=86400');
echo $bin;
