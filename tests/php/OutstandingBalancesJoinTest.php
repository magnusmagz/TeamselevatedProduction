<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Outstanding Balances must not lose a family whose fee came from the program's own
 * registration_fee (2026-09-14). registrations-api.php writes athlete_payments with
 * payment_item_id NULL on that path, so an inner join on payment_items dropped them
 * from the treasurer's list — 11 open balances ($2,194) were invisible when found.
 * A scan, because the query uses json_agg / bool_or and cannot run on SQLite.
 */
class OutstandingBalancesJoinTest extends TestCase
{
    public function testPaymentItemsAndProgramsAreLeftJoined(): void
    {
        $src = file_get_contents(__DIR__ . '/../../api/outstanding-balances.php');
        $this->assertMatchesRegularExpression('/LEFT\s+JOIN\s+payment_items\s+pi\b/i', $src);
        $this->assertMatchesRegularExpression('/LEFT\s+JOIN\s+programs\s+p\b/i', $src);
        $this->assertDoesNotMatchRegularExpression('/(?<!LEFT )\bJOIN\s+payment_items\b/i', $src,
            'an inner join on payment_items drops every registration billed from program.registration_fee');
        $this->assertStringContainsString("COALESCE(pi.name, p.name || ' Registration', 'Registration Fee')", $src,
            'a payment with no item still needs a name on the row');
        $this->assertStringContainsString("'a.deleted_at IS NULL'", $src);
    }
}
