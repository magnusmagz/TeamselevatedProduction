<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Decision 13 (approved 2026-09-03): when a crew member redeems an "Invite to
 * portal" link in handleSetParentPassword, the account is linked to its guardian
 * row through te_link_guardian_on_accept — the same call registration and the
 * shareable-invite accept already make. This is the one approved edit to
 * api/auth-gateway.php beyond the G2 exception, so this test parses the handler
 * and pins its shape:
 *
 *   - the call is present, keyed on the RESOLVED users.id, not the email;
 *   - it runs AFTER the commit, so a link failure can never roll back a password;
 *   - it is wrapped in try/catch, so it can never fail the sign-in.
 */
class ParentInviteRedemptionLinkTest extends TestCase
{
    private function handler(): string
    {
        $src = file_get_contents(__DIR__ . '/../../api/auth-gateway.php');
        $start = strpos($src, 'function handleSetParentPassword(');
        $this->assertNotFalse($start);
        $end = strpos($src, "\nfunction ", $start + 10);
        return substr($src, $start, ($end === false ? strlen($src) : $end) - $start);
    }

    public function testRedemptionWritesTheGuardianLinkForTheResolvedUser(): void
    {
        $h = $this->handler();
        $this->assertStringContainsString(
            "te_link_guardian_on_accept(\$db, (int) \$user['id'], null, \$email)", $h,
            'the link must be keyed on the resolved users.id — the email string is what identity-by-email gets wrong');
    }

    public function testTheLinkRunsAfterTheCommitAndCannotFailTheSignIn(): void
    {
        $h = $this->handler();
        $commit = strpos($h, '$db->commit();');
        $link = strpos($h, 'te_link_guardian_on_accept(');
        $response = strpos($h, "'Your parent account is ready.'");
        $this->assertNotFalse($commit);
        $this->assertNotFalse($link);
        $this->assertGreaterThan($commit, $link, 'link must run after the password/token commit');
        $this->assertLessThan($response, $link, 'link must run before the response is written');

        $between = substr($h, $commit, $link - $commit);
        $tryPos = strrpos($between, 'try {');
        $this->assertNotFalse($tryPos, 'the link call must be inside a try block');
        $after = substr($h, $link, 400);
        $this->assertMatchesRegularExpression('/\}\s*catch\s*\(Throwable\b/', $after, 'the link call must be caught, never fatal');
        $this->assertStringNotContainsString('http_response_code', $after, 'a link failure must not change the response');
    }
}
