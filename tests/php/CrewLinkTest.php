<?php

use PHPUnit\Framework\TestCase;

if (!defined('TE_CREW_LINK_LIB_ONLY')) {
    define('TE_CREW_LINK_LIB_ONLY', true);
}
require_once __DIR__ . '/../../api/crew-link.php';

/**
 * Auth double. `te_is_club_admin()` asks exactly two questions — isSuperAdmin()
 * and hasRole('club_admin', $clubId, 'club') — and the point of several of these
 * tests is that nothing else is consulted, so `canAccessClub()` deliberately
 * answers TRUE for everyone here. If the endpoint ever regresses to that
 * predicate the tests below go green on a parent, which is the failure this
 * class exists to make loud.
 */
class FakeCrewLinkAuth
{
    /** @param array<int, string[]> $rolesByClub */
    public function __construct(
        private int $userId = 900,
        private array $rolesByClub = [],
        private bool $superAdmin = false
    ) {
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function isSuperAdmin(): bool
    {
        return $this->superAdmin;
    }

    /** Membership, not standing. Always true — see the class docblock. */
    public function canAccessClub($clubProfileId): bool
    {
        return true;
    }

    public function hasRole($role, $clubProfileId = null, $scopeType = null): bool
    {
        if ($this->superAdmin) {
            return true;
        }
        $roles = $this->rolesByClub[(int) $clubProfileId] ?? [];
        return in_array($role, $roles, true);
    }
}

/**
 * The club-admin tool for connecting a signed-in account to its guardian record
 * — `api/crew-link.php`, phase 3 of docs/user-guardians-identity-plan.md.
 *
 * The family this exists for is Allix Boyce: an account on @gmail, a guardian
 * row on @yahoo, a valid `parent` role, and a parent portal that says no
 * athletes are registered to her. `te_guardian_ids_for_user()` returns nothing
 * for that account and there is no repair anyone can perform from inside the
 * product — which is why the fix is a recorded row rather than editing one of
 * the two addresses to match the other.
 *
 * What is pinned here, and why each one:
 *
 *  - **Candidates are the accounts the RESOLVER cannot place.** An account that
 *    already resolves — by a recorded link OR by the email match — is not stuck
 *    and must not be offered, or an admin spends their time connecting families
 *    who are already fine and eventually connects one to the wrong guardian.
 *  - **Both parties are scoped to the admin's club, from different evidence.**
 *    A cross-club user or a cross-club guardian is refused. Without this, a club
 *    admin holds a two-integer endpoint that attaches any adult on the platform
 *    to any child on the platform.
 *  - **A guardian already linked to a different account is a 409 naming that
 *    account**, not a silent second row and not a silent refusal.
 *  - **A successful link makes `te_athlete_ids_for_user()` non-empty.** That is
 *    the only assertion that proves the feature did the thing it is for; a row
 *    in `user_guardians` that resolves to no athletes is a link pointed at the
 *    wrong guardian and looks identical to a correct one.
 *  - **Both writes call `te_db_set_actor()` and `AuditLogger`** — asserted by
 *    parsing, because in SQLite both degrade silently (set_config() and NOW()
 *    are Postgres). Silent degradation is their production contract too, so the
 *    only place the call can be proven to exist is the source.
 *  - **The file gates on `te_is_club_admin` and never on `canAccessClub`.** A
 *    `parent` row satisfies the second one. This endpoint decides who may read a
 *    minor's medical record.
 */
class CrewLinkTest extends TestCase
{
    private PDO $pdo;

    private const CLUB = 51;
    private const OTHER_CLUB = 32;

    private const ADMIN = 900;

    // The stuck family: signs in on @gmail, guardian row says @yahoo.
    private const ALLIX_USER = 10;
    private const ALLIX_GUARDIAN = 110;

    // Resolves already, by the email match. Not stuck.
    private const EMILY_USER = 11;

    // Resolves already, by a recorded link whose addresses have drifted.
    private const LINKED_USER = 12;
    private const LINKED_GUARDIAN = 112;

