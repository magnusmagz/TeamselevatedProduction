<?php

use PHPUnit\Framework\TestCase;

/**
 * The inbound auto-reply.
 *
 * Families reply to broadcasts. Before this handler existed those messages were
 * accepted by Twilio and discarded — the parent got silence and reasonably
 * assumed someone had read it. This says otherwise, once, and points at the
 * portal.
 *
 * The handler is a script rather than a function, so these run it in a subprocess
 * with a faked Twilio POST and assert on the TwiML it emits.
 */
class SmsAutoReplyTest extends TestCase
{
    private const HANDLER = __DIR__ . '/../../api/webhooks/twilio-inbound.php';

    /** Run the webhook with a given inbound body, return the response text. */
    private function post(string $body, string $to = '+13605550199'): string
    {
        $script = sprintf(
            '$_POST = %s; $_SERVER["REQUEST_METHOD"] = "POST"; require %s;',
            var_export(['Body' => $body, 'To' => $to, 'From' => '+13605550201'], true),
            var_export(self::HANDLER, true)
        );

        $cmd = 'php -r ' . escapeshellarg($script) . ' 2>/dev/null';
        return (string) shell_exec($cmd);
    }

    private function messageIn(string $xml): ?string
    {
        return preg_match('#<Message>(.*)</Message>#s', $xml, $m) ? $m[1] : null;
    }

    public function testAnOrdinaryReplyGetsThePortalPointer(): void
    {
        $xml = $this->post("Ava can't make practice tonight");

        $msg = $this->messageIn($xml);
        $this->assertNotNull($msg, 'an ordinary reply must get an auto-response');
        $this->assertStringContainsString('parent portal', $msg);
        $this->assertStringContainsString('not monitored', $msg);
    }

    public function testItIsValidTwiml(): void
    {
        $xml = trim($this->post('hello'));

        $this->assertStringStartsWith('<?xml', $xml);
        $this->assertNotFalse(simplexml_load_string(
            preg_replace('/^<\?xml.*?\?>\s*/s', '', $xml)
        ), 'Twilio silently ignores malformed TwiML');
    }

    /**
     * Twilio owns STOP and HELP. It still forwards them here, but blocks any
     * outbound to that number afterwards — so replying would fail silently at
     * best, and reads as ignoring an opt-out at worst.
     */
    public function testCarrierKeywordsGetSilence(): void
    {
        foreach (['STOP', 'stop', 'Stop.', 'UNSUBSCRIBE', 'CANCEL', 'QUIT', 'HELP', 'INFO', 'START'] as $kw) {
            $xml = $this->post($kw);
            $this->assertNull(
                $this->messageIn($xml),
                "'{$kw}' is a carrier keyword and must not receive an auto-reply"
            );
            $this->assertStringContainsString('<Response>', $xml, 'must still acknowledge with valid TwiML');
        }
    }

    public function testAKeywordInsideASentenceStillGetsAReply(): void
    {
        // "stop by the field at 6" is a message, not an opt-out. Only the bare
        // keyword counts.
        $msg = $this->messageIn($this->post('Can we stop by the field at 6?'));

        $this->assertNotNull($msg);
        $this->assertStringContainsString('parent portal', $msg);
    }

    /**
     * Over 160 GSM-7 characters and every single reply costs two billed segments
     * instead of one, forever, for no added meaning.
     */
    public function testReplyFitsInOneBilledSegment(): void
    {
        $msg = $this->messageIn($this->post('hi'));

        $this->assertLessThanOrEqual(
            160,
            strlen($msg),
            'auto-reply must stay within one SMS segment; it is ' . strlen($msg) . ' chars'
        );
    }

    /**
     * A curly apostrophe or an em dash forces the whole message to UCS-2, where
     * the per-segment limit collapses from 160 to 70 — silently tripling the cost
     * of a message that looks fine in a code review.
     */
    public function testReplyIsGsm7Safe(): void
    {
        $msg = $this->messageIn($this->post('hi'));

        $this->assertSame(
            $msg,
            mb_convert_encoding($msg, 'ASCII', 'UTF-8'),
            'auto-reply contains a non-ASCII character (curly quote or dash?), which forces UCS-2'
        );
    }

    public function testNothingIsStored(): void
    {
        // Tier 0 promises the reply is not recorded, and the outgoing text says so.
        // If this file ever gains a database write, that promise is a lie and this
        // test should be the thing that objects.
        //
        // Comments are stripped first: the file's own docblock explains that it
        // does NOT write to communication_log, and a naive substring search flags
        // that explanation as the very thing it is disclaiming.
        $code = '';
        foreach (token_get_all(file_get_contents(self::HANDLER)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        foreach (['INSERT', 'UPDATE ', 'communication_log', 'Database::', 'PDO'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase(
                $forbidden,
                $code,
                "the auto-reply handler must not persist anything (found '{$forbidden}' in code)"
            );
        }
    }
}
