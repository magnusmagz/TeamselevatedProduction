<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/JWT.php';

/**
 * Which role a dual-role user "is".
 *
 * `JWT::buildOrganizationalContext()` nominates `roles[0]` as the active context,
 * and the query filling that array had **no ORDER BY** — so the answer was
 * physical row order. Seven accounts hold two roles in one club, and one
 * (`club_admin` + `coach`) drives the entire admin nav through
 * `OrgContext.isClubAdmin`. Today they all land favourably by luck; a row update
 * or a vacuum could silently flip one, removing Revenue, Facilities, Documents
 * and Programs from an admin's menu with nothing in the code or their access
 * having changed.
 *
 * Backend authorization is unaffected either way — `AuthMiddleware::hasRole()`
 * iterates every role. This is only about which single role the UI reads.
 *
 * Runs against in-memory SQLite; never touches production Neon.
 */
class ActiveRolePrecedenceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
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
        $this->pdo->exec("INSERT INTO users (id, system_role) VALUES (69,'user')");
        $this->pdo->exec("INSERT INTO club_profile (id,name) VALUES (32,'Teams Elevated'), (51,'Central Kansas United')");
    }

    /** @param array<int,array{0:string,1:int}> $roles [role, clubId] in insert order */
    private function grant(array $roles): void
    {
        $i = 1;
        foreach ($roles as [$role, $clubId]) {
            $s = $this->pdo->prepare(
                'INSERT INTO user_club_access (id,user_id,club_profile_id,role,active,revoked_at)
                 VALUES (?,69,?,?,1,NULL)'
            );
            $s->execute([$i++, $clubId, $role]);
        }
    }

    private function activeRole(): ?string
    {
        $ctx = JWT::buildOrganizationalContext($this->pdo, 69, null, null);
        return $ctx['active_context']['role'] ?? null;
    }

    /**
     * The live case: Elias Ulvi, club_admin + coach in club 32. Inserted
     * coach-first so a missing ORDER BY would return 'coach'.
     */
    public function testClubAdminBeatsCoachRegardlessOfInsertOrder(): void
    {
        $this->grant([['coach', 32], ['club_admin', 32]]);

        $this->assertSame('club_admin', $this->activeRole());
    }

    /** The six CKU coach-parents, inserted parent-first. */
    public function testCoachBeatsParent(): void
    {
        $this->grant([['parent', 51], ['coach', 51]]);

        $this->assertSame('coach', $this->activeRole());
    }

    public function testTreasurerBeatsCoach(): void
    {
        $this->grant([['coach', 51], ['treasurer', 51]]);

        $this->assertSame('treasurer', $this->activeRole());
    }

    public function testVolunteerBeatsParent(): void
    {
        $this->grant([['parent', 51], ['volunteer', 51]]);

        $this->assertSame('volunteer', $this->activeRole());
    }

    public function testParentBeatsPlayer(): void
    {
        $this->grant([['player', 51], ['parent', 51]]);

        $this->assertSame('parent', $this->activeRole());
    }

    /** Full ladder in one go, inserted in exactly the wrong order. */
    public function testTheWholeLadderHolds(): void
    {
        $this->grant([
            ['player', 51], ['parent', 51], ['volunteer', 51],
            ['coach', 51], ['treasurer', 51], ['club_admin', 51],
        ]);

        $this->assertSame('club_admin', $this->activeRole());
    }

    /** A single role is unaffected — this changes ordering, not membership. */
    public function testASingleRoleIsUnchanged(): void
    {
        $this->grant([['parent', 51]]);

        $this->assertSame('parent', $this->activeRole());
    }

    /**
     * Every role still reaches the token. Precedence decides which is ACTIVE, and
     * must never drop the others — backend checks iterate the whole array.
     */
    public function testEveryRoleSurvivesTheOrdering(): void
    {
        $this->grant([['coach', 51], ['parent', 51], ['club_admin', 32]]);

        $ctx = JWT::buildOrganizationalContext($this->pdo, 69, null, null);
        $roles = array_map(fn($r) => $r['role'], $ctx['roles']);

        sort($roles);
        $this->assertSame(['club_admin', 'coach', 'parent'], $roles);
    }

    /** Ties across clubs resolve on club id rather than storage order. */
    public function testMultiClubTiesAreStable(): void
    {
        $this->grant([['coach', 51], ['coach', 32]]);

        $ctx = JWT::buildOrganizationalContext($this->pdo, 69, null, null);
        $this->assertSame(32, $ctx['active_context']['scope_id']);
    }

    /**
     * An explicitly requested club still wins — precedence only decides the
     * DEFAULT, and must not override a user's own context switch.
     */
    public function testAnExplicitlyRequestedClubStillWins(): void
    {
        $this->grant([['club_admin', 32], ['parent', 51]]);

        $ctx = JWT::buildOrganizationalContext($this->pdo, 69, 51, 'club');

        $this->assertSame(51, $ctx['active_context']['scope_id']);
        $this->assertSame('parent', $ctx['active_context']['role']);
    }
}
