<?php

require_once __DIR__ . '/../lib/athlete_medical.php';
header('Content-Type: application/json');
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();


require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/AthleteScope.php';

// Ensure a PDO handle is available for this directly-invoked gateway.
if (!isset($pdo) || !($pdo instanceof PDO)) {
    $pdo = Database::getInstance()->getConnection();
}

// GET - List all athletes or get single athlete
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        // Check if specific athlete ID is requested
        if (isset($_GET['id'])) {
            $athleteId = intval($_GET['id']);

            // P0 access control: never return an athlete by id without verifying
            // the requester is allowed to see them (club admin of the athlete's
            // club, a coach of one of the athlete's teams, or a guardian of the
            // athlete). Enforced BEFORE any data query.
            $auth = AuthMiddleware::requireAuth();
            if (!AthleteScope::userCanAccessAthlete($pdo, $auth, $athleteId)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Access denied']);
                exit;
            }

            // Get athlete with guardian info
            $query = "
                SELECT a.*,
                       g.first_name as guardian_first_name,
                       g.last_name as guardian_last_name,
                       g.email as guardian_email,
                       g.mobile_phone as guardian_phone,
                       g.relationship
                FROM athletes a
                -- LEGACY `guardian_*` keys. There is no primary guardian
                -- (2026-09-02); this is the FIRST crew member by link id, and the
                -- whole family is in `guardians` below. LATERAL, not a JOIN, so a
                -- two-parent household does not duplicate the athlete row.
                LEFT JOIN LATERAL (
                    SELECT gg.first_name, gg.last_name, gg.email, gg.mobile_phone,
                           ag.relationship
                    FROM athlete_guardians ag
                    JOIN guardians gg ON gg.id = ag.guardian_id
                    WHERE ag.athlete_id = a.id
                    ORDER BY ag.id
                    LIMIT 1
                ) g ON true
                WHERE a.id = ?
                  AND a.active_status = true
            ";

            $stmt = $pdo->prepare($query);
            $stmt->execute([$athleteId]);
            $athlete = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$athlete) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Athlete not found']);
                exit;
            }

            // Get all guardians
            $guardiansQuery = "
                SELECT g.*, ag.relationship
                FROM guardians g
                INNER JOIN athlete_guardians ag ON g.id = ag.guardian_id
                WHERE ag.athlete_id = ?
                ORDER BY ag.id
            ";
            $stmt = $pdo->prepare($guardiansQuery);
            $stmt->execute([$athleteId]);
            $athlete['guardians'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get emergency contacts
            $emergencyQuery = "
                SELECT * FROM emergency_contacts
                WHERE athlete_id = ?
                ORDER BY priority_order
            ";
            $stmt = $pdo->prepare($emergencyQuery);
            $stmt->execute([$athleteId]);
            $athlete['emergency_contacts'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get teams for athlete
            $teamsQuery = "
                SELECT t.id, t.name
                FROM teams t
                INNER JOIN team_members tm ON t.id = tm.team_id
                WHERE tm.athlete_id = ?
            ";
            $stmt = $pdo->prepare($teamsQuery);
            $stmt->execute([$athleteId]);
            $athlete['teams'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'athlete' => $athlete]);
        } else {
            // List all athletes
            $query = "
                SELECT a.id, a.first_name, a.middle_initial, a.last_name,
                       a.preferred_name, a.date_of_birth, a.gender,
                       a.school_name, a.grade_level, a.active_status,
                       g.first_name as primary_guardian_name,
                       g.email as primary_guardian_email,
                       g.mobile_phone as primary_guardian_phone
                FROM athletes a
                -- LEGACY keys, first crew member by link id — see above.
                LEFT JOIN LATERAL (
                    SELECT gg.first_name, gg.email, gg.mobile_phone
                    FROM athlete_guardians ag
                    JOIN guardians gg ON gg.id = ag.guardian_id
                    WHERE ag.athlete_id = a.id
                    ORDER BY ag.id
                    LIMIT 1
                ) g ON true
                WHERE a.active_status = true
                ORDER BY a.last_name, a.first_name
            ";

            $stmt = $pdo->query($query);
            $athletes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode($athletes);
        }
    } catch (PDOException $e) {
        error_log("Database error in athletes.php GET: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Database error occurred']);
    }
}

