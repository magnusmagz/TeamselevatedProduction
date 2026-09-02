<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/JWT.php';
require_once __DIR__ . '/../../lib/impersonation.php';

/**
 * The G2 token diet (TE_FEATURE_SLIM_TOKEN).
 *
 * The problem it solves is not tidiness, it is a lockout: a GOTR national admin
 * holds a role in every one of ~270 councils, the JWT embeds one object per role
 * WITH the club's name, and the resulting ~40 KB Authorization header exceeds
 * the router's limit. That user cannot sign in at all.
 *
 * The diet drops `scope_name` from `roles` and caps the array, keeping
 * `active_context` whole. Everything the token stops carrying is display data;
 * authorization is re-derived from the database on every request (SEC-11), so
 * nothing here widens or narrows access.
 *
 * Runs against in-memory SQLite; never touches production Neon.
 */
class SlimTokenTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        putenv('JWT_SECRET=slim-token-test-secret');
        putenv('JWT_ALGORITHM=HS256');
        $this->off();

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("
            CREATE TABLE users (id INTEGER PRIMARY KEY, system_role TEXT);
            CREATE TABLE club_profile (id INTEGER PRIMARY KEY, name TEXT);
            CREATE TABLE user_club_access (
                id INTEGER PRIMARY KEY, user_id INTEGER, club_profile_id INTEGER,
                role TEXT, active INTEGER, revoked_at TEXT
            );
            CREATE TABLE teams (id INTEGER PRIMARY KEY, club_id INTEGER,
                primary_coach_id INTEGER, deleted_at TEXT);
            CREATE TABLE team_members (id INTEGER PRIMARY KEY, team_id INTEGER,
                user_id INTEGER, role TEXT, status TEXT);
        ");
        $this->pdo->exec("INSERT INTO users (id, system_role) VALUES (7,'user')");
    }

    protected function tearDown(): void
    {
        $this->off();
    }

    private function on(): void
    {
        putenv('TE_FEATURE_SLIM_TOKEN=on');
        unset($_ENV['TE_FEATURE_SLIM_TOKEN']);
    }

    private function off(): void
    {
        putenv('TE_FEATURE_SLIM_TOKEN=off');
        unset($_ENV['TE_FEATURE_SLIM_TOKEN']);
    }

    /**
     * A GOTR-shaped fixture: one `club_admin` role in each of $n councils, with
     * realistic names — the names are the bulk of the fat token, so a fixture
     * with short ones would prove nothing.
     */
    private function seedCouncils(int $n): void
    {
        $this->pdo->beginTransaction();
        $club = $this->pdo->prepare('INSERT INTO club_profile (id, name) VALUES (?, ?)');
        $uca = $this->pdo->prepare(
            'INSERT INTO user_club_access (id, user_id, club_profile_id, role, active, revoked_at)
             VALUES (?, 7, ?, ?, 1, NULL)'
        );
        for ($i = 1; $i <= $n; $i++) {
            $club->execute([$i, sprintf('Girls on the Run of Greater Metropolitan Council %03d', $i)]);
            $uca->execute([$i, $i, 'club_admin']);
        }
        $this->pdo->commit();
    }

    private function mint(?int $activeClub = null): string
    {
        return JWT::generateEnhanced(
            $this->pdo, 7, 'national.admin@girlsontherun.org', 'Dana National', $activeClub, $activeClub ? 'club' : null
        );
    }

    // -------------------------------------------------------------- the size

    public function testThreeHundredRolesMintUnderFourKilobytesWithTheSwitchOn(): void
    {
        $this->seedCouncils(300);
        $this->on();

        $token = $this->mint();

        $this->assertLessThan(
            4096,
            strlen($token),
            'a 300-council admin must fit in an Authorization header; got ' . strlen($token) . ' bytes'
        );
    }

    /**
     * The fixture is realistic. Without this the size assertion above could pass
     * on a fixture that was never big in the first place.
     */
    public function testTheSameUserIsUnmanageableWithTheSwitchOff(): void
    {
        $this->seedCouncils(300);
        $this->off();

        $this->assertGreaterThan(30000, strlen($this->mint()));
    }

    public function testASmallClubIsUnaffectedBySize(): void
    {
        $this->seedCouncils(1);
        $this->on();

        $this->assertLessThan(1200, strlen($this->mint()));
    }

    // ------------------------------------------------------------- the shape

    public function testRolesLoseTheirNamesAndTheArrayIsCapped(): void
    {
        $this->seedCouncils(300);
        $this->on();

        $p = JWT::decode($this->mint());

        $this->assertCount(JWT::TOKEN_ROLE_CAP, $p->roles);
        foreach ($p->roles as $r) {
            $this->assertObjectNotHasProperty('scope_name', $r);
            $this->assertSame('club', $r->scope_type);
            $this->assertIsInt($r->scope_id);
        }
        $this->assertTrue($p->roles_truncated);
    }

    /**
     * `roles_truncated` is what lets the frontend tell "40 roles" from "40 of
     * 300". Without it a club picker silently lists a prefix.
     */
    public function testTruncatedIsAbsentWhenNothingWasDropped(): void
    {
        $this->seedCouncils(5);
        $this->on();

        $p = JWT::decode($this->mint());

        $this->assertCount(5, $p->roles);
        $this->assertObjectNotHasProperty('roles_truncated', $p);
    }

    /**
     * `active_context` keeps its name — it is one object, the nav renders from
     * it, and it must survive the cap that ate the rest of the list.
     */
    public function testTheActiveContextKeepsItsNameAndSurvivesTheCap(): void
    {
        $this->seedCouncils(300);
        $this->on();

        // Club 299 sorts well past the cap in the precedence ordering.
        $p = JWT::decode($this->mint(299));

        $this->assertSame(299, $p->active_context->scope_id);
        $this->assertSame(
            'Girls on the Run of Greater Metropolitan Council 299',
            $p->active_context->scope_name
        );
        $this->assertSame('club_admin', $p->active_context->role);

        // …and it is the first kept role, not a casualty of array_slice().
        $this->assertSame(299, $p->roles[0]->scope_id);
        $ids = array_map(fn($r) => $r->scope_id, $p->roles);
        $this->assertContains(299, $ids);
        $this->assertSame($ids, array_unique($ids), 'the active role must not also appear in the tail');
    }

    public function testTheSwitchOffLeavesTheTokenExactlyAsItWas(): void
    {
        $this->seedCouncils(3);
        $this->off();

        $p = JWT::decode($this->mint());

        $this->assertCount(3, $p->roles);
        $this->assertSame('Girls on the Run of Greater Metropolitan Council 001', $p->roles[0]->scope_name);
        $this->assertObjectNotHasProperty('roles_truncated', $p);
    }

    // ------------------------------------------------------ what must not move

    /**
     * `user_id` is a STRING in the token and a NUMBER in Postgres, and three
     * production bugs in one day came from that boundary. The diet must not
     * touch it — every existing consumer expects a string.
     */
    public function testTheUserIdClaimIsStillAString(): void
    {
        $this->seedCouncils(300);
        $this->on();

        $p = JWT::decode($this->mint());

        $this->assertIsString($p->user_id);
        $this->assertSame('7', $p->user_id);
    }

    /**
     * The impersonation claim rides on the SAME claims array the diet rewrites.
     * Dropping it here would convert an impersonation into a permanent, unmarked
     * login as the target — the exact failure ImpersonationTest exists to catch,
     * arriving through a different door.
     */
    public function testTheImpersonationClaimSurvivesTheDiet(): void
    {
        $this->seedCouncils(300);
        $this->on();
        $now = time();

        $claims = te_carry_impersonation(
            JWT::buildOrganizationalContext($this->pdo, 7, null, null),
            json_decode(json_encode(te_impersonation_claims(1, 'admin@example.com', 'Ada Admin', $now))),
            $now + 60
        );
        $token = JWT::generate(7, 'x@y.z', 'Dana National', $claims);
        $p = JWT::decode($token);

        $this->assertSame(1, $p->imp->by);
        $this->assertSame('admin@example.com', $p->imp->by_email);
        // The window did not restart, and the token dies with it.
        $this->assertSame($now + TE_IMPERSONATION_TTL, $p->exp);
        $this->assertSame($p->imp->exp, $p->exp);
        // …and the diet still happened.
        $this->assertCount(JWT::TOKEN_ROLE_CAP, $p->roles);
        $this->assertLessThan(4096, strlen($token));
    }

    /** A slim token is still a valid, verifiable token. */
    public function testASlimTokenVerifies(): void
    {
        $this->seedCouncils(300);
        $this->on();

        $p = JWT::verify($this->mint());

        $this->assertNotFalse($p);
        $this->assertSame('7', $p->user_id);
        $this->assertSame('user', $p->system_role);
    }

    /**
     * Claims with no `roles` at all (scripts that mint a bare token) pass
     * through untouched rather than growing an empty array.
     */
    public function testClaimsWithoutRolesAreLeftAlone(): void
    {
        $this->on();

        $out = JWT::applyTokenDiet(['system_role' => 'super_admin']);

        $this->assertSame(['system_role' => 'super_admin'], $out);
    }

    // ------------------------------------------------- the server is unaffected

    /**
     * The diet is a MINT-time transform. `buildOrganizationalContext()` — which
     * `AuthMiddleware::requireAuth()` uses to authorize every request — must
     * still return every role, with names, whatever the switch says. Capping
     * there would truncate `getAccessibleClubIds()`, i.e. silently remove
     * someone's clubs.
     */
    public function testAuthorizationStillSeesEveryRoleWithTheSwitchOn(): void
    {
        $this->seedCouncils(300);
        $this->on();

        $ctx = JWT::buildOrganizationalContext($this->pdo, 7, null, null);

        $this->assertCount(300, $ctx['roles']);
        $this->assertSame(
            'Girls on the Run of Greater Metropolitan Council 001',
            $ctx['roles'][0]['scope_name']
        );
    }

    /**
     * The coach derivation is a UNION of two user-bounded queries now, not a
     * LEFT JOIN over every team on the platform. It must still find a club where
     * the user's ONLY standing is a team membership — bounding it to their
     * `user_club_access` clubs instead would have deleted this case, and with it
     * every coach who has no club role row.
     */
    public function testCoachDerivationStillFindsAClubWithNoUserClubAccessRow(): void
    {
        $this->pdo->exec("INSERT INTO club_profile (id,name) VALUES (51,'Central Kansas United'), (32,'Teams Elevated')");
        // Primary coach of a team in 51; assistant coach on a team in 32.
        $this->pdo->exec("INSERT INTO teams (id, club_id, primary_coach_id, deleted_at) VALUES
            (1, 51, 7, NULL), (2, 32, 99, NULL), (3, 51, 99, '2026-01-01')");
        $this->pdo->exec("INSERT INTO team_members (id, team_id, user_id, role, status) VALUES
            (1, 2, 7, 'assistant_coach', 'active')");

        $ctx = JWT::buildOrganizationalContext($this->pdo, 7, null, null);
        $byClub = [];
        foreach ($ctx['roles'] as $r) {
            $byClub[$r['scope_id']] = $r['role'];
        }

        $this->assertSame(['coach', 'coach'], [$byClub[32] ?? null, $byClub[51] ?? null]);
        $this->assertSame('Central Kansas United', $ctx['roles'][array_search(51, array_column($ctx['roles'], 'scope_id'))]['scope_name']);
    }

    /** A user_club_access role still beats a derived coach role for the same club. */
    public function testAnExplicitClubRoleStillWinsOverTheDerivedCoachRole(): void
    {
        $this->pdo->exec("INSERT INTO club_profile (id,name) VALUES (51,'Central Kansas United')");
        $this->pdo->exec("INSERT INTO user_club_access (id,user_id,club_profile_id,role,active,revoked_at) VALUES (1,7,51,'club_admin',1,NULL)");
        $this->pdo->exec("INSERT INTO teams (id, club_id, primary_coach_id, deleted_at) VALUES (1, 51, 7, NULL)");

        $ctx = JWT::buildOrganizationalContext($this->pdo, 7, null, null);

        $this->assertCount(1, $ctx['roles']);
        $this->assertSame('club_admin', $ctx['roles'][0]['role']);
    }

    /** A soft-deleted team confers nothing. */
    public function testASoftDeletedTeamConfersNoCoachRole(): void
    {
        $this->pdo->exec("INSERT INTO club_profile (id,name) VALUES (51,'Central Kansas United')");
        $this->pdo->exec("INSERT INTO teams (id, club_id, primary_coach_id, deleted_at) VALUES (1, 51, 7, '2026-01-01')");

        $ctx = JWT::buildOrganizationalContext($this->pdo, 7, null, null);

        $this->assertSame([], $ctx['roles']);
    }
}
