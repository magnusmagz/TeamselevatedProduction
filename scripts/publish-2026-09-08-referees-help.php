<?php
/**
 * Release note + help articles for Referees (2026-09-08).
 *
 * Features only (Maggie's rule): what people can do now, who it is for, where
 * to find it. Same shape as scripts/publish-2026-09-06-help-articles.php —
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

$noteTitle = 'Referees: a directory, a referee account, and games that find their own ref';

$noteBody = <<<'MD'
Referees now live in the platform. Clubs keep a referee directory, assign referees when they create a game, and referees sign in to a page that is just their games — including open games they can claim themselves.

## A referee directory

**Who:** club admins. **Where:** **People → Referees**.

Add a referee with their name, email, phone, **grade** (Grassroots, Regional, National, Professional, the assistant-referee grades, or the older numeric scale), certification and notes. Archive a referee who has moved on and restore them later. **Invite to portal** gives them a referee account.

A referee who works with more than one club is one person: if their email already has an account, your club links to it instead of creating a second one.

## Assign referees when you create a game

**Who:** club admins and the coaches of a team on the game. **Where:** the game's create form, and later the game's event modal.

Pick referees from the directory as you create the game, with a role for each (center, assistant, fourth). Set a **minimum referee grade** for the game, and choose whether referees can claim it themselves (on by default). The modal shows who is assigned, with their grade, and warns you if you place someone below the minimum or on a game that overlaps another of theirs.

## "Needs ref"

Any upcoming game without a center referee shows a **Needs ref** chip on the calendar, the team schedule and the game itself, for coaches and admins. Families do not see it.

## The referee's own page

**Who:** anyone with a referee account. **Where:** they sign in and land on their games.

- **Clubs** they referee for, as a filter.
- **Open games**: upcoming games in those clubs that still need a referee and that their grade qualifies for. **Take this game** claims it. Games that clash with one they already have are greyed out with the reason.
- **My games**: everything they are assigned to, across every club, upcoming first. A game they claimed can be released while it is still upcoming; a game the club assigned is the club's to change.
- Their contact card, editable.

## Referee feedback, joined up

Coaches giving feedback on a referee now pick from the directory, so the admin summary groups by the referee rather than by how their name was typed.
MD;

// ─── Articles ────────────────────────────────────────────────────────────────

$adminBody = <<<'MD'
Keep the club's referees in one place, and put them on games.

## The directory

**People → Referees** lists every referee your club works with: name, email, phone, grade, certification, and whether they have a portal account.

- **+ Add Referee** opens the form. Email and phone are optional, but a referee needs an email to be invited to the portal.
- **Grade** is the referee's official grade. Pick from Grassroots, Regional, National, Professional, the assistant-referee grades (Regional Assistant Referee, National Assistant Referee), or the older numeric scale (9 down to 1). Assistant grades qualify a referee to run the line, not to be center.
- **Edit** changes any of it. **Archive** hides a referee who has moved on without losing their history; **Show archived** and **Restore** bring them back.
- **Invite to portal** sends a single-use link so the referee can set a password and see their games. If their email already has an account with another club, your club is added to it rather than creating a duplicate.

## Assigning referees to a game

When you create a game, the form has a **Referees** section:

1. Type a name to search your directory and pick the referee.
2. Choose their role: **center**, **assistant** or **fourth**.
3. Optionally set a **minimum referee grade**. Referees below it will not see the game in their open-games list; you can still assign them yourself, with a warning.
4. Leave **Referees can claim this game** ticked if you want referees to be able to self-assign. Untick it for games you want to staff by hand.

After the game exists, the same block is on the game's event modal: assign more, unassign, and see who claimed the game themselves (marked **Self-assigned**).

If you place a referee on a game that overlaps another game they are already on, you are warned and the assignment is recorded as an override.

## Needs ref

Any upcoming game with no center referee shows a **Needs ref** chip on the calendar, the team schedule and the game modal. Coaches see it too. Families never do.

## Who can do this

Club admins manage the directory. Club admins and the coaches of a team on the game assign referees to it.
MD;

$coachBody = <<<'MD'
When you create a game you can put the referees on it at the same time.

## On the game form

1. In the **Referees** section, type a name to search the club's referee directory and pick one.
2. Choose their role: **center**, **assistant** or **fourth**.
3. Set a **minimum referee grade** if the game needs one. Referees below it will not see it as an open game.
4. Leave **Referees can claim this game** ticked to let qualified referees self-assign, or untick it to staff the game yourself.

You can do the same later from the game's event modal, and unassign from there.

## What you will see

- A **Needs ref** chip on any upcoming game with no center referee, on the calendar, your team schedule and the game itself.
- **Self-assigned** next to a referee who claimed the game themselves.
- A warning if you place someone below the game's minimum grade or on a game that clashes with another of theirs. You can go ahead; it is recorded as an override.

## Who can do this

Coaches of a team on the game, and club admins. Adding referees to the club's directory is a club admin job — ask them if someone is missing.
MD;

$refereeBody = <<<'MD'
Your referee account shows one thing: your games.

## Signing in

Your club sends you an invitation email with a single-use link. Set a password, and you land on your games page. If you referee for more than one club, they all appear on the same account.

## Your games page

- **Clubs** across the top: every club you referee for. Tap one to filter, or leave all selected.
- **Open games**: upcoming games that still need a referee and that your grade qualifies for. Tap **Take this game** and choose your role to claim it. A game that clashes with one you already have is greyed out with the reason. Games a club has closed to self-assignment do not appear here.
- **My games**: every game you are assigned to, across all your clubs, soonest first, with the date, time, venue, teams and your role. Past games are tucked under **Past**.
- **Your details**: your email and phone, which you can change. Your grade is set by the club.

## Releasing a game

If you claimed a game yourself, you can release it while it is still upcoming. A game the club assigned to you is the club's to change — contact them.

## Who can see what

Your clubs see your assignments and your directory entry. Nobody else in the platform sees your games, and you do not see anything else in the club's system.
MD;

$articles = [
    [
        'category'  => 'for-admins',
        'title'     => 'Managing referees and assigning them to games',
        'summary'   => 'Keep a referee directory with grades, invite referees to the portal, and put referees on games with a minimum grade and self-assign control.',
        'role_tags' => ['admin'],
        'feature'   => 'referees',
        'keywords'  => ['referee', 'referees', 'ref', 'assign referee', 'grade', 'needs ref', 'self-assign', 'directory'],
        'body'      => $adminBody,
    ],
    [
        'category'  => 'for-coaches',
        'title'     => 'Assigning referees to a game',
        'summary'   => 'Put referees on a game as you create it, set a minimum grade, and let qualified referees claim the game themselves.',
        'role_tags' => ['coach', 'admin'],
        'feature'   => 'referees',
        'keywords'  => ['referee', 'ref', 'assign referee', 'needs ref', 'game', 'minimum grade'],
        'body'      => $coachBody,
    ],
    [
        'category'  => 'getting-started',
        'title'     => 'Your referee account',
        'summary'   => 'Where your games are, how to claim an open game, and how to release one you took.',
        'role_tags' => [],
        'feature'   => 'referee-home',
        'keywords'  => ['referee', 'ref', 'my games', 'open games', 'claim', 'take this game', 'release'],
        'body'      => $refereeBody,
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
        'release_date'  => '2026-09-08',
        'tags'          => ['referees', 'games', 'admin', 'coach'],
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
