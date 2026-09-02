<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;
use ReflectionProperty;

require_once __DIR__ . '/../../lib/feature_flags.php';
require_once __DIR__ . '/../../lib/email_invoice_and_registration.php';

/**
 * Two sends that used to be stubs (Phase 2, slice 2.1b):
 *
 *   api/invoices.php?action=send        logged "DEMO: Would send invoice email to X"
 *                                       and answered success: true
 *   registrations-api.php POST          had `// sendConfirmationEmail($formData);`
 *
 * Both failure modes were invisible: the UI said the mail went, and nothing was
 * ever sent. So half of this file is a source scan rather than a unit test — the
 * bug was never in the template, it was in whether anything called one.
 *
 * The other half renders both templates with no network and no SendGrid key, and
 * checks the three things that have actually gone wrong in this repo's emails
 * before: an unbranded sender, an unresolved {{merge tag}} shipped to a family,
 * and a CTA whose white label lived only in a <style> block.
 */
class InvoiceAndRegistrationEmailTest extends TestCase
{
    private const INVOICES = 'api/invoices.php';
    private const REGISTRATIONS = 'registration/registrations-api.php';

    private function src(string $rel): string
    {
        $path = __DIR__ . '/../../' . $rel;
        $this->assertFileExists($path);

        return file_get_contents($path);
    }

