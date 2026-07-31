<?php

use PHPUnit\Framework\TestCase;

/**
 * The moderation review queue — api/chat-moderation.php.
 *
 * Source-level, like SchemaConformanceTest and AnalyticsOverviewContractTest:
 * the file exits on `requireAuth()` so it cannot be included in-process, and the
 * properties worth locking are structural rather than behavioural.
 *
 * What matters here is the refusals — who cannot review, and what the queue must
 * never echo back.
 */
class ChatModerationQueueTest extends TestCase
{
    private string $src;
    private string $schema;

    protected function setUp(): void
    {
        $this->src = file_get_contents(__DIR__ . '/../../api/chat-moderation.php');
        $this->schema = file_get_contents(__DIR__ . '/../fixtures/production-schema.json');
    }

    /**
     * The single most important property: a queue that echoed removed content
     * back to an admin would be the one hole in removal.
     */
    public function testTheQueueNeverReturnsTextOfARemovedMessage(): void
    {
        $this->assertStringContainsString(
            'CASE WHEN m.deleted_at IS NULL THEN m.message_text ELSE NULL END',
            $this->src,
            'removed message text must be nulled in SQL, not filtered in PHP'
        );

        // And there must be no other bare selection of message_text.
        preg_match_all('/m\.message_text/', $this->src, $hits);
        $this->assertCount(1, $hits[0], 'message_text may appear exactly once, inside the CASE');
    }

    public function testCoachesCannotReview(): void
    {
        // A coach reviewing reports about their own conduct is precisely the
        // situation moderation exists for.
        $this->assertMatchesRegularExpression(
            "/hasRole\('club_admin'\)/",
            $this->src
        );
        $this->assertStringNotContainsString("hasRole('coach')", $this->src);
        $this->assertStringNotContainsString("'coach'", $this->src);
    }

    public function testStandingIsCheckedByRoleNotActiveContext(): void
    {
        // getActiveContext() returns array-or-null since SEC-11, and a club admin
        // whose active context is a team would be refused their own queue.
        $this->assertStringNotContainsString("getActiveContext()['role']", $this->src);
        $this->assertStringContainsString('hasRole(', $this->src);
    }

    public function testEveryQueueReadIsClubScoped(): void
    {
        // Either an explicit club (access-checked) or the caller's own clubs.
        $this->assertStringContainsString('canAccessClub', $this->src);
        $this->assertStringContainsString('getAccessibleClubIds', $this->src);
    }

    public function testASuperAdminIsTheOnlyUnscopedReader(): void
    {
        $this->assertStringContainsString('isSuperAdmin()', $this->src);
    }

    public function testDismissRequiresPost(): void
    {
        $this->assertStringContainsString("REQUEST_METHOD'] !== 'POST'", $this->src);
    }

    public function testClosingAReportIsIdempotent(): void
    {
        // Only an open report moves, so a second click cannot rewrite who
        // reviewed it or when — the distinction between "nobody looked" and
        // "someone looked and judged it fine" has to survive.
        $this->assertMatchesRegularExpression(
            "/WHERE id = \? AND status = 'open'/",
            $this->src
        );
    }

    public function testClosingAReportIsAudited(): void
    {
        $this->assertStringContainsString('AuditLogger::log', $this->src);
        $this->assertStringContainsString("'chat_report_' . \$newStatus", $this->src);
    }

    public function testTheQueueDoesNotRemoveOrDeleteAnything(): void
    {
        // Removal stays on the chat server's socket, which is what broadcasts the
        // tombstone live. Two places that can soft-delete a message is one too many.
        $this->assertDoesNotMatchRegularExpression('/DELETE\s+FROM/i', $this->src);
        $this->assertStringNotContainsString('deleted_at = NOW()', $this->src);
        $this->assertStringNotContainsString('UPDATE chat_messages', $this->src);
    }

    public function testStatusFilterIsAClosedSet(): void
    {
        $this->assertMatchesRegularExpression(
            "/in_array\(\\\$status, \['open', 'actioned', 'dismissed', 'all'\], true\)/",
            $this->src
        );
    }

    public function testQueueSurfacesItsOwnHealth(): void
    {
        // An unactioned flag sitting for months is discoverable evidence that
        // someone was told and did nothing, so age is surfaced, not just count.
        $this->assertStringContainsString('oldest_open_at', $this->src);
        $this->assertStringContainsString('open_count', $this->src);
    }

    public function testOldestOpenItemsSortFirstWithinASeverity(): void
    {
        $this->assertStringContainsString('r.created_at ASC', $this->src);
        $this->assertStringContainsString("(r.status = 'open') DESC", $this->src);
    }

    /** Every column read here must exist in the committed Neon snapshot. */
    public function testColumnsExistInTheSchemaSnapshot(): void
    {
        $tables = json_decode($this->schema, true);
        $tables = $tables['tables'] ?? $tables;

        $this->assertArrayHasKey('chat_message_reports', $tables);
        foreach (['id', 'message_id', 'conversation_id', 'club_id', 'source', 'rule',
                  'severity', 'note', 'status', 'reported_by', 'reviewed_by',
                  'reviewed_at', 'created_at'] as $col) {
            $this->assertContains($col, $tables['chat_message_reports'], "chat_message_reports.$col");
        }
        foreach (['deleted_at', 'deleted_by', 'removal_reason', 'message_text'] as $col) {
            $this->assertContains($col, $tables['chat_messages'], "chat_messages.$col");
        }
    }
}
