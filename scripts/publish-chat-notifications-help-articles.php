<?php
/**
 * Publish the two Help articles for chat notifications (2026-08-26).
 *
 * One for parents, one for coaches. Same shape as
 * scripts/publish-roster-download-help-article.php: it POSTs to
 * help-gateway.php?action=create-article (super-admin gated) rather than
 * INSERTing, so each article gets the gateway's slug uniqueness, tag encoding
 * and author attribution.
 *
 * Two articles rather than one because the navigation genuinely differs — a
 * parent goes More > Account Settings, a coach goes through the profile menu —
 * and because the iPhone install step matters far more to families than to
 * staff on a laptop. One article covering both would bury the step that decides
 * whether it works.
 *
 * Idempotent on the slug within each category — a second run does nothing.
 *
 *   ... --dry-run      print the copy, write nothing
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

// ─── The copy ────────────────────────────────────────────────────────────────

$parentBody = <<<'MD'
Teams Elevated can tell you when your coach or club sends a message, so you do not have to keep opening the app to check.

There are two ways it can reach you, and you are already set up for one of them.

## Email — already on

You will get an email when someone messages you and you have not read it within a few minutes. Nothing to switch on; it works from the moment your account exists.

If you are reading your messages as they arrive, no email is sent — it is only there to catch what you miss.

## Notifications on your phone or computer — worth turning on

These arrive within seconds, make a sound, and work even when the app is closed. This is the one to turn on if you want to know straight away.

### On an iPhone or iPad — one step first

Apple only sends notifications to apps saved to your Home Screen. So:

1. Open Teams Elevated in Safari.
2. Tap the **Share** button (the square with an arrow pointing up).
3. Tap **Add to Home Screen**, then **Add**.
4. Open Teams Elevated from your Home Screen — the new icon, not Safari.

Now follow the steps below. Without this step the option will not appear at all, and that is Apple's rule rather than something the club can change.

### Turning them on

1. Tap **More** at the bottom, then **Account Settings**.
2. Find **Notifications**.
3. Tap **Turn on**.
4. Your browser or phone will ask whether to allow notifications. Choose **Allow**.

That is it. You can test it by asking someone to message you.

## If you do not see the option, or nothing happens

**"Turn on" does nothing, or says notifications are blocked.** You have previously told your browser to block this site. No button can undo that — you have to change it yourself:

- **Chrome or Edge:** click the icon just left of the web address, set Notifications to Allow, then reload the page.
- **Safari on a Mac:** Safari menu > Settings > Websites > Notifications, set Teams Elevated to Allow.
- **iPhone:** Settings > Notifications > Teams Elevated, and turn on Allow Notifications.

**Nothing arrives even though it says it is on.** Check your phone or computer's own notification settings — most devices have a separate switch per app, and a Focus or Do Not Disturb mode will hold everything back silently.

**It works on one device but not another.** That is expected. Notifications are per device, so turning them on your phone does not turn them on your laptop. Repeat the steps on each one you want.

## Turning it down

You do not have to have all of it.

- **One conversation too busy?** Open it and mute it. You stay in the conversation and can still read everything; it just stops alerting you.
- **Want to stop the phone notifications?** Go back to **More > Account Settings > Notifications** and tap Turn off.

Email is separate from muting a conversation, so you will still get the catch-up email for anything you have not read.
MD;

$coachBody = <<<'MD'
Teams Elevated can alert you when someone messages you, so you are not relying on opening the app to notice.

Two channels, and one of them is already working.

## Email — already on

If someone messages you and you have not read it within a few minutes, you get an email. No setup. If you are already reading the conversation, nothing is sent — it exists to catch what you miss, not to duplicate what you have seen.

Emails come from your club's name, so families and staff recognise them in an inbox.

## Browser and phone notifications — worth turning on

These arrive within seconds, make a sound, and work when the app is closed. If you coach and need to see a parent's message about a pickup or a cancellation, this is the one that matters.

### Turning them on

1. Click your name at the top right, then **My Profile**.
2. Find **Notifications**.
3. Click **Turn on**.
4. Your browser will ask whether to allow notifications. Choose **Allow**.

Send yourself a test by asking someone to message you.

### On a phone

Same steps in your phone's browser. On an **iPhone or iPad** there is one extra step first: open Teams Elevated in Safari, tap **Share**, then **Add to Home Screen**, and open it from that new icon. Apple only sends notifications to apps saved to the Home Screen, so the option will not appear otherwise.

## What you will and will not be told about

- Messages in your team conversations, and direct messages.
- **Not your own messages.**
- **Not a conversation you already have open** — if it is on your screen, you are reading it.
- Several messages in quick succession alert you each time, the same as any messaging app.

## If it is not working

**"Turn on" does nothing, or says notifications are blocked.** Your browser has this site on its block list, and no button on our side can undo that:

- **Chrome or Edge:** click the icon just left of the web address, set Notifications to Allow, reload the page.
- **Safari on a Mac:** Safari menu > Settings > Websites > Notifications, set Teams Elevated to Allow.
- **Firefox:** click the padlock left of the web address, clear the Notifications setting, reload.

**It says it is on, but nothing arrives.** Check your computer's own notification settings — on a Mac that is System Settings > Notifications, and each browser has its own entry there. A Focus or Do Not Disturb mode will hold everything back without telling you.

**Working in one browser, not another.** Expected — notifications are per browser and per device. Turn them on wherever you want them.

## Turning it down

- **Mute a busy conversation** by opening it and muting. You stay in it and can read everything; it stops alerting you.
- **Turn phone and browser notifications off** in **My Profile > Notifications**.

Muting a conversation does not stop the catch-up email for anything you have not read.

## A note on privacy

Notifications say who messaged you and how many messages are waiting. They deliberately **do not include the message text** — a notification appears on a lock screen where anyone nearby can read it, and an email cannot be recalled if a message is later removed by an administrator. To read the message, open the app.
MD;

$articles = [
    [
        'category' => 'for-parents',
        'title'    => 'Getting notified about new messages',
        'slug'     => 'getting-notified-about-new-messages',
        'summary'  => 'Email alerts are already on. Turn on phone and browser notifications to know straight away — including the extra step needed on an iPhone.',
        'role_tags'=> ['parent'],
        'body'     => $parentBody,
    ],
    [
        'category' => 'for-coaches',
        'title'    => 'Turning on chat notifications',
        'slug'     => 'turning-on-chat-notifications',
        'summary'  => 'Email alerts work already. Turn on browser and phone notifications for messages within seconds, and fix them when they are blocked.',
        'role_tags'=> ['coach', 'admin'],
        'body'     => $coachBody,
    ],
];

$keywords = ['notifications', 'chat', 'messages', 'alerts', 'push', 'email', 'sound',
             'notify', 'turn on notifications', 'not getting notifications', 'mute',
             'add to home screen', 'blocked notifications'];

if ($dryRun) {
    foreach ($articles as $a) {
        echo str_repeat('=', 70), "\n{$a['title']}  [{$a['category']}]\n", str_repeat('=', 70), "\n";
        echo $a['summary'], "\n\n", $a['body'], "\n\n";
    }
    exit(0);
}

// ─── Publish ─────────────────────────────────────────────────────────────────

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

    $existing = $pdo->prepare('SELECT id FROM help_articles WHERE category_id = ? AND slug = ?');
    $existing->execute([$category['id'], $article['slug']]);
    if ($row = $existing->fetch(PDO::FETCH_ASSOC)) {
        echo "[{$category['name']}] already published as id {$row['id']}. Nothing to do.\n";
        continue;
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => rtrim($base, '/') . '/api/help-gateway.php?action=create-article',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Authorization: Bearer {$token}"],
        CURLOPT_POSTFIELDS => json_encode([
            'category_id'     => (int) $category['id'],
            'title'           => $article['title'],
            'summary'         => $article['summary'],
            'body_markdown'   => $article['body'],
            // This DB's vocabulary is admin / coach / parent — NOT the
            // user_club_access value 'club_admin'.
            'role_tags'       => $article['role_tags'],
            'related_feature' => 'chat-notifications',
            'search_keywords' => $keywords,
            'sort_order'      => 0,
            'is_published'    => true,
        ]),
    ]);
    $out  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "[{$category['name']}] HTTP {$code} {$out}\n";
    if ($code !== 200) { $failed++; }
}

exit($failed === 0 ? 0 : 1);
