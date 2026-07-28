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
 * ---------------------------------------------------------------------------
 * Two representations, on purpose
 * ---------------------------------------------------------------------------
 * html_output  — the hand-authored document in email-assets/templates/, sent
 *                as-is when a club uses the template WITHOUT opening the editor.
 *                Its brand colours are merge-tag fallback pairs
 *                ("color:#1A3C5E;color:{{club_primary_color}};") so the club's
 *                real colour is resolved by MergeFieldService at send time.
 *
 * design_json  — NATIVE Unlayer blocks (text / divider), not one lump of custom
 *                HTML, so a club admin editing the template gets the rich-text
 *                editor on every paragraph instead of a code box. Colours here
 *                are the flat #1A3C5E / #C9A96E placeholders that
 *                TemplateEditor's applyBrandColors() rewrites to the club's
 *                brand colours when the design loads.
 *
 * The two stay consistent in content. They differ only in how colour reaches the
 * page, because each path resolves it differently: merge tags cannot be used in
 * design_json (Unlayer would treat them as invalid CSS on the canvas), and
 * applyBrandColors never touches html_output. Editing a platform template clones
 * it to club scope first (see TemplateLibrary handleEdit), so a save bakes that
 * club's real colours into the clone and leaves this platform original intact.
 */

require_once __DIR__ . '/../config/database.php';

const TEMPLATE_NAME = 'Club platform announcement';
const TEMPLATE_SUBJECT = '{{club_name}} is moving to Teams Elevated';
const TEMPLATE_CATEGORY = 'registration'; // "Registration & Welcome" in the 10-tag taxonomy

const BRAND = '#1A3C5E';  // -> club primary, via applyBrandColors()
const ACCENT = '#C9A96E'; // -> club accent,  via applyBrandColors()

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

// ---------------------------------------------------------------------------
// Unlayer block builders
// ---------------------------------------------------------------------------
$counters = ['row' => 0, 'col' => 0, 'text' => 0, 'divider' => 0];

$meta = function (string $kind) use (&$counters): array {
    $counters[$kind]++;
    $slug = $kind === 'col' ? 'column' : ($kind === 'row' ? 'row' : 'content_' . $kind);
    return ['htmlID' => "u_{$slug}_{$counters[$kind]}", 'htmlClassNames' => "u_{$slug}"];
};

$flags = [
    'selectable' => true, 'draggable' => true, 'duplicatable' => true,
    'deletable' => true, 'hideable' => true,
];

/** A native rich-text block — this is what makes the copy editable in the builder. */
$textBlock = function (string $html, string $padding = '10px 30px') use ($meta, $flags): array {
    return [
        'id' => 'txt_' . substr(md5($html), 0, 10),
        'type' => 'text',
        'values' => array_merge($flags, [
            'containerPadding' => $padding,
            'anchor' => '',
            'fontSize' => '15px',
            'textAlign' => 'left',
            'lineHeight' => '160%',
            'linkStyle' => [
                'inherit' => false,
                'linkColor' => BRAND,
                'linkHoverColor' => BRAND,
                'linkUnderline' => true,
                'linkHoverUnderline' => true,
            ],
            'hideDesktop' => false,
            'displayCondition' => null,
            '_meta' => $meta('text'),
            'text' => $html,
        ]),
    ];
};

/** The short accent rule under the intro. */
$dividerBlock = function () use ($meta, $flags): array {
    return [
        'id' => 'div_accent',
        'type' => 'divider',
        'values' => array_merge($flags, [
            'width' => '48px',
            'border' => [
                'borderTopWidth' => '3px',
                'borderTopStyle' => 'solid',
                'borderTopColor' => ACCENT,
            ],
            'textAlign' => 'left',
            'containerPadding' => '14px 30px',
            'anchor' => '',
            'hideDesktop' => false,
            'displayCondition' => null,
            '_meta' => $meta('divider'),
        ]),
    ];
};

/**
 * One full-width row. $bg paints the band (header/footer/callout); $colBorder
 * carries the callout's left accent bar, which lives on the column in Unlayer.
 */
