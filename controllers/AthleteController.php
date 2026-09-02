<?php

require_once __DIR__ . '/../lib/athlete_medical.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/AthleteScope.php';
require_once __DIR__ . '/../lib/db_actor.php';

class AthleteController {
    protected $db;

    /**
     * @param PDO|null $db Optional PDO. Defaults to the shared production
     *   connection so index.php can keep calling `new AthleteController()`.
     *   Injectable so the scope-enforcement path is unit-testable against an
     *   in-memory SQLite fixture (see tests/php/AthleteControllerScopeTest.php).
     */
    public function __construct(?PDO $db = null) {
        $this->db = $db ?? Database::getInstance()->getConnection();
    }

    /**
     * Resolve the authenticated requester. Defaults to JWT-based auth.
     * Extracted as a seam so tests can supply a context without a real token.
     *
     * @return AuthMiddleware (exits 401 on the request path if unauthenticated)
     */
    protected function resolveAuth() {
        return AuthMiddleware::requireAuth();
    }

    /**
     * Does the requester have standing to create athletes at all?
     *
     * Mirrors requesterCanCreateAthletes() in legacy/athletes-gateway.php — super
     * admin, any club admin, or a coach of any team. Kept in the same shape so the
     * two creation paths cannot drift into disagreeing about who may create.
     */
    protected function canCreateAthletes($auth): bool {
        if ($auth->isSuperAdmin()) {
            return true;
        }
        if (!empty(\AthleteScope::clubAdminClubIds($auth))) {
            return true;
        }
        $uid = (int) $auth->getUserId();
        return $uid > 0 && !empty(\AthleteScope::coachTeamIdsForUser($this->db, $uid));
    }

    public function createAthlete() {
        // Until 2026-08-17 this method had NO auth. index.php performs none either,
        // so POST /api/athletes created athlete records — and guardian rows carrying
        // whatever email the body supplied — for anyone on the internet.
        $auth = $this->resolveAuth();
        if (!$this->canCreateAthletes($auth)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Access denied']);
            return;
        }
        \te_db_set_actor($this->db, (int) $auth->getUserId());

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
                            athlete_id, guardian_id, relationship, is_primary, can_pickup
                        ) VALUES (
                            :athlete_id, :guardian_id, :relationship, :is_primary, :can_pickup
                        )";

