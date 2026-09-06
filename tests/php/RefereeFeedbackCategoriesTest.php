<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/referee_feedback.php';

/**
 * The referee-feedback category list exists in two languages with no codegen
 * step between them: TE_REFEREE_FEEDBACK_CATEGORIES (what the server accepts)
 * and REFEREE_FEEDBACK_CATEGORIES in
 * frontend/src/constants/refereeFeedbackCategories.ts (what the modal offers).
 * A tag offered on the client that the server refuses is a 422 on every submit
 * that ticks it, so the two are locked together here — same shape as
 * JerseySizeConsistencyTest.
 */
class RefereeFeedbackCategoriesTest extends TestCase
{
    private const TS_PATH = __DIR__ . '/../../frontend/src/constants/refereeFeedbackCategories.ts';

    private function tsValues(): array
    {
        $src = file_get_contents(self::TS_PATH);
        $this->assertNotFalse($src, 'refereeFeedbackCategories.ts must be readable');
        preg_match_all("/\{\s*value:\s*'([^']+)',\s*label:\s*'([^']+)'/", $src, $m);
        $this->assertNotEmpty($m[1], 'could not parse REFEREE_FEEDBACK_CATEGORIES out of the TS source');
        return $m[1];
    }

    public function testTypescriptAndPhpAgreeOnTheCategoryListAndItsOrder(): void
    {
        $this->assertSame(
            TE_REFEREE_FEEDBACK_CATEGORIES,
            $this->tsValues(),
            'refereeFeedbackCategories.ts and TE_REFEREE_FEEDBACK_CATEGORIES have drifted'
        );
    }

    public function testEveryCategoryIsALowercaseSlug(): void
    {
        foreach (TE_REFEREE_FEEDBACK_CATEGORIES as $c) {
            $this->assertMatchesRegularExpression('/^[a-z_]+$/', $c);
        }
        $this->assertSame(array_unique(TE_REFEREE_FEEDBACK_CATEGORIES), TE_REFEREE_FEEDBACK_CATEGORIES);
    }

    public function testMigration095MentionsTheOneListsHome(): void
    {
        $sql = file_get_contents(__DIR__ . '/../../database/migrations/095_referee_feedback.sql');
        $this->assertNotFalse($sql);
        $this->assertStringContainsString('lib/referee_feedback.php', $sql);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS referee_feedback', $sql);
        $this->assertStringContainsString('UNIQUE (calendar_event_id, submitted_by, referee_name)', $sql);
    }
}