$row = function (array $contents, string $bg = '', array $colBorder = []) use ($meta, $flags): array {
    return [
        'id' => 'row_' . substr(md5(json_encode($contents) . $bg), 0, 10),
        'cells' => [1],
        'columns' => [[
            'id' => 'col_' . substr(md5(json_encode($contents) . $bg), 0, 10),
            'contents' => $contents,
            'values' => [
                'backgroundColor' => '',
                'padding' => '0px',
                'border' => (object) $colBorder,
                'borderRadius' => '0px',
                '_meta' => $meta('col'),
            ],
        ]],
        'values' => array_merge($flags, [
            'displayCondition' => null,
            'columns' => false,
            'backgroundColor' => $bg,
            'columnsBackgroundColor' => '',
            'backgroundImage' => [
                'url' => '', 'fullWidth' => true, 'repeat' => 'no-repeat',
                'size' => 'custom', 'position' => 'center',
            ],
            'padding' => '0px',
            'anchor' => '',
            'hideDesktop' => false,
            '_meta' => $meta('row'),
        ]),
    ];
};

// ---------------------------------------------------------------------------
// The email, as editable blocks
// ---------------------------------------------------------------------------
$p = 'margin:0 0 14px 0;font-size:15px;line-height:160%;color:#333333;';
$li = 'margin:0 0 8px 0;font-size:15px;line-height:160%;color:#333333;';
$h2 = 'margin:0 0 10px 0;font-size:17px;line-height:130%;color:' . BRAND . ';font-weight:700;';

$rows = [
    // Header band — club name + kicker, on the brand colour.
    $row([
        $textBlock(
            '<div style="color:#ffffff;font-weight:800;font-size:16px;letter-spacing:.02em;line-height:120%;">{{club_name}}</div>'
            . '<div style="color:#dbe3ea;font-size:12px;text-transform:uppercase;letter-spacing:.08em;margin-top:3px;">Club announcement</div>',
            '18px 24px'
        ),
    ], BRAND),

    // Headline + intro.
    $row([
        $textBlock(
            '<h1 style="margin:0 0 16px 0;font-size:24px;line-height:125%;color:' . BRAND . ';font-weight:800;">A new home for club communication</h1>'
            . '<p style="' . $p . '">Hi there,</p>'
            . '<p style="' . $p . '">{{club_name}} has selected <strong>Teams Elevated</strong> as our team management and '
            . 'communication platform. Schedules, rosters, club news, and the emails and texts you get from us will now all '
            . 'run through one place &mdash; and every family gets a free portal account.</p>',
            '30px 30px 0px'
        ),
    ]),

    $row([$dividerBlock()]),

    // What this means for you.
    $row([
        $textBlock(
            '<h2 style="' . $h2 . '">What this means for you</h2>'
            . '<ul style="margin:0;padding-left:20px;">'
            . '<li style="' . $li . '">Club and team messages will arrive from Teams Elevated, sent on behalf of {{club_name}}.</li>'
            . '<li style="' . $li . '">Practices, games, and team events come to you as calendar invites that update themselves when something changes.</li>'
            . '<li style="' . $li . '">You get a free portal account. There is no cost and no subscription.</li>'
            . '</ul>',
            '10px 30px 20px'
        ),
    ]),

    // "Watch your inbox" callout — tinted band with a left accent bar.
    $row([
        $textBlock(
            '<div style="font-size:15px;font-weight:700;color:' . BRAND . ';margin-bottom:6px;">Watch your inbox</div>'
            . '<div style="font-size:14px;line-height:160%;color:#444444;">Over the next few days you&rsquo;ll receive an '
            . 'invitation from <strong>Teams Elevated</strong> to set up your account. It takes about two minutes. If you '
            . 'don&rsquo;t see it, check your spam or promotions folder and add the sender to your contacts so future club '
            . 'messages reach you.</div>',
            '16px 18px'
        ),
    ], '#f7f9fb', [
        'borderLeftWidth' => '4px',
        'borderLeftStyle' => 'solid',
        'borderLeftColor' => BRAND,
    ]),

    // What's in the portal.
    $row([
        $textBlock(
            '<h2 style="' . $h2 . 'margin-top:8px;">What&rsquo;s in your free portal</h2>'
            . '<ul style="margin:0;padding-left:20px;">'
            . '<li style="' . $li . '">Your athlete&rsquo;s full schedule, in one calendar you can sync to your phone</li>'
            . '<li style="' . $li . '">RSVP to practices, games, and team events</li>'
            . '<li style="' . $li . '">Team rosters and how to reach your coach</li>'
            . '<li style="' . $li . '">Club announcements, plus a history of everything we&rsquo;ve sent you</li>'
            . '<li style="' . $li . '">Forms, documents, and registration details in one place</li>'
            . '</ul>',
            '20px 30px 10px'
        ),
    ]),

    // Crew vocabulary note.
    $row([
        $textBlock(
            '<div style="font-size:14px;line-height:160%;color:#555555;"><strong style="color:' . BRAND . ';">One bit of '
            . 'vocabulary:</strong> in the portal, the adults connected to an athlete are called that athlete&rsquo;s '
            . '<strong>Crew</strong>. If you see &ldquo;Crew&rdquo; next to your athlete&rsquo;s name, that&rsquo;s you.</div>',
            '14px 16px'
        ),
    ], '#fbfaf7'),

    // Sign-off.
    $row([
        $textBlock(
            '<p style="' . $p . '">Thanks for being part of {{club_name}}. If you have questions in the meantime, just '
            . 'reply to this email and we&rsquo;ll help.</p>'
            . '<p style="margin:0;font-size:15px;line-height:160%;color:#333333;">&mdash; The {{club_name}} staff</p>',
            '20px 30px 24px'
        ),
    ]),

    // Footer band. No unsubscribe link — EmailSendService::processHtml() appends
    // the compliant one (and the tracking pixel) at send time.
    $row([
        $textBlock(
            '<div style="text-align:center;color:#ffffff;font-weight:800;font-size:15px;">{{club_name}}</div>'
            . '<div style="text-align:center;margin-top:10px;font-size:11px;color:#c3cdd6;">Powered by Teams Elevated</div>',
            '24px'
        ),
    ], BRAND),
];

