<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;

require_once __DIR__ . '/../../lib/guardian_link_writer.php';

/**
 * Writing `user_guardians` links at their source — lib/guardian_link_writer.php.
 *
 * Phase 3 part 2 of docs/user-guardians-identity-plan.md. Phase 1 backfilled 176 links,
 * phase 2 made one resolver read them UNION the email match, and part 1 gave a club admin
 * a repair tool. None of that stops NEW families arriving in the broken state: a Crew
 * invite accepted on a different address than the guardian row carries, or a returning
 * family registering online, still leaves nothing recorded. This file closes both.
 *
 * What is pinned here, and why each one:
 *
 *  - **Exactly one guardian on the address links; two link nothing.** Six production
 *    addresses carry two guardian rows and `users.email` is UNIQUE, so those households
 *    share one account. Choosing between them by name is the rule the plan measured and
 *    rejected — it would have dropped a real child from Carmen Hawk's family. The reason
 *    is RETURNED rather than swallowed, because "household, needs a human" and "staff
 *    account, nothing to link" are different problems with different fixes.
 *  - **Already linked is a no-op that does not rewrite `source`/`confidence`.** An
 *    admin's `admin_link`/`manual` row records that a named person chose; a later accept
 *    overwriting it with `invite_accept` destroys the only evidence available the day a
 *    wrong link surfaces.
 *  - **A staff-only account links nothing.** Standing comes from a guardian row, not
 *    from having accepted an invite. Linking on invite-accept alone would give every
 *    coach a guardian they do not have.
 *  - **The registration path links an EXISTING account and never creates one.**
 *    `users.email` is UNIQUE, so an account minted from a public form is permanent and
 *    unfixable — that is how 33 athlete shells came to own their parents' addresses
 *    (migration 067). No account yet is the ordinary case, not an error.
 *  - **Both gateways call the writer, and registrations-api calls it AFTER commit()
 *    inside its own try/catch.** Asserted by parsing the source, because the failure
 *    being prevented is invisible in a unit test: a throw inside the open transaction
 *    rolls back the family's registration, so they lose their place in the program
 *    because a bookkeeping row could not be written. Same rule the confirmation email
 *    already follows two lines below it.
 */
class GuardianLinkWriterTest extends TestCase
{
    private PDO $pdo;

    // Accepted on @gmail; her guardian row says @yahoo. Nothing resolves her today.
    private const ALLIX_USER = 10;
    private const ALLIX_GUARDIAN = 110;

    // One address, one account, ONE guardian row -> linkable by address alone.
    private const SOLO_USER = 11;
    private const SOLO_GUARDIAN = 111;

    // thejones@gmail.com: John and Jane, two guardian rows, one account between them.
    private const JONES_USER = 12;
    private const JONES_GUARDIAN_JOHN = 112;
    private const JONES_GUARDIAN_JANE = 113;

    // A coach. No guardian row anywhere.
    private const COACH_USER = 13;

