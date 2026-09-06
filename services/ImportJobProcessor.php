<?php
require_once __DIR__ . '/ImportStrategy.php';
require_once __DIR__ . '/AthleteImportStrategy.php';
require_once __DIR__ . '/FacilityImportStrategy.php';
require_once __DIR__ . '/VolunteerImportStrategy.php';
require_once __DIR__ . '/CoachImportStrategy.php';
require_once __DIR__ . '/NationalCoachImportStrategy.php';
require_once __DIR__ . '/TeamImportStrategy.php';

/**
 * ImportJobProcessor — the entity-agnostic outer loop for bulk imports.
 *
 * Loads an import_jobs row, STREAMS its csv_content one row at a time,
 * delegates per-row upsert work to the registered ImportStrategy for the job's
 * entity_type, and updates progress/error counters in the import_jobs table.
 *
 * Streamed, not materialised (GOTR G6): the previous loop parsed the whole file
 * into an array of associative arrays and issued one progress UPDATE per row.
 * A 50,000-row coach roster is ~50k arrays of ~1 KB and 50k round trips to
 * Neon; the memory alone crosses a 512 MB dyno. Rows now come from a generator
 * over a temp stream and progress is written every PROGRESS_EVERY rows (and
 * once at the end), so the file's cost is its byte size, twice.
 *
 * Used by:
 *   - workers/queue-worker.php (Redis queue dispatch)
 *   - api/imports-gateway.php (to resolve strategies for preview/upload)
 */

class ImportJobProcessor {
    /** Rows between progress writes. 50k rows → 500 UPDATEs instead of 50,000. */
    public const PROGRESS_EVERY = 100;

    private $pdo;
    /** @var array<string, ImportStrategy> */
    private $strategies = [];
    /** @var callable|null fn(array $jobPayload): void — pushes a coach-invite job */
    private $inviteEnqueuer = null;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function register(ImportStrategy $strategy): void {
        $this->strategies[$strategy->getEntityType()] = $strategy;
    }

    public function getStrategy(string $entityType): ?ImportStrategy {
        return $this->strategies[$entityType] ?? null;
    }

    /** @return string[] */
    public function getEntityTypes(): array {
        return array_keys($this->strategies);
    }

    /**
     * How a row hands an invite to the queue. The worker wires this to
     * RedisQueue so the import loop never sends mail itself; the gateway (which
     * only previews) and the tests leave it unset.
     */
    public function setInviteEnqueuer(?callable $fn): self {
        $this->inviteEnqueuer = $fn;
        return $this;
    }

    /**
     * Build a processor with all currently-supported strategies registered.
     * Adding a new entity type = one line here.
     */
    public static function buildDefault(PDO $pdo): self {
        $processor = new self($pdo);
        $processor->register(new AthleteImportStrategy());
        $processor->register(new FacilityImportStrategy());
        $processor->register(new VolunteerImportStrategy());
        $processor->register(new CoachImportStrategy());
        $processor->register(new NationalCoachImportStrategy());
        $processor->register(new TeamImportStrategy());
        return $processor;
    }

    /**
     * Process a Redis job payload. Payload must contain { job_id }.
     */
    public function processJob(array $payload): void {
        $jobId = (int) ($payload['job_id'] ?? 0);
        if ($jobId <= 0) {
            throw new RuntimeException('import job missing job_id');
        }

        $job = $this->loadJob($jobId);
        if (!$job) {
            throw new RuntimeException("import_jobs row {$jobId} not found");
        }

        $entityType = $job['entity_type'];
        $strategy = $this->getStrategy($entityType);
        if (!$strategy) {
            $this->recordError($jobId, 0, null, "No import strategy registered for entity_type '{$entityType}'");
            $this->markFinished($jobId, 'failed');
            throw new RuntimeException("No strategy for entity_type '{$entityType}'");
        }

        $this->markStarted($jobId);

        try {
            $mapping = is_string($job['column_mapping'] ?? null)
                ? json_decode($job['column_mapping'], true)
                : ($job['column_mapping'] ?? []);
            if (!is_array($mapping)) $mapping = [];

            $context = [
                'pdo'            => $this->pdo,
                'club_id'        => (int) $job['club_profile_id'],
                'team_id'        => $job['team_id'] !== null ? (int) $job['team_id'] : null,
                'user_id'        => (int) $job['user_id'],
                // Migration 094. SELECT * tolerates the column being absent.
                'org_unit_id'    => isset($job['org_unit_id']) && $job['org_unit_id'] !== null ? (int) $job['org_unit_id'] : 0,
                'enqueue_invite' => $this->inviteEnqueuer,
            ];

            $created = 0; $updated = 0; $skipped = 0; $errors = 0; $processed = 0;

            foreach ($this->iterateCsv((string) ($job['csv_content'] ?? '')) as $row) {
                $processed++;
                $rowNumber = $processed + 1; // +1 for the header row
                try {
                    $result = $strategy->processRow($row, $mapping, $context);
                    if ($result === 'created') $created++;
                    elseif ($result === 'updated') $updated++;
                    elseif ($result === 'skipped') $skipped++;
                } catch (Exception $e) {
                    $errors++;
                    $this->recordError($jobId, $rowNumber, $row, $e->getMessage());
                }
                if ($processed % self::PROGRESS_EVERY === 0) {
                    $this->updateProgress($jobId, $processed, $created, $updated, $skipped, $errors);
                }
            }

            $this->updateProgress($jobId, $processed, $created, $updated, $skipped, $errors);
            $this->markFinished($jobId, 'completed');
        } catch (Exception $e) {
            $this->recordError($jobId, 0, null, 'Fatal: ' . $e->getMessage());
            $this->markFinished($jobId, 'failed');
            throw $e;
        }
    }

