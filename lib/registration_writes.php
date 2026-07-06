<?php
/**
 * Registration write helpers.
 *
 * te_create_coach_registration is the coach/adult path for program registration,
 * kept separate from the (untouched) athlete flow in registrations-api.php so the
 * athlete path carries zero regression risk and the coach path is unit-testable.
 * Callers own the transaction (this function participates, never commits/rolls back).
 */

if (!function_exists('te_create_coach_registration')) {
    /**
     * Register a coach / adult for a program.
     *
     * Unlike the athlete flow: NO guardian, NO athlete record, and NO login `users`
     * row is created. Identity + dedup key is the email (guardian-style). Dedup is
     * per-program on lower(email).
     *
     * @param PDO   $pdo      Connection (caller owns the transaction).
     * @param array $program  The program row (needs 'id').
     * @param array $formData Submitted form fields (coach_first/last/email/phone…).
     * @return array{already_registered:bool, registration_id:int}
     * @throws InvalidArgumentException when required coach fields are missing.
     */
    function te_create_coach_registration(PDO $pdo, array $program, array $formData): array
    {
        $programId = (int) $program['id'];
        $first = trim((string) ($formData['coach_first'] ?? ''));
        $last  = trim((string) ($formData['coach_last'] ?? ''));
        $email = trim((string) ($formData['coach_email'] ?? ''));

        if ($first === '' || $last === '' || $email === '') {
            throw new InvalidArgumentException('Coach first name, last name, and email are required');
        }

        // Per-program dedup by email (case-insensitive), ignoring rejected regs.
        $stmt = $pdo->prepare(
            "SELECT id FROM registrations
             WHERE program_id = ? AND lower(registrant_email) = lower(?) AND status <> 'rejected'
             LIMIT 1"
        );
        $stmt->execute([$programId, $email]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            return ['already_registered' => true, 'registration_id' => (int) $existing['id']];
        }

        // No athlete, no guardian, no user — just the registration + registrant identity.
        $stmt = $pdo->prepare(
            "INSERT INTO registrations (
                program_id, athlete_id, guardian_id,
                registrant_first_name, registrant_last_name, registrant_email,
                form_data, status, submitted_at
            ) VALUES (?, NULL, NULL, ?, ?, ?, ?, 'pending', NOW())
            RETURNING id"
        );
        $stmt->execute([$programId, $first, $last, $email, json_encode($formData)]);
        $registrationId = (int) $stmt->fetch(PDO::FETCH_ASSOC)['id'];

        return ['already_registered' => false, 'registration_id' => $registrationId];
    }
}
