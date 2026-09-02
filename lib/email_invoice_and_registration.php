<?php
/**
 * Two transactional emails that were demo stubs until Phase 2 (2026-09-02):
 *
 *   - the invoice email       (`api/invoices.php?action=send`, which logged
 *                             "DEMO: Would send invoice email to ..." and then
 *                             answered `success: true`)
 *   - the registration confirmation
 *                            (`registration/registrations-api.php` POST, where
 *                             the call was commented out entirely)
 *
 * Both are club-branded. The caller builds `(new Email())->forClub($pdo, $clubId)`
 * and hands the instance here; the club id comes from the invoice's program (or
 * the athlete) and from the registration's program — never from a request body.
 *
 * ⚠️ WHY THE TEMPLATES LIVE HERE AND NOT IN `lib/Email.php`
 * `Email` already carries eleven near-identical copies of the same markup and they
 * drift (see EmailButtonContrastTest, which exists because four of them drifted at
 * once). Two more would be two more copies to keep in step, and the file is
 * currently being edited by another workstream. These are plain functions taking an
 * `Email`, so the send path, the From resolution and the SendGrid/mail() transport
 * choice all stay exactly where they were.
 *
 * Rendering is separated from sending on purpose: `te_invoice_email_content()` and
 * `te_registration_confirmation_content()` are pure, so a test can assert what a
 * family actually receives without a network call or a SendGrid key.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/Email.php';

/**
 * Dispatch through the Email instance the caller already branded.
 *
 * `Email::send()` is the one place that picks SendGrid vs mail() and stamps the
 * From — every public `sendX()` on that class ends up there, `sendParentInvite()`
 * included. It is `private`, and the rest of Phase 2 chose to add dedicated public
 * `sendX()` methods rather than open it, so this reaches it reflectively.
 *
 * If it is ever made public, the first branch takes over unchanged and the
 * reflection fallback becomes dead code to delete. Two branches, one method name —
 * nothing here guesses at an API that does not exist.
 *
 * @return bool true only if the transport reported the message accepted
 */
function te_email_dispatch(Email $email, string $to, string $subject, string $html, string $text): bool
{
    $method = new ReflectionMethod($email, 'send');

    if ($method->isPublic()) {
        return (bool) $email->send($to, $subject, $html, $text);
    }

    $method->setAccessible(true);

    return (bool) $method->invokeArgs($email, [$to, $subject, $html, $text]);
}

/**
 * Money, formatted once.
 *
 * A null or non-numeric amount renders as $0.00 rather than as an empty string —
 * a blank in the "amount due" line of an invoice email reads as "nothing owed",
 * which is the wrong default for a bill.
 */
function te_email_money($amount): string
{
    return '$' . number_format((float) $amount, 2);
}

/**
 * A date-only column, formatted in ONE timezone.
 *
 * `due_date` is a DATE. Parsing it into a timestamp and formatting it back is the
 * bug that put practices on the wrong weekday (CLAUDE.md, PracticeScheduler): the
 * parse and the format disagree about the zone all evening in Central. So the
 * year/month/day are read off the STRING and never converted.
 *
 * Anything that is not a YYYY-MM-DD prefix comes back unchanged — a value we
 * cannot read is better shown raw than silently turned into today.
 */
function te_email_format_date_only($value): string
{
    $value = trim((string) $value);

    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m)) {
        return $value;
    }

    $months = [1 => 'January', 'February', 'March', 'April', 'May', 'June',
               'July', 'August', 'September', 'October', 'November', 'December'];
    $month = $months[(int) $m[2]] ?? $m[2];

    return $month . ' ' . (int) $m[3] . ', ' . $m[1];
}

/**
 * The shared button markup.
 *
 * The white label is inline on the anchor AND on a nested span. Mail clients
 * routinely override anchor colour with their own link styling, and some override
 * the anchor but not its children — a <style> block alone renders a blue label on
 * the dark green button. Pinned for the Email class templates by
 * EmailButtonContrastTest; these two go through the same shape.
 */
function te_email_cta_button(string $url, string $label): string
{
    $url = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    $label = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');

    return '<a href="' . $url . '" class="button" style="display: inline-block; background: #12443E; '
        . 'color: #ffffff !important; padding: 15px 30px; text-decoration: none; border-radius: 5px; '
        . 'margin: 20px 0; font-weight: 600;">'
        . '<span style="color: #ffffff !important; text-decoration: none;">' . $label . '</span></a>';
}

