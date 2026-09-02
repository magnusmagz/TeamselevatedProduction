<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;

/**
 * "Setting a different guardian as primary does not stick" (CKU, R78).
 *
 * Three separate things conspired, and this test pins the two server-side ones.
 *
 * 1. WHICH crew member is primary must have a DETERMINISTIC answer.
 *    `legacy/athletes-gateway.php` fetched an athlete's crew with a bare
 *    `ORDER BY ag.is_primary DESC` — no NULLS LAST, no tiebreak. In Postgres a
 *    DESC sort puts NULLs FIRST, so a link row with a NULL `is_primary` outranks
 *    the real primary; and with two `true` rows the order between them is
 *    whatever the plan happens to emit. The form then wrote index 0 back as the
 *    primary, so an arbitrary row was promoted on every athlete save.
 *
 * 2. Nothing may create a SECOND primary link unconditionally.
 *    `registration/registrations-api.php` and `api/athletes.php` both inserted
 *    `is_primary = TRUE` as a literal, so a family registering a second program
 *    (or an athlete created through the API after a registration) ended up with
 *    two primary links — which is what made (1) arbitrary rather than merely
 *    undefined.
 *
 * Parse-based on purpose: both findings are properties of the SQL text, and the
 * live queries hit Neon. Confirmed to fail on the pre-fix code (2026-09-02):
 * the ORDER BY assertion on the missing NULLS LAST, and both INSERT assertions
 * on the hardcoded TRUE.
 */
class PrimaryGuardianWriteTest extends TestCase
{
    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        $this->assertFileExists($path, "$relative should exist");

        return (string) file_get_contents($path);
    }

    /**
     * Every ordering of an athlete's crew by is_primary must be total: NULLS LAST
     * so an unset flag cannot outrank a real primary, and a tiebreak column so two
     * primaries still come back in a stable order.
     */
    public function testAthleteCrewOrderingIsDeterministic(): void
    {
        $source = $this->read('legacy/athletes-gateway.php');

        preg_match_all('/ORDER BY\s+ag\.is_primary\s+DESC([^\n]*)/i', $source, $matches);

        $this->assertNotEmpty(
            $matches[0],
            'legacy/athletes-gateway.php should still order crew by ag.is_primary DESC'
        );

        foreach ($matches[0] as $i => $clause) {
            $this->assertMatchesRegularExpression(
                '/NULLS\s+LAST/i',
                $clause,
                "ORDER BY ag.is_primary DESC needs NULLS LAST — in Postgres a DESC sort "
                . "puts NULL first, so an unset flag outranks the real primary: $clause"
            );
            $this->assertMatchesRegularExpression(
                '/,\s*ag\.id/i',
                $clause,
                "ORDER BY ag.is_primary DESC needs a tiebreak (ag.id) so two primaries "
                . "come back in a stable order: $clause"
            );
        }
    }

    /**
     * The single-primary LATERAL on the athlete LIST already ordered by ag.id;
     * keep it that way so the list and the detail agree on who is primary.
     */
    public function testAthleteListPrimaryLookupHasATiebreak(): void
    {
        $source = $this->read('legacy/athletes-gateway.php');

        $this->assertMatchesRegularExpression(
            '/WHERE\s+ag\.athlete_id\s*=\s*a\.id\s+AND\s+ag\.is_primary\s*=\s*true\s+ORDER BY\s+ag\.id/i',
            preg_replace('/\s+/', ' ', $source),
            'The primary-guardian LATERAL must keep ORDER BY ag.id so the list picks the '
            . 'same primary every time'
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function linkInsertSites(): array
    {
        return [
            'public registration' => ['registration/registrations-api.php'],
            'athlete create API'  => ['api/athletes.php'],
        ];
    }

    /**
     * Neither writer may hardcode a true `is_primary` on the link it inserts. An
     * athlete that already has a primary crew member must keep them; only the
     * FIRST link an athlete gets is primary.
     *
     * @dataProvider linkInsertSites
     */
    public function testGuardianLinkInsertsDoNotHardcodePrimary(string $relative): void
    {
        $source = preg_replace('/\s+/', ' ', $this->read($relative));

        preg_match_all(
            '/INSERT INTO athlete_guardians\s*\((.*?)\)\s*VALUES\s*\((.*?)\)/i',
            $source,
            $matches,
            PREG_SET_ORDER
        );

        $this->assertNotEmpty(
            $matches,
            "$relative should still INSERT INTO athlete_guardians"
        );

        foreach ($matches as $insert) {
            $columns = array_map('trim', explode(',', $insert[1]));
            $values  = array_map('trim', explode(',', $insert[2]));

            $position = array_search('is_primary', $columns, true);
            if ($position === false) {
                continue;
            }

            $this->assertArrayHasKey(
                $position,
                $values,
                "$relative: is_primary has no matching VALUES entry"
            );

            $this->assertDoesNotMatchRegularExpression(
                '/^(TRUE|true|1)$/',
                $values[$position],
                "$relative writes a literal true for athlete_guardians.is_primary. An "
                . "athlete who already has a primary crew member gets a second one, and "
                . "'who is primary' then has no answer. Bind a value that is true only "
                . "when the athlete has no primary link yet."
            );
        }
    }

    /**
     * The value each site binds has to be derived from the athlete's existing
     * links, not from the request. Both compute it the same way.
     *
     * @dataProvider linkInsertSites
     */
    public function testGuardianLinkInsertsAskWhetherAPrimaryAlreadyExists(string $relative): void
    {
        $source = preg_replace('/\s+/', ' ', $this->read($relative));

        $this->assertMatchesRegularExpression(
            '/SELECT 1 FROM athlete_guardians WHERE athlete_id = \? AND is_primary/i',
            $source,
            "$relative must look for an existing primary link before deciding whether "
            . "the link it is about to create is the primary one"
        );
    }
}
