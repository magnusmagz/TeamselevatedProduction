<?php

use PHPUnit\Framework\TestCase;

if (!defined('TE_RECIPIENT_SEARCH_LIB_ONLY')) {
    define('TE_RECIPIENT_SEARCH_LIB_ONLY', true);
}
require_once __DIR__ . '/../../api/recipient-search-gateway.php';
require_once __DIR__ . '/../../lib/program_scope.php';

/**
 * Auth double. `hasRole` answers per club, which is the whole point of the
 * cross-club case below — an admin of club 32 must not be able to staff club
 * 51's camp, and a double that ignores the club id could not show that.
 */
class FakeProgramAuth
{
    /** @param array<int, string[]> $rolesByClub */
    public function __construct(private array $rolesByClub = [], private bool $superAdmin = false) {}
    public function isSuperAdmin(): bool { return $this->superAdmin; }
    public function canAccessClub($clubProfileId): bool { return true; }
    public function hasRole($role, $clubProfileId = null, $level = null): bool
    {
        return in_array($role, $this->rolesByClub[(int)$clubProfileId] ?? [], true);
    }
}

/**
 * Program staffing (CKU R66, slice 8.1).
 *
 * Camps, clinics and drop-ins have registrants and no roster. `team_members` is
 * empty for them, so getCoachTeamIds() correctly answers "no teams" for the
 * coach running the camp and every scope built on it then answers "nobody" — the
 * program is missing from their calendar and the families who signed up are
 * unreachable from the To field.
 *
 * `program_staff` (migration 086) is the only source of program standing. These
 * tests pin the two things that make that safe: who may create a row, and that
 * the row is what widens the reads — not a role, not a club, and not a
 * registration.
 */
class ProgramStaffTest extends TestCase
{
    private const CLUB = 51;
    private const OTHER_CLUB = 32;

    private const ADMIN = 300;          // club admin of 51
    private const OTHER_ADMIN = 301;    // club admin of 32 only
    private const CAMP_COACH = 158;     // coach of 51, no team, staffs the camp
    private const IDLE_COACH = 159;     // coach of 51, no team, staffs nothing
    private const A_PARENT = 400;       // parent of 51 — never assignable

    private const CAMP = 900;           // program in club 51
    private const OTHER_CAMP = 901;     // program in club 32

    /** A connection with `program_staff`, i.e. after migration 086. */
    private function migratedPdo(): PDO
    {
        $pdo = $this->basePdo();
        $pdo->exec("CREATE TABLE program_staff (id INTEGER PRIMARY KEY, program_id INTEGER,
            user_id INTEGER, role TEXT, assigned_by INTEGER, created_at TEXT,
            UNIQUE (program_id, user_id))");
        return $pdo;
    }

    /**
     * A connection WITHOUT `program_staff` — production between the push and the
     * hand-applied migration, which is a real window because `main` is shared.
     */
    private function unmigratedPdo(): PDO
    {
        return $this->basePdo();
    }

