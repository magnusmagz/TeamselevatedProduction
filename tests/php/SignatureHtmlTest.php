<?php

use PHPUnit\Framework\TestCase;

/**
 * Rich email signatures (roadmap 2.5, R13 second half).
 *
 * Three things are pinned here, and they fail in different ways:
 *
 *  1. The sanitiser's allowlist — what survives a save, and what does not.
 *  2. The APPEND path escapes a plain-text signature. This is a regression test
 *     for a live injection: before 2026-09-02 EmailSendService did a bare
 *     nl2br() on the stored signature, so a staff member's textarea content
 *     shipped as raw HTML to every family they mailed.
 *  3. A PARSE of api/user-profile.php asserting the sanitiser is actually
 *     called there. The sanitiser being correct proves nothing if the endpoint
 *     stops calling it — the recurring shape in this codebase is a predicate
 *     that was never wrong and a call site that used the other one.
 */
class SignatureHtmlTest extends TestCase
{
    protected function tearDown(): void
    {
        te_signature_format_probe_override(null);
    }

    // ---------------------------------------------------------------- allowed

    public function testTheAllowedTagsSurvive(): void
    {
        $in = '<p>Coach <strong>Smith</strong> <b>b</b> <i>i</i> <em>em</em> <u>u</u></p>'
            . '<p>Riverside SC<br>(555) 123-4567</p>';

        $this->assertSame($in, te_sanitize_signature_html($in));
    }

    public function testAnEmptySignatureIsEmptyRatherThanEmptyMarkup(): void
    {
        // A caller must be able to treat "nothing" as one answer. Returning
        // '<p></p>' would make !empty() true for a signature nobody wrote, and
        // every outbound email would carry an empty signature block.
        $this->assertSame('', te_sanitize_signature_html(''));
        $this->assertSame('', te_sanitize_signature_html('   '));
        $this->assertSame('', te_sanitize_signature_html('<p></p>'));
        $this->assertSame('', te_sanitize_signature_html('<p><b><i></i></b></p>'));
    }

    public function testNonAsciiSurvivesTheParse(): void
    {
        // DOMDocument::loadHTML assumes ISO-8859-1 with no flag to say otherwise,
        // so without the numeric-entity round trip every accented name in the
        // club mangles. Same class as the PHPMailer CharSet line.
        $out = te_sanitize_signature_html('<p>José Muñoz — “Coach”</p>');
        $this->assertStringContainsString('José Muñoz', $out);
        $this->assertStringContainsString('—', $out);
        $this->assertStringContainsString('“Coach”', $out);
    }

    // ---------------------------------------------------------------- stripped

    public function testScriptIsRemovedWithItsContents(): void
    {
        $out = te_sanitize_signature_html('<p>Coach</p><script>alert(1)</script>');

        $this->assertSame('<p>Coach</p>', $out);
        // Unwrapping a <script> would leave the JavaScript behind as visible
        // text. It has to go with its contents, not instead of them.
        $this->assertStringNotContainsString('alert', $out);
    }

    public function testStyleBlocksAreRemovedWithTheirContents(): void
    {
        $out = te_sanitize_signature_html('<style>p{display:none}</style><p>Coach</p>');

        $this->assertSame('<p>Coach</p>', $out);
        $this->assertStringNotContainsString('display', $out);
    }

    public function testEventHandlersAreRemoved(): void
    {
        foreach (['onclick', 'onerror', 'onmouseover', 'onfocus', 'ONLOAD'] as $handler) {
            $out = te_sanitize_signature_html('<p ' . $handler . '="steal()">Coach</p>');

            $this->assertSame('<p>Coach</p>', $out, "$handler survived");
        }
    }

    public function testIframesAreRemoved(): void
    {
        $out = te_sanitize_signature_html('<p>Coach</p><iframe src="https://evil.example"></iframe>');

        $this->assertSame('<p>Coach</p>', $out);
        $this->assertStringNotContainsString('iframe', $out);
    }

