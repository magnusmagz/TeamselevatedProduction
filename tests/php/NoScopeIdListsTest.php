<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;

require_once __DIR__ . '/../../lib/scope_sql.php';

/**
 * Scope belongs in SQL, not in a bound list of ids.
 *
 * `implode(',', array_fill(0, count($ids), '?'))` fetches a set into PHP and
 * re-binds it one placeholder at a time. Three ways that ends badly:
 *
 *  1. `array_fill(0, 0, '?')` is `IN ()` — a SYNTAX ERROR in Postgres, not an
 *     empty result. Every site needs its own emptiness guard and
 *     `handleChatSearch` did not have one (2026-08-14).
 *  2. Two round trips where one would do, with the planner unable to see the
 *     relationship between the ids and where they came from.
 *  3. **Postgres caps bind parameters at 65,535.** `accessibleAthleteFilter()`
 *     bound one per ATHLETE. A GOTR division admin over 30 councils is within an
 *     order of magnitude of a hard protocol error and planner time dies long
 *     before that (docs/gotr-hierarchy-plan-2026-09.md §5).
 *
 * This test is a **ratchet on the remaining sites, not a ban**. A count in the
 * inventory below may only go DOWN. Adding a new athlete- or team-id list, or a
 * second one in a file that already has some, fails the build; fixing one and
 * lowering its number does not.
 *
 * ⚠️ **Scope is the variable NAME, deliberately.** The scan matches
 * `array_fill(0, count($…team…))` / `$…athlete…` and nothing else. Matching
 * every `array_fill` would flag `$cols` in an INSERT builder and `$invoiceIds`
 * in a webhook, and a test that cries wolf gets deleted — the same lesson
 * MysqlOnlySqlTest learned when it matched the English word "field".
 *
 * ⚠️ **CLUB id lists are not in scope and never will be.** A person holds a role
 * in a handful of clubs and the list is already in the token; a subquery there
 * would add a `user_club_access` join to save nothing. Those sites are named in
 * ALLOWED_CLUB_ID_SITES purely so the decision is written down where someone
 * will look for it.
 *
 * Functional proof that the rewritten predicate still admits exactly the same
 * athletes — admin, coach, assistant coach, guardian, coach-parent, super admin,
 * nobody — is in AthleteScopeTest and AthleteScopeClubIdTest, which execute the
 * fragment against a SQLite fixture rather than asserting an id list.
 */
class NoScopeIdListsTest extends TestCase
{
    /** Directories that ship. `services/` and `controllers/` are out of scope for this pass. */
    private const ROOTS = ['lib', 'api', 'legacy'];