    // Another club entirely.
    private const OTHER_USER = 13;
    private const OTHER_GUARDIAN = 113;

    // Held by LINKED_USER, so connecting anyone else to it is a 409.
    private const CLAIMED_GUARDIAN = 112;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // Column names mirror tests/fixtures/production-schema.json.
        $this->pdo->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY, email TEXT NOT NULL,
                first_name TEXT, last_name TEXT, last_login_at TEXT
            );
            CREATE TABLE guardians (
                id INTEGER PRIMARY KEY, first_name TEXT NOT NULL, last_name TEXT NOT NULL,
                email TEXT NOT NULL, mobile_phone TEXT NOT NULL
            );
            CREATE TABLE athletes (
                id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT,
                club_id INTEGER, deleted_at TEXT
            );
            CREATE TABLE athlete_guardians (
                id INTEGER PRIMARY KEY, athlete_id INTEGER, guardian_id INTEGER
            );
            CREATE TABLE user_club_access (
                id INTEGER PRIMARY KEY, user_id INTEGER, club_profile_id INTEGER,
                role TEXT, active INTEGER, revoked_at TEXT
            );
            CREATE TABLE user_guardians (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, guardian_id INTEGER,
                source TEXT, confidence TEXT, linked_by INTEGER, created_at TEXT,
                UNIQUE (user_id, guardian_id)
            );
        ");

        $p = $this->pdo;

