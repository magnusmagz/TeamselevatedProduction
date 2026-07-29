<?php
/**
 * Email Branding
 *
 * One source of truth for the club-branded header and footer that wrap outbound
 * CRM email. Templates (platform and club) are club-agnostic by design — they are
 * shared across every club — so the club's identity is applied at send time from
 * `club_profile` rather than baked into template HTML.
 *
 * The logo is served as a cached, email-safe PNG by `api/club-logo.php`
 * (see migration 049 — `club_profile.logo_png` / `logo_w` / `logo_h`, stored at 2x).
 * Email clients cannot render SVG/AVIF and proxy-cache images hard, which is why
 * `logo_url` is never linked directly. A club with no cached PNG degrades to the
 * club name in text — never a broken image.
 *
 * Used by:
 *   - services/EmailSendService.php  (compose, template sends, broadcasts)
 *   - api/communications-gateway.php (preview-email, so preview == what sends)
 *   - services/CalendarInviteService.php (event invites) via its own helpers
 */
class EmailBranding
{
    /** Injected once; presence makes wrap() idempotent. */
    const HEADER_MARKER = '<!--te-brand-header-->';
    const FOOTER_MARKER = '<!--te-brand-footer-->';

    /** Header style: club logo + name centred above the message (safe on any template). */
    const STYLE_MASTHEAD = 'masthead';
    /** Header style: logo chip + club name locked up on a club-colour band. */
    const STYLE_BAND = 'band';

    /** @var array<int,array> per-request brand cache, keyed by club id */
    private static $cache = [];

    /**
     * Branding for a club: name, colour, logo endpoint + display size, socials.
     * Falls back to sane defaults for a missing club or an unreadable row.
     *
     * @param PDO $pdo
     * @param int $clubId
     * @return array{name:string,color:string,email:string,website:string,fb:string,ig:string,logo:string,logo_w:int,logo_h:int}
     */
    public static function forClub($pdo, $clubId)
    {
        $clubId = (int) $clubId;
        if (array_key_exists($clubId, self::$cache)) {
            return self::$cache[$clubId];
        }

        $brand = [
            'name' => 'Your Club', 'color' => '#12443e', 'email' => '', 'website' => '',
            'fb' => '', 'ig' => '', 'logo' => '', 'logo_w' => 0, 'logo_h' => 0,
        ];

        try {
            if ($clubId && $pdo) {
                // logo_v = short hash of the cached PNG so a re-uploaded logo busts the
                // email proxy cache. Computed in SQL so the base64 blob is never transferred.
                $stmt = $pdo->prepare(
                    "SELECT name, primary_color, email, website, social_facebook, social_instagram,
                            logo_w, logo_h,
                            CASE WHEN logo_png IS NOT NULL AND logo_png <> '' THEN substr(md5(logo_png), 1, 8) END AS logo_v
                     FROM club_profile WHERE id = ?"
                );
                $stmt->execute([$clubId]);
                $c = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($c) {
                    if (!empty($c['name'])) {
                        $brand['name'] = $c['name'];
                    }
                    if (!empty($c['primary_color']) && preg_match('/^#?[0-9a-fA-F]{6}$/', $c['primary_color'])) {
                        $brand['color'] = (substr($c['primary_color'], 0, 1) === '#' ? '' : '#') . $c['primary_color'];
                    }
                    $brand['email']   = $c['email'] ?? '';
                    $brand['website'] = $c['website'] ?? '';
                    $brand['fb']      = $c['social_facebook'] ?? '';
                    $brand['ig']      = $c['social_instagram'] ?? '';
                    if (!empty($c['logo_v']) && !empty($c['logo_w']) && !empty($c['logo_h'])) {
                        $brand['logo'] = self::backendUrl() . '/api/club-logo.php?club_id=' . $clubId . '&v=' . $c['logo_v'];
                        // stored at 2x for retina; display at half.
                        $brand['logo_w'] = (int) round($c['logo_w'] / 2);
                        $brand['logo_h'] = (int) round($c['logo_h'] / 2);
                    }
                }
            }
        } catch (Exception $e) {
            // fall back to defaults — branding must never block a send
        }

        self::$cache[$clubId] = $brand;
        return $brand;
    }

    /** True when the club has an email-safe logo we can render. */
    public static function hasLogo($brand)
    {
        return !empty($brand['logo']) && !empty($brand['logo_w']) && !empty($brand['logo_h']);
    }

