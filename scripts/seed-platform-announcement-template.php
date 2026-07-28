<?php
/**
 * Seed the "Club platform announcement" email into the template library as a
 * PLATFORM-scoped template, so every club — Central Kansas United now and every
 * club onboarded later — sees it without a per-club copy.
 *
 *   heroku run php scripts/seed-platform-announcement-template.php [--dry-run]
 *
 * Idempotent: re-running updates the existing platform template of the same name
 * rather than inserting a duplicate, so it is safe to run after editing the
 * source files in email-assets/templates/.
 *
 * Source of truth is the two files on disk:
 *   email-assets/templates/platform-announcement.html  -> html_output
 *   email-assets/templates/platform-announcement.txt   -> body_text
 *
 * design_json wraps the card in a single Unlayer custom-HTML block. That matters:
 * TemplateEditor only calls loadDesign() when design_json is present, so a template
 * seeded without one opens on a blank canvas and saving would wipe html_output.
 * One block also round-trips the markup faithfully — Unlayer re-exports it inside
 * its own document wrapper on save.
 *
 * Colours are the platform-template placeholders documented in TemplateEditor
 * (#1A3C5E -> club primary, #C9A96E -> club accent); applyBrandColors() swaps them
 * for the club's real brand colours when the template is opened in the editor.
 */

require_once __DIR__ . '/../config/database.php';

const TEMPLATE_NAME = 'Club platform announcement';
const TEMPLATE_SUBJECT = '{{club_name}} is moving to TeamsElevated';
const TEMPLATE_CATEGORY = 'registration'; // "Registration & Welcome" in the 10-tag taxonomy

$dryRun = in_array('--dry-run', $argv, true);

$htmlPath = __DIR__ . '/../email-assets/templates/platform-announcement.html';
$textPath = __DIR__ . '/../email-assets/templates/platform-announcement.txt';

foreach ([$htmlPath, $textPath] as $p) {
    if (!is_readable($p)) {
        fwrite(STDERR, "missing source file: {$p}\n");
        exit(1);
    }
}

$html = file_get_contents($htmlPath);
$text = file_get_contents($textPath);

// Unlayer renders inside its own document, so the design block carries the body
// fragment while html_output keeps the full standalone document used for sending.
if (!preg_match('#<body[^>]*>(.*)</body>#is', $html, $m)) {
    fwrite(STDERR, "could not extract <body> from {$htmlPath}\n");
    exit(1);
}
$fragment = trim($m[1]);

$design = [
    'counters' => ['u_row' => 1, 'u_column' => 1, 'u_content_html' => 1],
    'body' => [
        'id' => 'announcement_body',
        'rows' => [[
            'id' => 'announcement_row',
            'cells' => [1],
            'columns' => [[
                'id' => 'announcement_col',
                'contents' => [[
                    'id' => 'announcement_html',
                    'type' => 'html',
                    'values' => [
                        'html' => $fragment,
                        'hideDesktop' => false,
                        'displayCondition' => null,
                        'containerPadding' => '0px',
                        '_meta' => ['htmlID' => 'u_content_html_1', 'htmlClassNames' => 'u_content_html'],
                        'selectable' => true, 'draggable' => true, 'duplicatable' => true,
                        'deletable' => true, 'hideable' => true,
                    ],
                ]],
                'values' => [
                    'backgroundColor' => '',
                    'padding' => '0px',
                    'border' => (object) [],
                    '_meta' => ['htmlID' => 'u_column_1', 'htmlClassNames' => 'u_column'],
                ],
            ]],
            'values' => [
                'displayCondition' => null,
                'columns' => false,
                'backgroundColor' => '',
                'columnsBackgroundColor' => '',
                'padding' => '0px',
                'hideDesktop' => false,
                '_meta' => ['htmlID' => 'u_row_1', 'htmlClassNames' => 'u_row'],
                'selectable' => true, 'draggable' => true, 'duplicatable' => true,
                'deletable' => true, 'hideable' => true,
            ],
        ]],
        'headers' => [],
        'footers' => [],
        'values' => [
            'backgroundColor' => '#f4f4f4',
            'contentWidth' => '600px',
            'fontFamily' => ['label' => 'Arial', 'value' => 'arial,helvetica,sans-serif'],
            'textColor' => '#333333',
            '_meta' => ['htmlID' => 'u_body', 'htmlClassNames' => 'u_body'],
        ],
    ],
    'schemaVersion' => 16,
];

$designJson = json_encode($design, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($designJson === false) {
    fwrite(STDERR, "failed to encode design_json: " . json_last_error_msg() . "\n");
    exit(1);
}

if ($dryRun) {
    printf(
        "DRY RUN — would upsert platform template %s\n  subject: %s\n  category: %s\n  html: %d bytes\n  text: %d bytes\n  design_json: %d bytes\n",
        json_encode(TEMPLATE_NAME), TEMPLATE_SUBJECT, TEMPLATE_CATEGORY,
        strlen($html), strlen($text), strlen($designJson)
    );
    exit(0);
}

$db = Database::getInstance()->getConnection();

$stmt = $db->prepare("SELECT id FROM email_templates WHERE name = ? AND scope = 'platform' LIMIT 1");
$stmt->execute([TEMPLATE_NAME]);
$existingId = $stmt->fetchColumn();

if ($existingId) {
    $stmt = $db->prepare("
        UPDATE email_templates
        SET subject = ?, html_output = ?, body_text = ?, design_json = ?::jsonb,
            category = ?, is_active = true, channel = 'email', updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([TEMPLATE_SUBJECT, $html, $text, $designJson, TEMPLATE_CATEGORY, $existingId]);
    echo "UPDATED platform template #{$existingId}: " . TEMPLATE_NAME . "\n";
} else {
    $stmt = $db->prepare("
        INSERT INTO email_templates
            (club_profile_id, name, subject, design_json, html_output, body_text,
             category, is_active, scope, team_visibility, channel, created_at, updated_at)
        VALUES (NULL, ?, ?, ?::jsonb, ?, ?, ?, true, 'platform', '[]'::jsonb, 'email', NOW(), NOW())
        RETURNING id
    ");
    $stmt->execute([TEMPLATE_NAME, TEMPLATE_SUBJECT, $designJson, $html, $text, TEMPLATE_CATEGORY]);
    $newId = $stmt->fetchColumn();
    echo "INSERTED platform template #{$newId}: " . TEMPLATE_NAME . "\n";
}
