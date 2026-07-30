<?php
/**
 * Shared write path for athlete health profiles.
 *
 * Exists because three separate callers were inserting health-profile fields
 * (blood_type, physician_name, has_asthma, …) into `medical_records`, which is a
 * documents/clearance table and has none of those columns. Every one raised
 * SQLSTATE 42703 into a swallowing catch. The profile's real home is
 * `athlete_medical` (migration 051).
 *
 * Encryption and boolean normalisation live here so a fourth caller cannot get
 * them subtly wrong.
 */

if (!function_exists('te_normalize_athlete_medical_values')) {
    /**
     * Coerce a health-profile payload into values Postgres will accept.
     *
     * One definition of "valid value", shared by both writers — the full-row
     * upsert below and the partial UPDATE in legacy/medical-gateway.php. They had
     * separate, differently-wrong ideas about it: the gateway wrote `''` straight
     * into DATE columns, so saving an athlete who had no physical date on file
     * failed with SQLSTATE 22007 and the form reported "medical information could
     * not be saved". The numeric half of the same bug had been patched in the
     * browser (AthleteForm converts height/weight), which is exactly why the date
     * half survived — a fix on the client only covers the caller that has it.
     *
     * - DATE columns: '' (or whitespace) -> null. Empty is "not recorded".
     * - NUMERIC columns: '' -> null, for the same reason (SQLSTATE 22P02).
     * - BOOLEAN columns: -> 'true'/'false' strings. PDO binds PHP false as '',
     *   which Postgres rejects for a boolean.
     *
     * Keys absent from $row stay absent, so a partial update stays partial.
     */
    function te_normalize_athlete_medical_values(array $row): array
    {
        foreach (['last_physical_date', 'physical_expiry_date', 'last_concussion_date', 'return_to_play_date',
                  'height_inches', 'weight_lbs'] as $f) {
            if (array_key_exists($f, $row) && trim((string) $row[$f]) === '') {
                $row[$f] = null;
            }
        }

        foreach (['has_asthma', 'has_epipen', 'emergency_treatment_consent'] as $f) {
            if (array_key_exists($f, $row)) {
                $v = $row[$f];
                // 'false' and '0' arrive as strings from form posts and are FALSE,
                // however truthy PHP considers a non-empty string.
                $isFalse = $v === null || $v === false || $v === 0 || $v === '0'
                    || (is_string($v) && strcasecmp(trim($v), 'false') === 0)
                    || (is_string($v) && trim($v) === '');
                $row[$f] = $isFalse ? 'false' : 'true';
            }
        }

        return $row;
    }
}

require_once __DIR__ . '/Encryption.php';

if (!function_exists('te_save_athlete_medical')) {
    /**
     * Upsert an athlete's health profile.
     *
     * Accepts the legacy field names used by AthleteController / api/athletes.php
     * as well as the canonical ones:
     *   physical_exam_date -> last_physical_date
     *   alternate spellings are mapped rather than dropped.
     *
     * Fields with no column of their own (preferred_hospital, has_diabetes,
     * has_seizures, has_heart_condition) are folded into medical_conditions rather
     * than silently discarded — losing "has seizures" would be worse than an
     * imprecise column.
     *
     * @param array $m Raw medical payload.
     * @return bool True when something was written.
     */
    function te_save_athlete_medical(PDO $pdo, int $athleteId, array $m): bool
    {
        if ($athleteId <= 0 || empty($m)) {
            return false;
        }

        // Fold condition flags that have no dedicated column into the free-text
        // conditions field so the information survives.
        $extra = [];
        foreach (['has_diabetes' => 'Diabetes', 'has_seizures' => 'Seizure disorder', 'has_heart_condition' => 'Heart condition'] as $flag => $label) {
            if (!empty($m[$flag])) {
                $extra[] = $label;
            }
        }
        if (!empty($m['preferred_hospital'])) {
            $extra[] = 'Preferred hospital: ' . $m['preferred_hospital'];
        }
        if ($extra) {
            $existing = trim((string) ($m['medical_conditions'] ?? ''));
            $m['medical_conditions'] = trim($existing . ($existing ? '; ' : '') . implode('; ', $extra));
        }

        $row = [
            'allergies'                   => $m['allergies'] ?? null,
            'allergy_severity'            => $m['allergy_severity'] ?? null,
            'medical_conditions'          => $m['medical_conditions'] ?? null,
            'medications'                 => $m['medications'] ?? null,
            'physician_name'              => $m['physician_name'] ?? null,
            'physician_phone'             => $m['physician_phone'] ?? null,
            'physician_address'           => $m['physician_address'] ?? null,
            'insurance_provider'          => $m['insurance_provider'] ?? null,
            'insurance_policy_number'     => $m['insurance_policy_number'] ?? null,
            'insurance_group_number'      => $m['insurance_group_number'] ?? null,
            'last_physical_date'          => $m['last_physical_date'] ?? $m['physical_exam_date'] ?? null,
            'physical_expiry_date'        => $m['physical_expiry_date'] ?? null,
            'height_inches'               => $m['height_inches'] ?? null,
            'weight_lbs'                  => $m['weight_lbs'] ?? null,
            'blood_type'                  => $m['blood_type'] ?? null,
            'special_instructions'        => $m['special_instructions'] ?? null,
            'concussion_history'          => $m['concussion_history'] ?? null,
            'last_concussion_date'        => $m['last_concussion_date'] ?? null,
            'return_to_play_date'         => $m['return_to_play_date'] ?? null,
            'inhaler_location'            => $m['inhaler_location'] ?? null,
            'epipen_location'             => $m['epipen_location'] ?? null,
            'has_asthma'                  => $m['has_asthma'] ?? false,
            'has_epipen'                  => $m['has_epipen'] ?? false,
            'emergency_treatment_consent' => $m['emergency_treatment_consent'] ?? true,
        ];

        // Empty dates/numerics -> null, booleans -> 'true'/'false'.
        $row = te_normalize_athlete_medical_values($row);

        $row = Encryption::encryptFields($row, Encryption::athleteMedicalFields());

        $cols = array_keys($row);
        $stmt = $pdo->prepare(
            'INSERT INTO athlete_medical (athlete_id, ' . implode(', ', $cols) . ', is_encrypted, created_at, updated_at) '
            . 'VALUES (?, ' . implode(', ', array_fill(0, count($cols), '?')) . ', TRUE, NOW(), NOW()) '
            . 'ON CONFLICT (athlete_id) DO UPDATE SET '
            . implode(', ', array_map(fn($c) => "$c = EXCLUDED.$c", $cols))
            . ', is_encrypted = TRUE, updated_at = NOW()'
        );
        $stmt->execute(array_merge([$athleteId], array_values($row)));

        return true;
    }
}
