<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/compliance.php';
require_once __DIR__ . '/../../lib/compliance_reminders.php';
require_once __DIR__ . '/../../lib/compliance_intake.php';
require_once __DIR__ . '/ComplianceG7Fixture.php';

/**
 * GOTR G7 — the LMS intake feed (migration 098).
 *
 * Pinned: a key authenticates by hash and a revoked or unknown one is refused;
 * a key is scoped to its org unit and a request naming another unit is a 403;
 * a person the unit does not have is a 202 with an unmatched row, never a new
 * user; the credential written carries source='lms'; the per-key rate limit
 * holds with Redis and without it; an admin can match an arrival by hand.
 */
class ComplianceIntakeTest extends TestCase
{
    use ComplianceG7Fixture;

    private function key(PDO $pdo, int $unit = 2): array
    {
        $created = te_compliance_intake_key_create($pdo, $unit, 'Cornerstone', 90);
        $this->assertTrue($created['ok'], $created['error'] ?? '');
        return $created;
    }

    // ------------------------------------------------------------------ keys

    public function testAKeyIsStoredHashedShownOnceAndAuthenticatesByHash(): void
    {
        $pdo = $this->g7pdo();
        $created = $this->key($pdo);

        $this->assertStringStartsWith('tei_', $created['plain']);
        $this->assertGreaterThan(30, strlen($created['plain']));
        $row = $pdo->query('SELECT * FROM compliance_intake_keys')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(hash('sha256', $created['plain']), $row['key_hash']);
        $this->assertStringNotContainsString($created['plain'], json_encode($row), 'the plaintext is never stored');
        $this->assertSame(substr($created['plain'], 0, 8), $row['key_prefix']);

        $auth = te_compliance_intake_authenticate($pdo, $created['plain']);
        $this->assertTrue($auth['ok']);
        $this->assertSame(2, $auth['key']['org_unit_id']);

        // The list an admin sees carries the prefix and never the hash.
        $listed = te_compliance_intake_keys($pdo, 2);
        $this->assertCount(1, $listed);
        $this->assertArrayNotHasKey('key_hash', $listed[0]);
        $this->assertSame($row['key_prefix'], $listed[0]['key_prefix']);
    }

    public function testABadOrRevokedKeyIsRefused(): void
    {
        $pdo = $this->g7pdo();
        $created = $this->key($pdo);

        $this->assertSame('missing', te_compliance_intake_authenticate($pdo, null)['reason']);
        $this->assertSame('missing', te_compliance_intake_authenticate($pdo, '')['reason']);
        $this->assertSame('unknown', te_compliance_intake_authenticate($pdo, 'tei_' . str_repeat('0', 40))['reason']);

        $revoked = te_compliance_intake_key_revoke($pdo, $created['id'], 2, 90);
        $this->assertTrue($revoked['ok']);
        $this->assertSame('revoked', te_compliance_intake_authenticate($pdo, $created['plain'])['reason']);

        // Revoking a key that belongs to another unit is refused, not silently no-op'd.
        $other = te_compliance_intake_key_create($pdo, 5, 'Elsewhere', 90);
        $this->assertFalse(te_compliance_intake_key_revoke($pdo, $other['id'], 2, 90)['ok']);
    }

    public function testTheBearerIsReadFromTheAuthorizationHeaderOnly(): void
    {
        $this->assertSame('tei_abc', te_compliance_intake_bearer_from_header('Bearer tei_abc'));
        $this->assertSame('tei_abc', te_compliance_intake_bearer_from_header('bearer   tei_abc '));
        $this->assertNull(te_compliance_intake_bearer_from_header('Basic xyz'));
        $this->assertNull(te_compliance_intake_bearer_from_header(null));
    }

    // --------------------------------------------------------------- receive

    private function receive(PDO $pdo, array $key, array $body): array
    {
        return te_compliance_intake_receive($pdo, $key['key'] ?? te_compliance_intake_authenticate($pdo, $key['plain'])['key'], $body, '2026-09-06');
    }