    /**
     * Bare logo <img>, scaled to a target height (keeps aspect ratio).
     *
     * @param array $brand
     * @param int   $height display height in px
     */
    public static function logoImgHtml($brand, $height = 44)
    {
        if (!self::hasLogo($brand)) {
            return '';
        }
        $h = (int) $height;
        $w = (int) round($brand['logo_w'] * $h / max(1, (int) $brand['logo_h']));
        $src = htmlspecialchars($brand['logo'], ENT_QUOTES);
        $alt = htmlspecialchars($brand['name'] . ' logo', ENT_QUOTES);
        return '<img src="' . $src . '" alt="' . $alt . '" width="' . $w . '" height="' . $h . '" '
             . 'style="display:block;border:0;width:' . $w . 'px;height:' . $h . 'px;">';
    }

    /**
     * Logo on a small white chip — needed whenever the logo sits on the club
     * colour, since crests are often dark or transparent.
     */
    public static function logoChipHtml($brand, $height = 44)
    {
        $img = self::logoImgHtml($brand, $height);
        if ($img === '') {
            return '';
        }
        return '<span style="display:inline-block;background:#ffffff;border-radius:9px;padding:6px 10px;line-height:0;">'
             . $img . '</span>';
    }

    /**
     * Club-branded header.
     *
     * STYLE_MASTHEAD (default) — logo + club name centred on the email background.
     * Safe above ANY template, including the many that open with their own coloured
     * hero band: a second colour band stacked on a hero clashes whenever the club
     * colour differs from the template's.
     *
     * STYLE_BAND — the invite-email lockup: logo chip left, club name + subtitle
     * right, on the club-colour band.
     *
     * Returns '' when there is nothing to show.
     */
    public static function headerHtml($brand, $subtitle = '', $style = self::STYLE_MASTHEAD)
    {
        $club = htmlspecialchars($brand['name'] ?? '', ENT_QUOTES);
        $sub  = htmlspecialchars((string) $subtitle, ENT_QUOTES);
        $hasLogo = self::hasLogo($brand);

        if (!$hasLogo && $club === '') {
            return '';
        }

        if ($style === self::STYLE_BAND) {
            $chip = self::logoChipHtml($brand, 40);
            $logoCell = $chip !== '' ? '<td style="vertical-align:middle;padding-right:14px;">' . $chip . '</td>' : '';
            $subLine = $sub !== ''
                ? '<div style="color:rgba(255,255,255,.72);font-size:12px;text-transform:uppercase;letter-spacing:.08em;margin-top:3px;">' . $sub . '</div>'
                : '';
            $inner = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;"><tr>'
                   . $logoCell
                   . '<td style="vertical-align:middle;">'
                   . '<div style="color:#ffffff;font-weight:800;font-size:16px;letter-spacing:.02em;line-height:1.2;">' . $club . '</div>'
                   . $subLine
                   . '</td></tr></table>';
            $band = 'background-color:' . $brand['color'] . ';border-radius:10px;padding:16px 24px;';
        } else {
            $logo = $hasLogo ? '<div style="margin-bottom:8px;">' . self::centre(self::logoImgHtml($brand, 46)) . '</div>' : '';
            $subLine = $sub !== ''
                ? '<div style="color:#8a8f98;font-size:11px;text-transform:uppercase;letter-spacing:.09em;margin-top:4px;">' . $sub . '</div>'
                : '';
            $inner = $logo
                   . '<div style="color:' . $brand['color'] . ';font-weight:800;font-size:15px;letter-spacing:.02em;line-height:1.2;">' . $club . '</div>'
                   . $subLine;
            $band = 'padding:4px 24px 16px;';
        }

        return self::HEADER_MARKER
             . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">'
             . '<tr><td align="center" style="padding:20px 12px 0;">'
             . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;border-collapse:collapse;">'
             . '<tr><td align="center" style="text-align:center;font-family:Arial,Helvetica,sans-serif;' . $band . '">'
             . $inner
             . '</td></tr></table></td></tr></table>';
    }

    /** Centre a block-level <img> without relying on flex (Outlook). */
    private static function centre($html)
    {
        if ($html === '') {
            return '';
        }
        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto;border-collapse:collapse;">'
             . '<tr><td>' . $html . '</td></tr></table>';
    }

    /**
     * Social + website as hosted PNG icon buttons. Email clients don't render
     * inline SVG, so pre-rasterised white glyphs are served from
     * /email-assets/social/. Only shows platforms the club has a URL for.
     */
    public static function socialIconsHtml($brand)
    {
        $base = self::backendUrl();
        $mk = function ($url, $file, $label) use ($base) {
            return '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" style="text-decoration:none;display:inline-block;margin:0 5px;">'
                 . '<img src="' . $base . '/email-assets/social/' . $file . '" width="34" height="34" alt="' . $label . '" '
                 . 'style="display:inline-block;border:0;width:34px;height:34px;"></a>';
        };
        $items = [];
        if (!empty($brand['fb']))      { $items[] = $mk($brand['fb'], 'facebook.png', 'Facebook'); }
        if (!empty($brand['ig']))      { $items[] = $mk($brand['ig'], 'instagram.png', 'Instagram'); }
        if (!empty($brand['website'])) { $items[] = $mk($brand['website'], 'globe.png', 'Website'); }
        return $items ? '<div style="margin:14px 0 4px;">' . implode('', $items) . '</div>' : '';
    }

