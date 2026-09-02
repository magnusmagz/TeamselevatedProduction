<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;
use AuthMiddleware;

require_once __DIR__ . '/../../lib/athlete_evaluations.php';

/**
 * Mid-year athlete evaluations + IDP (CKU R76/R77, migration 086).
 *
 * The interesting question here is not "does an INSERT run". It is WHO, and the
 * answer has three different shapes that a single predicate would blur:
 *
 *   read   — AthleteScope::userCanAccessAthlete, guardian branch included. A
 *            parent seeing what their child's coach wrote is the feature.
 *   write  — staffCanManageAthlete AND directly coaching the athlete (or club
 *            admin). staffCanManageAthlete alone is NOT enough: it is satisfied
 *            by any club admin, and R76's rule is "players a coach directly
 *            coaches".
 *   delete — club admin only. Removing a child's development history is an
 *            administrative act.
 *
 * Same shape as userCanAccessAthlete vs staffCanManageAthlete elsewhere in this
 * codebase: the predicate is rarely wrong, WHICH ONE GETS CALLED is. So the last
 * test parses both files rather than trusting that the right one was used.
 *
 * The other thing pinned here is the reason the criteria are free text: a club
 * editing its tryout criteria must not change what a past evaluation MEANT.
 *
 * Fixture (one club, two teams, two athletes):
 *   Club 100
 *     Team 10 (primary_coach_id = 50) -> athlete 1 (Anna)
 *     Team 11 (assistant_coach 51)    -> athlete 2 (Ben)
 *   Athlete 1's guardian is user 80 (alice@family-a.com)
 *   Club admin: user 60. Unrelated: user 70.
 */
class AthleteEvaluationsTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = $this->migratedPdo();
    }

    // ---------------------------------------------------------------- fixture

    /** A connection WITH the migration-086 tables. */
    private function migratedPdo(): PDO
    {
        $pdo = $this->basePdo();
        $pdo->exec("
            CREATE TABLE athlete_evaluations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                athlete_id INTEGER NOT NULL,
                team_id INTEGER,
                evaluator_id INTEGER NOT NULL,
                evaluated_at TEXT NOT NULL,
                season_label TEXT NOT NULL,
                overall_score REAL,
                notes TEXT,
                idp_goals TEXT,
                created_at TEXT,
                updated_at TEXT
            );
            CREATE TABLE athlete_evaluation_scores (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                evaluation_id INTEGER NOT NULL,
                criterion_name TEXT NOT NULL,
                score REAL,
                max_score REAL,
                weight REAL,
                comment TEXT,
                display_order INTEGER NOT NULL DEFAULT 0,
                UNIQUE (evaluation_id, criterion_name)
            );
        ");
        return $pdo;
    }

    /**
     * A connection WITHOUT them — production between the push and the
     * hand-applied migration, which is a real window because `main` is shared
     * and deploys are by push.
     */
    private function unmigratedPdo(): PDO
    {
        return $this->basePdo();
    }

    private function basePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $pdo->exec("
            CREATE TABLE teams (id INTEGER PRIMARY KEY, name TEXT, club_id INTEGER,
                primary_coach_id INTEGER, deleted_at TEXT);
            CREATE TABLE team_members (id INTEGER PRIMARY KEY, team_id INTEGER, user_id INTEGER,
                athlete_id INTEGER, role TEXT, status TEXT);
            CREATE TABLE athletes (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT,
                club_id INTEGER);
            CREATE TABLE guardians (id INTEGER PRIMARY KEY, email TEXT);
            CREATE TABLE athlete_guardians (id INTEGER PRIMARY KEY, athlete_id INTEGER,
                guardian_id INTEGER);
            CREATE TABLE user_guardians (id INTEGER PRIMARY KEY, user_id INTEGER,
                guardian_id INTEGER, source TEXT, confidence TEXT);
            CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, first_name TEXT, last_name TEXT);
            CREATE TABLE programs (id INTEGER PRIMARY KEY, name TEXT, club_id INTEGER);
            CREATE TABLE tryout_evaluation_criteria (id INTEGER PRIMARY KEY, program_id INTEGER,
                name TEXT, description TEXT, max_score REAL, weight REAL, display_order INTEGER);
        ");

        $pdo->exec("INSERT INTO athletes (id, first_name, last_name, club_id) VALUES
            (1, 'Anna', 'Aaron', 100),
            (2, 'Ben', 'Brown', 100)");

        $pdo->exec("INSERT INTO teams (id, name, club_id, primary_coach_id, deleted_at) VALUES
            (10, 'Team A', 100, 50, NULL),
            (11, 'Team B', 100, NULL, NULL)");

        $pdo->exec("INSERT INTO team_members (id, team_id, user_id, athlete_id, role, status) VALUES
            (1, 10, NULL, 1, 'player', 'active'),
            (2, 11, NULL, 2, 'player', 'active'),
            (3, 11, 51, NULL, 'assistant_coach', 'active')");

        $pdo->exec("INSERT INTO guardians (id, email) VALUES (200, 'alice@family-a.com')");
        $pdo->exec("INSERT INTO athlete_guardians (id, athlete_id, guardian_id) VALUES (1, 1, 200)");

        $pdo->exec("INSERT INTO users (id, email, first_name, last_name) VALUES
            (50, 'coach50@club.test', 'Cora', 'Coach'),
            (51, 'coach51@club.test', 'Sam', 'Second'),
            (60, 'admin@club.test', 'Ada', 'Admin'),
            (70, 'nobody@example.com', 'No', 'Body'),
            (80, 'alice@family-a.com', 'Alice', 'Aaron')");

        $pdo->exec("INSERT INTO programs (id, name, club_id) VALUES (900, 'U12 Tryouts', 100)");
        $pdo->exec("INSERT INTO tryout_evaluation_criteria
            (id, program_id, name, description, max_score, weight, display_order) VALUES
            (1, 900, 'Technical Skills', 'Ball control', 5, 1.0, 1),
            (2, 900, 'Tactical Awareness', 'Game sense', 5, 2.0, 2)");

        return $pdo;
    }

    // ---------------------------------------------------------------- actors

    private function directCoach(): AuthMiddleware   // coaches team 10 -> athlete 1
    {
        return AuthMiddleware::fromContext(['user_id' => 50, 'email' => 'coach50@club.test', 'roles' => []]);
    }

    private function otherTeamCoach(): AuthMiddleware // coaches team 11 -> athlete 2 only
    {
        return AuthMiddleware::fromContext(['user_id' => 51, 'email' => 'coach51@club.test', 'roles' => []]);
    }

    private function clubAdmin(): AuthMiddleware
    {
        return AuthMiddleware::fromContext([
            'user_id' => 60,
            'email' => 'admin@club.test',
            'roles' => [['role' => 'club_admin', 'scope_type' => 'club', 'scope_id' => 100]],
        ]);
    }

    private function parent(): AuthMiddleware        // guardian of athlete 1
    {
        return AuthMiddleware::fromContext(['user_id' => 80, 'email' => 'alice@family-a.com', 'roles' => []]);
    }

    private function unrelated(): AuthMiddleware
    {
        return AuthMiddleware::fromContext(['user_id' => 70, 'email' => 'nobody@example.com', 'roles' => []]);
    }

    // ------------------------------------------------------------ the ladder

    /**
     * The whole reason reads and writes take different predicates. A parent
     * reading their own child's development plan is the feature; a parent
     * scoring them is not.
     */
    public function testAParentCanReadTheirChildsEvaluationsButCannotWriteOne(): void
    {
        $parent = $this->parent();

        $this->assertTrue(
            te_athlete_evaluation_can_read($this->pdo, $parent, 1),
            'a guardian must be able to read their own child'
        );
        $this->assertFalse(
            te_athlete_evaluation_can_write($this->pdo, $parent, 1),
            'the guardian branch of the read predicate must not leak into the write gate'
        );
    }

    /** And nothing about somebody else's child. */
    public function testAParentCannotReadAnotherFamilysAthlete(): void
    {
        $this->assertFalse(te_athlete_evaluation_can_read($this->pdo, $this->parent(), 2));
    }

    /**
     * staffCanManageAthlete is satisfied by any coach of any team the athlete is
     * on, so this coach is refused by the SECOND half of the gate — not the
     * first. Coach 51 coaches team 11; athlete 1 is on team 10.
     */
    public function testACoachOfADifferentTeamCannotWrite(): void
    {
        $coach = $this->otherTeamCoach();

        $this->assertFalse(te_athlete_evaluation_can_write($this->pdo, $coach, 1));
        $this->assertTrue(
            te_athlete_evaluation_can_write($this->pdo, $coach, 2),
            'the same coach must still be able to evaluate their OWN player'
        );
    }

    public function testTheDirectCoachCanWrite(): void
    {
        $this->assertTrue(te_athlete_evaluation_can_write($this->pdo, $this->directCoach(), 1));
    }

    public function testAnUnrelatedUserCanNeitherReadNorWrite(): void
    {
        $nobody = $this->unrelated();
        $this->assertFalse(te_athlete_evaluation_can_read($this->pdo, $nobody, 1));
        $this->assertFalse(te_athlete_evaluation_can_write($this->pdo, $nobody, 1));
    }

    /**
     * A club admin may record an evaluation for any athlete in their club, and
     * is the ONLY actor who may delete one — including for an athlete they have
     * never coached.
     */
    public function testClubAdminCanWriteAndIsTheOnlyOneWhoCanDelete(): void
    {
        $admin = $this->clubAdmin();
        $coach = $this->directCoach();

        $this->assertTrue(te_athlete_evaluation_can_write($this->pdo, $admin, 1));
        $this->assertTrue(te_athlete_evaluation_is_club_admin($this->pdo, $admin, 1));

        $this->assertFalse(
            te_athlete_evaluation_is_club_admin($this->pdo, $coach, 1),
            'a coach who may write must still not be able to delete'
        );
        $this->assertFalse(te_athlete_evaluation_is_club_admin($this->pdo, $this->parent(), 1));
    }

    // ------------------------------------------------- absent-table tolerance

    /**
     * `main` is shared and migrations are applied by hand, so this code runs in
     * production before migration 086 does. A missing table is 42P01 on
     * Postgres — a hard error that would take the whole athlete profile down for
     * every club rather than hiding one panel.
     */
    public function testEverythingToleratesTheTablesBeingAbsent(): void
    {
        $pdo = $this->unmigratedPdo();

        $this->assertFalse(te_athlete_evaluation_tables_present($pdo));
        $this->assertSame([], te_athlete_evaluation_list($pdo, 1));
        $this->assertNull(te_athlete_evaluation_find($pdo, 1));

        // The gates still answer — they read teams and guardians, not the new
        // tables — so the panel can say "not switched on" rather than "denied".
        $this->assertTrue(te_athlete_evaluation_can_write($pdo, $this->directCoach(), 1));
    }

    public function testThePresenceProbeIsPerConnectionNotPerProcess(): void
    {
        // A process-wide static would let the first connection's answer decide
        // the second's, which is exactly the bug lib/program_scope.php's WeakMap
        // comment describes.
        $this->assertTrue(te_athlete_evaluation_tables_present($this->migratedPdo()));
        $this->assertFalse(te_athlete_evaluation_tables_present($this->unmigratedPdo()));
        $this->assertTrue(te_athlete_evaluation_tables_present($this->migratedPdo()));
    }

    /** Half a migration is not a migration. */
    public function testASinglePresentTableIsNotEnough(): void
    {
        $pdo = $this->unmigratedPdo();
        $pdo->exec('CREATE TABLE athlete_evaluations (id INTEGER PRIMARY KEY)');
        $this->assertFalse(te_athlete_evaluation_tables_present($pdo));
    }

    // ----------------------------------------------------------- round trips

    public function testCreateStoresScoresGoalsAndAFrozenOverall(): void
    {
        $id = te_athlete_evaluation_create($this->pdo, 1, 50, [
            'team_id'      => 10,
            'evaluated_at' => '2026-01-15',
            'season_label' => '2025-26',
            'notes'        => 'Strong first half.',
            'scores'       => [
                ['criterion_name' => 'Technical Skills',   'score' => 4, 'max_score' => 5, 'weight' => 1],
                ['criterion_name' => 'Tactical Awareness', 'score' => 3, 'max_score' => 5, 'weight' => 2, 'comment' => 'Improving'],
            ],
            'idp_goals'    => [
                ['goal' => 'Weaker foot passing', 'target_date' => '2026-04-01'],
                ['goal' => 'Lead a warm-up', 'target_date' => null],
            ],
        ]);

        $list = te_athlete_evaluation_list($this->pdo, 1);
        $this->assertCount(1, $list);
        $row = $list[0];

        $this->assertSame($id, $row['id']);
        $this->assertSame('2026-01-15', $row['evaluated_at']);
        $this->assertSame('2025-26', $row['season_label']);
        $this->assertSame(10, $row['team_id']);
        $this->assertSame('Team A', $row['team_name']);
        $this->assertSame('Cora Coach', $row['evaluator_name']);

        // (4/5 * 100 * 1 + 3/5 * 100 * 2) / 3 = (80 + 120) / 3 = 66.67
        $this->assertSame(66.67, $row['overall_score']);

        $this->assertCount(2, $row['scores']);
        $this->assertSame('Technical Skills', $row['scores'][0]['criterion_name']);
        $this->assertSame('Improving', $row['scores'][1]['comment']);

        $this->assertCount(2, $row['idp_goals']);
        $this->assertSame('Weaker foot passing', $row['idp_goals'][0]['goal']);
        $this->assertSame('2026-04-01', $row['idp_goals'][0]['target_date']);
    }

    /**
     * The point of copying criterion names, max scores and weights onto the
     * evaluation. A club renaming or deleting a tryout criterion must not
     * change what a recorded evaluation says.
     */
    public function testHistorySurvivesTheClubEditingItsCriteria(): void
    {
        te_athlete_evaluation_create($this->pdo, 1, 50, [
            'evaluated_at' => '2026-01-15',
            'season_label' => '2025-26',
            'scores' => [
                ['criterion_name' => 'Technical Skills', 'score' => 4, 'max_score' => 5, 'weight' => 1],
            ],
        ]);

        // The club renames one criterion and deletes the other.
        $this->pdo->exec("UPDATE tryout_evaluation_criteria SET name = 'Ball Mastery', max_score = 10 WHERE id = 1");
        $this->pdo->exec('DELETE FROM tryout_evaluation_criteria WHERE id = 2');

        $row = te_athlete_evaluation_list($this->pdo, 1)[0];
        $this->assertSame('Technical Skills', $row['scores'][0]['criterion_name']);
        $this->assertSame(5.0, $row['scores'][0]['max_score']);
        $this->assertSame(80.0, $row['overall_score']);
    }

    /**
     * Scores are replaced wholesale, not upserted: dropping a criterion from the
     * form must drop it from the record, or the removed criterion stays on the
     * parent's screen forever.
     */
    public function testUpdateReplacesTheWholeScoreListAndRecomputesTheOverall(): void
    {
        $id = te_athlete_evaluation_create($this->pdo, 1, 50, [
            'evaluated_at' => '2026-01-15',
            'season_label' => '2025-26',
            'scores' => [
                ['criterion_name' => 'Technical Skills',   'score' => 4, 'max_score' => 5, 'weight' => 1],
                ['criterion_name' => 'Tactical Awareness', 'score' => 3, 'max_score' => 5, 'weight' => 1],
            ],
        ]);

        te_athlete_evaluation_update($this->pdo, $id, [
            'evaluated_at' => '2026-01-20',
            'season_label' => '2025-26',
            'scores' => [
                ['criterion_name' => 'Technical Skills', 'score' => 5, 'max_score' => 5, 'weight' => 1],
            ],
            'idp_goals' => [],
        ]);

        $row = te_athlete_evaluation_list($this->pdo, 1)[0];
        $this->assertCount(1, $row['scores'], 'the dropped criterion must be gone, not merely unscored');
        $this->assertSame(100.0, $row['overall_score']);
        $this->assertSame('2026-01-20', $row['evaluated_at']);
        $this->assertNotNull($row['updated_at']);
    }

    public function testDeleteRemovesTheEvaluationAndItsScores(): void
    {
        $id = te_athlete_evaluation_create($this->pdo, 1, 50, [
            'evaluated_at' => '2026-01-15',
            'season_label' => '2025-26',
            'scores' => [['criterion_name' => 'Technical Skills', 'score' => 4, 'max_score' => 5, 'weight' => 1]],
        ]);

        te_athlete_evaluation_delete($this->pdo, $id);

        $this->assertSame([], te_athlete_evaluation_list($this->pdo, 1));
        $this->assertSame(
            '0',
            (string) $this->pdo->query('SELECT COUNT(*) FROM athlete_evaluation_scores')->fetchColumn()
        );
    }

    public function testListIsNewestFirstAndScopedToOneAthlete(): void
    {
        te_athlete_evaluation_create($this->pdo, 1, 50, ['evaluated_at' => '2025-01-15', 'season_label' => '2024-25', 'scores' => []]);
        te_athlete_evaluation_create($this->pdo, 1, 50, ['evaluated_at' => '2026-01-15', 'season_label' => '2025-26', 'scores' => []]);
        te_athlete_evaluation_create($this->pdo, 2, 51, ['evaluated_at' => '2026-01-15', 'season_label' => '2025-26', 'scores' => []]);

        $rows = te_athlete_evaluation_list($this->pdo, 1);
        $this->assertCount(2, $rows);
        $this->assertSame('2026-01-15', $rows[0]['evaluated_at']);
        $this->assertSame('2025-01-15', $rows[1]['evaluated_at']);
    }

    // -------------------------------------------------------------- criteria

    public function testCriteriaComeFromTheClubsTryoutCriteria(): void
    {
        $result = te_athlete_evaluation_criteria($this->pdo, 1);

        $this->assertSame('club', $result['source']);
        $this->assertSame(
            ['Technical Skills', 'Tactical Awareness'],
            array_column($result['criteria'], 'name')
        );
        $this->assertSame(2.0, $result['criteria'][1]['weight']);
    }

    /** Deduplicated across programs, widest max_score winning. */
    public function testTheSameCriterionAcrossTwoProgramsIsOfferedOnce(): void
    {
        $this->pdo->exec("INSERT INTO programs (id, name, club_id) VALUES (901, 'U14 Tryouts', 100)");
        $this->pdo->exec("INSERT INTO tryout_evaluation_criteria
            (id, program_id, name, description, max_score, weight, display_order) VALUES
            (3, 901, 'Technical Skills', 'Ball control', 10, 1.0, 1)");

        $result = te_athlete_evaluation_criteria($this->pdo, 1);
        $names = array_column($result['criteria'], 'name');

        $this->assertSame(['Technical Skills'], array_values(array_filter($names, fn($n) => $n === 'Technical Skills')));
        $this->assertSame(10.0, $result['criteria'][0]['max_score']);
    }

    /**
     * A club that has never run a tryout still gets a form. The response says
     * where the list came from so the UI does not imply the club chose it.
     */
    public function testAClubWithNoTryoutCriteriaGetsTheDefaults(): void
    {
        $this->pdo->exec('DELETE FROM tryout_evaluation_criteria');

        $result = te_athlete_evaluation_criteria($this->pdo, 1);
        $this->assertSame('default', $result['source']);
        $this->assertNotEmpty($result['criteria']);
        $this->assertSame('Technical Skills', $result['criteria'][0]['name']);
    }

    // ------------------------------------------------------- the pure helpers

    public function testOverallIgnoresUnscoredCriteriaRatherThanCountingThemAsZero(): void
    {
        $overall = te_athlete_evaluation_overall([
            ['criterion_name' => 'A', 'score' => 5, 'max_score' => 5, 'weight' => 1],
            ['criterion_name' => 'B', 'score' => null, 'max_score' => 5, 'weight' => 1],
        ]);

        $this->assertSame(100.0, $overall, 'a partial evaluation reports on what was assessed');
    }

    public function testOverallIsNullWhenNothingWasScored(): void
    {
        // Null, not 0 — a fabricated zero would put a low point on the
        // year-over-year graph that nobody ever gave.
        $this->assertNull(te_athlete_evaluation_overall([]));
        $this->assertNull(te_athlete_evaluation_overall([
            ['criterion_name' => 'A', 'score' => 4, 'max_score' => 0, 'weight' => 1],
        ]));
    }

    public function testGoalsDropBlanksAndRefuseMoreThanTheCap(): void
    {
        $ok = te_athlete_evaluation_normalize_goals([
            ['goal' => 'One', 'target_date' => '2026-04-01'],
            ['goal' => '   ', 'target_date' => '2026-04-01'],
            ['goal' => 'Two'],
        ]);
        $this->assertNull($ok['error']);
        $this->assertCount(2, $ok['goals']);
        $this->assertNull($ok['goals'][1]['target_date']);

        $tooMany = te_athlete_evaluation_normalize_goals(array_map(
            fn(int $i): array => ['goal' => "Goal $i"],
            range(1, TE_IDP_MAX_GOALS + 1)
        ));
        // Refused, not silently truncated: a plan quietly missing its last goal
        // is the silent-failure shape this codebase keeps rediscovering.
        $this->assertNotNull($tooMany['error']);
        $this->assertSame([], $tooMany['goals']);
    }

    public function testAGoalTargetDateMustBeDateOnly(): void
    {
        $result = te_athlete_evaluation_normalize_goals([
            ['goal' => 'One', 'target_date' => '04/01/2026'],
        ]);
        $this->assertNotNull($result['error']);
    }

    public function testDuplicateAndUnnamedCriteriaAreResolvedBeforeTheInsert(): void
    {
        $rows = te_athlete_evaluation_normalize_scores([
            ['criterion_name' => 'A', 'score' => 1, 'max_score' => 5, 'weight' => 1],
            ['criterion_name' => '',  'score' => 5, 'max_score' => 5, 'weight' => 1],
            // Would violate UNIQUE(evaluation_id, criterion_name) — a 23505 at
            // INSERT time is a 500, not a message.
            ['criterion_name' => 'A', 'score' => 4, 'max_score' => 5, 'weight' => 1],
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame(4.0, $rows[0]['score']);
    }

    // -------------------------------------------------------------- the parse

    /** Remove PHP comments so a pattern search sees only executable code. */
    private static function stripComments(string $src): string
    {
        $src = preg_replace('!/\\*.*?\\*/!s', '', $src);
        return preg_replace('/^[ \\t]*\\/\\/.*$/m', '', $src);
    }


    /**
     * The predicate is rarely wrong; which one gets called is. Same reason
     * AthleteWriteScopeTest and ParentPortalChildScopeTest are scans.
     */
    public function testWritesGateOnStaffCanManageAthleteAndCoachesAthlete(): void
    {
        $lib = file_get_contents(__DIR__ . '/../../lib/athlete_evaluations.php');
        // Comments are stripped before counting: this file's own header names
        // the predicates it does NOT use, and a comment that names the defect it
        // avoids must not read as the defect itself (SchemaConformanceTest's
        // stripComments exists for the same reason).
        $api = self::stripComments(file_get_contents(__DIR__ . '/../../api/athlete-evaluations.php'));

        // The write gate is built from BOTH halves. Either one alone is a bug:
        // staffCanManageAthlete admits every club admin AND every coach of the
        // athlete's teams; coachesAthlete alone would admit a coach with no
        // standing in the club.
        $this->assertMatchesRegularExpression(
            '/function te_athlete_evaluation_can_write.*?AthleteScope::staffCanManageAthlete.*?AthleteScope::coachesAthlete.*?\n}/s',
            $lib,
            'te_athlete_evaluation_can_write must require staff standing AND direct coaching'
        );

        // The read predicate has no business in a write path.
        $this->assertSame(
            0,
            substr_count($api, 'AthleteScope::userCanAccessAthlete'),
            'the endpoint must reach the read predicate through te_athlete_evaluation_can_read only'
        );

        // Four uses, and each one matters: the criteria read, create, update,
        // and the `can_evaluate` flag the list returns. That last one is what
        // decides whether the panel offers a New evaluation button, and it must
        // be the SAME predicate the write path enforces — a button that leads to
        // a 403, or a hidden button over an endpoint that would have accepted,
        // are both symptoms of two answers to one question.
        $this->assertSame(
            4,
            substr_count($api, 'te_athlete_evaluation_can_write'),
            'criteria, create, update and the can_evaluate flag must all use the write gate'
        );

        // Delete is club admin only, and says so.
        $this->assertMatchesRegularExpression(
            '/action === \'delete\'.*?te_athlete_evaluation_is_club_admin/s',
            $api,
            'delete must gate on club admin standing'
        );

        // Every write is audited. An evaluation appearing or vanishing from a
        // child's record is exactly the kind of thing someone asks about later.
        foreach (['athlete_evaluation_created', 'athlete_evaluation_updated', 'athlete_evaluation_deleted'] as $action) {
            $this->assertStringContainsString($action, $api, "missing audit action $action");
        }
    }

    /**
     * A write against absent tables answers 503 with a sentence, never a silent
     * success. The coach needs to know their work was not saved.
     */
    public function testAWriteAgainstAbsentTablesRefusesRatherThanReportingSuccess(): void
    {
        $api = file_get_contents(__DIR__ . '/../../api/athlete-evaluations.php');

        $this->assertStringContainsString('503', $api);
        $this->assertStringContainsString('no evaluation was saved', $api);
        // Three write paths, each checking before it acts.
        $this->assertGreaterThanOrEqual(3, substr_count($api, 'te_eval_unavailable()'));
    }
}