    public function testImagesAreDroppedByDefault(): void
    {
        // Not a judgement that <img> is dangerous markup — it is that a staff-
        // typed <img> puts an unvetted per-recipient remote fetch into every
        // email the club sends. The club's real logo ships through
        // lib/EmailBranding.php from api/club-logo.php, on our own origin.
        $out = te_sanitize_signature_html('<p>Coach</p><img src="https://tracker.example/x.gif">');

        $this->assertSame('<p>Coach</p>', $out);
        $this->assertStringNotContainsString('<img', $out);
        $this->assertStringNotContainsString('tracker.example', $out);
    }

    public function testFormsAndInputsAreRemoved(): void
    {
        $out = te_sanitize_signature_html(
            '<form action="https://evil.example"><input name="password"><button>Go</button></form><p>Coach</p>'
        );

        $this->assertSame('<p>Coach</p>', $out);
    }

    public function testCommentsAreRemoved(): void
    {
        // Conditional comments are an Outlook-only execution surface, and no
        // signature anyone meant to write contains one.
        $out = te_sanitize_signature_html('<!--[if mso]><script>x()</script><![endif]--><p>Coach</p>');

        $this->assertSame('<p>Coach</p>', $out);
    }

    // -------------------------------------------------------------------- href

    public function testJavascriptHrefsAreRefused(): void
    {
        $hostile = [
            'javascript:alert(1)',
            'JaVaScRiPt:alert(1)',
            "java\nscript:alert(1)",
            "java\tscript:alert(1)",
            ' javascript:alert(1)',
            'data:text/html;base64,PHNjcmlwdD4=',
            'vbscript:msgbox(1)',
            'file:///etc/passwd',
        ];

        foreach ($hostile as $href) {
            $out = te_sanitize_signature_html('<a href="' . $href . '">Click</a>');

            // The anchor unwraps rather than surviving with no href: an <a> that
            // links nowhere is underlined text that lies about being a link.
            $this->assertSame('Click', $out, "refused href leaked: $href");
            $this->assertStringNotContainsString('href', $out);
        }
    }

    public function testANulByteInAnHrefTakesTheWholeSignatureRatherThanTheLink(): void
    {
        // libxml stops at a NUL, so the document truncates and nothing survives.
        // That is an acceptable answer — the signature is lost, not the escape —
        // and it is asserted rather than assumed, because "the text disappeared"
        // is the kind of behaviour someone would otherwise report as a bug and
        // then "fix" by feeding the raw string past the parser.
        $out = te_sanitize_signature_html("<a href=\"\x00javascript:alert(1)\">Click</a>");

        $this->assertStringNotContainsString('javascript', $out);
        $this->assertStringNotContainsString('href', $out);
    }

    public function testProtocolRelativeUrlsAreRefused(): void
    {
        // //evil.example is an absolute URL to somebody else's host wearing a
        // relative URL's clothes.
        $this->assertSame('Site', te_sanitize_signature_html('<a href="//evil.example">Site</a>'));
    }

    public function testRelativeUrlsAreRefused(): void
    {
        // There is no page for a signature to be relative TO — it is read inside
        // a mail client, which resolves /teams against whatever it likes.
        $this->assertSame('Teams', te_sanitize_signature_html('<a href="/teams">Teams</a>'));
    }

    public function testHttpHttpsAndMailtoSurviveAndCarryRelNoopener(): void
    {
        foreach (['https://club.example/teams', 'http://club.example', 'mailto:coach@club.example'] as $href) {
            $out = te_sanitize_signature_html('<a href="' . $href . '">Link</a>');

            $this->assertStringContainsString('href="' . $href . '"', $out);
            $this->assertStringContainsString('rel="noopener noreferrer"', $out);
        }
    }

    public function testASubmittedRelIsReplacedNotPreserved(): void
    {
        // rel is written, never kept — otherwise rel="opener" reintroduces the
        // exact thing the attribute exists to prevent.
        $out = te_sanitize_signature_html('<a href="https://club.example" rel="opener">Link</a>');

        $this->assertStringContainsString('rel="noopener noreferrer"', $out);
        $this->assertStringNotContainsString('rel="opener"', $out);
    }

