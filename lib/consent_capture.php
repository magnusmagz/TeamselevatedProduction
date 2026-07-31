<?php
/**
 * Recording parental consent captured OUTSIDE the parent portal.
 *
 * The public registration form has always asked for both COPPA consents and sent
 * the answers (`consent_data_collection`, `consent_medical_data`).
 * `registrations-api.php` never read them, so a real parent's real agreement was
 * discarded at the moment with the most legal weight — consent at the point of
 * collection — and a family that registered and never opened the portal had no
 * consent record at all.
 *
 * It could not simply be written before migration 063, because
 * `consent_records.guardian_id` was `NOT NULL REFERENCES users (id)` and public
 * registration creates a `guardians` row but no account. See that migration for
 * why the identity is now copied in rather than joined.
 *
 * THIS DOES NOT REPLACE THE PORTAL GATE. Both surfaces capture, by design:
 * registration records what the parent agreed to at sign-up, and `ConsentGate`
 * asks them to re-affirm the first time they enter the portal. `source` is what
 * keeps the two legible, and the gate deliberately clears only on a `'portal'`
 * row — see CLAUDE.md.
 */

/**
 * Consent text version. Bump when the wording families agree to changes, so old
 * records stay attributable to the text that was actually shown.
 *
 * `api/consent.php` takes CONSENT_VERSION from here; do not declare a second one.
 */
if (!defined('TE_CONSENT_VERSION')) {
    define('TE_CONSENT_VERSION', '1.0');
}

/** Where a consent was captured. Mirrors the migration-063 CHECK constraint. */
const TE_CONSENT_SOURCES = ['registration', 'portal', 'staff'];

/**
 * Which consents did this registration payload actually give?
 *
 * The two flags ride at the TOP LEVEL of the request body, as siblings of
 * `form_data`, not inside it (see PublicRegistrationForm's `payload`). Reading
 * them from `form_data` finds nothing and silently records no consent, which is
 * the bug this whole file exists to fix — so the shape is pinned by a test.
 *
 * Only affirmative answers are returned. The form blocks submission unless both
 * are ticked, so a false here means a caller that is not the public form; a
 * "consent_given = false" row would misrepresent a field that was never really
 * offered as a choice.
 *
 * @param array $payload decoded request body
 * @return string[] consent_type values, in a stable order
 */
function te_consent_types_from_registration(array $payload): array
{
    $map = [
        'consent_data_collection' => 'data_collection',
        'consent_medical_data'    => 'medical_data',
    ];

    $types = [];
    foreach ($map as $key => $consentType) {
        if (!empty($payload[$key])) {
            $types[] = $consentType;
        }
    }

    return $types;
}

/**
 * Write consent rows for a registering family.
 *
 * `guardian_id` is left NULL: at public registration the consenting adult has no
 * user account, and migration 063 made that representable. The identity is
 * carried by guardian_email / guardian_name, frozen as recorded.
 *
 * Idempotent per (athlete, email, type): a family registering a second program
 * for the same child does not pile up duplicate rows. The EARLIEST consent is the
 * meaningful artifact, so an existing unrevoked row wins and is left untouched.
 *
 * @return int number of rows actually inserted
 */
function te_record_registration_consent(
    PDO $pdo,
    int $athleteId,
    string $guardianEmail,
    string $guardianName,
    array $consentTypes,
    ?string $ip = null,
    ?string $userAgent = null
): int {
    if ($athleteId <= 0 || $guardianEmail === '' || empty($consentTypes)) {
        return 0;
    }

    $existing = $pdo->prepare(
        "SELECT 1 FROM consent_records
         WHERE athlete_id = ? AND guardian_email = ? AND consent_type = ?
           AND consent_given = TRUE AND revoked_at IS NULL
         LIMIT 1"
    );

    // CURRENT_TIMESTAMP, not NOW(): identical on Postgres, and it also runs on
    // SQLite, so RegistrationConsentCaptureTest exercises THIS statement rather
    // than a hand-copied lookalike. A fixture that drifts from the real SQL is
    // how the {{event_*}} merge-tag bug survived green tests for months.
    $insert = $pdo->prepare(
        "INSERT INTO consent_records
            (guardian_id, athlete_id, consent_type, consent_given, consented_at,
             ip_address, user_agent, consent_version, source,
             guardian_email, guardian_name)
         VALUES (NULL, ?, ?, TRUE, CURRENT_TIMESTAMP, ?, ?, ?, 'registration', ?, ?)"
    );

    $written = 0;
    foreach ($consentTypes as $type) {
        $existing->execute([$athleteId, $guardianEmail, $type]);
        if ($existing->fetch()) {
            continue;
        }
        $insert->execute([
            $athleteId, $type, $ip, $userAgent,
            TE_CONSENT_VERSION, $guardianEmail, $guardianName,
        ]);
        $written++;
    }

    return $written;
}

/**
 * Record registration consent without letting a failure sink the registration.
 *
 * Runs inside a SAVEPOINT on purpose. `main` is shared and deploys are by push,
 * so this code can reach production before migration 063 is applied to Neon — and
 * on Postgres a failed statement poisons the whole enclosing transaction, meaning
 * a missing column would roll back the FAMILY'S REGISTRATION, not just the consent
 * row. The savepoint contains that: the registration commits either way, the
 * failure is logged loudly rather than swallowed silently, and `ConsentGate`
 * remains the backstop that catches a family whose consent did not record.
 *
 * Caller must already be inside a transaction.
 *
 * @return int rows inserted; 0 on failure (check the log, not the return value)
 */
function te_record_registration_consent_safely(
    PDO $pdo,
    int $athleteId,
    string $guardianEmail,
    string $guardianName,
    array $consentTypes,
    ?string $ip = null,
    ?string $userAgent = null
): int {
    if (empty($consentTypes)) {
        return 0;
    }

    try {
        $pdo->exec('SAVEPOINT te_consent');
        $written = te_record_registration_consent(
            $pdo, $athleteId, $guardianEmail, $guardianName, $consentTypes, $ip, $userAgent
        );
        $pdo->exec('RELEASE SAVEPOINT te_consent');
        return $written;
    } catch (Throwable $e) {
        try {
            $pdo->exec('ROLLBACK TO SAVEPOINT te_consent');
            $pdo->exec('RELEASE SAVEPOINT te_consent');
        } catch (Throwable $inner) {
            error_log('consent capture: savepoint rollback failed: ' . $inner->getMessage());
        }
        error_log(
            'consent capture FAILED for athlete ' . $athleteId . ' (' . $guardianEmail . '): '
            . $e->getMessage()
            . ' — registration was kept; the parent will be asked again in the portal.'
        );
        return 0;
    }
}