    // Already linked by a club admin, deliberately, with linked_by set.
    private const DRIFT_USER = 14;
    private const DRIFT_GUARDIAN = 114;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // Column names mirror tests/fixtures/production-schema.json. The UNIQUE is the
        // one the writer's ON CONFLICT names, so a missing constraint fails here rather
        // than in production.
        $this->pdo->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY, email TEXT NOT NULL,
                first_name TEXT, last_name TEXT
            );
            CREATE TABLE guardians (
                id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT,
                email TEXT NOT NULL, mobile_phone TEXT
            );
            CREATE TABLE athletes (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT);
            CREATE TABLE athlete_guardians (
                id INTEGER PRIMARY KEY, athlete_id INTEGER, guardian_id INTEGER
            );
            CREATE TABLE user_guardians (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, guardian_id INTEGER,
                source TEXT, confidence TEXT, linked_by INTEGER, created_at TEXT,
                UNIQUE (user_id, guardian_id)
            );
            CREATE TABLE audit_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT,
                resource_type TEXT, resource_id INTEGER, ip_address TEXT,
                user_agent TEXT, details TEXT, created_at TEXT
            );
        ");

        $p = $this->pdo;

        $p->exec("INSERT INTO users (id, email, first_name, last_name) VALUES
            (10, 'allix@gmail.com',    'Allix', 'Boyce'),
            (11, 'solo@example.test',  'Solo',  'Parent'),
            (12, 'thejones@gmail.com', 'John',  'Jones'),
            (13, 'coach@club.test',    'Coach', 'Only'),
            (14, 'drifted@gmail.com',  'Drift', 'Ed')");

        $p->exec("INSERT INTO guardians (id, first_name, last_name, email, mobile_phone) VALUES
            (110, 'Allix', 'Boyce',  'allix@yahoo.com',    '7855550110'),
            (111, 'Solo',  'Parent', 'Solo@Example.Test',  '7855550111'),
            (112, 'John',  'Jones',  'thejones@gmail.com', '7855550112'),
            (113, 'Jane',  'Jones',  'thejones@gmail.com', '7855550113'),
            (114, 'Drift', 'Ed',     'drifted@yahoo.com',  '7855550114')");

        $p->exec("INSERT INTO athletes (id, first_name, last_name) VALUES
            (210, 'Ava', 'Boyce'), (211, 'Sol', 'Parent'),
            (212, 'Jay', 'Jones'), (214, 'Dee', 'Ed')");

        $p->exec("INSERT INTO athlete_guardians (id, athlete_id, guardian_id) VALUES
            (1, 210, 110), (2, 211, 111), (3, 212, 112), (4, 212, 113), (5, 214, 114)");

        // A deliberate admin link. Nothing on the accept or registration paths may
        // rewrite its source, confidence or linked_by.
        $p->exec("INSERT INTO user_guardians (user_id, guardian_id, source, confidence, linked_by)
            VALUES (14, 114, 'admin_link', 'manual', 900)");
    }

    /** @return array<string,mixed>|null */
    private function link(int $userId, int $guardianId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM user_guardians WHERE user_id = ? AND guardian_id = ?'
        );
        $stmt->execute([$userId, $guardianId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    private function linkCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM user_guardians')->fetchColumn();
    }

    // ── invite accept: the guardian is known ────────────────────────────────

    public function testAcceptLinksTheGuardianTheInviteWasMintedFor(): void
    {
        // parentInvite_ensureUserAndToken() is handed a guardian id, so a caller that
        // still has it in scope should never have to guess from the address — and this
        // is the ONLY way Allix can be linked, since her two addresses differ.
        $res = te_link_guardian_on_accept(
            $this->pdo,
            self::ALLIX_USER,
            self::ALLIX_GUARDIAN,
            'allix@gmail.com'
        );

        $this->assertSame(TE_GUARDIAN_LINK_LINKED, $res['outcome']);
        $this->assertSame(self::ALLIX_GUARDIAN, $res['guardian_id']);

        $row = $this->link(self::ALLIX_USER, self::ALLIX_GUARDIAN);
        $this->assertNotNull($row);
        $this->assertSame('invite_accept', $row['source']);
        $this->assertSame('exact', $row['confidence']);

        // The assertion that proves it did the thing it is for: the resolver now
        // reaches her child. A row that resolves to no athlete points at the wrong
        // guardian and looks identical to a correct one.
        $this->assertSame([210], te_athlete_ids_for_user($this->pdo, self::ALLIX_USER));
    }

    public function testAcceptResolvesAnAddressHeldByExactlyOneGuardian(): void
    {
        // Capitalisation differs between the two rows on purpose: `=` is case-sensitive
        // in Postgres, which is the whole of the Emily Govier incident.
        $res = te_link_guardian_on_accept($this->pdo, self::SOLO_USER, null, 'solo@example.test');

        $this->assertSame(TE_GUARDIAN_LINK_LINKED, $res['outcome']);
        $this->assertSame(self::SOLO_GUARDIAN, $res['guardian_id']);
        $this->assertSame('invite_accept', $this->link(self::SOLO_USER, self::SOLO_GUARDIAN)['source']);
    }

    // ── invite accept: the address is ambiguous ─────────────────────────────

    public function testTwoGuardiansOnOneAddressLinkNothingAndSayWhy(): void
    {
        $before = $this->linkCount();

        $res = te_link_guardian_on_accept($this->pdo, self::JONES_USER, null, 'thejones@gmail.com');

        $this->assertSame(TE_GUARDIAN_LINK_AMBIGUOUS, $res['outcome']);
        $this->assertNull($res['guardian_id']);
        $this->assertSame(
            [self::JONES_GUARDIAN_JOHN, self::JONES_GUARDIAN_JANE],
            $res['candidates'],
            'both candidates must be reported so a club admin can choose in crew-link'
        );
        $this->assertSame($before, $this->linkCount(), 'a household must not be guessed at');

        // And the family is not stranded meanwhile: the email fallback in the resolver
        // still answers for them, which is why refusing costs nothing today.
        $this->assertSame([212], te_athlete_ids_for_user($this->pdo, self::JONES_USER));
    }

    public function testAmbiguityIsDistinctFromHavingNoGuardianAtAll(): void
    {
        $ambiguous = te_link_guardian_on_accept($this->pdo, self::JONES_USER, null, 'thejones@gmail.com');
        $none = te_link_guardian_on_accept($this->pdo, self::COACH_USER, null, 'coach@club.test');

        $this->assertNotSame(
            $ambiguous['outcome'],
            $none['outcome'],
            'a household needing a human and a staff account needing nothing are different answers'
        );
    }

    // ── nothing to link ─────────────────────────────────────────────────────

    public function testStaffOnlyAccountLinksNothing(): void
    {
        $before = $this->linkCount();

        $res = te_link_guardian_on_accept($this->pdo, self::COACH_USER, null, 'coach@club.test');

        $this->assertSame(TE_GUARDIAN_LINK_NO_GUARDIAN, $res['outcome']);
        $this->assertSame($before, $this->linkCount());
    }

    public function testAGuardianIdThatDoesNotExistIsRefusedRatherThanInserted(): void
    {
        // On Postgres the FK would reject it; the point of refusing here is that the
        // rejection must not surface as a 500 on someone's account setup.
        $res = te_link_guardian_on_accept($this->pdo, self::ALLIX_USER, 99999, 'allix@gmail.com');

        $this->assertSame(TE_GUARDIAN_LINK_NO_GUARDIAN, $res['outcome']);
        $this->assertSame(1, $this->linkCount());
    }

    // ── idempotence ─────────────────────────────────────────────────────────

    public function testAlreadyLinkedIsANoOp(): void
    {
        $first = te_link_guardian_on_accept($this->pdo, self::ALLIX_USER, self::ALLIX_GUARDIAN, 'allix@gmail.com');
        $second = te_link_guardian_on_accept($this->pdo, self::ALLIX_USER, self::ALLIX_GUARDIAN, 'allix@gmail.com');

        $this->assertSame(TE_GUARDIAN_LINK_LINKED, $first['outcome']);
        $this->assertSame(TE_GUARDIAN_LINK_ALREADY_LINKED, $second['outcome']);
        $this->assertSame(2, $this->linkCount(), 'the seeded admin link plus exactly one new row');
    }

    public function testAnExistingAdminLinkIsNeverDowngraded(): void
    {
        $res = te_link_guardian_on_accept($this->pdo, self::DRIFT_USER, self::DRIFT_GUARDIAN, 'drifted@gmail.com');

        $this->assertSame(TE_GUARDIAN_LINK_ALREADY_LINKED, $res['outcome']);

        $row = $this->link(self::DRIFT_USER, self::DRIFT_GUARDIAN);
        $this->assertSame('admin_link', $row['source'], 'a named admin chose this; accept must not overwrite it');
        $this->assertSame('manual', $row['confidence']);
        $this->assertSame(900, (int) $row['linked_by']);
    }

    public function testRegistrationIsIdempotentToo(): void
    {
        te_link_guardian_on_registration($this->pdo, self::SOLO_GUARDIAN, 'solo@example.test');
        $res = te_link_guardian_on_registration($this->pdo, self::SOLO_GUARDIAN, 'SOLO@example.test');

        $this->assertSame(TE_GUARDIAN_LINK_ALREADY_LINKED, $res['outcome']);
        $this->assertSame(2, $this->linkCount());
    }

    // ── registration ────────────────────────────────────────────────────────

    public function testRegistrationLinksAnExistingAccountCaseInsensitively(): void
    {
        // The form carries 'Solo@Example.Test'; the account is 'solo@example.test'.
        $res = te_link_guardian_on_registration($this->pdo, self::SOLO_GUARDIAN, 'Solo@Example.Test');

        $this->assertSame(TE_GUARDIAN_LINK_LINKED, $res['outcome']);
        $this->assertSame(self::SOLO_USER, $res['user_id']);

        $row = $this->link(self::SOLO_USER, self::SOLO_GUARDIAN);
        $this->assertSame('registration', $row['source']);
        $this->assertSame('exact', $row['confidence']);
        $this->assertNull($row['linked_by'], 'a public sign-up has no operator; NULL is the honest record');
    }

    public function testRegistrationNeverCreatesAUsersRow(): void
    {
        $before = (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

        $res = te_link_guardian_on_registration($this->pdo, self::ALLIX_GUARDIAN, 'brand.new@example.test');

        $this->assertSame(TE_GUARDIAN_LINK_NO_ACCOUNT, $res['outcome']);
        $this->assertSame(
            $before,
            (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
            'users.email is UNIQUE — an account minted from a public form is permanent and unfixable'
        );
        $this->assertSame(1, $this->linkCount());
    }

    public function testRegistrationLinksTheGuardianTheFormResolvedNotTheAddress(): void
    {
        // The household case that stops the invite path does not arise here: the form
        // said which adult it was about, so John links and Jane does not.
        $res = te_link_guardian_on_registration($this->pdo, self::JONES_GUARDIAN_JOHN, 'thejones@gmail.com');

        $this->assertSame(TE_GUARDIAN_LINK_LINKED, $res['outcome']);
        $this->assertNotNull($this->link(self::JONES_USER, self::JONES_GUARDIAN_JOHN));
        $this->assertNull($this->link(self::JONES_USER, self::JONES_GUARDIAN_JANE));
    }

    // ── audit ───────────────────────────────────────────────────────────────
    //
    // Asserted by parsing, not by execution: in SQLite both `set_config()` and `NOW()`
    // are missing functions, so `te_db_set_actor()` and `AuditLogger` degrade silently.
    // That silent degradation is their PRODUCTION contract too — neither may ever break
    // the write it describes — so the source is the only place their presence can be
    // proven. Same reasoning as CrewLinkTest.

    public function testTheWriteSetsTheDbActorBeforeInsertingAndAuditsAfter(): void
    {
        $src = file_get_contents(__DIR__ . '/../../lib/guardian_link_writer.php');
        $this->assertIsString($src);

        $actor = strpos($src, 'te_db_set_actor(');
        $insert = strpos($src, 'INSERT INTO user_guardians');
        $audit = strpos($src, 'AuditLogger::log(');

        $this->assertNotFalse($actor, "migration 072's trigger has no other way to learn who is acting");
        $this->assertNotFalse($insert);
        $this->assertNotFalse($audit);

        $this->assertLessThan(
            $insert,
            $actor,
            'the trigger reads app.user_id at INSERT time — setting it afterwards attributes the row to nobody'
        );
        $this->assertGreaterThan($insert, $audit);
    }

    public function testTheInsertIsOnConflictDoNothingAndNeverDoUpdate(): void
    {
        // Asserted against the SQL LITERALS, not the raw source: the file's own docblock
        // says "DO NOTHING, never DO UPDATE", so a scan of the whole file would match its
        // own explanation. Same lesson as MysqlOnlySqlTest — scan SQL, not prose, or the
        // checker cries wolf and gets deleted.
        $sql = '';
        foreach (token_get_all(file_get_contents(__DIR__ . '/../../lib/guardian_link_writer.php')) as $t) {
            if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
                $sql .= $t[1] . "\n";
            }
        }

        $this->assertMatchesRegularExpression(
            '/ON CONFLICT \(user_id, guardian_id\) DO NOTHING/i',
            $sql,
            'two requests racing an accept must not raise a unique violation'
        );
        $this->assertStringNotContainsStringIgnoringCase(
            'DO UPDATE',
            $sql,
            'source and confidence record how an assertion came to exist; rewriting them destroys the evidence'
        );
    }

    // ── the call sites ──────────────────────────────────────────────────────
    //
    // Parsed, not executed. Both gateways connect to Neon at load and cannot be
    // required from a unit test, and the property that matters at the registration
    // site — that the call is OUTSIDE the transaction — is not observable from the
    // function itself at all.

    public function testRegistrationsApiCallsTheWriterAfterCommitInsideItsOwnTryCatch(): void
    {
        $src = file_get_contents(__DIR__ . '/../../registration/registrations-api.php');
        $this->assertIsString($src);

        $this->assertStringContainsString('guardian_link_writer.php', $src);

        $call = strpos($src, 'te_link_guardian_on_registration(');
        $this->assertNotFalse($call, 'the public sign-up must record the link it just created');

        // AFTER the POST branch's commit(): a throw while the transaction is open
        // rolls back the family's registration, and they lose their place in the
        // program because a bookkeeping row could not be written.
        $commit = strpos($src, '$connection->commit();');
        $this->assertNotFalse($commit);
        $this->assertGreaterThan(
            $commit,
            $call,
            'the link write must sit after commit(), like the confirmation email'
        );

        // In its OWN try/catch, with a Throwable catch: the enclosing catch calls
        // rollBack() on an already-committed transaction, and an Error is not an
        // Exception.
        $window = substr($src, $commit, $call - $commit + 1200);
        $this->assertMatchesRegularExpression(
            '/te_link_guardian_on_registration\(.*?\}\s*catch\s*\(\s*\\\\?Throwable/s',
            $window,
            'the link write needs its own try/catch — it must never fail a registration'
        );
    }

    public function testInvitationsGatewayAcceptCallsTheWriter(): void
    {
        $src = file_get_contents(__DIR__ . '/../../api/invitations-gateway.php');
        $this->assertIsString($src);

        $this->assertStringContainsString('guardian_link_writer.php', $src);

        $accept = strpos($src, 'function handleAcceptInvitation');
        $this->assertNotFalse($accept);
        $body = substr($src, $accept);

        $this->assertStringContainsString(
            'te_link_guardian_on_accept(',
            $body,
            'a parent-role invite must record the link, not just report linked_athletes'
        );
        $this->assertStringContainsString(
            'linked_guardian_id',
            $body,
            'the caller needs to know whether a link was actually recorded'
        );
    }
}
