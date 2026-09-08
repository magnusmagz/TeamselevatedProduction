<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;

/**
 * magic_link_tokens.id is a UUID in Neon. Casting it with (int) keeps the leading
 * digits only and Postgres rejects the update with 22P02 — the whole redeem
 * rolled back, so a coach/referee could never finish setting a password
 * (2026-09-08). The SQLite fixtures use integer ids, which is exactly why no unit
 * test caught it; this scan does.
 */
class MagicLinkTokenIdIsUuidTest extends TestCase
{
    public function testNoIntegerCastOnAMagicLinkTokenId(): void
    {
        $root = __DIR__ . '/../..';
        foreach (['lib/coach_invite.php', 'lib/coach_access.php', 'api/coach-access.php', 'lib/parent_invite_token.php',
                  'lib/ParentInvite.php', 'lib/magic_link.php', 'api/portal-access.php', 'api/referees.php', 'lib/referees.php'] as $rel) {
            $path = "$root/$rel";
            if (!file_exists($path)) { continue; }
            $src = file_get_contents($path);
            if (!str_contains($src, 'magic_link_tokens')) { continue; }
            // Any (int) cast applied to a variable named like a token row id in a file that
            // touches magic_link_tokens is the bug shape.
            $this->assertDoesNotMatchRegularExpression(
                '/\(int\)\s*\$(row|tok|token|tokenRow|existing|t)\[\'id\'\]/',
                $src,
                "$rel casts a magic_link_tokens id to int; the column is a UUID"
            );
        }
    }
}
