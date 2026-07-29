<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/EmailBranding.php';

/**
 * Club-branded email header/footer (lib/EmailBranding.php).
 *
 * Covers the markup helpers only — `forClub()` reads Postgres-specific SQL
 * (`md5()` over the cached logo blob) and is exercised against Neon, not here.
 */
class EmailBrandingTest extends TestCase
{
    private function brandWithLogo(): array
    {
        return [
            'name' => 'Central Kansas United',
            'color' => '#323c50',
            'email' => 'club@example.org',
            'website' => 'https://example.org',
            'fb' => 'https://facebook.com/cku',
            'ig' => '',
            'logo' => 'https://backend.example/api/club-logo.php?club_id=51&v=abc12345',
            'logo_w' => 68,
            'logo_h' => 52,
        ];
    }

    private function brandWithoutLogo(): array
    {
        return [
            'name' => 'Logoless FC', 'color' => '#1f2937', 'email' => '', 'website' => '',
            'fb' => '', 'ig' => '', 'logo' => '', 'logo_w' => 0, 'logo_h' => 0,
        ];
    }

    public function testHeaderCarriesTheClubLogoAndName(): void
    {
        $html = EmailBranding::headerHtml($this->brandWithLogo());

        $this->assertStringContainsString('club-logo.php?club_id=51', $html);
        $this->assertStringContainsString('Central Kansas United', $html);
        $this->assertStringContainsString(EmailBranding::HEADER_MARKER, $html);
    }

    public function testFooterCarriesTheClubLogoNameAndUnsubscribeLink(): void
    {
        $html = EmailBranding::footerHtml($this->brandWithLogo(), 'https://backend.example/unsubscribe?token=t0k3n');

        $this->assertStringContainsString('club-logo.php?club_id=51', $html);
        $this->assertStringContainsString('Central Kansas United', $html);
        $this->assertStringContainsString('https://backend.example/unsubscribe?token=t0k3n', $html);
        $this->assertStringContainsString('Unsubscribe', $html);
        // club colour band, not the hard-coded Teams Elevated green
        $this->assertStringContainsString('#323c50', $html);
    }

    public function testLogoScalesToTargetHeightKeepingAspectRatio(): void
    {
        // 68x52 asked for at 26px tall -> 34px wide
        $html = EmailBranding::logoImgHtml($this->brandWithLogo(), 26);

        $this->assertStringContainsString('width="34"', $html);
        $this->assertStringContainsString('height="26"', $html);
    }

    public function testClubWithoutACachedLogoFallsBackToTextNeverABrokenImage(): void
    {
        $header = EmailBranding::headerHtml($this->brandWithoutLogo());
        $footer = EmailBranding::footerHtml($this->brandWithoutLogo(), 'https://x/unsub');

        $this->assertStringNotContainsString('<img', $header);
        $this->assertStringContainsString('Logoless FC', $header);
        $this->assertStringNotContainsString('club-logo.php', $footer);
        $this->assertStringContainsString('Logoless FC', $footer);
    }

    public function testSocialIconsOnlyShowPlatformsTheClubHas(): void
    {
        $html = EmailBranding::socialIconsHtml($this->brandWithLogo());

        $this->assertStringContainsString('facebook.png', $html);
        $this->assertStringContainsString('globe.png', $html);
        $this->assertStringNotContainsString('instagram.png', $html); // no ig url on this club
        $this->assertSame('', EmailBranding::socialIconsHtml($this->brandWithoutLogo()));
    }

    public function testWrapInsertsInsideBodyForAFullDocument(): void
    {
        $doc = '<!DOCTYPE html><html><head></head><body style="margin:0"><p>Hi Jordan</p></body></html>';
        $out = EmailBranding::wrap($doc, $this->brandWithLogo(), 'https://x/unsub');

        $this->assertStringContainsString('</body></html>', $out);
        $this->assertLessThan(strpos($out, '<p>Hi Jordan</p>'), strpos($out, EmailBranding::HEADER_MARKER));
        $this->assertGreaterThan(strpos($out, '<p>Hi Jordan</p>'), strpos($out, EmailBranding::FOOTER_MARKER));
        // footer must land before </body>, not after the document
        $this->assertLessThan(strpos($out, '</body>'), strpos($out, EmailBranding::FOOTER_MARKER));
    }

