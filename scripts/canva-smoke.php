<?php
/**
 * Canva integration smoke test — proves the headless round trip end to end.
 *
 * Staged, because you cannot autofill a template until you know its field names:
 *
 *   php scripts/canva-smoke.php
 *       Who are we connected as, and what brand templates does the org have?
 *
 *   php scripts/canva-smoke.php --template=<id>
 *       What fields does that template accept? (No design is created.)
 *
 *   php scripts/canva-smoke.php --template=<id> --club=<id> [--event=<id>] --run
 *       Full run: upload the club logo as an asset, autofill from a real
 *       calendar_events row, export a PNG, download it, write club_media_assets.
 *
 * --run CREATES a design in the Canva org and a row in club_media_assets. Everything
 * short of it is read-only. Nothing here sends anything to a family.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../lib/CanvaClient.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$opts     = getopt('', ['template::', 'club::', 'event::', 'run']);
$template = $opts['template'] ?? null;
$clubId   = isset($opts['club']) ? (int) $opts['club'] : null;
$eventId  = isset($opts['event']) ? (int) $opts['event'] : null;
$doRun    = array_key_exists('run', $opts);

$pdo    = Database::getInstance()->getConnection();
$canva  = new CanvaClient($pdo);

function heading(string $s): void { echo "\n== {$s} ==\n"; }
function fail(string $s): void { fwrite(STDERR, "FAIL: {$s}\n"); exit(1); }

// ── 1. Connection ───────────────────────────────────────────────────────────
heading('Connection');
try {
    $canva->accessToken();
    echo "token OK\n";
} catch (Throwable $e) {
    fail($e->getMessage());
}

// ── 2. Brand templates ──────────────────────────────────────────────────────
if (!$template) {
    heading('Brand templates in the org');
    try {
        $list = $canva->listBrandTemplates();
    } catch (Throwable $e) {
        // The most likely first failure, and the message matters: brand templates are
        // a Teams/Enterprise feature, so an empty or forbidden result here is a plan
        // or org-membership answer, not a code bug.
        fail($e->getMessage() . "\n\nIf this is a 403, confirm the service user is in the "
           . "Canva Enterprise org and has MFA enabled.");
    }

    $items = $list['items'] ?? [];
    if (!$items) {
        echo "(none)\n\nBuild at least one brand template in Canva with autofill data fields,\n"
           . "then re-run. A plain design is NOT a brand template and cannot be autofilled.\n";
        exit(0);
    }
    foreach ($items as $t) {
        printf("  %-40s  %s\n", $t['id'] ?? '?', $t['title'] ?? '(untitled)');
    }
    echo "\nNext: --template=<id> to inspect its fields.\n";
    exit(0);
}

// ── 3. Dataset ──────────────────────────────────────────────────────────────
heading('Template dataset');
try {
    $ds = $canva->getBrandTemplateDataset($template);
} catch (Throwable $e) {
    fail($e->getMessage());
}

$fields = $ds['dataset'] ?? [];
if (!$fields) {
    fail("This template has no autofill data fields. In Canva, select an element and\n"
       . "give it a data field name, then re-publish the brand template.");
}
foreach ($fields as $name => $spec) {
    printf("  %-28s %s\n", $name, $spec['type'] ?? '?');
}

if (!$doRun) {
    echo "\nNext: --club=<id> --run to generate a real graphic.\n";
    exit(0);
}

if (!$clubId) fail('--club=<id> is required with --run');

// ── 4. Source data ──────────────────────────────────────────────────────────
heading('Source data');

$club = $pdo->prepare("SELECT id, name, primary_color, logo_png FROM club_profile WHERE id = ?");
$club->execute([$clubId]);
$club = $club->fetch(PDO::FETCH_ASSOC);
if (!$club) fail("No club_profile row {$clubId}");
echo "club: {$club['name']}\n";

// calendar_events, NOT events — there is no events table. name/event_date/start_time/type.
$eventSql = "SELECT e.id, e.name, e.event_date, e.start_time, e.type, e.opponent_name,
                    e.location, v.name AS venue_name
               FROM calendar_events e
          LEFT JOIN venues v ON v.id = e.venue_id
              WHERE e.club_id = ? " . ($eventId ? "AND e.id = ? " : "AND e.event_date >= CURRENT_DATE ") . "
           ORDER BY e.event_date ASC, e.start_time ASC
              LIMIT 1";
$stmt = $pdo->prepare($eventSql);
$stmt->execute($eventId ? [$clubId, $eventId] : [$clubId]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$event) fail("No upcoming calendar_events row for club {$clubId} (try --event=<id>)");
echo "event: #{$event['id']} {$event['name']} on {$event['event_date']}\n";

// ── 5. Logo → Canva asset ───────────────────────────────────────────────────
$logoAssetId = null;
$needsImage = false;
foreach ($fields as $spec) {
    if (($spec['type'] ?? '') === 'image') { $needsImage = true; break; }
}

if ($needsImage) {
    heading('Logo upload');
    if (empty($club['logo_png'])) {
        echo "club has no cached logo_png — skipping image fields\n";
    } else {
        $appUrl = rtrim(Env::get('APP_URL', ''), '/');
        if ($appUrl === '') fail('APP_URL must be set — Canva fetches the logo over the public internet');

        // Cache-bust so a re-uploaded club logo is not served stale to Canva.
        $logoUrl = "{$appUrl}/api/club-logo.php?club_id={$clubId}&v=" . substr(md5($club['logo_png']), 0, 8);
        echo "uploading {$logoUrl}\n";

        try {
            $job = $canva->createAssetUploadFromUrl("club-{$clubId}-logo", $logoUrl);
            $jobId = $job['job']['id'] ?? null;
            $done = $canva->pollJob(fn() => $canva->getAssetUploadJob($jobId));
            $logoAssetId = $done['asset']['id'] ?? null;
            echo "asset: {$logoAssetId}\n";
        } catch (Throwable $e) {
            fail($e->getMessage() . "\n\nIf this timed out, check APP_URL is reachable from "
               . "the public internet — Canva fetches the URL itself.");
        }
    }
}

// ── 6. Build the autofill payload ───────────────────────────────────────────
//
// Matched by field NAME against the template's dataset. Autofill rejects the whole
// request on an unknown field, so we send only fields the template actually declares
// and report what we could not fill rather than guessing.
heading('Autofill payload');

$time = $event['start_time'] ? date('g:ia', strtotime($event['start_time'])) : '';
$candidates = [
    'event_name'   => $event['name'],
    'event_date'   => date('D, M j', strtotime($event['event_date'])),
    'event_time'   => $time,
    'opponent'     => $event['opponent_name'] ?? '',
    'venue'        => $event['venue_name'] ?: ($event['location'] ?? ''),
    'club_name'    => $club['name'],
];

$data = [];
$unfilled = [];
foreach ($fields as $name => $spec) {
    $type = $spec['type'] ?? '';

    if ($type === 'image') {
        if ($logoAssetId) {
            $data[$name] = ['type' => 'image', 'asset_id' => $logoAssetId];
        } else {
            $unfilled[] = "{$name} (image, no logo)";
        }
        continue;
    }

    if ($type === 'text') {
        $value = $candidates[$name] ?? null;
        if ($value === null || $value === '') {
            $unfilled[] = "{$name} (text, no source)";
            continue;
        }
        $data[$name] = ['type' => 'text', 'text' => (string) $value];
        continue;
    }

    $unfilled[] = "{$name} ({$type}, unsupported here)";
}

foreach ($data as $name => $v) {
    echo "  {$name} = " . ($v['text'] ?? $v['asset_id'] ?? '') . "\n";
}
if ($unfilled) {
    // Not fatal: Canva leaves an unsupplied field at its template default. But a
    // graphic that says "OPPONENT" in the middle of it is worse than no graphic, so
    // this list is what the real service will have to treat as a hard error.
    echo "  UNFILLED: " . implode(', ', $unfilled) . "\n";
}
if (!$data) fail('Nothing could be filled — field names in the template do not match any TE data.');

// ── 7. Autofill → export → download ─────────────────────────────────────────
heading('Generate');

$assetRow = $pdo->prepare(
    "INSERT INTO club_media_assets (club_profile_id, source, graphic_type, calendar_event_id, status)
     VALUES (?, 'canva', 'smoke_test', ?, 'rendering') RETURNING id"
);
$assetRow->execute([$clubId, $event['id']]);
$assetId = (int) $assetRow->fetchColumn();

try {
    $job = $canva->createDesignAutofillJob($template, $data, "TE smoke — {$event['name']}");
    $jobId = $job['job']['id'] ?? null;
    $done = $canva->pollJob(fn() => $canva->getDesignAutofillJob($jobId));

    $designId = $done['result']['design']['id'] ?? null;
    $editUrl  = $done['result']['design']['urls']['edit_url'] ?? null;
    if (!$designId) fail('Autofill succeeded but returned no design id: ' . json_encode($done));
    echo "design: {$designId}\n";

    $exportJob = $canva->createDesignExportJob($designId, ['type' => 'png', 'quality' => 'pro']);
    $exportId = $exportJob['job']['id'] ?? null;
    $exportDone = $canva->pollJob(fn() => $canva->getDesignExportJob($exportId));

    $url = $exportDone['urls'][0] ?? ($exportDone['result']['urls'][0] ?? null);
    if (!$url) fail('Export succeeded but returned no URL: ' . json_encode($exportDone));

    // Download NOW. Export URLs expire; a media library holding only URLs is a media
    // library of broken images tomorrow.
    $bytes = file_get_contents($url);
    if ($bytes === false || $bytes === '') fail('Export URL returned no bytes');

    $size = strlen($bytes);
    $dims = @getimagesizefromstring($bytes) ?: [null, null];

    $pdo->prepare(
        "UPDATE club_media_assets
            SET canva_design_id = ?, canva_edit_url = ?, image_data = ?, mime_type = 'image/png',
                file_size = ?, width = ?, height = ?, status = 'ready', updated_at = CURRENT_TIMESTAMP
          WHERE id = ?"
    )->execute([$designId, $editUrl, $bytes, $size, $dims[0], $dims[1], $assetId]);

    $out = sys_get_temp_dir() . "/canva-smoke-{$assetId}.png";
    file_put_contents($out, $bytes);

    heading('Result');
    echo "club_media_assets.id : {$assetId}\n";
    echo "size                 : " . round($size / 1024) . " KB ({$dims[0]}x{$dims[1]})\n";
    echo "saved                : {$out}\n";
    echo "\nRound trip works.\n";
} catch (Throwable $e) {
    $pdo->prepare("UPDATE club_media_assets SET status = 'failed', error_message = ? WHERE id = ?")
        ->execute([substr($e->getMessage(), 0, 1000), $assetId]);
    fail($e->getMessage() . "\n(club_media_assets.{$assetId} marked failed)");
}
