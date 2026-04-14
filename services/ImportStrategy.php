<?php
/**
 * ImportStrategy — abstract base class for per-entity import implementations.
 *
 * Each entity type (athletes, facilities, volunteers, coaches, users, teams)
 * supplies its own concrete subclass defining required/optional fields,
 * human-readable labels, synonym hints for auto-detection, and the per-row
 * upsert logic.
 *
 * The outer loop (load job, parse CSV, iterate rows, update progress, write
 * errors) lives in ImportJobProcessor and is shared across all strategies.
 */

abstract class ImportStrategy {
    /**
     * Machine-readable entity type. Must match the import_jobs.entity_type
     * CHECK constraint (see migration 017 / 018).
     */
    abstract public function getEntityType(): string;

    /** Destination field keys that must be mapped before an import can start. */
    abstract public function getRequiredFields(): array;

    /** Destination field keys that may optionally be mapped. */
    abstract public function getOptionalFields(): array;

    /** Destination field key => human-readable label for the mapping UI. */
    abstract public function getFieldLabels(): array;

    /**
     * Destination field key => array of normalized header patterns to match
     * against in auto-detect. Normalized means lowercase, alphanumerics only.
     * Example: 'athlete_first_name' => ['firstname', 'givenname', 'athletefirstname']
     */
    abstract public function getSynonyms(): array;

    /**
     * Process one row of parsed CSV data. Should handle its own transaction
     * boundary (the processor does NOT wrap rows in transactions — see
     * note in ImportJobProcessor::processJob).
     *
     * @param array $row     CSV row keyed by source header
     * @param array $mapping Destination field key => source header
     * @param array $context ['pdo' => PDO, 'club_id' => int, 'team_id' => ?int, 'user_id' => int]
     * @return string 'created' | 'updated' | 'skipped'
     * @throws RuntimeException on row validation failure — caught by the processor
     *                          and recorded to import_job_errors without aborting
     *                          subsequent rows.
     */
    abstract public function processRow(array $row, array $mapping, array $context): string;

    // ─────────────────────────────────────────────────────────────
    // Shared helpers — usable from any subclass or the gateway.

    /**
     * Look up a destination field's value in a row via the column mapping.
     * Falls back to using the destination name as the source column if no
     * mapping entry exists (handy for tests that use identity mappings).
     */
    protected function field(array $row, array $mapping, string $destField): string {
        $sourceCol = $mapping[$destField] ?? $destField;
        return trim((string) ($row[$sourceCol] ?? ''));
    }

    /**
     * Auto-detect a column mapping from a list of CSV headers using this
     * strategy's synonym dictionary.
     */
    public function autoDetectMapping(array $headers): array {
        $synonyms = $this->getSynonyms();
        $normalizedHeaders = [];
        foreach ($headers as $h) {
            $normalizedHeaders[self::normalizeHeader($h)] = $h;
        }
        $mapping = [];
        foreach ($synonyms as $dest => $candidates) {
            foreach ($candidates as $cand) {
                if (isset($normalizedHeaders[$cand])) {
                    $mapping[$dest] = $normalizedHeaders[$cand];
                    break;
                }
            }
        }
        return $mapping;
    }

    /**
     * Validate a column mapping against the CSV's actual headers. Returns
     * an array of error messages (empty if valid).
     */
    public function validateMapping(array $mapping, array $headers): array {
        $errors = [];
        foreach ($this->getRequiredFields() as $dest) {
            if (!isset($mapping[$dest]) || $mapping[$dest] === '') {
                $errors[] = "Required field '{$dest}' is not mapped";
                continue;
            }
            if (!in_array($mapping[$dest], $headers, true)) {
                $errors[] = "Mapped column '{$mapping[$dest]}' for '{$dest}' is not in the CSV headers";
            }
        }
        return $errors;
    }

    public static function normalizeHeader(string $s): string {
        return preg_replace('/[^a-z0-9]/', '', strtolower($s));
    }

    protected function intOrNull($v): ?int {
        $s = trim((string) $v);
        return $s === '' ? null : (int) $s;
    }

    protected function strOrNull($v): ?string {
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    protected function parseBool($v): bool {
        if (is_bool($v)) return $v;
        $s = strtolower(trim((string) $v));
        return in_array($s, ['t', 'true', '1', 'yes', 'y'], true);
    }
}
