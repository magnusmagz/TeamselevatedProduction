<?php

use PHPUnit\Framework\TestCase;
use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/../../services/CalendarInviteService.php';

/**
 * PHPMailer ships with CharSet = iso-8859-1. Every body we send is UTF-8, so
 * leaving the default in place declares Latin-1 over UTF-8 bytes and mail
 * clients render mojibake: "🎉" as "ðŸŽ‰", "—" as "â€”".
 *
 * PHPMailer only auto-upgrades to UTF-8 when the recipient ADDRESS contains
 * high bytes (see PHPMailer::addAnAddress), which never applies to us — so the
 * charset has to be set explicitly and stay set.
 *
 * CalendarInviteService holds the only PHPMailer instance in the codebase; the
 * bulk send path goes out over the SendGrid HTTP API, which is JSON and
 * UTF-8-safe by construction.
 */
class MailerCharsetTest extends TestCase
{
    /** Text exercising the characters that actually broke: emoji, em dash, curly quote, accent. */
    private const SAMPLE = 'Season kickoff 🎉 — we’re glad you’re here, José';

    private function serviceMailer(): PHPMailer
    {
        // The constructor swaps in a mock mailer when APP_ENV=test, and this
        // test is specifically about the real one. Force the real branch.
        $prevEnv = getenv('APP_ENV');
        putenv('APP_ENV=phpunit-real-mailer');

        try {
            $svc = new CalendarInviteService(new PDO('sqlite::memory:'), false);
            $prop = new ReflectionProperty(CalendarInviteService::class, 'mailer');
            $prop->setAccessible(true);
            return $prop->getValue($svc);
        } finally {
            $prevEnv === false ? putenv('APP_ENV') : putenv("APP_ENV={$prevEnv}");
        }
    }

    public function testServiceMailerDeclaresUtf8(): void
    {
        $this->assertSame(
            'utf-8',
            strtolower($this->serviceMailer()->CharSet),
            'CalendarInviteService must set CharSet explicitly; the PHPMailer default is iso-8859-1.'
        );
    }

    /**
     * The property alone is not the bug — the rendered MIME is. Build a real
     * message and assert the declared charset matches the bytes actually sent.
     */
    public function testRenderedMessageDeclaresUtf8AndRoundTrips(): void
    {
        $mailer = $this->serviceMailer();
        $mailer->addAddress('household@example.invalid', 'John & Jane Test');
        $mailer->Subject = self::SAMPLE;
        $mailer->isHTML(true);
        $mailer->Body = '<p>' . self::SAMPLE . '</p>';

        $this->assertTrue($mailer->preSend(), $mailer->ErrorInfo);
        $mime = $mailer->getSentMIMEMessage();

        $this->assertMatchesRegularExpression(
            '/charset=["\']?utf-8/i',
            $mime,
            'Message must declare UTF-8.'
        );

        // Decode the transfer encoding and confirm the characters survived intact
        // rather than arriving as the Latin-1 misreading.
        $decoded = quoted_printable_decode($mime);
        if (stripos($mailer->Encoding, 'base64') !== false) {
            $decoded = base64_decode(preg_replace('/\s+/', '', $mime));
        }

        $this->assertStringContainsString('🎉', $decoded, 'Emoji must survive encoding.');
        $this->assertStringContainsString('José', $decoded, 'Accented names must survive encoding.');
        $this->assertStringNotContainsString('ðŸŽ‰', $decoded, 'Body must not contain mojibake.');
    }

    /**
     * Guards the guard: proves this test detects the regression it exists for.
     * With PHPMailer's stock charset the same body is declared Latin-1.
     */
    public function testDefaultCharsetWouldProduceTheBug(): void
    {
        $this->assertSame(
            'iso-8859-1',
            (new PHPMailer(true))->CharSet,
            'If PHPMailer ever changes its default, this suite can relax — until then the explicit set is load-bearing.'
        );
    }
}
