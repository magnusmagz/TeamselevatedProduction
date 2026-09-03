<?php
/**
 * Rich email signatures — the one sanitiser, and the one renderer.
 *
 * Roadmap 2.5 / R13 second half. Staff could previously only type a plain-text
 * signature into a textarea. This file makes it rich without making it a hole.
 *
 * ⚠️ THE PLAIN-TEXT PATH WAS ALREADY AN INJECTION. Until 2026-09-02
 * services/EmailSendService.php did `nl2br($senderInfo['email_signature'])` with
 * no escaping at all, so whatever a staff member typed into that textarea was
 * emitted as raw HTML into every email they sent to families. `<a href>`,
 * `<style>`, `<img src=x>` — all of it shipped verbatim. Nothing in the send path
 * looked at it: the unresolved-`{{tag}}` guard checks the BODY, not the
 * signature, and EmailBranding::wrap() appends around it. That is fixed here, in
 * te_render_signature_html(), which escapes the text path. Do not "simplify" the
 * escape away because "it's only staff input" — the audience is every family the
 * club mails, and a signature is stored once and re-sent forever.
 *
 * Two entry points and nothing else:
 *
 *   te_sanitize_signature_html()  — WRITE side. api/user-profile.php runs every
 *                                   submitted rich signature through it before
 *                                   the value reaches the database, so the column
 *                                   only ever holds sanitised markup.
 *   te_render_signature_html()    — SEND side. The single place a stored
 *                                   signature becomes HTML in an outbound email.
 *
 * The choke point is deliberately the WRITE, not the send: sanitising once per
 * save is cheaper than once per recipient, and it means the stored value is the
 * reviewed value — an admin looking at the column sees what actually ships.
 * The consequence is that any NEW writer of users.email_signature must call the
 * sanitiser too; SignatureHtmlTest parses api/user-profile.php and fails if that
 * endpoint stops doing it.
 */

/** Hard ceiling on submitted markup, in characters, before parsing. */
const TE_SIGNATURE_HTML_MAX_INPUT = 20000;

/** How deep the element tree may nest before the rest is unwrapped to text. */
const TE_SIGNATURE_HTML_MAX_DEPTH = 20;

/**
 * Tags that survive, mapped to the attributes they may carry.
 *
 * Deliberately small. A signature is a name, a role, a club, a phone number and
 * maybe a link — it is not a document. Every tag here is one an email client
 * renders identically without a stylesheet, which is the property that matters:
 * Outlook, Gmail and Apple Mail each strip or rewrite CSS differently, so
 * anything whose appearance depends on a <style> block is a signature that looks
 * broken in half the inboxes it reaches.
 *
 * NOT here, and why:
 *   span, font  — the two tags whose entire purpose is carrying presentation.
 *                 Allowing them means allowing an arbitrary style bag on
 *                 arbitrary text, which is most of what a style sanitiser then
 *                 has to defend. They are UNWRAPPED (see below), so pasting a
 *                 Gmail signature keeps every word and loses the colour, rather
 *                 than losing the words. Colour is still reachable — the bounded
 *                 `style` attribute is allowed on the tags that ARE here.
 *   img         — see te_sig_dropped_with_content(). Dropped, not unwrapped.
 *   div         — REWRITTEN to <p> rather than dropped; see te_sig_rewrite_tag().
 */
function te_sig_allowed_tags(): array
{
    return [
        'p'      => ['style'],
        'br'     => [],
        'b'      => ['style'],
        'strong' => ['style'],
        'i'      => ['style'],
        'em'     => ['style'],
        'u'      => ['style'],
        'a'      => ['href', 'style', 'target', 'rel'],
    ];
}