    public function testOnlyBlankTargetSurvives(): void
    {
        $blank = te_sanitize_signature_html('<a href="https://club.example" target="_blank">L</a>');
        $this->assertStringContainsString('target="_blank"', $blank);

        $named = te_sanitize_signature_html('<a href="https://club.example" target="evilframe">L</a>');
        $this->assertStringNotContainsString('target', $named);
    }

    // ------------------------------------------------------------------- style

    public function testStyleKeepsOnlyColourAndFontWeight(): void
    {
        $out = te_sanitize_signature_html(
            '<p style="color:#c00;font-weight:bold;position:fixed;display:none;background:red">Coach</p>'
        );

        $this->assertStringContainsString('color:#c00', $out);
        $this->assertStringContainsString('font-weight:bold', $out);
        $this->assertStringNotContainsString('position', $out);
        $this->assertStringNotContainsString('display', $out);
        $this->assertStringNotContainsString('background', $out);
    }

    public function testStyleValuesAreMatchedAgainstAShapeNotScannedForBadWords(): void
    {
        $hostile = [
            'color:url(javascript:alert(1))',
            'color:expression(alert(1))',
            'color:red;/*comment*/',
            'color:\\75rl(x)',
            'color:"quoted"',
            'font-weight:900px',
            'font-weight:url(x)',
        ];

        foreach ($hostile as $style) {
            $out = te_sanitize_signature_html('<p style="' . $style . '">Coach</p>');

            $this->assertStringNotContainsString('url(', $out, "leaked: $style");
            $this->assertStringNotContainsString('expression', $out, "leaked: $style");
            $this->assertStringNotContainsString('/*', $out, "leaked: $style");
        }
    }

    public function testValidColourNotationsSurvive(): void
    {
        foreach (['#c00', '#cc0000', 'rgb(12, 34, 56)', 'darkgreen'] as $colour) {
            $out = te_sanitize_signature_html('<p style="color:' . $colour . '">Coach</p>');

            $this->assertStringContainsString('color:' . strtolower($colour), $out, "dropped: $colour");
        }
    }

    // ------------------------------------------------------------ unwrap rules

    public function testSpanAndFontAreUnwrappedRatherThanDropped(): void
    {
        // The words are the signature. Dropping a <span> with its contents would
        // silently delete the text of any signature pasted out of Gmail.
        $this->assertSame(
            'Sales team',
            te_sanitize_signature_html('<span style="color:red">Sales</span> team')
        );
        $this->assertSame(
            'Big',
            te_sanitize_signature_html('<font color="red">Big</font>')
        );
    }

    public function testDivsBecomeParagraphsSoPastedLineStructureSurvives(): void
    {
        // Gmail, Word and Apple Mail emit one <div> per line. Unwrapping would
        // run the whole signature onto one line.
        $this->assertSame(
            '<p>Coach Smith</p><p>Riverside SC</p>',
            te_sanitize_signature_html('<div>Coach Smith</div><div>Riverside SC</div>')
        );
    }

    public function testUnknownTagsUnwrapAndTheirHostileAttributesGoWithThem(): void
    {
        $out = te_sanitize_signature_html('<article onclick="x()"><marquee>Coach</marquee></article>');

        $this->assertSame('Coach', $out);
        $this->assertStringNotContainsString('onclick', $out);
    }

    // ------------------------------------------------------------------ bounds

    public function testInputIsLengthBounded(): void
    {
        $huge = '<p>' . str_repeat('a', TE_SIGNATURE_HTML_MAX_INPUT * 2) . '</p>';
        $out = te_sanitize_signature_html($huge);

        $this->assertLessThanOrEqual(TE_SIGNATURE_HTML_MAX_INPUT + 64, strlen($out));
    }

    public function testDeepNestingIsBoundedAndKeepsTheText(): void
    {
        $depth = TE_SIGNATURE_HTML_MAX_DEPTH + 40;
        $in = str_repeat('<b>', $depth) . 'Coach' . str_repeat('</b>', $depth);

        $out = te_sanitize_signature_html($in);

        $this->assertStringContainsString('Coach', $out);
        $this->assertLessThan($depth, substr_count($out, '<b>'));
    }

