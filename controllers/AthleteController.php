<?php
require_once __DIR__ . '/../config/database.php';

class AthleteController {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function createAthlete() {
        $data = json_decode(file_get_contents('php://input'), true);

        // Validate required fields
        $errors = $this->validateAthlete($data);
        if (!empty($errors)) {
            http_response_code(400);
            echo json_encode(['errors' => $errors]);
            return;
        }

        try {
            $this->db->beginTransaction();

            // Create the athlete record
            $sql = "INSERT INTO athletes (
                        first_name, middle_initial, last_name, preferred_name,
                        date_of_birth, gender, home_address_line1, home_address_line2,
                        city, state, zip_code, country, school_name, grade_level,
                        dietary_restrictions, created_by
                    ) VALUES (
                        :first_name, :middle_initial, :last_name, :preferred_name,
                        :date_of_birth, :gender, :home_address_line1, :home_address_line2,
                        :city, :state, :zip_code, :country, :school_name, :grade_level,
                        :dietary_restrictions, :created_by
                    )";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':first_name' => $data['first_name'],
                ':middle_initial' => $data['middle_initial'] ?? null,
                ':last_name' => $data['last_name'],
                ':preferred_name' => $data['preferred_name'] ?? null,
                ':date_of_birth' => $data['date_of_birth'],
                ':gender' => $data['gender'],
                ':home_address_line1' => $data['home_address_line1'] ?? 'TBD',
                ':home_address_line2' => $data['home_address_line2'] ?? null,
                ':city' => $data['city'] ?? 'TBD',
                ':state' => $data['state'] ?? 'CA',
                ':zip_code' => $data['zip_code'] ?? '00000',
                ':country' => $data['country'] ?? 'USA',
                ':school_name' => $data['school_name'] ?? null,
                ':grade_level' => $data['grade_level'] ?? null,
                ':dietary_restrictions' => !empty($data['dietary_restrictions']) ? json_encode($data['dietary_restrictions']) : null,
                ':created_by' => $_SESSION['user_id'] ?? null
            ]);

            $athleteId = $this->db->lastInsertId();

            // Create guardian record if provided
            if (!empty($data['guardian'])) {
                $guardianId = $this->createOrFindGuardian($data['guardian']);

                // Create relationship
                $sql = "INSERT INTO athlete_guardians (
                            athlete_id, guardian_id, relationship_type, is_primary_contact,
                            has_legal_custody, can_authorize_medical, can_pickup,
                            receives_communications, financial_responsible
                        ) VALUES (
                            :athlete_id, :guardian_id, :relationship_type, :is_primary_contact,
                            :has_legal_custody, :can_authorize_medical, :can_pickup,
                            :receives_communications, :financial_responsible
                        )";

                $stmt = $this->db->prepare($sql);
                $stmt->execute([
                    ':athlete_id' => $athleteId,
                    ':guardian_id' => $guardianId,
                    ':relationship_type' => $data['guardian']['relationship_type'] ?? 'Guardian',
                    ':is_primary_contact' => true,
                    ':has_legal_custody' => $data['guardian']['has_legal_custody'] ?? true,
                    ':can_authorize_medical' => $data['guardian']['can_authorize_medical'] ?? true,
                    ':can_pickup' => $data['guardian']['can_pickup'] ?? true,
                    ':receives_communications' => true,
                    ':financial_responsible' => $data['guardian']['financial_responsible'] ?? true
                ]);
            }

            // Create emergency contacts if provided
            if (!empty($data['emergency_contacts'])) {
                foreach ($data['emergency_contacts'] as $index => $contact) {
                    $sql = "INSERT INTO emergency_contacts (
                                athlete_id, contact_name, relationship, primary_phone,
                                alternate_phone, can_authorize_medical, priority_order
                            ) VALUES (
                                :athlete_id, :contact_name, :relationship, :primary_phone,
                                :alternate_phone, :can_authorize_medical, :priority_order
                            )";

                    $stmt = $this->db->prepare($sql);
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

            // Create medical record if provided
            if (!empty($data['medical'])) {
                $sql = "INSERT INTO medical_records (
                            athlete_id, physical_exam_date, physical_exam_file_url,
                            physician_name, physician_phone, preferred_hospital,
                            blood_type, has_asthma, has_diabetes, has_seizures,
                            has_heart_condition
                        ) VALUES (
                            :athlete_id, :physical_exam_date, :physical_exam_file_url,
                            :physician_name, :physician_phone, :preferred_hospital,
                            :blood_type, :has_asthma, :has_diabetes, :has_seizures,
                            :has_heart_condition
                        )";

                $stmt = $this->db->prepare($sql);
                $stmt->execute([
                    ':athlete_id' => $athleteId,
                    ':physical_exam_date' => $data['medical']['physical_exam_date'] ?? null,
                    ':physical_exam_file_url' => $data['medical']['physical_exam_file_url'] ?? '',
                    ':physician_name' => $data['medical']['physician_name'] ?? '',
                    ':physician_phone' => $data['medical']['physician_phone'] ?? '',
                    ':preferred_hospital' => $data['medical']['preferred_hospital'] ?? null,
                    ':blood_type' => $data['medical']['blood_type'] ?? null,
                    ':has_asthma' => $data['medical']['has_asthma'] ?? false,
                    ':has_diabetes' => $data['medical']['has_diabetes'] ?? false,
                    ':has_seizures' => $data['medical']['has_seizures'] ?? false,
                    ':has_heart_condition' => $data['medical']['has_heart_condition'] ?? false
                ]);
            }

            $this->db->commit();

            http_response_code(201);
            echo json_encode([
                'id' => $athleteId,
                'message' => 'Athlete created successfully'
            ]);
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log('Failed to create athlete: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            http_response_code(500);
            echo json_encode(['error' => 'Failed to create athlete', 'details' => $e->getMessage()]);
        }
    }

    private function createOrFindGuardian($guardianData) {
        // Check if guardian exists with same email AND first name
        // This allows families to share emails (e.g., John and Jane both using thejonesfamily@email.com)
        $sql = "SELECT id FROM guardians
                WHERE email = :email
                AND first_name = :first_name";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':email' => $guardianData['email'],
            ':first_name' => $guardianData['first_name']
        ]);
        $existing = $stmt->fetch();

        if ($existing) {
            return $existing['id'];
        }

        // Create new guardian
        $sql = "INSERT INTO guardians (
                    first_name, last_name, email, mobile_phone,
                    work_phone, home_phone, address_line1, address_line2,
                    city, state, zip_code, occupation, employer
                ) VALUES (
                    :first_name, :last_name, :email, :mobile_phone,
                    :work_phone, :home_phone, :address_line1, :address_line2,
                    :city, :state, :zip_code, :occupation, :employer
                )";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':first_name' => $guardianData['first_name'],
            ':last_name' => $guardianData['last_name'],
            ':email' => $guardianData['email'],
            ':mobile_phone' => $guardianData['mobile_phone'],
            ':work_phone' => $guardianData['work_phone'] ?? null,
            ':home_phone' => $guardianData['home_phone'] ?? null,
            ':address_line1' => $guardianData['address_line1'] ?? null,
            ':address_line2' => $guardianData['address_line2'] ?? null,
            ':city' => $guardianData['city'] ?? null,
            ':state' => $guardianData['state'] ?? null,
            ':zip_code' => $guardianData['zip_code'] ?? null,
            ':occupation' => $guardianData['occupation'] ?? null,
            ':employer' => $guardianData['employer'] ?? null
        ]);

        return $this->db->lastInsertId();
    }

    public function getAthletes() {
        $sql = "SELECT
                    a.*,
                    CONCAT(g.first_name, ' ', g.last_name) as primary_guardian_name,
                    g.email as primary_guardian_email,
                    g.mobile_phone as primary_guardian_phone
                FROM athletes a
                LEFT JOIN athlete_guardians ag ON a.id = ag.athlete_id AND ag.is_primary_contact = 1
                LEFT JOIN guardians g ON ag.guardian_id = g.id
                WHERE a.active_status = 1
                ORDER BY a.last_name, a.first_name";

        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $athletes = $stmt->fetchAll();

        echo json_encode($athletes);
    }

    public function getAthlete($id) {
        // Get athlete details
        $sql = "SELECT * FROM athletes WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        $athlete = $stmt->fetch();

        if (!$athlete) {
            http_response_code(404);
            echo json_encode(['error' => 'Athlete not found']);
            return;
        }

        // Get guardians
        $sql = "SELECT g.*, ag.relationship_type, ag.is_primary_contact
                FROM guardians g
                JOIN athlete_guardians ag ON g.id = ag.guardian_id
                WHERE ag.athlete_id = :athlete_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':athlete_id' => $id]);
        $athlete['guardians'] = $stmt->fetchAll();

        // Get emergency contacts
        $sql = "SELECT * FROM emergency_contacts WHERE athlete_id = :athlete_id ORDER BY priority_order";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':athlete_id' => $id]);
        $athlete['emergency_contacts'] = $stmt->fetchAll();

        // Get medical record
        $sql = "SELECT * FROM medical_records WHERE athlete_id = :athlete_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':athlete_id' => $id]);
        $athlete['medical'] = $stmt->fetch();

        // Get allergies
        $sql = "SELECT * FROM allergies WHERE athlete_id = :athlete_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':athlete_id' => $id]);
        $athlete['allergies'] = $stmt->fetchAll();

        // Get medications
        $sql = "SELECT * FROM medications WHERE athlete_id = :athlete_id AND is_active = 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':athlete_id' => $id]);
        $athlete['medications'] = $stmt->fetchAll();

        echo json_encode($athlete);
    }

    private function validateAthlete($data) {
        $errors = [];

        if (empty($data['first_name'])) {
            $errors['first_name'] = 'First name is required';
        }

        if (empty($data['last_name'])) {
            $errors['last_name'] = 'Last name is required';
        }

        if (empty($data['date_of_birth'])) {
            $errors['date_of_birth'] = 'Date of birth is required';
        } else {
            $dob = new DateTime($data['date_of_birth']);
            $now = new DateTime();
            $age = $now->diff($dob)->y;
            if ($age < 4 || $age > 18) {
                $errors['date_of_birth'] = 'Athlete must be between 4 and 18 years old';
            }
        }

        if (empty($data['gender'])) {
            $errors['gender'] = 'Gender is required';
        }

        // Address fields are optional, defaults will be applied in createAthlete

        return $errors;
    }

    public function addGuardian($athleteId) {
        $data = json_decode(file_get_contents('php://input'), true);

        try {
            $this->db->beginTransaction();

            // Create or find guardian
            $guardianId = $this->createOrFindGuardian($data);

            // Check if relationship already exists
            $sql = "SELECT id FROM athlete_guardians
                    WHERE athlete_id = :athlete_id AND guardian_id = :guardian_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':athlete_id' => $athleteId,
                ':guardian_id' => $guardianId
            ]);

            if ($stmt->fetch()) {
                http_response_code(400);
                echo json_encode(['error' => 'Guardian already linked to this athlete']);
                return;
            }

            // Create relationship
            $sql = "INSERT INTO athlete_guardians (
                        athlete_id, guardian_id, relationship_type, is_primary_contact,
                        has_legal_custody, can_authorize_medical, can_pickup,
                        receives_communications, financial_responsible
                    ) VALUES (
                        :athlete_id, :guardian_id, :relationship_type, :is_primary_contact,
                        :has_legal_custody, :can_authorize_medical, :can_pickup,
                        :receives_communications, :financial_responsible
                    )";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':athlete_id' => $athleteId,
                ':guardian_id' => $guardianId,
                ':relationship_type' => $data['relationship_type'] ?? 'Guardian',
                ':is_primary_contact' => $data['is_primary_contact'] ?? false,
                ':has_legal_custody' => $data['has_legal_custody'] ?? true,
                ':can_authorize_medical' => $data['can_authorize_medical'] ?? true,
                ':can_pickup' => $data['can_pickup'] ?? true,
                ':receives_communications' => $data['receives_communications'] ?? true,
                ':financial_responsible' => $data['financial_responsible'] ?? false
            ]);

            $this->db->commit();

            http_response_code(201);
            echo json_encode([
                'message' => 'Guardian added successfully',
                'guardian_id' => $guardianId
            ]);
        } catch (Exception $e) {
            $this->db->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'Failed to add guardian']);
        }
    }

    public function removeGuardian($athleteId, $guardianId) {
        try {
            $sql = "UPDATE athlete_guardians
                    SET active_status = 0
                    WHERE athlete_id = :athlete_id AND guardian_id = :guardian_id";

            $stmt = $this->db->prepare($sql);
            $result = $stmt->execute([
                ':athlete_id' => $athleteId,
                ':guardian_id' => $guardianId
            ]);

            if ($result) {
                echo json_encode(['message' => 'Guardian removed successfully']);
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Failed to remove guardian']);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to remove guardian']);
        }
    }

    public function updateGuardianRelationship($athleteId, $guardianId) {
        $data = json_decode(file_get_contents('php://input'), true);

        try {
            $sql = "UPDATE athlete_guardians
                    SET relationship_type = :relationship_type,
                        is_primary_contact = :is_primary_contact,
                        has_legal_custody = :has_legal_custody,
                        can_authorize_medical = :can_authorize_medical,
                        can_pickup = :can_pickup,
                        receives_communications = :receives_communications,
                        financial_responsible = :financial_responsible
                    WHERE athlete_id = :athlete_id AND guardian_id = :guardian_id";

            $stmt = $this->db->prepare($sql);
            $result = $stmt->execute([
                ':athlete_id' => $athleteId,
                ':guardian_id' => $guardianId,
                ':relationship_type' => $data['relationship_type'],
                ':is_primary_contact' => $data['is_primary_contact'] ?? false,
                ':has_legal_custody' => $data['has_legal_custody'] ?? true,
                ':can_authorize_medical' => $data['can_authorize_medical'] ?? true,
                ':can_pickup' => $data['can_pickup'] ?? true,
                ':receives_communications' => $data['receives_communications'] ?? true,
                ':financial_responsible' => $data['financial_responsible'] ?? false
            ]);

            if ($result) {
                echo json_encode(['message' => 'Guardian relationship updated successfully']);
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Failed to update guardian relationship']);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to update guardian relationship']);
        }
    }
}