    /**
     * path => [ variable name => [max occurrences, why it is still here] ]
     *
     * "SCOPE" marks a list built from the CALLER'S STANDING — the ones that grow
     * with the organisation and the reason this file exists. "BOUNDED" marks a
     * list whose size is set by the request or by one event/team, which does not
     * grow with the platform and is therefore not urgent.
     *
     * Recorded 2026-09-02, after the G2 rewrite of AthleteScope,
     * recipient-search-gateway, calendar-events-gateway and
     * chat_notification_scope.
     */
    private const INVENTORY = [
        'lib/event_standing.php' => [
            'coachTeams' => [1, 'SCOPE — coachTeamIdsForUser() for one event standing check.'],
        ],
        'lib/document_scope.php' => [
            'teamIds' => [1, 'SCOPE — the caller\'s teams, for document assignment visibility.'],
        ],
        'lib/roster_export.php' => [
            'athleteIds' => [1, 'BOUNDED — the athletes of ONE team, already capped at 1000 rows by the export itself.'],
        ],
        'lib/suppression.php' => [
            'teamIds' => [1, 'BOUNDED — the teams of one send, passed in by the caller.'],
        ],
        'api/event-attendance.php' => [
            'teamIds' => [1, 'BOUNDED — the teams attached to ONE event, read off the event row.'],
        ],
        'api/calendar-events-gateway.php' => [
            'teamIds' => [4, 'BOUNDED — the teams attached to ONE event. Not the caller\'s standing: '
                . 'these four also carry a coach predicate WITHOUT tm.status = \'active\', so routing them '
                . 'through te_scope_coach_team_ids_sql() would NARROW who counts as privileged. That is a '
                . 'behaviour change, not a rewrite, and belongs in its own commit.'],
        ],
        'api/recipient-search-gateway.php' => [
            'teamIds' => [2, 'BOUNDED — team ids supplied in the REQUEST body, being validated against the club. '
                . 'The caller\'s own reach on both paths is now a subquery (te_scope_all_ids_within).'],
        ],
        'api/sibling-discount.php' => [
            'athleteIds' => [1, 'BOUNDED — the siblings in one family.'],
        ],
        'api/financial-permissions.php' => [
            'teamIds' => [2, 'SCOPE — the caller\'s coached teams, per club.'],
        ],
        'api/documents-gateway.php' => [
            'coachTeamIds' => [1, 'SCOPE — getCoachTeamIds() for the document list.'],
            'teamIds'      => [1, 'BOUNDED — team ids read off the documents already fetched.'],
            'athleteIds'   => [1, 'SCOPE — athletes on the caller\'s teams.'],
        ],
        'api/invoices.php' => [
            'athlete_ids' => [1, 'SCOPE — the caller\'s accessible athletes, for billing.'],
        ],
        'api/communications-gateway.php' => [
            'coachTeamIds' => [4, 'SCOPE — getCoachTeamIds() at four send/report sites.'],
            'teamIds'      => [1, 'BOUNDED — the teams named in one broadcast.'],
        ],
        'api/analytics-gateway.php' => [
            'teamIds' => [4, 'SCOPE — getCoachTeamIds() feeding the four Email Reporting queries.'],
        ],
        'legacy/athletes-gateway.php' => [
            'teamIds' => [1, 'SCOPE — coachTeamIdsForUser() for the roster column.'],
        ],
        'legacy/events-gateway.php' => [
            'teamIds' => [1, 'SCOPE — coachTeamIdsForUser() for the event list.'],
        ],
    ];

    /**
     * Club-id lists, kept as materialised `IN (?,?,…)` on purpose.
     *
     * These do not match the scan (their variables are named for clubs), so this
     * list changes no test outcome. It exists because "why was this one left
     * alone" is the question someone will ask, and the answer should not have to
     * be reconstructed from a diff.
     */
    private const ALLOWED_CLUB_ID_SITES = [
        'lib/AthleteScope.php:$adminClubIds'
            => 'Club admin clubs, straight off the JWT. One or two values.',
        'lib/AuthMiddleware.php:$clubIds'
            => 'getAccessibleClubIds(). OFF-LIMITS to this workstream in any case — another lane owns that file.',
        'legacy/venues-gateway.php:$accessibleClubIds'
            => 'getAccessibleClubIds() scoping the facility list.',
        'legacy/fields-gateway.php:$accessibleClubIds'
            => 'getAccessibleClubIds() scoping the field list.',
        'legacy/coaches-gateway.php:$accessibleClubs'
            => 'getAccessibleClubIds(), twice: the coach list and the coach-edit tenant check.',
        'lib/athlete_evaluations.php:$clubIds'
            => 'Club scope for evaluation reads.',
    ];