// POST - Create new athlete
elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $data = json_decode(file_get_contents('php://input'), true);

        // Begin transaction
        $pdo->beginTransaction();

        // Insert athlete
        $athleteQuery = "
            INSERT INTO athletes (
                first_name, middle_initial, last_name, preferred_name,
                date_of_birth, gender, home_address_line1, home_address_line2,
                city, state, zip_code, country, school_name, grade_level,
                dietary_restrictions, active_status
            ) VALUES (
                :first_name, :middle_initial, :last_name, :preferred_name,
                :date_of_birth, :gender, :home_address_line1, :home_address_line2,
                :city, :state, :zip_code, :country, :school_name, :grade_level,
                :dietary_restrictions, 1
            )
        ";

        $stmt = $pdo->prepare($athleteQuery);
        $stmt->execute([
            ':first_name' => $data['first_name'],
            ':middle_initial' => $data['middle_initial'] ?? null,
            ':last_name' => $data['last_name'],
            ':preferred_name' => $data['preferred_name'] ?? null,
            ':date_of_birth' => $data['date_of_birth'],
            ':gender' => $data['gender'],
            ':home_address_line1' => $data['home_address_line1'],
            ':home_address_line2' => $data['home_address_line2'] ?? null,
            ':city' => $data['city'],
            ':state' => $data['state'],
            ':zip_code' => $data['zip_code'],
            ':country' => $data['country'] ?? 'USA',
            ':school_name' => $data['school_name'] ?? null,
            ':grade_level' => $data['grade_level'] ?? null,
            ':dietary_restrictions' => isset($data['dietary_restrictions']) ? json_encode($data['dietary_restrictions']) : null
        ]);

        $athleteId = $pdo->lastInsertId();

        // Insert guardian if provided
        if (isset($data['guardian']) && !empty($data['guardian']['email'])) {
            $guardian = $data['guardian'];

            // Composite match: email + first_name + last_name, matching AthleteController::createOrFindGuardian()
            // so families can share an email (e.g. thejones@gmail.com for both parents).
            // Blank email matches nothing: 25 guardians carry email = '' (empty
            // string, not NULL) and '' = '' is true, so two unrelated emailless
            // people with the same name would merge into one record.
            // Comparison is case/whitespace-insensitive so "Taylor" and "taylor "
            // are one person, not two rows.
            $existingGuardian = false;
            if (trim((string)($guardian['email'] ?? '')) !== '') {
                $checkGuardian = $pdo->prepare(
                    "SELECT id FROM guardians
                     WHERE lower(trim(email))      = lower(:email)
                       AND lower(trim(first_name)) = lower(:first_name)
                       AND lower(trim(last_name))  = lower(:last_name)"
                );
                $checkGuardian->execute([
                    ':email'      => trim($guardian['email']),
                    ':first_name' => trim((string)($guardian['first_name'] ?? '')),
                    ':last_name'  => trim((string)($guardian['last_name'] ?? '')),
                ]);
                $existingGuardian = $checkGuardian->fetch();
            }

            if ($existingGuardian) {
                $guardianId = $existingGuardian['id'];
            } else {
                // Create new guardian
                $guardianQuery = "
                    INSERT INTO guardians (
                        first_name, last_name, email, mobile_phone, work_phone,
                        address_line1, city, state, zip_code
                    ) VALUES (
                        :first_name, :last_name, :email, :mobile_phone, :work_phone,
                        :address_line1, :city, :state, :zip_code
                    )
                ";

                $stmt = $pdo->prepare($guardianQuery);
                $stmt->execute([
                    ':first_name' => $guardian['first_name'],
                    ':last_name' => $guardian['last_name'],
                    ':email' => $guardian['email'],
                    ':mobile_phone' => $guardian['mobile_phone'],
                    ':work_phone' => $guardian['work_phone'] ?? null,
                    ':address_line1' => $guardian['address_line1'] ?? $data['home_address_line1'],
                    ':city' => $guardian['city'] ?? $data['city'],
                    ':state' => $guardian['state'] ?? $data['state'],
                    ':zip_code' => $guardian['zip_code'] ?? $data['zip_code']
                ]);

                $guardianId = $pdo->lastInsertId();
            }

            // Link guardian to athlete.
            //
            // `is_primary` is not written. There is no primary guardian in this
            // product (2026-09-02) — crew members are equal — so nothing here
            // decides which of a family's adults represents it.
            $linkQuery = "
                INSERT INTO athlete_guardians (
                    athlete_id, guardian_id, relationship,
                    can_pickup, emergency_contact
                ) VALUES (
                    :athlete_id, :guardian_id, :relationship, true, true
                )
            ";

            $stmt = $pdo->prepare($linkQuery);
            $stmt->execute([
                ':athlete_id' => $athleteId,
                ':guardian_id' => $guardianId,
                ':relationship' => $guardian['relationship'] ?? 'Guardian'
            ]);
        }

        // Insert emergency contacts
        if (isset($data['emergency_contacts']) && is_array($data['emergency_contacts'])) {
            $emergencyQuery = "
                -- Columns are name / secondary_phone; contact_name /
                -- alternate_phone do not exist (42703). Second copy of the
                -- defect fixed in AthleteController.
                INSERT INTO emergency_contacts (
                    athlete_id, name, relationship, primary_phone,
                    secondary_phone, can_authorize_medical, priority_order
                ) VALUES (
                    :athlete_id, :name, :relationship, :primary_phone,
                    :secondary_phone, :can_authorize_medical, :priority_order
                )
            ";

            $stmt = $pdo->prepare($emergencyQuery);
            foreach ($data['emergency_contacts'] as $index => $contact) {
                // Accept either input spelling; skip the blank starter row.
                if (!empty($contact['name']) || !empty($contact['contact_name'])) {
                    $stmt->execute([
                        ':athlete_id' => $athleteId,
                        ':name' => $contact['name'] ?? $contact['contact_name'] ?? null,
                        ':relationship' => $contact['relationship'],
                        ':primary_phone' => $contact['primary_phone'],
                        ':secondary_phone' => $contact['secondary_phone'] ?? $contact['alternate_phone'] ?? null,
                        ':can_authorize_medical' => !empty($contact['can_authorize_medical']) ? 'true' : 'false',
                        ':priority_order' => $index + 1
                    ]);
                }
            }
        }

        // Insert medical record if provided.
        // Same defect as AthleteController: these columns live on
        // athlete_medical, not medical_records.
        if (isset($data['medical']) && !empty($data['medical'])) {
            te_save_athlete_medical($pdo, (int) $athleteId, $data['medical']);
        }

        $pdo->commit();

        http_response_code(201);
        echo json_encode([
            'id' => $athleteId,
            'message' => 'Athlete created successfully'
        ]);

    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Database error in athletes.php POST: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Failed to create athlete: ' . $e->getMessage()]);
    }
}

