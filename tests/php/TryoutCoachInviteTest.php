<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;

require_once __DIR__ . '/../../lib/tryout_coach_invite.php';

/**
 * "Coach invited player" (CKU R86, slice 8.2).
 *
 * Two halves, and both are the point:
 *
 *  - PARSE. The three new tryouts-api cases must gate on
 *    `tryout_requireClubStaff` before any write, must consult the kill switch
 *    AFTER the row is written (a claim that is recorded but not emailed is a
 *    correct outcome; an email with no row is not), and must tolerate migration
 *    087 being unapplied. The predicates were never wrong in this repo's bugs —
 *    which branch called them was.
 *
 *  - SQLITE. The idempotency the unique constraint promises: one coach pressing
 *    twice is one claim, two coaches wanting the same player is two, and a
 *    status transition does not disturb either.
 *
 * Nothing here touches SendGrid or Neon; the transport is injected.
 */
class TryoutCoachInviteTest extends TestCase
{
    private const API = __DIR__ . '/../../registration/tryouts-api.php';

    private PDO $pdo;

    /** Emails captured instead of sent. */
    private array $sent = [];

    protected function setUp(): void
    {
        $this->sent = [];
        $this->pdo = $this->db(true);
    }

    /**
     * @param bool $withInvitesTable False builds the database as production
     *                               looks BEFORE migration 087 runs.
     */
    private function db(bool $withInvitesTable): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // Mirrors tests/fixtures/production-schema.json for the columns used
        // here. A fixture that does not mirror the live shape is worse than no
        // fixture — MergeFieldServiceTest stayed green for months against a
        // table that had been renamed out from under it.
        $pdo->exec("
            CREATE TABLE club_profile (id INTEGER PRIMARY KEY, name TEXT, email TEXT);
            CREATE TABLE programs (id INTEGER PRIMARY KEY, name TEXT, club_id INTEGER, embed_code TEXT);
            CREATE TABLE teams (id INTEGER PRIMARY KEY, name TEXT, club_id INTEGER, program_id INTEGER);
            CREATE TABLE athletes (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT);
            CREATE TABLE users (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT);
            CREATE TABLE guardians (
                id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT,
                email TEXT, mobile_phone TEXT
            );
            CREATE TABLE athlete_guardians (
                id INTEGER PRIMARY KEY, athlete_id INTEGER, guardian_id INTEGER,
                relationship TEXT, is_primary INTEGER
            );
            CREATE TABLE registrations (
                id INTEGER PRIMARY KEY, program_id INTEGER, athlete_id INTEGER,
                tryout_status TEXT, tryout_number TEXT, assigned_team_id INTEGER,
                registrant_first_name TEXT, registrant_last_name TEXT, registrant_email TEXT
            );
            CREATE TABLE tryout_offers (
                id INTEGER PRIMARY KEY, registration_id INTEGER, offer_type TEXT,
                team_id INTEGER, response TEXT
            );
            CREATE TABLE team_members (
                id INTEGER PRIMARY KEY, team_id INTEGER, athlete_id INTEGER,
                status TEXT, join_date TEXT, leave_date TEXT
            );
        ");

        if ($withInvitesTable) {
            // The shape migration 087 creates, unique constraint included —
            // which is the thing under test.
            $pdo->exec("
                CREATE TABLE tryout_coach_invites (
                    id INTEGER PRIMARY KEY,
                    registration_id INTEGER NOT NULL,
                    team_id INTEGER NULL,
                    invited_by INTEGER NOT NULL,
                    invited_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    email_sent_at TEXT NULL,
                    status TEXT NOT NULL DEFAULT 'invited'
                        CHECK (status IN ('invited', 'registered', 'declined', 'withdrawn')),
                    notes TEXT NULL,
                    UNIQUE (registration_id, invited_by)
                );
            ");
        }

        $pdo->exec("
            INSERT INTO club_profile (id, name, email)
                VALUES (51, 'Central Kansas United', 'office@cku.example');
            INSERT INTO programs (id, name, club_id, embed_code)
                VALUES (10, 'Fall 2026 Tryouts', 51, NULL);
            INSERT INTO programs (id, name, club_id, embed_code)
                VALUES (11, 'Fall 2026 Select', 51, 'FALL26');

            -- Team 7 belongs to the SELECT program, which carries the embed code
            -- the family registers through. Team 8 has no program at all.
            INSERT INTO teams (id, name, club_id, program_id) VALUES (7, 'Thunder U12', 51, 11);
            INSERT INTO teams (id, name, club_id, program_id) VALUES (8, 'Lightning U12', 51, NULL);

            INSERT INTO users (id, first_name, last_name, email) VALUES (61, 'Dana', 'Fields', 'dana@cku.example');
            INSERT INTO users (id, first_name, last_name, email) VALUES (62, 'Rob', 'Hale', 'rob@cku.example');

            INSERT INTO athletes (id, first_name, last_name) VALUES (100, 'Maya', 'Rivera');
            INSERT INTO athletes (id, first_name, last_name) VALUES (200, 'Sam', 'Alvarez');

            -- One household, two guardians, ONE address spelled two ways. The
            -- dedupe key is lowercased, so this must produce ONE email.
            INSERT INTO guardians (id, first_name, last_name, email, mobile_phone)
                VALUES (1, 'John', 'Rivera', 'theRiveras@Gmail.com', '620-555-0101');
            INSERT INTO guardians (id, first_name, last_name, email, mobile_phone)
                VALUES (2, 'Jane', 'Rivera', 'THERIVERAS@gmail.com', '620-555-0102');
            INSERT INTO athlete_guardians (id, athlete_id, guardian_id, relationship, is_primary)
                VALUES (1, 100, 2, 'Parent', 0);
            INSERT INTO athlete_guardians (id, athlete_id, guardian_id, relationship, is_primary)
                VALUES (2, 100, 1, 'Parent', 1);

            INSERT INTO registrations (id, program_id, athlete_id, tryout_number, registrant_first_name, registrant_last_name, registrant_email)
                VALUES (1000, 10, 100, '12', 'John', 'Rivera', 'front-desk@example.com');
            -- No guardian link: the registrant_email fallback.
            INSERT INTO registrations (id, program_id, athlete_id, tryout_number, registrant_first_name, registrant_last_name, registrant_email)
                VALUES (2000, 10, 200, '13', 'dana', 'alvarez', 'Dana.Alvarez@example.com');
        ");

        return $pdo;
    }

    /** A transport that records instead of sending, and can be told to fail. */
    private function recorder(bool $succeed = true): callable
    {
        return function (string $to, string $team, string $by, string $link, string $msg) use ($succeed): bool {
            $this->sent[] = ['to' => $to, 'team' => $team, 'by' => $by, 'link' => $link, 'message' => $msg];
            return $succeed;
        };
    }

    // ========================================================================
    // PARSE — the handler
    // ========================================================================

    /** Comments stripped, so prose describing a bug is not read as the bug. */
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

    /** The body of one `case 'x':` arm, comments stripped. */
    private function caseBody(string $path): string
    {
        $code = $this->code(file_get_contents(self::API));
        $needle = "case '" . $path . "':";
        $start = strpos($code, $needle);
        $this->assertNotFalse($start, "tryouts-api.php has no case '{$path}'");
        $start += strlen($needle);

        $end = strlen($code);
        if (preg_match('/\n\s+(case\s+\'|default\s*:)/', substr($code, $start), $m, PREG_OFFSET_CAPTURE)) {
            $end = $start + $m[0][1];
        }

        return substr($code, $start, $end - $start);
    }

    /**
     * Every new case demands club staff standing in the program's club before
     * it writes anything.
     *
     * `coach-invite-status` probes for the table first — resolving its program
     * means reading the table that may not exist — but the scope check still
     * precedes every write.
     */
    public function testEveryNewCaseGatesOnClubStaffBeforeItWrites(): void
    {
        foreach (['coach-invite', 'coach-invite-status', 'coach-invites'] as $path) {
            $body = $this->caseBody($path);

            $guard = strpos($body, 'tryout_requireClubStaff');
            $this->assertNotFalse(
                $guard,
                "case '{$path}' must call tryout_requireClubStaff — nothing upstream "
                . 'authorises this file, and the club must come from the database.'
            );

            foreach (['te_tryout_coach_invite_record', 'te_tryout_coach_invite_set_status',
                      'te_tryout_coach_invite_mark_sent', 'te_tryout_coach_invite_list'] as $write) {
                $at = strpos($body, $write);
                if ($at !== false) {
                    $this->assertLessThan(
                        $at,
                        $guard,
                        "case '{$path}' calls {$write}() before it checks scope. "
                        . 'A check that runs after the write is not a check.'
                    );
                }
            }
        }
    }

    /**
     * The claim is written FIRST, and the send sits inside the kill-switch
     * check after it.
     *
     * Order is the whole assertion. A send before the INSERT can mail a family
     * about a selection that then fails to store; a send outside the switch
     * makes the switch decorative — which is the Phase 2 bug class this slice
     * belongs to.
     */
    public function testTheSendSitsInsideTheSwitchAfterTheRowIsWritten(): void
    {
        $body = $this->caseBody('coach-invite');

        $record = strpos($body, 'te_tryout_coach_invite_record');
        $flag   = strpos($body, "te_feature_enabled('TRYOUT_COACH_INVITE_EMAIL')");
        $send   = strpos($body, 'te_tryout_coach_invite_send');

        $this->assertNotFalse($record, 'the claim must be recorded');
        $this->assertNotFalse($flag, 'the send must be behind TRYOUT_COACH_INVITE_EMAIL');
        $this->assertNotFalse($send, 'the family must actually be emailed');

        $this->assertLessThan($flag, $record, 'the row is written before the switch is consulted');
        $this->assertLessThan($send, $flag, 'the switch is consulted before the send');
    }

    /**
     * invited_by comes from the token, never the body.
     *
     * The entire value of this table is attributing a selection to a person; a
     * body-supplied coach id would make the director's view a list of claims
     * anyone could have typed.
     */
    public function testTheInvitingCoachComesFromTheToken(): void
    {
        $body = $this->caseBody('coach-invite');

        $this->assertStringContainsString('$auth->getUserId()', $body);
        $this->assertStringNotContainsString("\$data['invited_by']", $body);
        $this->assertStringNotContainsString("\$data['coach_id']", $body);
    }

    /** All three paths refuse with a 503 and a sentence while 087 is unapplied. */
    public function testEveryNewCaseToleratesTheTableBeingAbsent(): void
    {
        foreach (['coach-invite', 'coach-invite-status', 'coach-invites'] as $path) {
            $this->assertStringContainsString(
                'tryout_requireCoachInvitesTable',
                $this->caseBody($path),
                "case '{$path}' must probe for tryout_coach_invites. On Postgres a query "
                . 'against a missing table is 42P01, which would read as the whole '
                . 'Tryouts screen being broken.'
            );
        }

        $api = $this->code(file_get_contents(self::API));
        $this->assertStringContainsString('tryout_refuse(503', $api);
    }

    /** The refusal is a sentence a person can act on, not a code. */
    public function testTheUnavailableMessageExplainsItself(): void
    {
        $this->assertStringContainsString('not available yet', TE_TRYOUT_COACH_INVITE_UNAVAILABLE);
        $this->assertStringContainsString('migration', TE_TRYOUT_COACH_INVITE_UNAVAILABLE);
    }

    /**
     * The probe answers false without throwing when the table is absent, and
     * the memo is per connection.
     *
     * A process-wide static would let the first database's answer decide the
     * second's — the exact reason lib/program_scope.php uses a WeakMap.
     */
    public function testTheTableProbeIsPerConnection(): void
    {
        $with = $this->db(true);
        $without = $this->db(false);

        $this->assertFalse(te_tryout_coach_invites_table_present($without));
        $this->assertTrue(te_tryout_coach_invites_table_present($with));
        $this->assertFalse(te_tryout_coach_invites_table_present($without));
    }

    // ========================================================================
    // SQLITE — the claim
    // ========================================================================

    private function invites(): array
    {
        return $this->pdo->query(
            'SELECT * FROM tryout_coach_invites ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Pressing the button twice is one claim, not two. */
    public function testASecondInviteByTheSameCoachIsIdempotent(): void
    {
        $first  = te_tryout_coach_invite_record($this->pdo, 1000, 7, 61);
        $second = te_tryout_coach_invite_record($this->pdo, 1000, 7, 61);

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created'], 'a re-press must not create a second claim');
        $this->assertSame($first['id'], $second['id']);
        $this->assertCount(1, $this->invites());
    }

    /** Re-pressing with a different team moves the team; it does not add a row. */
    public function testARepressWithADifferentTeamUpdatesTheTeam(): void
    {
        te_tryout_coach_invite_record($this->pdo, 1000, 7, 61);
        te_tryout_coach_invite_record($this->pdo, 1000, 8, 61);

        $rows = $this->invites();
        $this->assertCount(1, $rows);
        $this->assertSame(8, (int) $rows[0]['team_id']);
    }

    /**
     * Two coaches wanting the same player is two claims.
     *
     * This is the situation the director most needs to see, so the unique
     * constraint is on (registration_id, invited_by) and not on the
     * registration alone.
     */
    public function testASecondCoachCanInviteTheSamePlayer(): void
    {
        te_tryout_coach_invite_record($this->pdo, 1000, 7, 61);
        te_tryout_coach_invite_record($this->pdo, 1000, 8, 62);

        $rows = $this->invites();
        $this->assertCount(2, $rows);
        $this->assertSame([61, 62], array_map('intval', array_column($rows, 'invited_by')));
    }

    /** A re-press must not re-mail the family, and must not lose the evidence. */
    public function testARepressKeepsTheRecordOfAnEarlierSend(): void
    {
        $invite = te_tryout_coach_invite_record($this->pdo, 1000, 7, 61);
        te_tryout_coach_invite_mark_sent($this->pdo, $invite['id']);

        $again = te_tryout_coach_invite_record($this->pdo, 1000, 7, 61);
        $this->assertNotNull($again['email_sent_at'], 'the send evidence must survive a re-press');
    }

    /** declined and withdrawn are the transitions the API offers. */
    public function testStatusTransitions(): void
    {
        $invite = te_tryout_coach_invite_record($this->pdo, 1000, 7, 61);

        $this->assertTrue(te_tryout_coach_invite_set_status($this->pdo, $invite['id'], 'declined'));
        $this->assertSame('declined', $this->invites()[0]['status']);

        $this->assertTrue(te_tryout_coach_invite_set_status($this->pdo, $invite['id'], 'withdrawn'));
        $this->assertSame('withdrawn', $this->invites()[0]['status']);

        // Back to invited: a coach who changed their mind twice is ordinary.
        $this->assertTrue(te_tryout_coach_invite_set_status($this->pdo, $invite['id'], 'invited'));
        $this->assertSame('invited', $this->invites()[0]['status']);
    }

    /**
     * 'registered' is refused even though the CHECK constraint admits it.
     *
     * Whether the athlete has been rostered is computed at read time; a stored
     * copy drifts the first time someone is rostered by hand in psql.
     */
    public function testRegisteredIsNeverWrittenByTheApi(): void
    {
        $invite = te_tryout_coach_invite_record($this->pdo, 1000, 7, 61);

        $this->assertFalse(te_tryout_coach_invite_set_status($this->pdo, $invite['id'], 'registered'));
        $this->assertFalse(te_tryout_coach_invite_set_status($this->pdo, $invite['id'], 'nonsense'));
        $this->assertSame('invited', $this->invites()[0]['status']);
        $this->assertNotContains('registered', TE_TRYOUT_COACH_INVITE_STATUSES);
    }

    /** An unknown invite id is a miss, not a silent success. */
    public function testSettingTheStatusOfAnUnknownInviteFails(): void
    {
        $this->assertFalse(te_tryout_coach_invite_set_status($this->pdo, 999999, 'declined'));
    }

    // ========================================================================
    // SQLITE — the email
    // ========================================================================

    /** Two guardians on one address, in two spellings, receive ONE email. */
    public function testAHouseholdSharingAnAddressGetsOneEmail(): void
    {
        $ctx = te_tryout_coach_invite_context($this->pdo, 1000, 7);
        $this->assertNotNull($ctx);

        $this->assertTrue(te_tryout_coach_invite_send($this->pdo, $ctx, 'Dana Fields', $this->recorder()));
        $this->assertCount(1, $this->sent);
        $this->assertSame('theRiveras@Gmail.com', $this->sent[0]['to']);
    }

    /** With no guardian link at all, registrant_email is the fallback. */
    public function testTheRegistrantEmailIsTheFallback(): void
    {
        $ctx = te_tryout_coach_invite_context($this->pdo, 2000, 7);
        te_tryout_coach_invite_send($this->pdo, $ctx, 'Dana Fields', $this->recorder());

        $this->assertSame('Dana.Alvarez@example.com', $this->sent[0]['to']);
    }

    /** A family with no resolvable address is a failure, never a quiet success. */
    public function testAFamilyWithNoAddressReportsFailure(): void
    {
        $this->pdo->exec("UPDATE registrations SET registrant_email = '' WHERE id = 2000");
        $ctx = te_tryout_coach_invite_context($this->pdo, 2000, null);

        $this->assertSame([], $ctx['recipients']);
        $this->assertFalse(te_tryout_coach_invite_send($this->pdo, $ctx, 'Dana Fields', $this->recorder()));
        $this->assertSame([], $this->sent);
    }

    /** A transport that refuses is reported as a failure. */
    public function testATransportFailureIsReported(): void
    {
        $ctx = te_tryout_coach_invite_context($this->pdo, 1000, 7);
        $this->assertFalse(
            te_tryout_coach_invite_send($this->pdo, $ctx, 'Dana Fields', $this->recorder(false))
        );
    }

    /**
     * The link is the registration page for the INVITED TEAM's program — not
     * the tryout program, which the family has already registered for.
     */
    public function testTheLinkIsTheRegistrationPageForTheTeamsProgram(): void
    {
        putenv('APP_URL=https://app.example');
        $_ENV['APP_URL'] = 'https://app.example';

        $ctx = te_tryout_coach_invite_context($this->pdo, 1000, 7);
        $this->assertSame('https://app.example/register/FALL26', $ctx['link']);

        // Team 8 has no program, so there is no registration page to send them
        // to. The portal is the honest fallback — never an invented link.
        $ctx = te_tryout_coach_invite_context($this->pdo, 1000, 8);
        $this->assertSame('https://app.example/parent', $ctx['link']);

        putenv('APP_URL');
        unset($_ENV['APP_URL']);
    }

    /** The email names the team when there is one, and the program when there is not. */
    public function testTheInviteNameFallsBackToTheProgram(): void
    {
        $withTeam = te_tryout_coach_invite_context($this->pdo, 1000, 7);
        $this->assertSame('Thunder U12', $withTeam['invite_name']);

        $noTeam = te_tryout_coach_invite_context($this->pdo, 1000, null);
        $this->assertSame('Fall 2026 Tryouts', $noTeam['invite_name']);
    }

    /** The message tells the family to register — the template alone does not. */
    public function testTheMessageCarriesRegistrationInstructions(): void
    {
        $ctx = te_tryout_coach_invite_context($this->pdo, 1000, 7);
        $message = te_tryout_coach_invite_message($ctx, 'Dana Fields');

        $this->assertStringContainsString('Dana Fields', $message);
        $this->assertStringContainsString('Maya Rivera', $message);
        $this->assertStringContainsString('Thunder U12', $message);
        $this->assertStringContainsString('registration', $message);
    }

    /** The coach's name is read from the database, not taken on trust. */
    public function testTheCoachNameComesFromTheDatabase(): void
    {
        $this->assertSame('Dana Fields', te_tryout_coach_invite_coach_name($this->pdo, 61));
        $this->assertSame('', te_tryout_coach_invite_coach_name($this->pdo, 999999));
        $this->assertSame('', te_tryout_coach_invite_coach_name($this->pdo, 0));
    }

    // ========================================================================
    // SQLITE — the director's view
    // ========================================================================

    /** Every invite in the program, with the coach and the athlete named. */
    public function testTheDirectorSeesEveryCoachsClaim(): void
    {
        te_tryout_coach_invite_record($this->pdo, 1000, 7, 61);
        te_tryout_coach_invite_record($this->pdo, 1000, 8, 62);
        te_tryout_coach_invite_record($this->pdo, 2000, 7, 61);

        $rows = te_tryout_coach_invite_list($this->pdo, 10);
        $this->assertCount(3, $rows);

        $byCoach = array_count_values(array_column($rows, 'invited_by_name'));
        $this->assertSame(2, $byCoach['Dana Fields']);
        $this->assertSame(1, $byCoach['Rob Hale']);
        $this->assertContains('Maya Rivera', array_column($rows, 'athlete_name'));
    }

    /** Another program's invites never appear. */
    public function testTheListIsScopedToOneProgram(): void
    {
        te_tryout_coach_invite_record($this->pdo, 1000, 7, 61);

        $this->assertCount(1, te_tryout_coach_invite_list($this->pdo, 10));
        $this->assertSame([], te_tryout_coach_invite_list($this->pdo, 11));
    }

    /**
     * `rostered` is computed, and it asks about the INVITED team.
     *
     * The athlete being on some other team is not the coach getting their
     * player, which is the question the column answers.
     */
    public function testRosteredIsComputedAgainstTheInvitedTeam(): void
    {
        te_tryout_coach_invite_record($this->pdo, 1000, 7, 61);
        $this->assertFalse(te_tryout_coach_invite_list($this->pdo, 10)[0]['rostered']);

        // On a DIFFERENT team: the coach who asked for team 7 did not get her.
        $this->pdo->exec("INSERT INTO team_members (id, team_id, athlete_id, status)
                          VALUES (1, 8, 100, 'active')");
        $this->assertFalse(te_tryout_coach_invite_list($this->pdo, 10)[0]['rostered']);

        $this->pdo->exec("INSERT INTO team_members (id, team_id, athlete_id, status)
                          VALUES (2, 7, 100, 'active')");
        $this->assertTrue(te_tryout_coach_invite_list($this->pdo, 10)[0]['rostered']);
    }

    /** A membership that has ended does not count as rostered. */
    public function testALeftRosterDoesNotCountAsRostered(): void
    {
        te_tryout_coach_invite_record($this->pdo, 1000, 7, 61);
        $this->pdo->exec("INSERT INTO team_members (id, team_id, athlete_id, status, leave_date)
                          VALUES (1, 7, 100, 'inactive', '2026-09-01')");

        $this->assertFalse(te_tryout_coach_invite_list($this->pdo, 10)[0]['rostered']);
    }

    /** With no team named, rostered degrades to "on a roster at all". */
    public function testWithNoTeamNamedRosteredMeansAnyTeam(): void
    {
        te_tryout_coach_invite_record($this->pdo, 1000, null, 61);
        $this->assertFalse(te_tryout_coach_invite_list($this->pdo, 10)[0]['rostered']);

        $this->pdo->exec("INSERT INTO team_members (id, team_id, athlete_id, status)
                          VALUES (1, 8, 100, 'active')");
        $this->assertTrue(te_tryout_coach_invite_list($this->pdo, 10)[0]['rostered']);
    }

    /**
     * Several offers and two team memberships must not multiply the invite row.
     *
     * The "what happened next" columns are EXISTS subqueries for exactly this
     * reason — a JOIN would show one coach's single selection three times, and
     * every count on the director's page would be wrong.
     */
    public function testMultipleOffersAndTeamsDoNotDuplicateTheRow(): void
    {
        te_tryout_coach_invite_record($this->pdo, 1000, 7, 61);
        $this->pdo->exec("
            INSERT INTO tryout_offers (id, registration_id, offer_type, team_id, response)
                VALUES (1, 1000, 'waitlist', 7, NULL);
            INSERT INTO tryout_offers (id, registration_id, offer_type, team_id, response)
                VALUES (2, 1000, 'roster', 7, 'accepted');
            INSERT INTO team_members (id, team_id, athlete_id, status) VALUES (1, 7, 100, 'active');
            INSERT INTO team_members (id, team_id, athlete_id, status) VALUES (2, 8, 100, 'active');
        ");

        $rows = te_tryout_coach_invite_list($this->pdo, 10);
        $this->assertCount(1, $rows, 'EXISTS, not JOIN — a joined roster multiplies the invite');
        // The MOST RECENT offer, not the first one found.
        $this->assertSame('roster', $rows[0]['offer_type']);
        $this->assertSame('accepted', $rows[0]['offer_response']);
    }

    /** An unnamed coach or athlete renders as "Unknown", never as a blank cell. */
    public function testMissingNamesRenderAsUnknownRatherThanBlank(): void
    {
        $this->pdo->exec("UPDATE users SET first_name = '', last_name = '' WHERE id = 61");
        te_tryout_coach_invite_record($this->pdo, 1000, 7, 61);

        $row = te_tryout_coach_invite_list($this->pdo, 10)[0];
        $this->assertSame('Unknown coach', $row['invited_by_name']);
    }
}
