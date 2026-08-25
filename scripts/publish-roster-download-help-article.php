<?php
/**
 * Publish the Help article "How to download a team roster" (2026-08-25).
 *
 * One-off, same shape as scripts/publish-roster-download-release-note.php:
 * it POSTs to help-gateway.php?action=create-article (super-admin gated)
 * rather than INSERTing, so the article gets the gateway's slug uniqueness,
 * tag encoding and author attribution.
 *
 * Idempotent on the slug within the category — a second run does nothing.
 *
 *   ... --list                     categories, article titles, one sample body
 *   ... --dry-run                  print the copy, write nothing
 *   ... --category=<slug>          which category to file it under
 *   ... --author=<email>           attribute to this super admin
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../lib/JWT.php';

$opt = static function (string $name) use ($argv): ?string {
    foreach ($argv as $a) {
        if (str_starts_with($a, "--{$name}=")) { return substr($a, strlen($name) + 3); }
    }
    return null;
};
$list   = in_array('--list', $argv, true);
$dryRun = in_array('--dry-run', $argv, true);

$title = 'How to download a team roster';
$slug  = 'how-to-download-a-team-roster';

$body = <<<'MD'
You can download any roster you have access to as a CSV file and open it in Excel, Google Sheets or Numbers — useful for check-in sheets, tournament paperwork, or a contact list for the season.

## Download it

1. Open the team, either from **Teams** or by clicking the team name anywhere it appears.
2. Click **Download**, at the top of the Roster section. (The same button is on **Manage Roster**.)
3. Choose which version you want.

The file downloads straight away, named for the team and today's date — for example, `Sharks-U12-roster-2026-08-25.csv`.

## Choose which version you need

**Athletes** gives you the roster on its own: jersey number, last name, first name, date of birth, age, position and status.

**Athletes + Crew** gives you all of that plus each athlete's crew — name, relationship, email and phone number — so it doubles as your contact list for the team.

Pick **Athletes** when you're printing something that will be handed around, like a sideline sheet or a check-in list. Family contact details don't belong on a page that gets left on a table.

## What's in the file

- Every player currently on the roster, in the same order as the roster page.
- Players marked **injured**, **suspended** or **inactive** are included. Their status is a column, so you can sort or filter to leave them out.
- Players who have left the team are not included.
- Dates of birth are written as `YYYY-MM-DD`, which is the format spreadsheets sort correctly. Age is worked out for you as of the day you download.

If your athletes have different numbers of crew members, the file makes room for the largest family on the team — a parent never gets dropped to keep the columns even. Athletes with fewer crew simply have blank cells.

## Who can download a roster

Coaches can download rosters for the teams they're on. Club admins can download any team in their club.

Parents and players can see their team's roster in the app but can't download it. The **Athletes + Crew** version is a contact list for other people's families, and a downloaded file keeps existing long after someone has left the club — so downloading stays with coaches and club admins.

Every download is recorded in your club's audit log: who downloaded which team's roster, which version, and when.

## Troubleshooting

**Names with accents look wrong in Excel.** Open Excel first, then use **File → Import** and choose UTF-8, rather than double-clicking the file. The file itself is correct.

**A message says not everything fit.** A single file holds up to 1,000 players and four crew members per athlete. If a roster goes past that, the app tells you exactly what was left out — it never hands you a short file without saying so. Download the teams separately if you hit it.

**You don't see the Download button.** It only appears for coaches and club admins. If you're a coach and it's missing, check with your club admin that you're listed on the team.
MD;

$summary = "Download a team roster as a CSV — athletes on their own, or athletes with their crew's contact details.";

if ($dryRun) {
    echo "=== {$title} ===\n{$summary}\n\n{$body}\n";
    exit(0);
}

$pdo = new PDO(
    sprintf('pgsql:host=%s;port=%s;dbname=%s;sslmode=require',
        Env::get('DB_HOST'), Env::get('DB_PORT', '5432'), Env::get('DB_NAME')),
    Env::get('DB_USER'),
    Env::get('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

if ($list) {
    echo "CATEGORIES\n";
    foreach ($pdo->query("
        SELECT c.id, c.slug, c.name, c.role_tag, c.sort_order,
               COUNT(a.id) AS articles
        FROM help_categories c
        LEFT JOIN help_articles a ON a.category_id = c.id
        WHERE c.is_active = true
        GROUP BY c.id ORDER BY c.sort_order
    ") as $c) {
        printf("  %-3s %-28s %-34s role=%-8s articles=%s\n",
            $c['id'], $c['slug'], $c['name'], $c['role_tag'] ?? '-', $c['articles']);
    }

    echo "\nARTICLES\n";
    foreach ($pdo->query("
        SELECT a.id, c.slug AS cat, a.slug, a.title, a.role_tags, a.related_feature, a.is_published
        FROM help_articles a JOIN help_categories c ON c.id = a.category_id
        ORDER BY c.sort_order, a.sort_order, a.id
    ") as $a) {
        printf("  %-3s %-20s %-42s roles=%-28s feature=%s\n",
            $a['id'], $a['cat'], $a['slug'], $a['role_tags'], $a['related_feature'] ?? '-');
    }

    $sample = $pdo->query("SELECT title, summary, body_markdown FROM help_articles ORDER BY id LIMIT 1")
                  ->fetch(PDO::FETCH_ASSOC);
    echo "\nSAMPLE ARTICLE — {$sample['title']}\nsummary: {$sample['summary']}\n---\n"
        . substr($sample['body_markdown'], 0, 1500) . "\n";
    exit(0);
}

$categorySlug = $opt('category');
if (!$categorySlug) {
    fwrite(STDERR, "--category=<slug> is required. Run with --list to see the options.\n");
    exit(1);
}
$cat = $pdo->prepare('SELECT id, name FROM help_categories WHERE slug = ? AND is_active = true');
$cat->execute([$categorySlug]);
$category = $cat->fetch(PDO::FETCH_ASSOC);
if (!$category) {
    fwrite(STDERR, "No active category with slug '{$categorySlug}'.\n");
    exit(1);
}

$existing = $pdo->prepare('SELECT id FROM help_articles WHERE category_id = ? AND slug = ?');
$existing->execute([$category['id'], $slug]);
if ($row = $existing->fetch(PDO::FETCH_ASSOC)) {
    echo "Already published as id {$row['id']} in {$category['name']}. Nothing to do.\n";
    exit(0);
}

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
    fwrite(STDERR, "No matching super_admin — create-article is super-admin only.\n");
    exit(1);
}
echo "Filing under: {$category['name']}\nAuthoring as: {$author['email']} (user {$author['id']})\n";

$token = JWT::generateEnhanced(
    $pdo, $author['id'], $author['email'],
    trim($author['first_name'] . ' ' . $author['last_name']), 32, 'club'
);

$base = Env::get('API_BASE_URL', 'https://teamselevated-backend-0485388bd66e.herokuapp.com');
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => rtrim($base, '/') . '/api/help-gateway.php?action=create-article',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_TIMEOUT => 45,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Authorization: Bearer {$token}"],
    CURLOPT_POSTFIELDS => json_encode([
        'category_id'     => (int) $category['id'],
        'title'           => $title,
        'summary'         => $summary,
        'body_markdown'   => $body,
        // This DB's vocabulary is admin / coach / parent — NOT the
        // user_club_access value 'club_admin'. Tagging it club_admin would
        // file it under a role the Help sidebar does not filter on.
        'role_tags'       => ['admin', 'coach'],
        // Matches the existing roster article rather than inventing a new
        // feature key, so both surface together if contextual help ever
        // keys on it.
        'related_feature' => 'roster-management',
        'search_keywords' => ['roster', 'download', 'export', 'csv', 'spreadsheet',
                              'excel', 'contact list', 'crew', 'team list', 'print roster'],
        'sort_order'      => 0,
        'is_published'    => true,
    ]),
]);
$out  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

echo "HTTP {$code}\n{$out}\n";
exit($code === 200 ? 0 : 1);
