<?php

use PHPUnit\Framework\TestCase;

if (!defined('TE_TRYOUTS_LIB_ONLY')) {
    define('TE_TRYOUTS_LIB_ONLY', true);
}
require_once __DIR__ . '/../../registration/tryouts-api.php';

/**
 * registration/tryouts-api.php performed NO authentication of any kind.
 *
 * ~800 lines dispatched on `?path=` across GET/POST/PUT/DELETE, reached
 * directly rather than through index.php (which has no auth layer either), and
 * not one branch asked who was calling. From the open internet:
 *
 *     GET    ?path=registrations&program_id=N   name, date of birth, gender of
 *                                               every tryout registrant
 *     GET    ?path=rankings&program_id=N        scores and rankings
 *     POST   ?path=send-offers                  offered / waitlisted / cut athletes
 *     POST   ?path=add-to-roster                put an athlete on a team
 *     POST   ?path=create                       created a program, club 1 by default
 *     DELETE ?path=evaluations&id=N             deleted an evaluation by integer id
 *
 * Exactly one path is public — GET ?path=sessions — because
 * PublicTryoutRegistration.tsx shows a family the dates and locations before
 * they have an account, and that is the only tryouts-api call that page makes.
 *
 * Parse-based on purpose, and a SCAN rather than a spot check: there are 17
 * non-public cases across four handlers, the predicates were never wrong, and
 * the whole failure mode of this class of bug is one branch that nobody wired
 * up. Fixing sixteen and missing one is the same shape as
 * ParentPortalChildScopeTest and MysqlOnlySqlTest.
 */
class TryoutsApiScopeTest extends TestCase
{
    private const API = __DIR__ . '/../../registration/tryouts-api.php';

    /**
     * Every `case` in the four handlers, and the standing it must demand.
     *
     * 'public' is the single unauthenticated path. 'staff' is club admin OR
     * coach in the club that owns the program. 'admin' is club admin only.
     */
    private const CASES = [
        'handleGet' => [
            'sessions'      => 'public',
            'criteria'      => 'staff',
            'registrations' => 'staff',
            'evaluations'   => 'staff',
            'rankings'      => 'staff',
            'offers'        => 'staff',
            'coach-invites' => 'staff',
        ],
        'handlePost' => [
            'create'          => 'admin',
            'sessions'        => 'staff',
            'criteria'        => 'staff',
            'check-in'        => 'staff',
            'evaluate'        => 'staff',
            'send-offers'     => 'staff',
            'update-offer'    => 'staff',
            'add-to-roster'   => 'staff',
            'update-rankings' => 'staff',
            // Slice 8.2 — a coach claiming a tryout registrant, and closing the
            // loop on that claim.
            'coach-invite'        => 'staff',
            'coach-invite-status' => 'staff',
        ],
        'handlePut' => [
            'update'   => 'staff',
            'sessions' => 'staff',
            'criteria' => 'staff',
        ],
        'handleDelete' => [
            'sessions'    => 'staff',
            'criteria'    => 'staff',
            'evaluations' => 'staff',
        ],
    ];

