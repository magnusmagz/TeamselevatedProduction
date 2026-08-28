<?php
/**
 * Release note for the 2026-08-28 chat work.
 *
 * ⚠️ Chat notifications are positioned as a FIX, not a new feature (Maggie,
 * 2026-08-28). Families reasonably expected to be told when someone messaged
 * them; announcing that as new implies it was never promised, and everyone who
 * quietly gave up on the app knows better. Saying it was broken and is now
 * fixed is both truer and easier to trust.
 *
 * Same shape as scripts/publish-roster-download-release-note.php: POSTs to
 * help-gateway.php?action=create-release-note, which is super-admin gated.
 *
 *   ... --dry-run
 *   ... --author=<email>
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
$dryRun = in_array('--dry-run', $argv, true);

$title = 'Chat: notifications fixed, plus reactions, polls and pinned messages';

$body = <<<'MD'
Chat has had a substantial week. The headline is a fix rather than a feature: **notifications now actually work.** Alongside that, chat gains reactions, polls and pinned messages.

**Who can use it:** everyone can react, vote and see pinned messages. Coaches and club admins can create polls and pin messages.
**Where to find it:** the chat panel on the main app, and **Chat** in the parent portal.

## Notifications: this was broken, and it is now fixed

If you have ever sent a message and wondered why nobody replied, this is why. Chat had no notifications of any kind — no email, nothing on your phone. Unless you happened to have the app open with the conversation on screen, a message reached you only when you next went looking for it.

That is fixed.

- **Email** tells you about messages you have not read, a few minutes after they arrive. Nothing to switch on; it works for everyone already.
- **Notifications on your phone or computer** arrive within seconds and work when the app is closed. Turn them on in **My Profile → Notifications**, or from the prompt you will see in the app.

If you are reading a conversation as it happens, nothing is sent — the point is to catch what you miss, not to duplicate what you have seen.

**On an iPhone or iPad** there is one extra step: add Teams Elevated to your Home Screen and open it from there. Apple only sends notifications to apps saved that way. The app will tell you how.

We also found and fixed several related problems in the same pass: unread counts that never updated until you refreshed, and messages that appeared twice to the person who sent them.

## React to a message

Six reactions: 👍 ❤️ 🎉 👏 😂 😮

Tap the **+** under any message. Tap the same one again to take it back, and hover a reaction to see who used it.

There is deliberately nothing negative in the set. A thumbs-down in a team chat full of parents starts arguments that a message on its own would not, and we would rather not put that on the screen.

## Polls

Coaches and club admins can ask the group to choose between options — *team dinner Friday at 7, or Thursday at 6:30?* — instead of counting fourteen replies by hand.

Tap **+ Create a poll** above the message box. Write a question and the options, or use **Yes / No** for a straight question. Three choices at the time you create it:

- **Make anonymous** — hides who voted. This one is permanent, so the poll makes a promise it can keep.
- **Hide results until someone votes** — stops early answers swaying later ones.
- **Closes** — an optional deadline. You can change it afterwards.

Anyone in the conversation can vote, and change their mind until the poll closes.

Polls are for choosing between options. If you need to know who is bringing what, that is a different job and we have not built it yet — tell us if you want it.

## Pin a message

Coaches and club admins can pin one message to the top of a conversation, so the thing everyone keeps scrolling back for stays put — practice details, a field change, the address for Saturday.

Hover a message and tap the pin. Pinning a different message replaces the pin: there is only ever one, so it is either current or obviously out of date, rather than one of four things nobody has tidied.

Everyone in the conversation sees the pin. Only coaches and club admins can change it.

## For club admins

Club administrators are told about high-severity flagged messages as they happen, and get a weekly summary of anything still waiting for review. Reported messages had been queuing up with nobody notified since the moderation tools launched.

The **Reported Messages** item in Communications now carries a count of what is outstanding.

## What is coming next

- Letting people pick more than one option in a poll.
- A prompt to nudge people who have not voted yet — we are wary of making the app nag, so tell us if it would be useful.
MD;

if ($dryRun) {
    echo str_repeat('=', 72), "\n{$title}\n", str_repeat('=', 72), "\n\n{$body}\n";
    exit(0);
}

$pdo = Database::getInstance()->getConnection();

$existing = $pdo->prepare('SELECT id FROM help_release_notes WHERE title = ?');
$existing->execute([$title]);
if ($row = $existing->fetch(PDO::FETCH_ASSOC)) {
    echo "Already published as id {$row['id']}. Nothing to do.\n";
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
    CURLOPT_URL => rtrim($base, '/') . '/api/help-gateway.php?action=create-release-note',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_TIMEOUT => 45,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Authorization: Bearer {$token}"],
    CURLOPT_POSTFIELDS => json_encode([
        'title'         => $title,
        'body_markdown' => $body,
        'release_date'  => '2026-08-28',
        // 'fix' leads deliberately — notifications are the headline and they are
        // a repair, not an addition.
        'tags'          => ['fix', 'feature', 'chat', 'notifications', 'parent', 'coach', 'admin'],
        'is_published'  => true,
    ]),
]);
$out  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

echo "HTTP {$code}\n{$out}\n";
exit($code === 200 ? 0 : 1);
