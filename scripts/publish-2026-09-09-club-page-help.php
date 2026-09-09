<?php
/**
 * Release note + help article for the club link page (2026-09-09).
 *
 * Features only (Maggie's rule): what people can do now, who it is for, where
 * to find it. Same shape as scripts/publish-2026-09-08-referees-help.php —
 * articles are matched on category + TITLE and updated in place on rerun;
 * the release note is matched on title and updated with --update=<id>.
 *
 *   ... --dry-run
 *   ... --author=<email>
 *   ... --update-note=<id>
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../lib/JWT.php';
require_once __DIR__ . '/../config/database.php';

$opt = static function (string $name) use ($argv): ?string {
    foreach ($argv as $a) {
        if (str_starts_with($a, "--{$name}=")) { return substr($a, strlen($name) + 3); }
    }
    return null;
};
$dryRun = in_array('--dry-run', $argv, true);
$updateNoteId = $opt('update-note');

// ─── Release note ────────────────────────────────────────────────────────────

$noteTitle = 'Your club has a public page';

$noteBody = <<<'MD'
Every club now has a public, mobile-first page at **/club/your-club-name** — one link for your Instagram bio, your email signature, flyers and sign-up tables. Families and visitors can see it without an account.

## What is on it

- Your logo, club colours and an optional tagline
- **Visit our website**, **Call the club** and **Contact us** buttons, plus your social links
- A **thank you to our sponsors** strip with their logos, linking to their sites
- **Upcoming games and tournaments** — date, time, venue and field, with Home, Away and Tournament colour-coded — and an **Add to my calendar** button families can subscribe to
- **Our coaches**: name, role and team, nine to a page
- A **Contact us** form that goes straight to the club's administrators

Practices, meetings, athletes, families and rosters are never shown. Coaches appear by name and role only.

## Managing it

**Who:** club admins. **Where:** **Club Profile → Public Page**.

The page is on for every club from today. On that tab you can turn it off, change the link, add a tagline, copy the link, copy the calendar feed address, and download a QR code that opens your page.

## Sharing it

When you share the link in a text or on social media, the preview shows your club's name and logo.
MD;

// ─── Article ─────────────────────────────────────────────────────────────────

$adminBody = <<<'MD'
Your club's public page is a single link that shows visitors who you are, when you play, who coaches, and how to reach you — without an account.

## Finding your link

Go to **Club Profile → Public Page**. Your link is shown at the top, in the form `/club/your-club-name`.

- **Copy link** puts it on your clipboard for an Instagram bio, an email signature or a flyer.
- **Open page** shows you what visitors see.
- The **QR code** opens the same page. Download it for banners and sign-up tables.

## What visitors see

- Your **logo, colours and tagline** (the tagline is set on this tab; the logo and colours come from the Branding tab).
- **Visit our website** and **Call the club**, from the website and phone on your Club Information tab, and your **social links** from the same place. A button only appears when you have filled that field in.
- **Sponsors**: every active sponsor from your Sponsors page, with their logo and a link to their site.
- **Upcoming games and tournaments** from your calendar: date, time, venue and field, colour-coded Home, Away and Tournament. Practices and meetings are not shown, and neither are event notes.
- **Our coaches**: the head coach, assistant coaches and team managers of your active teams, by name, role and team, nine to a page. No email addresses or phone numbers.
- A **Contact us** form. Messages go by email to every club administrator, with the visitor's address as the reply-to, so you answer from your inbox.

Athletes, families and rosters are never on the page.

## Changing the link

The link is made from your club name. You can change it on the Public Page tab: lowercase letters, numbers and dashes, 3 to 60 characters. Changing it breaks links you have already shared, so pick it once. Two clubs cannot have the same link.

## Turning it off

Switch **Public page is live** off and save. The link then shows "This club page is not available" until you switch it back on.

## The calendar feed

**Copy** next to **Public calendar feed** gives you an address families can add to Google Calendar or Apple Calendar to subscribe to your games and tournaments. It updates as you add games.

## Who can do this

Club admins manage the page. Coaches and families do not need to do anything — coaches appear automatically once they are on a team.
MD;