                $stmt = $this->db->prepare($sql);
                $stmt->execute([
                    ':athlete_id' => $athleteId,
                    ':guardian_id' => $guardianId,
                    ':relationship' => $data['guardian']['relationship_type'] ?? 'Guardian',
                    ':is_primary' => true,
                    ':can_pickup' => $data['guardian']['can_pickup'] ?? true,
                ]);
            }

            // Create emergency contacts if provided
            if (!empty($data['emergency_contacts'])) {
                foreach ($data['emergency_contacts'] as $index => $contact) {
                    // The table's columns are name / secondary_phone — this insert
                    // used contact_name / alternate_phone and would have thrown
                    // SQLSTATE 42703 had it ever run. Accepts either input spelling.
                    $sql = "INSERT INTO emergency_contacts (
                                athlete_id, name, relationship, primary_phone,
                                secondary_phone, can_authorize_medical, priority_order
                            ) VALUES (
                                :athlete_id, :name, :relationship, :primary_phone,
                                :secondary_phone, :can_authorize_medical, :priority_order
                            )";

                    $stmt = $this->db->prepare($sql);
                    $stmt->execute([
                        ':athlete_id' => $athleteId,
                        ':name' => $contact['name'] ?? $contact['contact_name'] ?? null,
                        ':relationship' => $contact['relationship'] ?? null,
                        ':primary_phone' => $contact['primary_phone'] ?? null,
                        ':secondary_phone' => $contact['secondary_phone'] ?? $contact['alternate_phone'] ?? null,
                        ':can_authorize_medical' => !empty($contact['can_authorize_medical']) ? 'true' : 'false',
                        ':priority_order' => $index + 1
                    ]);
                }
            }

            // Create medical record if provided.
            // Was inserting health-profile columns into medical_records, a
            // documents/clearance table that has none of them (42703, swallowed).
            // The profile's home is athlete_medical; the shared helper handles
            // field mapping, boolean binding and encryption.
            if (!empty($data['medical'])) {
                te_save_athlete_medical($this->db, (int) $athleteId, $data['medical']);
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
        // Identity is email + FIRST + LAST, compared case- and whitespace-insensitively.
        // Households legitimately share one address (John & Jane at
        // thejones@gmail.com), so email alone would merge two people — but email +
        // first name alone merged them too: "Taylor Cook" and a hypothetical
        // "Taylor Cooke" on the same family address collapsed into one row.
        //
        // A BLANK email matches nothing, deliberately. 25 guardians carry
        // email = '' (an empty string, not NULL — '' = '' is true), so matching on
        // it merged unrelated people who happened to share a first name. `Juan
        // Rocha` and `Juan Coca` are both in production right now, and a third
        // emailless Juan would have attached to whichever came first.
        //
        // Two duplicate pairs created by the old rule were merged by hand on
        // 2026-07-31 (Taylor Cook, Maddison Mathis) — see CHANGELOG.
        $email     = trim((string)($guardianData['email'] ?? ''));
        $firstName = trim((string)($guardianData['first_name'] ?? ''));
        $lastName  = trim((string)($guardianData['last_name'] ?? ''));

        if ($email !== '') {
            $sql = "SELECT id FROM guardians
                    WHERE lower(trim(email))      = lower(:email)
                      AND lower(trim(first_name)) = lower(:first_name)
                      AND lower(trim(last_name))  = lower(:last_name)";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':email'      => $email,
                ':first_name' => $firstName,
                ':last_name'  => $lastName,
            ]);
            $existing = $stmt->fetch();

            if ($existing) {
                return $existing['id'];
            }
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
        // Reached as GET /api/athletes — Apache serves api/athletes/index.php for that
        // path (it is a directory), so this is the live entry point. Until 2026-09-02 it
        // had no auth and returned every athlete in every club with the primary
        // guardian's email and mobile: 329 rows, anonymously. Same scope as the legacy
        // athletes gateway: club admin of their club, coach of their teams, guardian of
        // their own child.
        $auth = $this->resolveAuth();
        $scope = \AthleteScope::accessibleAthleteFilter($this->db, $auth, 'a.id');

        $sql = "SELECT
                    a.*,
                    CONCAT(g.first_name, ' ', g.last_name) as primary_guardian_name,
                    g.email as primary_guardian_email,
                    g.mobile_phone as primary_guardian_phone
                FROM athletes a
                LEFT JOIN athlete_guardians ag ON a.id = ag.athlete_id AND ag.is_primary = true
                LEFT JOIN guardians g ON ag.guardian_id = g.id
                WHERE a.active_status = true
                {$scope['sql']}
                ORDER BY a.last_name, a.first_name";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($scope['params']);
        $athletes = $stmt->fetchAll();

        echo json_encode($athletes);
    }

    public function getAthlete($id) {
        $athleteId = (int) $id;

        // P0 access control: never return an athlete (incl. medical) by id without
        // verifying the requester may see them — club admin of the athlete's club,
        // a coach of one of the athlete's teams, or a guardian of the athlete.
        // Enforced BEFORE any data query, mirroring api/athletes.php /
        // legacy/athletes-gateway.php which both already use AthleteScope.
        $auth = $this->resolveAuth();
        if (!\AthleteScope::userCanAccessAthlete($this->db, $auth, $athleteId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Access denied']);
            return;
        }

        // Get athlete details
        $sql = "SELECT * FROM athletes WHERE id = :id AND active_status = true";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        $athlete = $stmt->fetch();

        if (!$athlete) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Athlete not found']);
            return;
        }

        // Get guardians
        $sql = "SELECT g.*, ag.relationship, ag.is_primary
                FROM guardians g
                JOIN athlete_guardians ag ON g.id = ag.guardian_id
                WHERE ag.athlete_id = :athlete_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':athlete_id' => $id]);
        $athlete['guardians'] = $stmt->fetchAll();

        // Get teams
        $sql = "SELECT t.id, t.name
                FROM teams t
                JOIN team_members tm ON t.id = tm.team_id
                WHERE tm.athlete_id = :athlete_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':athlete_id' => $id]);
        $athlete['teams'] = $stmt->fetchAll();

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
        $sql = "SELECT * FROM medications WHERE athlete_id = :athlete_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':athlete_id' => $id]);
        $athlete['medications'] = $stmt->fetchAll();

        echo json_encode(['success' => true, 'athlete' => $athlete]);
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
        // staffCanManageAthlete, NOT userCanAccessAthlete: attaching an adult to a
        // child is a write, and the read predicate's guardian branch would let one
        // parent add anyone to their child — or, combined with the missing auth this
        // method had until 2026-08-17, let an anonymous caller attach a guardian row
        // carrying their own email to any athlete and thereby gain that child's
        // record through the read predicate. Same hole CLAUDE.md records for
        // legacy/guardian-gateway.php, except this route required no token at all.
        $auth = $this->resolveAuth();
        if (!\AthleteScope::staffCanManageAthlete($this->db, $auth, (int) $athleteId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Access denied']);
            return;
        }
        \te_db_set_actor($this->db, (int) $auth->getUserId());

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
                        athlete_id, guardian_id, relationship, is_primary, can_pickup
                    ) VALUES (
                        :athlete_id, :guardian_id, :relationship, :is_primary, :can_pickup
                    )";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':athlete_id' => $athleteId,
                ':guardian_id' => $guardianId,
                ':relationship' => $data['relationship_type'] ?? 'Guardian',
                ':is_primary' => $data['is_primary_contact'] ?? false,
                ':can_pickup' => $data['can_pickup'] ?? true,
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
        // This route answered 200 to an unauthenticated DELETE until 2026-08-17 —
        // two integers in a URL detached a parent from a child, with no token, no
        // scope check and no record. It is the most plausible mechanism for the
        // guardian link that appeared on athlete 435 and vanished untraceably.
        $auth = $this->resolveAuth();
        if (!\AthleteScope::staffCanManageAthlete($this->db, $auth, (int) $athleteId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Access denied']);
            return;
        }
        \te_db_set_actor($this->db, (int) $auth->getUserId());

        try {
            // athlete_guardians has no active_status column — this UPDATE threw
            // 42703, so "remove guardian" reported success and removed nothing.
            // The table carries no soft-delete flag; the link row is deleted, which
            // is what guardian-gateway and the athlete form already do. The
            // guardian record itself survives (guardians are shared across siblings).
            $sql = "DELETE FROM athlete_guardians
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
        $isPrimary = $data['is_primary_contact'] ?? false;

        try {
            $this->db->beginTransaction();

            if ($isPrimary) {
                // Demote any other primary guardian on this athlete so only one stays primary
                $demote = $this->db->prepare("
                    UPDATE athlete_guardians
                    SET is_primary = false
                    WHERE athlete_id = :athlete_id
                      AND guardian_id != :guardian_id
                      AND is_primary = true
                ");
                $demote->execute([
                    ':athlete_id' => $athleteId,
                    ':guardian_id' => $guardianId,
                ]);
            }

            $sql = "UPDATE athlete_guardians
                    SET relationship = :relationship,
                        is_primary = :is_primary,
                        can_pickup = :can_pickup
                    WHERE athlete_id = :athlete_id AND guardian_id = :guardian_id";

            $stmt = $this->db->prepare($sql);
            $result = $stmt->execute([
                ':athlete_id' => $athleteId,
                ':guardian_id' => $guardianId,
                ':relationship' => $data['relationship_type'] ?? 'Guardian',
                ':is_primary' => $isPrimary,
                ':can_pickup' => $data['can_pickup'] ?? true,
            ]);

            $this->db->commit();

            if ($result) {
                echo json_encode(['message' => 'Guardian relationship updated successfully']);
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Failed to update guardian relationship']);
            }
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            http_response_code(500);
            echo json_encode(['error' => 'Failed to update guardian relationship']);
        }
    }
}