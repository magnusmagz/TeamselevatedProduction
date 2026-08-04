<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Why a parent-invite link did or didn't work.
 *
 * The regression: `handleSetParentPassword` folded `used_at IS NULL AND
 * expires_at > NOW()` into the token lookup's WHERE clause, so four distinct
 * situations — no such token, already used, expired, invalidated by a re-send —
 * all produced "Invalid or expired link".
 *
 * On 2026-08-03 a parent finished setup successfully, clicked his link again,
 * was told it had expired, and emailed support four minutes later. His token had
 * four days left; it was simply spent. The message, not the expiry, produced the
 * ticket.
 */
class ParentInviteTokenTest extends TestCase
{
    /** 2026-08-03 12:00:00 UTC — fixed so the tests never depend on the clock. */
    private const NOW = 1785758400;

    private function row(?string $usedAt, string $expiresAt): array
    {
        return ['id' => 1, 'email' => 'p@example.com:parent_invite',
                'used_at' => $usedAt, 'expires_at' => $expiresAt];
    }

    public function testNoRowIsNotFound(): void
    {
        $this->assertSame(TE_INVITE_TOKEN_NOT_FOUND, te_classify_parent_invite_token(null, self::NOW));
        $this->assertSame(TE_INVITE_TOKEN_NOT_FOUND, te_classify_parent_invite_token(false, self::NOW));
        $this->assertSame(TE_INVITE_TOKEN_NOT_FOUND, te_classify_parent_invite_token([], self::NOW));
    }

    public function testUnusedAndInDateIsValid(): void
    {
        $this->assertSame(
            TE_INVITE_TOKEN_VALID,
            te_classify_parent_invite_token($this->row(null, '2026-08-07 22:43:09'), self::NOW)
        );
    }

    /** THE ONE THAT CAUSED THE TICKET. Spent, four days of validity remaining. */
    public function testSpentTokenReportsUsedNotExpired(): void
    {
        $row = $this->row('2026-08-03 21:55:19', '2026-08-07 22:43:09');

        $this->assertSame(TE_INVITE_TOKEN_USED, te_classify_parent_invite_token($row, self::NOW));

        $err = te_parent_invite_token_error(TE_INVITE_TOKEN_USED);
        $this->assertSame('already_used', $err['reason']);
        $this->assertStringContainsString('already set up', $err['error']);
        $this->assertStringNotContainsString('expired', strtolower($err['error']));
    }

    public function testPastExpiryIsExpired(): void
    {
        $this->assertSame(
            TE_INVITE_TOKEN_EXPIRED,
            te_classify_parent_invite_token($this->row(null, '2026-07-01 00:00:00'), self::NOW)
        );
    }

    /**
     * Used is checked BEFORE expired. A spent token whose window has since closed
     * is, to the parent, an account they already have — telling them it expired
     * sends them to the club for an invite they do not need.
     */
    public function testUsedWinsOverExpiredWhenBothApply(): void
    {
        $row = $this->row('2026-07-02 09:00:00', '2026-07-01 00:00:00');

        $this->assertSame(TE_INVITE_TOKEN_USED, te_classify_parent_invite_token($row, self::NOW));
    }

    /** A row with no usable expiry is malformed, not valid forever. */
    public function testMissingOrUnparsableExpiryIsRefused(): void
    {
        $this->assertSame(TE_INVITE_TOKEN_EXPIRED, te_classify_parent_invite_token($this->row(null, ''), self::NOW));
        $this->assertSame(TE_INVITE_TOKEN_EXPIRED, te_classify_parent_invite_token($this->row(null, 'not a date'), self::NOW));
    }

    /** Every non-valid outcome yields a distinct, machine-readable reason. */
    public function testEachOutcomeHasItsOwnReasonAndMessage(): void
    {
        $reasons = [];
        foreach ([TE_INVITE_TOKEN_USED, TE_INVITE_TOKEN_EXPIRED, TE_INVITE_TOKEN_NOT_FOUND] as $c) {
            $err = te_parent_invite_token_error($c);
            $this->assertSame(400, $err['status']);
            $this->assertNotSame('', $err['error']);
            $reasons[] = $err['reason'];
        }

        $this->assertSame($reasons, array_unique($reasons), 'reasons must not collapse');
    }

    /**
     * The unknown-token message must not confirm or deny what exists — it is the
     * only branch reachable by someone guessing tokens.
     */
    public function testUnknownTokenMessageRevealsNothing(): void
    {
        $err = te_parent_invite_token_error(TE_INVITE_TOKEN_NOT_FOUND);

        $this->assertStringNotContainsString('already', strtolower($err['error']));
        $this->assertStringNotContainsString('used', strtolower($err['error']));
    }

    /**
     * Source-level guard: the handler must NOT put used/expiry back into the
     * lookup's WHERE clause, and must not spend the token before resolving the
     * account. Both were the actual bugs; the predicates being right is no help
     * if the query filters the evidence away first.
     */
    public function testHandlerKeepsTheEvidenceItNeedsToTellCasesApart(): void
    {
        $src = file_get_contents(__DIR__ . '/../../api/auth-gateway.php');
        $start = strpos($src, 'function handleSetParentPassword');
        $this->assertNotFalse($start);
        $handler = substr($src, $start, 4000);

        $this->assertStringNotContainsString(
            "email LIKE '%:parent_invite' AND used_at IS NULL",
            $handler,
            'folding used_at into the WHERE clause makes used and expired indistinguishable again'
        );
        $this->assertStringContainsString('te_classify_parent_invite_token', $handler);

        // The account lookup must precede the token being spent.
        $lookupAt = strpos($handler, 'SELECT id, email, first_name, last_name FROM users');
        $spendAt  = strpos($handler, 'SET used_at = CURRENT_TIMESTAMP');
        $this->assertNotFalse($lookupAt);
        $this->assertNotFalse($spendAt);
        $this->assertLessThan(
            $spendAt,
            $lookupAt,
            'the token must not be spent before the account is confirmed to exist'
        );
    }

    /** The invite email must disclose single-use, in BOTH the HTML and text parts. */
    public function testInviteEmailSaysTheLinkIsSingleUse(): void
    {
        $src = file_get_contents(__DIR__ . '/../../lib/Email.php');

        $start = strpos($src, 'public function sendParentInvite');
        $end = strpos($src, 'private function getParentInviteTemplate');
        $this->assertNotFalse($start);
        $textHalf = substr($src, $start, strpos($src, 'return $this->send', $start) - $start);

        $htmlStart = strpos($src, 'private function getParentInviteTemplate');
        $htmlHalf = substr($src, $htmlStart, 4000);

        foreach (['plain text' => $textHalf, 'HTML' => $htmlHalf] as $which => $half) {
            $this->assertMatchesRegularExpression(
                '/only be used once/i',
                $half,
                "the $which invite body must say the link is single-use"
            );
            $this->assertStringContainsString('7 days', $half, "the $which body must still state the 7-day window");
        }
    }
}