/** Shared page chrome, so the two templates cannot drift apart in style. */
function te_email_shell(string $clubName, string $heading, string $bodyHtml): string
{
    $clubName = htmlspecialchars($clubName, ENT_QUOTES, 'UTF-8');
    $heading = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');
    $year = date('Y');

    return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #12443E; color: #ffffff; padding: 30px; text-align: center; }
        .header h1 { margin: 0; font-size: 22px; }
        .content { background: #f9f9f9; padding: 30px; }
        .button { display: inline-block; background: #12443E; color: #ffffff !important; padding: 15px 30px; text-decoration: none; border-radius: 5px; margin: 20px 0; }
        .button span { color: #ffffff !important; }
        .card { background: #ffffff; border: 1px solid #e2e2e2; border-radius: 8px; padding: 20px; margin: 20px 0; }
        .amount { font-size: 32px; color: #12443E; font-weight: bold; text-align: center; margin: 10px 0; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.items th { text-align: left; font-size: 12px; text-transform: uppercase; color: #666; border-bottom: 1px solid #ddd; padding: 6px 0; }
        table.items td { padding: 8px 0; border-bottom: 1px solid #f0f0f0; font-size: 14px; }
        table.items td.num { text-align: right; white-space: nowrap; }
        .row { display: flex; justify-content: space-between; padding: 8px 0; }
        .muted { color: #666; font-size: 13px; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>{$clubName}</h1>
        </div>
        <div class="content">
            <h2>{$heading}</h2>
{$bodyHtml}
        </div>
        <div class="footer">
            <p>{$clubName} &middot; via Teams Elevated</p>
            <p>&copy; {$year} Teams Elevated. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
HTML;
}

/**
 * Render the invoice email.
 *
 * @param array $ctx club_name, guardian_first, athlete_name, program_name,
 *                   invoice_number, invoice_date, due_date, total_amount,
 *                   amount_paid, amount_due, memo, items[], pay_url
 * @return array{subject:string,html:string,text:string}
 */
function te_invoice_email_content(array $ctx): array
{
    $clubName      = trim((string) ($ctx['club_name'] ?? '')) !== '' ? (string) $ctx['club_name'] : 'Teams Elevated';
    $guardianFirst = trim((string) ($ctx['guardian_first'] ?? '')) !== '' ? (string) $ctx['guardian_first'] : 'there';
    $athleteName   = trim((string) ($ctx['athlete_name'] ?? ''));
    $programName   = trim((string) ($ctx['program_name'] ?? ''));
    $invoiceNumber = trim((string) ($ctx['invoice_number'] ?? ''));
    $dueRaw        = (string) ($ctx['due_date'] ?? '');
    $memo          = trim((string) ($ctx['memo'] ?? ''));
    $payUrl        = (string) ($ctx['pay_url'] ?? '');
    $items         = is_array($ctx['items'] ?? null) ? $ctx['items'] : [];

    $amountDue = te_email_money($ctx['amount_due'] ?? 0);
    $total     = te_email_money($ctx['total_amount'] ?? 0);
    $paid      = te_email_money($ctx['amount_paid'] ?? 0);
    $hasPaid   = (float) ($ctx['amount_paid'] ?? 0) > 0;
    $dueDate   = $dueRaw !== '' ? te_email_format_date_only($dueRaw) : '';

    $subject = $invoiceNumber !== ''
        ? "Invoice $invoiceNumber from $clubName — $amountDue due"
        : "Invoice from $clubName — $amountDue due";

    $e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    // Line items. Every value here is club- or import-supplied text, so it is
    // escaped; a description containing an ampersand or a quote must not be able
    // to break the table (or anything else) in a family's inbox.
    $itemRows = '';
    foreach ($items as $item) {
        $qty = (float) ($item['quantity'] ?? 1);
        $qtyLabel = $qty == (int) $qty ? (string) (int) $qty : rtrim(rtrim(number_format($qty, 2), '0'), '.');
        $itemRows .= '                        <tr><td>' . $e($item['description'] ?? 'Item') . '</td>'
            . '<td class="num">' . $e($qtyLabel) . '</td>'
            . '<td class="num">' . $e(te_email_money($item['unit_price'] ?? 0)) . '</td>'
            . '<td class="num">' . $e(te_email_money($item['line_total'] ?? 0)) . "</td></tr>\n";
    }

    $itemsHtml = '';
    if ($itemRows !== '') {
        $itemsHtml = "                <table class=\"items\">\n"
            . "                    <thead><tr><th>Description</th><th style=\"text-align:right;\">Qty</th>"
            . "<th style=\"text-align:right;\">Unit</th><th style=\"text-align:right;\">Amount</th></tr></thead>\n"
            . "                    <tbody>\n" . $itemRows . "                    </tbody>\n"
            . "                </table>\n";
    }

    $forLine = '';
    if ($athleteName !== '') {
        $forLine = '                <p>This invoice is for <strong>' . $e($athleteName) . '</strong>'
            . ($programName !== '' ? ' &mdash; ' . $e($programName) : '') . ".</p>\n";
    } elseif ($programName !== '') {
        $forLine = '                <p>This invoice is for <strong>' . $e($programName) . "</strong>.</p>\n";
    }

    $dueLine = $dueDate !== ''
        ? '                <p style="text-align: center;" class="muted">Due ' . $e($dueDate) . "</p>\n"
        : '';

    $paidLine = $hasPaid
        ? '                <div class="row"><span class="muted">Invoice total</span><span>' . $e($total) . '</span></div>'
            . '<div class="row"><span class="muted">Already paid</span><span>' . $e($paid) . "</span></div>\n"
        : '';

    $memoHtml = $memo !== ''
        ? '                <p class="muted">' . $e($memo) . "</p>\n"
        : '';

    $ctaHtml = $payUrl !== ''
        ? "                <p style=\"text-align: center;\">" . te_email_cta_button($payUrl, 'View &amp; pay') . "</p>\n"
            . '                <p class="muted" style="word-break: break-all;">Or copy and paste this link: '
            . $e($payUrl) . "</p>\n"
        : '';

    $invoiceLabel = $invoiceNumber !== ''
        ? '                <p class="muted" style="text-align: center;">Invoice ' . $e($invoiceNumber) . "</p>\n"
        : '';

    $body = "            <p>Hi " . $e($guardianFirst) . ",</p>\n"
        . $forLine
        . "            <div class=\"card\">\n"
        . '                <div class="amount">' . $e($amountDue) . "</div>\n"
        . '                <p style="text-align: center; margin: 0;" class="muted">Amount due</p>' . "\n"
        . $dueLine
        . $invoiceLabel
        . $paidLine
        . $itemsHtml
        . $memoHtml
        . "            </div>\n"
        . $ctaHtml
        . "            <p class=\"muted\">Questions about this invoice? Reply to this email and it will reach "
        . $e($clubName) . ".</p>\n";

    $html = te_email_shell($clubName, 'Your invoice', $body);

    // Plain text. The HTML part is not a fallback — a text/plain alternative that
    // omits the amount or the link is a worse email, not a shorter one.
    $text = "Hi $guardianFirst,\n\n";
    if ($athleteName !== '') {
        $text .= "This invoice is for $athleteName" . ($programName !== '' ? " - $programName" : '') . ".\n\n";
    } elseif ($programName !== '') {
        $text .= "This invoice is for $programName.\n\n";
    }
    $text .= "Amount due: $amountDue\n";
    if ($invoiceNumber !== '') {
        $text .= "Invoice: $invoiceNumber\n";
    }
    if ($dueDate !== '') {
        $text .= "Due: $dueDate\n";
    }
    if ($hasPaid) {
        $text .= "Invoice total: $total\nAlready paid: $paid\n";
    }
    if (!empty($items)) {
        $text .= "\nDetails:\n";
        foreach ($items as $item) {
            $text .= '- ' . (string) ($item['description'] ?? 'Item') . ': '
                . te_email_money($item['line_total'] ?? 0) . "\n";
        }
    }
    if ($memo !== '') {
        $text .= "\n$memo\n";
    }
    if ($payUrl !== '') {
        $text .= "\nView and pay: $payUrl\n";
    }
    $text .= "\nQuestions? Reply to this email and it will reach $clubName.\n\n$clubName\nvia Teams Elevated\n";

    return ['subject' => $subject, 'html' => $html, 'text' => $text];
}

/**
 * Send the invoice email through an already-branded Email instance.
 *
 * @return bool false if the transport refused it — the caller must report that
 *              rather than answering `sent: true`, which is the whole point of
 *              replacing the DEMO log line.
 */
function te_send_invoice_email(Email $email, string $to, array $ctx): bool
{
    $content = te_invoice_email_content($ctx);

    return te_email_dispatch($email, $to, $content['subject'], $content['html'], $content['text']);
}

/**
 * Render the registration confirmation.
 *
 * @param array $ctx club_name, guardian_first, athlete_name, program_name,
 *                   what_to_bring, portal_url
 * @return array{subject:string,html:string,text:string}
 */
function te_registration_confirmation_content(array $ctx): array
{
    $clubName      = trim((string) ($ctx['club_name'] ?? '')) !== '' ? (string) $ctx['club_name'] : 'Teams Elevated';
    $guardianFirst = trim((string) ($ctx['guardian_first'] ?? '')) !== '' ? (string) $ctx['guardian_first'] : 'there';
    $athleteName   = trim((string) ($ctx['athlete_name'] ?? ''));
    $programName   = trim((string) ($ctx['program_name'] ?? ''));
    $whatToBring   = trim((string) ($ctx['what_to_bring'] ?? ''));
    $portalUrl     = (string) ($ctx['portal_url'] ?? '');

    $subject = $programName !== ''
        ? "You're registered for $programName"
        : "Registration received by $clubName";

    $e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    $who = $athleteName !== ''
        ? '<strong>' . $e($athleteName) . '</strong>'
        : 'your athlete';
    $what = $programName !== ''
        ? '<strong>' . $e($programName) . '</strong>'
        : 'the program';

    // what_to_bring is free text a club types into the program record. It arrives
    // with the newlines they typed, so those become <br> — and it is escaped
    // first, so the escaping cannot be undone by the formatting.
    $bringHtml = '';
    if ($whatToBring !== '') {
        $bringHtml = "            <div class=\"card\">\n"
            . "                <p style=\"margin-top:0;\"><strong>What to bring</strong></p>\n"
            . '                <p style="margin-bottom:0;">' . nl2br($e($whatToBring)) . "</p>\n"
            . "            </div>\n";
    }

    $ctaHtml = $portalUrl !== ''
        ? "            <p style=\"text-align: center;\">" . te_email_cta_button($portalUrl, 'Open the parent portal') . "</p>\n"
            . '            <p class="muted" style="word-break: break-all;">Or copy and paste this link: '
            . $e($portalUrl) . "</p>\n"
        : '';

    $body = "            <p>Hi " . $e($guardianFirst) . ",</p>\n"
        . '            <p>We have received the registration for ' . $who . ' in ' . $what . ". Thank you!</p>\n"
        . '            <p>Nothing further is needed from you right now. ' . $e($clubName)
        . " will be in touch with next steps, including schedules and any paperwork still outstanding.</p>\n"
        . $bringHtml
        . $ctaHtml
        . '            <p class="muted">Questions? Reply to this email and it will reach ' . $e($clubName) . ".</p>\n";

    $html = te_email_shell($clubName, 'Registration received', $body);

    $text = "Hi $guardianFirst,\n\n"
        . 'We have received the registration for '
        . ($athleteName !== '' ? $athleteName : 'your athlete')
        . ' in ' . ($programName !== '' ? $programName : 'the program') . ". Thank you!\n\n"
        . "Nothing further is needed from you right now. $clubName will be in touch with next steps, "
        . "including schedules and any paperwork still outstanding.\n";
    if ($whatToBring !== '') {
        $text .= "\nWhat to bring:\n$whatToBring\n";
    }
    if ($portalUrl !== '') {
        $text .= "\nOpen the parent portal: $portalUrl\n";
    }
    $text .= "\nQuestions? Reply to this email and it will reach $clubName.\n\n$clubName\nvia Teams Elevated\n";

    return ['subject' => $subject, 'html' => $html, 'text' => $text];
}

/**
 * Send the registration confirmation through an already-branded Email instance.
 *
 * @return bool false if the transport refused it. The caller reports that as
 *              `confirmation_sent: false` and does NOT fail the registration —
 *              the family's place in the program does not depend on our mail
 *              provider.
 */
function te_send_registration_confirmation(Email $email, string $to, array $ctx): bool
{
    $content = te_registration_confirmation_content($ctx);

    return te_email_dispatch($email, $to, $content['subject'], $content['html'], $content['text']);
}

/**
 * The parent-portal URL a family pays an invoice at: /parent/pay/{invoiceId}
 * (frontend route `pay/:invoiceId` under `/parent`, rendered by PaymentPage).
 *
 * APP_URL is the Netlify frontend — not BACKEND_URL, which is what the tracking
 * pixel and click redirects use. Returns '' when APP_URL is unset, and the
 * templates then omit the button rather than mailing a link to nowhere.
 */
function te_invoice_pay_url($invoiceId): string
{
    $appUrl = rtrim((string) Env::get('APP_URL', ''), '/');

    if ($appUrl === '' || $invoiceId === null || $invoiceId === '') {
        return '';
    }

    return $appUrl . '/parent/pay/' . rawurlencode((string) $invoiceId);
}

/** The parent portal home, for the registration confirmation's CTA. */
function te_parent_portal_url(): string
{
    $appUrl = rtrim((string) Env::get('APP_URL', ''), '/');

    return $appUrl === '' ? '' : $appUrl . '/parent';
}