    /** @return array<string, array<string,int>> path => var => occurrences */
    private function scan(): array
    {
        $root = dirname(__DIR__, 2);
        $found = [];

        foreach (self::ROOTS as $dir) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $rel = ltrim(str_replace($root, '', $file->getPathname()), '/');
                $body = file_get_contents($file->getPathname());

                if (!preg_match_all('/array_fill\(\s*0\s*,\s*count\(\s*\$([A-Za-z_][A-Za-z0-9_]*)/', $body, $m)) {
                    continue;
                }
                foreach ($m[1] as $var) {
                    // Variable NAME, not file path: api/athlete-*.php building an
                    // INSERT column list is not a scope list.
                    if (!preg_match('/team|athlete/i', $var)) {
                        continue;
                    }
                    $found[$rel][$var] = ($found[$rel][$var] ?? 0) + 1;
                }
            }
        }

        return $found;
    }

    public function testNoNewAthleteOrTeamIdListsAppear(): void
    {
        $found = $this->scan();
        $problems = [];

        foreach ($found as $path => $vars) {
            foreach ($vars as $var => $count) {
                $allowed = self::INVENTORY[$path][$var][0] ?? 0;
                if ($count > $allowed) {
                    $problems[] = sprintf(
                        '%s: $%s appears in %d array_fill id list(s), inventory allows %d.',
                        $path,
                        $var,
                        $count,
                        $allowed
                    );
                }
            }
        }

        $this->assertSame([], $problems, implode("\n", array_merge(
            ['A scope id list was added or widened. Build the set as SQL instead:'],
            ['  lib/scope_sql.php — te_scope_coach_team_ids_sql(), te_scope_guardian_team_ids_sql(),'],
            ['  te_scope_guardian_athlete_exists_sql(), te_scope_program_athlete_ids_sql().'],
            ['If the list genuinely cannot grow with the organisation, add it to INVENTORY with a'],
            ['reason saying WHY it is bounded — never to silence a finding.'],
            [''],
            $problems
        )));
    }

    public function testTheRewrittenFilesCarryNoAthleteOrTeamIdListsAtAll(): void
    {
        // The four files this workstream rewrote. A regression here is the
        // specific thing the G2 slice bought, so it is asserted directly rather
        // than left to the inventory's counts.
        $found = $this->scan();

        foreach (['lib/AthleteScope.php', 'lib/chat_notification_scope.php'] as $path) {
            $this->assertArrayNotHasKey(
                $path,
                $found,
                "{$path} was rewritten to subqueries; an athlete/team id list has come back."
            );
        }
    }

    public function testEveryInventoryEntryStillExists(): void
    {
        // A stale entry is an allowlist that has stopped describing the code, and
        // the next person reads it as current. This does not fail on a FIX (the
        // count assertion is `<=`), only on an entry whose file or variable is
        // gone entirely.
        $found = $this->scan();
        foreach (self::INVENTORY as $path => $vars) {
            foreach ($vars as $var => $entry) {
                $this->assertArrayHasKey($path, $found, "Stale INVENTORY entry: {$path} no longer matches. Delete it.");
                $this->assertArrayHasKey(
                    $var,
                    $found[$path],
                    "Stale INVENTORY entry: {$path} \${$var} no longer matches. Delete it."
                );
            }
        }
    }

    public function testEveryClubIdSiteCarriesAReason(): void
    {
        foreach (self::ALLOWED_CLUB_ID_SITES as $site => $reason) {
            $this->assertNotSame('', trim($reason), "No reason recorded for {$site}");
        }
    }

    // ---- The helpers themselves ----

    private function fixture(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("
            CREATE TABLE teams (id INTEGER PRIMARY KEY, club_id INTEGER, primary_coach_id INTEGER, deleted_at TEXT);
            CREATE TABLE team_members (id INTEGER PRIMARY KEY, team_id INTEGER, user_id INTEGER,
                                       athlete_id INTEGER, role TEXT, status TEXT);
            CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT);
            CREATE TABLE guardians (id INTEGER PRIMARY KEY, email TEXT);
            CREATE TABLE user_guardians (id INTEGER PRIMARY KEY, user_id INTEGER, guardian_id INTEGER);
            CREATE TABLE athlete_guardians (id INTEGER PRIMARY KEY, athlete_id INTEGER, guardian_id INTEGER);

            INSERT INTO teams (id, club_id, primary_coach_id, deleted_at) VALUES
                (10, 100, 50, NULL), (11, 100, NULL, NULL), (12, 101, 50, NULL), (13, 100, 50, '2026-01-01');
            INSERT INTO team_members (id, team_id, user_id, athlete_id, role, status) VALUES
                (1, 11, 51, NULL, 'assistant_coach', 'active'),
                (2, 11, 52, NULL, 'assistant_coach', 'inactive'),
                (3, 10, NULL, 1, 'player', 'active'),
                (4, 11, NULL, 2, 'player', 'active');
            INSERT INTO users (id, email) VALUES (50, 'c50@x.test'), (51, 'c51@x.test'), (80, 'a@fam.test');
            INSERT INTO guardians (id, email) VALUES (200, 'a@fam.test');
            INSERT INTO athlete_guardians (id, athlete_id, guardian_id) VALUES (1, 1, 200);
        ");
        return $pdo;
    }

    private function ids(PDO $pdo, array $q): array
    {
        $stmt = $pdo->prepare($q['sql']);
        $stmt->execute($q['params']);
        $out = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        sort($out);
        return $out;
    }

    public function testCoachTeamSubqueryMatchesTheListItReplaced(): void
    {
        $pdo = $this->fixture();

        // Head coach, club-scoped, soft-deleted teams left in (getCoachTeamIds()).
        $this->assertSame([10, 13], $this->ids($pdo, te_scope_coach_team_ids_sql(50, 100, false)));
        // Same coach, soft-deleted excluded (AthleteScope::coachTeamIdsForUser()).
        $this->assertSame([10], $this->ids($pdo, te_scope_coach_team_ids_sql(50, 100, true)));
        // Across clubs, unscoped.
        $this->assertSame([10, 12, 13], $this->ids($pdo, te_scope_coach_team_ids_sql(50, null, false)));
        // Assistant coach, active membership only.
        $this->assertSame([11], $this->ids($pdo, te_scope_coach_team_ids_sql(51, 100, false)));
        $this->assertSame([], $this->ids($pdo, te_scope_coach_team_ids_sql(52, 100, false)));
    }

    public function testGuardianTeamSubqueryFindsTheirChildsTeamOnly(): void
    {
        $pdo = $this->fixture();
        $this->assertSame([10], $this->ids($pdo, te_scope_guardian_team_ids_sql(80, 100)));
        $this->assertSame([], $this->ids($pdo, te_scope_guardian_team_ids_sql(51, 100)));
    }

    public function testAnEmptyScopeIsAnEmptySubqueryAndNotAnInSyntaxError(): void
    {
        // The whole reason for the shape. `IN ()` is a syntax error rather than
        // an empty result, so every id-list site needed its own emptiness guard.
        $pdo = $this->fixture();
        $none = te_scope_coach_team_ids_sql(999, 100, false);

        $stmt = $pdo->prepare("SELECT id FROM teams WHERE id IN ({$none['sql']})");
        $stmt->execute($none['params']);
        $this->assertSame([], $stmt->fetchAll(PDO::FETCH_COLUMN));

        $this->assertFalse(te_scope_subquery_has_rows($pdo, $none));
        $this->assertTrue(te_scope_subquery_has_rows($pdo, te_scope_coach_team_ids_sql(50, 100, false)));
    }

    public function testAllIdsWithinRefusesAnyIdOutsideTheScope(): void
    {
        $pdo = $this->fixture();
        $scope = te_scope_coach_team_ids_sql(50, 100, false);

        $this->assertTrue(te_scope_all_ids_within($pdo, [10], $scope));
        $this->assertTrue(te_scope_all_ids_within($pdo, [10, 13], $scope));
        $this->assertFalse(te_scope_all_ids_within($pdo, [10, 11], $scope));
        $this->assertFalse(te_scope_all_ids_within($pdo, [12], $scope));
        // Nothing asked for, nothing refused — the caller rejects an empty
        // request earlier, with its own message.
        $this->assertTrue(te_scope_all_ids_within($pdo, [], $scope));
    }

    public function testUnionDedupesAcrossBothHats(): void
    {
        $pdo = $this->fixture();
        // Coach 50 coaches team 10; make them the guardian of athlete 1, also on
        // team 10, plus give them a child on team 11 which they do not coach.
        $pdo->exec("UPDATE users SET email = 'a@fam.test' WHERE id = 50");
        $pdo->exec("INSERT INTO athlete_guardians (id, athlete_id, guardian_id) VALUES (2, 2, 200)");

        $union = te_scope_union_sql(
            te_scope_coach_team_ids_sql(50, 100, false, 'a'),
            te_scope_guardian_team_ids_sql(50, 100, 'b')
        );
        // 10 appears from both hats and once in the result; 11 comes from the
        // parent hat alone. Capabilities accumulate.
        $this->assertSame([10, 11, 13], $this->ids($pdo, $union));
    }
}