    /**
     * Assert a call sits INSIDE the body of `if (te_feature_enabled('FLAG')) { ... }`.
     *
     * Position alone is not enough: a send written after the if-block would still
     * come "after" the check and would still fire with the switch off. So this
     * walks braces from the check to its matching close and requires the call to
     * land between them.
     */
    private function assertCallIsInsideTheFlagBlock(string $src, string $flag, string $call): void
    {
        $checkAt = strpos($src, "te_feature_enabled('$flag')");
        $this->assertNotFalse($checkAt, "expected a te_feature_enabled('$flag') check");

        $open = strpos($src, '{', $checkAt);
        $this->assertNotFalse($open, "te_feature_enabled('$flag') must open a block");

        $depth = 0;
        $close = null;
        for ($i = $open, $n = strlen($src); $i < $n; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    $close = $i;
                    break;
                }
            }
        }
        $this->assertNotNull($close, "unbalanced braces after te_feature_enabled('$flag')");

        $callAt = strpos($src, $call);
        $this->assertNotFalse($callAt, "expected a real send: $call");
        $this->assertGreaterThan($open, $callAt, "$call must sit inside the te_feature_enabled('$flag') block");
        $this->assertLessThan($close, $callAt, "$call must sit inside the te_feature_enabled('$flag') block");
    }

    // ---------------------------------------------------------------- invoices

    /**
     * The stub said `DEMO: Would send invoice email to ...` and the endpoint then
     * answered success: true. A club admin clicking Send had no way to know.
     */
    public function testTheInvoiceDemoStubIsGone(): void
    {
        $this->assertStringNotContainsString('DEMO:', $this->src(self::INVOICES),
            'api/invoices.php must not carry a DEMO stub in place of a send');
    }

    public function testTheInvoiceEndpointReallySends(): void
    {
        $this->assertStringContainsString('te_send_invoice_email(', $this->src(self::INVOICES));
    }

    public function testTheInvoiceSendIsGatedOnItsSwitch(): void
    {
        $this->assertCallIsInsideTheFlagBlock(
            $this->src(self::INVOICES), 'TRANSACTIONAL_EMAIL', 'te_send_invoice_email('
        );
    }

    /** With the switch off the endpoint must SAY so, never report a send. */
    public function testTheInvoiceEndpointReportsTheSwitchRatherThanClaimingSuccess(): void
    {
        $src = $this->src(self::INVOICES);
        $this->assertStringContainsString("te_feature_disabled_response('TRANSACTIONAL_EMAIL')", $src);
    }

    /**
     * The club comes from the invoice's program (or the athlete) and is resolved
     * before the send, so the From line carries the club a family recognises.
     */
    public function testTheInvoiceSendIsBrandedBeforeItSends(): void
    {
        $src = $this->src(self::INVOICES);

        $brandAt = strpos($src, '->forClub(');
        $sendAt = strpos($src, 'te_send_invoice_email(');
        $this->assertNotFalse($brandAt, 'the invoice send must brand as the club');
        $this->assertNotFalse($sendAt);
        $this->assertLessThan($sendAt, $brandAt, '->forClub() must precede the send');

        $constructs = preg_match_all('/new\s+Email\s*\(\s*\)/', $src);
        $branded = substr_count($src, '->forClub(');
        $this->assertGreaterThan(0, $constructs);
        $this->assertSame($constructs, $branded,
            'every Email constructed here has a club, so every one must brand as it');
    }

    // ----------------------------------------------------------- registrations

    public function testTheCommentedOutConfirmationCallIsGone(): void
    {
        $src = $this->src(self::REGISTRATIONS);
        $this->assertDoesNotMatchRegularExpression(
            '~//\s*sendConfirmationEmail\s*\(~',
            $src,
            'the commented-out call must be replaced by a real send, not left beside one'
        );
    }

    public function testTheRegistrationConfirmationReallySends(): void
    {
        $this->assertStringContainsString(
            'te_send_registration_confirmation(', $this->src(self::REGISTRATIONS)
        );
    }

    public function testTheRegistrationSendIsGatedOnItsSwitch(): void
    {
        $this->assertCallIsInsideTheFlagBlock(
            $this->src(self::REGISTRATIONS), 'REGISTRATION_CONFIRMATION', 'te_send_registration_confirmation('
        );
    }

    public function testTheRegistrationSendIsBrandedBeforeItSends(): void
    {
        $src = $this->src(self::REGISTRATIONS);

        $brandAt = strpos($src, '->forClub(');
        $sendAt = strpos($src, 'te_send_registration_confirmation(');
        $this->assertNotFalse($brandAt, 'the confirmation must brand as the club');
        $this->assertNotFalse($sendAt);
        $this->assertLessThan($sendAt, $brandAt, '->forClub() must precede the send');

        // The Email built for the confirmation is branded on the spot.
        $this->assertStringContainsString('(new Email())->forClub($connection, $confirmClubId)', $src);
    }

    /** Every Email this file constructs is branded as the club (EmailSenderTest's rule, applied here). */
    public function testEveryEmailInTheFileIsBrandedAsTheClub(): void
    {
        $src = $this->src(self::REGISTRATIONS);
        $constructs = preg_match_all('/new\s+Email\s*\(\s*\)/', $src);
        $branded = substr_count($src, '->forClub(');
        $this->assertSame(2, $constructs, 'this file constructs two Emails: the confirmation and the approval invite');
        $this->assertSame($constructs, $branded, 'an Email constructed here must be ->forClub()');
    }

    /**
     * ⚠️ The send must sit AFTER commit(), not inside the transaction.
     *
     * A throw inside the transaction rolls back the family's registration — they
     * lose their place in the program because our mail provider had a bad minute.
     * Same lesson as the consent capture in this file, which runs in a SAVEPOINT
     * for exactly this reason.
     */
    public function testTheConfirmationIsSentAfterTheRegistrationIsCommitted(): void
    {
        $src = $this->src(self::REGISTRATIONS);

        $sendAt = strpos($src, 'te_send_registration_confirmation(');
        $this->assertNotFalse($sendAt);

        // The commit that precedes the send is the POST branch's own commit.
        $commitAt = strrpos(substr($src, 0, $sendAt), '$connection->commit();');
        $this->assertNotFalse($commitAt, 'the confirmation must be sent after a commit()');

        // Nothing may roll back between that commit and the send.
        $between = substr($src, $commitAt, $sendAt - $commitAt);
        $this->assertStringNotContainsString('beginTransaction', $between,
            'no transaction may be opened between the commit and the send');
    }

    /** A failed or switched-off send must never fail the registration. */
    public function testTheRegistrationResponseCarriesWhetherTheConfirmationWent(): void
    {
        $this->assertStringContainsString("'confirmation_sent'", $this->src(self::REGISTRATIONS));
    }

    // -------------------------------------------------------- rendered content

    private function pdoWithClub(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec("
            CREATE TABLE club_profile (
                id INTEGER PRIMARY KEY, name TEXT, primary_color TEXT, email TEXT,
                website TEXT, social_facebook TEXT, social_instagram TEXT,
                logo_png BLOB, logo_w INTEGER, logo_h INTEGER
            );
        ");
        $pdo->sqliteCreateFunction('md5', 'md5', 1);
        $pdo->exec("INSERT INTO club_profile (id, name, primary_color)
                    VALUES (51, 'Central Kansas United', '#12443e')");

        if (class_exists('EmailBranding') && property_exists('EmailBranding', 'cache')) {
            $ref = new \ReflectionProperty('EmailBranding', 'cache');
            $ref->setAccessible(true);
            $ref->setValue(null, []);
        }

        return $pdo;
    }

    private function fromNameOf(\Email $email): string
    {
        $p = new ReflectionProperty(\Email::class, 'fromName');
        $p->setAccessible(true);

        return (string) $p->getValue($email);
    }

    private function invoiceCtx(): array
    {
        return [
            'club_name' => 'Central Kansas United',
            'guardian_first' => 'Jane',
            'athlete_name' => 'Rachel Jones',
            'program_name' => 'Fall 2026 U12 Select',
            'invoice_number' => 'INV-202609-00042',
            'due_date' => '2026-10-01',
            'total_amount' => 450.00,
            'amount_paid' => 100.00,
            'amount_due' => 350.00,
            'memo' => 'Balance due before the first match.',
            'items' => [
                ['description' => 'Program fee — Fall 2026', 'quantity' => 1, 'unit_price' => 400, 'line_total' => 400],
                ['description' => 'Uniform kit & socks', 'quantity' => 2, 'unit_price' => 25, 'line_total' => 50],
            ],
            'pay_url' => 'https://teams-elevated.netlify.app/parent/pay/42',
        ];
    }

    private function registrationCtx(): array
    {
        return [
            'club_name' => 'Central Kansas United',
            'guardian_first' => 'Jane',
            'athlete_name' => 'Rachel Jones',
            'program_name' => 'Fall 2026 U12 Select',
            'what_to_bring' => "Cleats & shin guards\nA full water bottle",
            'portal_url' => 'https://teams-elevated.netlify.app/parent',
        ];
    }

    /**
     * The club's own name is what a parent recognises in their inbox. Before
     * lib/email_sender.php there were three senders across three paths and the
     * club appeared in none of them.
     */
    public function testBothTemplatesSendUnderTheClubName(): void
    {
        $pdo = $this->pdoWithClub();
        $email = (new \Email())->forClub($pdo, 51);

        $this->assertSame('Central Kansas United', $this->fromNameOf($email));

        foreach ([te_invoice_email_content($this->invoiceCtx()),
                  te_registration_confirmation_content($this->registrationCtx())] as $c) {
            $this->assertStringContainsString('Central Kansas United', $c['html']);
            $this->assertStringContainsString('Central Kansas United', $c['text']);
        }
    }

    /**
     * An unresolved {{tag}} in a sent email cannot be unsent — the send path for
     * bulk mail 422s on one for that reason. These templates interpolate in PHP,
     * so a `{{` in the output means a tag reached a family verbatim.
     *
     * @dataProvider renderedParts
     */
    public function testNoUnresolvedMergeTagReachesAFamily(string $part): void
    {
        foreach ([te_invoice_email_content($this->invoiceCtx()),
                  te_registration_confirmation_content($this->registrationCtx())] as $c) {
            $this->assertStringNotContainsString('{{', $c[$part]);
            $this->assertStringNotContainsString('}}', $c[$part]);
        }
    }

    public static function renderedParts(): array
    {
        return [['subject'], ['html'], ['text']];
    }

    /**
     * EmailButtonContrastTest's rule: the white label must be inline on the
     * anchor AND on a nested span. Mail clients override anchor colour with their
     * own link styling, and some override the anchor but not its children — a
     * <style> block alone renders a blue label on the dark green button.
     *
     * @dataProvider ctaTemplates
     */
    public function testTheCtaButtonLabelIsExplicitlyWhite(string $which): void
    {
        $html = $which === 'invoice'
            ? te_invoice_email_content($this->invoiceCtx())['html']
            : te_registration_confirmation_content($this->registrationCtx())['html'];

        $this->assertMatchesRegularExpression(
            '/<a\b[^>]*class="button"[^>]*style="[^"]*color:\s*#ffffff\s*!important/i',
            $html,
            "$which: the anchor must set white inline, not rely on the <style> block"
        );
        $this->assertMatchesRegularExpression(
            '/<a\b[^>]*class="button"[^>]*>\s*<span[^>]*color:\s*#ffffff\s*!important/i',
            $html,
            "$which: the label needs a white <span> for clients that override the anchor"
        );

        preg_match('/<a\b[^>]*class="button"[^>]*>.*?<\/a>/is', $html, $button);
        $this->assertNotEmpty($button, "$which: no CTA anchor found");
        $this->assertDoesNotMatchRegularExpression(
            '/color:\s*(blue|#0000ff|#00f\b|#1a73e8|#0645ad)/i',
            $button[0],
            "$which: the button must not declare a blue label colour"
        );
    }

    public static function ctaTemplates(): array
    {
        return [['invoice'], ['registration']];
    }

    /** The bill's headline numbers and the pay link have to actually appear. */
    public function testTheInvoiceEmailCarriesTheAmountDueDateItemsAndLink(): void
    {
        $c = te_invoice_email_content($this->invoiceCtx());

        $this->assertStringContainsString('$350.00', $c['html']);
        $this->assertStringContainsString('$350.00', $c['subject']);
        $this->assertStringContainsString('October 1, 2026', $c['html']);
        $this->assertStringContainsString('INV-202609-00042', $c['html']);
        $this->assertStringContainsString('Uniform kit', $c['html']);
        $this->assertStringContainsString('/parent/pay/42', $c['html']);
        $this->assertStringContainsString('/parent/pay/42', $c['text']);
        $this->assertStringContainsString('$350.00', $c['text']);
    }

    /**
     * A date-only column is read and written in ONE timezone. Formatting a DATE
     * through a timestamp is what put practices on the wrong weekday; here it
     * would put the due date a day early for every family in Central.
     */
    public function testTheDueDateIsFormattedOffTheStringNotAThroughATimestamp(): void
    {
        $this->assertSame('January 1, 2027', te_email_format_date_only('2027-01-01'));
        $this->assertSame('October 1, 2026', te_email_format_date_only('2026-10-01 00:00:00'));
        // Unreadable is returned unchanged rather than silently becoming today.
        $this->assertSame('not a date', te_email_format_date_only('not a date'));
    }

    /** A blank amount reads as "nothing owed", which is the wrong default for a bill. */
    public function testAMissingAmountRendersAsZeroNotAsBlank(): void
    {
        $c = te_invoice_email_content(['club_name' => 'CKU', 'guardian_first' => 'Sam']);

        $this->assertStringContainsString('$0.00', $c['html']);
        $this->assertStringNotContainsString('Amount due</p>' . "\n" . '                <div class="amount"></div>', $c['html']);
    }

    /** Line-item text is club-supplied; it must not be able to break out into markup. */
    public function testClubSuppliedTextIsEscaped(): void
    {
        $ctx = $this->invoiceCtx();
        $ctx['items'] = [['description' => '<script>alert(1)</script> & "quotes"',
                          'quantity' => 1, 'unit_price' => 1, 'line_total' => 1]];
        $html = te_invoice_email_content($ctx)['html'];

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testWhatToBringIsOptionalAndEscapedWhenPresent(): void
    {
        $with = te_registration_confirmation_content($this->registrationCtx());
        $this->assertStringContainsString('What to bring', $with['html']);
        $this->assertStringContainsString('Cleats', $with['text']);

        $ctx = $this->registrationCtx();
        unset($ctx['what_to_bring']);
        $without = te_registration_confirmation_content($ctx);
        $this->assertStringNotContainsString('What to bring', $without['html']);

        $ctx['what_to_bring'] = '<b>cleats</b>';
        $escaped = te_registration_confirmation_content($ctx)['html'];
        $this->assertStringNotContainsString('<b>cleats</b>', $escaped);
    }

    /** "We'll be in touch" is the whole point of the confirmation. */
    public function testTheConfirmationSaysTheClubWillBeInTouch(): void
    {
        $c = te_registration_confirmation_content($this->registrationCtx());

        $this->assertStringContainsString('will be in touch', $c['html']);
        $this->assertStringContainsString('will be in touch', $c['text']);
        $this->assertStringContainsString('Rachel Jones', $c['html']);
        $this->assertStringContainsString('Fall 2026 U12 Select', $c['subject']);
    }

    /** No APP_URL means no button, rather than a link to nowhere. */
    public function testAMissingPayUrlOmitsTheButtonRatherThanLinkingNowhere(): void
    {
        $ctx = $this->invoiceCtx();
        $ctx['pay_url'] = '';

        $this->assertStringNotContainsString('class="button"', te_invoice_email_content($ctx)['html']);
    }

    public function testThePayUrlPointsAtTheParentPortalInvoicePage(): void
    {
        putenv('APP_URL=https://teams-elevated.netlify.app');
        $_ENV['APP_URL'] = 'https://teams-elevated.netlify.app';

        $this->assertSame('https://teams-elevated.netlify.app/parent/pay/42', te_invoice_pay_url(42));
        $this->assertSame('https://teams-elevated.netlify.app/parent', te_parent_portal_url());

        putenv('APP_URL');
        unset($_ENV['APP_URL']);
    }
}
