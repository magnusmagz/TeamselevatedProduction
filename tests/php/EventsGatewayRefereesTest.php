<?php

use PHPUnit\Framework\TestCase;

/**
 * legacy/events-gateway.php cannot be loaded lib-only (it authenticates and
 * connects at load), so the referee wiring it gained on 2026-09-08 is pinned
 * by parsing it — the way the guardian-link and athlete-write tests pin their
 * gateways. The behaviour behind each call is executed in RefereesTest
 * (te_game_referees_apply, te_game_referee_status_*). Also pins migration 099's
 * one non-additive step.
 */
class EventsGatewayRefereesTest extends TestCase
{
    private const GATEWAY = __DIR__ . '/../../legacy/events-gateway.php';
    private const MIGRATION = __DIR__ . '/../../database/migrations/099_referees.sql';

    private function postBranch(): string
    {
        $src = file_get_contents(self::GATEWAY);
        $start = strpos($src, "case 'POST':");
        $end = strpos($src, "case 'PUT':", $start);
        return substr($src, $start, $end - $start);
    }

    private function putBranch(): string
    {
        $src = file_get_contents(self::GATEWAY);
        $start = strpos($src, "case 'PUT':");
        $end = strpos($src, "case 'DELETE':", $start);
        return substr($src, $start, $end - $start);
    }

    public function testCreateAppliesRefereesInTheSameRequestAndOnlyWhenSent(): void
    {
        $post = $this->postBranch();
        $this->assertStringContainsString("array_key_exists('referees', \$data)", $post, 'absent means ignored — older bundles keep working');
        $this->assertStringContainsString('te_game_referees_apply(', $post);
        $this->assertMatchesRegularExpression('/te_game_referees_apply\([^;]*,\s*false\)/', $post, 'create assigns; it has nothing to replace');
        $this->assertStringContainsString('catch (InvalidArgumentException $e)', $post, 'a bad referee entry is a 422 and rolls the create back');
        $this->assertStringContainsString('http_response_code(422)', $post);
        // Validation of the list happens BEFORE the transaction opens.
        $this->assertLessThan(strpos($post, '$pdo->beginTransaction()'), strpos($post, "array_key_exists('referees', \$data)"));
    }

    public function testEditReplacesRefereesOnlyWhenTheKeyIsPresent(): void
    {
        $put = $this->putBranch();
        $this->assertStringContainsString("array_key_exists('referees', \$data)", $put);
        $this->assertStringContainsString('if ($refereeList !== null)', $put, 'null / absent leaves assignments alone');
        $this->assertMatchesRegularExpression('/te_game_referees_apply\([^;]*,\s*true\)/', $put, 'edit REPLACES the list');
    }

    public function testMinimumGradeIsOptionalAndProbedOnBothWrites(): void
    {
        foreach ([$this->postBranch(), $this->putBranch()] as $branch) {
            $this->assertStringContainsString('te_game_min_grade(', $branch, 'validated against the four grades or Any');
            $this->assertStringContainsString('te_min_referee_grade_column_present(', $branch, 'the column may not exist yet');
        }
        $this->assertStringContainsString("array_key_exists('min_referee_grade', \$data)", $this->putBranch(), 'an absent key must keep the stored value');
        $this->assertStringContainsString("array_key_exists('allow_referee_self_assign', \$data)", $this->putBranch(), 'the toggle too');
        $this->assertStringContainsString('te_game_self_assign_flag(', $this->postBranch());
        $this->assertStringContainsString('allow_referee_self_assign', file_get_contents(self::MIGRATION));
        $this->assertStringContainsString('conflict_override', file_get_contents(self::MIGRATION));
    }

    public function testTheListCarriesRefereeStatusFromOneQuery(): void
    {
        $src = file_get_contents(self::GATEWAY);
        $this->assertStringContainsString('te_game_referee_status_columns($pdo', $src, 'subselects in the SAME query');
        $this->assertStringContainsString('te_game_referee_status_apply(', $src);
        // Nothing per-row: the only game_referees reads in this file are inside the shared fragment.
        $this->assertStringNotContainsString('FROM game_referees', $src);
    }

    public function testMigration099SwapsTheCheckByNameLookupAndFailsLoudlyOtherwise(): void
    {
        $sql = file_get_contents(self::MIGRATION);
        $this->assertStringContainsString("conrelid = 'user_club_access'::regclass", $sql);
        $this->assertStringContainsString('IF matched <> 1 THEN', $sql);
        $this->assertStringContainsString('RAISE EXCEPTION', $sql, 'a wrong constraint name must abort, never add a second constraint');
        $this->assertStringContainsString("DROP CONSTRAINT %I", $sql);
        $this->assertMatchesRegularExpression("/'club_admin', 'coach', 'parent', 'player', 'volunteer', 'treasurer', 'referee'/", $sql, 'the six live values plus referee');
        foreach (['CREATE TABLE IF NOT EXISTS referees', 'CREATE TABLE IF NOT EXISTS game_referees',
                  'ADD COLUMN IF NOT EXISTS referee_id', 'ADD COLUMN IF NOT EXISTS min_referee_grade',
                  'self_assigned', 'grade_override', 'REVERSE:'] as $needle) {
            $this->assertStringContainsString($needle, $sql);
        }
        $this->assertStringNotContainsString('DROP TABLE ' . 'tournament_referees', $sql, 'the tournament module\'s table is a different thing');
    }
}
