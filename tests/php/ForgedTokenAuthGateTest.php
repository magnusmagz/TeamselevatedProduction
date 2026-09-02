<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use JWT;

/**
 * JWT::decode() does not verify signatures, and three endpoints authenticated with it.
 *
 * decode() splits the token and base64-decodes the payload. That is all. verify() is
 * the one that checks the HMAC and the expiry. Confirmed against production on
 * 2026-08-31 with a hand-built token `{"alg":"HS256"}.{"user_id":999999}.not-a-signature`:
 *
 *     api/invitations-gateway.php?action=list  -> 200 (forged)   401 (no token)
 *     api/user-profile.php                     -> 404 User not found  (passed auth)
 *     api/coach-notes.php?action=list          -> 400 athlete_id is required (passed auth)
 *
 * The 404/400 are the proof: those requests cleared authentication and failed on their
 * own business logic. user-profile.php PUT writes `WHERE id = :user_id` from that claim
 * and lib/guardian_sync.php carries the change into `guardians` — so a forged token
 * edited any account's name, email and phone, and what the club sees for them.
 *
 * Two kinds of assertion, because the bug was never in verify(): it was in which
 * function got called.
 *   1. Functional: a forged token is rejected by verify() and accepted by decode().
 *   2. Scan: no auth gate in the runtime tree calls decode(). The eight calls in
 *      api/auth-gateway.php and the one in api/super-admin-gateway.php decode a token
 *      the server just minted itself, to shape a response — those are correct and
 *      auth-gateway is on the do-not-modify list, so they are the only exemptions.
 */
class ForgedTokenAuthGateTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** Files that legitimately decode() a token they minted themselves. */
    private const EXEMPT = [
        'api/auth-gateway.php',
        'api/super-admin-gateway.php',
        'lib/JWT.php',
    ];

    private const SCAN_DIRS = ['api', 'legacy', 'controllers', 'lib', 'services', 'workers', 'registration'];

    protected function setUp(): void
    {
        require_once self::ROOT . '/lib/JWT.php';
        putenv('JWT_SECRET=forged-token-test-secret');
        putenv('JWT_ALGORITHM=HS256');
    }

    private static function b64url(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function forgedToken(int $userId): string
    {
        return self::b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']))
            . '.' . self::b64url(json_encode(['user_id' => (string)$userId, 'exp' => time() + 3600]))
            . '.not-a-real-signature';
    }

    public function testDecodeAcceptsAForgedTokenWhichIsWhyItIsNotAnAuthGate(): void
    {
        $payload = JWT::decode(self::forgedToken(999999));
        $this->assertIsObject($payload, 'decode() returns the payload of an unsigned token');
        $this->assertSame('999999', $payload->user_id);
    }

    public function testVerifyRejectsAForgedToken(): void
    {
        $this->assertFalse(JWT::verify(self::forgedToken(999999)));
    }

    public function testVerifyAcceptsAGenuineToken(): void
    {
        $token = JWT::generate(42, 'someone@example.com', 'Some One');
        $payload = JWT::verify($token);
        $this->assertIsObject($payload);
        $this->assertSame('42', (string)$payload->user_id);
    }

    public function testVerifyRejectsATamperedPayloadOnAGenuineSignature(): void
    {
        $token = JWT::generate(42, 'someone@example.com', 'Some One');
        [$h, , $sig] = explode('.', $token);
        $tampered = $h . '.' . self::b64url(json_encode(['user_id' => '1', 'exp' => time() + 3600])) . '.' . $sig;
        $this->assertFalse(JWT::verify($tampered));
    }

    public function testNoAuthGateInTheRuntimeTreeCallsDecode(): void
    {
        $offenders = [];
        foreach (self::SCAN_DIRS as $dir) {
            $base = self::ROOT . '/' . $dir;
            if (!is_dir($base)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base));
            foreach ($it as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $rel = ltrim(str_replace(self::ROOT, '', $file->getPathname()), '/');
                if (in_array($rel, self::EXEMPT, true)) {
                    continue;
                }
                $code = self::stripComments(file_get_contents($file->getPathname()));
                if (preg_match('/\bJWT::decode\s*\(|\$jwt->decode\s*\(/', $code)) {
                    $offenders[] = $rel;
                }
            }
        }
        sort($offenders);
        $this->assertSame([], $offenders,
            'These files call JWT::decode(), which does not verify the signature. Use JWT::verify() '
            . 'or AuthMiddleware::requireAuth(). Offenders: ' . implode(', ', $offenders));
    }

    /**
     * The three repaired files now go through verify() (directly or via requireAuth()).
     * Pinned individually so a future refactor that drops the gate on one of them is
     * named in the failure, not buried in the scan.
     *
     * @dataProvider repairedGates
     */
    public function testRepairedGateVerifiesTheSignature(string $rel, string $expected): void
    {
        $code = self::stripComments(file_get_contents(self::ROOT . '/' . $rel));
        $this->assertStringContainsString($expected, $code, "$rel must authenticate through $expected");
    }

    public static function repairedGates(): array
    {
        return [
            ['api/invitations-gateway.php', 'AuthMiddleware::requireAuth()'],
            ['api/user-profile.php', 'JWT::verify('],
            ['api/coach-notes.php', 'JWT::verify('],
        ];
    }

    private static function stripComments(string $src): string
    {
        $out = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out .= $t[1];
            } else {
                $out .= $t;
            }
        }
        return $out;
    }
}