$design = [
    'counters' => [
        'u_row' => $counters['row'],
        'u_column' => $counters['col'],
        'u_content_text' => $counters['text'],
        'u_content_divider' => $counters['divider'],
    ],
    'body' => [
        'id' => 'announcement_body',
        'rows' => $rows,
        'headers' => [],
        'footers' => [],
        'values' => [
            'popupPosition' => 'center',
            'popupWidth' => '600px',
            'popupHeight' => 'auto',
            'borderRadius' => '10px',
            'contentAlign' => 'center',
            'contentVerticalAlign' => 'center',
            'contentWidth' => '600px',
            'fontFamily' => ['label' => 'Arial', 'value' => 'arial,helvetica,sans-serif'],
            'textColor' => '#333333',
            'backgroundColor' => '#f4f4f4',
            'preheaderText' => 'Watch for an invitation from Teams Elevated to set up your free portal account.',
            'linkStyle' => [
                'body' => true,
                'linkColor' => BRAND,
                'linkHoverColor' => BRAND,
                'linkUnderline' => true,
                'linkHoverUnderline' => true,
            ],
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

// Print the generated design to stdout for inspection / structural checks.
if (in_array('--dump-design', $argv, true)) {
    echo $designJson, "\n";
    exit(0);
}

if ($dryRun) {
    printf(
        "DRY RUN — would upsert platform template %s\n  subject: %s\n  category: %s\n  html: %d bytes\n  text: %d bytes\n"
        . "  design_json: %d bytes\n  blocks: %d rows, %d text, %d divider (all natively editable)\n",
        json_encode(TEMPLATE_NAME), TEMPLATE_SUBJECT, TEMPLATE_CATEGORY,
        strlen($html), strlen($text), strlen($designJson),
        $counters['row'], $counters['text'], $counters['divider']
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