$articles = [
    [
        'category'  => 'for-admins',
        'title'     => 'Your club\'s public page',
        'summary'   => 'One link for your bio, signature and flyers: logo, contact buttons, sponsors, upcoming games, coaches and a contact form. Managed on Club Profile → Public Page.',
        'role_tags' => ['admin'],
        'feature'   => 'club-page',
        'keywords'  => ['public page', 'club page', 'link', 'linktree', 'qr code', 'share', 'sponsors', 'calendar feed', 'contact form', 'tagline'],
        'body'      => $adminBody,
    ],
];

if ($dryRun) {
    echo str_repeat('=', 72), "\n{$noteTitle}\n", str_repeat('=', 72), "\n\n{$noteBody}\n\n";
    foreach ($articles as $a) {
        echo str_repeat('=', 72), "\n{$a['title']}  [{$a['category']}]\n", str_repeat('=', 72), "\n";
        echo $a['summary'], "\n\n", $a['body'], "\n\n";
    }
    exit(0);
}

$pdo = Database::getInstance()->getConnection();

$authorEmail = $opt('author');
if ($authorEmail) {
    $a = $pdo->prepare("SELECT id, email, first_name, last_name FROM users
                        WHERE lower(email) = lower(?) AND system_role = 'super_admin'");
    $a->execute([$authorEmail]);
} else {
    $a = $pdo->query("SELECT id, email, first_name, last_name FROM users
                      WHERE system_role = 'super_admin' ORDER BY id LIMIT 1");
}
$author = $a->fetch(PDO::FETCH_ASSOC);
if (!$author) {
    fwrite(STDERR, "No matching super_admin.\n");
    exit(1);
}

$token = JWT::generateEnhanced(
    $pdo, $author['id'], $author['email'],
    trim($author['first_name'] . ' ' . $author['last_name']), 32, 'club'
);
$base = Env::get('API_BASE_URL', 'https://teamselevated-backend-0485388bd66e.herokuapp.com');

$post = static function (string $action, string $method, array $payload) use ($base, $token): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => rtrim($base, '/') . '/api/help-gateway.php?action=' . $action,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Authorization: Bearer {$token}"],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $out  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return [$code, $out];
};

$failed = 0;

// Release note: create, or update in place when it already exists / --update-note given.
$noteId = $updateNoteId;
if (!$noteId) {
    $existing = $pdo->prepare('SELECT id FROM help_release_notes WHERE title = ?');
    $existing->execute([$noteTitle]);
    if ($row = $existing->fetch(PDO::FETCH_ASSOC)) { $noteId = (int) $row['id']; }
}
[$code, $out] = $post(
    $noteId ? "update-release-note&id={$noteId}" : 'create-release-note',
    $noteId ? 'PUT' : 'POST',
    [
        'title'         => $noteTitle,
        'body_markdown' => $noteBody,
        'release_date'  => '2026-09-09',
        'tags'          => ['club-page', 'public', 'sponsors', 'admin'],
        'is_published'  => true,
    ]
);
echo "[Release note] " . ($noteId ? "update {$noteId} " : 'create ') . "HTTP {$code} {$out}\n";
if ($code !== 200) { $failed++; }

foreach ($articles as $article) {
    $cat = $pdo->prepare('SELECT id, name FROM help_categories WHERE slug = ? AND is_active = true');
    $cat->execute([$article['category']]);
    $category = $cat->fetch(PDO::FETCH_ASSOC);
    if (!$category) { fwrite(STDERR, "No active category '{$article['category']}'.\n"); $failed++; continue; }

    $existing = $pdo->prepare('SELECT id FROM help_articles WHERE category_id = ? AND title = ? ORDER BY id LIMIT 1');
    $existing->execute([$category['id'], $article['title']]);
    $existingId = ($row = $existing->fetch(PDO::FETCH_ASSOC)) ? (int) $row['id'] : null;

    [$code, $out] = $post(
        $existingId ? "update-article&id={$existingId}" : 'create-article',
        $existingId ? 'PUT' : 'POST',
        [
            'category_id'     => (int) $category['id'],
            'title'           => $article['title'],
            'summary'         => $article['summary'],
            'body_markdown'   => $article['body'],
            'role_tags'       => $article['role_tags'],
            'related_feature' => $article['feature'],
            'search_keywords' => $article['keywords'],
            'sort_order'      => 0,
            'is_published'    => true,
        ]
    );
    echo "[{$category['name']}] {$article['title']} → HTTP {$code} {$out}\n";
    if ($code !== 200) { $failed++; }
}

exit($failed === 0 ? 0 : 1);