    public function testAKnownPersonGetsAVerifiedLmsCredential(): void
    {
        $pdo = $this->g7pdo();
        $key = $this->key($pdo);
        $result = $this->receive($pdo, $key, [
            'email' => 'HEAD@gotr.org', 'requirement_key' => 'safesport',
            'completed_on' => '2026-09-01', 'external_id' => 'lms-1',
        ]);
        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertTrue($result['matched']);
        $this->assertSame(50, $result['user_id']);

        $row = $pdo->query('SELECT * FROM person_credentials')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('lms', $row['source']);
        $this->assertSame('verified', $row['status']);
        $this->assertSame(10, (int) $row['requirement_id']);
        $this->assertSame('2026-09-01', $row['completed_at']);
        $this->assertSame('2027-09-01', $row['expires_at'], 'expiry is computed from the requirement, as everywhere else');
        $this->assertStringContainsString('lms-1', (string) $row['notes']);
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM compliance_intake_unmatched')->fetchColumn());

        // The requirement can also be named by id.
        $byId = $this->receive($pdo, $key, ['email' => 'head@gotr.org', 'requirement_key' => '10', 'completed_on' => '2026-09-02']);
        $this->assertTrue($byId['matched']);
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM person_credentials')->fetchColumn(), 'one row per person per requirement');
    }

    public function testAnUnknownPersonIsAnUnmatchedRowAndNeverANewUser(): void
    {
        $pdo = $this->g7pdo();
        $key = $this->key($pdo);
        $before = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

        $result = $this->receive($pdo, $key, [
            'email' => 'stranger@gotr.org', 'requirement_key' => 'safesport', 'completed_on' => '2026-09-01',
        ]);
        $this->assertTrue($result['ok']);
        $this->assertFalse($result['matched']);
        $this->assertSame('no_person', $result['reason']);
        $this->assertSame($before, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM person_credentials')->fetchColumn());

        $open = te_compliance_intake_unmatched($pdo, 2);
        $this->assertCount(1, $open);
        $this->assertSame('stranger@gotr.org', $open[0]['email']);
        $this->assertSame('no_person', $open[0]['reason']);
    }

    /** A person who exists on the platform but not UNDER the key's unit is unknown to that key. */
    public function testAPersonOutsideTheUnitIsUnmatched(): void
    {
        $pdo = $this->g7pdo();
        $key = $this->key($pdo, 2);
        // 57 is a coach in the other tree; 53 is a parent at Kansas — not staff.
        foreach (['other@elsewhere.org', 'parent@gotr.org'] as $email) {
            $result = $this->receive($pdo, $key, ['email' => $email, 'requirement_key' => 'safesport', 'completed_on' => '2026-09-01']);
            $this->assertFalse($result['matched'], $email);
            $this->assertSame('no_person', $result['reason']);
        }
        // A key at the Kansas council does not reach California's coach.
        $council = $this->key($pdo, 3);
        $this->assertFalse($this->receive($pdo, $council, ['email' => 'cali@gotr.org', 'requirement_key' => 'safesport', 'completed_on' => '2026-09-01'])['matched']);
        $this->assertTrue($this->receive($pdo, $council, ['email' => 'head@gotr.org', 'requirement_key' => 'safesport', 'completed_on' => '2026-09-01'])['matched']);
    }

    public function testAnUnknownRequirementKeyIsUnmatchedNotInvented(): void
    {
        $pdo = $this->g7pdo();
        $key = $this->key($pdo);
        $result = $this->receive($pdo, $key, ['email' => 'head@gotr.org', 'requirement_key' => 'basket_weaving', 'completed_on' => '2026-09-01']);
        $this->assertFalse($result['matched']);
        $this->assertSame('no_requirement', $result['reason']);
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM compliance_requirements WHERE name LIKE \'%basket%\'')->fetchColumn());

