<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/RsvpToken.php';

/**
 * The signed RSVP token — lib/RsvpToken.php.
 *
 * It is the whole credential for api/event-rsvp.php: no login, the payload names
 * the event and the guardian (or athlete), and the answer (yes/no/maybe) rides
 * outside the signature on purpose. So the only things standing between a
 * stranger and another family's RSVP are the HMAC and the fact that the event id
 * is inside the signed body.
 *
 * Shipped 2026 with no phpunit coverage at all — only the manual root-level
 * scripts (test-rsvp-parser.php, test-reply-webhook.php) ever exercised any of
 * this, and they send real email against production.
 */
class RsvpTokenTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        $this->envBackup = $_ENV;
    }

    protected function tearDown(): void
    {
        $_ENV = $this->envBackup;
    }

    // Mutation: return the payload from make() unsigned (drop the '.' . $sig) — round trip dies.
    public function testAGuardianTokenSurvivesTheRoundTrip(): void
    {
        $token = RsvpToken::make(['e' => 4021, 'g' => 463]);
        $payload = RsvpToken::verify($token);
        $this->assertSame(4021, $payload['e']);
        $this->assertSame(463, $payload['g']);
        $this->assertArrayHasKey('x', $payload, 'links minted now carry an expiry');
    }

    // Mutation: same as above, for the athlete-with-own-email shape.
    public function testAnAthleteTokenSurvivesTheRoundTrip(): void
    {
        $token = RsvpToken::make(['e' => 4021, 'a' => 452]);
        $payload = RsvpToken::verify($token);
        foreach (['e' => 4021, 'a' => 452] as $k => $v) { $this->assertSame($v, $payload[$k]); }
        $this->assertArrayHasKey('x', $payload);
    }

    // Mutation: replace hash_equals() in verify() with `true` — every tampered payload is accepted.
    public function testAnEditedPayloadIsRejected(): void
    {
        $token = RsvpToken::make(['e' => 4021, 'g' => 463]);
        [$body, $sig] = explode('.', $token);

        // Re-point the token at a different guardian, keeping the original signature.
        $forgedBody = $this->b64u(json_encode(['e' => 4021, 'g' => 999]));
        $this->assertNull(RsvpToken::verify($forgedBody . '.' . $sig));
    }

    // Mutation: replace hash_equals() with `true` — a mangled signature is accepted.
    public function testAnEditedSignatureIsRejected(): void
    {
        $token = RsvpToken::make(['e' => 4021, 'g' => 463]);
        [$body, $sig] = explode('.', $token);
        $sig[0] = $sig[0] === 'A' ? 'B' : 'A';

        $this->assertNull(RsvpToken::verify($body . '.' . $sig));
    }

    // Mutation: drop the `count($parts) !== 2` guard — a bare string reaches hash_hmac and warns.
    public function testMalformedTokenShapesAreRejectedWithoutWarning(): void
    {
        foreach (['', 'notatoken', 'a.b.c', '.', 'a.'] as $bad) {
            $this->assertNull(RsvpToken::verify($bad), "accepted: '$bad'");
        }
    }

    // Mutation: replace hash_equals() with `true` — the swapped body then verifies as event B.
    public function testATokenForOneEventCannotBeReusedOnAnother(): void
    {
        $eventA = RsvpToken::make(['e' => 4021, 'g' => 463]);
        $eventB = RsvpToken::make(['e' => 5099, 'g' => 463]);

        // Each opens only its own event.
        $this->assertSame(4021, RsvpToken::verify($eventA)['e']);
        $this->assertSame(5099, RsvpToken::verify($eventB)['e']);

        // And the event id is INSIDE the signed body: pairing A's body with B's
        // signature (the shape of "edit the event number in the emailed link")
        // verifies as nothing at all.
        [$bodyA] = explode('.', $eventA);
        [, $sigB] = explode('.', $eventB);
        $this->assertNull(RsvpToken::verify($bodyA . '.' . $sigB));
    }

    // Mutation: make secret() return a constant — a token minted under another key verifies.
    public function testATokenSignedWithADifferentSecretIsRejected(): void
    {
        $_ENV['RSVP_TOKEN_SECRET'] = 'a-secret-this-deployment-does-not-have';
        $foreign = RsvpToken::make(['e' => 4021, 'g' => 463]);

        unset($_ENV['RSVP_TOKEN_SECRET']);
        $this->assertNull(RsvpToken::verify($foreign));
    }

    /** Decision 11c (2026-09-02): links carry a 60-day expiry from the change forward. */
    // Mutation: drop the `x < time()` check in verify().
    public function testAnExpiredLinkIsRejected(): void
    {
        $long_ago = RsvpToken::make(['e' => 4021, 'g' => 463, 'x' => 946684800]); // 2000-01-01
        $this->assertNull(RsvpToken::verify($long_ago));
    }

    public function testAFreshLinkCarriesASixtyDayExpiry(): void
    {
        $payload = RsvpToken::verify(RsvpToken::make(['e' => 4021, 'g' => 463]));
        $this->assertIsArray($payload);
        $this->assertEqualsWithDelta(time() + RsvpToken::TTL_SECONDS, $payload['x'], 5);
    }

    public function testALinkMintedBeforeTheChangeStillWorks(): void
    {
        // No 'x' claim at all — every invite email sent before 2026-09-02.
        $body = $this->b64u(json_encode(['e' => 4021, 'g' => 463]));
        $secret = new \ReflectionMethod(RsvpToken::class, 'secret');
        $secret->setAccessible(true);
        $sig = $this->b64u(hash_hmac('sha256', $body, $secret->invoke(null), true));
        $payload = RsvpToken::verify("$body.$sig");
        $this->assertIsArray($payload, 'a legacy link without an expiry must keep working');
    }

    private function b64u(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
