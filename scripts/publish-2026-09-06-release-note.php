<?php
/**
 * Release note for the week of 31 Aug – 6 Sep 2026.
 *
 * Same rules as the 2026-08-28 note (Maggie): what people can do NOW, who it is
 * for, where to find it. No bugs, no security work, no roadmap, no confessions.
 * Features that are deployed but switched off are NOT mentioned — a note about
 * something nobody can reach yet is a promise, not a release.
 *
 * POSTs to help-gateway.php?action=create-release-note (super-admin gated).
 *
 *   ... --dry-run
 *   ... --author=<email>
 *   ... --update=<id>     republish over an existing note
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/JWT.php';

$opt = static function (string $name) use ($argv): ?string {
    foreach ($argv as $a) {
        if (str_starts_with($a, "--{$name}=")) { return substr($a, strlen($name) + 3); }
    }
    return null;
};
$dryRun   = in_array('--dry-run', $argv, true);
$updateId = $opt('update');

$title = 'Lineups, referee feedback, staff access and a cleaner look';

$body = <<<'MD'
A big week: coaches can build a game lineup, rate a referee, and be assigned to teams from one place; club admins manage every staff member's access from Club Settings; and every page in the staff app now shares one look.

## Build a lineup for a game

**Who:** coaches and club admins. **Where:** open a team and click **Lineups**, or click **Lineup** on any game in the calendar.

Pick a formation for your field size, then tap a spot on the pitch and tap a player. Tap two spots to swap them. Players marked absent for that game are greyed out, and injured or suspended players carry a badge so you see it before you put them on.

- **Save as default** keeps a team lineup you can start every game from.
- **Use last game** copies the previous game's lineup.
- **Publish to crew** shows the lineup to families on their portal, with their child highlighted. Nothing is shown until you publish.
- **Print** gives you a clean sheet for the sideline.

## Give feedback on a referee

**Who:** coaches and club admins. **Where:** open a past game in the calendar and click **Referee feedback**.

Enter the referee's name, a 1 to 5 rating, the areas it applies to (control, consistency, communication, safety, punctuality), and any comments. Tick **Flag as incident** if something needs the club's attention. You can come back and edit your own feedback later.

Club admins review everything at **Programs → Referee Feedback**: filter by team, date or referee, see incidents at a glance, and download a CSV. Families never see any of this.

## Assign a coach to a team from the Coaches page

**Who:** club admins. **Where:** **People → Coaches → Assign to Team**.

Choose the team and the role: head coach, assistant coach or team manager. If the team already has a head coach you are told who would be replaced before you confirm. **View Teams** on a coach now lists every team and role they hold, with an Unassign control on each.

The coach modal also has a **phone** field now, filled in from their account when there is one.

## Manage staff access from Club Settings

**Who:** club admins. **Where:** **Club Settings → Users**.

Each staff row has one button that does the right thing for that person: **Invite** for someone who has never been invited, **Resend invite** if theirs has lapsed, or **Send login link** for someone who already has an account but cannot get in. Beside it, **Set password** lets you set a temporary password when email is not working for them. It is shown once so you can pass it on, and the person is reminded to change it.

New coaches now receive their own invitation email with a single-use link to set their password.

## Treasurer

**Who:** club admins. **Where:** **Club Settings → Users → Invite**.

Treasurer is now an invitable role. A treasurer reaches every payments and revenue screen and nothing else, so a volunteer handling the money does not need to be a club admin.

## Coaches on programs, evaluations and tryouts

- **Program staff:** assign coaches to a program from the program's Staff tab, so the calendar and recipient search know who runs it.
- **Mid-year evaluations:** the athlete's **Performance** tab holds evaluations, development goals and a season trend. Families can read it.
- **Invite a player to tryouts** from the tryouts page.
- Coaches see tryout registrations for the age groups they coach, with a **show all** option.

## Programs, Home and fields

- **Home** is now an overview of teams, athletes, revenue and programs, and the app opens there.
- Programs can be **reordered**, **archived**, and collapsed by type.
- Fields carry a **size** (4v4, 7v7, 9v9, 11v11), matched to the age group when you schedule.
- The Athletes list has a **Consent** column you can sort and filter.

## Email signatures

**Where:** **My Profile → Signature**. Write a formatted signature and it is added to every email you send from the platform.

## For families

- **Lineups:** once your coach publishes one, the game on your **Schedule** shows the starting lineup with your child highlighted.
- **Chat:** links in messages are clickable, and message times show in your own timezone.
- If your account is not yet connected to an athlete, the portal now says so and what to do.

## One look everywhere

Every page in the staff app now shares the same header, table and buttons, in the club's colours. Nothing moved, it just matches.
MD;

if ($dryRun) {
    echo str_repeat('=', 72), "\n{$title}\n", str_repeat('=', 72), "\n\n{$body}\n";
    exit(0);
}

$pdo = Database::getInstance()->getConnection();

if (!$updateId) {
    $existing = $pdo->prepare('SELECT id FROM help_release_notes WHERE title = ?');
    $existing->execute([$title]);
    if ($row = $existing->fetch(PDO::FETCH_ASSOC)) {
        echo "Already published as id {$row['id']}. Re-run with --update={$row['id']} to replace it.\n";
        exit(0);
    }
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
    fwrite(STDERR, "No matching super_admin — create-release-note is super-admin only.\n");
    exit(1);
}

$token = JWT::generateEnhanced(
    $pdo, $author['id'], $author['email'],
    trim($author['first_name'] . ' ' . $author['last_name']), 32, 'club'
);
$base = Env::get('API_BASE_URL', 'https://teamselevated-backend-0485388bd66e.herokuapp.com');

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => rtrim($base, '/') . '/api/help-gateway.php?action='
        . ($updateId ? "update-release-note&id={$updateId}" : 'create-release-note'),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => $updateId ? 'PUT' : 'POST',
    CURLOPT_TIMEOUT => 45,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Authorization: Bearer {$token}"],
    CURLOPT_POSTFIELDS => json_encode([
        'title'         => $title,
        'body_markdown' => $body,
        'release_date'  => '2026-09-06',
        'tags'          => ['lineups', 'referee', 'coaches', 'staff access', 'programs', 'admin', 'coach', 'parent'],
        'is_published'  => true,
    ]),
]);
$out  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
echo "HTTP {$code}\n{$out}\n";
exit($code === 200 ? 0 : 1);
