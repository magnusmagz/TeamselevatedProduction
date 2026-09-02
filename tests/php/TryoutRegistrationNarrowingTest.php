<?php

use PHPUnit\Framework\TestCase;

if (!defined('TE_TRYOUTS_LIB_ONLY')) {
    define('TE_TRYOUTS_LIB_ONLY', true);
}
require_once __DIR__ . '/../../registration/tryouts-api.php';

/**
 * R84 — GET ?path=registrations narrows a coach to their own age groups.
 *
 * Reported by CKU: a coach opening the Tryouts tab saw every registrant in the
 * club, hundreds of families deep, and had to find their own group by eye. The
 * authorization half landed 2026-09-02 (a coach must be staff in the owning
 * club); this is the product half, which waited on the age rule being decided.
 *
 * Two failure modes this pins, both of which look like success:
 *  - a coach with no team seeing EVERYTHING because an empty scope was read as
 *    "no filter" (the shape that emptied the chat typeahead in reverse), and
 *  - a coach seeing NOTHING because 'U12' was compared against a stored '12U'.
 */
class TryoutRegistrationNarrowingTest extends TestCase
{
    private const PROGRAM = 900;
    private const CLUB    = 51;

    /** Season 2027 (1 Aug 2026 – 31 Jul 2027) is the reference throughout. */
    private const ON_DATE = '2026-08-15';

