<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;

/**
 * Magic-link minting, shared by the login page and the admin "Send login link".
 *
 * The two TTLs differ on purpose and the difference is the whole feature: an
 * admin clicks now and the parent reads tonight, so a 15-minute admin-sent link
 * would usually be dead on arrival — the same "expired" confusion the 2026-08-03
 * invite fix removed, reintroduced somewhere new.
 */
class MagicLinkTtlTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec("
            CREATE TABLE magic_link_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL, token TEXT NOT NULL,
                expires_at TEXT, used_at TEXT, created_at TEXT
            );
        ");
    }

    public function testAdminSentLinksLastLongerThanSelfServiceOnes(): void
    {
        $this->assertGreaterThan(
            TE_MAGIC_LINK_TTL_SELF_SERVICE,
            TE_MAGIC_LINK_TTL_ADMIN_SENT,
            'an admin-sent link is read asynchronously and must outlive a self-service one'
        );
        // Long enough to survive "I will do it after dinner".
        $this->assertGreaterThanOrEqual(3600, TE_MAGIC_LINK_TTL_ADMIN_SENT);
    }

    public function testMintedTokenIsStoredWithTheRequestedExpiry(): void
    {
        $before = time();
        $token = te_mint_magic_link_token($this->pdo, 'p@example.com', TE_MAGIC_LINK_TTL_ADMIN_SENT);

        $row = $this->pdo->query('SELECT * FROM magic_link_tokens')->fetch();
        $this->assertSame($token, $row['token']);
        $this->assertSame('p@example.com', $row['email']);
        $this->assertNull($row['used_at'], 'a freshly minted token must be unspent');

        $expiry = strtotime($row['expires_at']);
        $this->assertGreaterThanOrEqual($before + TE_MAGIC_LINK_TTL_ADMIN_SENT - 5, $expiry);
        $this->assertLessThanOrEqual(time() + TE_MAGIC_LINK_TTL_ADMIN_SENT + 5, $expiry);
    }

    /**
     * The row shape must not depend on which caller minted it — verify-magic-link
     * has no idea where a token came from and must not need to.
     */
    public function testBothTtlsProduceTheSameRowShape(): void
    {
        te_mint_magic_link_token($this->pdo, 'a@example.com', TE_MAGIC_LINK_TTL_SELF_SERVICE);
        te_mint_magic_link_token($this->pdo, 'b@example.com', TE_MAGIC_LINK_TTL_ADMIN_SENT);

        $rows = $this->pdo->query('SELECT * FROM magic_link_tokens ORDER BY id')->fetchAll();
        $this->assertCount(2, $rows);
        $this->assertSame(array_keys($rows[0]), array_keys($rows[1]));
        foreach ($rows as $r) {
            $this->assertSame(64, strlen($r['token']), 'tokens are 32 random bytes, hex encoded');
        }
    }

    public function testTokensAreUnique(): void
    {
        $seen = [];
        for ($i = 0; $i < 20; $i++) {
            $seen[] = te_mint_magic_link_token($this->pdo, 'p@example.com', TE_MAGIC_LINK_TTL_ADMIN_SENT);
        }
        $this->assertCount(20, array_unique($seen));
    }

    /** The phrase shown to the admin must describe the TTL actually used. */
    public function testTtlPhraseMatchesTheTtl(): void
    {
        $this->assertSame('15 minutes', te_magic_link_ttl_phrase(TE_MAGIC_LINK_TTL_SELF_SERVICE));
        $this->assertSame('24 hours', te_magic_link_ttl_phrase(TE_MAGIC_LINK_TTL_ADMIN_SENT));
        $this->assertSame('1 hour', te_magic_link_ttl_phrase(3600));
        $this->assertSame('1 minute', te_magic_link_ttl_phrase(60));
        // Multi-day windows read as days (the coach invite); 24h stays "24 hours".
        $this->assertSame('7 days', te_magic_link_ttl_phrase(7 * 24 * 3600));
    }

    /**
     * Source guard on the endpoint's authorization. `canAccessClub` is club
     * MEMBERSHIP — a `parent` row satisfies it (see the open handleClubParents
     * finding) — so using it here would let any parent mail a sign-in link to
     * any other family in their club.
     */
    public function testEndpointRequiresClubAdminNotMereClubAccess(): void
    {
        $src = file_get_contents(__DIR__ . '/../../api/portal-access.php');

        $this->assertStringContainsString("hasRole('club_admin'", $src);
        $this->assertStringContainsString('requireAuth', $src);

        // Forbid the CALL, not the word — the comment explaining why this
        // endpoint avoids canAccessClub() is worth keeping in the file.
        $this->assertDoesNotMatchRegularExpression(
            '/\$auth\s*->\s*canAccessClub\s*\(/',
            $src,
            'canAccessClub is club membership, not staff standing — a parent satisfies it'
        );
    }

    /**
     * The token must never come back in the response — the email is the channel,
     * and that is what stops an admin from gaining access to a family's account.
     */
    public function testEndpointNeverReturnsTheToken(): void
    {
        $src = file_get_contents(__DIR__ . '/../../api/portal-access.php');

        $successBlock = substr($src, strpos($src, "echo json_encode([\n    'success' => true"));
        $this->assertStringNotContainsString('$token', $successBlock, 'the token must not be echoed');
        $this->assertStringNotContainsString('$link', $successBlock, 'the link contains the token');
    }
}
