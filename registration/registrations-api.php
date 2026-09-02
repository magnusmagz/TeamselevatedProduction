<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();


// Database connection
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/registration_writes.php';
require_once __DIR__ . '/../lib/jersey_size.php';
require_once __DIR__ . '/../lib/consent_capture.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/AthleteScope.php';
require_once __DIR__ . '/../lib/club_standing.php';
require_once __DIR__ . '/../lib/feature_flags.php';
require_once __DIR__ . '/../lib/email_invoice_and_registration.php';
try {
    $db = Database::getInstance();
    $connection = $db->getConnection();
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

/**
 * The club that owns a registration, reached through its program.
 *
 * NULL means there is no such registration, which is a 404. A registration whose
 * program carries no club comes back as 0 instead — a real row that no club_admin
 * or coach role can match, so it fails the staff check closed for everyone except
 * a super admin. The two answers must stay distinguishable.
 */
function te_registration_club_id(PDO $pdo, int $registrationId): ?int
{
    $stmt = $pdo->prepare(
        'SELECT p.club_id
           FROM registrations r
           JOIN programs p ON p.id = r.program_id
          WHERE r.id = ?'
    );
    $stmt->execute([$registrationId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    return (int)$row['club_id'];
}

/**
 * The statuses a reviewer may set.
 *
 * registrations.status has no CHECK constraint in Neon, so this whitelist is the
 * only thing between the review screen and an arbitrary string. Live values:
 * every insert starts at 'pending' (here and in lib/registration_writes.php),
 * and RegistrationsModal sends 'approved' / 'rejected'. tryout_status is a
 * separate column with its own vocabulary and is not settable here.
 */
const TE_REGISTRATION_REVIEW_STATUSES = ['pending', 'approved', 'rejected'];

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            // A registration row carries the family's whole form submission — the
            // athlete's name and birthday, the guardian's email and mobile. Until
            // 2026-09-02 this branch required no token at all, so `?program_id=N`
            // handed every family in a program to anyone who guessed the id.
            $auth = AuthMiddleware::requireAuth();

            // Get registrations — filter by program_id or athlete_id
            $program_id = $_GET['program_id'] ?? null;
            $athlete_id = $_GET['athlete_id'] ?? null;

            if ($athlete_id) {
                // The parent portal reads its own child here (AthleteDetailPage),
                // so this is the READ predicate and its guardian branch is the
                // point — te_is_club_staff would lock every family out of their
                // own record.
                if (!AthleteScope::userCanAccessAthlete($connection, $auth, (int)$athlete_id)) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Not authorized for this athlete']);
                    exit();
                }

                // Get all registrations for a specific athlete
                $stmt = $connection->prepare("
                    SELECT r.id, r.program_id, r.athlete_id, r.status, r.submitted_at, r.reviewed_at,
                           p.name as program_name, p.season_type as season, p.start_date, p.end_date,
                           i.id as invoice_id, i.invoice_number, i.total_amount, i.amount_paid, i.status as invoice_status
                    FROM registrations r
                    LEFT JOIN programs p ON r.program_id = p.id
                    LEFT JOIN athlete_payments ap ON ap.athlete_id = r.athlete_id AND ap.program_id = r.program_id
                    LEFT JOIN invoices i ON i.athlete_payment_id = ap.id
                    WHERE r.athlete_id = ?
                    ORDER BY r.submitted_at DESC
                ");
                $stmt->execute([$athlete_id]);
            } else {
                // A whole program's registrations is club-wide family data, so
                // this branch takes the staff predicate, not club membership.
                $stmt = $connection->prepare('SELECT club_id FROM programs WHERE id = ?');
                $stmt->execute([(int)($program_id ?? 0)]);
                $programClubId = $stmt->fetchColumn();

                if ($programClubId === false) {
                    http_response_code(404);
                    echo json_encode(['error' => 'Program not found']);
                    exit();
                }

                if (!te_is_club_staff($auth, (int)$programClubId)) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Not authorized for this program']);
                    exit();
                }

                // Get registrations for a program (existing behavior)
                $stmt = $connection->prepare("
                    SELECT r.*, p.name as program_name,
                           i.id as invoice_id, i.invoice_number, i.total_amount, i.status as invoice_status
                    FROM registrations r
                    LEFT JOIN programs p ON r.program_id = p.id
                    LEFT JOIN athlete_payments ap ON ap.athlete_id = r.athlete_id AND ap.program_id = r.program_id
                    LEFT JOIN invoices i ON i.athlete_payment_id = ap.id
                    WHERE r.program_id = ?
                    ORDER BY r.submitted_at DESC
                ");
                $stmt->execute([$program_id ?? 0]);
            }

            $registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Decode JSON form data if present
            foreach ($registrations as &$registration) {
                if (isset($registration['form_data'])) {
                    $registration['form_data'] = json_decode($registration['form_data'], true);
                }
            }

            echo json_encode($registrations);
            break;

        case 'POST':
            // Submit new registration
            $data = json_decode(file_get_contents("php://input"), true);

            // Validate program exists and is open for registration
            $stmt = $connection->prepare("
                SELECT id, status, registration_closes, registration_fee, club_id, participant_type
                FROM programs
                WHERE id = ? AND status = 'published'
            ");
            $stmt->execute([$data['program_id']]);
            $program = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$program) {
                http_response_code(400);
                echo json_encode(['error' => 'Program not available for registration']);
                exit();
            }

            // Check if registration is still open
            if ($program['registration_closes']) {
                $closes = new DateTime($program['registration_closes']);
                $now = new DateTime();
                if ($now > $closes) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Registration period has ended']);
                    exit();
                }
            }

            // Coach / adult programs use a separate, athlete-free flow: no guardian,
            // no athlete record, no login user — just a registration keyed by the
            // registrant's email. The athlete flow below is intentionally untouched.
            $participantType = $program['participant_type'] ?? 'athlete';
            if ($participantType === 'coach' || $participantType === 'adult') {
                $connection->beginTransaction();
                try {
                    $res = te_create_coach_registration($connection, $program, $data['form_data'] ?? []);
                    if ($res['already_registered']) {
                        $connection->rollBack();
                        echo json_encode([
                            'success' => true,
                            'id' => $res['registration_id'],
                            'already_registered' => true,
                            'message' => "You're already registered for this program"
                        ]);
                        exit();
                    }
                    $connection->commit();
                    echo json_encode([
                        'success' => true,
                        'id' => $res['registration_id'],
                        'message' => 'Registration submitted successfully'
                    ]);
                    exit();
                } catch (Exception $e) {
                    $connection->rollBack();
                    throw $e;
                }
            }

            $connection->beginTransaction();

            try {
                $formData = $data['form_data'];

                // Club that owns this program — used to scope returning-athlete dedup
                $programClubId = $program['club_id'];

                // Extract guardian information
                $guardianEmail = $formData['guardian_email'] ?? null;
                $guardianFirst = $formData['guardian_first'] ?? null;
                $guardianLast = $formData['guardian_last'] ?? null;
                $mobilePhone = $formData['mobile_phone'] ?? null;

                if (!$guardianEmail || !$guardianFirst || !$guardianLast || !$mobilePhone) {
                    throw new Exception('Guardian information is required');
                }

                // Check if guardian exists by composite match (email + first + last).
                // guardians.email is intentionally non-unique to support shared
                // household emails, so match on the full name too — mirrors
                // AthleteImportStrategy / AthleteController::createOrFindGuardian.
                $stmt = $connection->prepare("
                    SELECT id FROM guardians
                    WHERE email = ? AND first_name = ? AND last_name = ?
                    LIMIT 1
                ");
                $stmt->execute([$guardianEmail, $guardianFirst, $guardianLast]);
                $existingGuardian = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($existingGuardian) {
                    $guardian_id = $existingGuardian['id'];
                } else {
                    // Create new guardian
                    $stmt = $connection->prepare("
                        INSERT INTO guardians (first_name, last_name, email, mobile_phone, created_at)
                        VALUES (?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([$guardianFirst, $guardianLast, $guardianEmail, $mobilePhone]);
                    $guardian_id = $connection->lastInsertId();
                }

                // Extract athlete information
                $athleteFirst = $formData['athlete_first'] ?? null;
                $athleteLast = $formData['athlete_last'] ?? null;
                $athleteBirthday = $formData['athlete_birthday'] ?? null;
                $athleteGender = $formData['athlete_gender'] ?? null;
                $athleteGrade = $formData['athlete_grade'] ?? null;

                // Jersey size for uniform ordering. Optional — a family that does
                // not know the size leaves it blank, and a blank is more useful
                // than a guess because it shows up as a gap worth chasing.
                //
                // The public form's generic select submits the visible label
                // ('Youth Medium (10-12)'), so this resolves label -> code and
                // yields NULL for blank or unrecognized input. Writing the raw
                // value would violate athletes_jersey_size_check and roll back the
                // whole registration. See lib/jersey_size.php.
                $athleteJerseySize = te_normalize_jersey_size($formData['jersey_size'] ?? null);

                if (!$athleteFirst || !$athleteLast || !$athleteBirthday || !$athleteGender) {
                    throw new Exception('Athlete information is required');
                }

                // Map grade to grade_level integer
                $gradeMap = [
                    'Pre-K' => 0, 'Kindergarten' => 0,
                    '1st' => 1, '2nd' => 2, '3rd' => 3, '4th' => 4,
                    '5th' => 5, '6th' => 6, '7th' => 7, '8th' => 8,
                    '9th' => 9, '10th' => 10, '11th' => 11, '12th' => 12
                ];
                $gradeLevel = $gradeMap[$athleteGrade] ?? null;

                // Returning-athlete dedup: look for an existing athlete in this
                // program's club matching (first + last + dob), the same key the
                // importer uses. Avoids creating duplicate athlete rows when a
                // family re-registers a child season over season.
                $stmt = $connection->prepare("
                    SELECT id FROM athletes
                    WHERE first_name = ? AND last_name = ? AND date_of_birth = ? AND club_id = ?
                    LIMIT 1
                ");
                $stmt->execute([$athleteFirst, $athleteLast, $athleteBirthday, $programClubId]);
                $existingAthlete = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($existingAthlete) {
                    $athlete_id = $existingAthlete['id'];
                    $matchedExisting = true;

                    // A returning athlete who was previously soft-deleted should
                    // come back active when they re-register — otherwise they'd
                    // sit "deleted" while holding a live registration. No-op if
                    // the matched athlete is already active.
                    $reactivate = $connection->prepare("
                        UPDATE athletes
                        SET active_status = true, deleted_at = NULL
                        WHERE id = ? AND active_status = false
                    ");
                    $reactivate->execute([$athlete_id]);

                    // A returning athlete has grown since last season, so the size
                    // they just gave us is fresher than what is on file — take it.
                    // Guarded on non-NULL: a family leaving the (optional) field
                    // blank must not wipe a size the club already knows.
                    if ($athleteJerseySize !== null) {
                        $sizeStmt = $connection->prepare(
                            "UPDATE athletes SET jersey_size = ? WHERE id = ?"
                        );
                        $sizeStmt->execute([$athleteJerseySize, $athlete_id]);
                    }
                } else {
                    // Create athlete record (address will be collected later).
                    // Stamp club_id so future registrations can match this athlete.
                    $stmt = $connection->prepare("
                        INSERT INTO athletes (
                            first_name, last_name, date_of_birth, gender,
                            grade_level, jersey_size, club_id, created_at, active_status
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), TRUE)
                    ");
                    $stmt->execute([
                        $athleteFirst,
                        $athleteLast,
                        $athleteBirthday,
                        $athleteGender,
                        $gradeLevel,
                        $athleteJerseySize,
                        $programClubId
                    ]);
                    $athlete_id = $connection->lastInsertId();
                    $matchedExisting = false;
                }

                // Link athlete to guardian only if the relationship doesn't exist yet
                $stmt = $connection->prepare("
                    SELECT id FROM athlete_guardians
                    WHERE athlete_id = ? AND guardian_id = ?
                ");
                $stmt->execute([$athlete_id, $guardian_id]);
                if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                    // Only the FIRST crew member an athlete gets is primary. This
                    // used to insert a literal TRUE, so a returning family
                    // registering a second child's program — or a second guardian
                    // signing the same athlete up — left the athlete with two
                    // primary links, and "who is primary" then had no answer (R78).
                    // Staff promote a different primary in the Crew modal; that
                    // decision must survive a later registration.
                    $primaryCheck = $connection->prepare(
                        "SELECT 1 FROM athlete_guardians WHERE athlete_id = ? AND is_primary LIMIT 1"
                    );
                    $primaryCheck->execute([$athlete_id]);
                    $isPrimary = $primaryCheck->fetchColumn() ? 'false' : 'true';

                    $stmt = $connection->prepare("
                        INSERT INTO athlete_guardians (
                            athlete_id, guardian_id, relationship,
                            is_primary, created_at
                        ) VALUES (?, ?, 'Guardian', ?::boolean, NOW())
                    ");
                    $stmt->execute([$athlete_id, $guardian_id, $isPrimary]);
                }

                // Duplicate-registration guard: if this athlete already has a
                // non-rejected registration for this program, don't create a
                // second one. Roll back any newly-created athlete/guardian rows —
                // a matched athlete/guardian already existed; a brand-new one will
                // be recreated on a legitimate future registration.
                $stmt = $connection->prepare("
                    SELECT id FROM registrations
                    WHERE program_id = ? AND athlete_id = ? AND status <> 'rejected'
                    LIMIT 1
                ");
                $stmt->execute([$data['program_id'], $athlete_id]);
                $existingRegistration = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($existingRegistration) {
                    $connection->rollBack();
                    echo json_encode([
                        'success' => true,
                        'id' => $existingRegistration['id'],
                        'athlete_id' => $athlete_id,
                        'guardian_id' => $guardian_id,
                        'already_registered' => true,
                        'message' => 'This athlete is already registered for this program'
                    ]);
                    exit();
                }

                // Persist a returning-athlete flag in form_data so the admin sees
                // a "verify same athlete" badge at approval time.
                if ($matchedExisting) {
                    $formData['_athlete_matched'] = true;
                }

                // Insert registration with athlete and guardian references
                $stmt = $connection->prepare("
                    INSERT INTO registrations (
                        program_id, athlete_id, guardian_id, form_data, status, submitted_at
                    ) VALUES (?, ?, ?, ?, 'pending', NOW())
                ");
                $stmt->execute([
                    $data['program_id'],
                    $athlete_id,
                    $guardian_id,
                    json_encode($formData)
                ]);

                $registration_id = $connection->lastInsertId();

                // Record the parental consent this form just collected. The two
                // flags sit at the TOP LEVEL of the payload, beside form_data —
                // not inside it. They were sent and thrown away until 2026-07-31,
                // which meant a family who registered and never opened the portal
                // had no consent record at all.
                //
                // Runs in a savepoint: a failure here must not roll back the
                // family's registration. See lib/consent_capture.php.
                te_record_registration_consent_safely(
                    $connection,
                    (int) $athlete_id,
                    (string) $guardianEmail,
                    trim($guardianFirst . ' ' . $guardianLast),
                    te_consent_types_from_registration($data),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null
                );

                // Get payment item for this program (registration fee)
                $stmt = $connection->prepare("
                    SELECT id, base_price, allow_payment_plan
                    FROM payment_items
                    WHERE program_id = ? AND item_type = 'registration' AND active = true
                    LIMIT 1
                ");
                $stmt->execute([$data['program_id']]);
                $paymentItem = $stmt->fetch(PDO::FETCH_ASSOC);

                $athlete_payment_id = null;
                $payment_amount = 0;
                $sibling_discount = 0;
                $sibling_discount_applied = false;
                $payment_item_id = null;

                // Use payment_item if exists, otherwise fall back to program's registration_fee
                if ($paymentItem) {
                    $payment_amount = floatval($paymentItem['base_price']);
                    $payment_item_id = $paymentItem['id'];
                } elseif ($program['registration_fee']) {
                    $payment_amount = floatval($program['registration_fee']);
                }

                if ($payment_amount > 0) {
                    // Only process payment if there's a fee

                    // Check for sibling discount (only if using payment_item with discount settings)
                    if ($payment_item_id) {
                        $stmt = $connection->prepare("
                            SELECT sibling_discount_enabled, sibling_discount_type, sibling_discount_value
                            FROM payment_items WHERE id = ?
                        ");
                        $stmt->execute([$payment_item_id]);
                        $discountSettings = $stmt->fetch(PDO::FETCH_ASSOC);

                        if ($discountSettings && $discountSettings['sibling_discount_enabled']) {
                            // Find other athletes linked to the same guardian
                            $stmt = $connection->prepare("
                                SELECT DISTINCT ag.athlete_id
                                FROM athlete_guardians ag
                                WHERE ag.guardian_id = ? AND ag.athlete_id != ?
                            ");
                            $stmt->execute([$guardian_id, $athlete_id]);
                            $siblingIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

                            if (!empty($siblingIds)) {
                                // Check if any siblings are registered for the same program
                                $placeholders = implode(',', array_fill(0, count($siblingIds), '?'));
                                $stmt = $connection->prepare("
                                    SELECT COUNT(*) FROM registrations
                                    WHERE program_id = ? AND athlete_id IN ($placeholders) AND status = 'approved'
                                ");
                                $params = array_merge([$data['program_id']], $siblingIds);
                                $stmt->execute($params);
                                $registeredSiblingCount = $stmt->fetchColumn();

                                if ($registeredSiblingCount > 0) {
                                    // Apply sibling discount
                                    $discountType = $discountSettings['sibling_discount_type'];
                                    $discountValue = floatval($discountSettings['sibling_discount_value']);

                                    if ($discountType === 'percentage') {
                                        $sibling_discount = round($payment_amount * ($discountValue / 100), 2);
                                    } else {
                                        $sibling_discount = min($discountValue, $payment_amount);
                                    }
                                    $sibling_discount_applied = true;
                                }
                            }
                        }
                    }

                    $final_amount = $payment_amount - $sibling_discount;

                    // Create athlete_payments record
                    $stmt = $connection->prepare("
                        INSERT INTO athlete_payments (
                            athlete_id, payment_item_id, program_id,
                            base_amount, discount_amount, scholarship_amount, final_amount,
                            status, amount_paid, amount_remaining, created_at
                        ) VALUES (?, ?, ?, ?, ?, 0, ?, 'pending', 0, ?, NOW())
                    ");
                    $stmt->execute([
                        $athlete_id,
                        $payment_item_id,  // Can be null if using program's registration_fee
                        $data['program_id'],
                        $payment_amount,
                        $sibling_discount,
                        $final_amount,
                        $final_amount
                    ]);
                    $athlete_payment_id = $connection->lastInsertId();
                    $payment_amount = $final_amount; // Update for response
                }

                $connection->commit();

                // ⚠️ The confirmation is sent AFTER commit(), never inside the
                // transaction. A throw in here while the transaction was open
                // would roll back the family's registration — they would lose
                // their place in the program because SendGrid had a bad minute.
                // Same reasoning as the consent capture above, which runs in a
                // SAVEPOINT for exactly this reason.
                //
                // Everything below is best-effort: the registration is already
                // durable, so a failed or switched-off send is reported in the
                // response and nothing more. `confirmation_sent` is always
                // present so a false is a fact rather than a missing key.
                $confirmationSent = false;
                $confirmationDisabled = null;

                if (te_feature_enabled('REGISTRATION_CONFIRMATION')) {
                    try {
                        // registrant_email is only written by the coach/adult
                        // flow, which returns long before here; the athlete flow
                        // carries the address in form_data. Read both so this
                        // keeps working if that ever changes.
                        $confirmTo = trim((string) ($guardianEmail ?? ''));
                        if ($confirmTo === '') {
                            $row = $connection->prepare(
                                'SELECT registrant_email FROM registrations WHERE id = ?'
                            );
                            $row->execute([$registration_id]);
                            $confirmTo = trim((string) ($row->fetchColumn() ?: ''));
                        }

                        // Program name and "what to bring" are not in the
                        // validation SELECT above; read them here, outside the
                        // transaction, so the write path is untouched.
                        $progStmt = $connection->prepare(
                            'SELECT name, what_to_bring, club_id FROM programs WHERE id = ?'
                        );
                        $progStmt->execute([$data['program_id']]);
                        $progRow = $progStmt->fetch(PDO::FETCH_ASSOC) ?: [];

                        $confirmClubId = $progRow['club_id'] ?? $programClubId ?? null;
                        $confirmClubId = ($confirmClubId === null || $confirmClubId === '')
                            ? null : (int) $confirmClubId;

                        if ($confirmTo !== '') {
                            $mailer = (new Email())->forClub($connection, $confirmClubId);
                            $confirmationSent = te_send_registration_confirmation($mailer, $confirmTo, [
                                'club_name'      => te_email_from_name($connection, $confirmClubId),
                                'guardian_first' => $guardianFirst,
                                'athlete_name'   => trim(($athleteFirst ?? '') . ' ' . ($athleteLast ?? '')),
                                'program_name'   => $progRow['name'] ?? '',
                                'what_to_bring'  => $progRow['what_to_bring'] ?? '',
                                'portal_url'     => te_parent_portal_url(),
                            ]);

                            if (!$confirmationSent) {
                                error_log("registration confirmation refused by the provider "
                                    . "for registration {$registration_id}");
                            }
                        } else {
                            error_log("registration confirmation skipped for registration "
                                . "{$registration_id}: no registrant email on the submission");
                        }
                    } catch (Throwable $e) {
                        // Never fails the registration. Throwable, not Exception:
                        // this sits inside a catch that calls rollBack(), and the
                        // transaction is already committed by now.
                        error_log("registration confirmation failed for registration {$registration_id}: "
                            . $e->getMessage());
                    }
                } else {
                    $confirmationDisabled = 'REGISTRATION_CONFIRMATION';
                }

                echo json_encode([
                    'success' => true,
                    'id' => $registration_id,
                    'confirmation_sent' => $confirmationSent,
                    'confirmation_feature_disabled' => $confirmationDisabled,
                    'athlete_id' => $athlete_id,
                    'guardian_id' => $guardian_id,
                    'athlete_matched' => $matchedExisting,
                    'athlete_payment_id' => $athlete_payment_id,
                    'payment_amount' => $payment_amount,
                    'sibling_discount_applied' => $sibling_discount_applied,
                    'sibling_discount_amount' => $sibling_discount,
                    'message' => $sibling_discount_applied
                        ? 'Registration submitted successfully! Sibling discount applied.'
                        : 'Registration submitted successfully'
                ]);

            } catch (Exception $e) {
                $connection->rollBack();
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'PUT':
            // Approving a registration mints an athlete_payment, an invoice and a
            // parent-portal invite email. It was reachable with no token, and both
            // the new status and the reviewer's id came from the request body.
            $auth = AuthMiddleware::requireAuth();

            // Update registration status
            $registration_id = (int)($_GET['id'] ?? 0);
            $data = json_decode(file_get_contents("php://input"), true);
            if (!is_array($data)) {
                $data = [];
            }

            // Get registration details
            $stmt = $connection->prepare("
                SELECT r.*, p.registration_fee, p.club_id
                FROM registrations r
                JOIN programs p ON r.program_id = p.id
                WHERE r.id = ?
            ");
            $stmt->execute([$registration_id]);
            $registration = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$registration) {
                http_response_code(404);
                echo json_encode(['error' => 'Registration not found']);
                exit();
            }

            // Admin or coach of the club that owns the program. Deliberately NOT
            // canAccessClub(): a parent holds a role scoped to the club and would
            // pass it.
            if (!te_is_club_staff($auth, (int)$registration['club_id'])) {
                http_response_code(403);
                echo json_encode(['error' => 'Not authorized for this registration']);
                exit();
            }

            $status = $data['status'] ?? null;
            if (!in_array($status, TE_REGISTRATION_REVIEW_STATUSES, true)) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'Invalid status',
                    'allowed' => TE_REGISTRATION_REVIEW_STATUSES
                ]);
                exit();
            }

            $connection->beginTransaction();

            try {
                // Update registration status
                $stmt = $connection->prepare("
                    UPDATE registrations
                    SET status = ?, reviewed_at = NOW(), reviewed_by = ?
                    WHERE id = ?
                ");
                // reviewed_by is the record of who approved this. Taken from the
                // body it recorded whoever the caller nominated.
                $stmt->execute([
                    $status,
                    $auth->getUserId(),
                    $registration_id
                ]);

                $athlete_payment_id = null;
                $invoice_id = null;

                // If approving, ensure athlete_payment + invoice exist
                if ($status === 'approved') {
                    // Check if athlete_payment already exists
                    $stmt = $connection->prepare("
                        SELECT id, final_amount, base_amount, discount_amount, due_date
                        FROM athlete_payments
                        WHERE athlete_id = ? AND program_id = ?
                    ");
                    $stmt->execute([$registration['athlete_id'], $registration['program_id']]);
                    $existingPayment = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($existingPayment) {
                        $athlete_payment_id = $existingPayment['id'];
                    } else {
                        // Determine fee: check payment_items first (matches the
                        // registration submission flow), then fall back to
                        // programs.registration_fee.
                        $fee = 0;
                        $payment_item_id = null;

                        $stmt = $connection->prepare("
                            SELECT id, base_price
                            FROM payment_items
                            WHERE program_id = ? AND item_type = 'registration' AND active = true
                            LIMIT 1
                        ");
                        $stmt->execute([$registration['program_id']]);
                        $paymentItem = $stmt->fetch(PDO::FETCH_ASSOC);

                        if ($paymentItem) {
                            $fee = floatval($paymentItem['base_price']);
                            $payment_item_id = $paymentItem['id'];
                        } elseif ($registration['registration_fee'] && floatval($registration['registration_fee']) > 0) {
                            $fee = floatval($registration['registration_fee']);
                        }

                        if ($fee > 0) {
                            $stmt = $connection->prepare("
                                INSERT INTO athlete_payments (
                                    athlete_id, payment_item_id, program_id,
                                    base_amount, discount_amount, scholarship_amount, final_amount,
                                    status, amount_paid, amount_remaining, created_at
                                ) VALUES (?, ?, ?, ?, 0, 0, ?, 'pending', 0, ?, NOW())
                                RETURNING id
                            ");
                            $stmt->execute([
                                $registration['athlete_id'],
                                $payment_item_id,
                                $registration['program_id'],
                                $fee,
                                $fee,
                                $fee
                            ]);
                            $athlete_payment_id = $stmt->fetchColumn();
                        }
                    }

                    // Auto-create invoice if we have a payment and no invoice yet
                    if ($athlete_payment_id) {
                        $stmt = $connection->prepare("
                            SELECT id FROM invoices WHERE athlete_payment_id = ? LIMIT 1
                        ");
                        $stmt->execute([$athlete_payment_id]);

                        if (!$stmt->fetch()) {
                            // Load payment details for the invoice
                            $stmt = $connection->prepare("
                                SELECT ap.*, pi.name as item_name, p.name as program_name
                                FROM athlete_payments ap
                                LEFT JOIN payment_items pi ON ap.payment_item_id = pi.id
                                LEFT JOIN programs p ON ap.program_id = p.id
                                WHERE ap.id = ?
                            ");
                            $stmt->execute([$athlete_payment_id]);
                            $payment = $stmt->fetch(PDO::FETCH_ASSOC);

                            if ($payment) {
                                $inv_number = $connection->query("SELECT generate_invoice_number()")->fetchColumn();
                                $due_date = $payment['due_date'] ?? date('Y-m-d', strtotime('+30 days'));
                                $subtotal = floatval($payment['base_amount']);
                                $discount = floatval($payment['discount_amount']) + floatval($payment['scholarship_amount']);
                                $total = floatval($payment['final_amount']);

                                $stmt = $connection->prepare("
                                    INSERT INTO invoices (
                                        invoice_number, athlete_id, athlete_payment_id, program_id,
                                        invoice_date, due_date, subtotal, discount_amount, total_amount,
                                        status, memo
                                    ) VALUES (?, ?, ?, ?, CURRENT_DATE, ?, ?, ?, ?, 'draft', NULL)
                                    RETURNING id
                                ");
                                $stmt->execute([
                                    $inv_number,
                                    $registration['athlete_id'],
                                    $athlete_payment_id,
                                    $registration['program_id'],
                                    $due_date,
                                    $subtotal,
                                    $discount,
                                    $total
                                ]);
                                $invoice_id = $stmt->fetchColumn();

                                // Auto-create line item
                                $description = $payment['item_name']
                                    ?? ($payment['program_name'] ? $payment['program_name'] . ' Registration' : 'Registration Fee');
                                $connection->prepare("
                                    INSERT INTO invoice_items (invoice_id, payment_item_id, description, quantity, unit_price, line_total)
                                    VALUES (?, ?, ?, 1, ?, ?)
                                ")->execute([
                                    $invoice_id,
                                    $payment['payment_item_id'],
                                    $description,
                                    $total,
                                    $total
                                ]);
                            }
                        }
                    }
                }

                // Parent-portal invite: ensure the guardian has a login and email
                // them a "set your password" link. Wrapped so a failure here can
                // NEVER break approval.
                $parent_invite_status = null;
                if ($status === 'approved') {
                    try {
                        require_once __DIR__ . '/../config/env.php';
                        require_once __DIR__ . '/../lib/ParentInvite.php';
                        require_once __DIR__ . '/../lib/Email.php';

                        // Resolve the club that owns this program.
                        $stmt = $connection->prepare('SELECT club_id FROM programs WHERE id = ?');
                        $stmt->execute([$registration['program_id']]);
                        $clubId = (int)$stmt->fetchColumn();

                        $inv = parentInvite_ensureUserAndToken(
                            $connection,
                            (int)$registration['guardian_id'],
                            $clubId
                        );
                        $parent_invite_status = $inv['status'];

                        if ($inv['status'] === 'invited') {
                            // Athlete name for context (best-effort).
                            $athleteName = null;
                            try {
                                $aStmt = $connection->prepare('SELECT first_name, last_name FROM athletes WHERE id = ?');
                                $aStmt->execute([$registration['athlete_id']]);
                                $a = $aStmt->fetch(PDO::FETCH_ASSOC);
                                if ($a) {
                                    $athleteName = trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? '')) ?: null;
                                }
                            } catch (Throwable $ae) {
                                // non-fatal
                            }

                            $appUrl = rtrim(Env::get('APP_URL', 'https://teams-elevated.netlify.app'), '/');
                            $link = $appUrl . '/set-parent-password?token=' . $inv['token'];
                            // Branded as the club: $clubId is the program's club, resolved above.
                            (new Email())->forClub($connection, (int)$clubId)->sendParentInvite($inv['email'], $inv['name'], $link, $athleteName);
                        } elseif ($inv['status'] === 'already_active') {
                            error_log('parent invite skipped (already active): ' . ($inv['email'] ?? ''));
                        } else {
                            error_log('parent invite not sent: ' . ($inv['message'] ?? 'unknown'));
                        }
                    } catch (Throwable $e) {
                        error_log('parent invite failed: ' . $e->getMessage());
                    }
                }

                $connection->commit();

                echo json_encode([
                    'success' => true,
                    'message' => 'Registration updated',
                    'athlete_payment_id' => $athlete_payment_id,
                    'invoice_id' => $invoice_id,
                    'parent_invite' => $parent_invite_status
                ]);

            } catch (Exception $e) {
                $connection->rollBack();
                http_response_code(500);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'DELETE':
            // A hard DELETE, so one integer removed a family's registration
            // permanently. It required no token until 2026-09-02.
            $auth = AuthMiddleware::requireAuth();

            // Delete registration
            $registration_id = (int)($_GET['id'] ?? 0);

            $clubId = te_registration_club_id($connection, $registration_id);
            if ($clubId === null) {
                http_response_code(404);
                echo json_encode(['error' => 'Registration not found']);
                exit();
            }

            if (!te_is_club_staff($auth, $clubId)) {
                http_response_code(403);
                echo json_encode(['error' => 'Not authorized for this registration']);
                exit();
            }

            $stmt = $connection->prepare("DELETE FROM registrations WHERE id = ?");
            $stmt->execute([$registration_id]);

            echo json_encode(['success' => true, 'message' => 'Registration deleted']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>