// PUT - Update athlete
elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    try {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($data['id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Athlete ID is required']);
            exit;
        }

        $athleteId = $data['id'];

        // Update athlete
        $updateQuery = "
            UPDATE athletes SET
                first_name = :first_name,
                middle_initial = :middle_initial,
                last_name = :last_name,
                preferred_name = :preferred_name,
                date_of_birth = :date_of_birth,
                gender = :gender,
                home_address_line1 = :home_address_line1,
                home_address_line2 = :home_address_line2,
                city = :city,
                state = :state,
                zip_code = :zip_code,
                school_name = :school_name,
                grade_level = :grade_level,
                dietary_restrictions = :dietary_restrictions
            WHERE id = :id
        ";

        $stmt = $pdo->prepare($updateQuery);
        $stmt->execute([
            ':id' => $athleteId,
            ':first_name' => $data['first_name'],
            ':middle_initial' => $data['middle_initial'] ?? null,
            ':last_name' => $data['last_name'],
            ':preferred_name' => $data['preferred_name'] ?? null,
            ':date_of_birth' => $data['date_of_birth'],
            ':gender' => $data['gender'],
            ':home_address_line1' => $data['home_address_line1'],
            ':home_address_line2' => $data['home_address_line2'] ?? null,
            ':city' => $data['city'],
            ':state' => $data['state'],
            ':zip_code' => $data['zip_code'],
            ':school_name' => $data['school_name'] ?? null,
            ':grade_level' => $data['grade_level'] ?? null,
            ':dietary_restrictions' => isset($data['dietary_restrictions']) ? json_encode($data['dietary_restrictions']) : null
        ]);

        echo json_encode(['message' => 'Athlete updated successfully']);

    } catch (PDOException $e) {
        error_log("Database error in athletes.php PUT: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Failed to update athlete']);
    }
}