/**
 * Elements removed together with everything inside them.
 *
 * The distinction from "unwrap" is the whole point. Unwrapping keeps a node's
 * children, which is right for a <span> — the text inside it is the signature.
 * It is wrong for a <script> or a <style>, whose children are CODE: unwrapping
 * those would strip the tag and leave the JavaScript sitting in the email as
 * visible text, which is not a security hole but is a garbled signature, and for
 * <style> it is worse — a CSS block unwrapped into a <p> renders as gibberish to
 * the family reading it.
 *
 * img is on this list rather than kept. Keeping it would mean every outbound
 * email carrying an arbitrary staff-supplied remote fetch: a per-recipient
 * beacon the club did not choose, on a host nobody vetted, with no size bound
 * and no fallback when it 404s. The club's actual logo already ships — branding
 * puts it in the header via lib/EmailBranding.php out of api/club-logo.php
 * (migration 049), on our own origin and with a text degrade. If a signature
 * logo is ever wanted, the way to add it is to bound `src` to that same endpoint
 * — an id we serve, not a URL a user types — not to open <img> generally.
 */
function te_sig_dropped_with_content(): array
{
    return [
        'script', 'style', 'iframe', 'object', 'embed', 'applet', 'frame',
        'frameset', 'form', 'input', 'select', 'option', 'textarea', 'button',
        'link', 'meta', 'base', 'title', 'head', 'svg', 'math', 'template',
        'noscript', 'img', 'picture', 'source', 'video', 'audio', 'canvas',
        'map', 'area', 'track', 'portal', 'dialog',
    ];
}

/**
 * Tags rewritten to an allowed equivalent instead of unwrapped.
 *
 * Only <div>. Every rich-text surface that is not tiptap — Gmail's composer,
 * Word, Apple Mail — emits one <div> per line. Unwrapping those would run the
 * whole signature together on one line, so a paste would silently lose its line
 * structure. Mapping to <p> keeps it.
 */
function te_sig_rewrite_tag(string $tag): string
{
    return $tag === 'div' ? 'p' : $tag;
}

/**
 * Sanitise submitted signature markup down to the allowlist.
 *
 * Allowlist, not blocklist. A blocklist has to enumerate every dangerous tag,
 * attribute and URL scheme that exists now and every one added later; an
 * allowlist only has to enumerate the eight tags a signature needs. Anything
 * unrecognised is removed by default, including tags invented after this file
 * was written.
 *
 * Returns '' for input that contains no renderable text, so a caller can treat
 * "empty" as one answer rather than having to recognise `<p></p>`.
 */
