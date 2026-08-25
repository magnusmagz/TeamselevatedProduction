<?php
/**
 * Publish the in-product release note for the roster download (2026-08-25).
 *
 * One-off. Release notes are rows in `help_release_notes`, and the only writer
 * is `help-gateway.php?action=create-release-note`, which is super-admin gated.
 * This goes through that endpoint rather than INSERTing directly so the note
 * gets the same slug generation, tag encoding and author attribution as one
 * written in Help Admin.
 *
 * Idempotent: it checks for the slug first and does nothing if the note is
 * already there, so a second run cannot post a duplicate to every club.
 *
 *   heroku run "php scripts/publish-roster-download-release-note.php" -a teamselevated-backend
 *   ... --dry-run   to print the note and stop
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../lib/JWT.php';

$dryRun = in_array('--dry-run', $argv, true);
$slug   = 'download-your-roster';

$body = <<<'MD'
Coaches and club admins can now download a team's roster as a CSV file — either the athletes on their own, or the athletes together with their crew's contact details.

**Who can use it:** coaches, for the teams they're on, and club admins, for any team in their club.
**Where to find it:** the **Download** button on a team's page, and on **Manage Roster**.

## Two versions of the file

**Athletes** — jersey number, last name, first name, date of birth, age, position and status.

**Athletes + Crew** — everything above, plus each athlete's crew: name, relationship, email and phone number.

Both open straight into Excel, Google Sheets or Numbers. The file is named for the team and the day you downloaded it, so a folder of them stays readable — for example, `Sharks-U12-roster-2026-08-25.csv`.

## What's in it

Every player currently on the roster, listed in the same order as the roster page. Players marked injured, suspended or inactive are included, with their status in its own column so you can sort or filter on it. Players who have left the team are not included.

Dates of birth are written as `YYYY-MM-DD` so spreadsheets sort them properly, and age is worked out for you as of the day you download.

If one athlete has three crew members and another has one, the file makes room for three — nobody's second parent gets dropped to make the columns line up.

## Who can't download it

Parents and players can see their team's roster in the app, but they can't download it. The crew version is a contact list for other families on the team, and a file carries on existing long after someone has left the club, so downloading stays with coaches and club admins.

Every download is recorded in the club's audit log: who downloaded which team's roster, which version, and when.

## Limits

A single file holds up to 1,000 players and 25 columns, which is room for four crew members per athlete. No team is close to either limit today. If a roster ever doesn't fit, the app tells you exactly what was left out rather than quietly handing you a short file.
MD;

if ($dryRun) {
    echo "--- Download your roster ---\n\n{$body}\n";
    exit(0);
}

$pdo = new PDO(
    sprintf('pgsql:host=%s;port=%s;dbname=%s;sslmode=require',
        Env::get('DB_HOST'), Env::get('DB_PORT', '5432'), Env::get('DB_NAME')),
    Env::get('DB_USER'),
    Env::get('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$existing = $pdo->prepare('SELECT id, is_published FROM help_release_notes WHERE slug = ?');
$existing->execute([$slug]);
if ($row = $existing->fetch(PDO::FETCH_ASSOC)) {
    echo "Already published as id {$row['id']} (slug {$slug}). Nothing to do.\n";
    exit(0);
}

$author = $pdo->query("
    SELECT id, email, first_name, last_name
    FROM users WHERE system_role = 'super_admin' ORDER BY id LIMIT 1
")->fetch(PDO::FETCH_ASSOC);
if (!$author) {
    fwrite(STDERR, "No super_admin account found — create-release-note is super-admin only.\n");
    exit(1);
}
echo "Authoring as {$author['email']} (user {$author['id']})\n";

$token = JWT::generateEnhanced(
    $pdo, $author['id'], $author['email'],
    trim($author['first_name'] . ' ' . $author['last_name']), 32, 'club'
);

$base = Env::get('API_BASE_URL', 'https://teamselevated-backend-0485388bd66e.herokuapp.com');
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => rtrim($base, '/') . '/api/help-gateway.php?action=create-release-note',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_TIMEOUT => 45,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Authorization: Bearer {$token}"],
    CURLOPT_POSTFIELDS => json_encode([
        'title'         => 'Download your roster',
        'body_markdown' => $body,
        'release_date'  => '2026-08-25',
        'tags'          => ['feature', 'admin', 'coach', 'roster', 'export'],
        'is_published'  => true,
    ]),
]);
$out  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

echo "HTTP {$code}\n{$out}\n";
exit($code === 200 ? 0 : 1);
