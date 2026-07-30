<?php

use PHPUnit\Framework\TestCase;

/**
 * Chat retention rules — lib/retention_plans.php + migration 059.
 *
 * These plans delete production rows, so the things worth locking down are the
 * refusals: that the removed-messages plan can never touch a live message, that
 * neither plan can be armed by accident, and that both clear the inbound foreign
 * key that would otherwise fail the purge.
 */
class ChatRetentionPlanTest extends TestCase
{
    private array $plans;

    protected function setUp(): void
    {
        require_once __DIR__ . '/../../lib/retention_plans.php';
        $this->plans = te_retention_plans();
    }

    private function migration(): string
    {
        return file_get_contents(__DIR__ . '/../../database/migrations/059_chat_retention_policy.sql');
    }

    public function testBothChatPlansAreRegistered(): void
    {
        // A policy row with no plan is reported "UNSUPPORTED" and silently does
        // nothing, which looks identical to "nothing expired".
        $this->assertArrayHasKey('chat_messages_removed', $this->plans);
        $this->assertArrayHasKey('chat_messages', $this->plans);
    }

    public function testMigrationSeedsExactlyThePlansThatExist(): void
    {
        $sql = $this->migration();
        foreach (['chat_messages_removed', 'chat_messages'] as $type) {
            $this->assertStringContainsString("'$type'", $sql, "migration must seed $type");
        }
    }

    public function testPreExistingPlansSurvivedTheExtraction(): void
    {
        // The rules were moved out of scripts/retention-check.php; nothing should
        // have been dropped on the way.
        foreach (['athlete_medical', 'medical_records', 'consent_records', 'audit_logs'] as $type) {
            $this->assertArrayHasKey($type, $this->plans);
        }
    }

    /** The central refusal: a removed-message purge must never reach a live message. */
    public function testRemovedPlanOnlyEverTouchesAlreadyRemovedMessages(): void
    {
        $plan = $this->plans['chat_messages_removed'];

        foreach (['count', 'delete'] as $key) {
            $this->assertStringContainsString(
                'deleted_at IS NOT NULL',
                $plan[$key],
                "$key must be constrained to already-removed messages"
            );
        }

        foreach ($plan['before'] as $sql) {
            $this->assertStringContainsString(
                'deleted_at IS NOT NULL',
                $sql,
                'the pointer-clearing statements must be scoped to removed messages too'
            );
        }
    }

    public function testRemovedPlanIsAgedOnRemovalNotCreation(): void
    {
        // The grace period runs from when a moderator removed it — the window is
        // for reversing a bad call, not for how old the message happens to be.
        $delete = $this->plans['chat_messages_removed']['delete'];
        $this->assertStringContainsString("deleted_at < NOW()", $delete);
        $this->assertStringNotContainsString("created_at <", $delete);
    }

    public function testOpenEndedPlanIsAgedOnCreation(): void
    {
        $plan = $this->plans['chat_messages'];
        $this->assertStringContainsString("created_at < NOW()", $plan['delete']);
        $this->assertStringContainsString('DELETE FROM chat_messages', $plan['delete']);
    }

    /**
     * chat_read_receipts.last_read_message_id is a NO ACTION FK onto chat_messages
     * (verified against live Neon 2026-07-30). Delete a message a receipt still
     * points at and Postgres raises 23503, failing the entire purge.
     *
     * The table is empty today and nothing writes it, which is precisely the risk:
     * the purge would pass every test and then fail the first time it mattered.
     */
    public function testBothChatPlansClearTheBlockingForeignKeyFirst(): void
    {
        foreach (['chat_messages_removed', 'chat_messages'] as $type) {
            $this->assertArrayHasKey('before', $this->plans[$type], "$type needs pointer cleanup");

            $before = implode("\n", $this->plans[$type]['before']);
            $this->assertStringContainsString('UPDATE chat_read_receipts', $before,
                "$type must clear chat_read_receipts.last_read_message_id or the DELETE raises 23503");
            $this->assertStringContainsString('UPDATE conversation_participants', $before,
                "$type must clear the conversation_participants watermark too");
            $this->assertStringContainsString('last_read_message_id = NULL', $before);
        }
    }

    public function testNeitherChatPolicyShipsArmed(): void
    {
        // Every seeded policy in this system is auto_delete FALSE — declare, don't
        // destroy. Purging needs BOTH --purge and an armed policy; shipping one
        // armed would remove half of that deliberateness without anyone asking.
        $sql = $this->migration();

        $this->assertSame(
            2,
            preg_match_all('/\bFALSE,\s*NOW\(\),\s*NOW\(\)/i', $sql),
            'both seeded chat policies must set auto_delete = FALSE'
        );
        $this->assertSame(0, preg_match_all('/\bTRUE,\s*NOW\(\),\s*NOW\(\)/i', $sql));
    }

    public function testMigrationIsRerunnable(): void
    {
        // Migrations are applied by hand; a double-apply must not duplicate rows.
        $this->assertSame(2, preg_match_all('/WHERE NOT EXISTS/i', $this->migration()));
    }

    public function testEveryPlanDeletesFromOneTableAndCountsWithoutDeleting(): void
    {
        foreach ($this->plans as $type => $plan) {
            $this->assertStringStartsWith('SELECT count(*)', trim($plan['count']),
                "$type: count must count");
            $this->assertDoesNotMatchRegularExpression('/\bDELETE\b/i', $plan['count'],
                "$type: the count query must never delete");
            $this->assertMatchesRegularExpression('/^DELETE FROM/i', trim($plan['delete']),
                "$type: delete must be a DELETE");
        }
    }

    public function testEveryStatementBindsOnlyTheDaysParameter(): void
    {
        // The runner executes each statement with exactly [':days' => n]. Any other
        // placeholder throws at purge time, which is the worst moment to find out.
        foreach ($this->plans as $type => $plan) {
            $statements = array_merge(
                [$plan['count'], $plan['delete']],
                $plan['before'] ?? []
            );
            foreach ($statements as $sql) {
                // (?<!:) so PostgreSQL's `::interval` cast is not read as a placeholder.
                preg_match_all('/(?<!:):([a-z_]+)/i', $sql, $m);
                $this->assertSame(
                    ['days'],
                    array_values(array_unique($m[1])),
                    "$type: statements may only bind :days"
                );
            }
        }
    }
}