function te_sanitize_signature_html(string $html): string
{
    if (trim($html) === '') {
        return '';
    }

    // Bound the work before doing any of it. Truncating markup mid-tag is safe
    // precisely because the parser below repairs unclosed elements — the cut
    // costs the tail of the signature, never a broken document.
    if (mb_strlen($html, 'UTF-8') > TE_SIGNATURE_HTML_MAX_INPUT) {
        $html = mb_substr($html, 0, TE_SIGNATURE_HTML_MAX_INPUT, 'UTF-8');
    }

    // DOMDocument::loadHTML assumes ISO-8859-1 and there is no flag to tell it
    // otherwise, so every accented name (José, Muñoz) and every curly quote
    // arrives mangled. Converting non-ASCII to numeric entities first sidesteps
    // the guess entirely — the parser only ever sees ASCII — and the decode at
    // the end puts the characters back. The same class of bug as the PHPMailer
    // CharSet line in CalendarInviteService.
    $ascii = mb_encode_numericentity(
        $html,
        [0x80, 0x10FFFF, 0, 0x1FFFFF],
        'UTF-8'
    );

    $doc = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    $loaded = $doc->loadHTML(
        '<div>' . $ascii . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
    );
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if (!$loaded || !$doc->documentElement) {
        // Unparseable input yields nothing, never the original string. Falling
        // back to the raw value on a parse failure would make a malformed
        // payload the way past the sanitiser.
        return '';
    }

    $root = $doc->documentElement;
    te_sig_clean_children($root, $doc, 0);

    $out = '';
    foreach ($root->childNodes as $child) {
        $out .= $doc->saveHTML($child);
    }

    $out = mb_decode_numericentity($out, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');
    $out = trim($out);

    // A signature whose every tag was stripped is empty, whatever markup is left
    // wrapping the nothing. <br> counts as renderable only alongside text.
    if (html_entity_decode(trim(strip_tags($out)), ENT_QUOTES, 'UTF-8') === ''
        && stripos($out, '<img') === false) {
        return '';
    }

    return $out;
}

/**
 * Walk one element's children, in place.
 *
 * Iterates over a SNAPSHOT of childNodes. DOMNodeList is live, so removing or
 * replacing a node while iterating it directly skips the next sibling — which in
 * a sanitiser means a dangerous element survives simply because the one before
 * it was removed.
 */
function te_sig_clean_children(DOMNode $parent, DOMDocument $doc, int $depth): void
{
    $children = iterator_to_array($parent->childNodes);

    foreach ($children as $node) {
        if ($node instanceof DOMText) {
            continue; // Text is the payload; saveHTML escapes it on the way out.
        }

        if ($node instanceof DOMComment || $node instanceof DOMProcessingInstruction) {
            // Comments are removed rather than kept: conditional comments are an
            // Outlook-only execution surface, and a comment cannot be part of a
            // signature anyone meant to write.
            $parent->removeChild($node);
            continue;
        }

        if (!($node instanceof DOMElement)) {
            $parent->removeChild($node);
            continue;
        }

        $tag = strtolower($node->nodeName);

        if (in_array($tag, te_sig_dropped_with_content(), true)) {
            $parent->removeChild($node);
            continue;
        }

        if ($depth >= TE_SIGNATURE_HTML_MAX_DEPTH) {
            // Past the depth bound everything unwraps to its text. A deeply
            // nested paste is a mangled signature either way; the bound is there
            // so a hostile payload cannot make the walk the expensive part.
            te_sig_unwrap($node, $parent);
            continue;
        }

        $tag = te_sig_rewrite_tag($tag);
        $allowed = te_sig_allowed_tags();

        if (!isset($allowed[$tag])) {
            te_sig_clean_children($node, $doc, $depth + 1);
            te_sig_unwrap($node, $parent);
            continue;
        }

        te_sig_clean_children($node, $doc, $depth + 1);

        // Rewritten tags are rebuilt rather than renamed — DOMElement has no
        // rename, and copying the children across is the only way.
        if ($tag !== strtolower($node->nodeName)) {
            $replacement = $doc->createElement($tag);
            foreach (iterator_to_array($node->childNodes) as $moved) {
                $replacement->appendChild($moved);
            }
            $parent->replaceChild($replacement, $node);
            $node = $replacement;
        }

        te_sig_clean_attributes($node, $tag, $allowed[$tag]);

        // An anchor with no usable href is a link to nowhere. Keeping it would
        // leave underlined blue text that does nothing; unwrapping keeps the
        // words and drops the lie.
        if ($tag === 'a' && !$node->hasAttribute('href')) {
            te_sig_unwrap($node, $parent);
        }
    }
}

/** Replace an element with its children, in order, at its own position. */
function te_sig_unwrap(DOMElement $node, DOMNode $parent): void
{
    foreach (iterator_to_array($node->childNodes) as $child) {
        $parent->insertBefore($child, $node);
    }
    $parent->removeChild($node);
}

/**
 * Strip every attribute the tag may not carry, and bound the ones it may.
 *
 * The default is removal. `on*` handlers are not enumerated and do not need to
 * be — `onclick`, `onerror`, `onmouseover` and every handler added to HTML in
 * future are simply not in any tag's allowlist, so they go the same way as
 * `id`, `class`, `data-*` and `srcset`.
 */
function te_sig_clean_attributes(DOMElement $node, string $tag, array $allowedAttrs): void
{
    foreach (iterator_to_array($node->attributes) as $attr) {
        $name = strtolower($attr->nodeName);
        $value = $attr->nodeValue ?? '';

        if (!in_array($name, $allowedAttrs, true)) {
            $node->removeAttribute($attr->nodeName);
            continue;
        }

        if ($name === 'style') {
            $clean = te_sig_safe_style($value);
            if ($clean === null) {
                $node->removeAttribute($attr->nodeName);
            } else {
                $node->setAttribute('style', $clean);
            }
            continue;
        }

        if ($name === 'href') {
            $clean = te_sig_safe_href($value);
            if ($clean === null) {
                $node->removeAttribute($attr->nodeName);
            } else {
                $node->setAttribute('href', $clean);
            }
            continue;
        }

        if ($name === 'target') {
            // _blank is the only target worth having in an email and the only
            // one whose behaviour is predictable. A named target addresses a
            // frame that does not exist in any mail client.
            if (strtolower(trim($value)) !== '_blank') {
                $node->removeAttribute($attr->nodeName);
            }
            continue;
        }

        if ($name === 'rel') {
            // Never trust a submitted rel — it is set below, not preserved.
            $node->removeAttribute($attr->nodeName);
        }
    }

    // rel is written, not kept, so a link cannot arrive carrying
    // rel="opener" and reintroduce the thing the attribute exists to prevent.
    if ($tag === 'a' && $node->hasAttribute('href')) {
        $node->setAttribute('rel', 'noopener noreferrer');
    }
}

/**
 * Is this href safe to emit? Returns the URL, or null to drop it.
 *
 * Three schemes, spelled out, and nothing else — including no relative URLs. A
 * signature has no page context to be relative to: it is read inside an email
 * client, where `/teams` resolves against whatever the client thinks the base is
 * and `//evil.com` is a protocol-relative absolute URL to somebody else's host.
 *
 * The scheme is tested against a copy with every space and control character
 * removed, because `java\tscript:`, `java\nscript:` and ` javascript:` are all
 * things browsers and mail clients have historically executed. The value
 * RETURNED is the trimmed original, so a mailto with a spaced subject survives.
 */
function te_sig_safe_href(string $raw): ?string
{
    $trimmed = trim($raw);
    if ($trimmed === '' || mb_strlen($trimmed, 'UTF-8') > 2000) {
        return null;
    }

    // Control characters are stripped from the returned value too — they cannot
    // appear in a legitimate URL and their only use is splitting a scheme.
    $trimmed = preg_replace('/[\x00-\x1F\x7F]/u', '', $trimmed);
    if ($trimmed === null || $trimmed === '') {
        return null;
    }

    $probe = strtolower(preg_replace('/[\x00-\x20]/', '', $trimmed));

    if (!preg_match('~^(https://|http://|mailto:)~', $probe)) {
        return null;
    }

    return $trimmed;
}

/**
 * Bound a style attribute to the two declarations a signature legitimately needs.
 *
 * Returns the rebuilt declaration string, or null when nothing survives.
 *
 * `color` and `font-weight` only. Not a judgement about which properties are
 * "dangerous" — it is that an allowlist of two is auditable and an allowlist of
 * forty is not. Everything else goes, `position`, `display`, `background` and
 * `content` included: none of them belongs in a signature, and each is a way to
 * make text overlay or hide the club's own message above it.
 *
 * Values are matched against a shape, never merely scanned for bad substrings.
 * `url(`, `expression(`, escapes and comments are excluded because nothing
 * matching `#a1b2c3`, `rgb(0,0,0)`, a bare colour keyword or a weight can
 * contain them — which is a stronger guarantee than a list of things to look for.
 */
function te_sig_safe_style(string $raw): ?string
{
    $kept = [];
    $declarations = explode(';', $raw);

    // Four is more than a signature needs and stops a payload from making the
    // parse itself the attack.
    if (count($declarations) > 12) {
        $declarations = array_slice($declarations, 0, 12);
    }

    foreach ($declarations as $declaration) {
        if (strpos($declaration, ':') === false) {
            continue;
        }

        [$property, $value] = explode(':', $declaration, 2);
        $property = strtolower(trim($property));
        $value = trim($value);

        if ($value === '' || strlen($value) > 64) {
            continue;
        }

        if ($property === 'color') {
            $isColor = preg_match('/^#[0-9a-f]{3}$/i', $value)
                || preg_match('/^#[0-9a-f]{6}$/i', $value)
                || preg_match('/^rgb\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*\)$/i', $value)
                || preg_match('/^[a-z]{3,20}$/i', $value);
            if ($isColor) {
                $kept['color'] = strtolower($value);
            }
            continue;
        }

        if ($property === 'font-weight') {
            if (preg_match('/^(normal|bold|bolder|lighter|[1-9]00)$/i', $value)) {
                $kept['font-weight'] = strtolower($value);
            }
        }
    }

    if (!$kept) {
        return null;
    }

    $out = [];
    foreach ($kept as $property => $value) {
        $out[] = $property . ':' . $value;
    }

    return implode(';', $out);
}

/**
 * Turn a stored signature into the HTML block appended to an outbound email.
 *
 * The ONE place that decision is made. Both formats end in the same wrapper so
 * the markup around a signature does not depend on which editor produced it.
 *
 *   'html' — emitted as-is. It was sanitised on the way IN, by
 *            te_sanitize_signature_html() in api/user-profile.php, which is the
 *            gate. Re-sanitising here would be a second DOM parse per recipient
 *            on a value that cannot have changed since it was written.
 *   anything else, including NULL and a missing column — treated as text and
 *            ESCAPED. This is the fix to the pre-2026-09-02 injection described
 *            at the top of this file, and the default deliberately falls this
 *            way: an unrecognised format value escapes rather than trusts.
 */
function te_render_signature_html(?string $signature, ?string $format = null): string
{
    $signature = (string) $signature;
    if (trim($signature) === '') {
        return '';
    }

    if (strtolower(trim((string) $format)) === 'html') {
        $body = $signature;
    } else {
        $body = nl2br(htmlspecialchars($signature, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
    }

    return '<div class="email-signature" style="margin-top:16px">' . $body . '</div>';
}

/**
 * Convert a plain-text signature into equivalent rich markup.
 *
 * Used when a staff member who has only ever had a text signature opens the rich
 * editor for the first time: their existing lines have to arrive intact, and
 * they have to arrive ESCAPED — the stored text is exactly the untrusted value
 * the render path escapes, so handing it to an HTML editor raw would launder it
 * through the sanitiser as markup the user never typed.
 */
function te_signature_text_to_html(string $text): string
{
    if (trim($text) === '') {
        return '';
    }

    $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $lines = preg_split('/\r\n|\r|\n/', $escaped) ?: [];

    $out = '';
    foreach ($lines as $line) {
        $out .= '<p>' . ($line === '' ? '<br>' : $line) . '</p>';
    }

    return $out;
}

/**
 * Is `users.email_signature_format` live?
 *
 * One information_schema query per request, memoised. A failed probe answers
 * false, which is the safe degrade: without the column every signature is read
 * as text and therefore ESCAPED. A rich signature saved before the migration
 * runs would show its own tags rather than render them — visibly wrong, never
 * an injection.
 *
 * `main` is shared and deploys are by push, so this code reaches production
 * days before the SQL is applied by hand. On Postgres naming a missing column is
 * 42703, which would take the whole email send path down for every club.
 */
function te_signature_format_column_present(PDO $pdo): bool
{
    $override = te_signature_format_probe_override();
    if ($override !== null) {
        return $override;
    }

    static $present = null;
    if ($present !== null) {
        return $present;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM information_schema.columns
              WHERE table_name = 'users' AND column_name = 'email_signature_format'"
        );
        $stmt->execute();
        $present = (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        // SQLite (the test fixtures) has no information_schema at all, and a
        // Postgres failure here is a database problem, not a schema answer.
        error_log('te_signature_format_column_present: ' . $e->getMessage());
        $present = false;
    }

    return $present;
}

/**
 * Test seam: force the answer to the column probe, or pass null to clear.
 *
 * Explicit rather than reaching into the static, so a test exercising the
 * column-absent path says so in one line and no production caller can reach it
 * by accident. Same shape as te_field_size_probe_override().
 */
function te_signature_format_probe_override(?bool $value = null): ?bool
{
    static $override = null;
    if (func_num_args() > 0) {
        $override = $value;
    }
    return $override;
}
