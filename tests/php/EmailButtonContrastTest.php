<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/Email.php';

/**
 * The CTA buttons are dark green (#12443E). Their white text used to live only
 * in a <style> block, and mail clients routinely override anchor color with
 * their own link styling — so the button rendered dark green with the client's
 * default blue label, which is close to unreadable.
 *
 * The color has to be inline on the anchor AND on a nested span (some clients
 * override the anchor but not its children). This guards all four templates,
 * which are near-identical copies and drift easily.
 */
class EmailButtonContrastTest extends TestCase
{
    /** @return array<string, array{0:string, 1:array}> template method => constructor args */
    public static function templateProvider(): array
    {
        return [
            'magic link'      => ['getMagicLinkTemplate',      ['Sam', 'https://example.invalid/m']],
            'password reset'  => ['getPasswordResetTemplate',  ['Sam', 'https://example.invalid/r']],
            'parent invite'   => ['getParentInviteTemplate',   ['Sam', 'https://example.invalid/i', 'Rachel']],
            'team invitation' => ['getTeamInvitationTemplate', ['Eagles', 'Coach Lee', 'https://example.invalid/t', '']],
        ];
    }

    private function render(string $method, array $args): string
    {
        $m = new ReflectionMethod(Email::class, $method);
        $m->setAccessible(true);
        return $m->invokeArgs(new Email(), $args);
    }

    /**
     * @dataProvider templateProvider
     */
    public function testButtonLabelIsExplicitlyWhite(string $method, array $args): void
    {
        $html = $this->render($method, $args);

        $this->assertMatchesRegularExpression(
            '/<a\b[^>]*class="button"[^>]*style="[^"]*color:\s*#ffffff\s*!important/i',
            $html,
            "{$method}: button anchor must set white inline, not rely on the <style> block."
        );

        $this->assertMatchesRegularExpression(
            '/<a\b[^>]*class="button"[^>]*>\s*<span[^>]*color:\s*#ffffff\s*!important/i',
            $html,
            "{$method}: button label needs a white <span> for clients that override the anchor."
        );

        // The failure mode was a white-on-green button losing its color and
        // falling back to the client's blue. Nothing here should name a blue.
        $button = [];
        preg_match('/<a\b[^>]*class="button"[^>]*>.*?<\/a>/is', $html, $button);
        $this->assertNotEmpty($button, "{$method}: no button anchor found.");
        $this->assertDoesNotMatchRegularExpression(
            '/color:\s*(blue|#0000ff|#00f\b|#1a73e8|#0645ad)/i',
            $button[0],
            "{$method}: button must not declare a blue label color."
        );
    }
}