    /**
     * Club-branded footer: club-colour band carrying the logo, club name, social
     * icons, the CAN-SPAM "why you got this" line and the unsubscribe link.
     *
     * @param array  $brand
     * @param string $unsubscribeUrl Signed per-recipient URL. '' renders an inert
     *                               link (preview only — real sends always pass one).
     */
    public static function footerHtml($brand, $unsubscribeUrl = '')
    {
        $club  = htmlspecialchars($brand['name'] ?? 'your club', ENT_QUOTES);
        $color = $brand['color'];
        $href  = $unsubscribeUrl !== '' ? htmlspecialchars($unsubscribeUrl, ENT_QUOTES) : '#';

        $logoTop = '';
        if (self::hasLogo($brand)) {
            $logoTop = '<div style="margin-bottom:10px;">' . self::centre(self::logoChipHtml($brand, 26)) . '</div>';
        }

        $contact = '';
        if (!empty($brand['email'])) {
            $contact = '<div style="margin-top:4px;font-size:12px;">'
                     . '<a href="mailto:' . htmlspecialchars($brand['email'], ENT_QUOTES) . '" style="color:rgba(255,255,255,.85);text-decoration:underline;">'
                     . htmlspecialchars($brand['email'], ENT_QUOTES) . '</a></div>';
        }

        $inner = $logoTop
               . '<div style="font-weight:800;font-size:15px;">' . $club . '</div>'
               . $contact
               . self::socialIconsHtml($brand)
               . '<div style="margin-top:10px;font-size:12px;color:rgba(255,255,255,.75);">You received this email from ' . $club . '.</div>'
               . '<div style="margin-top:4px;"><a href="' . $href . '" style="color:#ffffff;text-decoration:underline;font-size:12px;">Unsubscribe</a></div>'
               . '<div style="margin-top:12px;font-size:11px;color:rgba(255,255,255,.55);">Powered by Teams Elevated</div>';

        return self::FOOTER_MARKER
             . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">'
             . '<tr><td align="center" style="padding:20px 12px 24px;">'
             . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;border-collapse:collapse;">'
             . '<tr><td align="center" style="text-align:center;font-family:Arial,Helvetica,sans-serif;background-color:' . $color . ';color:#ffffff;border-radius:10px;padding:22px 24px;">'
             . $inner
             . '</td></tr></table></td></tr></table>';
    }

    /**
     * Wrap a message body in the club header and footer.
     *
     * Idempotent: a body that already carries a marker is returned untouched, so
     * re-processing (retries, a template that opted in explicitly) never doubles up.
     * Inserts inside <body> when the body is a full document, otherwise around the
     * fragment.
     */
    public static function wrap($html, $brand, $unsubscribeUrl = '', $subtitle = '', $style = self::STYLE_MASTHEAD)
    {
        $html = (string) $html;

        if (strpos($html, self::HEADER_MARKER) === false) {
            $header = self::headerHtml($brand, $subtitle, $style);
            if ($header !== '') {
                if (preg_match('/<body\b[^>]*>/i', $html, $m)) {
                    $html = str_replace($m[0], $m[0] . $header, $html);
                } else {
                    $html = $header . $html;
                }
            }
        }

        if (strpos($html, self::FOOTER_MARKER) === false) {
            $html = self::appendToBody($html, self::footerHtml($brand, $unsubscribeUrl));
        }

        return $html;
    }

    /**
     * Insert $fragment just before the document's closing </body>.
     *
     * Anchors on the LAST </body>, not every match: several seeded templates carry
     * explanatory HTML comments containing markup, so a blind str_ireplace inserted
     * the fragment twice — once inside the comment, which also broke the comment
     * open/close pair and leaked its prose into the rendered email.
     */
    public static function appendToBody($html, $fragment)
    {
        if ($fragment === '') {
            return $html;
        }
        $pos = strripos($html, '</body>');
        if ($pos === false) {
            return $html . $fragment;
        }
        return substr($html, 0, $pos) . $fragment . substr($html, $pos);
    }

    /** Backend origin — everything branding links to is served by THIS PHP app. */
    private static function backendUrl()
    {
        return rtrim(getenv('BACKEND_URL') ?: 'https://teamselevated-backend-0485388bd66e.herokuapp.com', '/');
    }
}
