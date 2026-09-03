<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/compliance.php';
require_once __DIR__ . '/../../lib/compliance_reminders.php';
require_once __DIR__ . '/../../lib/compliance_export.php';

/**
 * GOTR G4 — the compliance CSV export, the reminder sweep, and the worker tick.
 *
 * Fixture is the G3 tree (ComplianceTest builds the same shape) plus the pieces
 * G4 needs: `users.last_login_at`, and migration 093's partial unique index on
 * compliance_reminder_log.
 *
 *   1 national  Girls on the Run   /1/       req 10 SafeSport      required, 365d, all roles
 *   2 division  West               /1/2/     req 11 Concussion     required, no expiry, head_coach
 *   3 council   Kansas             /1/2/3/   -> club 100
 *   4 council   California         /1/2/4/   -> club 101
 *   club 100                                  req 13 Parking pass  OPTIONAL, volunteer
 *
 * What these tests pin, in order of how much damage the alternative does:
 *
 * - A REMINDER IS NEVER SENT TWICE for one person and one threshold. There are
 *   30,000 coaches behind this; a duplicate blast cannot be unsent, and the tick
 *   runs four times a day forever.
 * - The dedupe survives a NULL stream_id. Postgres does not consider two NULLs
 *   equal, so 091's UNIQUE alone admits unlimited duplicates and the feature
 *   refuses to send until migration 093's partial index exists.
 * - The claim happens BEFORE the send. Losing a reminder is recoverable; sending
 *   one twice is not.
 * - The export is gated on the ADMIN predicate, not club membership, and its cap
 *   is reported rather than silently shipping a short file.
 * - The tick is behind BOTH switches, takes the lock, and runs the expiry sweep
 *   before the reminder pass.
 */
class ComplianceRemindersTest extends TestCase
{
    // ---------------------------------------------------------------- fixture

