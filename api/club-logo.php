<?php
/**
 * Public email-safe club logo.
 *
 *   GET /api/club-logo.php?club_id=<id>[&v=<cachebust>]
 *
 * Serves the cached email PNG (club_profile.logo_png, produced by migration 049 +
 * the logo backfill). Email clients cannot render SVG/AVIF and proxy-cache images
 * aggressively, so we serve a pre-rasterized PNG at a stable URL; the optional &v
 * (a hash of the logo) lets a changed logo bust the proxy cache.
 *
 * Falls back to logo_url only when it is already an email-renderable raster
 * (png/jpeg/gif data URI). Returns 404 otherwise so the email falls back to its
 * text/monogram header. No auth — it is embedded in outbound email.
 */

require_once __DIR__ . '/../config/database.php';

function logoNotFound(): void {
    header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found', true, 404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'no logo';
    exit;
}

function serveImage(string $bin, string $contentType): void {
    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . strlen($bin));
    header('Cache-Control: public, max-age=86400');
    echo $bin;
    exit;
}

$clubId = isset($_GET['club_id']) ? (int) $_GET['club_id'] : 0;
if (!$clubId) logoNotFound();

try {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT logo_png, logo_url FROM club_profile WHERE id = ?");
    $stmt->execute([$clubId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    logoNotFound();
}

if (!$row) logoNotFound();

// Preferred: the cached email PNG.
if (!empty($row['logo_png'])) {
    $png = base64_decode($row['logo_png'], true);
    if ($png !== false && $png !== '') serveImage($png, 'image/png');
}

// Fallback: logo_url when it is already an email-safe raster data URI.
if (!empty($row['logo_url'])
    && preg_match('#^data:image/(png|jpe?g|gif);base64,(.*)$#is', $row['logo_url'], $m)) {
    $bin = base64_decode($m[2], true);
    if ($bin !== false && $bin !== '') {
        $sub = strtolower($m[1]);
        $ct = $sub === 'png' ? 'image/png' : ($sub === 'gif' ? 'image/gif' : 'image/jpeg');
        serveImage($bin, $ct);
    }
}

logoNotFound();
