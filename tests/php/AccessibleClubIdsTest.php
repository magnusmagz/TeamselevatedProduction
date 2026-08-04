<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;
use AuthMiddleware;

/**
 * `getAccessibleClubIds()` must return a list PDO can bind.
 *
 * Reported 2026-08-04: eli@teamselevated.com clicked Facilities and got
 * "Something went wrong", with `/legacy/venues-gateway.php` returning 500 and
 * the page dying on `t.map is not a function`.
 *
 * `array_unique()` and `array_filter()` both PRESERVE KEYS. Eli holds two roles
 * in club 32 (club_admin and coach), so de-duplicating produced
 * `[0 => 32, 2 => 50]` — a gap at index 1. Every caller passes that array
 * straight to `PDO::execute()` as positional parameters, and PDO rejects a
 * non-sequential array.
 *
 * Ten files call this method (venues, teams, programs, coaches, fields,
 * tournaments, chat moderation), and 7 accounts hold two roles in one club — 6
 * of them Central Kansas coach+parent, the same population as the
 * financial-permissions break.
 */
class AccessibleClubIdsTest extends TestCase
{
    private function auth(array $roles): AuthMiddleware
    {
        return AuthMiddleware::fromContext([
            'user_id' => 69, 'email' => 'eli@teamselevated.com', 'roles' => $roles,
        ]);
    }

    private function clubRole(string $role, int $clubId): array
    {
        return ['role' => $role, 'scope_type' => 'club', 'scope_id' => $clubId];
    }

    /** THE REGRESSION. Two roles in one club must not leave a hole in the keys. */
    public function testTwoRolesInOneClubStillYieldsABindableList(): void
    {
        $ids = $this->auth([
            $this->clubRole('club_admin', 32),
            $this->clubRole('coach', 32),
            $this->clubRole('club_admin', 50),
        ])->getAccessibleClubIds();

        $this->assertSame([32, 50], $ids, 'de-duplicated and re-indexed');
        $this->assertSame(
            range(0, count($ids) - 1),
            array_keys($ids),
            'keys must be sequential or PDO cannot bind them'
        );
    }

    /**
     * The failure was never in the values — it was that PDO would not accept the
     * shape. Bind the real thing rather than trusting the key assertion.
     */
    public function testTheResultCanActuallyBeBoundByPdo(): void
    {
        $ids = $this->auth([
            $this->clubRole('club_admin', 32),
            $this->clubRole('coach', 32),
            $this->clubRole('club_admin', 50),
        ])->getAccessibleClubIds();

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE venues (id INTEGER, club_id INTEGER)');
        $pdo->exec('INSERT INTO venues (id, club_id) VALUES (1, 32), (2, 50), (3, 99)');

        $sql = 'SELECT id FROM venues WHERE club_id IN ('
             . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);

        $this->assertSame(
            [1, 2],
            array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'))
        );
    }

    public function testThreeRolesInTheSameClubCollapseToOne(): void
    {
        $ids = $this->auth([
            $this->clubRole('club_admin', 51),
            $this->clubRole('coach', 51),
            $this->clubRole('parent', 51),
        ])->getAccessibleClubIds();

        $this->assertSame([51], $ids);
    }

    /** A single role was never broken and must stay unaffected. */
    public function testSingleRoleIsUnchanged(): void
    {
        $this->assertSame([51], $this->auth([$this->clubRole('parent', 51)])->getAccessibleClubIds());
    }

    public function testNoClubRolesYieldsAnEmptyList(): void
    {
        $ids = $this->auth([])->getAccessibleClubIds();

        $this->assertSame([], $ids);
        // Callers branch on empty vs null — empty means "no clubs", null means
        // "super admin, unrestricted". They must not be conflated.
        $this->assertNotNull($ids);
    }

    public function testSuperAdminStillGetsNullMeaningUnrestricted(): void
    {
        $auth = AuthMiddleware::fromContext([
            'user_id' => 1, 'email' => 'super@platform.test',
            'system_role' => 'super_admin', 'roles' => [],
        ]);

        $this->assertNull($auth->getAccessibleClubIds());
    }

    /**
     * A revoked role must not be minted into a token. `active` and `revoked_at`
     * can disagree — one live row had active = TRUE with revoked_at set — and
     * when they do, the revocation is the newer fact.
     */
    public function testJwtExcludesRevokedRoles(): void
    {
        $src = file_get_contents(__DIR__ . '/../../lib/JWT.php');

        $this->assertMatchesRegularExpression(
            '/uca\.active\s*=\s*TRUE\s*AND\s*uca\.revoked_at\s+IS\s+NULL/i',
            $src,
            'the club-roles query must exclude revoked rows, not just inactive ones'
        );
    }
}
