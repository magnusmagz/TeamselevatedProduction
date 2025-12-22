<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once '../config/database.php';

// GET - List all athletes or get single athlete
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        // Check if specific athlete ID is requested
        if (isset($_GET['id'])) {
            $athleteId = intval($_GET['id']);

            // Get athlete with guardian info
            $query = "
                SELECT a.*,
                       g.first_name as guardian_first_name,
                       g.last_name as guardian_last_name,
                       g.email as guardian_email,
                       g.mobile_phone as guardian_phone,
                       ag.relationship_type
                FROM athletes a
                LEFT JOIN athlete_guardians ag ON a.id = ag.athlete_id AND ag.is_primary_contact = 1
                LEFT JOIN guardians g ON ag.guardian_id = g.id
                WHERE a.id = ? AND a.active_status = 1
            ";

            $stmt = $pdo->prepare($query);
            $stmt->execute([$athleteId]);
            $athlete = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$athlete) {
                http_response_code(404);
                echo json_encode(['error' => 'Athlete not found']);
                exit;
            }

            // Get all guardians
            $guardiansQuery = "
                SELECT g.*, ag.relationship_type, ag.is_primary_contact
                FROM guardians g
                INNER JOIN athlete_guardians ag ON g.id = ag.guardian_id
                WHERE ag.athlete_id = ?
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

            echo json_encode($athlete);
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
                LEFT JOIN athlete_guardians ag ON a.id = ag.athlete_id AND ag.is_primary_contact = 1
                LEFT JOIN guardians g ON ag.guardian_id = g.id
                WHERE a.active_status = 1
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

            // Check if guardian already exists
            $checkGuardian = $pdo->prepare("SELECT id FROM guardians WHERE email = ?");
            $checkGuardian->execute([$guardian['email']]);
            $existingGuardian = $checkGuardian->fetch();

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

            // Link guardian to athlete
            $linkQuery = "
                INSERT INTO athlete_guardians (
                    athlete_id, guardian_id, relationship_type, is_primary_contact,
                    can_authorize_medical, can_pickup, receives_communications,
                    financial_responsible
                ) VALUES (
                    :athlete_id, :guardian_id, :relationship_type, 1, 1, 1, 1, 1
                )
            ";

            $stmt = $pdo->prepare($linkQuery);
            $stmt->execute([
                ':athlete_id' => $athleteId,
                ':guardian_id' => $guardianId,
                ':relationship_type' => $guardian['relationship_type'] ?? 'Guardian'
            ]);
        }

        // Insert emergency contacts
        if (isset($data['emergency_contacts']) && is_array($data['emergency_contacts'])) {
            $emergencyQuery = "
                INSERT INTO emergency_contacts (
                    athlete_id, contact_name, relationship, primary_phone,
                    alternate_phone, can_authorize_medical, priority_order
                ) VALUES (
                    :athlete_id, :contact_name, :relationship, :primary_phone,
                    :alternate_phone, :can_authorize_medical, :priority_order
                )
            ";

            $stmt = $pdo->prepare($emergencyQuery);
            foreach ($data['emergency_contacts'] as $index => $contact) {
                if (!empty($contact['contact_name'])) {
                    $stmt->execute([
                        ':athlete_id' => $athleteId,
                        ':contact_name' => $contact['contact_name'],
                        ':relationship' => $contact['relationship'],
                        ':primary_phone' => $contact['primary_phone'],
                        ':alternate_phone' => $contact['alternate_phone'] ?? null,
                        ':can_authorize_medical' => $contact['can_authorize_medical'] ?? false,
                        ':priority_order' => $index + 1
                    ]);
                }
            }
        }

        // Insert medical record if provided
        if (isset($data['medical']) && !empty($data['medical']['physician_name'])) {
            $medical = $data['medical'];
            $medicalQuery = "
                INSERT INTO medical_records (
                    athlete_id, physical_exam_date, physical_exam_file_url,
                    physician_name, physician_phone, preferred_hospital,
                    blood_type, has_asthma, has_diabetes, has_seizures,
                    has_heart_condition
                ) VALUES (
                    :athlete_id, :physical_exam_date, '',
                    :physician_name, :physician_phone, :preferred_hospital,
                    :blood_type, :has_asthma, :has_diabetes, :has_seizures,
                    :has_heart_condition
                )
            ";

            $stmt = $pdo->prepare($medicalQuery);
            $stmt->execute([
                ':athlete_id' => $athleteId,
                ':physical_exam_date' => date('Y-m-d'),
                ':physician_name' => $medical['physician_name'],
                ':physician_phone' => $medical['physician_phone'],
                ':preferred_hospital' => $medical['preferred_hospital'] ?? null,
                ':blood_type' => $medical['blood_type'] ?? null,
                ':has_asthma' => $medical['has_asthma'] ?? false,
                ':has_diabetes' => $medical['has_diabetes'] ?? false,
                ':has_seizures' => $medical['has_seizures'] ?? false,
                ':has_heart_condition' => $medical['has_heart_condition'] ?? false
            ]);
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