    /**
     * Strip comments before any "must NOT contain" or ordering assertion.
     *
     * The comments in this file name the predicate that was wrong and quote the
     * `?? 1` default they replaced, so a scanner reading prose flags the
     * documentation as the defect. Same lesson as MysqlOnlySqlTest needing a SQL
     * keyword near the match.
     */
    private function code(string $src): string
    {
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

    /** Function bodies, keyed by name, from a brace-counted scan. */
    private function functions(string $path): array
    {
        $src = file_get_contents($path);
        $out = [];

        // The `(?::\s*...)?` is load-bearing: the scope helpers declare return
        // types (`: ?string`), and without it they are invisible to this scan —
        // which silently turns the assertions below into no-ops.
        $pattern = '/function\s+(\w+)\s*\([^)]*\)\s*(?::\s*\??[\w\\\\|]+\s*)?\{/';
        if (!preg_match_all($pattern, $src, $m, PREG_OFFSET_CAPTURE)) {
            return $out;
        }

        foreach ($m[1] as $i => $hit) {
            $start = $m[0][$i][1] + strlen($m[0][$i][0]) - 1;
            $depth = 0;
            $len = strlen($src);
            for ($p = $start; $p < $len; $p++) {
                if ($src[$p] === '{') {
                    $depth++;
                } elseif ($src[$p] === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $out[$hit[0]] = substr($src, $start, $p - $start + 1);
                        break;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * The body of one `case 'x':` arm, comments stripped — up to the next arm
     * at the same indentation, or `default:`.
     */
    private function caseBody(string $handlerBody, string $path): ?string
    {
        $code = $this->code($handlerBody);
        $needle = "case '" . $path . "':";
        $start = strpos($code, $needle);
        if ($start === false) {
            return null;
        }
        $start += strlen($needle);

        $end = strlen($code);
        if (preg_match('/\n\s+(case\s+\'|default\s*:)/', substr($code, $start), $m, PREG_OFFSET_CAPTURE)) {
            $end = $start + $m[0][1];
        }

        return substr($code, $start, $end - $start);
    }

    public function testTheFileAuthenticates(): void
    {
        $code = $this->code(file_get_contents(self::API));

        $this->assertStringContainsString(
            'AuthMiddleware::requireAuth()',
            $code,
            'registration/tryouts-api.php does not authenticate. Nothing upstream '
            . 'does it for this file — it is reached directly, and index.php has no '
            . 'auth layer.'
        );
    }

    /**
     * GET ?path=sessions is the ONE public path, and it must stay public: the
     * public registration page has no token to send.
     */
    public function testTheSessionListStaysPublic(): void
    {
        $handlers = $this->functions(self::API);
        $body = $this->caseBody($handlers['handleGet'], 'sessions');

        $this->assertNotNull($body, "handleGet has no case 'sessions'");
        $this->assertStringNotContainsString(
            'tryout_require',
            $body,
            "GET ?path=sessions must stay unauthenticated. PublicTryoutRegistration.tsx "
            . "fetches it with no token to show a family the dates and locations."
        );
    }

    /**
     * Every other case gates BEFORE it touches the database. Order is the whole
     * assertion: a check after the query has already read or written the row is
     * not a check.
     */
    public function testEveryNonPublicCaseGatesBeforeItQueries(): void
    {
        $handlers = $this->functions(self::API);

        foreach (self::CASES as $handler => $paths) {
            $this->assertArrayHasKey($handler, $handlers, "{$handler} not found");

            foreach ($paths as $path => $standing) {
                if ($standing === 'public') {
                    continue;
                }

                $body = $this->caseBody($handlers[$handler], $path);
                $this->assertNotNull($body, "{$handler} has no case '{$path}'");

                $guard = strpos($body, 'tryout_require');
                $this->assertNotFalse(
                    $guard,
                    "{$handler} case '{$path}' has no scope check. Every path but "
                    . "GET ?path=sessions must resolve the owning club and demand "
                    . "staff standing in it."
                );

                $query = strpos($body, '$connection->prepare(');
                if ($query !== false) {
                    $this->assertLessThan(
                        $query,
                        $guard,
                        "{$handler} case '{$path}' queries before it checks scope. "
                        . "A check that runs after the read or the write is not a check."
                    );
                }
            }
        }
    }

    /**
     * `create` is the one path with no program to resolve a club from, so it
     * takes club_id from the body — and standing is checked against THAT club.
     * Club-admin only (Maggie, 2026-09-02): creating a tryout is an admin action.
     */
    public function testCreateIsAdminGatedInTheClubItNames(): void
    {
        $handlers = $this->functions(self::API);
        $body = $this->caseBody($handlers['handlePost'], 'create');

        $this->assertStringContainsString(
            'tryout_requireClubAdminForClub',
            $body,
            "POST ?path=create must be gated on the club_id it is given."
        );
    }

    /**
     * The `?? 1` default put a program into club 1 whenever the body omitted
     * club_id. A body-supplied club is unavoidable on this one path; a
     * body-supplied club with a silent fallback is not.
     */
    public function testTheClubIdDefaultIsGone(): void
    {
        $code = $this->code(file_get_contents(self::API));

        $this->assertStringNotContainsString(
            "\$data['club_id'] ?? 1",
            $code,
            "`\$data['club_id'] ?? 1` is back. An omitted club_id silently created "
            . "the program in club 1; it must be required instead."
        );
    }

    /**
     * The standing predicate must be the STAFF one. `canAccessClub()` is club
     * MEMBERSHIP — a `parent` row satisfies it, which is exactly how
     * handleClubParents handed every guardian in a club to any parent in it.
     */
    public function testTheHelperUsesTheStaffPredicateAndNotClubMembership(): void
    {
        $helper = $this->code($this->functions(self::API)['tryout_clubStanding']);

        $this->assertMatchesRegularExpression(
            '/te_is_club_(staff|admin)\(/',
            $helper,
            'tryout_clubStanding must decide standing with te_is_club_staff / '
            . 'te_is_club_admin from lib/club_standing.php.'
        );
        $this->assertStringNotContainsString(
            'canAccessClub',
            $helper,
            'tryout_clubStanding uses canAccessClub(), which is club MEMBERSHIP. '
            . 'A parent, player, volunteer or treasurer passes it.'
        );
    }

    // ------------------------------------------------------------------
    // Functional: the predicate itself, against SQLite.
    // ------------------------------------------------------------------

    private function db(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("
            CREATE TABLE programs (id INTEGER PRIMARY KEY, club_id INTEGER);
            CREATE TABLE registrations (id INTEGER PRIMARY KEY, program_id INTEGER);
            CREATE TABLE tryout_offers (id INTEGER PRIMARY KEY, registration_id INTEGER);
            CREATE TABLE tryout_evaluations (id INTEGER PRIMARY KEY, registration_id INTEGER);
            CREATE TABLE tryout_sessions (id INTEGER PRIMARY KEY, program_id INTEGER);
            CREATE TABLE tryout_evaluation_criteria (id INTEGER PRIMARY KEY, program_id INTEGER);

            INSERT INTO programs (id, club_id) VALUES (900, 51);
            INSERT INTO registrations (id, program_id) VALUES (7000, 900);
            INSERT INTO tryout_offers (id, registration_id) VALUES (8000, 7000);
            INSERT INTO tryout_evaluations (id, registration_id) VALUES (8100, 7000);
            INSERT INTO tryout_sessions (id, program_id) VALUES (8200, 900);
            INSERT INTO tryout_evaluation_criteria (id, program_id) VALUES (8300, 900);
        ");

        return $pdo;
    }

    private function auth(string $role, int $clubId, bool $superAdmin = false): AuthMiddleware
    {
        return AuthMiddleware::fromContext([
            'user_id' => 1,
            'system_role' => $superAdmin ? 'super_admin' : 'user',
            'roles' => [
                ['role' => $role, 'scope_id' => $clubId, 'scope_type' => 'club'],
            ],
        ]);
    }

    /**
     * The reported shape: staff at one club reaching another club's tryout.
     */
    public function testAClubAdminOfAnotherClubHasNoStanding(): void
    {
        $this->assertNull(
            tryout_clubStaffStanding($this->db(), $this->auth('club_admin', 32), 900),
            'A club_admin of club 32 must have no standing on a program owned by club 51.'
        );
    }

    public function testStaffOfTheOwningClubHaveStanding(): void
    {
        $db = $this->db();

        $this->assertSame('admin', tryout_clubStaffStanding($db, $this->auth('club_admin', 51), 900));
        $this->assertSame('staff', tryout_clubStaffStanding($db, $this->auth('coach', 51), 900));
        $this->assertSame(
            'admin',
            tryout_clubStaffStanding($db, $this->auth('parent', 99, true), 900),
            'A super admin is admin everywhere.'
        );
    }

    /**
     * Membership in the club is not staff standing. A parent of a tryout
     * registrant is in club 51 and must still not read the club's tryout list.
     */
    public function testClubMembershipIsNotStaffStanding(): void
    {
        $db = $this->db();

        foreach (['parent', 'player', 'volunteer', 'treasurer'] as $role) {
            $this->assertNull(
                tryout_clubStaffStanding($db, $this->auth($role, 51), 900),
                "A {$role} in club 51 must have no standing on that club's tryout."
            );
        }
    }

    /**
     * A program that does not exist is not a pass. Answering "no club known" and
     * letting the handler run turns the check into a no-op for any invented id.
     */
    public function testAMissingProgramIsNotAPass(): void
    {
        $db = $this->db();

        $this->assertNull(tryout_clubStaffStanding($db, $this->auth('club_admin', 51), 999999));
        $this->assertNull(tryout_clubStaffStanding($db, $this->auth('club_admin', 51), 0));
        $this->assertNull(tryout_programClubId($db, 999999));
    }

    /**
     * The indirect ids all have to land on the same program, or the guard checks
     * the wrong club — which is worse than no guard, because it looks checked.
     */
    public function testEveryIndirectIdResolvesToTheOwningProgram(): void
    {
        $db = $this->db();

        $this->assertSame(900, tryout_programIdForRegistration($db, 7000));
        $this->assertSame(900, tryout_programIdForOffer($db, 8000));
        $this->assertSame(900, tryout_programIdForEvaluation($db, 8100));
        $this->assertSame(900, tryout_programIdForSession($db, 8200));
        $this->assertSame(900, tryout_programIdForCriterion($db, 8300));

        foreach ([0, -1, 999999] as $missing) {
            $this->assertNull(tryout_programIdForRegistration($db, $missing));
            $this->assertNull(tryout_programIdForOffer($db, $missing));
            $this->assertNull(tryout_programIdForEvaluation($db, $missing));
            $this->assertNull(tryout_programIdForSession($db, $missing));
            $this->assertNull(tryout_programIdForCriterion($db, $missing));
        }
    }
}