    // ------------------------------------------------------------ append path

    public function testAPlainTextSignatureIsEscapedOnTheWayIntoAnEmail(): void
    {
        // THE REGRESSION TEST. Before 2026-09-02 this shipped verbatim.
        $out = te_render_signature_html("Coach <script>alert(1)</script>\nRiverside", 'text');

        $this->assertStringNotContainsString('<script>', $out);
        $this->assertStringContainsString('&lt;script&gt;', $out);
        // Line breaks still become <br> — that is what nl2br was there for and
        // escaping must not cost it.
        $this->assertStringContainsString('<br>', $out);
    }

    public function testAnUnknownOrMissingFormatEscapes(): void
    {
        // The default falls toward escaping, never toward trusting. NULL is what
        // a row reads as before migration 092 is applied.
        foreach ([null, '', 'text', 'TEXT', 'markdown', 'rich'] as $format) {
            $out = te_render_signature_html('<b>bold</b>', $format);

            $this->assertStringContainsString('&lt;b&gt;', $out, 'format: ' . var_export($format, true));
        }
    }

    public function testASanitisedHtmlSignatureIsEmittedAsIs(): void
    {
        $out = te_render_signature_html('<p>Coach <strong>Smith</strong></p>', 'html');

        $this->assertStringContainsString('<p>Coach <strong>Smith</strong></p>', $out);
        $this->assertStringNotContainsString('&lt;p&gt;', $out);
    }

    public function testAnEmptySignatureAppendsNothingAtAll(): void
    {
        // Not an empty wrapper div. An outbound email must not carry a signature
        // block for a staff member who has not written one.
        $this->assertSame('', te_render_signature_html(null, 'html'));
        $this->assertSame('', te_render_signature_html('', 'text'));
        $this->assertSame('', te_render_signature_html('   ', 'text'));
    }

    public function testBothFormatsShareOneWrapper(): void
    {
        $text = te_render_signature_html('Coach', 'text');
        $html = te_render_signature_html('<p>Coach</p>', 'html');

        foreach ([$text, $html] as $out) {
            $this->assertStringStartsWith('<div class="email-signature" style="margin-top:16px">', $out);
            $this->assertStringEndsWith('</div>', $out);
        }
    }

    public function testTextToHtmlEscapesBeforeItBecomesMarkup(): void
    {
        // Opening the rich editor on an existing text signature must not launder
        // the stored text into markup the staff member never typed.
        $out = te_signature_text_to_html("Coach <b>Smith</b>\nRiverside");

        $this->assertStringContainsString('&lt;b&gt;', $out);
        $this->assertStringNotContainsString('<b>', $out);
        $this->assertSame(2, substr_count($out, '<p>'));
    }

    // ------------------------------------------------------- column tolerance

    public function testTheColumnProbeCanBeForcedBothWays(): void
    {
        te_signature_format_probe_override(false);
        $this->assertFalse(te_signature_format_column_present($this->sqlite()));

        te_signature_format_probe_override(true);
        $this->assertTrue(te_signature_format_column_present($this->sqlite()));
    }

    public function testTheProbeAnswersFalseWhereThereIsNoInformationSchema(): void
    {
        // SQLite has none, and neither does a database that is refusing queries.
        // False is the safe degrade: every signature reads as text and is
        // therefore escaped.
        te_signature_format_probe_override(null);
        $this->assertFalse(te_signature_format_column_present($this->sqlite()));
    }

    // ------------------------------------------------------------------ parses

