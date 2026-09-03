<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/team_gender.php';

/**
 * teams.gender is CHECK-constrained to Male/Female/Mixed. Tournament divisions
 * describe the same idea as boys/girls/coed, so the two vocabularies meet in
 * te_normalize_team_gender() — and a value that misses the constraint fails the
 * entire team save, not just the field.
 */
class TeamGenderConsistencyTest extends TestCase
{
    private function tsUtil(): string
    {
        return file_get_contents(__DIR__ . '/../../frontend/src/utils/teamGender.ts');
    }

    public function testStoredValuesAreExactlyWhatTheConstraintAllows(): void
    {
        // Mirrors teams_gender_check in production Neon.
        $this->assertSame(['Male', 'Female', 'Mixed'], TE_TEAM_GENDERS);
    }

    public function testEveryNormalizedValueIsStorable(): void
    {
        foreach (['male', 'Boys', 'girl', 'F', 'coed', 'CO-ED', 'mixed', 'both'] as $input) {
            $this->assertContains(
                te_normalize_team_gender($input),
                TE_TEAM_GENDERS,
                "$input must resolve to a value the CHECK constraint accepts"
            );
        }
    }

    public function testDivisionVocabularyTranslates(): void
    {
        // A tournament division's gender copied onto a team would otherwise
        // raise SQLSTATE 23514 and roll back the whole save.
        $this->assertSame('Male', te_normalize_team_gender('boys'));
        $this->assertSame('Female', te_normalize_team_gender('girls'));
        $this->assertSame('Mixed', te_normalize_team_gender('coed'));
    }

    public function testNothingUsableIsNullRatherThanAGuess(): void
    {
        // Null means "the caller said nothing", which lets a create fall back to
        // the column default and an update keep the team's existing value.
        // Guessing 'Mixed' here would silently relabel a girls team.
        $this->assertNull(te_normalize_team_gender(null));
        $this->assertNull(te_normalize_team_gender(''));
        $this->assertNull(te_normalize_team_gender('   '));
        $this->assertNull(te_normalize_team_gender('U12'));
        $this->assertNull(te_normalize_team_gender(['Male']));
        $this->assertNull(te_normalize_team_gender(1));
    }

    public function testTheTypeScriptListCarriesTheSameValues(): void
    {
        $ts = $this->tsUtil();
        foreach (TE_TEAM_GENDERS as $value) {
            $this->assertStringContainsString("value: '$value'", $ts, "TEAM_GENDER_OPTIONS is missing $value");
        }
        // No option may offer a value the constraint would reject.
        preg_match_all("/value: '([^']+)'/", $ts, $m);
        $this->assertSame(TE_TEAM_GENDERS, $m[1]);
    }

    public function testTheGatewayNormalizesBothWritePaths(): void
    {
        $src = file_get_contents(__DIR__ . '/../../legacy/teams-gateway.php');
        $this->assertStringContainsString("require_once __DIR__ . '/../lib/team_gender.php';", $src);
        $this->assertSame(
            2,
            substr_count($src, 'te_normalize_team_gender('),
            'both the INSERT and the UPDATE must go through the normalizer'
        );
        // The UPDATE is a full-row SET; an absent gender must preserve the
        // stored value rather than default the team back to Mixed.
        $this->assertStringContainsString('SELECT gender FROM teams WHERE id = ?', $src);
        $this->assertStringNotContainsString("\$data['gender'] ?? 'Mixed'", $src);
    }
}
