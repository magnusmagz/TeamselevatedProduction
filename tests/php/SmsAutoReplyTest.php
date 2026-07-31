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

    /**
     * Replaced 2026-07-31. This used to assert the handler wrote NOTHING, pinning
     * the Tier 0 promise that a reply was answered and discarded.
     *
     * M1 deliberately breaks that promise: replies are now recorded so the inbox
     * has something to show. What has NOT changed is the outgoing wording — it
     * still says the number is not monitored, and that stays true, because storing
     * is not monitoring. The copy moves in M4, when a human can actually answer.
     *
     * So the invariant worth guarding is no longer "stores nothing" but "the
     * message still tells the truth", which A1/A2 below already cover. Recording
     * itself is covered by InboundSmsCaptureTest.
     */
    public function testTheHandlerStillClaimsOnlyWhatIsTrue(): void
    {
        $msg = $this->messageIn($this->post('hi'));

        $this->assertStringContainsString(
            'not monitored',
            $msg,
            'until M4 gives a human somewhere to answer, this must stay accurate'
        );
    }
}