    private function pdo(bool $withDedupeIndex = true): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY, email TEXT, first_name TEXT, last_name TEXT,
                last_login_at TEXT
            );
            CREATE TABLE club_profile (
                id INTEGER PRIMARY KEY, name TEXT, org_unit_id INTEGER
            );
            CREATE TABLE user_club_access (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER,
                club_profile_id INTEGER, role TEXT, active BOOLEAN DEFAULT 1,
                revoked_at TEXT
            );
            CREATE TABLE documents (
                id INTEGER PRIMARY KEY AUTOINCREMENT, uploaded_by INTEGER, title TEXT
            );
            CREATE TABLE org_units (
                id INTEGER PRIMARY KEY AUTOINCREMENT, parent_id INTEGER, type TEXT NOT NULL,
                name TEXT NOT NULL, external_code TEXT, path TEXT NOT NULL, depth INTEGER NOT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE user_org_access (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
                org_unit_id INTEGER NOT NULL, role TEXT NOT NULL,
                granted_at TEXT DEFAULT CURRENT_TIMESTAMP, granted_by INTEGER,
                revoked_at TEXT, revoked_by INTEGER, active BOOLEAN DEFAULT 1,
                UNIQUE (user_id, org_unit_id, role)
            );
            CREATE TABLE compliance_requirements (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                org_unit_id INTEGER, club_profile_id INTEGER,
                kind TEXT NOT NULL DEFAULT 'custom', name TEXT NOT NULL, description TEXT,
                proof TEXT NOT NULL DEFAULT 'attested_date', proof_url TEXT,
                validity_days INTEGER, required BOOLEAN NOT NULL DEFAULT 1,
                active BOOLEAN NOT NULL DEFAULT 1, sort_order INTEGER NOT NULL DEFAULT 0,
                created_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE compliance_requirement_roles (
                id INTEGER PRIMARY KEY AUTOINCREMENT, requirement_id INTEGER NOT NULL,
                staff_role TEXT NOT NULL, UNIQUE (requirement_id, staff_role)
            );
            CREATE TABLE club_staff_roles (
                user_id INTEGER NOT NULL, club_profile_id INTEGER NOT NULL,
                staff_role TEXT NOT NULL, assigned_by INTEGER,
                assigned_at TEXT DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, club_profile_id, staff_role)
            );
            CREATE TABLE person_credentials (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
                requirement_id INTEGER NOT NULL, status TEXT NOT NULL DEFAULT 'missing',
                completed_at TEXT, expires_at TEXT, document_id INTEGER,
                submitted_at TEXT, verified_by INTEGER, verified_at TEXT,
                rejection_reason TEXT, source TEXT NOT NULL DEFAULT 'admin', notes TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (user_id, requirement_id)
            );
            CREATE TABLE compliance_reminder_streams (
                id INTEGER PRIMARY KEY AUTOINCREMENT, requirement_id INTEGER NOT NULL,
                org_unit_id INTEGER, club_profile_id INTEGER, active BOOLEAN NOT NULL DEFAULT 0,
                steps TEXT NOT NULL DEFAULT '[]', created_by INTEGER,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            -- stream_id is NULLABLE here, as migration 093 makes it in production.
            CREATE TABLE compliance_reminder_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT, credential_id INTEGER NOT NULL,
                stream_id INTEGER, days_before INTEGER NOT NULL,
                sent_at TEXT DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (credential_id, stream_id, days_before)
            );
        ");

        if ($withDedupeIndex) {
            $pdo->exec(
                'CREATE UNIQUE INDEX ' . TE_COMPLIANCE_REMINDER_DEDUPE_INDEX
                . ' ON compliance_reminder_log (credential_id, days_before) WHERE stream_id IS NULL'
            );
        }

        $pdo->exec("
            INSERT INTO users (id, email, first_name, last_name, last_login_at) VALUES
                (50, 'head@gotr.org', 'Hana', 'Head', '2026-09-01 10:00:00'),
                (51, 'vol@gotr.org', 'Vic', 'Volunteer', '2026-09-01 10:00:00'),
                (52, 'coach@gotr.org', 'Cal', 'Coach', '2026-09-01 10:00:00'),
                (53, 'parent@gotr.org', 'Pat', 'Parent', '2026-09-01 10:00:00'),
                (54, 'dormant@gotr.org', 'Dot', 'Dormant', '2025-01-01 10:00:00'),
                (55, 'noemail@gotr.org', 'Nia', 'Noaddress', '2026-09-01 10:00:00');
            UPDATE users SET email = '' WHERE id = 55;
            INSERT INTO club_profile (id, name, org_unit_id) VALUES
                (100, 'GOTR Kansas', 3),
                (101, 'GOTR California', 4);
            INSERT INTO user_club_access (user_id, club_profile_id, role, active, revoked_at) VALUES
                (50, 100, 'coach', 1, NULL),
                (51, 100, 'volunteer', 1, NULL),
                (52, 100, 'coach', 1, NULL),
                (53, 100, 'parent', 1, NULL),
                (54, 100, 'coach', 1, NULL),
                (55, 100, 'coach', 1, NULL);
            INSERT INTO org_units (id, parent_id, type, name, path, depth) VALUES
                (1, NULL, 'national', 'Girls on the Run', '/1/', 0),
                (2, 1, 'division', 'West', '/1/2/', 1),
                (3, 2, 'council', 'Kansas', '/1/2/3/', 2),
                (4, 2, 'council', 'California', '/1/2/4/', 2);
            INSERT INTO compliance_requirements
                (id, org_unit_id, club_profile_id, kind, name, proof, validity_days, required, active, sort_order) VALUES
                (10, 1, NULL, 'training', 'SafeSport', 'document', 365, 1, 1, 1),
                (11, 2, NULL, 'training', 'Concussion protocol', 'attested_date', NULL, 1, 1, 2),
                (13, NULL, 100, 'custom', 'Council parking pass', 'attested_date', NULL, 0, 1, 4);
            INSERT INTO compliance_requirement_roles (requirement_id, staff_role) VALUES
                (11, 'head_coach'),
                (13, 'volunteer');
            INSERT INTO club_staff_roles (user_id, club_profile_id, staff_role) VALUES
                (50, 100, 'head_coach'),
                (51, 100, 'volunteer');
        ");

        return $pdo;
    }

    /** A verified credential for user $u against requirement $r expiring on $expires. */
    private function verified(PDO $pdo, int $user, int $requirement, string $expires): int
    {
        $pdo->prepare(
            "INSERT INTO person_credentials (user_id, requirement_id, status, completed_at, expires_at, source)
             VALUES (?, ?, 'verified', '2026-01-01', ?, 'admin')"
        )->execute([$user, $requirement, $expires]);
        return (int) $pdo->lastInsertId();
    }

    /** Collect envelopes into a comparable shape. */
    private function shape(array $envelopes): array
    {
        return array_map(static fn (array $e): array => [
            'user'      => $e['user_id'],
            'club'      => $e['club_id'],
            'threshold' => $e['threshold'],
            'names'     => array_map(static fn (array $i): string => $i['name'], $e['items']),
        ], $envelopes);
    }

    // -------------------------------------------------------------- threshold

    /**
     * The SMALLEST threshold the day count falls inside.
     *
     * A credential recorded 20 days before it expires is inside 90, 60 AND 30.
     * Sending all three is three emails in one tick; sending the largest is a
     * "90 days left" notice that is a lie. 30 is the only honest answer.
     */
    public function testTheSmallestEligibleThresholdWins(): void
    {
        $this->assertSame(90, te_compliance_reminder_threshold(90));
        $this->assertSame(90, te_compliance_reminder_threshold(85));
        $this->assertSame(60, te_compliance_reminder_threshold(60));
        $this->assertSame(60, te_compliance_reminder_threshold(45));
        $this->assertSame(30, te_compliance_reminder_threshold(20));
        $this->assertSame(7, te_compliance_reminder_threshold(7));
        $this->assertSame(7, te_compliance_reminder_threshold(0));
    }

    /** Beyond the widest window, and already past, are both "not now". */
    public function testNothingFiresOutsideTheWindowOrAfterExpiry(): void
    {
        $this->assertNull(te_compliance_reminder_threshold(91));
        $this->assertNull(te_compliance_reminder_threshold(365));
        $this->assertNull(te_compliance_reminder_threshold(null));
        // Already expired: it is `expired` on every screen and in the rollup,
        // which is a louder signal than an email pretending it is due soon.
        $this->assertNull(te_compliance_reminder_threshold(-1));
    }

    /**
     * ⚠️ The SQL bands and the PHP threshold must agree on every single day.
     *
     * te_compliance_reminder_candidate_users() picks people with a band query;
     * te_compliance_pending_reminders() then decides their threshold in PHP. A
     * disagreement of one day means either a second email for a band already
     * sent, or a person walked on every tick and never mailed at all — and
     * neither shows up as an error anywhere.
     */
    public function testTheCandidateBandsAgreeWithTheThresholdOnEveryDay(): void
    {
        $pdo = $this->pdo();
        $today = '2026-09-06';

        for ($days = 0; $days <= 95; $days++) {
            $pdo->exec('DELETE FROM person_credentials');
            $expires = te_compliance_reminder_shift($today, $days);
            $this->verified($pdo, 50, 10, $expires);

            $expected = te_compliance_reminder_threshold($days);
            $picked = te_compliance_reminder_candidate_users($pdo, $today, 0);

            if ($expected === null) {
                $this->assertSame([], $picked, "day $days should be outside every band");
                continue;
            }
            $this->assertSame([50], $picked, "day $days should be a candidate");

            $envelopes = te_compliance_pending_reminders($pdo, ['today' => $today]);
            $this->assertCount(1, $envelopes, "day $days produced no envelope");
            $this->assertSame($expected, $envelopes[0]['threshold'], "day $days landed in the wrong band");
        }
    }

    /** Calendar-day arithmetic in UTC, never 86,400-second steps. */
    public function testDateShiftsAreWholeCalendarDays(): void
    {
        $this->assertSame('2026-03-03', te_compliance_reminder_shift('2026-02-28', 3));
        // 2028 is a leap year: Feb has 29 days.
        $this->assertSame('2028-03-01', te_compliance_reminder_shift('2028-02-28', 2));
        $this->assertSame('2026-06-08', te_compliance_reminder_shift('2026-09-06', -90));
    }

    // ---------------------------------------------------------------- dedupe

    /**
     * ⚠️ THE test. One person, one threshold, one email — for ever.
     *
     * The tick runs every six hours and a certificate sits inside the 30-day
     * window for 23 days. Without the dedupe that is ~92 emails to one coach
     * about one certificate.
     */
    public function testAReminderIsNeverSentTwiceForOnePersonAndOneThreshold(): void
    {
        $pdo = $this->pdo();
        $this->verified($pdo, 50, 10, '2026-10-01');   // 25 days out from 09-06

        $sent = [];
        $mailer = function (array $envelope, array $copy, array $person) use (&$sent): bool {
            $sent[] = $person['email'] . ':' . $envelope['threshold'];
            return true;
        };

        $first = te_compliance_dispatch_reminders($pdo, ['today' => '2026-09-06', 'mailer' => $mailer]);
        $this->assertSame(1, $first['sent']);
        $this->assertSame(['head@gotr.org:30'], $sent);

        // Four more ticks the same day, and one a fortnight later while still
        // inside the same window.
        foreach (['2026-09-06', '2026-09-06', '2026-09-07', '2026-09-20'] as $day) {
            $again = te_compliance_dispatch_reminders($pdo, ['today' => $day, 'mailer' => $mailer]);
            $this->assertSame(0, $again['sent'], "a second reminder went out on $day");
        }
        $this->assertSame(['head@gotr.org:30'], $sent);

        $rows = (int) $pdo->query('SELECT COUNT(*) FROM compliance_reminder_log')->fetchColumn();
        $this->assertSame(1, $rows);
    }

    /** Crossing into the next threshold is a NEW reminder, not a duplicate. */
    public function testTheNextThresholdDownDoesSend(): void
    {
        $pdo = $this->pdo();
        $this->verified($pdo, 50, 10, '2026-10-01');

        $sent = [];
        $mailer = function (array $envelope) use (&$sent): bool {
            $sent[] = $envelope['threshold'];
            return true;
        };

        te_compliance_dispatch_reminders($pdo, ['today' => '2026-09-06', 'mailer' => $mailer]); // 25 -> 30
        te_compliance_dispatch_reminders($pdo, ['today' => '2026-09-28', 'mailer' => $mailer]); // 3  -> 7

        $this->assertSame([30, 7], $sent);
        // And the 90/60 steps never fire, because the day count only falls and
        // the smallest eligible threshold is always chosen.
        $this->assertSame(
            [7, 30],
            $pdo->query('SELECT days_before FROM compliance_reminder_log ORDER BY days_before')
                ->fetchAll(PDO::FETCH_COLUMN, 0)
        );
    }

    /**
     * ⚠️ Without migration 093 the feature REFUSES to send.
     *
     * Postgres treats two NULLs as distinct, so 091's
     * UNIQUE (credential_id, stream_id, days_before) does not dedupe the default
     * stream at all. Running anyway would mail everybody again every six hours.
     * A feature dark for a day is recoverable; a reminder loop is not.
     */
    public function testWithoutTheDefaultStreamIndexNothingIsSent(): void
    {
        $pdo = $this->pdo(false);
        $this->verified($pdo, 50, 10, '2026-10-01');

        $this->assertFalse(te_compliance_reminder_dedupe_ready($pdo));

        $sent = 0;
        $result = te_compliance_dispatch_reminders($pdo, [
            'today'  => '2026-09-06',
            'mailer' => function () use (&$sent): bool { $sent++; return true; },
        ]);

        $this->assertSame(0, $sent);
        $this->assertSame(0, $result['sent']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('093', $result['errors'][0]);
    }

    /**
     * ⚠️ EITHER switch off means nothing is mailed, and it is said out loud.
     *
     * The tick checks these too, but a kill switch is per FEATURE and not per
     * caller: a script or a future endpoint reaching the dispatcher must not be
     * able to mail 30,000 people while the config var says reminders are off.
     * And a skipped pass must never report as a successful quiet one — that is
     * the whole point of Phase 2's disabled-response rule.
     */
    public function testEitherSwitchOffStopsEverySend(): void
    {
        foreach (['TE_FEATURE_COMPLIANCE', 'TE_FEATURE_COMPLIANCE_REMINDERS'] as $var) {
            $pdo = $this->pdo();
            $this->verified($pdo, 50, 10, '2026-10-01');

            putenv("$var=off");
            $_ENV[$var] = 'off';
            try {
                $sent = 0;
                $result = te_compliance_dispatch_reminders($pdo, [
                    'today'  => '2026-09-06',
                    'mailer' => function () use (&$sent): bool { $sent++; return true; },
                ]);

                $this->assertSame(0, $sent, "$var=off still sent mail");
                $this->assertSame(0, $result['sent']);
                $this->assertNotEmpty($result['errors'], "$var=off must say why nothing happened");
                $this->assertStringContainsString('feature_disabled', $result['errors'][0]);
                $this->assertSame(
                    0,
                    (int) $pdo->query('SELECT COUNT(*) FROM compliance_reminder_log')->fetchColumn(),
                    'a switched-off pass must not claim thresholds it never sent'
                );
            } finally {
                putenv($var);
                unset($_ENV[$var]);
            }
        }

        // And with both unset — which per lib/feature_flags.php means ON — it
        // sends. Shipping this dark means SETTING them off, not merely not
        // setting them, and that asymmetry is worth pinning next to the above.
        $pdo = $this->pdo();
        $this->verified($pdo, 50, 10, '2026-10-01');
        $result = te_compliance_dispatch_reminders($pdo, [
            'today'  => '2026-09-06',
            'mailer' => fn (): bool => true,
        ]);
        $this->assertSame(1, $result['sent']);
    }

    /**
     * The claim is an INSERT and it happens BEFORE the send.
     *
     * A crash between the two costs one missed reminder. The other ordering
     * costs a duplicate blast to 30,000 people, which cannot be undone.
     */
    public function testTheClaimIsWrittenBeforeTheSend(): void
    {
        $pdo = $this->pdo();
        $credentialId = $this->verified($pdo, 50, 10, '2026-10-01');

        $rowsAtSendTime = null;
        te_compliance_dispatch_reminders($pdo, [
            'today'  => '2026-09-06',
            'mailer' => function () use ($pdo, &$rowsAtSendTime): bool {
                $rowsAtSendTime = (int) $pdo->query('SELECT COUNT(*) FROM compliance_reminder_log')->fetchColumn();
                return true;
            },
        ]);

        $this->assertSame(1, $rowsAtSendTime, 'the log row must exist before the mailer runs');
        $this->assertTrue(te_compliance_claim_reminder($pdo, $credentialId, 60));
        $this->assertFalse(te_compliance_claim_reminder($pdo, $credentialId, 60), 're-claiming must fail');
    }

    /** A send the mailer refuses releases its claim, so the next tick retries. */
    public function testAFailedSendIsRetriedOnTheNextTick(): void
    {
        $pdo = $this->pdo();
        $this->verified($pdo, 50, 10, '2026-10-01');

        $attempts = 0;
        $flaky = function () use (&$attempts): bool {
            $attempts++;
            return $attempts > 1;
        };

        $first = te_compliance_dispatch_reminders($pdo, ['today' => '2026-09-06', 'mailer' => $flaky]);
        $this->assertSame(1, $first['failed']);
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM compliance_reminder_log')->fetchColumn(),
            'a failed send must not leave a claim behind, or the person is never reminded');

        $second = te_compliance_dispatch_reminders($pdo, ['today' => '2026-09-06', 'mailer' => $flaky]);
        $this->assertSame(1, $second['sent']);
    }

    // ------------------------------------------------------------- audience

    /** One email per person per threshold, naming every requirement in it. */
    public function testTwoRequirementsExpiringTogetherAreOneEmail(): void
    {
        $pdo = $this->pdo();
        $this->verified($pdo, 50, 10, '2026-10-01');
        // Requirement 11 has no validity_days, so give it an explicit expiry.
        $pdo->exec("INSERT INTO person_credentials (user_id, requirement_id, status, completed_at, expires_at, source)
                    VALUES (50, 11, 'verified', '2026-01-01', '2026-10-02', 'admin')");

        $envelopes = te_compliance_pending_reminders($pdo, ['today' => '2026-09-06']);

        $this->assertSame([[
            'user' => 50, 'club' => 100, 'threshold' => 30,
            'names' => ['SafeSport', 'Concussion protocol'],
        ]], $this->shape($envelopes));
    }

    /**
     * A stored `missing` row on a REQUIRED requirement is nudged once; a
     * dormant account is not nudged at all.
     *
     * The dormant guard is not politeness. 30,000 addresses that have not been
     * signed into in three months is a bounce rate that costs a sending domain
     * its reputation, and it buys nothing — nobody acts on it.
     */
    public function testAMissingRequiredRowNudgesOnlyRecentlyActivePeople(): void
    {
        $pdo = $this->pdo();
        $pdo->exec("INSERT INTO person_credentials (user_id, requirement_id, status, source) VALUES
            (52, 10, 'missing', 'admin'),
            (54, 10, 'missing', 'admin')");

        $envelopes = te_compliance_pending_reminders($pdo, ['today' => '2026-09-06']);

        $this->assertSame([[
            'user' => 52, 'club' => 100, 'threshold' => TE_COMPLIANCE_REMINDER_MISSING_THRESHOLD,
            'names' => ['SafeSport'],
        ]], $this->shape($envelopes), 'the dormant account (54) must not be mailed');
    }

    /** An OPTIONAL requirement never produces a missing-nudge. */
    public function testAnOptionalMissingRowIsNotNudged(): void
    {
        $pdo = $this->pdo();
        // 13 (parking pass) is required = 0 and applies to volunteers, i.e. 51.
        $pdo->exec("INSERT INTO person_credentials (user_id, requirement_id, status, source)
                    VALUES (51, 13, 'missing', 'admin')");

        $this->assertSame([], te_compliance_pending_reminders($pdo, ['today' => '2026-09-06']));
    }

    /**
     * A requirement with NO stored row is deliberately not mailed about.
     *
     * The absence of a person_credentials row is how "missing" is normally
     * stored, so synthesising envelopes for those would mean 30,000 coaches ×
     * their inherited requirements on the very first tick — the replay the chat
     * notification lookback window exists to prevent, an order of magnitude
     * larger. The dashboard alert card covers this case in-product.
     */
    public function testARequirementWithNoRecordAtAllIsNotMailedAbout(): void
    {
        $pdo = $this->pdo();
        // User 52 is a coach owing SafeSport and has no credential row at all.
        $this->assertSame([], te_compliance_pending_reminders($pdo, ['today' => '2026-09-06']));
    }

    /** submitted and rejected are with somebody else, or already loud. */
    public function testSubmittedAndRejectedCredentialsAreNotReminded(): void
    {
        $pdo = $this->pdo();
        $pdo->exec("INSERT INTO person_credentials (user_id, requirement_id, status, expires_at, source) VALUES
            (50, 10, 'submitted', '2026-10-01', 'portal'),
            (52, 10, 'rejected', NULL, 'portal')");

        $this->assertSame([], te_compliance_pending_reminders($pdo, ['today' => '2026-09-06']));
    }

    /** Nobody with a staff role but no address is retried for ever. */
    public function testSomebodyWithNoEmailIsSkippedNotFailed(): void
    {
        $pdo = $this->pdo();
        $this->verified($pdo, 55, 10, '2026-10-01');

        $result = te_compliance_dispatch_reminders($pdo, [
            'today'  => '2026-09-06',
            'mailer' => function (): bool { $this->fail('nothing should be mailed'); },
        ]);

        $this->assertSame(0, $result['sent']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, $result['failed']);
    }

    /** The per-tick cap is applied and REPORTED, never silent. */
    public function testThePerTickCapIsReported(): void
    {
        $pdo = $this->pdo();
        $this->verified($pdo, 50, 10, '2026-10-01');
        $this->verified($pdo, 52, 10, '2026-10-01');

        $result = te_compliance_dispatch_reminders($pdo, [
            'today'  => '2026-09-06',
            'limit'  => 1,
            'mailer' => fn (): bool => true,
        ]);

        $this->assertSame(1, $result['sent']);
        $this->assertTrue($result['capped'], 'a pass that hit the ceiling must say so');

        // The remainder is picked up next pass rather than dropped.
        $next = te_compliance_dispatch_reminders($pdo, [
            'today'  => '2026-09-06',
            'limit'  => 1,
            'mailer' => fn (): bool => true,
        ]);
        $this->assertSame(1, $next['sent']);
    }

    // ----------------------------------------------------------------- copy

    /** Requirement names and dates only. No notes, no reasons, nobody else. */
    public function testTheCopyCarriesNamesAndDatesAndNothingElse(): void
    {
        $copy = te_compliance_reminder_copy([
            'user_id' => 50, 'club_id' => 100, 'threshold' => 30,
            'items' => [
                ['credential_id' => 1, 'name' => 'SafeSport', 'expires_at' => '2026-10-01', 'days' => 25],
            ],
        ]);

        $this->assertStringContainsString('30 days', $copy['subject']);
        $this->assertSame(['SafeSport — Expires October 1, 2026'], $copy['lines']);
        $this->assertSame(['SafeSport' => 'Expires October 1, 2026'], $copy['rows']);
        $this->assertStringContainsString('Sign in', $copy['cta']);
    }

    /** A date is rendered off the STRING; no zone can move it a day. */
    public function testADateIsFormattedWithoutEverBecomingAnInstant(): void
    {
        $this->assertSame('January 1, 2026', te_compliance_reminder_format_date('2026-01-01'));
        $this->assertSame('December 31, 2026', te_compliance_reminder_format_date('2026-12-31'));
        $this->assertSame('March 1, 2028', te_compliance_reminder_format_date('2028-03-01'));
    }

    /** Two tiers naming a rule the same thing must both appear. */
    public function testTwoRequirementsWithTheSameNameBothSurvive(): void
    {
        $copy = te_compliance_reminder_copy([
            'threshold' => 7,
            'items' => [
                ['credential_id' => 1, 'name' => 'Background check', 'expires_at' => '2026-10-01', 'days' => 3],
                ['credential_id' => 2, 'name' => 'Background check', 'expires_at' => '2026-10-05', 'days' => 7],
            ],
        ]);

        $this->assertCount(2, $copy['rows']);
        $this->assertCount(2, $copy['lines']);
    }

    /** The missing nudge says what it means, and names no expiry. */
    public function testTheMissingNudgeReadsAsOutstandingNotExpiring(): void
    {
        $copy = te_compliance_reminder_copy([
            'threshold' => TE_COMPLIANCE_REMINDER_MISSING_THRESHOLD,
            'items' => [['credential_id' => 1, 'name' => 'SafeSport', 'expires_at' => null, 'days' => null]],
        ]);

        $this->assertStringContainsString('outstanding', strtolower($copy['subject']));
        $this->assertStringNotContainsString('expire', strtolower($copy['subject']));
        $this->assertSame(['SafeSport' => 'Not on file'], $copy['rows']);
    }

    // --------------------------------------------------------------- export

    /** The sheet is one row per (person × requirement), with the origin named. */
    public function testTheExportIsLongFormAndNamesWhereEachRuleComesFrom(): void
    {
        $pdo = $this->pdo();
        $this->verified($pdo, 50, 10, '2026-10-01');

        $sheet = te_compliance_export_sheet($pdo, 100, '', '2026-09-06');

        $this->assertSame('Required by', $sheet['headers'][5]);
        // Hana Head is a head_coach: SafeSport (national) + Concussion (division).
        $origins = [];
        foreach ($sheet['rows'] as $row) {
            if ($row[1] === 'Hana') {
                $origins[$row[4]] = $row[5];
            }
        }
        $this->assertSame([
            'SafeSport'           => 'National — Girls on the Run',
            'Concussion protocol' => 'Division — West',
        ], $origins);
    }

    /** A club's own rule is labelled as its own, not attributed to a tier. */
    public function testAClubsOwnRuleIsLabelledThisClub(): void
    {
        $pdo = $this->pdo();
        $sheet = te_compliance_export_sheet($pdo, 100, '', '2026-09-06');

        $parking = array_values(array_filter(
            $sheet['rows'],
            static fn (array $r): bool => $r[4] === 'Council parking pass'
        ));
        $this->assertNotEmpty($parking);
        $this->assertSame('This club', $parking[0][5]);
        $this->assertSame('No', $parking[0][7], 'the parking pass is optional');
    }

    /** The filter is the SAME predicate the screen uses, so the file matches it. */
    public function testTheFilterMatchesTheScreensPredicate(): void
    {
        $pdo = $this->pdo();
        // Hana verified on both her required rules => compliant.
        $this->verified($pdo, 50, 10, '2027-10-01');
        $pdo->exec("INSERT INTO person_credentials (user_id, requirement_id, status, completed_at, expires_at, source)
                    VALUES (50, 11, 'verified', '2026-01-01', NULL, 'admin')");

        $compliant = te_compliance_export_sheet($pdo, 100, 'compliant', '2026-09-06');
        $names = array_unique(array_map(static fn (array $r): string => $r[1], $compliant['rows']));
        $this->assertSame(['Hana'], array_values($names));

        $missing = te_compliance_export_sheet($pdo, 100, 'missing', '2026-09-06');
        $missingNames = array_unique(array_map(static fn (array $r): string => $r[1], $missing['rows']));
        $this->assertNotContains('Hana', $missingNames);
    }

    /**
     * ⚠️ The cap is reported, and the total keeps counting past it.
     *
     * A CSV is a download. Nothing is rendered back to the person who pressed
     * the button, so a file that stops at the cap is indistinguishable from a
     * club that has exactly that many rows — unless we say so.
     */
    public function testTheRowCapIsAppliedAndSaidOutLoud(): void
    {
        $pdo = $this->pdo();
        // 5 staff × up to 3 requirements is comfortably over a cap of 4.
        $sheet = te_compliance_export_sheet($pdo, 100, '', '2026-09-06');
        $this->assertGreaterThan(4, $sheet['total_rows']);

        $capped = $sheet;
        $capped['rows'] = array_slice($sheet['rows'], 0, 4);
        $capped['omitted_rows'] = $sheet['total_rows'] - 4;

        $notice = te_compliance_export_truncation_notice($capped);
        $this->assertNotNull($notice);
        $this->assertStringContainsString((string) $capped['omitted_rows'], $notice);
        $this->assertStringContainsString((string) TE_COMPLIANCE_EXPORT_MAX_ROWS, $notice);

        // A complete file says nothing at all — a notice on every download is a
        // notice nobody reads.
        $this->assertNull(te_compliance_export_truncation_notice($sheet));
    }

    /** Families are not staff and never appear in a compliance report. */
    public function testAParentIsNotInTheComplianceReport(): void
    {
        $pdo = $this->pdo();
        $sheet = te_compliance_export_sheet($pdo, 100, '', '2026-09-06');
        $firstNames = array_map(static fn (array $r): string => $r[1], $sheet['rows']);
        $this->assertNotContains('Pat', $firstNames);
    }

    /** A club name is header text — it must not be able to inject one. */
    public function testTheFilenameCannotInjectAHeader(): void
    {
        $name = te_compliance_export_filename("Evil\r\nSet-Cookie: a=b", 'expiring', '2026-09-06');
        $this->assertStringNotContainsString("\r", $name);
        $this->assertStringNotContainsString("\n", $name);
        $this->assertStringNotContainsString('"', $name);
        $this->assertStringContainsString('expiring', $name);
        $this->assertStringContainsString('2026-09-06', $name);
    }

    /**
     * The endpoint is procedural and cannot be executed, so this parses it.
     *
     * The failure it guards against is not a wrong predicate — it is a read that
     * happens before the gate. There is no auth layer in index.php; whatever this
     * file does is the whole of the access control.
     */
    public function testTheExportEndpointGatesBeforeItReadsAnything(): void
    {
        $src = file_get_contents(__DIR__ . '/../../api/compliance-export.php');

        $auth = strpos($src, 'AuthMiddleware::requireAuth()');
        $flag = strpos($src, "if (!te_feature_enabled('COMPLIANCE'))");
        $gate = strpos($src, 'te_compliance_can_admin_club($pdo, $auth, $clubId)');
        $read = strpos($src, 'te_compliance_export_sheet(');

        $this->assertNotFalse($auth, 'the export must authenticate');
        $this->assertNotFalse($flag, 'the kill switch is gone');
        $this->assertNotFalse($gate, 'the export must check standing');
        $this->assertNotFalse($read, 'the export must build a sheet');

        $this->assertLessThan($flag, $auth, 'authenticate first, then check the switch');
        $this->assertLessThan($gate, $flag);
        $this->assertLessThan($read, $gate, 'nothing may be read before the standing check');

        // The ADMIN predicate, not club membership and not te_is_club_staff: a
        // coach is team-scoped and this file is every other staff member's
        // background-check history.
        $this->assertStringNotContainsString('te_is_club_staff(', $src);
        $this->assertStringNotContainsString('canAccessClub(', $src);

        // A bulk export of staff compliance is exactly the event a council needs
        // to reconstruct for an insurer later.
        $this->assertStringContainsString("'compliance_exported'", $src);

        // BOM first, or Excel mangles every accented name.
        $this->assertStringContainsString('"\xEF\xBB\xBF"', $src);

        // The cap reaches the person who pressed the button.
        $this->assertStringContainsString('X-Compliance-Export-Truncated', $src);
        $this->assertStringContainsString('Access-Control-Expose-Headers', $src);

        // An unrecognised filter is refused, never quietly treated as "everyone".
        $this->assertStringContainsString('TE_COMPLIANCE_EXPORT_FILTERS', $src);
    }

    // ------------------------------------------------------------- the tick

    /**
     * The worker tick: both switches, the lock, the sweep before the reminders,
     * and its own catch.
     *
     * Parsed rather than executed — queue-worker.php is an infinite loop that
     * connects to Redis and Neon at load. Every property below has cost this
     * repo a live incident in some other tick, which is why they are pinned
     * individually rather than as "the tick exists".
     */
    public function testTheTickIsGatedLockedAndSweepsFirst(): void
    {
        $src = file_get_contents(__DIR__ . '/../../workers/queue-worker.php');

        $throttle = strpos($src, 'TE_COMPLIANCE_TICK_SECONDS)');
        $this->assertNotFalse($throttle, 'the compliance tick is not throttled');

        // BOTH switches. COMPLIANCE is the whole feature; COMPLIANCE_REMINDERS is
        // this tick alone, so the screens can be live while nothing is mailed.
        $gate = strpos($src, "te_feature_enabled('COMPLIANCE') && te_feature_enabled('COMPLIANCE_REMINDERS')");
        $this->assertNotFalse($gate, 'the tick must require both switches');
        $this->assertGreaterThan($throttle, $gate);

        // The lock, so two worker processes cannot both send.
        $lock = strpos($src, "te_worker_tick_lock(\$tickLockClient, 'compliance_reminders')");
        $this->assertNotFalse($lock, 'the tick must take its own lock');
        $this->assertGreaterThan($gate, $lock);
        $this->assertStringContainsString(
            "te_worker_tick_unlock(\$tickLockClient, 'compliance_reminders', \$complianceLock)",
            $src,
            'the lock must be released'
        );
        $this->assertStringContainsString('} finally {', $src, 'the unlock must be in a finally');

        // The sweep runs inside the tick, and BEFORE the reminders — the reminder
        // pass only considers `verified` rows, so a certificate that lapsed
        // overnight would otherwise be mailed about as if it were days from due.
        $sweep = strpos($src, 'te_compliance_expire_sweep($db)');
        $send = strpos($src, 'te_compliance_dispatch_reminders($db)');
        $this->assertNotFalse($sweep, 'the expiry sweep must run in this tick');
        $this->assertNotFalse($send);
        $this->assertLessThan($send, $sweep, 'sweep before reminders');
        $this->assertGreaterThan($lock, $sweep, 'the sweep must be inside the lock');

        // The handle is refreshed first. Neon's pooler drops idle connections and
        // this tick may be the first database work in six hours.
        $ensure = strrpos(substr($src, 0, $sweep), '$ensureDb();');
        $this->assertNotFalse($ensure, 'the tick must call $ensureDb() before touching the database');

        // Its own catch. An uncaught throw here stops email, SMS, imports and
        // calendar sync along with it.
        $catch = strpos($src, "error_log('[Worker] compliance sweep error: '");
        $this->assertNotFalse($catch, 'the tick needs its own catch');
        $this->assertGreaterThan($send, $catch);

        // The cap is reported in the tick's line, so a pass that stopped at the
        // ceiling does not read as a quiet night.
        $this->assertStringContainsString("per-tick cap reached", $src);
    }

    /** Reminders go through lib/Email.php + forClub, never EmailSendService. */
    public function testRemindersSendThroughTheBrandedTransactionalPathOnly(): void
    {
        $src = file_get_contents(__DIR__ . '/../../lib/compliance_reminders.php');

        $this->assertStringContainsString('(new Email())->forClub($pdo, (int) $envelope[\'club_id\'])', $src);
        $this->assertStringContainsString('sendComplianceReminder', $src);

        // EmailSendService writes a communication_log row per send (flooding
        // Email Reporting) and applies email_suppressions — the club's MARKETING
        // opt-out. A coach who unsubscribed from broadcasts must still be told
        // their background check expires next week. Both failures are invisible.
        $this->assertFalse(strpos($src, 'new EmailSendService'), 'never construct EmailSendService here');
        $this->assertFalse(strpos($src, 'EmailSendService('), 'never call EmailSendService here');

        // Every Email that is CONSTRUCTED is branded. Counted past the file's
        // header docblock, which names ->forClub() in prose — the same shape as
        // EmailSenderTest's per-file count, which compares code and not comments.
        $code = substr($src, (int) strpos($src, "require_once __DIR__ . '/compliance.php';"));
        $this->assertSame(
            preg_match_all('/new\s+Email\s*\(\s*\)/', $code),
            substr_count($code, '->forClub('),
            'every Email in this file must brand as the club'
        );
    }

    /** Migration 093 carries its own reverse, and the index it promises. */
    public function testTheMigrationCarriesItsOwnReverse(): void
    {
        $sql = file_get_contents(__DIR__ . '/../../database/migrations/093_compliance_default_reminder_stream.sql');

        $this->assertStringContainsString('REVERSE SQL', $sql);
        $this->assertStringContainsString('ALTER COLUMN stream_id DROP NOT NULL', $sql);
        $this->assertStringContainsString('ALTER COLUMN stream_id SET NOT NULL', $sql);
        $this->assertStringContainsString(TE_COMPLIANCE_REMINDER_DEDUPE_INDEX, $sql);
        // The partial predicate is the whole point: without it the index would
        // forbid a second authored-stream row that is perfectly legitimate.
        $this->assertStringContainsString('WHERE stream_id IS NULL', $sql);
    }
}