    // ─────────────────────────────────────────────────────────────

    private function loadJob(int $jobId): ?array {
        $stmt = $this->pdo->prepare('SELECT * FROM import_jobs WHERE id = :id');
        $stmt->execute(['id' => $jobId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function markStarted(int $jobId): void {
        $this->pdo->prepare("UPDATE import_jobs SET status = 'processing', started_at = NOW() WHERE id = :id")
            ->execute(['id' => $jobId]);
    }

    private function markFinished(int $jobId, string $status): void {
        $this->pdo->prepare("UPDATE import_jobs SET status = :status, finished_at = NOW() WHERE id = :id")
            ->execute(['id' => $jobId, 'status' => $status]);
    }

    private function updateProgress(int $jobId, int $processed, int $created, int $updated, int $skipped, int $errors): void {
        $this->pdo->prepare('
            UPDATE import_jobs
            SET processed_rows = :p, created_count = :c, updated_count = :u, skipped_count = :s, error_count = :e
            WHERE id = :id
        ')->execute([
            'id' => $jobId, 'p' => $processed, 'c' => $created, 'u' => $updated, 's' => $skipped, 'e' => $errors,
        ]);
    }

    private function recordError(int $jobId, int $rowNumber, ?array $rowJson, string $message): void {
        $this->pdo->prepare('
            INSERT INTO import_job_errors (job_id, row_number, row_json, error_message)
            VALUES (:job, :row, :json, :msg)
        ')->execute([
            'job'  => $jobId,
            'row'  => $rowNumber,
            'json' => $rowJson !== null ? json_encode($rowJson) : null,
            'msg'  => $message,
        ]);
    }

    /**
     * Yield one associative row at a time from CSV text.
     *
     * A temp stream (memory up to 2 MB, then disk) plus fgetcsv, so the peak
     * cost is one copy of the file and one row. Blank lines are skipped; short
     * rows are padded to the header width; values are trimmed — the same
     * normalisation the array version did.
     *
     * @return \Generator<int, array<string, string>>
     */
    private function iterateCsv(string $content): \Generator {
        $content = trim($content);
        if ($content === '') {
            return;
        }
        // Classic-Mac CR-only files: fgetcsv does not split on a bare \r.
        if (strpos($content, "\n") === false && strpos($content, "\r") !== false) {
            $content = str_replace("\r", "\n", $content);
        }

        $stream = fopen('php://temp/maxmemory:2097152', 'r+');
        try {
            fwrite($stream, $content);
            unset($content);
            rewind($stream);

            $headers = fgetcsv($stream, 0, ',', '"', '\\');
            if (!is_array($headers)) {
                return;
            }
            $headers = array_map('trim', $headers);
            $width = count($headers);

            while (($values = fgetcsv($stream, 0, ',', '"', '\\')) !== false) {
                if ($values === [null] || $values === ['']) {
                    continue; // blank line
                }
                $values = array_pad($values, $width, '');
                if (count($values) > $width) {
                    $values = array_slice($values, 0, $width);
                }
                yield array_combine($headers, array_map(static fn ($v): string => trim((string) $v), $values));
            }
        } finally {
            fclose($stream);
        }
    }
}