        // A requirement the person's club does not inherit is equally unknown:
        // club 100's parking pass is not California's.
        $this->assertSame('no_requirement', $this->receive($pdo, $key, ['email' => 'cali@gotr.org', 'requirement_key' => 'council_parking_pass', 'completed_on' => '2026-09-01'])['reason']);
    }

    public function testTheBodyIsValidatedBeforeAnythingIsWritten(): void
    {
        $pdo = $this->g7pdo();
        $key = $this->key($pdo);
        foreach ([
            ['requirement_key' => 'safesport', 'completed_on' => '2026-09-01'],
            ['email' => 'not-an-email', 'requirement_key' => 'safesport', 'completed_on' => '2026-09-01'],
            ['email' => 'head@gotr.org', 'completed_on' => '2026-09-01'],
            ['email' => 'head@gotr.org', 'requirement_key' => 'safesport'],
            ['email' => 'head@gotr.org', 'requirement_key' => 'safesport', 'completed_on' => '09/01/2026'],
            ['email' => 'head@gotr.org', 'requirement_key' => 'safesport', 'completed_on' => '2099-01-01'],
        ] as $body) {
            $result = $this->receive($pdo, $key, $body);
            $this->assertFalse($result['ok'], json_encode($body));
            $this->assertSame('invalid', $result['reason']);
        }
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM person_credentials')->fetchColumn());
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM compliance_intake_unmatched')->fetchColumn());
    }

    public function testAnUnmatchedArrivalCanBeMatchedByAnAdminAndIsThenClosed(): void
    {
        $pdo = $this->g7pdo();
        $key = $this->key($pdo);
        $this->receive($pdo, $key, ['email' => 'h.head@gotr.org', 'requirement_key' => 'safesport', 'completed_on' => '2026-09-01', 'external_id' => 'x9']);
        $open = te_compliance_intake_unmatched($pdo, 2);
        $this->assertCount(1, $open);

        // Matching to somebody outside the unit is refused.
        $refused = te_compliance_intake_match($pdo, 2, $open[0]['id'], 57, 90);
        $this->assertFalse($refused['ok']);
        $this->assertSame('person_not_under_unit', $refused['reason']);

        $matched = te_compliance_intake_match($pdo, 2, $open[0]['id'], 50, 90);
        $this->assertTrue($matched['ok'], $matched['error'] ?? '');
        $row = $pdo->query('SELECT * FROM person_credentials')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(50, (int) $row['user_id']);
        $this->assertSame('lms', $row['source']);
        $this->assertSame('2026-09-01', $row['completed_at']);

        $this->assertSame([], te_compliance_intake_unmatched($pdo, 2), 'a matched arrival leaves the queue');
        $closed = $pdo->query('SELECT * FROM compliance_intake_unmatched')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(50, (int) $closed['matched_user_id']);
        $this->assertSame((int) $row['id'], (int) $closed['credential_id']);
        $this->assertNotNull($closed['matched_at']);

        // Matching again is refused: it is closed.
        $this->assertFalse(te_compliance_intake_match($pdo, 2, $open[0]['id'], 50, 90)['ok']);
        // And a unit that does not own the row cannot match it.
        $this->receive($pdo, $key, ['email' => 'nobody@gotr.org', 'requirement_key' => 'safesport', 'completed_on' => '2026-09-01']);
        $second = te_compliance_intake_unmatched($pdo, 2)[0];
        $this->assertFalse(te_compliance_intake_match($pdo, 5, $second['id'], 50, 90)['ok']);
    }

    /** A no_requirement arrival matched by hand needs the requirement chosen. */
    public function testMatchingANoRequirementArrivalTakesARequirementId(): void
    {
        $pdo = $this->g7pdo();
        $key = $this->key($pdo);
        $this->receive($pdo, $key, ['email' => 'head@gotr.org', 'requirement_key' => 'safe-sport-2026', 'completed_on' => '2026-09-01']);
        $open = te_compliance_intake_unmatched($pdo, 2)[0];
        $this->assertSame('no_requirement', $open['reason']);

        $this->assertFalse(te_compliance_intake_match($pdo, 2, $open['id'], 50, 90)['ok'], 'no requirement to write against');
        $ok = te_compliance_intake_match($pdo, 2, $open['id'], 50, 90, 10);
        $this->assertTrue($ok['ok'], $ok['error'] ?? '');
        $this->assertSame(10, (int) $pdo->query('SELECT requirement_id FROM person_credentials')->fetchColumn());
    }

    // ------------------------------------------------------------ rate limit

    public function testTheRateLimitHoldsThroughRedis(): void
    {
        $pdo = $this->g7pdo();
        $redis = new class {
            public array $counts = [];
            public array $expires = [];
            public function incr(string $k): int { return $this->counts[$k] = ($this->counts[$k] ?? 0) + 1; }
            public function expire(string $k, int $ttl): void { $this->expires[$k] = $ttl; }
        };
        for ($i = 1; $i <= TE_COMPLIANCE_INTAKE_RATE_PER_MINUTE; $i++) {
            $this->assertFalse(te_compliance_intake_rate_limited($pdo, 7, $redis, '2026-09-06 10:00:00'), "request $i");
        }
        $this->assertTrue(te_compliance_intake_rate_limited($pdo, 7, $redis, '2026-09-06 10:00:30'));
        // A different key and the next minute are both clean.
        $this->assertFalse(te_compliance_intake_rate_limited($pdo, 8, $redis, '2026-09-06 10:00:30'));
        $this->assertFalse(te_compliance_intake_rate_limited($pdo, 7, $redis, '2026-09-06 10:01:00'));
        $this->assertNotEmpty($redis->expires, 'the counter must expire on its own');
    }

    public function testWithoutRedisTheRateLimitCountsAuditRows(): void
    {
        $pdo = $this->g7pdo();
        $insert = $pdo->prepare(
            "INSERT INTO audit_log (user_id, action, resource_type, resource_id, created_at) VALUES (NULL, 'compliance_intake_received', 'compliance_intake_keys', ?, ?)"
        );
        for ($i = 0; $i < TE_COMPLIANCE_INTAKE_RATE_PER_MINUTE; $i++) {
            $insert->execute([7, '2026-09-06 10:00:10']);
        }
        $this->assertTrue(te_compliance_intake_rate_limited($pdo, 7, null, '2026-09-06 10:00:40'));
        $this->assertFalse(te_compliance_intake_rate_limited($pdo, 8, null, '2026-09-06 10:00:40'), 'another key');
        $this->assertFalse(te_compliance_intake_rate_limited($pdo, 7, null, '2026-09-06 10:02:00'), 'the window has passed');
    }

    /** A broken Redis client falls through to the database count rather than to "unlimited". */
    public function testABrokenRedisFallsBackToTheDatabaseCount(): void
    {
        $pdo = $this->g7pdo();
        $broken = new class {
            public function incr(string $k): int { throw new RuntimeException('connection refused'); }
            public function expire(string $k, int $ttl): void {}
        };
        $this->assertFalse(te_compliance_intake_rate_limited($pdo, 7, $broken, '2026-09-06 10:00:00'));
    }

    // -------------------------------------------------------------- gateway

    public function testTheIntakeGatewayIsShapedTheWayTheFeedNeeds(): void
    {
        $src = file_get_contents(__DIR__ . '/../../api/compliance-intake.php');

        // The feed action authenticates by KEY, before any JWT check, and the
        // admin actions by JWT + org_admin standing.
        $lms = strpos($src, "\$action === 'lms'");
        // The STATEMENT, not the mention of it in the file's docblock.
        $auth = strpos($src, '$auth = AuthMiddleware::requireAuth();');
        $this->assertNotFalse($lms);
        $this->assertNotFalse($auth);
        $this->assertLessThan($auth, $lms, 'the lms action must not require a user token');
        $this->assertStringContainsString('te_compliance_intake_authenticate(', $src);
        $this->assertStringContainsString("te_user_org_standing(\$pdo, \$auth, \$orgUnitId) !== 'org_admin'", $src);

        // Both switches, and the response never claims success for a switched-off feed.
        $this->assertStringContainsString("te_feature_enabled('COMPLIANCE')", $src);
        $this->assertStringContainsString("te_feature_enabled('COMPLIANCE_INTAKE')", $src);
        $this->assertStringContainsString("te_feature_disabled_response('COMPLIANCE_INTAKE')", $src);

        // The status codes the spec names.
        foreach (['401', '403', '202', '429', '503'] as $code) {
            $this->assertStringContainsString("http_response_code($code)", $src, "no $code");
        }
        $this->assertStringContainsString('te_compliance_intake_rate_limited(', $src);

        foreach (['key-create', 'key-revoke', 'keys', 'unmatched', 'match'] as $action) {
            $this->assertNotFalse(strpos($src, "\$action === '$action'"), "action $action missing");
        }
        foreach ([
            'compliance_intake_received', 'compliance_intake_key_created', 'compliance_intake_key_revoked',
            'compliance_intake_matched',
        ] as $a) {
            $this->assertStringContainsString("'$a'", $src, "no audit row for $a");
        }
        // The key is in the create response ONCE and never in the list.
        $this->assertStringContainsString("'key' => \$created['plain']", $src);
    }

    /** The migration carries its reverse and creates exactly the two tables the lib names. */
    public function testTheMigrationCarriesItsOwnReverse(): void
    {
        $sql = file_get_contents(__DIR__ . '/../../database/migrations/098_compliance_intake.sql');
        $this->assertStringContainsString('REVERSE SQL', $sql);
        foreach (TE_COMPLIANCE_INTAKE_TABLES as $table) {
            $this->assertStringContainsString("CREATE TABLE IF NOT EXISTS $table", $sql);
            $this->assertStringContainsString("DROP TABLE IF EXISTS $table", $sql);
        }
        $this->assertStringNotContainsString('ALTER TABLE', $sql, 'additive only');
    }
}