    private function basePdo(): PDO
    {
        $pdo = new IlikeTranslatingPdo('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec("
            CREATE TABLE users (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT,
                email TEXT, phone TEXT);
            CREATE TABLE user_club_access (id INTEGER PRIMARY KEY, user_id INTEGER,
                club_profile_id INTEGER, role TEXT, active INTEGER, revoked_at TEXT);
            CREATE TABLE teams (id INTEGER PRIMARY KEY, name TEXT, age_group TEXT, club_id INTEGER,
                primary_coach_id INTEGER, deleted_at TEXT);
            CREATE TABLE team_members (id INTEGER PRIMARY KEY, team_id INTEGER, athlete_id INTEGER,
                user_id INTEGER, role TEXT, status TEXT);
            CREATE TABLE athletes (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT,
                email TEXT, phone TEXT, club_id INTEGER, deleted_at TEXT, active_status INTEGER);
            CREATE TABLE guardians (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT,
                email TEXT, mobile_phone TEXT, sms_opt_out INTEGER DEFAULT 0);
            CREATE TABLE athlete_guardians (id INTEGER PRIMARY KEY, athlete_id INTEGER, guardian_id INTEGER);
            CREATE TABLE user_guardians (id INTEGER PRIMARY KEY, user_id INTEGER, guardian_id INTEGER,
                source TEXT, confidence TEXT);
            CREATE TABLE programs (id INTEGER PRIMARY KEY, name TEXT, type TEXT, club_id INTEGER);
            CREATE TABLE registrations (id INTEGER PRIMARY KEY, program_id INTEGER,
                athlete_id INTEGER, status TEXT);
            CREATE TABLE email_suppressions (id INTEGER PRIMARY KEY, club_profile_id INTEGER,
                email TEXT, phone TEXT, channel TEXT, reason TEXT, scope TEXT, team_id INTEGER);
        ");

        $pdo->exec("INSERT INTO users (id,first_name,last_name,email,phone) VALUES
            (300,'Leya','Devora','leya@example.com','1'),
            (301,'Otherclub','Admin','otheradmin@example.com','2'),
            (158,'Morgan','Long','morgan@example.com','3'),
            (159,'Idle','Coach','idle@example.com','4'),
            (400,'Pat','Parent','pat@example.com','5')");

        $pdo->exec("INSERT INTO user_club_access (id,user_id,club_profile_id,role,active,revoked_at) VALUES
            (1,300,51,'club_admin',1,NULL),
            (2,301,32,'club_admin',1,NULL),
            (3,158,51,'coach',1,NULL),
            (4,159,51,'coach',1,NULL),
            (5,400,51,'parent',1,NULL)");

        $pdo->exec("INSERT INTO programs (id,name,type,club_id) VALUES
            (900,'Summer Camp','camp',51),
            (901,'Other Club Camp','camp',32)");

        // Camper Casey is registered to the camp and is on NO team — the whole
        // population this slice exists for. Their guardian is Dana.
        $pdo->exec("INSERT INTO athletes (id,first_name,last_name,email,phone,club_id,deleted_at,active_status)
                    VALUES (950,'Casey','Camper','casey@example.com','555',51,NULL,1)");
        $pdo->exec("INSERT INTO guardians (id,first_name,last_name,email,mobile_phone)
                    VALUES (50,'Dana','Camper','dana@example.com','5551111')");
        $pdo->exec("INSERT INTO athlete_guardians (id,athlete_id,guardian_id) VALUES (60,950,50)");
        $pdo->exec("INSERT INTO registrations (id,program_id,athlete_id,status)
                    VALUES (70,900,950,'pending')");

        // A rejected registration, and a registrant on the other club's camp.
        $pdo->exec("INSERT INTO athletes (id,first_name,last_name,email,phone,club_id,deleted_at,active_status)
                    VALUES (951,'Rex','Rejected','rex@example.com','556',51,NULL,1)");
        $pdo->exec("INSERT INTO registrations (id,program_id,athlete_id,status)
                    VALUES (71,900,951,'rejected')");

        return $pdo;
    }

    private function assign(PDO $pdo, $auth, int $userId, int $programId = self::CAMP): array
    {
        return te_program_staff_assign($pdo, $auth, $programId, $userId, 'coach', self::ADMIN);
    }

    private function admin(): FakeProgramAuth
    {
        return new FakeProgramAuth([self::CLUB => ['club_admin']]);
    }

    // ─── Who may be assigned ─────────────────────────────────────────────────

    /**
     * A program_staff row is a reach grant over every registered family's
     * contact details. Handing one to a guardian would give one family the rest
     * of the camp's phone numbers.
     */
    public function testAssignRefusesAParent(): void
    {
        $pdo = $this->migratedPdo();

        $result = $this->assign($pdo, $this->admin(), self::A_PARENT);

        $this->assertFalse($result['ok']);
        $this->assertSame('not_staff', $result['reason']);
        $this->assertSame(422, $result['status']);
        $this->assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM program_staff')->fetchColumn(),
            'a refused assignment must write no row');
    }

    /**
     * The club is read off the PROGRAM, never from the request body — the same
     * rule pg_programClubId() enforces for archive and reorder. An admin of
     * another club fails the actor check even though they are unmistakably an
     * admin somewhere.
     */
    public function testAssignRefusesACrossClubAdmin(): void
    {
        $pdo = $this->migratedPdo();
        $otherAdmin = new FakeProgramAuth([self::OTHER_CLUB => ['club_admin']]);

        $result = $this->assign($pdo, $otherAdmin, self::CAMP_COACH);

        $this->assertFalse($result['ok']);
        $this->assertSame('forbidden', $result['reason']);
        $this->assertSame(403, $result['status']);
        $this->assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM program_staff')->fetchColumn());
    }

    /** A coach with no team is still a coach. Role decides standing, not data. */
    public function testAssignAcceptsATeamlessClubCoach(): void
    {
        $pdo = $this->migratedPdo();

        $result = $this->assign($pdo, $this->admin(), self::CAMP_COACH);

        $this->assertTrue($result['ok'], 'a club coach with no team must be assignable');
        $this->assertSame([self::CAMP], te_program_ids_for_user($pdo, self::CAMP_COACH));
    }

    /** UNIQUE(program_id, user_id): a re-assign updates, it does not duplicate. */
    public function testReassigningDoesNotCreateASecondRow(): void
    {
        $pdo = $this->migratedPdo();
        $this->assign($pdo, $this->admin(), self::CAMP_COACH);

        $again = te_program_staff_assign($pdo, $this->admin(), self::CAMP, self::CAMP_COACH, 'manager', self::ADMIN);

        $this->assertTrue($again['ok']);
        $this->assertSame(1, (int)$pdo->query('SELECT COUNT(*) FROM program_staff')->fetchColumn());
        $this->assertSame('manager', $pdo->query('SELECT role FROM program_staff')->fetchColumn());
    }

    /** The CHECK constraint's vocabulary is enforced before the write, not by it. */
    public function testAssignRefusesARoleTheConstraintWouldReject(): void
    {
        $pdo = $this->migratedPdo();

        $result = te_program_staff_assign($pdo, $this->admin(), self::CAMP, self::CAMP_COACH, 'parent', self::ADMIN);

        $this->assertFalse($result['ok']);
        $this->assertSame('bad_role', $result['reason']);
    }

    public function testRemoveRefusesACrossClubAdmin(): void
    {
        $pdo = $this->migratedPdo();
        $this->assign($pdo, $this->admin(), self::CAMP_COACH);
        $otherAdmin = new FakeProgramAuth([self::OTHER_CLUB => ['club_admin']]);

        $result = te_program_staff_remove($pdo, $otherAdmin, self::CAMP, self::CAMP_COACH, self::OTHER_ADMIN);

        $this->assertFalse($result['ok']);
        $this->assertSame('forbidden', $result['reason']);
        $this->assertSame(1, (int)$pdo->query('SELECT COUNT(*) FROM program_staff')->fetchColumn());
    }

    /** Removing someone who was never assigned is an answer, not an error. */
    public function testRemoveReportsWhenThereWasNothingToRemove(): void
    {
        $pdo = $this->migratedPdo();

        $result = te_program_staff_remove($pdo, $this->admin(), self::CAMP, self::IDLE_COACH, self::ADMIN);

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['removed']);
    }

    // ─── The missing-table window ────────────────────────────────────────────

    /**
     * `main` is shared and migrations are applied by hand, so this code reaches
     * production before migration 086 does. On Postgres a SELECT against a
     * missing table is 42P01 — a hard error that would take the calendar and the
     * recipient typeahead down for everyone. The probe answers "no programs",
     * which widens nothing and breaks nothing.
     */
    public function testProgramIdsAreEmptyWhenTheTableIsAbsent(): void
    {
        $pdo = $this->unmigratedPdo();

        $this->assertSame([], te_program_ids_for_user($pdo, self::CAMP_COACH));
        $this->assertFalse(te_program_staff_table_present($pdo));
    }

    public function testAssignRefusesWithAClearReasonWhenTheTableIsAbsent(): void
    {
        $pdo = $this->unmigratedPdo();

        $result = $this->assign($pdo, $this->admin(), self::CAMP_COACH);

        $this->assertFalse($result['ok']);
        $this->assertSame('schema', $result['reason'], 'the admin must be told it is not live yet, not that it failed');
        $this->assertSame(503, $result['status']);
    }

    /**
     * The probe is memoised per CONNECTION, so a process that has already
     * answered "absent" for one database must not answer "absent" for another.
     */
    public function testTheProbeIsPerConnectionNotPerProcess(): void
    {
        $absent = $this->unmigratedPdo();
        $this->assertFalse(te_program_staff_table_present($absent));

        $present = $this->migratedPdo();
        $this->assertTrue(te_program_staff_table_present($present));
    }

    public function testProgramIdsAreReturnedWhenTheTableIsPresent(): void
    {
        $pdo = $this->migratedPdo();
        $this->assign($pdo, $this->admin(), self::CAMP_COACH);

        $this->assertSame([self::CAMP], te_program_ids_for_user($pdo, self::CAMP_COACH));
        $this->assertSame([], te_program_ids_for_user($pdo, self::IDLE_COACH));
    }

    // ─── Registrants ─────────────────────────────────────────────────────────

    /**
     * `status <> 'rejected'`, not `status = 'approved'`: a camp coach needs to
     * reach the family that signed up this morning, and every registration
     * starts at 'pending'.
     */
    public function testRegistrantsIncludePendingAndExcludeRejected(): void
    {
        $pdo = $this->migratedPdo();

        $this->assertSame([950], te_program_registrant_athlete_ids($pdo, self::CAMP));
    }

    // ─── Recipient search: the payoff ────────────────────────────────────────

    private function search(PDO $pdo, string $q, int $userId, array $roles): array
    {
        $_GET = ['q' => $q, 'club_profile_id' => self::CLUB, 'channel' => 'email'];
        ob_start();
        try {
            handleSearch($pdo, new FakeProgramAuth([self::CLUB => $roles]), $userId);
        } finally {
            $out = ob_get_clean();
        }
        $res = json_decode($out, true) ?: [];
        return array_map(
            fn($r) => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
            $res['results'] ?? []
        );
    }

    /**
     * The reported need: a coach running a camp must be able to message the
     * families signed up for it. Before program_staff there was no roster to
     * derive that from, so the To field returned nobody.
     */
    public function testAnAssignedCoachFindsARegistrantsGuardian(): void
    {
        $pdo = $this->migratedPdo();
        $this->assign($pdo, $this->admin(), self::CAMP_COACH);

        $names = $this->search($pdo, 'Camper', self::CAMP_COACH, ['coach']);

        $this->assertContains('Dana Camper', $names, 'the camp coach must reach the registrant\'s crew');
        $this->assertContains('Casey Camper', $names);
    }

    /**
     * The other half, and the one that makes the first safe: standing comes from
     * the program_staff ROW. Holding the coach role in the club is not enough,
     * and neither is the family having registered.
     */
    public function testAnUnassignedCoachDoesNotFindThatGuardian(): void
    {
        $pdo = $this->migratedPdo();
        $this->assign($pdo, $this->admin(), self::CAMP_COACH);

        $names = $this->search($pdo, 'Camper', self::IDLE_COACH, ['coach']);

        $this->assertNotContains('Dana Camper', $names);
        $this->assertNotContains('Casey Camper', $names);
    }

    /** A rejected registration is not membership. */
    public function testARejectedRegistrantStaysOutOfReach(): void
    {
        $pdo = $this->migratedPdo();
        $this->assign($pdo, $this->admin(), self::CAMP_COACH);

        $names = $this->search($pdo, 'Rejected', self::CAMP_COACH, ['coach']);

        $this->assertNotContains('Rex Rejected', $names);
    }

    /**
     * Before migration 086 the widening is inert — the same coach, the same
     * search, and the pre-existing answer. This is what "degrades rather than
     * 500s" has to mean in practice.
     */
    public function testTheWideningIsInertBeforeTheMigration(): void
    {
        $pdo = $this->unmigratedPdo();

        $names = $this->search($pdo, 'Camper', self::CAMP_COACH, ['coach']);

        $this->assertNotContains('Dana Camper', $names);
    }

    /** Club admins are unaffected: they already reached everyone. */
    public function testAClubAdminIsUnaffected(): void
    {
        $pdo = $this->migratedPdo();

        $names = $this->search($pdo, 'Camper', self::ADMIN, ['club_admin']);

        $this->assertContains('Dana Camper', $names);
    }

    // ─── The one helper, called from both reads ──────────────────────────────

    /**
     * A parse check, not a behaviour check, and deliberately so: the bug this
     * class of change keeps producing is not a wrong predicate, it is the RIGHT
     * predicate not being called — `userCanAccessAthlete` vs
     * `staffCanManageAthlete`, `canAccessClub` vs `te_is_club_admin`,
     * `accessibleAthletes` vs `my_children`. If a future edit re-derives program
     * scope locally in either file, this fails.
     */
    public function testBothReadsGoThroughTheOneHelper(): void
    {
        $root = realpath(__DIR__ . '/../..');
        foreach ([
            'api/calendar-events-gateway.php',
            'api/recipient-search-gateway.php',
        ] as $rel) {
            $src = file_get_contents($root . '/' . $rel);
            $this->assertStringContainsString('te_program_ids_for_user(', $src,
                "$rel must derive program scope from lib/program_scope.php, not locally");
            $this->assertStringContainsString("require_once __DIR__ . '/../lib/program_scope.php'", $src,
                "$rel must require lib/program_scope.php");
        }
    }

    /**
     * The calendar widening must stay a UNION. A program branch written as an
     * extra WHERE predicate on the existing query would narrow what parents and
     * admins already see, which is a regression wearing a feature's clothes.
     */
    public function testTheCalendarWideningIsAdditive(): void
    {
        $src = file_get_contents(realpath(__DIR__ . '/../..') . '/api/calendar-events-gateway.php');

        $this->assertMatchesRegularExpression(
            '/\$events\[\]\s*=\s*\$e;/',
            $src,
            'program events must be APPENDED to whatever the existing branch returned'
        );
        $this->assertStringNotContainsString('AND ce.program_id IN', $src,
            'a program predicate on the main query would narrow the existing result');
    }
}
