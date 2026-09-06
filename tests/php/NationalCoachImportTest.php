<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;
use ImportStrategy;
use ImportJobProcessor;
use NationalCoachImportStrategy;

require_once __DIR__ . '/../../services/ImportJobProcessor.php';
require_once __DIR__ . '/../../services/NationalCoachImportStrategy.php';

/**
 * A strategy that counts rows and does nothing else — the outer loop's memory
 * and progress behaviour are what the 50k-row test measures, not row work.
 */
class CountingImportStrategy extends ImportStrategy
{
    public int $rows = 0;
    public array $lastContext = [];
    public function getEntityType(): string { return 'counting'; }
    public function getRequiredFields(): array { return ['first_name']; }
    public function getOptionalFields(): array { return []; }
    public function getFieldLabels(): array { return ['first_name' => 'First']; }
    public function getSynonyms(): array { return ['first_name' => ['firstname']]; }
    public function processRow(array $row, array $mapping, array $context): string
    {
        $this->rows++;
        $this->lastContext = $context;
        if (($row['first_name'] ?? '') === 'BOOM') {
            throw new \RuntimeException('boom');
        }
        return 'created';
    }
}

/**
 * Multi-council coach import (GOTR G6): one CSV, a `council_code` column, rows
 * resolved to the club under the caller's org unit whose org_units.external_code
 * matches. Unknown, foreign and ambiguous codes are REJECTED with a reason and
 * never fall back to the caller's own club — the anchor club on the job is a
 * schema requirement, not a destination.
 *
 * The processor is streamed and its progress writes are batched: a 50,000-row
 * file must stay under 128 MB. The old loop materialised every row as an
 * associative array and issued one UPDATE per row.
 */
class NationalCoachImportTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
        $this->pdo->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT UNIQUE, first_name TEXT, last_name TEXT,
                password_hash TEXT, role TEXT, auth_provider TEXT, phone TEXT, last_login_at TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE user_club_access (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, club_profile_id INTEGER, role TEXT,
                granted_at TEXT DEFAULT CURRENT_TIMESTAMP, granted_by INTEGER, revoked_at TEXT, revoked_by INTEGER,
                active BOOLEAN DEFAULT 1, UNIQUE (user_id, club_profile_id, role)
            );
            CREATE TABLE magic_link_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT, token TEXT, expires_at TEXT, used_at TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP, invitation_id INTEGER, return_to TEXT
            );
            CREATE TABLE audit_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT, resource_type TEXT,
                resource_id INTEGER, ip_address TEXT, user_agent TEXT, details TEXT, created_at TEXT
            );
            CREATE TABLE club_profile (id INTEGER PRIMARY KEY, name TEXT, org_unit_id INTEGER);
            CREATE TABLE org_units (
                id INTEGER PRIMARY KEY AUTOINCREMENT, parent_id INTEGER, type TEXT NOT NULL, name TEXT NOT NULL,
                external_code TEXT, path TEXT NOT NULL, depth INTEGER NOT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE user_org_access (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, org_unit_id INTEGER NOT NULL,
                role TEXT NOT NULL, granted_at TEXT, granted_by INTEGER, revoked_at TEXT, revoked_by INTEGER,
                active BOOLEAN DEFAULT 1
            );
            CREATE TABLE import_jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, club_profile_id INTEGER, team_id INTEGER,
                entity_type TEXT, status TEXT, original_filename TEXT, total_rows INTEGER, processed_rows INTEGER DEFAULT 0,
                created_count INTEGER DEFAULT 0, updated_count INTEGER DEFAULT 0, skipped_count INTEGER DEFAULT 0,
                error_count INTEGER DEFAULT 0, created_at TEXT DEFAULT CURRENT_TIMESTAMP, started_at TEXT, finished_at TEXT,
                csv_content TEXT, column_mapping TEXT, org_unit_id INTEGER
            );
            CREATE TABLE import_job_errors (
                id INTEGER PRIMARY KEY AUTOINCREMENT, job_id INTEGER, row_number INTEGER, row_json TEXT,
                error_message TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            INSERT INTO org_units (id, parent_id, type, name, external_code, path, depth) VALUES
                (1, NULL, 'national', 'Girls on the Run', 'GOTR', '/1/', 0),
                (2, 1, 'division', 'West', 'WEST', '/1/2/', 1),
                (3, 2, 'council', 'Kansas', 'KS', '/1/2/3/', 2),
                (4, 2, 'council', 'California', 'CA', '/1/2/4/', 2),
                (5, 1, 'division', 'East', 'EAST', '/1/5/', 1),
                (6, 5, 'council', 'Boston', 'BOS', '/1/5/6/', 2),
                (7, 2, 'council', 'Nevada', 'NV', '/1/2/7/', 2);
            INSERT INTO club_profile (id, name, org_unit_id) VALUES
                (100, 'GOTR Kansas', 3),
                (101, 'GOTR California', 4),
                (102, 'GOTR Boston', 6),
                (103, 'Central Kansas United', NULL),
                (104, 'GOTR Nevada North', 7),
                (105, 'GOTR Nevada South', 7);
        ");
    }

    private function context(int $orgUnitId, ?callable $enqueue = null): array
    {
        return [
            'pdo' => $this->pdo, 'club_id' => 100, 'team_id' => null, 'user_id' => 1,
            'org_unit_id' => $orgUnitId, 'enqueue_invite' => $enqueue,
        ];
    }

    private function row(string $email, string $code): array
    {
        return ['first_name' => 'Pat', 'last_name' => 'Coach', 'email' => $email, 'phone' => '', 'council_code' => $code];
    }

    private function mapping(): array
    {
        return ['first_name' => 'first_name', 'last_name' => 'last_name', 'email' => 'email',
                'phone' => 'phone', 'council_code' => 'council_code'];
    }

    // -------------------------------------------------------- council codes

    public function testACouncilCodeResolvesToTheClubUnderTheCallersUnit(): void
    {
        $queued = [];
        $s = new NationalCoachImportStrategy();
        $out = $s->processRow($this->row('ca@gotr.org', 'ca'), $this->mapping(),
            $this->context(2, function (array $job) use (&$queued) { $queued[] = $job; }));

        $this->assertSame('created', $out);
        $access = $this->pdo->query("SELECT club_profile_id, role FROM user_club_access")->fetchAll();
        $this->assertSame([['club_profile_id' => 101, 'role' => 'coach']], $access,
            'the row lands on California (101), not the anchor club (100); codes are case-insensitive');
        $this->assertNull($this->pdo->query("SELECT password_hash FROM users WHERE email = 'ca@gotr.org'")->fetchColumn());
        $this->assertCount(1, $queued, 'the invite is queued, not sent inline');
        $this->assertSame('coach_invite', $queued[0]['type']);
        $this->assertSame(101, $queued[0]['club_id']);
        $this->assertArrayNotHasKey('token', $queued[0], 'the token never rides in a Redis payload');
    }

    public function testAnUnknownCodeRejectsTheRowAndTouchesNothing(): void
    {
        $s = new NationalCoachImportStrategy();
        try {
            $s->processRow($this->row('x@gotr.org', 'ZZ'), $this->mapping(), $this->context(2));
            $this->fail('expected a rejection');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("council code 'ZZ'", $e->getMessage());
        }
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM users')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM user_club_access')->fetchColumn());
    }

    public function testACodeOutsideTheCallersUnitIsForeignNotAFallback(): void
    {
        $s = new NationalCoachImportStrategy();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/council code 'BOS'/");
        // Boston is real, but it is under East and the caller administers West.
        $s->processRow($this->row('bos@gotr.org', 'BOS'), $this->mapping(), $this->context(2));
    }

    public function testACodeWithTwoClubsIsAmbiguousAndRejected(): void
    {
        $s = new NationalCoachImportStrategy();
        try {
            $s->processRow($this->row('nv@gotr.org', 'NV'), $this->mapping(), $this->context(2));
            $this->fail('expected a rejection');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('2 clubs', $e->getMessage());
        }
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM user_club_access')->fetchColumn());
    }

    public function testAMissingCodeIsARejectionNotTheAnchorClub(): void
    {
        $s = new NationalCoachImportStrategy();
        $this->expectException(\RuntimeException::class);
        $s->processRow($this->row('x@gotr.org', ''), $this->mapping(), $this->context(2));
    }

    public function testWithoutAnOrgUnitTheStrategyRefusesEveryRow(): void
    {
        $s = new NationalCoachImportStrategy();
        $this->expectException(\RuntimeException::class);
        $s->processRow($this->row('ks@gotr.org', 'KS'), $this->mapping(), $this->context(0));
    }

    public function testCouncilCodeIsRequiredAndAutoDetected(): void
    {
        $s = new NationalCoachImportStrategy();
        $this->assertContains('council_code', $s->getRequiredFields());
        $m = $s->autoDetectMapping(['First Name', 'Last Name', 'Email', 'Council Code']);
        $this->assertSame('Council Code', $m['council_code']);
        $m = $s->autoDetectMapping(['first', 'last', 'email', 'council']);
        $this->assertSame('council', $m['council_code']);
        $this->assertSame('national_coaches', $s->getEntityType());
    }

    // ---------------------------------------------------------- the processor

    private function job(string $csv, string $entity = 'counting', ?int $orgUnitId = null, array $mapping = ['first_name' => 'first_name']): int
    {
        $s = $this->pdo->prepare("INSERT INTO import_jobs (user_id, club_profile_id, entity_type, status, original_filename,
            csv_content, column_mapping, total_rows, org_unit_id) VALUES (1, 100, ?, 'queued', 'x.csv', ?, ?, 0, ?)");
        $s->execute([$entity, $csv, json_encode($mapping), $orgUnitId]);
        return (int) $this->pdo->lastInsertId();
    }

    public function testFiftyThousandRowsStayUnder128MbAndBatchTheirProgressWrites(): void
    {
        $lines = ['first_name,last_name,email'];
        for ($i = 0; $i < 50000; $i++) {
            $lines[] = "Pat{$i},Coach{$i},coach{$i}@example.org";
        }
        $csv = implode("\n", $lines);
        unset($lines);
        $jobId = $this->job($csv);
        unset($csv);
        gc_collect_cycles();

        $strategy = new CountingImportStrategy();
        $processor = new ImportJobProcessor($this->pdo);
        $processor->register($strategy);

        $before = memory_get_usage(true);
        $processor->processJob(['job_id' => $jobId]);
        $peak = memory_get_peak_usage(true) - $before;

        $this->assertSame(50000, $strategy->rows);
        $this->assertLessThan(128 * 1024 * 1024, $peak, sprintf('peak %.1f MB over baseline', $peak / 1048576));

        $job = $this->pdo->query("SELECT * FROM import_jobs WHERE id = $jobId")->fetch();
        $this->assertSame('completed', $job['status']);
        $this->assertSame(50000, (int) $job['processed_rows']);
        $this->assertSame(50000, (int) $job['created_count']);
    }

    public function testTheProcessorNeverHoldsEveryRowAtOnce(): void
    {
        $src = file_get_contents(__DIR__ . '/../../services/ImportJobProcessor.php');
        $this->assertMatchesRegularExpression('/function\s+iterateCsv\s*\([^)]*\)\s*:\s*\\\\?(Generator|iterable)/', $src,
            'rows must come from a generator — a 50k-row file must not be an array of 50k arrays');
        $this->assertStringNotContainsString('$rows[] = array_combine', $src);
        $this->assertStringContainsString('PROGRESS_EVERY', $src, 'progress writes are batched, not one UPDATE per row');
    }

    public function testRowErrorsAreRecordedWithTheirRowNumberAndTheJobStillCompletes(): void
    {
        $jobId = $this->job("first_name\nA\nBOOM\nC\n");
        $strategy = new CountingImportStrategy();
        $processor = new ImportJobProcessor($this->pdo);
        $processor->register($strategy);
        $processor->processJob(['job_id' => $jobId]);

        $job = $this->pdo->query("SELECT * FROM import_jobs WHERE id = $jobId")->fetch();
        $this->assertSame('completed', $job['status']);
        $this->assertSame(3, (int) $job['processed_rows']);
        $this->assertSame(2, (int) $job['created_count']);
        $this->assertSame(1, (int) $job['error_count']);
        $err = $this->pdo->query("SELECT row_number, error_message FROM import_job_errors")->fetchAll();
        $this->assertSame([['row_number' => 3, 'error_message' => 'boom']], $err);
    }

    public function testTheJobsOrgUnitReachesTheStrategyContext(): void
    {
        $jobId = $this->job("first_name\nA\n", 'counting', 2);
        $strategy = new CountingImportStrategy();
        $processor = new ImportJobProcessor($this->pdo);
        $processor->register($strategy);
        $queued = [];
        $processor->setInviteEnqueuer(function (array $job) use (&$queued) { $queued[] = $job; });
        $processor->processJob(['job_id' => $jobId]);

        $this->assertSame(2, $strategy->lastContext['org_unit_id']);
        $this->assertIsCallable($strategy->lastContext['enqueue_invite']);
    }

    public function testTheDefaultProcessorKnowsTheNationalEntity(): void
    {
        $p = ImportJobProcessor::buildDefault($this->pdo);
        $this->assertInstanceOf(NationalCoachImportStrategy::class, $p->getStrategy('national_coaches'));
    }

    // ------------------------------------------------------------ the gateway

    public function testTheGatewayGatesTheNationalEntityOnOrgStandingAndTheSwitch(): void
    {
        $src = file_get_contents(__DIR__ . '/../../api/imports-gateway.php');
        $start = strpos($src, 'function handleUpload(');
        $this->assertNotFalse($start);
        $end = strpos($src, "\nfunction ", $start + 10);
        $upload = substr($src, $start, $end - $start);

        $this->assertStringContainsString("te_feature_enabled('NATIONAL_IMPORT')", $upload);
        $this->assertStringContainsString("te_user_org_standing(", $upload);
        $this->assertStringContainsString("'org_admin'", $upload, 'an org_viewer reads rollups and imports nothing');

        $status = substr($src, strpos($src, 'function handleStatus('));
        $this->assertStringContainsString('te_user_org_standing(', $status,
            'a national job is read through org standing, not the anchor club');
    }

    public function testMigration094IsAdditiveAndCarriesItsReverse(): void
    {
        $path = __DIR__ . '/../../database/migrations/094_import_jobs_org_unit.sql';
        $this->assertFileExists($path);
        $sql = file_get_contents($path);
        $this->assertStringContainsString('ADD COLUMN IF NOT EXISTS org_unit_id', $sql);
        $this->assertStringContainsString('REVERSE SQL', $sql);
        $this->assertStringNotContainsString('DROP NOT NULL', $sql, 'additive only — club_profile_id keeps its constraint');
        $live = implode("\n", array_filter(explode("\n", $sql), static fn (string $l): bool => !str_starts_with(ltrim($l), '--')));
        $this->assertDoesNotMatchRegularExpression('/\bDROP\b/i', $live, 'nothing outside the reverse-SQL comment drops anything');
    }
}
