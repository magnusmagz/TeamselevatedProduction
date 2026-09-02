<?php
/**
 * RsvpToken — tiny signed token for one-click email RSVP.
 *
 * The token authorizes an RSVP for a specific event by a specific guardian (or
 * athlete). The chosen response (yes/no/maybe) travels as a separate query param
 * — changing it only changes that family's own RSVP, so it needs no signing.
 * HMAC-SHA256 over a base64url payload (same primitives JWT.php uses; this does
 * NOT touch the auth system).
 *
 * Payload: ['e' => eventId, 'g' => guardianId]  (guardian invite)
 *       or ['e' => eventId, 'a' => athleteId]   (athlete-with-own-email invite)
 */
require_once __DIR__ . '/../config/env.php';

class RsvpToken
{
    private static function secret(): string
    {
        // Dedicated secret if set, else reuse JWT_SECRET (domain-separated by the
        // 'rsvp:' prefix below). Never the empty string in prod.
        $s = Env::get('RSVP_TOKEN_SECRET', '') ?: Env::get('JWT_SECRET', '');
        return 'rsvp:' . ($s ?: 'te-rsvp-dev-secret');
    }

    /** Links minted from 2026-09-02 carry an expiry (decision 11c); older links have none and stay valid. */
    public const TTL_SECONDS = 60 * 86400;

    public static function make(array $payload): string
    {
        if (!array_key_exists('x', $payload)) {
            $payload['x'] = time() + self::TTL_SECONDS;
        }
        $body = self::b64u(json_encode($payload));
        $sig  = self::b64u(hash_hmac('sha256', $body, self::secret(), true));
        return $body . '.' . $sig;
    }

    /** @return array|null decoded payload, or null if invalid/tampered */
    public static function verify(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        [$body, $sig] = $parts;
        $expected = self::b64u(hash_hmac('sha256', $body, self::secret(), true));
        if (!hash_equals($expected, $sig)) {
            return null;
        }
        $data = json_decode(self::b64uDecode($body), true);
        if (!is_array($data)) {
            return null;
        }
        if (isset($data['x']) && (int) $data['x'] < time()) {
            return null; // expired — the page's "invalid or has expired" copy is finally true
        }
        return $data;
    }

    private static function b64u(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function b64uDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/')) ?: '';
    }
}
