<?php
/**
 * Release note + help article for scholarships on invoices (2026-09-15).
 *
 * Features only (Maggie's rule): what people can do now, who it is for, where
 * to find it. Same shape as scripts/publish-2026-09-09-club-page-help.php —
 * the article is matched on category + TITLE and updated in place on rerun;
 * the release note is matched on title and updated with --update-note=<id>.
 *
 *   heroku run --no-tty -a teamselevated-backend -- php scripts/publish-2026-09-15-scholarships-help.php --dry-run
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

$noteTitle = 'Scholarships on invoices';

$noteBody = <<<'MD'
Club admins and treasurers can now reduce what a family owes on an athlete's invoice with a **scholarship**, and see every scholarship the club has awarded in one place.

## Awarding one

Open the athlete's invoice — from their profile's **Payments** tab, or from **Payments → Outstanding Balances** — and choose **Apply scholarship**. Enter a dollar amount or a percent, the label the family will see (it says "Scholarship" unless you change it), and a reason for the club's records. The invoice total drops right away, and if the scholarship covers the whole balance the invoice is marked paid.

## What the family sees

The scholarship appears as a line on their invoice in the parent portal and in the invoice email, with the label and the amount. The reason stays with the club.

## Keeping track

**Payments → Revenue → Scholarships** lists every scholarship in your club with the total awarded, who awarded each one and why. The Reporting panel on **Club Settings → Payments** shows the same total next to collected and refunded.
MD;

// ─── Article ─────────────────────────────────────────────────────────────────

$adminBody = <<<'MD'
A scholarship reduces what one family owes on one athlete's invoice. It is awarded by a club admin or treasurer, shows on the family's invoice as a labelled line, and is tracked for the club in one report.

## Awarding a scholarship

1. Open the athlete's invoice. Two ways to get there:
   - The athlete's profile → **Payments** tab.
   - **Payments → Outstanding Balances** → expand the family.
2. Choose **Apply scholarship** on the invoice.
3. Enter the amount. Use the **$ / %** switch to enter a percent of the fee instead; the modal shows the dollar figure it will save.
4. Set the **label the family sees**. It defaults to "Scholarship". Use something like "Booster Club Scholarship" or "Adjustment" if the club prefers.
5. Write a **reason**. This is required and stays with the club; families never see it.
6. Check the preview (fee, any sibling discount, scholarship, new total, anything already paid) and choose **Apply scholarship**.

The invoice total updates immediately. Checkout and any contribution link on the invoice ask for the new balance.

## What a scholarship can and cannot do

- It can be any amount up to the fee after the sibling discount.
- If it covers the whole balance, the invoice is marked **paid** and the family owes nothing.
- It cannot take the total below what the family has already paid. If a family has paid more than the new total would be, refund first, then award.
- An invoice holds one scholarship. Applying another replaces it; the earlier one is kept in the club's audit history.

## Changing or removing one

Open the same invoice and choose **Edit scholarship**. Change the amount, label or reason and save, or choose **Remove scholarship** to restore the original total.

## What families see

The label and the amount, as a line on the invoice in the parent portal and in the invoice email. Not the reason, and not who awarded it.

## The Scholarships report

**Payments → Revenue → Scholarships** lists every scholarship in the club: athlete, invoice, program, label, amount, balance due, status, when it was awarded and by whom, and the reason. The total awarded is at the top. **Edit** on any row opens the same modal.

The Reporting panel on **Club Settings → Payments** shows the total awarded next to Collected and Refunded. Scholarships are fees the club chose not to collect, so they are not part of net revenue.

## Who can do this

Club admins and treasurers. Coaches cannot award scholarships and do not see the reason or the report.
MD;

$articles = [
    [
        'category'  => 'for-admins',
        'title'     => 'Scholarships on invoices',
        'summary'   => 'Reduce what a family owes on an athlete\'s invoice, with a label the family sees and a reason the club keeps. Awarded from the invoice; tracked under Payments → Revenue → Scholarships.',
        'role_tags' => ['admin'],
        'feature'   => 'payments',
        'keywords'  => ['scholarship', 'financial aid', 'discount', 'invoice', 'waive', 'reduce fee', 'treasurer', 'outstanding balances', 'payments'],
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
        'release_date'  => '2026-09-15',
        'tags'          => ['payments', 'scholarships', 'admin', 'treasurer'],
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
