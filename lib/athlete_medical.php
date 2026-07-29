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
            // 'true'/'false' strings: PDO binds PHP false as '', which Postgres
            // rejects for a boolean column.
            'has_asthma'                  => !empty($m['has_asthma']) ? 'true' : 'false',
            'has_epipen'                  => !empty($m['has_epipen']) ? 'true' : 'false',
            'emergency_treatment_consent' => array_key_exists('emergency_treatment_consent', $m)
                ? (!empty($m['emergency_treatment_consent']) ? 'true' : 'false')
                : 'true',
        ];

        // Drop empty dates — '' is not a valid DATE and would abort the write.
        foreach (['last_physical_date', 'physical_expiry_date', 'last_concussion_date', 'return_to_play_date'] as $d) {
            if ($row[$d] === '') {
                $row[$d] = null;
            }
        }

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