    /**
     * Several seeded templates carry an explanatory HTML comment that itself
     * contains markup, so the document matches </body> more than once. Anchoring
     * on every match inserted the footer inside the comment too — a second footer
     * plus the comment's prose leaking into the rendered email.
     */
    public function testWrapAnchorsOnTheLastBodyCloseOnly(): void
    {
        $doc = '<html><body><!-- design note: the band sits above </body> in the export -->'
             . '<p>Hi</p></body></html>';
        $out = EmailBranding::wrap($doc, $this->brandWithLogo(), 'https://x/unsub');

        $this->assertSame(1, substr_count($out, EmailBranding::FOOTER_MARKER));
        // the comment must survive intact
        $this->assertStringContainsString('<!-- design note: the band sits above </body> in the export -->', $out);
    }

    public function testWrapHandlesABareFragment(): void
    {
        $out = EmailBranding::wrap('<p>Quick note</p>', $this->brandWithLogo(), 'https://x/unsub');

        $this->assertStringContainsString('<p>Quick note</p>', $out);
        $this->assertStringContainsString(EmailBranding::HEADER_MARKER, $out);
        $this->assertStringContainsString(EmailBranding::FOOTER_MARKER, $out);
    }

    /**
     * A retried job re-processes a body that is already branded. Wrapping twice
     * must not stack two headers/footers (or two unsubscribe links).
     */
    public function testWrapIsIdempotent(): void
    {
        $brand = $this->brandWithLogo();
        $once  = EmailBranding::wrap('<p>Hi</p>', $brand, 'https://x/unsub');
        $twice = EmailBranding::wrap($once, $brand, 'https://x/unsub');

        $this->assertSame($once, $twice);
        $this->assertSame(1, substr_count($twice, EmailBranding::HEADER_MARKER));
        $this->assertSame(1, substr_count($twice, EmailBranding::FOOTER_MARKER));
        $this->assertSame(1, substr_count($twice, '>Unsubscribe</a>'));
    }

    public function testBandStyleUsesTheClubColourLockup(): void
    {
        $html = EmailBranding::headerHtml($this->brandWithLogo(), "You're invited", EmailBranding::STYLE_BAND);

        $this->assertStringContainsString('background-color:#323c50', $html);
        $this->assertStringContainsString("You&#039;re invited", $html);
        $this->assertStringContainsString('club-logo.php?club_id=51', $html);
    }

    /**
     * The template editor renders the club's real header/footer on its canvas, so
     * an Unlayer export can carry them. Templates are shared across clubs, so a
     * save must never persist one club's logo — this is the server-side backstop
     * for the browser-side strip.
     */
    public function testStripBrandingRemovesBothBlocks(): void
    {
        $brand = $this->brandWithLogo();
        $wrapped = EmailBranding::wrap('<p>Body copy</p>', $brand, 'https://x/unsub');
        $stripped = EmailBranding::stripBranding($wrapped);

        $this->assertStringContainsString('<p>Body copy</p>', $stripped);
        $this->assertStringNotContainsString('club-logo.php', $stripped);
        $this->assertStringNotContainsString('Unsubscribe', $stripped);
        $this->assertStringNotContainsString('te-brand-', $stripped);
    }

    public function testStripBrandingAlsoClearsTheEmptiedUnlayerRowWrapper(): void
    {
        $brand = $this->brandWithLogo();
        $html = '<div class="u-row-container te_brand_injected">'
              . EmailBranding::headerHtml($brand)
              . '</div><p>Body</p>';

        $stripped = EmailBranding::stripBranding($html);

        $this->assertStringNotContainsString('te_brand_injected', $stripped);
        $this->assertStringContainsString('<p>Body</p>', $stripped);
    }

    public function testStripBrandingLeavesUnbrandedHtmlUntouched(): void
    {
        $html = '<html><body><p>Nothing to strip</p></body></html>';

        $this->assertSame($html, EmailBranding::stripBranding($html));
    }

    /** strip(wrap(x)) === x — the editor round-trip must not erode the template. */
    public function testWrapThenStripIsLossless(): void
    {
        $original = '<!DOCTYPE html><html><body style="margin:0"><p>Hi {{recipient_first_name}}</p></body></html>';
        $round = EmailBranding::stripBranding(
            EmailBranding::wrap($original, $this->brandWithLogo(), 'https://x/unsub')
        );

        $this->assertSame($original, $round);
    }

    public function testClubNameAndUrlsAreEscaped(): void
    {
        $brand = $this->brandWithLogo();
        $brand['name'] = 'Fish & "Chips" FC <script>';
        $header = EmailBranding::headerHtml($brand);
        $footer = EmailBranding::footerHtml($brand, 'https://x/unsub?a=1&b=2');

        $this->assertStringNotContainsString('<script>', $header);
        $this->assertStringContainsString('Fish &amp; &quot;Chips&quot; FC', $header);
        $this->assertStringContainsString('a=1&amp;b=2', $footer);
    }
}