    /**
     * teams.age_group is free text; the three spellings below are the ones
     * production actually holds.
     */
    private function db(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("
            CREATE TABLE programs (id INTEGER PRIMARY KEY, club_id INTEGER, start_date TEXT);
            CREATE TABLE teams (
                id INTEGER PRIMARY KEY, club_id INTEGER, age_group TEXT, primary_coach_id INTEGER
            );
            CREATE TABLE team_members (
                id INTEGER PRIMARY KEY, team_id INTEGER, user_id INTEGER, role TEXT, status TEXT
            );

            INSERT INTO programs (id, club_id, start_date) VALUES (900, 51, '2026-08-15');

            -- coach 10 runs the U12s; the label is stored as 'U-12'.
            INSERT INTO teams (id, club_id, age_group, primary_coach_id) VALUES (1, 51, 'U-12', 10);
            -- coach 11 is an assistant on the U14s, stored as '14U'.
            INSERT INTO teams (id, club_id, age_group, primary_coach_id) VALUES (2, 51, '14U', 99);
            INSERT INTO team_members (id, team_id, user_id, role, status)
                VALUES (1, 2, 11, 'assistant_coach', 'active');
            -- coach 12 has no team at all.
            -- A team whose group is not a single U-group contributes nothing.
            INSERT INTO teams (id, club_id, age_group, primary_coach_id) VALUES (3, 51, 'Open', 13);
        ");

        return $pdo;
    }

    private function auth(int $userId, string $role, int $clubId = self::CLUB): AuthMiddleware
    {
        return AuthMiddleware::fromContext([
            'user_id' => $userId,
            'system_role' => 'user',
            'roles' => [
                ['role' => $role, 'scope_id' => $clubId, 'scope_type' => 'club'],
            ],
        ]);
    }

    /**
     * Season 2027: U12 is born 2015, U13 born 2014, U14 born 2013.
     *
     * @return array<int, array<string, mixed>>
     */
    private function registrations(): array
    {
        return [
            ['id' => 1, 'first_name' => 'Ana',   'date_of_birth' => '2015-03-02'], // U12
            ['id' => 2, 'first_name' => 'Ben',   'date_of_birth' => '2015-11-30'], // U12
            ['id' => 3, 'first_name' => 'Cara',  'date_of_birth' => '2014-06-15'], // U13
            ['id' => 4, 'first_name' => 'Dev',   'date_of_birth' => '2013-01-01'], // U14
            ['id' => 5, 'first_name' => 'Eli',   'date_of_birth' => null],         // no DOB
        ];
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function ids(array $rows): array
    {
        return array_column($rows, 'id');
    }

    private function scope($db, $auth, string $standing, bool $optOut = false): array
    {
        return tryout_narrowRegistrationsForCaller(
            $db,
            $auth,
            $standing,
            self::CLUB,
            $this->registrations(),
            self::ON_DATE,
            $optOut
        );
    }

    /**
     * The reported case. A U12 coach gets the two U12 registrants and no one
     * else — including, deliberately, not the athlete with no DOB.
     */
    public function testACoachSeesOnlyTheirOwnAgeGroup(): void
    {
        $result = $this->scope($this->db(), $this->auth(10, 'coach'), 'staff');

        $this->assertSame([1, 2], $this->ids($result['registrations']));
        $this->assertTrue($result['narrowed']);
        $this->assertSame(['U12'], $result['age_groups']);
    }

    /**
     * ⚠️ 'U-12' on the team and 'U12' derived from the DOB are the same group.
     * Comparing them raw matches nothing, and an empty list reads as "no one
     * registered for your group" rather than as a broken filter.
     */
    public function testStoredLabelFormatsNormaliseOnBothSides(): void
    {
        $db = $this->db();

        // '14U' on team 2, reached through an assistant_coach membership.
        $result = $this->scope($db, $this->auth(11, 'coach'), 'staff');
        $this->assertSame([4], $this->ids($result['registrations']));
        $this->assertSame(['U14'], $result['age_groups']);

        // Every spelling of the same group must produce the same answer.
        foreach (['U12', 'u 12', '12U', '12-U', 'Under 12'] as $spelling) {
            $db->prepare("UPDATE teams SET age_group = ? WHERE id = 1")->execute([$spelling]);
            $again = $this->scope($db, $this->auth(10, 'coach'), 'staff');
            $this->assertSame(['U12'], $again['age_groups'], "team age_group stored as '$spelling'");
            $this->assertSame([1, 2], $this->ids($again['registrations']), "team age_group stored as '$spelling'");
        }
    }

    /**
     * The director asks a coach to help with another group's evaluations. A
     * filter with no way off is a filter people route around by borrowing an
     * admin login, so `?all=1` opts out — and says so in the response.
     */
    public function testAllOptsACoachOutOfNarrowing(): void
    {
        $result = $this->scope($this->db(), $this->auth(10, 'coach'), 'staff', true);

        $this->assertSame([1, 2, 3, 4, 5], $this->ids($result['registrations']));
        $this->assertFalse($result['narrowed']);
        $this->assertSame(
            ['U12'],
            $result['age_groups'],
            'Opting out still reports whose list this would have been.'
        );
    }

    /**
     * A club admin runs the tryout. Narrowing them would break the job, and
     * `age_groups` is empty because no narrowing concept applies — not because
     * they coach nothing.
     */
    public function testAClubAdminSeesEveryone(): void
    {
        $result = $this->scope($this->db(), $this->auth(20, 'club_admin'), 'admin');

        $this->assertSame([1, 2, 3, 4, 5], $this->ids($result['registrations']));
        $this->assertFalse($result['narrowed']);
        $this->assertSame([], $result['age_groups']);
    }

    /**
     * ⚠️ The one that must not regress into "no filter". An empty scope and
     * "everything" are opposite answers; a coach with no team assigned sees
     * nobody, and `narrowed` says why the list is empty.
     */
    public function testACoachWithNoTeamSeesNobodyRatherThanEverybody(): void
    {
        $result = $this->scope($this->db(), $this->auth(12, 'coach'), 'staff');

        $this->assertSame([], $this->ids($result['registrations']));
        $this->assertTrue($result['narrowed']);
        $this->assertSame([], $result['age_groups']);
    }

    /**
     * A team labelled 'Open' or 'U10/U11' is not a single age group. It must
     * contribute nothing rather than silently resolving to one of its halves —
     * and it must not throw, which would 500 the whole tab.
     */
    public function testATeamWithNoSingleAgeGroupContributesNothing(): void
    {
        $result = $this->scope($this->db(), $this->auth(13, 'coach'), 'staff');

        $this->assertSame([], $this->ids($result['registrations']));
        $this->assertTrue($result['narrowed']);
        $this->assertSame([], $result['age_groups']);
    }

    /**
     * The season boundary reaches this endpoint. The same coach and the same
     * registrants produce a different list either side of 1 Aug, because the
     * group each athlete is trying out FOR has moved up.
     */
    public function testTheSeasonBoundaryMovesWhoACoachSees(): void
    {
        $db = $this->db();
        $auth = $this->auth(10, 'coach');

        $before = tryout_narrowRegistrationsForCaller(
            $db, $auth, 'staff', self::CLUB, $this->registrations(), '2026-07-31', false
        );
        $after = tryout_narrowRegistrationsForCaller(
            $db, $auth, 'staff', self::CLUB, $this->registrations(), '2026-08-01', false
        );

        // Season 2026: U12 is born 2014. Season 2027: U12 is born 2015.
        $this->assertSame([3], $this->ids($before['registrations']));
        $this->assertSame([1, 2], $this->ids($after['registrations']));
    }

    // ------------------------------------------------------------------
    // The handler, by parse.
    // ------------------------------------------------------------------

    /** The body of the `registrations` case, comments stripped. */
    private function caseBody(): string
    {
        $src = file_get_contents(__DIR__ . '/../../registration/tryouts-api.php');
        $code = '';
        foreach (token_get_all($src) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }

        $start = strpos($code, "case 'registrations':");
        $this->assertNotFalse($start, "handleGet has no case 'registrations'");
        $end = strpos($code, "case 'evaluations':", $start);
        $this->assertNotFalse($end);

        return substr($code, $start, $end - $start);
    }

    /**
     * ⚠️ Narrowing is a PRODUCT filter and never a substitute for the scope
     * check. `tryout_requireClubStaff` must still run, and still run first —
     * otherwise a coach at another club gets a politely narrowed view of a
     * tryout they have no standing on.
     */
    public function testTheCaseStillGatesOnClubStaffBeforeAnythingElse(): void
    {
        $body = $this->caseBody();

        $guard = strpos($body, 'tryout_requireClubStaff(');
        $this->assertNotFalse($guard, "case 'registrations' must call tryout_requireClubStaff.");

        $query = strpos($body, '$connection->prepare(');
        $this->assertNotFalse($query);
        $this->assertLessThan($query, $guard, 'The gate must precede the query.');

        $narrow = strpos($body, 'tryout_narrowRegistrationsForCaller(');
        $this->assertNotFalse($narrow, 'The R84 narrowing must be wired into the case.');
        $this->assertLessThan($narrow, $guard, 'The gate must precede the narrowing.');
    }

    /**
     * The Phase 6 TODO said the age rule was unresolved. It is resolved; the
     * comment must not survive to tell the next reader otherwise.
     */
    public function testThePhaseSixTodoIsGone(): void
    {
        $src = file_get_contents(__DIR__ . '/../../registration/tryouts-api.php');

        $this->assertStringNotContainsString('TODO (Phase 6)', $src);
        $this->assertStringNotContainsString('Pick one before filtering on it', $src);
    }

    /**
     * The response carries the two fields the UI needs to tell "your group has
     * three registrants" apart from "the club has three registrants".
     */
    public function testTheResponseReportsWhetherItWasNarrowed(): void
    {
        $body = $this->caseBody();

        $this->assertStringContainsString("'narrowed'", $body);
        $this->assertStringContainsString("'age_groups'", $body);
        $this->assertStringContainsString("'registrations'", $body);
    }

    /**
     * Coach team scoping is getCoachTeamIds() and nowhere else — it counts
     * assistant_coach / team_manager memberships, which teams.primary_coach_id
     * alone does not. Re-deriving it here is what undercounted Luis Escamilla.
     */
    public function testCoachTeamsComeFromTheSharedPredicate(): void
    {
        $src = file_get_contents(__DIR__ . '/../../registration/tryouts-api.php');
        $start = strpos($src, 'function tryout_narrowRegistrationsForCaller(');
        $this->assertNotFalse($start);
        $body = substr($src, $start, 3000);

        $this->assertStringContainsString('getCoachTeamIds(', $body);
        $this->assertStringNotContainsString('primary_coach_id', $body);
    }
}
