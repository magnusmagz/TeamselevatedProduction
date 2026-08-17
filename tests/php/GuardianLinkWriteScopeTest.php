<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Attaching an adult to a child is a staff write, and it must be recorded.
 *
 * Three routes in index.php reached AthleteController with no authentication of any
 * kind — index.php performs none, and the controller called resolveAuth() in exactly
 * one of its methods (getAthlete). Confirmed against production on 2026-08-17:
 *
 *     POST   /api/athletes/999/guardians      -> 500
 *     DELETE /api/athletes/999/guardians/999  -> 200
 *
 * The DELETE reached `DELETE FROM athlete_guardians` with both ids taken from the URL
 * and no token. Two integers detached a parent from a child. No frontend code calls
 * these routes, which is exactly why it went unnoticed for so long — and is the same
 * lesson as legacy/guardian-gateway.php: the absence of a UI is not an access control.
 *
 * This is the most plausible mechanism for the guardian link that let Jaia Hanks
 * record consent for Sebastian Luna on 2026-07-31 and then disappeared. Unprovable,
 * because nothing audited it — which is what migration 070 now fixes.
 *
 * Parse-based on purpose: the predicates were never wrong, which one got called was.
 */
class GuardianLinkWriteScopeTest extends TestCase
{
    private const CONTROLLER = __DIR__ . '/../../controllers/AthleteController.php';
    private const MIGRATION = __DIR__ . '/../../database/migrations/070_athlete_guardians_audit.sql';

    /**
     * Strip comments before any "must NOT contain" assertion.
     *
     * The comments in this codebase explain which predicate is wrong and why, so they
     * name it — and a scanner that reads prose flags the documentation as the defect.
     * Both negative assertions below failed on their own explanatory comments the
     * first time. Same lesson as MysqlOnlySqlTest needing a SQL keyword near the match
     * and QueriedTablesExistTest scanning SQL literals rather than English.
     */
    private function code(string $src): string
    {
        // token_get_all treats input without an opening tag as inline HTML, which
        // would return the comments intact — so a fragment (a method body) needs one.
        if (strpos($src, '<?php') === false) {
            $src = "<?php\n" . $src;
        }

        $out = '';
        foreach (token_get_all($src) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }
        return $out;
    }

    /** Method bodies, keyed by name, from a brace-counted scan. */
    private function methods(string $path): array
    {
        $src = file_get_contents($path);
        $out = [];

        if (!preg_match_all('/function\s+(\w+)\s*\([^)]*\)\s*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
            return $out;
        }

        foreach ($m[1] as $i => $hit) {
            $name = $hit[0];
            $start = $m[0][$i][1] + strlen($m[0][$i][0]) - 1;
            $depth = 0;
            $len = strlen($src);
            for ($p = $start; $p < $len; $p++) {
                if ($src[$p] === '{') $depth++;
                elseif ($src[$p] === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $out[$name] = substr($src, $start, $p - $start + 1);
                        break;
                    }
                }
            }
        }

        return $out;
    }

    public function testGuardianLinkWritesAuthenticate(): void
    {
        $methods = $this->methods(self::CONTROLLER);

        foreach (['createAthlete', 'addGuardian', 'removeGuardian'] as $name) {
            $this->assertArrayHasKey($name, $methods, "AthleteController::{$name} not found");
            $this->assertStringContainsString(
                'resolveAuth()',
                $methods[$name],
                "AthleteController::{$name} does not authenticate. index.php has no auth "
                . "layer, so an unauthenticated caller reaches this method directly."
            );
        }
    }

    /**
     * The READ predicate must never gate these. Its guardian branch would let one
     * parent attach or detach anyone on their own child — including removing the
     * other parent.
     */
    public function testGuardianLinkWritesUseTheStaffPredicate(): void
    {
        $methods = $this->methods(self::CONTROLLER);

        foreach (['addGuardian', 'removeGuardian'] as $name) {
            $this->assertStringContainsString(
                'staffCanManageAthlete',
                $methods[$name],
                "AthleteController::{$name} must gate on staffCanManageAthlete."
            );
            $this->assertStringNotContainsString(
                'userCanAccessAthlete',
                $this->code($methods[$name]),
                "AthleteController::{$name} gates on the READ predicate. A guardian "
                . "passes it, so a parent could detach the other parent from their child."
            );
        }
    }

    /**
     * Attribution has to be set before the write, or the trigger records NULL and the
     * audit trail says only that *someone* re-parented a child.
     */
    public function testGuardianLinkWritesIdentifyTheActorToTheDatabase(): void
    {
        $methods = $this->methods(self::CONTROLLER);

        foreach (['createAthlete', 'addGuardian', 'removeGuardian'] as $name) {
            $this->assertStringContainsString(
                'te_db_set_actor',
                $methods[$name],
                "AthleteController::{$name} must call te_db_set_actor so migration 070's "
                . "trigger can attribute the change."
            );
        }
    }

    /**
     * The interactive gateways that create and delete links must attribute too.
     * registrations-api.php is deliberately absent: a public registration has no
     * signed-in operator, and a NULL actor there is the honest record.
     */
    public function testGuardianGatewaysIdentifyTheActor(): void
    {
        foreach (['legacy/guardian-gateway.php', 'legacy/athletes-gateway.php'] as $rel) {
            $src = file_get_contents(__DIR__ . '/../../' . $rel);
            $this->assertStringContainsString(
                'te_db_set_actor(',
                $src,
                "{$rel} mutates athlete_guardians and must identify the actor."
            );
        }
    }

    public function testMigration070InstallsTheAuditTrigger(): void
    {
        $this->assertFileExists(self::MIGRATION);
        $sql = file_get_contents(self::MIGRATION);

        $this->assertStringContainsString('CREATE TRIGGER athlete_guardians_audit', $sql);
        $this->assertMatchesRegularExpression(
            '/AFTER\s+INSERT\s+OR\s+UPDATE\s+OR\s+DELETE\s+ON\s+athlete_guardians/i',
            $sql,
            'The trigger must cover all three operations. A delete-only trigger cannot '
            . 'show how an adult became attached to a child.'
        );

        foreach (['guardian_link_added', 'guardian_link_removed', 'guardian_link_changed'] as $action) {
            $this->assertStringContainsString($action, $sql);
        }

        // BEFORE would record writes that a later constraint aborts.
        $this->assertDoesNotMatchRegularExpression(
            '/BEFORE\s+INSERT\s+OR\s+UPDATE\s+OR\s+DELETE\s+ON\s+athlete_guardians/i',
            $sql
        );

        // current_setting must be the missing_ok form, or every insert on an
        // uninstrumented path raises instead of recording a NULL actor.
        $this->assertStringContainsString(
            "current_setting('app.user_id', true)",
            $sql,
            'Use the missing_ok form of current_setting; the strict form throws when unset.'
        );
    }

    /**
     * Session scope, not SET LOCAL. Most of these gateways write outside an explicit
     * transaction, where SET LOCAL is discarded immediately and every audit row would
     * silently lose its actor.
     */
    public function testActorIsSetAtSessionScope(): void
    {
        $src = file_get_contents(__DIR__ . '/../../lib/db_actor.php');
        $code = $this->code($src);
        $this->assertStringContainsString("set_config", $src);
        $this->assertStringContainsString("'app.user_id'", $src);
        $this->assertStringNotContainsString('SET LOCAL', $code);
    }
}
