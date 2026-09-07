<?php
/**
 * Help articles for the features shipped 31 Aug – 6 Sep 2026.
 *
 * Six articles: lineups (coach + parent), referee feedback (coach + admin),
 * staff access on Club Settings → Users (admin), assigning coaches to teams
 * (admin). Same shape as scripts/publish-chat-features-help-articles.php —
 * POSTs to help-gateway.php?action=create-article (super-admin gated), and
 * re-running UPDATES an article matched on category + TITLE (the gateway
 * derives its own slug from the title, so matching on slug creates a duplicate).
 *
 *   ... --dry-run
 *   ... --author=<email>
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

$lineupCoach = <<<'MD'
Set who starts, where they play, and who is on the bench — from your phone on the sideline if you need to — and keep a default so you are not starting over every week.

## Open the lineup

- From the team: open the team and click **Lineups**, then pick the game.
- From the calendar: open a game and click **Lineup**.

The first time you open a game's lineup it starts from your team's **default** lineup if you have saved one, and says so at the top.

## Place players

1. Pick a **formation** from the dropdown. The options match your field size (4v4, 7v7, 9v9 or 11v11), which comes from the team's age group.
2. Tap a spot on the pitch, then tap a player from the bench below. The player moves onto the pitch.
3. To swap two players, tap one spot and then the other.
4. To take a player off the pitch, tap their spot and press **Remove from pitch**.

The counter at the top shows how many are on the field, for example **9/11**. You cannot save more players on the field than the format allows.

## Who is on the bench

The bench lists everyone on the roster who is not on the field, sorted by their usual position.

- Players marked **absent** or **excused** for this game are greyed out. You can still place them, but you are warned.
- Players marked **injured** or **suspended** carry a badge. They can sit on the bench; putting them on the field is refused.

## Save, reuse, publish, print

- **Save** keeps this game's lineup.
- **Save as default** keeps it as the team's default, which every new game starts from.
- **Use default** and **Use last game** copy from those.
- **Publish to crew** shows the lineup to families on their portal, with their own child highlighted. Nothing is shown to families until you publish, and you can unpublish.
- **Print** opens a clean version for a screenshot or a printed sheet.

## Who can do this

Coaches of the team and club admins can build and publish lineups. Families see a lineup only after it is published, and they see the starters and bench names, not your notes.
MD;

$lineupParent = <<<'MD'
When your coach publishes the lineup for a game, you can see it on the game itself.

## Where to find it

Open **Schedule** in the portal, then the game. If the coach has published a lineup, it appears below the game details as a pitch diagram with the starting players and the bench.

Your child is **highlighted** so you can find them at a glance.

## What you will and will not see

- The starting eleven (or seven, or nine, depending on the format) and who is on the bench.
- Not the coach's notes, and not the order of substitutes.
- If nothing appears, the coach has not published a lineup for that game yet. Coaches choose when, and whether, to share it.

Lineups can change up to kick-off, so check again on game day.
MD;

$refereeCoach = <<<'MD'
After a game, you can record how the referee did. Your club uses this to spot patterns and to raise concerns with the assignor.

## Where

Open a **past** game in the calendar and click **Referee feedback**. The button appears only on games that have already happened.

## What to record

- **Referee's name.** Type it as it appears on the match card.
- **Rating** from 1 to 5.
- **Areas** the rating applies to: control, consistency, communication, safety, punctuality. Pick as many as apply.
- **Comments.** Specific beats general: "two late tackles in the second half went uncalled" is more useful than "poor".
- **Flag as incident** if something happened that the club should look at — a safety issue, abuse, or a decision that changed the outcome. Flagged feedback is shown separately to your club admins.

There may be more than one referee in a game; add a separate entry for each.

## Editing

You can come back and edit your own feedback. You cannot see or edit other coaches' feedback; club admins can.

## Who sees it

Club admins. Families and players never see referee feedback.
MD;

$refereeAdmin = <<<'MD'
Every coach's referee feedback for the club in one place, so you can see patterns before they become problems.

## Where

**Programs → Referee Feedback**.

## The list

Each row is one coach's feedback on one referee for one game. Filter by date range, team, referee name, or incidents only. Rows a coach flagged as an incident are marked.

Click a row to read the full comments.

## Per-referee summary

Above the list, each referee name shows how many times they have been rated, their average rating, and how many incidents were flagged. Names are grouped exactly as coaches typed them, so "J. Smith" and "John Smith" appear separately.

## Download

**Download CSV** gives you the filtered list as a spreadsheet, for example to send to your referee assignor. Very large downloads are capped and the file tells you if it was.

## Who can do this

Club admins can review, filter and download. Coaches see only their own feedback. Families see nothing.
MD;

$staffAccess = <<<'MD'
Everything about getting a staff member signed in lives in one place.

## Where

**Club Settings → Users**. Each row is a person with a role in your club. Staff rows (club admin, coach, treasurer, volunteer) have an access button; crew rows point you to the Crew page, where families are handled.

## The access button

The button changes with the person's situation:

- **Invite** — they have never been invited. Sends an invitation email with a single-use link, valid seven days, to set their own password.
- **Resend invite** — their invitation has lapsed or been lost. Sends a fresh link and cancels the old one.
- **Send login link** — they already have a password but cannot get in right now. Sends a sign-in link valid for 24 hours. No password is changed.

The **Status** column tells you where each person is: invited, accepted, signed in, or never used.

## Set password

When email is not working for someone, **Set password** lets you set a temporary password for them. Type one or click **Generate**. It is shown **once**, with a copy button, so you can pass it on by phone or text. The person sees a reminder to change it the next time they sign in.

Every one of these actions is recorded in the club's audit trail, with who did it and when. The password itself is never recorded.

## Inviting new people

**Invite** at the top of the tab creates a new staff member: choose the role (Club Admin, Coach, Crew, or Treasurer) and enter their email. A treasurer reaches the payments and revenue screens and nothing else.

## Who can do this

Club admins only.
MD;

$assignCoach = <<<'MD'
Put a coach on a team without leaving the Coaches page.

## Where

**People → Coaches**. Each coach's row has **Assign to Team**.

## Assign

1. Click **Assign to Team**.
2. Choose the team. Teams are grouped by program.
3. Choose the role: **Head coach**, **Assistant coach** or **Team manager**.
4. Click **Assign**.

If the team already has a head coach and you choose Head coach, you are told who would be replaced before you confirm. The previous head coach stays on the team's staff record until you remove them.

## See and remove assignments

**View Teams** on a coach lists every team they are on and the role on each. Click **Unassign** to take them off a team. Their history on that team is kept.

The **Teams** count in the coach's row includes all three roles.

## Phone numbers

**Edit** on a coach now includes a phone number. It is filled in from their account if one is on file, and it is used for text messages from the club.

## Who can do this

Club admins. The change is recorded in the audit trail.
MD;

$articles = [
    [
        'category'  => 'for-coaches',
        'title'     => 'Building a game lineup',
        'summary'   => 'Pick a formation, tap players onto the pitch, keep a team default, and publish the lineup to families when you are ready.',
        'role_tags' => ['coach', 'admin'],
        'feature'   => 'lineups',
        'keywords'  => ['lineup', 'lineups', 'formation', 'starting lineup', 'bench', 'starters', 'game day', 'publish lineup', 'print lineup'],
        'body'      => $lineupCoach,
    ],
    [
        'category'  => 'for-parents',
        'title'     => 'Seeing the lineup for a game',
        'summary'   => 'Where a published lineup appears on the schedule, what it shows, and why it might not be there yet.',
        'role_tags' => ['parent'],
        'feature'   => 'lineups',
        'keywords'  => ['lineup', 'starting', 'is my child starting', 'bench', 'game', 'schedule'],
        'body'      => $lineupParent,
    ],
    [
        'category'  => 'for-coaches',
        'title'     => 'Giving feedback on a referee',
        'summary'   => 'Rate the referee after a game, note the areas it applies to, and flag anything the club should look at.',
        'role_tags' => ['coach', 'admin'],
        'feature'   => 'referee-feedback',
        'keywords'  => ['referee', 'ref', 'feedback', 'rating', 'incident', 'match official', 'complaint'],
        'body'      => $refereeCoach,
    ],
    [
        'category'  => 'for-admins',
        'title'     => 'Reviewing referee feedback',
        'summary'   => 'Filter every coach\'s referee feedback, see incidents and per-referee averages, and download a CSV for your assignor.',
        'role_tags' => ['admin'],
        'feature'   => 'referee-feedback',
        'keywords'  => ['referee', 'feedback', 'incident', 'assignor', 'csv', 'ratings', 'review'],
        'body'      => $refereeAdmin,
    ],
    [
        'category'  => 'for-admins',
        'title'     => 'Managing staff access: invites, login links and passwords',
        'summary'   => 'Invite a staff member, resend a lapsed invite, send a login link, or set a temporary password, all from Club Settings → Users.',
        'role_tags' => ['admin'],
        'feature'   => 'club-users',
        'keywords'  => ['invite', 'resend invite', 'login link', 'magic link', 'password', 'reset password', 'temporary password', 'cannot log in', 'staff access', 'users', 'treasurer'],
        'body'      => $staffAccess,
    ],
    [
        'category'  => 'for-admins',
        'title'     => 'Assigning coaches to teams',
        'summary'   => 'Put a coach on a team as head coach, assistant or team manager from the Coaches page, and see or remove their assignments.',
        'role_tags' => ['admin'],
        'feature'   => 'coach-management',
        'keywords'  => ['assign coach', 'head coach', 'assistant coach', 'team manager', 'coaches', 'view teams', 'unassign', 'coach phone'],
        'body'      => $assignCoach,
    ],
];

if ($dryRun) {
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
    fwrite(STDERR, "No matching super_admin — create-article is super-admin only.\n");
    exit(1);
}

$token = JWT::generateEnhanced(
    $pdo, $author['id'], $author['email'],
    trim($author['first_name'] . ' ' . $author['last_name']), 32, 'club'
);
$base = Env::get('API_BASE_URL', 'https://teamselevated-backend-0485388bd66e.herokuapp.com');

$failed = 0;
foreach ($articles as $article) {
    $cat = $pdo->prepare('SELECT id, name FROM help_categories WHERE slug = ? AND is_active = true');
    $cat->execute([$article['category']]);
    $category = $cat->fetch(PDO::FETCH_ASSOC);
    if (!$category) {
        fwrite(STDERR, "No active category '{$article['category']}'.\n");
        $failed++;
        continue;
    }

    // Match on TITLE: the gateway derives the slug from it (see the 08-28 script).
    $existing = $pdo->prepare('SELECT id FROM help_articles WHERE category_id = ? AND title = ? ORDER BY id LIMIT 1');
    $existing->execute([$category['id'], $article['title']]);
    $existingId = ($row = $existing->fetch(PDO::FETCH_ASSOC)) ? (int) $row['id'] : null;
    if ($existingId) {
        echo "[{$category['name']}] updating id {$existingId}\n";
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => rtrim($base, '/') . '/api/help-gateway.php?action='
            . ($existingId ? "update-article&id={$existingId}" : 'create-article'),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $existingId ? 'PUT' : 'POST',
        CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Authorization: Bearer {$token}"],
        CURLOPT_POSTFIELDS => json_encode([
            'category_id'     => (int) $category['id'],
            'title'           => $article['title'],
            'summary'         => $article['summary'],
            'body_markdown'   => $article['body'],
            'role_tags'       => $article['role_tags'],
            'related_feature' => $article['feature'],
            'search_keywords' => $article['keywords'],
            'sort_order'      => 0,
            'is_published'    => true,
        ]),
    ]);
    $out  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    echo "[{$category['name']}] {$article['title']} → HTTP {$code} {$out}\n";
    if ($code !== 200) { $failed++; }
}

exit($failed === 0 ? 0 : 1);