        $p->exec("INSERT INTO users (id, email, first_name, last_name, last_login_at) VALUES
            (10, 'allix@gmail.com',        'Allix', 'Boyce',  '2026-08-30 10:00:00'),
            (11, 'emilygovier0@gmail.com', 'Emily', 'Govier', '2026-08-29 10:00:00'),
            (12, 'drifted@gmail.com',      'Drift', 'Ed',     NULL),
            (13, 'other@club.test',        'Other', 'Club',   NULL),
            (900,'admin@club.test',        'Leya',  'Admin',  NULL)");

        $p->exec("INSERT INTO guardians (id, first_name, last_name, email, mobile_phone) VALUES
            (110, 'Allix',  'Boyce',  'allix@yahoo.com',           '7855550110'),
            (111, 'Emily',  'Govier', 'Emilygovier0@gmail.com',    '7855550111'),
            (112, 'Drift',  'Ed',     'drifted@yahoo.com',         '7855550112'),
            (113, 'Other',  'Club',   'otherguardian@club.test',   '7855550113'),
            (114, 'Bertie', 'Boyce',  'bertie@example.test',       '7855550114'),
            (115, 'Allix',  'Nguyen', 'allixn@example.test',       '7855550115'),
            (116, 'Nobody', 'Related','nobody@example.test',       '7855550116'),
            (117, 'Ghost',  'Boyce',  'ghost@example.test',        '7855550117')");

        $p->exec("INSERT INTO athletes (id, first_name, last_name, club_id, deleted_at) VALUES
            (210, 'Ava',    'Boyce',  51, NULL),
            (211, 'Bo',     'Boyce',  51, NULL),
            (212, 'Emmy',   'Govier', 51, NULL),
            (213, 'Dee',    'Ed',     51, NULL),
            (214, 'Nia',    'Nguyen', 51, NULL),
            (215, 'Sam',    'Related',51, NULL),
            (216, 'Gone',   'Boyce',  51, '2026-01-01'),
            (219, 'Away',   'Club',   32, NULL)");

        $p->exec("INSERT INTO athlete_guardians (id, athlete_id, guardian_id) VALUES
            (1, 210, 110), (2, 211, 110),
            (3, 212, 111),
            (4, 213, 112),
            (5, 219, 113),
            (6, 214, 115),
            (7, 215, 116),
            (8, 216, 117)"); // guardian 117's only athlete is soft-deleted

        $p->exec("INSERT INTO user_club_access (id, user_id, club_profile_id, role, active, revoked_at) VALUES
            (1, 10,  51, 'parent',     1, NULL),
            (2, 11,  51, 'parent',     1, NULL),
            (3, 12,  51, 'parent',     1, NULL),
            (4, 13,  32, 'parent',     1, NULL),
            (5, 900, 51, 'club_admin', 1, NULL)");

        // The drifted account is already recorded. It must not appear as stuck,
        // and its guardian must not be offered to anyone else.
        $p->exec("INSERT INTO user_guardians (user_id, guardian_id, source, confidence, linked_by)
            VALUES (12, 112, 'backfill_email', 'exact', NULL)");
    }

    private function admin(): FakeCrewLinkAuth
    {
        return new FakeCrewLinkAuth(self::ADMIN, [self::CLUB => ['club_admin']]);
    }

    private function parent(): FakeCrewLinkAuth
    {
        return new FakeCrewLinkAuth(self::ALLIX_USER, [self::CLUB => ['parent']]);
    }

    private function coach(): FakeCrewLinkAuth
    {
        return new FakeCrewLinkAuth(800, [self::CLUB => ['coach']]);
    }

    // ── candidates ──────────────────────────────────────────────────────────

    public function testCandidatesListsOnlyAccountsTheResolverCannotPlace(): void
    {
        $res = te_crew_link_candidates($this->pdo, $this->admin(), self::CLUB);

        $this->assertSame(200, $res['status']);
        $ids = array_column($res['body']['candidates'], 'user_id');

        $this->assertSame([self::ALLIX_USER], $ids,
            'Only the account with no recorded link and no email match is stuck.');
    }

    public function testAnAccountResolvingByEmailIsNotACandidate(): void
    {
        // Emily's guardian row is capitalised differently; the resolver's LOWER()
        // comparison still finds it, so she is not stuck and offering her would
        // invite an admin to record a link she does not need.
        $res = te_crew_link_candidates($this->pdo, $this->admin(), self::CLUB);
        $ids = array_column($res['body']['candidates'], 'user_id');

        $this->assertNotContains(self::EMILY_USER, $ids);
        $this->assertNotEmpty(te_guardian_ids_for_user($this->pdo, self::EMILY_USER));
    }

    public function testAnAccountResolvingByARecordedLinkIsNotACandidate(): void
    {
        $res = te_crew_link_candidates($this->pdo, $this->admin(), self::CLUB);
        $ids = array_column($res['body']['candidates'], 'user_id');

        $this->assertNotContains(self::LINKED_USER, $ids);
    }

    public function testCandidatesCarryTheContactFactsAnAdminNeeds(): void
    {
        $res = te_crew_link_candidates($this->pdo, $this->admin(), self::CLUB);
        $allix = $res['body']['candidates'][0];

        $this->assertSame('Allix', $allix['first_name']);
        $this->assertSame('Boyce', $allix['last_name']);
        $this->assertSame('allix@gmail.com', $allix['email']);
        $this->assertSame('2026-08-30 10:00:00', $allix['last_login_at']);
    }

    public function testSuggestionsRankLastNameAboveFirstNameAndCarryTheAthletes(): void
    {
        $res = te_crew_link_candidates($this->pdo, $this->admin(), self::CLUB);
        $suggestions = $res['body']['candidates'][0]['suggestions'];

        $byId = [];
        foreach ($suggestions as $s) {
            $byId[$s['guardian_id']] = $s;
        }

        // 110 Allix Boyce — both names. 114 Bertie Boyce and 117 Ghost Boyce —
        // surname. 115 Allix Nguyen — first name only. 116 is unrelated.
        $this->assertSame(110, $suggestions[0]['guardian_id'], 'Both names beats one.');
        $this->assertSame('first_and_last_name', $suggestions[0]['match']);
        $this->assertArrayHasKey(115, $byId);
        $this->assertSame('first_name', $byId[115]['match']);
        $this->assertArrayNotHasKey(116, $byId, 'No shared name is not a suggestion.');

        $this->assertSame(
            ['Ava Boyce', 'Bo Boyce'],
            array_map(
                static fn (array $a): string => $a['first_name'] . ' ' . $a['last_name'],
                $byId[110]['athletes']
            )
        );
    }

    public function testAGuardianWhoseOnlyAthleteIsSoftDeletedIsNotSuggested(): void
    {
        // Guardian 117 shares the surname, but has no live athlete in the club,
        // so connecting to them would resolve to nothing.
        $res = te_crew_link_candidates($this->pdo, $this->admin(), self::CLUB);
        $ids = array_column($res['body']['candidates'][0]['suggestions'], 'guardian_id');

        $this->assertNotContains(117, $ids);
    }

    public function testAGuardianAlreadyCarryingALinkIsNotSuggested(): void
    {
        $this->pdo->exec("INSERT INTO user_guardians (user_id, guardian_id, source, confidence, linked_by)
            VALUES (11, 114, 'admin_link', 'manual', 900)");

        $res = te_crew_link_candidates($this->pdo, $this->admin(), self::CLUB);
        $ids = array_column($res['body']['candidates'][0]['suggestions'], 'guardian_id');

        $this->assertNotContains(114, $ids);
    }

    public function testASuggestionReachableByAnotherAccountIsDisclosedNotHidden(): void
    {
        // A second account holding the guardian's own address. This is the
        // two-logins-one-person case migration 072 says to link both times, so
        // it stays offered — but the admin has to be told before deciding.
        $this->pdo->exec("INSERT INTO users (id, email, first_name, last_name)
            VALUES (14, 'allix@yahoo.com', 'Allix', 'Boyce')");

        $res = te_crew_link_candidates($this->pdo, $this->admin(), self::CLUB);
        $byId = array_column($res['body']['candidates'][0]['suggestions'], null, 'guardian_id');

        $this->assertArrayHasKey(110, $byId);
        $this->assertSame(14, $byId[110]['already_reachable_by']['user_id']);
        $this->assertNull($byId[115]['already_reachable_by']);
    }

    public function testACandidateInAnotherClubIsNotListed(): void
    {
        $res = te_crew_link_candidates($this->pdo, $this->admin(), self::CLUB);
        $ids = array_column($res['body']['candidates'], 'user_id');

        $this->assertNotContains(self::OTHER_USER, $ids);
    }

    // ── standing ────────────────────────────────────────────────────────────

    public function testAParentCannotListCandidates(): void
    {
        $res = te_crew_link_candidates($this->pdo, $this->parent(), self::CLUB);
        $this->assertSame(403, $res['status']);
    }

    public function testACoachCannotListCandidates(): void
    {
        // Deliberate: a coach maintains a team, not the club's identity records.
        $res = te_crew_link_candidates($this->pdo, $this->coach(), self::CLUB);
        $this->assertSame(403, $res['status']);
    }

    public function testAParentCannotLinkOrUnlink(): void
    {
        $link = te_crew_link_connect($this->pdo, $this->parent(), self::CLUB, self::ALLIX_USER, self::ALLIX_GUARDIAN);
        $this->assertSame(403, $link['status']);

        $unlink = te_crew_link_disconnect($this->pdo, $this->parent(), self::CLUB, self::LINKED_USER, self::LINKED_GUARDIAN);
        $this->assertSame(403, $unlink['status']);

        $this->assertSame(0, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM user_guardians WHERE user_id = 10'
        )->fetchColumn());
    }

    public function testAnAdminOfAnotherClubIsRefused(): void
    {
        $foreign = new FakeCrewLinkAuth(901, [self::OTHER_CLUB => ['club_admin']]);
        $res = te_crew_link_connect($this->pdo, $foreign, self::CLUB, self::ALLIX_USER, self::ALLIX_GUARDIAN);

        $this->assertSame(403, $res['status']);
    }

    // ── link ────────────────────────────────────────────────────────────────

    public function testASuccessfulLinkMakesTheFamilyResolvable(): void
    {
        $this->assertSame([], te_athlete_ids_for_user($this->pdo, self::ALLIX_USER));

        $res = te_crew_link_connect($this->pdo, $this->admin(), self::CLUB, self::ALLIX_USER, self::ALLIX_GUARDIAN);

        $this->assertSame(201, $res['status']);
        $this->assertTrue($res['body']['success']);
        $this->assertSame([210, 211], te_athlete_ids_for_user($this->pdo, self::ALLIX_USER));
        $this->assertSame(
            ['Ava Boyce', 'Bo Boyce'],
            array_map(
                static fn (array $a): string => $a['first_name'] . ' ' . $a['last_name'],
                $res['body']['athletes']
            )
        );
    }

    public function testTheRowRecordsHowItGotThere(): void
    {
        te_crew_link_connect($this->pdo, $this->admin(), self::CLUB, self::ALLIX_USER, self::ALLIX_GUARDIAN);

        $row = $this->pdo->query(
            'SELECT source, confidence, linked_by FROM user_guardians WHERE user_id = 10 AND guardian_id = 110'
        )->fetch();

        // Migration 072's vocabulary, not free text. A backfilled string match
        // and a named admin's click must stay distinguishable forever.
        $this->assertSame('admin_link', $row['source']);
        $this->assertSame('manual', $row['confidence']);
        $this->assertSame(self::ADMIN, (int) $row['linked_by']);
    }

    public function testLinkingRemovesTheAccountFromTheCandidateList(): void
    {
        te_crew_link_connect($this->pdo, $this->admin(), self::CLUB, self::ALLIX_USER, self::ALLIX_GUARDIAN);

        $res = te_crew_link_candidates($this->pdo, $this->admin(), self::CLUB);
        $this->assertSame([], $res['body']['candidates']);
    }

    public function testACrossClubUserIsRefused(): void
    {
        $res = te_crew_link_connect($this->pdo, $this->admin(), self::CLUB, self::OTHER_USER, self::ALLIX_GUARDIAN);

        $this->assertSame(404, $res['status']);
        $this->assertSame(0, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM user_guardians WHERE user_id = 13'
        )->fetchColumn());
    }

    public function testACrossClubGuardianIsRefused(): void
    {
        $res = te_crew_link_connect($this->pdo, $this->admin(), self::CLUB, self::ALLIX_USER, self::OTHER_GUARDIAN);

        $this->assertSame(404, $res['status']);
        $this->assertSame(0, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM user_guardians WHERE guardian_id = 113'
        )->fetchColumn());
    }

    public function testAGuardianHeldByAnotherAccountIsA409NamingThem(): void
    {
        $res = te_crew_link_connect($this->pdo, $this->admin(), self::CLUB, self::ALLIX_USER, self::CLAIMED_GUARDIAN);

        $this->assertSame(409, $res['status']);
        $this->assertSame('guardian_already_linked', $res['body']['reason']);
        $this->assertSame(self::LINKED_USER, $res['body']['linked_to']['user_id']);
        $this->assertSame('drifted@gmail.com', $res['body']['linked_to']['email']);

        // And nothing was written on the way to refusing.
        $this->assertSame(1, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM user_guardians WHERE guardian_id = 112'
        )->fetchColumn());
    }

    public function testRelinkingTheSamePairIsIdempotentRatherThanAConstraintError(): void
    {
        te_crew_link_connect($this->pdo, $this->admin(), self::CLUB, self::ALLIX_USER, self::ALLIX_GUARDIAN);
        $again = te_crew_link_connect($this->pdo, $this->admin(), self::CLUB, self::ALLIX_USER, self::ALLIX_GUARDIAN);

        $this->assertSame(200, $again['status']);
        $this->assertTrue($again['body']['already_linked']);
        $this->assertSame(1, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM user_guardians WHERE user_id = 10 AND guardian_id = 110'
        )->fetchColumn());
    }

    public function testMissingIdsAreRefusedBeforeAnythingIsRead(): void
    {
        $this->assertSame(400, te_crew_link_connect($this->pdo, $this->admin(), self::CLUB, 0, self::ALLIX_GUARDIAN)['status']);
        $this->assertSame(400, te_crew_link_connect($this->pdo, $this->admin(), self::CLUB, self::ALLIX_USER, 0)['status']);
        $this->assertSame(400, te_crew_link_candidates($this->pdo, $this->admin(), 0)['status']);
    }

    // ── unlink ──────────────────────────────────────────────────────────────

    public function testUnlinkRemovesTheRowAndReportsWhatIsLeft(): void
    {
        $res = te_crew_link_disconnect($this->pdo, $this->admin(), self::CLUB, self::LINKED_USER, self::LINKED_GUARDIAN);

        $this->assertSame(200, $res['status']);
        $this->assertSame([], $res['body']['athletes']);
        $this->assertSame([], te_athlete_ids_for_user($this->pdo, self::LINKED_USER));
        $this->assertSame(0, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM user_guardians WHERE user_id = 12'
        )->fetchColumn());
    }

    public function testUnlinkingAPairThatIsNotConnectedIs404(): void
    {
        $res = te_crew_link_disconnect($this->pdo, $this->admin(), self::CLUB, self::ALLIX_USER, self::ALLIX_GUARDIAN);
        $this->assertSame(404, $res['status']);
    }

    // ── source-parse guards ─────────────────────────────────────────────────
    //
    // In SQLite both te_db_set_actor() (set_config) and AuditLogger (NOW())
    // degrade to a swallowed error, which is also their production contract. So
    // the only place their presence can be proven is the source.

    /** @return string the body of one top-level function in api/crew-link.php */
    private function functionBody(string $name): string
    {
        $src = file_get_contents(__DIR__ . '/../../api/crew-link.php');
        $start = strpos($src, "function {$name}(");
        $this->assertNotFalse($start, "api/crew-link.php no longer defines {$name}()");

        $brace = strpos($src, '{', $start);
        $depth = 0;
        for ($i = $brace, $n = strlen($src); $i < $n; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $brace, $i - $brace + 1);
                }
            }
        }

        $this->fail("Could not read the body of {$name}()");
    }

    public function testBothWritesTellTheDatabaseWhoIsActing(): void
    {
        foreach (['te_crew_link_connect', 'te_crew_link_disconnect'] as $fn) {
            $body = $this->functionBody($fn);

            $this->assertStringContainsString('te_db_set_actor(', $body,
                "{$fn}() must set app.user_id, or migration 072's trigger records the change as nobody's.");

            // Order matters: the trigger reads the GUC during the write.
            $actorAt = strpos($body, 'te_db_set_actor(');
            $writeAt = $fn === 'te_crew_link_connect'
                ? strpos($body, 'INSERT INTO user_guardians')
                : strpos($body, 'DELETE FROM user_guardians');
            $this->assertNotFalse($writeAt);
            $this->assertLessThan($writeAt, $actorAt,
                "{$fn}() must set the actor BEFORE the write, not after.");
        }
    }

    public function testBothWritesAreAudited(): void
    {
        $this->assertStringContainsString(
            "'guardian_account_linked'",
            $this->functionBody('te_crew_link_connect')
        );
        $this->assertStringContainsString(
            "'guardian_account_unlinked'",
            $this->functionBody('te_crew_link_disconnect')
        );
    }

    public function testTheFileNeverGatesOnCanAccessClub(): void
    {
        $src = file_get_contents(__DIR__ . '/../../api/crew-link.php');

        // `canAccessClub` is club MEMBERSHIP and a parent row satisfies it. It
        // may appear in prose; it must never be called.
        $this->assertSame(
            0,
            preg_match_all('/->\s*canAccessClub\s*\(/', $src),
            'api/crew-link.php must gate on te_is_club_admin, never on canAccessClub.'
        );
        $this->assertStringContainsString('te_is_club_admin(', $src);
    }

    public function testEveryHandlerChecksClubAdminStanding(): void
    {
        foreach (['te_crew_link_candidates', 'te_crew_link_connect', 'te_crew_link_disconnect'] as $fn) {
            $this->assertStringContainsString('te_is_club_admin(', $this->functionBody($fn),
                "{$fn}() must check club-admin standing itself — nothing upstream does it.");
        }
    }
}
