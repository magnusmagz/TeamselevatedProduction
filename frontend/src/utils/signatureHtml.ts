/**
 * Email signature helpers — the client half of lib/signature_html.php.
 *
 * ⚠️ NOTHING HERE IS A SANITISER, and none of it may be treated as one. The
 * server sanitises, in te_sanitize_signature_html(), and api/user-profile.php
 * runs it before the value reaches the database. What the browser does is
 * convert between the two shapes a signature can take and decide what to render
 * in the preview. A sanitiser that also existed here would be a second
 * allowlist to keep in step with the first, and the moment the two disagreed the
 * preview would stop showing what actually ships.
 *
 * That is also why the preview renders the SERVER's response rather than the
 * editor's live output: what the staff member is shown is the round trip, so a
 * tag the sanitiser removed is visibly absent instead of quietly different.
 */

/** Escape text for safe interpolation into markup. */
function escapeHtml(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

/**
 * Turn an existing plain-text signature into paragraphs for the rich editor.
 *
 * Mirrors te_signature_text_to_html(). The escape is the load-bearing part: the
 * stored text is exactly the untrusted value the send path escapes, so handing
 * it to an HTML editor raw would launder a staff member's literal "<b>" into
 * markup they never wrote — and then the sanitiser would keep it, because by
 * that point it IS markup.
 */
export function signatureTextToHtml(text: string): string {
  if (text.trim() === '') return '';

  return text
    .split(/\r\n|\r|\n/)
    .map((line) => `<p>${line === '' ? '<br>' : escapeHtml(line)}</p>`)
    .join('');
}

/**
 * Flatten rich markup back to plain text, for a staff member switching to the
 * plain textarea.
 *
 * Block ends and <br> become newlines; everything else is dropped and the text
 * kept. Formatting is genuinely lost — that is what switching to plain text
 * means, and the UI says so before it happens rather than discovering it after.
 *
 * Parsed with DOMParser, never with a regex over the string. A regex that strips
 * tags gets `<p title="a>b">` wrong, and getting it wrong here means text
 * silently disappearing out of somebody's signature.
 */
export function signatureHtmlToText(html: string): string {
  if (html.trim() === '') return '';

  const doc = new DOMParser().parseFromString(html, 'text/html');

  doc.querySelectorAll('br').forEach((br) => {
    br.replaceWith(doc.createTextNode('\n'));
  });
  doc.querySelectorAll('p, div').forEach((block) => {
    block.appendChild(doc.createTextNode('\n'));
  });

  return (doc.body.textContent || '')
    .replace(/\n{3,}/g, '\n\n')
    .trim();
}

/**
 * Does this markup contain anything a reader would see?
 *
 * tiptap never yields an empty string — an empty document serialises to
 * `<p></p>`. Without this, clearing the editor would save a signature block
 * containing nothing onto every outbound email, and `!!value` would call it
 * present. The server answers the same question the same way, by returning ''
 * from the sanitiser.
 */
export function isSignatureHtmlEmpty(html: string): boolean {
  if (html.trim() === '') return true;

  const doc = new DOMParser().parseFromString(html, 'text/html');
  return (doc.body.textContent || '').trim() === '';
}
