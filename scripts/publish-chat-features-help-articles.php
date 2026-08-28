<?php
/**
 * Help articles for reactions, polls and pinned messages (2026-08-28).
 *
 * One per role, same reasoning as the chat-notification pair: a coach needs to
 * know how to CREATE these and what the choices mean; a parent needs to know how
 * to use them and what is expected of them. One combined article would bury each
 * audience's half in the other's.
 *
 * Idempotent on the slug within each category.
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

$coachBody = <<<'MD'
Chat has three tools beyond plain messages: reactions, polls and a pinned message. Polls and pinning are yours — parents and players can use them but not create them.

## Pin a message

One message can be pinned to the top of a conversation, where everyone sees it first. Practice details, a field change, the address for Saturday — the thing people keep scrolling back to find.

Hover over a message and tap the pin icon. To replace it, pin a different message; to remove it, tap **Unpin** on the banner.

**There is only ever one pinned message per conversation.** That is deliberate. A single pin is either current or obviously out of date; four pins are a second inbox nobody tidies, and people stop reading them.

Everyone in the conversation sees the pin. Only coaches and club admins can change it.

## Create a poll

Use a poll when the group needs to choose between options — *team dinner Friday at 7, or Thursday at 6:30?* — rather than counting replies by hand.

Tap **+ Create a poll** above the message box.

1. Write the question.
2. Add the options, or tap **Use Yes / No** for a straight question.
3. Choose your settings.
4. Tap **Post poll**.

### The three settings, and what they mean

**Make anonymous** — hides who voted. Everyone still sees the totals.

⚠️ **This cannot be changed once the poll is posted.** Turning it off later would expose votes people cast believing they were private, so the app will not let you. Decide before you post.

**Let people pick more than one** — for "which of these nights suit you?" rather than "pick one". The count then counts votes rather than people, since one person may choose several.

**Hide results until someone votes** — people see the totals only after voting. Useful when early answers might sway later ones. You can change this afterwards.

**Closes** — an optional deadline. After it passes nobody can vote, and everyone can see the results. You can move it later or earlier; shortening it stops new votes but never discards votes already cast.

### While it is running

Anyone in the conversation can vote and change their mind until the poll closes. Voting for the same option again takes the vote back. If the poll is not anonymous, hover an option to see who chose it.

### What a poll is not

A poll is for **choosing between options**. It is not a signup sheet — "who can bring oranges" is a different job, where each answer is a commitment and you want a list of names against tasks. We have not built that. Tell us if you need it.

## Reactions

Six: 👍 ❤️ 🎉 👏 😂 😮

Tap the **+** under any message, including your own. Tap a reaction again to take it back, and hover one to see who used it.

There is deliberately nothing negative in the set. A thumbs-down in a chat full of parents starts arguments a message on its own would not.

## A note on moderation

Polls and pinned messages are ordinary messages underneath, so everything that applies to a message applies to them: they can be reported, a club admin can remove them, and they are covered by your club's retention policy. A removed message stops being pinned and stops counting votes.
MD;

$parentBody = <<<'MD'
Alongside messages, chat has reactions, polls and a pinned message at the top of some conversations.

## The pinned message

Some conversations have a message pinned at the top, with a 📌 beside it. It is there because your coach or a club admin wants everyone to see it — practice details, a field change, an address.

It stays in view while you scroll. There is only ever one, so it is the current thing rather than a pile of old notices.

Only coaches and club admins can pin or change it.

## Voting in a poll

Your coach may post a poll instead of a question — *team dinner Friday at 7, or Thursday at 6:30?* Tap the option you want.

- **You can change your mind.** Tap a different option, or tap the same one again to take your vote back. This works until the poll closes.
- **Some polls are anonymous.** If it says so, your coach sees the totals but not who voted for what. If it does not say so, they can see.
- **Some polls hide the results until you vote.** Vote, and the totals appear.
- **Some polls let you pick more than one.** If so it says "pick as many as you like" under the options.
- **Some polls close.** If there is a closing time it is shown, and after it passes voting stops and everyone can see the results.

Only coaches and club admins can create a poll. Everyone can vote.

## Reacting to a message

Six reactions: 👍 ❤️ 🎉 👏 😂 😮

Tap the **+** under a message and pick one. Tap it again to take it back. Your name shows against a reaction — it is a quick reply rather than an anonymous vote, so people can see who said thanks.

Reacting is a good way to say "got it" without adding another message to a busy team chat.

## Getting told about all this

If you have notifications turned on you will hear about new messages, including polls. See **Getting notified about new messages** for how to set that up — including the extra step needed on an iPhone.
MD;

$articles = [
    [
        'category' => 'for-coaches',
        'title'    => 'Polls, pinned messages and reactions',
        'slug'     => 'polls-pinned-messages-and-reactions',
        'summary'  => 'Pin the message everyone keeps scrolling back for, run a poll to settle a choice, and react without adding to a busy thread.',
        'role_tags'=> ['coach', 'admin'],
        'body'     => $coachBody,
    ],
    [
        'category' => 'for-parents',
        'title'    => 'Polls, pinned messages and reactions',
        'slug'     => 'polls-pinned-messages-and-reactions-parents',
        'summary'  => 'How to vote in a poll, what the pinned message at the top means, and how to react to a message.',
        'role_tags'=> ['parent'],
        'body'     => $parentBody,
    ],
];

$keywords = ['poll', 'polls', 'vote', 'voting', 'pin', 'pinned', 'pinned message',
             'reaction', 'reactions', 'emoji', 'react', 'anonymous poll', 'chat'];

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

    $existing = $pdo->prepare('SELECT id FROM help_articles WHERE category_id = ? AND slug = ?');
    $existing->execute([$category['id'], $article['slug']]);
    $existingId = ($row = $existing->fetch(PDO::FETCH_ASSOC)) ? (int) $row['id'] : null;

    // Re-running updates in place rather than refusing, so the copy can be
    // revised without a second article appearing beside the first.
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
            'related_feature' => 'chat',
            'search_keywords' => $keywords,
            'sort_order'      => 0,
            'is_published'    => true,
        ]),
    ]);
    $out  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    echo "[{$category['name']}] HTTP {$code} {$out}\n";
    if ($code !== 200) { $failed++; }
}

exit($failed === 0 ? 0 : 1);
