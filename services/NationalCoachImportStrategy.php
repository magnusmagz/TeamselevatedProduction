<?php
require_once __DIR__ . '/CoachImportStrategy.php';

/**
 * NationalCoachImportStrategy — one CSV, many councils (GOTR G6).
 *
 * GOTR's export is a coach roster with a council code per row. A national or
 * division admin uploads it once; each row is attached to the club under THEIR
 * org unit whose `org_units.external_code` matches the row's `council_code`.
 *
 * Three refusals, and none of them falls back to the job's anchor club:
 *   - a code that matches nothing under the caller's unit (unknown, or a real
 *     council in a sibling division — which is the same answer from here);
 *   - a code whose org unit has more than one club attached (ambiguous);
 *   - a blank code.
 * A row that cannot say where it belongs is an error row with a reason, never
 * a coach silently created somewhere plausible.
 *
 * Codes compare case-insensitively (`ks` == `KS`) and are resolved once per
 * code per job — a 30,000-row file over 270 councils is 270 lookups.
 *
 * Authorization happens in api/imports-gateway.php (org_admin of the unit);
 * this class trusts `$context['org_unit_id']` because the job row that carries
 * it was written by that gate.
 */
class NationalCoachImportStrategy extends CoachImportStrategy {
    /** @var array<string, int|string> code → club id, or the refusal message */
    private array $resolved = [];

    public function getEntityType(): string {
        return 'national_coaches';
    }

    public function getRequiredFields(): array {
        return array_merge(parent::getRequiredFields(), ['council_code']);
    }

    public function getFieldLabels(): array {
        return parent::getFieldLabels() + ['council_code' => 'Council Code'];
    }

    public function getSynonyms(): array {
        return parent::getSynonyms() + [
            'council_code' => ['councilcode', 'council', 'code', 'councilid', 'site', 'sitecode', 'chapter', 'chaptercode'],
        ];
    }

    public function processRow(array $row, array $mapping, array $context): string {
        /** @var PDO $pdo */
        $pdo = $context['pdo'];
        $orgUnitId = (int) ($context['org_unit_id'] ?? 0);
        if ($orgUnitId <= 0) {
            throw new RuntimeException('A multi-council import must be scoped to an organization — this job has no org unit');
        }

        $code = trim($this->field($row, $mapping, 'council_code'));
        if ($code === '') {
            throw new RuntimeException('Missing council_code — the row does not say which council this coach belongs to');
        }

        $clubId = $this->resolveCouncil($pdo, $orgUnitId, $code);

        return parent::processRow($row, $mapping, array_merge($context, ['club_id' => $clubId]));
    }

    /**
     * The club under $orgUnitId whose org unit carries $code. Cached per job.
     *
     * @throws RuntimeException unknown / foreign / ambiguous
     */
    private function resolveCouncil(PDO $pdo, int $orgUnitId, string $code): int {
        $key = $orgUnitId . ':' . strtoupper($code);
        if (!array_key_exists($key, $this->resolved)) {
            $stmt = $pdo->prepare(
                'SELECT c.id
                   FROM club_profile c
                   JOIN org_units o ON o.id = c.org_unit_id
                  WHERE UPPER(o.external_code) = UPPER(?)
                    AND o.path LIKE (SELECT a.path FROM org_units a WHERE a.id = ?) || \'%\'
                  ORDER BY c.id'
            );
            $stmt->execute([$code, $orgUnitId]);
            $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

            if (count($ids) === 1) {
                $this->resolved[$key] = $ids[0];
            } elseif (count($ids) === 0) {
                $this->resolved[$key] = "Unknown council code '{$code}' under this organization — row rejected";
            } else {
                $this->resolved[$key] = "council code '{$code}' matches " . count($ids) . ' clubs — row rejected as ambiguous';
            }
        }

        $hit = $this->resolved[$key];
        if (is_string($hit)) {
            throw new RuntimeException($hit);
        }
        return $hit;
    }
}