    public function testUserProfilePutSanitisesBeforeItWrites(): void
    {
        $src = file_get_contents(__DIR__ . '/../../api/user-profile.php');

        $this->assertStringContainsString(
            'te_sanitize_signature_html',
            $src,
            'api/user-profile.php no longer sanitises the rich signature — the column is the choke point and this is it'
        );

        // The sanitised value, not the raw one, is what is bound.
        $this->assertMatchesRegularExpression(
            '/\$params\[[\'"]email_signature[\'"]\]\s*=\s*te_sanitize_signature_html\(/',
            $src,
            'the rich signature reaches the database without going through the sanitiser'
        );

        $this->assertStringNotContainsString(
            "\$params['email_signature'] = \$data['email_signature_html']",
            $src,
            'the raw submitted HTML is being stored'
        );
    }

    public function testUserProfileStampsTheFormatForBothShapes(): void
    {
        $src = file_get_contents(__DIR__ . '/../../api/user-profile.php');

        // A staff member moving back to the plain textarea must not leave the row
        // claiming to be HTML, or their next signature ships unescaped.
        $this->assertStringContainsString("'html'", $src);
        $this->assertStringContainsString("'text'", $src);
        $this->assertStringContainsString('email_signature_format = :email_signature_format', $src);
    }

    public function testUserProfileReadsTheFormatColumnTolerantly(): void
    {
        $src = file_get_contents(__DIR__ . '/../../api/user-profile.php');

        $this->assertStringContainsString('te_signature_format_column_present', $src);
        // Migration 092 is not applied. A bare mention of the column in a SELECT
        // would be 42703 on Postgres and 500 the profile page for everyone.
        $this->assertStringNotContainsString(
            'phone, email_signature, email_signature_format',
            $src,
            'the format column is named unconditionally in a SELECT'
        );
    }

    public function testTheSendPathGoesThroughTheOneRenderer(): void
    {
        $src = file_get_contents(__DIR__ . '/../../services/EmailSendService.php');

        $this->assertStringContainsString('te_render_signature_html', $src);
        $this->assertStringContainsString('te_signature_format_column_present', $src);

        // The bare nl2br is the bug. If it comes back, so does the injection.
        $this->assertDoesNotMatchRegularExpression(
            '/nl2br\(\s*\$senderInfo/',
            $src,
            'the unescaped nl2br append is back — this is the 2026-09-02 injection'
        );
    }

    /**
     * The editor's extension set and the server's allowlist are one decision
     * written in two languages. Same shape as JerseySizeConsistencyTest.
     *
     * Drift here is not a security failure — the sanitiser still holds — it is a
     * product failure, and a confusing one: the staff member formats their
     * signature, saves it, and watches the formatting vanish with nothing on
     * screen saying why.
     */
    public function testTheEditorCannotProduceMarkupTheSanitiserWouldStrip(): void
    {
        $editor = file_get_contents(__DIR__ . '/../../frontend/src/components/SignatureEditor.tsx');

        // Every StarterKit extension whose output has no home in
        // te_sig_allowed_tags() must be switched off in the editor.
        $mustBeDisabled = [
            'heading', 'codeBlock', 'code', 'blockquote',
            'bulletList', 'orderedList', 'listItem', 'horizontalRule', 'strike',
        ];

        foreach ($mustBeDisabled as $extension) {
            $this->assertMatchesRegularExpression(
                '/\b' . preg_quote($extension, '/') . '\s*:\s*false/',
                $editor,
                "SignatureEditor enables `$extension`, whose markup te_sanitize_signature_html() strips"
            );
        }
    }

    public function testTheEditorAcceptsExactlyTheSchemesTheSanitiserDoes(): void
    {
        $editor = file_get_contents(__DIR__ . '/../../frontend/src/components/SignatureEditor.tsx');

        // te_sig_safe_href() accepts http, https and mailto. A URL the editor
        // accepts and the sanitiser strips is a link the staff member believes
        // they added; one the editor refuses and the sanitiser would have kept
        // is a link they cannot add for no visible reason.
        $this->assertStringContainsString(
            "protocols: ['http', 'https', 'mailto']",
            $editor,
            'the editor and te_sig_safe_href() disagree about which URL schemes a signature may link to'
        );

        $this->assertStringContainsString(
            "rel: 'noopener noreferrer'",
            $editor
        );
    }

    private function sqlite(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }
}
