import {
  signatureTextToHtml,
  signatureHtmlToText,
  isSignatureHtmlEmpty,
} from './signatureHtml';

/**
 * The two conversions between a signature's shapes, and the emptiness question.
 *
 * None of this is a sanitiser and none of it is asserted to be one — the server
 * sanitises, in te_sanitize_signature_html(). What is pinned here is that
 * crossing between the plain textarea and the rich editor cannot change what a
 * staff member's signature SAYS.
 */
describe('signatureTextToHtml', () => {
  it('turns each line into a paragraph', () => {
    expect(signatureTextToHtml('Coach Smith\nRiverside SC')).toBe(
      '<p>Coach Smith</p><p>Riverside SC</p>'
    );
  });

  it('keeps a blank line as a blank line', () => {
    expect(signatureTextToHtml('A\n\nB')).toBe('<p>A</p><p><br></p><p>B</p>');
  });

  it('handles CRLF, which is what a Windows paste actually contains', () => {
    expect(signatureTextToHtml('A\r\nB')).toBe('<p>A</p><p>B</p>');
  });

  it('ESCAPES the text rather than promoting it to markup', () => {
    // The stored text is exactly the untrusted value the send path escapes. A
    // staff member who literally typed "<b>" must not have it become bold
    // simply because they opened the rich editor — by the time the sanitiser
    // saw it, it would be indistinguishable from markup they meant.
    expect(signatureTextToHtml('Coach <b>Smith</b>')).toBe(
      '<p>Coach &lt;b&gt;Smith&lt;/b&gt;</p>'
    );
    expect(signatureTextToHtml('<script>alert(1)</script>')).not.toContain('<script>');
    expect(signatureTextToHtml('Tom & Jerry')).toBe('<p>Tom &amp; Jerry</p>');
  });

  it('is empty for empty input', () => {
    expect(signatureTextToHtml('')).toBe('');
    expect(signatureTextToHtml('   \n  ')).toBe('');
  });
});

describe('signatureHtmlToText', () => {
  it('flattens paragraphs to newlines', () => {
    expect(signatureHtmlToText('<p>Coach Smith</p><p>Riverside SC</p>')).toBe(
      'Coach Smith\nRiverside SC'
    );
  });

  it('turns a hard break into a newline', () => {
    expect(signatureHtmlToText('<p>Coach<br>Riverside</p>')).toBe('Coach\nRiverside');
  });

  it('keeps the words when it drops the formatting', () => {
    expect(signatureHtmlToText('<p>Coach <strong>Smith</strong>, <em>Head</em></p>')).toBe(
      'Coach Smith, Head'
    );
  });

  it('keeps a link\'s text and loses only its href', () => {
    expect(
      signatureHtmlToText('<p>See <a href="https://club.example">our site</a></p>')
    ).toBe('See our site');
  });

  it('does not lose text to an attribute containing a bracket', () => {
    // A regex tag-stripper gets this wrong, and getting it wrong means text
    // silently disappearing out of somebody's signature.
    expect(signatureHtmlToText('<p title="a>b">Coach</p>')).toBe('Coach');
  });

  it('is empty for empty input', () => {
    expect(signatureHtmlToText('')).toBe('');
    expect(signatureHtmlToText('  ')).toBe('');
  });
});

describe('isSignatureHtmlEmpty', () => {
  it('calls an empty tiptap document empty', () => {
    // tiptap never yields ''. Without this, clearing the editor would append an
    // empty signature block to every outbound email, and `!!value` would call
    // it present.
    expect(isSignatureHtmlEmpty('<p></p>')).toBe(true);
    expect(isSignatureHtmlEmpty('<p><br></p>')).toBe(true);
    expect(isSignatureHtmlEmpty('')).toBe(true);
    expect(isSignatureHtmlEmpty('   ')).toBe(true);
  });

  it('calls a real signature present', () => {
    expect(isSignatureHtmlEmpty('<p>Coach</p>')).toBe(false);
    expect(isSignatureHtmlEmpty('<p><strong>C</strong></p>')).toBe(false);
  });
});

describe('the round trip', () => {
  it('returns the same text it started with', () => {
    const text = 'Coach Smith\nRiverside SC\n(555) 123-4567';

    expect(signatureHtmlToText(signatureTextToHtml(text))).toBe(text);
  });

  it('survives characters that have to be escaped on the way out', () => {
    const text = 'Tom & Jerry <coach>';

    expect(signatureHtmlToText(signatureTextToHtml(text))).toBe(text);
  });
});
